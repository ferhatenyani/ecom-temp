<?php

declare(strict_types=1);

namespace AlgerianCommerce\Core;

/**
 * Minimal PSR-4 autoloader used when Composer's autoloader is not present.
 *
 * Kept dependency-free and self-contained: it is required directly by the
 * plugin bootstrap before any other class exists.
 */
final class Autoloader
{
    public function __construct(
        private readonly string $prefix,
        private readonly string $baseDir
    ) {
    }

    public function register(): void
    {
        spl_autoload_register([$this, 'load']);
    }

    public function load(string $class): void
    {
        if (!str_starts_with($class, $this->prefix)) {
            return;
        }

        $relative = substr($class, strlen($this->prefix));
        $path = rtrim($this->baseDir, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace('\\', DIRECTORY_SEPARATOR, $relative)
            . '.php';

        if (is_readable($path)) {
            require_once $path;
        }
    }
}
