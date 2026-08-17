<?php

declare(strict_types=1);

namespace AlgerianCommerce\Notifications;

/**
 * Are SPF, DKIM and DMARC actually published for the domain this shop sends
 * from? — `wp algerian-commerce mail-check`.
 *
 * **This exists because §85 made the failure expensive and silent.** A shop
 * with working SMTP credentials and no SPF record sends mail that arrives —
 * into spam, or not at all, with no error anywhere on this side. `wp_mail()`
 * returns true, the queue drains, `ac_campaigns` records a send, and the only
 * evidence is customers saying they never got it. `MailCheckCommand` already
 * answers "can this shop hand a message to a transport"; this answers the other
 * half, which is whether anyone will accept it.
 *
 * **The domain is the From address's, and nothing else.** SPF and DKIM are
 * bound to the envelope sender — which is why `Campaigns\CampaignInput` refuses
 * a per-campaign `from` — so checking the storefront's domain or the SMTP
 * username's would check a domain no receiver looks at.
 *
 * **The verdicts are pure and the lookup is injected**, so every shape below is
 * a unit test rather than something discovered against a live domain months
 * later. A resolver that cannot answer is reported as *unknown*, never as a
 * missing record: an offline container must not tell an operator their DNS is
 * wrong.
 */
final class MailDns
{
    /**
     * Brevo publishes DKIM at `brevo._domainkey`. Every provider picks its own,
     * so this is a default rather than a fact — `--dkim-selector` overrides it,
     * and the report always names the host it queried so a wrong guess is
     * diagnosable rather than indistinguishable from a missing record.
     */
    public const DEFAULT_SELECTOR = 'brevo';

    public const OK = 'ok';
    public const MISSING = 'missing';
    public const PROBLEM = 'problem';
    public const UNKNOWN = 'unknown';

    /** @var callable(string): (list<string>|null) */
    private $resolver;

