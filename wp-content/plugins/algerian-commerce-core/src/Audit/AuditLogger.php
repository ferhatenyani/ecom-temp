<?php

declare(strict_types=1);

namespace AlgerianCommerce\Audit;

use AlgerianCommerce\Core\Logger;
use Throwable;

/**
 * Records privileged and state-changing actions.
 *
 * Call this from services, not controllers — the service knows what actually
 * happened, and an audit trail written from the edge records intent rather
 * than outcome.
 */
final class AuditLogger
{
    public function __construct(
        private readonly AuditRepository $repository,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param array<string, mixed> $metadata Redacted by AuditEvent before storage.
     */
    public function record(
        string $action,
        string $resourceType = '',
        string|int $resourceId = '',
        array $metadata = []
    ): ?int {
        try {
            $event = new AuditEvent(
                $action,
                $resourceType,
                (string) $resourceId,
                $this->currentUserId(),
                $this->currentUserLogin(),
                $this->clientIp(),
                $metadata
            );

            $id = $this->repository->insert($event);

            if ($id === null) {
                $this->logger->error('Audit write failed', [
                    'action' => $action,
                    'resource_type' => $resourceType,
                ]);
            }

            return $id;
        } catch (Throwable $throwable) {
            /*
             * A failed audit write must not abort the business operation that
             * triggered it — an unwritable log table would otherwise take the
             * whole API down. It is logged loudly instead, and the health
             * endpoint surfaces a broken database separately.
             */
            $this->logger->error('Audit write threw', [
                'action' => $action,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }
    }

    private function currentUserId(): int
    {
        return function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    }

    private function currentUserLogin(): string
    {
        if (!function_exists('wp_get_current_user')) {
            return '';
        }

        $user = wp_get_current_user();

        return isset($user->user_login) ? (string) $user->user_login : '';
    }

    /**
     * REMOTE_ADDR only.
     *
     * X-Forwarded-For is trivially spoofed by the client and is only
     * meaningful behind a proxy you control. Recording a forged address in an
     * append-only audit trail is worse than recording the proxy's.
     */
    private function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        if (!is_string($remote) || filter_var($remote, FILTER_VALIDATE_IP) === false) {
            return '';
        }

        return $remote;
    }
}
