<?php

declare(strict_types=1);

namespace AlgerianCommerce\Audit;

use AlgerianCommerce\Core\Config;
use AlgerianCommerce\Core\Logger;
use AlgerianCommerce\Security\ClientIp;
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
        private readonly Logger $logger,
        /*
         * Optional so every existing construction keeps working — the §84
         * precedent. Absent, `ClientIp` reads no header and returns
         * REMOTE_ADDR, which is what this class did before §86, so a wiring
         * mistake fails towards trusting less rather than more.
         */
        private readonly ?Config $config = null
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
     * One rule, in `Security\ClientIp` — see it for why the header is read only
     * from a configured proxy and never from anyone else.
     *
     * This used to say "REMOTE_ADDR only, because X-Forwarded-For is trivially
     * spoofed and is only meaningful behind a proxy you control". Both halves
     * still hold; §86 put a proxy in front, so the condition in the second half
     * is now something the deployment states rather than something we assume
     * away. With `AC_TRUSTED_PROXIES` unset this returns exactly what it
     * always did.
     *
     * An append-only trail is the reason to be strict: a forged address here
     * cannot be corrected later.
     */
    private function clientIp(): string
    {
        return ClientIp::resolve($_SERVER, $this->config?->get('AC_TRUSTED_PROXIES'));
    }
}
