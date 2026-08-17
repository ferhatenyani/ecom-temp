<?php

declare(strict_types=1);

namespace AlgerianCommerce\Campaigns;

use AlgerianCommerce\Permissions\Capabilities;
use WP_Post;
use WP_Query;

/**
 * `ac_email_template` — roadmap §85, and §61's precedent applied unchanged.
 *
 * **A template is a post type, and there is no migration for it.** §61 made banners
 * and FAQs post types on the instruction that "WordPress stores content", and this is
 * the same thing: revisions come free, the editor screens are WordPress's own, and
 * the media library is already there for images. A table would be a table with a
 * `content` column and a hand-rolled revision story.
 *
 * The registration copies §61's three deliberate choices and its one hard-won trap:
 *
 *  - **`public => false`, `show_ui => true`.** This plugin renders no frontend, so a
 *    template must never acquire a URL of its own — and a template *with* a URL is a
 *    marketing email readable by anybody who guesses the slug.
 *  - **`show_in_rest => false`.** `/wp/v2` is not this project's contract, and a
 *    second unversioned way to read the same content is a second thing to secure.
 *  - **Only the primitive capabilities are mapped.** `edit_post`, `read_post` and
 *    `delete_post` are meta capabilities: mapping one writes the name into
 *    WordPress's global `$post_type_meta_caps`, after which *every* check of
 *    `ac_manage_marketing` anywhere maps to `delete_post` with no post id and
 *    resolves to `do_not_allow`. §61 shipped that bug for an afternoon and
 *    `tests/Api/cms.php` caught it; `map_meta_cap => true` derives the three from the
 *    primitives.
 *
 * ## The sanitiser hooks the save, not the send
 *
 * **`wp_kses` runs on the way in.** A template is stored and re-rendered, so a stored
 * XSS fires in the admin's own preview — with whatever session the admin app holds —
 * long before it reaches an inbox. Sanitising on the way *out* would leave the
 * hostile markup in the database, where the next thing to read it might not sanitise
 * at all. The filter is `wp_insert_post_data`, so it covers every author: the
 * dashboard editor, WP-CLI, and anything that later gains a write endpoint.
 *
 * `ac_manage_marketing` is the capability throughout — **no new one**, matching §61's
 * media precedent and §63's analytics one.
 */
final class EmailTemplates
{
    public const POST_TYPE = 'ac_email_template';

    /** The authored plain-text part, which is never stripped from the HTML. */
    public const TEXT_META = '_ac_email_text';

    /** The default subject line a campaign inherits when it names this template. */
    public const SUBJECT_META = '_ac_email_subject';

    public function register(): void
    {
        add_action('init', [$this, 'registerAll']);

        /*
         * Priority 10 with three args, so `$postarr` is available: a template's text
         * part lives in meta and is sanitised in `sanitizeMeta()` on `save_post`,
         * while the HTML is the post content and is sanitised here.
         */
        add_filter('wp_insert_post_data', [$this, 'sanitizeOnSave'], 10, 2);
        add_action('save_post_' . self::POST_TYPE, [$this, 'sanitizeMeta'], 10, 1);
    }

