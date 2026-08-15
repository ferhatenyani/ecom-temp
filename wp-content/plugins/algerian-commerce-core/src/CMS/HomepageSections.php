<?php

declare(strict_types=1);

namespace AlgerianCommerce\CMS;

/**
 * The homepage document — roadmap §61, docs/PLAN.md §23.
 *
 * A list of `{type, data}` sections the storefront renders in order, exactly
 * the shape §61 prints. Pure: no WordPress, so the normalisation that decides
 * what a storefront is handed is unit-testable on its own.
 *
 * **An option, not a post type.** Banners and FAQs are lists of things a person
 * edits one at a time, which is what a post type is for; the homepage is one
 * document edited as a whole, and splitting it across eleven post rows would
 * make "what does the homepage look like" a query rather than a value. It is
 * written the way §20 prefers — from the command line, in one step:
 *
 *     wp option update ac_cms_homepage --format=json '{"sections":[…]}'
 *
 * A bad value never fatals and never 500s. Invalid sections are dropped and
 * reported through `problems()`, the same contract `YalidineSettings` has, for
 * the same reason: an option is edited by hand and a typo must degrade rather
 * than break. They are reported rather than only logged because a section that
 * silently vanishes is the one failure a content manager cannot diagnose.
 */
final class HomepageSections
{
    /** The section vocabulary, verbatim from docs/PLAN.md §23. */
    public const TYPES = [
        'hero',
        'featured_products',
        'categories',
        'promotion',
        'banner',
        'text',
        'image',
        'faq',
        'testimonials',
        'newsletter',
        'custom',
    ];

    /**
     * A homepage is a page, not a catalogue. The cap exists so a mangled option
     * cannot turn one GET into an unbounded response.
     */
    public const MAX_SECTIONS = 50;

    /**
     * @param list<array{type: string, data: array<string, mixed>}> $sections
     * @param list<string>                                          $problems
     */
    private function __construct(
        public readonly array $sections,
        public readonly array $problems
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * Normalise whatever the option actually holds.
     *
     * Accepts either the documented `{"sections": [...]}` wrapper or a bare
     * list of sections, because both are what someone types.
     */
    public static function fromStored(mixed $stored): self
    {
        if (!is_array($stored)) {
            return $stored === false || $stored === null || $stored === ''
                ? self::empty()
                : new self([], ['The homepage option is not a list of sections.']);
        }

        $raw = $stored;

        if (array_key_exists('sections', $stored)) {
            if (!is_array($stored['sections'])) {
                return new self([], ['"sections" is not a list.']);
            }

            $raw = $stored['sections'];
        }

        $sections = [];
        $problems = [];
        $position = 0;

        foreach ($raw as $entry) {
            $position++;

            if (count($sections) >= self::MAX_SECTIONS) {
                $problems[] = sprintf('More than %d sections; the rest were dropped.', self::MAX_SECTIONS);
                break;
            }

            if (!is_array($entry)) {
                $problems[] = sprintf('Section %d is not an object.', $position);
                continue;
            }

            $type = $entry['type'] ?? null;

            if (!is_string($type) || !self::isKnownType($type)) {
                $problems[] = sprintf(
                    'Section %d has an unknown type %s.',
                    $position,
                    is_string($type) ? '"' . $type . '"' : 'value'
                );
                continue;
            }

            $data = $entry['data'] ?? [];

            if (!is_array($data)) {
                $problems[] = sprintf('Section %d ("%s") has a "data" that is not an object.', $position, $type);
                continue;
            }

            $sections[] = ['type' => $type, 'data' => $data];
        }

        return new self($sections, $problems);
    }

    public static function isKnownType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public function isEmpty(): bool
    {
        return $this->sections === [];
    }

    /** @return array{sections: list<array{type: string, data: array<string, mixed>}>} */
    public function toArray(): array
    {
        return ['sections' => $this->sections];
    }
}
