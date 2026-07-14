<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Database-backed, file-cached settings store.
 *
 * All admin-editable configuration (bot name, fees, referral %, minimums,
 * maintenance mode, currency, …) lives in the `settings` table as typed
 * key/value rows. To avoid a query on every webhook hit, the full set is
 * cached to a single PHP file and invalidated whenever a value changes.
 */
final class Settings
{
    private Database $db;
    private string $cacheFile;

    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(Database $db, string $cacheDir)
    {
        $this->db = $db;
        $this->cacheFile = rtrim($cacheDir, '/') . '/settings.cache.php';
    }

    public function get(string $key, string $default = ''): string
    {
        $this->ensureLoaded();
        return $this->cache[$key] ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, (string) $default);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function float(string $key, float $default = 0.0): float
    {
        $value = $this->get($key, (string) $default);
        return is_numeric($value) ? (float) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default ? '1' : '0');
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function set(string $key, string $value): void
    {
        $exists = $this->db->column(
            'SELECT 1 FROM settings WHERE setting_key = ? LIMIT 1',
            [$key]
        );

        if ($exists) {
            $this->db->update('settings', ['setting_value' => $value, 'updated_at' => date('Y-m-d H:i:s')], ['setting_key' => $key]);
        } else {
            $this->db->insert('settings', [
                'setting_key'   => $key,
                'setting_value' => $value,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        }

        $this->flush();
    }

    /**
     * @param array<string, string> $pairs
     */
    public function setMany(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            $this->set($key, $value);
        }
    }

    public function flush(): void
    {
        $this->cache = null;
        if (is_file($this->cacheFile)) {
            @unlink($this->cacheFile);
        }
    }

    private function ensureLoaded(): void
    {
        if ($this->cache !== null) {
            return;
        }

        if (is_file($this->cacheFile)) {
            /** @var array<string, string> $data */
            $data = require $this->cacheFile;
            $this->cache = $data;
            return;
        }

        $this->cache = $this->loadFromDatabase();
        $this->writeCache($this->cache);
    }

    /**
     * @return array<string, string>
     */
    private function loadFromDatabase(): array
    {
        $rows = $this->db->fetchAll('SELECT setting_key, setting_value FROM settings');
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['setting_key']] = (string) $row['setting_value'];
        }
        return $out;
    }

    /**
     * @param array<string, string> $data
     */
    private function writeCache(array $data): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $this->cacheFile,
            '<?php return ' . var_export($data, true) . ';',
            LOCK_EX
        );
    }
}
