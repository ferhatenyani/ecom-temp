<?php

declare(strict_types=1);

namespace AlgerianCommerce\CLI;

use AlgerianCommerce\Permissions\Capabilities;
use AlgerianCommerce\Permissions\Roles;
use WP_CLI;

/**
 * wp algerian-commerce roles
 *
 * Roles install on activation, but the capability matrix changes over time
 * and a file-only deploy never reactivates the plugin. This re-syncs.
 */
final class RolesCommand
{
    public function __construct(private readonly Roles $roles)
    {
    }

    /**
     * Install or re-sync the plugin's roles and capabilities.
     *
     * ## OPTIONS
     *
     * [--list]
     * : Show the role → capability matrix instead of installing.
     *
     * ## EXAMPLES
     *
     *     wp algerian-commerce roles
     *     wp algerian-commerce roles --list
     *
     * @param list<string>         $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs = []): void
    {
        if (!empty($assocArgs['list'])) {
            $this->render();

            return;
        }

        $this->roles->install();

        WP_CLI::success(sprintf(
            '%d roles installed with %d capabilities (version %d).',
            count(Capabilities::roles()),
            count(Capabilities::ALL),
            Roles::VERSION
        ));
    }

    private function render(): void
    {
        $rows = [];

        foreach (Capabilities::roles() as $key => $definition) {
            $rows[] = [
                'role' => $key,
                'name' => $definition['name'],
                'capabilities' => count($definition['capabilities']),
                'grants' => implode(', ', array_map(
                    static fn (string $cap): string => substr($cap, strlen(Capabilities::PREFIX)),
                    $definition['capabilities']
                )),
            ];
        }

        WP_CLI\Utils\format_items('table', $rows, ['role', 'name', 'capabilities', 'grants']);
    }
}
