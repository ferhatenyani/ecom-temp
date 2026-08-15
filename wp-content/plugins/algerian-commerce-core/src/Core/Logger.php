<?php

declare(strict_types=1);

namespace AlgerianCommerce\Core;

/**
 * Basic logging foundation.
 *
 * Context is redacted before it is written: docs/SECURITY.md forbids logging
 * API secrets, payment secrets, and full authentication headers, and a logger
 * that relies on every caller remembering that will eventually leak one.
 */
final class Logger
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';

    private const PRIORITY = [
        self::DEBUG => 10,
        self::INFO => 20,
        self::WARNING => 30,
        self::ERROR => 40,
    ];

    /** Any context key containing one of these is masked. */
    private const SENSITIVE = [
        'password', 'secret', 'token', 'authorization', 'auth',
        'api_key', 'apikey', 'key', 'signature', 'credential',
        'card', 'cvv', 'pan', 'cookie',
    ];

    /**
     * Keys masked only on an exact match — docs/SECURITY.md, §55.
     *
     * A Yalidine parcel comes back with `label` and `labels`: **URLs that carry
     * an access token**, so anyone holding one can fetch the shipping label and
     * with it the customer's name, phone and full address, without a credential
     * of their own. They belong in `ac_shipments.metadata` — an operator has to
     * print the label — but never in a log, where they outlive the parcel and
     * cannot be revoked.
     *
     * Exact rather than substring, because "label" is an ordinary English word
     * that also names harmless things (`provider_status_label` is ZR Express's
     * wording for a parcel state). Widening the substring list with it would
     * mask those too — the same over-matching that already redacts a hashed
     * rate-limit bucket into uselessness.
     */
    private const SENSITIVE_EXACT = ['label', 'labels'];

    public const MASK = '[redacted]';

    public function __construct(
        private readonly string $channel = 'core',
        private readonly string $minLevel = self::INFO
    ) {
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log(self::DEBUG, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        error_log($this->format($level, $message, $context));
    }

    public function shouldLog(string $level): bool
    {
        $current = self::PRIORITY[$level] ?? self::PRIORITY[self::INFO];
        $floor = self::PRIORITY[$this->minLevel] ?? self::PRIORITY[self::INFO];

        return $current >= $floor;
    }

    /** @param array<string, mixed> $context */
    public function format(string $level, string $message, array $context = []): string
    {
        $line = sprintf('[algerian-commerce][%s][%s] %s', $this->channel, $level, $message);

        if ($context !== []) {
            $encoded = json_encode(self::redact($context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $line .= ' ' . ($encoded === false ? '{"context":"unencodable"}' : $encoded);
        }

        return $line;
    }

    /**
     * Recursively mask values whose key looks sensitive.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public static function redact(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $safe[$key] = self::MASK;
                continue;
            }

            $safe[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $safe;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $needle = strtolower($key);

        if (in_array($needle, self::SENSITIVE_EXACT, true)) {
            return true;
        }

        foreach (self::SENSITIVE as $marker) {
            if (str_contains($needle, $marker)) {
                return true;
            }
        }

        return false;
    }
}
