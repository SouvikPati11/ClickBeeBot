<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Schema installer and migration runner.
 *
 * Runs the canonical schema (idempotent CREATE TABLE IF NOT EXISTS) and any
 * incremental migration files under database/migrations. Existing user and
 * financial data is never dropped — migrations only add or alter safely.
 */
final class Migrator
{
    private Database $db;
    private string $basePath;

    public function __construct(Database $db, string $basePath)
    {
        $this->db = $db;
        $this->basePath = rtrim($basePath, '/');
    }

    /**
     * Create all base tables. Safe to run repeatedly.
     */
    public function installSchema(): void
    {
        /** @var array<string, string> $schema */
        $schema = require $this->basePath . '/database/schema.php';
        foreach ($schema as $sql) {
            $this->db->pdo()->exec($sql);
        }
    }

    /**
     * Seed default settings and task types (only inserts missing rows).
     */
    public function seed(): void
    {
        /** @var array{settings: array<string,string>, task_types: array<int, array<string,mixed>>} $seeds */
        $seeds = require $this->basePath . '/database/seeds.php';

        foreach ($seeds['settings'] as $key => $value) {
            $exists = $this->db->column('SELECT 1 FROM settings WHERE setting_key = ? LIMIT 1', [$key]);
            if (!$exists) {
                $this->db->insert('settings', ['setting_key' => $key, 'setting_value' => (string) $value]);
            }
        }

        foreach ($seeds['task_types'] as $type) {
            $exists = $this->db->column('SELECT 1 FROM campaign_task_types WHERE type_key = ? LIMIT 1', [$type['type_key']]);
            if (!$exists) {
                $this->db->insert('campaign_task_types', $type);
            }
        }
    }

    /**
     * Apply pending incremental migrations from database/migrations/*.php.
     * Each file returns ['version' => string, 'up' => array<string> of SQL].
     *
     * @return array<int, string> Versions applied in this run.
     */
    public function migrate(): array
    {
        $applied = [];
        $dir = $this->basePath . '/database/migrations';
        if (!is_dir($dir)) {
            return $applied;
        }

        $files = glob($dir . '/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            /** @var array{version: string, up: array<int, string>} $migration */
            $migration = require $file;
            $version = $migration['version'];

            $done = $this->db->column('SELECT 1 FROM migrations WHERE version = ? LIMIT 1', [$version]);
            if ($done) {
                continue;
            }

            $this->db->transaction(function (Database $db) use ($migration, $version): void {
                foreach ($migration['up'] as $sql) {
                    $db->pdo()->exec($sql);
                }
                $db->insert('migrations', ['version' => $version]);
            });

            $applied[] = $version;
        }

        return $applied;
    }
}
