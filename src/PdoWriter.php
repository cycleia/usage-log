<?php
declare(strict_types=1);

namespace Cycleia\UsageLog;

use PDO;
use RuntimeException;

/**
 * The default Writer: a prepared INSERT on a PDO connection.
 */
final class PdoWriter implements Writer
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(string $table, array $row): void
    {
        $columns = array_keys($row);
        foreach ($columns as $column) {
            if (!preg_match('/^[a-z_]+$/', $column)) {
                throw new RuntimeException('Invalid column name: ' . $column);
            }
        }
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (:%s)',
            $table,
            implode('`, `', $columns),
            implode(', :', $columns)
        ));
        foreach ($row as $column => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue(':' . $column, $value, $type);
        }
        if ($statement->execute() === false) {
            throw new RuntimeException('Usage event insert failed');
        }
    }
}
