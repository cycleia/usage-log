<?php
declare(strict_types=1);

namespace Cycleia\UsageLog;

/**
 * Writes one already-sanitised row. Implement this to write through a
 * framework's own connection (CakePHP's Connection::insert(), Laravel's
 * query builder) instead of handing the Recorder a PDO.
 */
interface Writer
{
    /**
     * @param string $table Validated table name.
     * @param array<string, scalar|null> $row Column => value.
     */
    public function insert(string $table, array $row): void;
}
