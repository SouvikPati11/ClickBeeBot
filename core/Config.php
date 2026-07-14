<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable accessor over the bootstrap configuration file.
 *
 * Holds only the values that must exist before a database connection is
 * available. All runtime, admin-editable settings live in {@see Settings}.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException('Configuration file not found. Run the installer.');
        }

        /** @var array<string, mixed> $data */
        $data = require $path;

        return new self($data);
    }

    /**
     * Dot-notation getter, e.g. get('db.host').
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->data;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function isInstalled(): bool
    {
        return (bool) $this->get('installed', false);
    }

    public function isDebug(): bool
    {
        return $this->get('environment', 'production') === 'debug';
    }

    public function baseUrl(): string
    {
        return rtrim((string) $this->get('base_url', ''), '/');
    }
}
