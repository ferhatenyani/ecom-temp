<?php

declare(strict_types=1);

namespace AlgerianCommerce\API;

use AlgerianCommerce\Core\Logger;
use Throwable;
use WP_REST_Request;
use WP_REST_Response;

use const AlgerianCommerce\REST_NAMESPACE;

/**
 * Base class for every controller in the plugin.
 *
 * Controllers parse the request, call exactly one service, and format the
 * result — no business logic, no direct $wpdb or WC_* access
 * (docs/ARCHITECTURE.md §2).
 */
abstract class AbstractController
{
    public function __construct(protected readonly Logger $logger)
    {
    }

    abstract public function registerRoutes(): void;

    protected function restNamespace(): string
    {
        return REST_NAMESPACE;
    }

    /**
     * Wrap a handler so every route shares one error contract.
     *
     * An ApiException becomes its declared code and status. Anything else is
     * logged with its real message and returned as a generic internal error —
     * clients never see a stack trace or an implementation detail.
     */
    protected function handle(callable $handler): callable
    {
        return function (WP_REST_Request $request) use ($handler): WP_REST_Response {
            try {
                return $handler($request);
            } catch (ApiException $exception) {
                return Response::fromException($exception);
            } catch (Throwable $throwable) {
                $this->logger->error('Unhandled exception in REST handler', [
                    'route' => $request->get_route(),
                    'method' => $request->get_method(),
                    'exception' => $throwable::class,
                    'message' => $throwable->getMessage(),
                ]);

                return Response::fromException(ApiException::internal());
            }
        };
    }
}
