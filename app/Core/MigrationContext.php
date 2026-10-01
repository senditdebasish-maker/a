<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * Small, MySQL/MariaDB-compatible schema migration helper.
 *
 * MySQL DDL is not reliably transactional, so every operation is guarded by
 * information_schema and must be safe to re-run after an interrupted upgrade.
 */
final class MigrationContext
{
    private array $messages = [];
    private string $databaseName;

    public function __construct(private readonly PDO $pdo, private readonly bool $dryRun = false)
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'mysql') {
            throw new RuntimeException('Production migrations support MySQL or MariaDB only.');
        }
        $this->databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($this->databaseName === '') {
            throw new RuntimeException('No database is selected.');
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function note(string $message): void
    {
        $this->messages[] = $message;
    }

    public function messages(): array
    {
        return $this->messages;
    }

    public function tableExists(string $table): bool
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table',
            ['schema' => $this->databaseName, 'table' => $table]
        ) > 0;
    }

    public function columnExists(string $table, string $column): bool
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = :schema AND table_name = :table AND column_name = :column',
            ['schema' => $this->databaseName, 'table' => $table, 'column' => $column]
        ) > 0;
    }

    public function indexExists(string $table, string $index): bool
    {
        return (int) $this->value(
            'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = :schema AND table_name = :table AND index_name = :index',
            ['schema' => $this->databaseName, 'table' => $table, 'index' => $index]
        ) > 0;
    }

    public function foreignKeyExists(string $constraint): bool
    {
        return (int) $this->value(
            "SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = :schema AND constraint_name = :constraint AND constraint_type = 'FOREIGN KEY'",
            ['schema' => $this->databaseName, 'constraint' => $constraint]
        ) > 0;
    }

    public function createTable(string $table, string $sql): void
    {
        $this->identifier($table);
        if ($this->tableExists($table)) {
            $this->note("SKIP table {$table} already exists");
            return;
        }
        $this->execute($sql, [], "CREATE table {$table}");
    }

    public function addColumn(string $table, string $column, string $definition): void
    {
        $table = $this->identifier($table);
        $column = $this->identifier($column);
        if ($this->columnExists($table, $column)) {
            $this->note("SKIP column {$table}.{$column} already exists");
            return;
        }
        $this->execute("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}", [], "ADD column {$table}.{$column}");
    }

    public function modifyColumn(string $table, string $column, string $definition): void
    {
        $table = $this->identifier($table);
        $column = $this->identifier($column);
        if (!$this->columnExists($table, $column)) {
            throw new RuntimeException("Cannot modify missing column {$table}.{$column}.");
        }
        $this->execute("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$definition}", [], "MODIFY column {$table}.{$column}");
    }

    public function addIndex(string $table, string $index, string $columns, bool $unique = false): void
    {
        $table = $this->identifier($table);
        $index = $this->identifier($index);
        if ($this->indexExists($table, $index)) {
            $this->note("SKIP index {$index} already exists");
            return;
        }
        $kind = $unique ? 'UNIQUE INDEX' : 'INDEX';
        $this->execute("ALTER TABLE `{$table}` ADD {$kind} `{$index}` ({$columns})", [], "ADD index {$index}");
    }

    public function addForeignKey(string $table, string $constraint, string $sql): void
    {
        $table = $this->identifier($table);
        $constraint = $this->identifier($constraint);
        if ($this->foreignKeyExists($constraint)) {
            $this->note("SKIP foreign key {$constraint} already exists");
            return;
        }
        $this->execute("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` {$sql}", [], "ADD foreign key {$constraint}");
    }

    public function execute(string $sql, array $params = [], ?string $label = null): int
    {
        $summary = $label ?: preg_replace('/\s+/', ' ', trim($sql));
        if ($this->dryRun) {
            $this->note('PLAN ' . $summary);
            return 0;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $this->note('DONE ' . $summary);
        return $statement->rowCount();
    }

    public function value(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }

    public function assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    private function identifier(string $value): string
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $value)) {
            throw new RuntimeException('Unsafe SQL identifier: ' . $value);
        }
        return $value;
    }
}
