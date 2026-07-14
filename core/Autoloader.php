<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lightweight PSR-4 autoloader.
 *
 * The platform runs on shared hosting where the Composer CLI is not
 * available, so autoloading is handled manually via an explicit prefix map.
 */
final class Autoloader
{
    /** @var array<string, string> Namespace prefix => base directory. */
    private array $prefixes = [];

    public function register(): void
    {
        spl_autoload_register([$this, 'load']);
    }

    public function addNamespace(string $prefix, string $baseDir): void
    {
        $prefix = trim($prefix, '\\') . '\\';
        $baseDir = rtrim($baseDir, '/\\') . '/';
        $this->prefixes[$prefix] = $baseDir;
    }

    public function load(string $class): void
    {
        foreach ($this->prefixes as $prefix => $baseDir) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }
}