    public function registerAll(): void
    {
        register_post_type(self::POST_TYPE, [
            'label' => 'Email templates',
            'labels' => [
                'name' => 'Email templates',
                'singular_name' => 'Email template',
                'add_new_item' => 'Add email template',
                'edit_item' => 'Edit email template',
                'new_item' => 'New email template',
                'search_items' => 'Search email templates',
                'not_found' => 'No email templates yet',
                'menu_name' => 'Email templates',
            ],
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => false,
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
            'menu_icon' => 'dashicons-email-alt',
            'menu_position' => 28,
            'supports' => ['title', 'editor', 'revisions', 'custom-fields'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'capabilities' => self::capabilities(),
        ]);

        foreach ([self::TEXT_META, self::SUBJECT_META] as $key) {
            register_post_meta(self::POST_TYPE, $key, [
                'type' => 'string',
                'single' => true,
                'default' => '',
                'show_in_rest' => false,
                'sanitize_callback' => static fn (mixed $value): string => EmailHtml::sanitizeText((string) $value),
                'auth_callback' => static fn (): bool => current_user_can(Capabilities::MANAGE_MARKETING),
            ]);
        }
    }

    /**
     * Run the allowlist over a template's HTML on the way into the database.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $postarr
     * @return array<string, mixed>
     */
    public function sanitizeOnSave(mixed $data, mixed $postarr = []): mixed
    {
        if (!is_array($data) || ($data['post_type'] ?? '') !== self::POST_TYPE) {
            return $data;
        }

        unset($postarr);

        $data['post_content'] = EmailHtml::sanitize((string) ($data['post_content'] ?? ''));

        // A title reaches a Subject: header through `SUBJECT_META`'s fallback, and a
        // newline in one is header injection — the same rule
        // `TemplateRenderer::render()` applies to the rendered subject.
        $data['post_title'] = trim((string) preg_replace('/[\r\n\t]+/', ' ', (string) ($data['post_title'] ?? '')));

        return $data;
    }

    /**
     * The text part and the subject, sanitised after a save.
     *
     * `register_post_meta`'s `sanitize_callback` covers writes that go through the
     * meta API; this covers the case where a value was written before the type was
     * registered on this request, which is what happens when a template is created
     * by WP-CLI on a request that has not reached `init`.
     */
    public function sanitizeMeta(mixed $postId): void
    {
        $postId = (int) $postId;

        if ($postId <= 0) {
            return;
        }

        foreach ([self::TEXT_META, self::SUBJECT_META] as $key) {
            $stored = get_post_meta($postId, $key, true);

            if (!is_string($stored) || $stored === '') {
                continue;
            }

            $clean = EmailHtml::sanitizeText($stored);

            if ($clean !== $stored) {
                update_post_meta($postId, $key, $clean);
            }
        }
    }

    /** @return array<string, mixed>|null */
    public static function read(int $id): ?array
    {
        $post = get_post($id);

        if (!$post instanceof WP_Post || $post->post_type !== self::POST_TYPE) {
            return null;
        }

        return self::present($post);
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public static function paginate(int $page, int $perPage): array
    {
        $query = new WP_Query([
            'post_type' => self::POST_TYPE,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => max(1, $perPage),
            'paged' => max(1, $page),
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => false,
        ]);

        $items = [];

        foreach ($query->posts as $post) {
            if ($post instanceof WP_Post) {
                $items[] = self::present($post);
            }
        }

        return ['items' => $items, 'total' => (int) $query->found_posts];
    }

    /** @return array<string, mixed> */
    private static function present(WP_Post $post): array
    {
        $html = (string) $post->post_content;
        $text = (string) get_post_meta($post->ID, self::TEXT_META, true);

        return [
            'id' => $post->ID,
            'name' => $post->post_title,
            'subject' => (string) (get_post_meta($post->ID, self::SUBJECT_META, true) ?: $post->post_title),
            'status' => $post->post_status,
            'body_html' => $html,
            'body_text' => $text,
            // What a campaign using this template would report — surfaced on the
            // template itself so an author sees a typo before a campaign does
            // (§61's malformed-section precedent).
            'unknown_tokens' => TemplateRenderer::unknownTokens($html . "\n" . $text),
            'has_unsubscribe_token' => TemplateRenderer::mentions($html),
            'modified_at' => get_post_modified_time('c', true, $post) ?: null,
        ];
    }

    /**
     * Every **primitive** post capability, all pointing at `ac_manage_marketing`.
     * See the class docblock for why the three meta capabilities are absent.
     *
     * @return array<string, string>
     */
    private static function capabilities(): array
    {
        $capability = Capabilities::MANAGE_MARKETING;

        return [
            'edit_posts' => $capability,
            'edit_others_posts' => $capability,
            'delete_posts' => $capability,
            'delete_others_posts' => $capability,
            'delete_published_posts' => $capability,
            'delete_private_posts' => $capability,
            'publish_posts' => $capability,
            'read_private_posts' => $capability,
            'edit_published_posts' => $capability,
            'edit_private_posts' => $capability,
            'create_posts' => $capability,
        ];
    }
}
