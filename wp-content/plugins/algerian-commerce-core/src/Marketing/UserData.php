<?php

declare(strict_types=1);

namespace AlgerianCommerce\Marketing;

/**
 * Customer identifiers, hashed before they cross any boundary — roadmap §62b.
 *
 * Pure: no WordPress, no network, so the normalisation that decides whether a
 * conversion matches a real person is unit-testable against known vectors.
 *
 * **Raw PII never leaves this class.** The constructor is private and the
 * factory hashes on the way in, so there is no object anywhere in the system
 * holding a customer's email address on its way to an advertising network — an
 * adapter cannot leak what it was never given, and a `var_dump` in the wrong
 * place cannot either.
 *
 * Hashing is **not** anonymisation. A SHA-256 of an email address is a stable
 * identifier for that person, which is the entire point of sending it, and the
 * space of real email addresses is small enough to brute force. This is
 * customer PII going to a third party; docs/SECURITY.md's review applies in
 * full, and a shop must have a lawful basis and a privacy notice for it.
 *
 * ## The normalisation is the contract
 *
 * Meta matches on the hash, so a hash of `Ali@Example.COM ` matches nothing at
 * all while a hash of `ali@example.com` matches a person. Every rule below is
 * from Meta's customer information parameters documentation, read 2026-08-16.
 * The same trimmed-lowercase-then-SHA-256 convention is what TikTok and Google
 * use, which is why this sits in `Marketing/` rather than in the Meta adapter.
 */
final class UserData
{
    /**
     * Algeria's calling code, for turning a local number into the
     * country-code-prefixed digits Meta wants.
     *
     * A shop in Algeria stores `0551020304`, and Meta's rule is "remove
     * symbols, letters and leading zeros; include the country code". Without
     * this the leading zero would be stripped to `551020304`, which is not a
     * phone number anywhere, and every phone match would silently miss.
     */
    public const DEFAULT_CALLING_CODE = '213';

    /**
     * The fields Meta matches **literally** and instructs must never be hashed.
     *
     * A hashed IP or user agent is not a weaker signal, it is a dead one: Meta
     * compares them byte for byte, so hashing them sends a value that can never
     * match while looking exactly like a field that is working.
     */
    public const PLAIN_KEYS = ['client_ip_address', 'client_user_agent', 'fbc', 'fbp'];

