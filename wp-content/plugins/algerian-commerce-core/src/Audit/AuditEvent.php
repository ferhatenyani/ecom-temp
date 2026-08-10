<?php

declare(strict_types=1);

namespace AlgerianCommerce\Audit;

use AlgerianCommerce\Core\Logger;
use InvalidArgumentException;

/**
 * One audit record, validated and shaped for storage.
 *
 * Pure — no WordPress, no database — so the rules that matter can be tested
 * directly: metadata is redacted before it is ever written, and every field
 * is truncated to its column width. MySQL in strict mode rejects an
 * over-length value outright, which would turn a long user agent or SKU into
 * a failed audit write on an otherwise fine operation.
 */
final class AuditEvent
{
    public const MAX_ACTION = 100;
    public const MAX_RESOURCE_TYPE = 50;
    public const MAX_RESOURCE_ID = 64;
    public const MAX_ACTOR_LOGIN = 60;
    public const MAX_IP = 45;

    public readonly string $action;
    public readonly string $resourceType;
    public readonly string $resourceId;
    public readonly int $actorId;
    public readonly string $actorLogin;
    public readonly string $ipAddress;
    /** @var array<string, mixed> */
    public readonly array $metadata;
    public readonly string $createdAt;

    /** @param array<string, mixed> $metadata */
    public function __construct(
        string $action,
        string $resourceType = '',
        string $resourceId = '',
        int $actorId = 0,
        string $actorLogin = '',
        string $ipAddress = '',
        array $metadata = [],
        ?string $createdAt = null
    ) {
        $action = trim($action);

        if ($action === '') {
            throw new InvalidArgumentException('An audit event requires an action.');
        }

        $this->action = self::clip($action, self::MAX_ACTION);
        $this->resourceType = self::clip(trim($resourceType), self::MAX_RESOURCE_TYPE);
        $this->resourceId = self::clip(trim($resourceId), self::MAX_RESOURCE_ID);
        $this->actorId = max(0, $actorId);
        $this->actorLogin = self::clip(trim($actorLogin), self::MAX_ACTOR_LOGIN);
        $this->ipAddress = self::clip(trim($ipAddress), self::MAX_IP);
        // Redact here, not at the call site — a caller that forgets is how
        // secrets end up in an append-only table.
        $this->metadata = Logger::redact($metadata);
        $this->createdAt = $createdAt ?? gmdate('Y-m-d H:i:s');
    }

    /**
     * Row for $wpdb->insert(), matching migration 001.
     *
     * @return array<string, string|int>
     */
    public function toRow(): array
    {
        return [
            'actor_id' => $this->actorId,
            'actor_login' => $this->actorLogin,
            'action' => $this->action,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'ip_address' => $this->ipAddress,
            'metadata' => $this->encodedMetadata(),
            'created_at' => $this->createdAt,
        ];
    }

    /** Placeholders for $wpdb->insert(), in the same order as toRow(). */
    public function rowFormats(): array
    {
        return ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];
    }

    public function encodedMetadata(): string
    {
        if ($this->metadata === []) {
            return '';
        }

        $encoded = json_encode($this->metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }

    /** Truncate on characters, not bytes, so multibyte text is never split. */
    private static function clip(string $value, int $limit): string
    {
        return mb_substr($value, 0, $limit);
    }
}