    /**
     * @param (callable(string): (list<string>|null))|null $resolver TXT lookup;
     *        null on failure, which is not the same as an empty list.
     */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver ?? self::systemResolver();
    }

    /**
     * @return list<array{record: string, host: string, status: string, detail: string}>
     */
    public function check(string $domain, string $selector = self::DEFAULT_SELECTOR): array
    {
        $domain = self::normalizeDomain($domain);
        $selector = trim($selector) !== '' ? trim($selector) : self::DEFAULT_SELECTOR;

        $dkimHost = $selector . '._domainkey.' . $domain;
        $dmarcHost = '_dmarc.' . $domain;

        return [
            self::row('SPF', $domain, self::spfVerdict(($this->resolver)($domain))),
            self::row('DKIM', $dkimHost, self::dkimVerdict(($this->resolver)($dkimHost))),
            self::row('DMARC', $dmarcHost, self::dmarcVerdict(($this->resolver)($dmarcHost))),
        ];
    }

    /**
     * The domain a receiver will check SPF and DKIM against.
     *
     * Returns '' for anything that is not an address, so a caller reports "no
     * From address" rather than querying DNS for a fragment.
     */
    public static function domainOf(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false || $at === strlen($email) - 1) {
            return '';
        }

        return self::normalizeDomain(substr($email, $at + 1));
    }

    /**
     * @param list<string>|null $txt
     *
     * @return array{status: string, detail: string}
     */
    public static function spfVerdict(?array $txt): array
    {
        if ($txt === null) {
            return self::unknown();
        }

        $records = self::matching($txt, 'v=spf1');

        if ($records === []) {
            return [
                'status' => self::MISSING,
                'detail' => 'No v=spf1 record. Receivers cannot confirm this server may send for the domain.',
            ];
        }

        /*
         * Two SPF records is not "twice as configured". RFC 7208 makes more
         * than one a permerror, so a shop that adds a second for a new provider
         * instead of merging the includes breaks the one it already had — and
         * the symptom is the mail that used to work starting to fail.
         */
        if (count($records) > 1) {
            return [
                'status' => self::PROBLEM,
                'detail' => sprintf(
                    '%d v=spf1 records. RFC 7208 allows one — merge the includes into a single record.',
                    count($records)
                ),
            ];
        }

        return ['status' => self::OK, 'detail' => $records[0]];
    }

    /**
     * @param list<string>|null $txt
     *
     * @return array{status: string, detail: string}
     */
    public static function dkimVerdict(?array $txt): array
    {
        if ($txt === null) {
            return self::unknown();
        }

        $records = self::matching($txt, 'v=DKIM1');

        if ($records === []) {
            return [
                'status' => self::MISSING,
                'detail' => 'No v=DKIM1 record at this host. Check the selector matches the provider.',
            ];
        }

        /*
         * `p=` present but empty is a *revoked* key, not an absent one, and it
         * is the shape that reads as configured from every angle except the
         * one that matters. RFC 6376 §3.6.1.
         */
        if (preg_match('/(?:^|;)\s*p\s*=\s*(?:;|$)/', $records[0]) === 1) {
            return [
                'status' => self::PROBLEM,
                'detail' => 'The public key (p=) is empty, which publishes the key as revoked.',
            ];
        }

        return ['status' => self::OK, 'detail' => 'Public key published.'];
    }

    /**
     * @param list<string>|null $txt
     *
     * @return array{status: string, detail: string}
     */
    public static function dmarcVerdict(?array $txt): array
    {
        if ($txt === null) {
            return self::unknown();
        }

        $records = self::matching($txt, 'v=DMARC1');

        if ($records === []) {
            return [
                'status' => self::MISSING,
                'detail' => 'No _dmarc record. Nothing tells receivers what to do when SPF or DKIM fails.',
            ];
        }

        if (count($records) > 1) {
            return [
                'status' => self::PROBLEM,
                'detail' => sprintf('%d v=DMARC1 records. Receivers ignore the lot — publish one.', count($records)),
            ];
        }

        $policy = self::tagValue($records[0], 'p');

        if ($policy === '') {
            return [
                'status' => self::PROBLEM,
                'detail' => 'No p= policy tag, which makes the record invalid and ignored.',
            ];
        }

        /*
         * p=none is the correct place to *start* — it collects reports without
         * anybody's mail being rejected — so it is not a failure. It is also
         * not protection, and a shop that stops here has a record that does
         * nothing, which is the state most likely to be mistaken for done.
         */
        if ($policy === 'none') {
            return [
                'status' => self::OK,
                'detail' => 'p=none — monitoring only. Move to quarantine once reports show mail passing.',
            ];
        }

        return ['status' => self::OK, 'detail' => 'p=' . $policy . ' — enforcing.'];
    }

    /**
     * @param array{status: string, detail: string} $verdict
     *
     * @return array{record: string, host: string, status: string, detail: string}
     */
    private static function row(string $record, string $host, array $verdict): array
    {
        return [
            'record' => $record,
            'host' => $host,
            'status' => $verdict['status'],
            'detail' => $verdict['detail'],
        ];
    }

    /** @return array{status: string, detail: string} */
    private static function unknown(): array
    {
        return [
            'status' => self::UNKNOWN,
            'detail' => 'DNS could not be queried from this container. Not evidence the record is missing.',
        ];
    }

    /**
     * @param list<string> $txt
     *
     * @return list<string>
     */
    private static function matching(array $txt, string $prefix): array
    {
        $found = [];

        foreach ($txt as $record) {
            $record = trim($record);

            // Case-insensitive: the version tag is spelled both ways in the
            // wild and receivers accept either.
            if (stripos($record, $prefix) === 0) {
                $found[] = $record;
            }
        }

        return $found;
    }

    /** Read one `tag=value` out of a DMARC record, lowercased. */
    private static function tagValue(string $record, string $tag): string
    {
        if (preg_match('/(?:^|;)\s*' . preg_quote($tag, '/') . '\s*=\s*([^;]+)/i', $record, $m) !== 1) {
            return '';
        }

        return strtolower(trim($m[1]));
    }

    private static function normalizeDomain(string $domain): string
    {
        return strtolower(trim(trim($domain), '.'));
    }

    /**
     * `dns_get_record()` returns false on a lookup failure and an empty array
     * when the name resolves with no TXT records. Collapsing those two into one
     * value is what would let an offline container report a missing SPF record.
     *
     * @return callable(string): (list<string>|null)
     */
    private static function systemResolver(): callable
    {
        return static function (string $host): ?array {
            if (!function_exists('dns_get_record')) {
                return null;
            }

            $records = @dns_get_record($host, DNS_TXT);

            if ($records === false) {
                return null;
            }

            $txt = [];

            foreach ($records as $record) {
                if (isset($record['txt']) && is_string($record['txt'])) {
                    $txt[] = $record['txt'];
                }
            }

            return $txt;
        };
    }
}
