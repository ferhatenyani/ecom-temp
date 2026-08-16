<?php

declare(strict_types=1);

namespace AlgerianCommerce\SEO;

use AlgerianCommerce\API\ApiException;

/**
 * Validates an SEO write payload — roadmap §62.
 *
 * Pure, and shaped like every other Input in this plugin: unknown fields are
 * refused rather than ignored, `null` clears a field back to the derived
 * default, and the whole thing is rejected as one list of field errors.
 *
 * Clearing matters more here than elsewhere. Every one of these fields has a
 * sensible derived value, so "unset" is a real and often better state than
 * "set to something stale" — an editor who deletes a hand-written description
 * gets the excerpt back, not an empty meta tag.
 */
final class SeoInput
{
    /** Emitted on read, ignored on write, so a client can round-trip a payload. */
    private const READ_ONLY = ['og', 'image', 'structured_data', 'overrides'];

    private const MAX_TITLE = 200;
    private const MAX_DESCRIPTION = 500;
    private const MAX_CANONICAL = 500;

    /** @param array<string, mixed> $fields */
    private function __construct(public readonly array $fields)
    {
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

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $errors collected by the caller, so an SEO
     *        error appears in the same `fields` list as the rest of a product
     *        write rather than in a second, differently shaped response
     */
    public static function fromPayload(mixed $payload, array &$errors = []): self
    {
        $own = [];

        if (!is_array($payload)) {
            $errors['seo'] = 'Must be an object.';

            return new self([]);
        }

        $payload = array_diff_key($payload, array_flip(self::READ_ONLY));
        $clean = [];

        foreach (array_diff(array_keys($payload), SeoFields::FIELDS) as $field) {
            $own['seo.' . (string) $field] = 'Unknown field.';
        }

        foreach (['title' => self::MAX_TITLE, 'description' => self::MAX_DESCRIPTION] as $field => $max) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];

            if ($value === null) {
                $clean[$field] = '';
                continue;
            }

            if (!is_scalar($value)) {
                $own['seo.' . $field] = 'Must be a string.';
                continue;
            }

            $value = trim((string) $value);

            if (mb_strlen($value) > $max) {
                $own['seo.' . $field] = "Must be at most {$max} characters.";
                continue;
            }

            $clean[$field] = $value;
        }

        if (array_key_exists('canonical', $payload)) {
            $canonical = $payload['canonical'];

            if ($canonical === null || $canonical === '') {
                $clean['canonical'] = '';
            } elseif (!is_string($canonical) || mb_strlen($canonical) > self::MAX_CANONICAL) {
                $own['seo.canonical'] = 'Must be a URL of at most ' . self::MAX_CANONICAL . ' characters.';
            } elseif (!SeoFields::isAcceptableCanonical(trim($canonical))) {
                $own['seo.canonical'] = 'Must be an absolute https URL.';
            } else {
                $clean['canonical'] = trim($canonical);
            }
        }

        if (array_key_exists('robots', $payload)) {
            $robots = $payload['robots'];

            if ($robots === null || $robots === '') {
                $clean['robots'] = '';
            } elseif (is_array($robots)) {
                // The read shape is an object, so accept it back: a client that
                // GETs, flips `index` and PATCHes must not have to know that
                // the storage is a directive string.
                $index = (bool) ($robots['index'] ?? true);
                $follow = (bool) ($robots['follow'] ?? true);
                $clean['robots'] = SeoFields::robotsDirective($index, $follow);
            } elseif (is_string($robots)) {
                $parsed = SeoFields::parseRobots($robots);
                $clean['robots'] = SeoFields::robotsDirective($parsed['index'], $parsed['follow']);
            } else {
                $own['seo.robots'] = 'Must be "index, follow" or an object with index and follow.';
            }
        }

        if (array_key_exists('image_id', $payload)) {
            $imageId = $payload['image_id'];

            if ($imageId === null || $imageId === '' || $imageId === 0) {
                $clean['image_id'] = 0;
            } elseif (!is_numeric($imageId) || (int) $imageId < 0) {
                $own['seo.image_id'] = 'Must be an attachment id, or 0 to clear.';
            } else {
                $clean['image_id'] = (int) $imageId;
            }
        }

        foreach ($own as $field => $message) {
            $errors[$field] = $message;
        }

        return new self($clean);
    }

    /**
     * Standalone entry point for endpoints whose whole body is SEO, which
     * throws rather than collecting.
     *
     * @param array<string, mixed> $payload
     *
     * @throws ApiException
     */
    public static function fromRequest(mixed $payload): self
    {
        $errors = [];
        $input = self::fromPayload($payload, $errors);

        if ($errors !== []) {
            throw ApiException::invalidRequest('The SEO data is invalid.', ['fields' => $errors]);
        }

        return $input;
    }
}
