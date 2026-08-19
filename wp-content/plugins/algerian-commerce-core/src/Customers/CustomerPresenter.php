<?php

declare(strict_types=1);

namespace AlgerianCommerce\Customers;

use WC_Customer;

/**
 * Shapes a WC_Customer for the API.
 *
 * `billing` and `shipping` come back in exactly the form CustomerInput accepts,
 * so GET → edit → PATCH round trips without translation.
 *
 * Nothing from the WordPress user object beyond identity and name: no password
 * hash, no capability map, no session tokens, no `user_activation_key`. A
 * customer record is read by the thinnest role in the system and there is no
 * version of "support looked up a customer" that needs any of it.
 */
final class CustomerPresenter
{
    /**
     * @param array<string, mixed>|null $statistics omitted from list rows,
     *        where computing it per customer would mean a query per row
     * @return array<string, mixed>
     */
    public static function toArray(WC_Customer $customer, ?array $statistics = null): array
    {
        $payload = [
            'id' => $customer->get_id(),
            'username' => $customer->get_username(),
            'email' => $customer->get_email(),
            'first_name' => $customer->get_first_name(),
            'last_name' => $customer->get_last_name(),
            'role' => $customer->get_role(),
            // WooCommerce's own flag, set on the first paid order.
            'is_paying_customer' => $customer->get_is_paying_customer(),
            /*
             * Roadmap §85. **Reported here and writable from nowhere in this module**
             * — a shop has to be able to see who consented, and `CustomerInput`
             * deliberately does not accept it, because a flag staff could tick is not
             * a consent record. The customer sets it at registration or through
             * `POST /account/marketing-consent`, and clears it with the one-click
             * unsubscribe link in every campaign.
             */
            'marketing_consent' => \AlgerianCommerce\Campaigns\Consent::has($customer->get_id()),
            /*
             * The flag on its own was not enough to build a screen from. An admin
             * panel showing a bare "Non" cannot distinguish a customer who declined
             * from one who was never asked, and both the date and the source were
             * already being stored — the first since this module was written, the
             * second added beside it — and simply never presented. So the panel had
             * a boolean it could not explain and a spec asking it to explain one.
             *
             * Both are null together on a customer who has never decided, which is
             * 15 of the 16 in the development shop. A client renders the pair, not
             * the flag: "Non, retiré le 3 mars" and "Non" are different answers.
             */
            'marketing_consent_at' => \AlgerianCommerce\Campaigns\Consent::changedAt($customer->get_id()),
            'marketing_consent_source' => \AlgerianCommerce\Campaigns\Consent::source($customer->get_id()),
            'billing' => self::billing($customer),
            'shipping' => self::shipping($customer),
            'date_created' => self::date($customer->get_date_created()),
            'date_modified' => self::date($customer->get_date_modified()),
        ];

        if ($statistics !== null) {
            $payload['statistics'] = $statistics;
        }

        return $payload;
    }

    /**
     * @param list<WC_Customer> $customers
     * @return list<array<string, mixed>>
     */
    public static function toArrayList(array $customers): array
    {
        return array_values(array_map(
            static fn (WC_Customer $customer): array => self::toArray($customer),
            $customers
        ));
    }

    /** @return array<string, string> */
    private static function billing(WC_Customer $customer): array
    {
        return [
            'first_name' => $customer->get_billing_first_name(),
            'last_name' => $customer->get_billing_last_name(),
            'company' => $customer->get_billing_company(),
            'address_1' => $customer->get_billing_address_1(),
            'address_2' => $customer->get_billing_address_2(),
            'city' => $customer->get_billing_city(),
            'state' => $customer->get_billing_state(),
            'postcode' => $customer->get_billing_postcode(),
            'country' => $customer->get_billing_country(),
            'email' => $customer->get_billing_email(),
            'phone' => $customer->get_billing_phone(),
        ];
    }

    /** @return array<string, string> */
    private static function shipping(WC_Customer $customer): array
    {
        return [
            'first_name' => $customer->get_shipping_first_name(),
            'last_name' => $customer->get_shipping_last_name(),
            'company' => $customer->get_shipping_company(),
            'address_1' => $customer->get_shipping_address_1(),
            'address_2' => $customer->get_shipping_address_2(),
            'city' => $customer->get_shipping_city(),
            'state' => $customer->get_shipping_state(),
            'postcode' => $customer->get_shipping_postcode(),
            'country' => $customer->get_shipping_country(),
            'phone' => $customer->get_shipping_phone(),
        ];
    }

    private static function date(mixed $date): ?string
    {
        return is_object($date) && method_exists($date, 'date') ? $date->date('c') : null;
    }
}
