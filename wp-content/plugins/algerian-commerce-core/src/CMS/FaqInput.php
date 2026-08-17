<?php

declare(strict_types=1);

namespace AlgerianCommerce\CMS;

use AlgerianCommerce\API\ApiException;

/**
 * Validates an FAQ write payload — roadmap §89.
 *
 * Pure. Field names are `CmsPresenter::faq()`'s, and `categories` is the one
 * that had to work harder for it: the presenter emits a list of
 * `{id, slug, name}` objects, so this accepts that shape as well as a bare list
 * of slugs or ids. A read body written back is a no-op — the round-trip promise
 * `docs/API.md` makes in "Things that will bite you" — and a client that has
 * only ever seen slugs never has to learn term ids.
 */
final class FaqInput
{
    /** Emitted on read, dropped on write. */
    private const READ_ONLY = ['id', 'date_created', 'date_modified'];

    /** @var array<string, string> refused by name, with the reason */
    private const REFUSED = [
        'category' => 'Use "categories" — an FAQ may sit in more than one.',
        'title' => 'Use "question", which is what the read body carries.',
        'content' => 'Use "answer", which is what the read body carries.',
        'menu_order' => 'Use "position", which is what the read body carries.',
    ];

    private const MAX_QUESTION = 500;
    private const MAX_ANSWER = 50000;
    private const MAX_CATEGORIES = 20;

    /** @param array<string, mixed> $fields */
    private function __construct(public readonly array $fields)
    {
    }

    /** @return list<string> */
    public static function allowedFields(): array
    {
        return ['question', 'answer', 'categories', 'position', 'status'];
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    public function get(string $field): mixed
    {
        return $this->fields[$field] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /** @param array<string, mixed> $payload */
    public static function forCreate(array $payload): self
    {
        $errors = [];
        $clean = self::common($payload, $errors);

        if (!array_key_exists('question', $payload)) {
            $errors['question'] = 'Required.';
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The FAQ data is invalid.', ['fields' => $errors]);
        }

        return new self($clean);
    }

    /** @param array<string, mixed> $payload */
    public static function forUpdate(array $payload): self
    {
        $errors = [];
        $clean = self::common($payload, $errors);

        if ($errors !== []) {
            throw ApiException::invalidRequest('The FAQ data is invalid.', ['fields' => $errors]);
        }

        return new self($clean);
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $errors
     * @return array<string, mixed>
     */
    private static function common(array $payload, array &$errors): array
    {
        $clean = [];

        $payload = array_diff_key($payload, array_flip(self::READ_ONLY));

        foreach (array_diff(array_keys($payload), self::allowedFields()) as $field) {
            $field = (string) $field;
            $errors[$field] = self::REFUSED[$field] ?? 'Unknown field.';
        }

        if (array_key_exists('question', $payload)) {
            $question = is_scalar($payload['question'])
                ? ContentHtml::sanitizeText(trim((string) $payload['question']))
                : '';

            if ($question === '') {
                $errors['question'] = 'Must be a non-empty string.';
            } elseif (mb_strlen($question) > self::MAX_QUESTION) {
                $errors['question'] = 'Must be at most ' . self::MAX_QUESTION . ' characters.';
            } else {
                $clean['question'] = $question;
            }
        }

        if (array_key_exists('answer', $payload)) {
            $answer = $payload['answer'];

            if ($answer === null) {
                $clean['answer'] = '';
            } elseif (!is_string($answer)) {
                $errors['answer'] = 'Must be a string.';
            } elseif (strlen($answer) > self::MAX_ANSWER) {
                $errors['answer'] = 'Must be at most ' . self::MAX_ANSWER . ' bytes.';
            } else {
                $clean['answer'] = ContentHtml::sanitize($answer);
            }
        }

        if (array_key_exists('categories', $payload)) {
            $categories = $payload['categories'];

            if ($categories === null) {
                $clean['categories'] = [];
            } elseif (!is_array($categories) || !array_is_list($categories)) {
                $errors['categories'] = 'Must be a list of category slugs, ids, or the objects a read returns.';
            } elseif (count($categories) > self::MAX_CATEGORIES) {
                $errors['categories'] = 'At most ' . self::MAX_CATEGORIES . ' categories.';
            } else {
                $resolved = self::categories($categories, $errors);

                if ($resolved !== null) {
                    $clean['categories'] = $resolved;
                }
            }
        }

        if (array_key_exists('position', $payload)) {
            if (!is_int($payload['position']) && !(is_string($payload['position']) && ctype_digit($payload['position']))) {
                $errors['position'] = 'Must be an integer.';
            } else {
                $clean['position'] = (int) $payload['position'];
            }
        }

        if (array_key_exists('status', $payload)) {
            $status = is_scalar($payload['status']) ? (string) $payload['status'] : '';

            if (!in_array($status, PageInput::STATUSES, true)) {
                $errors['status'] = 'Must be one of: ' . implode(', ', PageInput::STATUSES) . '.';
            } else {
                $clean['status'] = $status;
            }
        }

        return $clean;
    }

    /**
     * Normalise the three accepted shapes to one list of `{id}` or `{slug}`.
     *
     * An id is preferred when both are present, because the read body carries
     * both and an id cannot be ambiguous. The repository is what turns a slug
     * into a term, since that is a database question.
     *
     * @param list<mixed>           $categories
     * @param array<string, string> $errors
     * @return list<array{id?: int, slug?: string}>|null
     */
    private static function categories(array $categories, array &$errors): ?array
    {
        $out = [];

        foreach ($categories as $index => $entry) {
            if (is_int($entry) || (is_string($entry) && ctype_digit($entry))) {
                $out[] = ['id' => (int) $entry];
                continue;
            }

            if (is_string($entry)) {
                $slug = strtolower(trim($entry));

                if (preg_match('/^[a-z0-9\-_]{1,64}$/', $slug) !== 1) {
                    $errors["categories[{$index}]"] = 'Must be a slug of 1–64 characters of a–z, 0–9, hyphen or underscore.';

                    return null;
                }

                $out[] = ['slug' => $slug];
                continue;
            }

            if (is_array($entry) && isset($entry['id']) && is_numeric($entry['id']) && (int) $entry['id'] > 0) {
                $out[] = ['id' => (int) $entry['id']];
                continue;
            }

            if (is_array($entry) && isset($entry['slug']) && is_string($entry['slug'])) {
                $slug = strtolower(trim($entry['slug']));

                if (preg_match('/^[a-z0-9\-_]{1,64}$/', $slug) !== 1) {
                    $errors["categories[{$index}].slug"] = 'Must be a slug of 1–64 characters of a–z, 0–9, hyphen or underscore.';

                    return null;
                }

                $out[] = ['slug' => $slug];
                continue;
            }

            $errors["categories[{$index}]"] = 'Must be a category slug, an id, or an object carrying one of them.';

            return null;
        }

        return $out;
    }
}
