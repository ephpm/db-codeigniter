<?php

declare(strict_types=1);

namespace Ephpm\Db\CodeIgniter\Tests;

use Ephpm\Db\CodeIgniter\Connection;
use Ephpm\Db\CodeIgniter\ResultId;

/**
 * Exposes the driver's protected `execute()` and transaction primitives so
 * they can be unit-tested without routing through CodeIgniter's full query
 * pipeline (which would require a booted application for its Events/Config
 * services).
 */
final class TestableConnection extends Connection
{
    /**
     * @return false|ResultId
     */
    public function executeRaw(string $sql)
    {
        return $this->execute($sql);
    }

    public function transBeginRaw(): bool
    {
        return $this->_transBegin();
    }

    public function transCommitRaw(): bool
    {
        return $this->_transCommit();
    }

    public function transRollbackRaw(): bool
    {
        return $this->_transRollback();
    }
}
