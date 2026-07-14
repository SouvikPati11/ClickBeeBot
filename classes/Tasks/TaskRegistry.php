<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Core\Database;
use App\Telegram\TelegramApi;

/**
 * Resolves task-type handlers dynamically from the database.
 *
 * The `campaign_task_types` table maps each type_key to a handler_class, so
 * enabling a brand-new task type is a data change (plus one handler class),
 * never an edit to existing engine code.
 */
final class TaskRegistry
{
    /** @var array<string, TaskType> */
    private array $instances = [];

    /** @var array<string, array<string, mixed>>|null */
    private ?array $rows = null;

    public function __construct(
        private Database $db,
        private TelegramApi $telegram,
    ) {
    }

    public function get(string $typeKey): ?TaskType
    {
        if (isset($this->instances[$typeKey])) {
            return $this->instances[$typeKey];
        }

        $config = $this->configFor($typeKey);
        if ($config === null || empty($config['enabled'])) {
            return null;
        }

        $class = (string) ($config['handler_class'] ?? '');
        if ($class === '' || !class_exists($class) || !is_subclass_of($class, TaskType::class)) {
            return null;
        }

        /** @var TaskType $instance */
        $instance = new $class($config, $this->telegram);
        return $this->instances[$typeKey] = $instance;
    }

    /** @return array<string, mixed>|null */
    public function configFor(string $typeKey): ?array
    {
        $this->loadRows();
        return $this->rows[$typeKey] ?? null;
    }

    /**
     * All enabled task types ordered for menu display.
     *
     * @return array<int, array<string, mixed>>
     */
    public function enabled(): array
    {
        $this->loadRows();
        $enabled = array_filter($this->rows ?? [], static fn (array $r): bool => (bool) $r['enabled']);
        usort($enabled, static fn (array $a, array $b): int => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));
        return $enabled;
    }

    private function loadRows(): void
    {
        if ($this->rows !== null) {
            return;
        }
        $this->rows = [];
        foreach ($this->db->fetchAll('SELECT * FROM campaign_task_types') as $row) {
            $this->rows[(string) $row['type_key']] = $row;
        }
    }
}
