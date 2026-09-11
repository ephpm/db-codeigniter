<?php

declare(strict_types=1);

namespace Ephpm\Db\CodeIgniter\Tests;

use Ephpm\Db\CodeIgniter\DbOpsInterface;

/**
 * A {@see DbOpsInterface} that records the SQL it is handed instead of
 * touching a database — used to assert what the driver ships to the bridge
 * (e.g. the exact `BEGIN` / `COMMIT` / `ROLLBACK` transaction control).
 */
final class RecordingOps implements DbOpsInterface
{
    /** @var list<string> every SQL string passed to run() */
    public array $log = [];

    /** When set, run() throws for any SQL containing this needle. */
    public ?string $failOn = null;

    public function query(string $sql, array $params = []): array
    {
        $this->log[] = $sql;

        return [];
    }

    public function execute(string $sql, array $params = []): array
    {
        $this->log[] = $sql;

        return ['affected_rows' => 0, 'last_insert_id' => 0];
    }

    public function run(string $sql, array $params = []): array
    {
        $this->log[] = $sql;

        if ($this->failOn !== null && str_contains($sql, $this->failOn)) {
            throw new \Exception('SQLSTATE[HY000]: recording ops failure', 1105);
        }

        return [
            'has_rowset'     => false,
            'rows'           => [],
            'columns'        => [],
            'affected_rows'  => 0,
            'last_insert_id' => 0,
        ];
    }
}
