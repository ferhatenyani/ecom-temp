<?php

declare(strict_types=1);

namespace AlgerianCommerce\API;

use AlgerianCommerce\Core\Logger;

/**
 * Owns the algerian-commerce/v1 namespace and the controllers registered
 * under it. New domains add a controller here — nothing else registers routes.
 */
final class RestApi
{
    /** @var list<AbstractController> */
    private array $controllers;

    /** @param list<AbstractController> $controllers */
    public function __construct(private readonly Logger $logger, array $controllers = [])
    {
        $this->controllers = $controllers;
    }

    public function add(AbstractController $controller): void
    {
        $this->controllers[] = $controller;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);

        (new ErrorNormalizer())->register();
    }

    public function registerRoutes(): void
    {
        foreach ($this->controllers as $controller) {
            $controller->registerRoutes();
        }

        $this->logger->debug('REST routes registered', [
            'controllers' => count($this->controllers),
        ]);
    }
}