    /**
     * @param array<string, string> $hashed   already SHA-256, ready to send
     * @param array<string, string> $plain    fields Meta requires **unhashed**
     */
    private function __construct(
        public readonly array $hashed,
        public readonly array $plain
    ) {
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    /**
     * Rehydrate from a queued payload.
     *
     * The values are already hashed — they were hashed before they were stored,
     * because the queue outlives the request and a table holding raw customer
     * emails destined for an ad network is exactly what §62b's rules forbid.
     * This constructor therefore hashes **nothing**: doing so would double-hash
     * on every drain and match nobody.
     *
     * @param array<string, string> $hashed
     * @param array<string, string> $plain
     */
    public static function fromStored(array $hashed, array $plain): self
    {
        return new self($hashed, $plain);
    }

    /**
     * Build from a customer's real details.
     *
     * Every value is normalised, then hashed, then forgotten. An empty or
     * unusable field is **omitted** rather than sent as a hash of the empty
     * string — `e3b0c442…` is a valid SHA-256 that matches nobody, and sending
     * it for every customer with no surname would tell Meta that thousands of
     * different people share one.
     *
     * @param array<string, string> $fields email, phone, first_name, last_name,
     *                                      city, state, zip, country, external_id
     * @param array<string, string> $context client_ip_address, client_user_agent,
     *                                      fbc, fbp — sent as-is, never hashed
     */
    public static function fromCustomer(array $fields, array $context = []): self
    {
        $normalised = [
            'em' => self::normalizeEmail((string) ($fields['email'] ?? '')),
            'ph' => self::normalizePhone((string) ($fields['phone'] ?? '')),
            'fn' => self::normalizeName((string) ($fields['first_name'] ?? '')),
            'ln' => self::normalizeName((string) ($fields['last_name'] ?? '')),
            'ct' => self::normalizeCity((string) ($fields['city'] ?? '')),
            'st' => self::normalizeName((string) ($fields['state'] ?? '')),
            'zp' => self::normalizeZip((string) ($fields['zip'] ?? '')),
            'country' => self::normalizeCountry((string) ($fields['country'] ?? '')),
            'external_id' => trim((string) ($fields['external_id'] ?? '')),
        ];

        $hashed = [];

        foreach ($normalised as $key => $value) {
            if ($value !== '') {
                $hashed[$key] = hash('sha256', $value);
            }
        }

        /*
         * Never hashed, on Meta's explicit instruction: an IP or a user agent
         * is matched literally, and a hashed one is silently useless. `fbc` and
         * `fbp` are the browser's own click and browser ids, which only the
         * storefront can read — they are the strongest match signal there is,
         * which is why the endpoint accepts them.
         */
        $plain = [];

        foreach (self::PLAIN_KEYS as $key) {
            $value = trim((string) ($context[$key] ?? ''));

            if ($value !== '') {
                $plain[$key] = $value;
            }
        }

        return new self($hashed, $plain);
    }

    /** @return array<string, string> the `user_data` object, ready to send */
    public function toArray(): array
    {
        return $this->hashed + $this->plain;
    }

    public function isEmpty(): bool
    {
        return $this->hashed === [] && $this->plain === [];
    }

    /**
     * How many identifiers this event carries.
     *
     * Meta's match rate rises with the number of parameters, and an event with
     * none is worth nothing — the service uses this to refuse to queue one
     * rather than spend a request finding out.
     */
    public function strength(): int
    {
        return count($this->hashed);
    }

    public static function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));

        // A malformed address is dropped rather than hashed: it can match
        // nobody, and sending it only tells Meta the shop has bad data.
        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? '' : $email;
    }

    /**
     * Digits only, with a country code, no leading zeros.
     *
     * `0551 02 03 04` → `213551020304`, and `+213 551 02 03 04` → the same, so
     * a customer who typed either form is the same person to Meta.
     */
    public static function normalizePhone(string $phone, string $callingCode = self::DEFAULT_CALLING_CODE): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);

        if ($digits === '') {
            return '';
        }

        // Already international: 00213… is how a number is often stored.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            // A national number: the leading zero is the trunk prefix and is
            // replaced by the country code, not merely dropped.
            $digits = $callingCode . ltrim($digits, '0');
        } elseif (!str_starts_with($digits, $callingCode)) {
            $digits = $callingCode . $digits;
        }

        // Shorter than any real international number: almost certainly a
        // truncated field, and a wrong hash is worse than a missing one.
        return strlen($digits) < 8 ? '' : $digits;
    }

    /**
     * Lowercase, no punctuation. Accents are kept — Meta hashes UTF-8.
     *
     * ASSUMPTION (unverified — no ad account): that a hyphen is **removed**
     * rather than replaced with a space, so `El-Hadi` normalises to `elhadi`
     * and not `el hadi`. Meta's rule reads "lowercase only with no
     * punctuation", which says to remove it and says nothing about
     * substituting a space — but the two produce different hashes, and
     * compound names are common enough here that the difference is a real
     * slice of the match rate. Worth confirming against a Test Events run.
     */
    public static function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = (string) preg_replace('/[\p{P}\p{S}]+/u', '', $name);

        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /** Lowercase, and no spaces at all: "Bordj Bou Arréridj" → "bordjbouarréridj". */
    public static function normalizeCity(string $city): string
    {
        return (string) preg_replace('/\s+/u', '', self::normalizeName($city));
    }

    /** Lowercase, no spaces or dashes. */
    public static function normalizeZip(string $zip): string
    {
        return strtolower((string) preg_replace('/[\s\-]+/', '', trim($zip)));
    }

    /** ISO 3166-1 alpha-2, lowercase. Anything else is dropped. */
    public static function normalizeCountry(string $country): string
    {
        $country = strtolower(trim($country));

        return preg_match('/^[a-z]{2}$/', $country) === 1 ? $country : '';
    }
}
