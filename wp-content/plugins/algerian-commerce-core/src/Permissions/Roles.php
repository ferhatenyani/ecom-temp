<?php

declare(strict_types=1);

namespace AlgerianCommerce\Permissions;

use AlgerianCommerce\Core\Logger;

/**
 * Installs the plugin's roles into WordPress.
 *
 * Roles are stored in the database, not computed per request, so a change to
 * the matrix in Capabilities only takes effect once this runs. The stored
 * version is what makes that detectable.
 */
final class Roles
{
    public const VERSION_OPTION = 'ac_core_roles_version';

    /** Bump when Capabilities::roles() changes so installs re-sync. */
    public const VERSION = 1;

    public function __construct(private readonly Logger $logger)
    {
    }

    public function isInstalled(): bool
    {
        return (int) get_option(self::VERSION_OPTION, 0) >= self::VERSION;
    }

    /**
     * Create or re-sync every role.
     *
     * remove_role() then add_role() rather than add_cap(): it is the only way
     * to drop a capability that was removed from the matrix. Users keep their
     * role assignment because that is stored per user, not on the role.
     */
    public function install(): void
    {
        foreach (Capabilities::roles() as $key => $definition) {
            remove_role($key);

            add_role($key, $definition['name'], $this->capabilityMap($definition['capabilities']));
        }

        $this->grantToAdministrator();

        update_option(self::VERSION_OPTION, self::VERSION);

        $this->logger->info('Roles installed', [
            'roles' => count(Capabilities::roles()),
            'version' => self::VERSION,
        ]);
    }

    /**
     * The WordPress administrator keeps every capability, so installing this
     * plugin never locks the site owner out of their own backend.
     */
    private function grantToAdministrator(): void
    {
        $administrator = get_role('administrator');

        if ($administrator === null) {
            return;
        }

        foreach (Capabilities::ALL as $capability) {
            $administrator->add_cap($capability);
        }
    }

    /**
     * Deliberately not called on deactivation — that would strip capabilities
     * from real accounts on a routine plugin update. Uninstall only.
     */
    public function remove(): void
    {
        foreach (Capabilities::roleKeys() as $key) {
            remove_role($key);
        }

        $administrator = get_role('administrator');

        if ($administrator !== null) {
            foreach (Capabilities::ALL as $capability) {
                $administrator->remove_cap($capability);
            }
        }

        delete_option(self::VERSION_OPTION);
    }

    /**
     * @param list<string> $capabilities
     * @return array<string, bool>
     */
    private function capabilityMap(array $capabilities): array
    {
        // 'read' lets the account sign in to WordPress at all.
        $map = ['read' => true];

        foreach ($capabilities as $capability) {
            $map[$capability] = true;
        }

        return $map;
    }
}
