<?php

declare(strict_types=1);

namespace AlgerianCommerce\Permissions;

/**
 * The capability vocabulary and the role → capability matrix.
 *
 * Pure data and pure functions: no WordPress, so the matrix — the thing that
 * decides who can do what — is unit-testable on its own.
 *
 * Capabilities carry an `ac_` prefix. docs/PLAN.md §3 names them bare
 * (`manage_products`), but WordPress capabilities live in one global
 * namespace shared with core, WooCommerce and every other plugin; an
 * unprefixed `manage_products` risks colliding with something that grants it
 * for unrelated reasons. The prefix keeps the suffixes from PLAN.md intact.
 */
final class Capabilities
{
    public const PREFIX = 'ac_';

    public const MANAGE_PRODUCTS = 'ac_manage_products';
    public const MANAGE_INVENTORY = 'ac_manage_inventory';
    public const MANAGE_ORDERS = 'ac_manage_orders';
    public const MANAGE_CUSTOMERS = 'ac_manage_customers';
    public const MANAGE_COUPONS = 'ac_manage_coupons';
    public const MANAGE_CONTENT = 'ac_manage_content';
    public const MANAGE_MARKETING = 'ac_manage_marketing';
    public const VIEW_ANALYTICS = 'ac_view_analytics';
    public const MANAGE_SHIPPING = 'ac_manage_shipping';
    public const MANAGE_PAYMENTS = 'ac_manage_payments';
    public const MANAGE_SETTINGS = 'ac_manage_settings';
    public const MANAGE_USERS = 'ac_manage_users';
    public const VIEW_AUDIT_LOGS = 'ac_view_audit_logs';

    public const SUPER_ADMIN = 'ac_super_admin';
    public const ADMIN = 'ac_admin';
    public const MANAGER = 'ac_manager';
    public const PRODUCT_MANAGER = 'ac_product_manager';
    public const ORDER_MANAGER = 'ac_order_manager';
    public const MARKETING_MANAGER = 'ac_marketing_manager';
    public const SUPPORT_AGENT = 'ac_support_agent';

    /** Every capability this plugin defines — docs/PLAN.md §3. */
    public const ALL = [
        self::MANAGE_PRODUCTS,
        self::MANAGE_INVENTORY,
        self::MANAGE_ORDERS,
        self::MANAGE_CUSTOMERS,
        self::MANAGE_COUPONS,
        self::MANAGE_CONTENT,
        self::MANAGE_MARKETING,
        self::VIEW_ANALYTICS,
        self::MANAGE_SHIPPING,
        self::MANAGE_PAYMENTS,
        self::MANAGE_SETTINGS,
        self::MANAGE_USERS,
        self::VIEW_AUDIT_LOGS,
    ];

    /**
     * Role definitions. Least privilege: a role gets a capability only where
     * the job needs it, so a compromised support account cannot reprice the
     * catalogue.
     *
     * @return array<string, array{name: string, capabilities: list<string>}>
     */
    public static function roles(): array
    {
        return [
            self::SUPER_ADMIN => [
                'name' => 'Super Admin',
                'capabilities' => self::ALL,
            ],
            self::ADMIN => [
                'name' => 'Admin',
                // Everything operational. Platform settings and user/role
                // management stay with Super Admin — that is the boundary
                // that stops privilege escalation from an Admin account.
                'capabilities' => [
                    self::MANAGE_PRODUCTS,
                    self::MANAGE_INVENTORY,
                    self::MANAGE_ORDERS,
                    self::MANAGE_CUSTOMERS,
                    self::MANAGE_COUPONS,
                    self::MANAGE_CONTENT,
                    self::MANAGE_MARKETING,
                    self::VIEW_ANALYTICS,
                    self::MANAGE_SHIPPING,
                    self::MANAGE_PAYMENTS,
                    self::VIEW_AUDIT_LOGS,
                ],
            ],
            self::MANAGER => [
                'name' => 'Manager',
                'capabilities' => [
                    self::MANAGE_PRODUCTS,
                    self::MANAGE_INVENTORY,
                    self::MANAGE_ORDERS,
                    self::MANAGE_CUSTOMERS,
                    self::MANAGE_COUPONS,
                    self::MANAGE_SHIPPING,
                    self::VIEW_ANALYTICS,
                ],
            ],
            self::PRODUCT_MANAGER => [
                'name' => 'Product Manager',
                'capabilities' => [
                    self::MANAGE_PRODUCTS,
                    self::MANAGE_INVENTORY,
                    self::VIEW_ANALYTICS,
                ],
            ],
            self::ORDER_MANAGER => [
                'name' => 'Order Manager',
                'capabilities' => [
                    self::MANAGE_ORDERS,
                    self::MANAGE_CUSTOMERS,
                    self::MANAGE_SHIPPING,
                    self::VIEW_ANALYTICS,
                ],
            ],
            self::MARKETING_MANAGER => [
                'name' => 'Marketing Manager',
                'capabilities' => [
                    self::MANAGE_MARKETING,
                    self::MANAGE_CONTENT,
                    self::MANAGE_COUPONS,
                    self::VIEW_ANALYTICS,
                ],
            ],
            self::SUPPORT_AGENT => [
                'name' => 'Support Agent',
                // Deliberately thin. PLAN.md §3 defines only manage_* and
                // view_* capabilities, with no read-only variant for orders,
                // so anything more would hand support write access to the
                // order book. Revisit when read capabilities exist.
                'capabilities' => [
                    self::MANAGE_CUSTOMERS,
                    self::VIEW_ANALYTICS,
                ],
            ],
        ];
    }

    /** @return list<string> */
    public static function roleKeys(): array
    {
        return array_keys(self::roles());
    }

    /** @return list<string> */
    public static function forRole(string $role): array
    {
        return self::roles()[$role]['capabilities'] ?? [];
    }

    public static function isKnownCapability(string $capability): bool
    {
        return in_array($capability, self::ALL, true);
    }

    public static function isKnownRole(string $role): bool
    {
        return array_key_exists($role, self::roles());
    }

    public static function roleHas(string $role, string $capability): bool
    {
        return in_array($capability, self::forRole($role), true);
    }
}
