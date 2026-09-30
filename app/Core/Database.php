<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final class Database
{
    private static ?self $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $driver = (string) Config::get('database.driver', 'mysql');
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            if ($driver === 'sqlite') {
                $path = (string) Config::get('database.sqlite_path');
                $this->pdo = new PDO('sqlite:' . $path, null, null, $options);
                $this->pdo->exec('PRAGMA foreign_keys = ON');
            } else {
                $host = Config::get('database.host');
                $port = Config::get('database.port');
                $name = Config::get('database.database');
                $charset = Config::get('database.charset', 'utf8mb4');
                $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
                $this->pdo = new PDO($dsn, (string) Config::get('database.username'), (string) Config::get('database.password'), $options);
            }
        } catch (PDOException $exception) {
            throw new RuntimeException('Database connection failed. Check the installation settings.', 0, $exception);
        }
    }

    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $record = $this->query($sql, $params)->fetch();
        return $record === false ? null : $record;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        return $this->query($sql, $params)->fetchColumn();
    }

    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $quoted = implode(', ', array_map(static fn ($column) => '`' . $column . '`', $columns));
        $placeholders = implode(', ', array_map(static fn ($column) => ':' . $column, $columns));
        $this->query("INSERT INTO `{$table}` ({$quoted}) VALUES ({$placeholders})", $data);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        $params = [];
        foreach ($data as $key => $value) {
            $sets[] = '`' . $key . '` = :set_' . $key;
            $params['set_' . $key] = $value;
        }
        return $this->query("UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE {$where}", $params + $whereParams)->rowCount();
    }

    public function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }
}
