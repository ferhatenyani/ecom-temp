<?php

declare(strict_types=1);

namespace AlgerianCommerce\Auth;

use AlgerianCommerce\Permissions\Capabilities;

/**
 * Who the caller is, as this API describes them.
 *
 * Pure — no WordPress — so the part that decides what a credential is allowed
 * to reveal about itself is unit-testable.
 *
 * The capability list is filtered down to this plugin's own vocabulary. A
 * WordPress administrator carries well over sixty core capabilities
 * (`edit_themes`, `install_plugins`, …) which say nothing about commerce and
 * would hand any client that reads this a detailed map of the platform
 * underneath. The client only needs to know what it may do *here*.
 */
final class Identity
{
    /**
     * @param list<string> $roles
     * @param list<string> $capabilities already filtered to Capabilities::ALL
     */
    private function __construct(
        public readonly int $id,
        public readonly string $username,
        public readonly string $displayName,
        public readonly string $email,
        public readonly array $roles,
        public readonly array $capabilities,
        public readonly string $authMethod
    ) {
    }

    /**
     * @param array<string, bool>|list<string> $allCapabilities as WordPress
     *        reports them: a map of capability => granted, or a plain list
     * @param list<string> $roles
     */
    public static function create(
        int $id,
        string $username,
        string $displayName,
        string $email,
        array $roles,
        array $allCapabilities,
        string $authMethod
    ): self {
        return new self(
            $id,
            $username,
            $displayName,
            $email,
            array_values(array_map('strval', $roles)),
            self::commerceCapabilities($allCapabilities),
            $authMethod
        );
    }

    /**
     * Keep only the capabilities this plugin defines, and only those actually
     * granted.
     *
     * WordPress's `allcaps` is a map of name => bool, and a `false` entry means
     * explicitly denied — treating a present key as a grant is how a revoked
     * capability comes back to life.
     *
     * @param array<string, bool>|list<string> $capabilities
     * @return list<string>
     */
    private static function commerceCapabilities(array $capabilities): array
    {
        $granted = [];

        foreach ($capabilities as $key => $value) {
            $name = is_int($key) ? (string) $value : (string) $key;
            $isGranted = is_int($key) || (bool) $value;

            if ($isGranted && Capabilities::isKnownCapability($name)) {
                $granted[] = $name;
            }
        }

        // Sorted so the shape is stable between requests and diffable in tests.
        $granted = array_values(array_unique($granted));
        sort($granted);

        return $granted;
    }

    public function can(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'display_name' => $this->displayName,
            'email' => $this->email,
            'roles' => $this->roles,
            'capabilities' => $this->capabilities,
            'auth_method' => $this->authMethod,
        ];
    }
}
