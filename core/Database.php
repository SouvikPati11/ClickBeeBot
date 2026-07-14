<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Thin, safe wrapper around PDO.
 *
 * Every query goes through prepared statements. Callers never build SQL from
 * user input directly; identifiers are whitelisted at the repository layer.
 */
final class Database
{
    private PDO $pdo;

    /**
     * @param array<string, mixed> $config The 'db' section of the config file.
     */
    public function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'] ?? 'localhost',
            (int) ($config['port'] ?? 3306),
            $config['name'] ?? '',
            $config['charset'] ?? 'utf8mb4'
        );

        try {
            $this->pdo = new PDO($dsn, $config['user'] ?? '', $config['pass'] ?? '', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode());
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function column(string $sql, array $params = []): mixed
    {
        return $this->run($sql, $params)->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data Column => value map.
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (%s)',
            $table,
            implode('`, `', $columns),
            implode(', ', $placeholders)
        );

        $this->run($sql, $this->prefixKeys($data));

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed>       $data  Column => value map.
     * @param array<string, mixed>       $where Column => value map (AND joined).
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = [];
        foreach (array_keys($data) as $column) {
            $set[] = sprintf('`%s` = :set_%s', $column, $column);
        }

        $conditions = [];
        foreach (array_keys($where) as $column) {
            $conditions[] = sprintf('`%s` = :where_%s', $column, $column);
        }

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            $table,
            implode(', ', $set),
            implode(' AND ', $conditions)
        );

        $params = [];
        foreach ($data as $key => $value) {
            $params['set_' . $key] = $value;
        }
        foreach ($where as $key => $value) {
            $params['where_' . $key] = $value;
        }

        return $this->run($sql, $params)->rowCount();
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prefixKeys(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $out[':' . $key] = $value;
        }
        return $out;
    }
}
