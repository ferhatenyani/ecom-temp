<?php

declare(strict_types=1);

namespace AlgerianCommerce\CMS;

use AlgerianCommerce\API\ApiException;

/**
 * Validates a homepage write payload — roadmap §89.
 *
 * Pure, and deliberately the **opposite** of `HomepageSections::fromStored()`.
 * That class drops a malformed section and reports it, because the option is
 * edited by hand with `wp option update` and a typo must degrade rather than
 * break a storefront's homepage. This one refuses with a 400 naming
 * `sections[2].type`, because at this end of the pipe there is a person with a
 * form who can fix it, and dropping their work quietly is the one failure a
 * content manager cannot diagnose.
 *
 * The two share `HomepageSections::TYPES` and `MAX_SECTIONS` rather than
 * restating them, so the vocabulary the reader accepts and the vocabulary the
 * writer permits cannot drift apart.
 *
 * `PUT` replaces the document whole. There is no section-level route: sections
 * are ordered, and an API letting two clients insert at index 2 concurrently
 * has invented a merge problem the shop does not have.
 */
final class HomepageInput
{
    /**
     * @param list<array{type: string, data: array<string, mixed>}> $sections
     */
    private function __construct(public readonly array $sections)
    {
    }

    /** @return list<string> */
    public static function allowedFields(): array
    {
        return ['sections'];
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $errors = [];

        foreach (array_diff(array_keys($payload), self::allowedFields()) as $field) {
            $errors[(string) $field] = 'Unknown field. The homepage is one document and carries only "sections".';
        }

        if (!array_key_exists('sections', $payload)) {
            $errors['sections'] = 'Required. Send [] to empty the homepage.';
        } elseif (!is_array($payload['sections']) || !array_is_list($payload['sections'])) {
            /*
             * The bare list `fromStored()` also accepts is deliberately not
             * accepted here. A reader takes what it is given; a writer states
             * one shape, and the one it states is the one it emits.
             */
            $errors['sections'] = 'Must be a list of {type, data} objects.';
        } elseif (count($payload['sections']) > HomepageSections::MAX_SECTIONS) {
            $errors['sections'] = sprintf(
                'A homepage carries at most %d sections; this one has %d.',
                HomepageSections::MAX_SECTIONS,
                count($payload['sections'])
            );
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The homepage data is invalid.', ['fields' => $errors]);
        }

        /** @var list<mixed> $raw */
        $raw = $payload['sections'];
        $sections = [];

        foreach ($raw as $index => $entry) {
            $where = "sections[{$index}]";

            if (!is_array($entry)) {
                $errors[$where] = 'Must be an object with "type" and "data".';
                continue;
            }

            foreach (array_diff(array_keys($entry), ['type', 'data']) as $field) {
                $errors["{$where}.{$field}"] = 'Unknown field. A section is {type, data}.';
            }

            $type = $entry['type'] ?? null;

            if (!is_string($type) || $type === '') {
                $errors["{$where}.type"] = 'Required, and must be a string.';
                continue;
            }

            if (!HomepageSections::isKnownType($type)) {
                $errors["{$where}.type"] = 'Unknown section type "' . $type . '". One of: '
                    . implode(', ', HomepageSections::TYPES) . '.';
                continue;
            }

            $data = $entry['data'] ?? [];

            if ($data === null) {
                $data = [];
            }

            if (!is_array($data)) {
                $errors["{$where}.data"] = 'Must be an object.';
                continue;
            }

            // Free-form by design — a section's fields are whatever its type
            // needs — so the allowlist is routed at string leaves rather than
            // pointed at named fields. See `ContentHtml::sanitizeDocument()`.
            $sections[] = ['type' => $type, 'data' => ContentHtml::sanitizeDocument($data)];
        }

        if ($errors !== []) {
            throw ApiException::invalidRequest('The homepage data is invalid.', ['fields' => $errors]);
        }

        return new self($sections);
    }

    /** @return array{sections: list<array{type: string, data: array<string, mixed>}>} */
    public function toArray(): array
    {
        return ['sections' => $this->sections];
    }
}
