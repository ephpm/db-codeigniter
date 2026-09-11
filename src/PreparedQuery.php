<?php

declare(strict_types=1);

namespace Ephpm\Db\CodeIgniter;

use BadMethodCallException;
use CodeIgniter\Database\BasePreparedQuery;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Prepared query over the ePHPm bridge.
 *
 * The bridge has no server-side prepare — there is no socket and no
 * statement handle to allocate. `_prepare()` therefore just retains the SQL
 * (with its `?` positional placeholders) that CodeIgniter's base
 * {@see BasePreparedQuery::prepare()} has already normalised (named `:name`
 * placeholders are rewritten to `?` there), and each `execute()` ships the
 * SQL and the freshly bound values through `ephpm_db_run()` in one FFI call.
 *
 * @extends BasePreparedQuery<DbOpsInterface, string, ResultId>
 */
class PreparedQuery extends BasePreparedQuery
{
    /** The prepared SQL, retained for each execute(). */
    protected string $preparedSql = '';

    /** The result id of the most recent {@see _execute()}. */
    protected ResultId|false $result = false;

    /**
     * Retain the (already `?`-normalised) SQL for later execution.
     *
     * @param array $options unused — the bridge has no prepare-time options
     *
     * @return $this
     */
    public function _prepare(string $sql, array $options = []): PreparedQuery
    {
        $this->preparedSql = rtrim($sql, "; \t\r\n");

        // A non-null marker so BasePreparedQuery::close()'s isset() guard and
        // hasError() reflect a prepared statement even though there is no
        // native handle to hold.
        $this->statement = $this->preparedSql;

        return $this;
    }

    /**
     * Run the retained SQL with a new set of positional values.
     */
    public function _execute(array $data): bool
    {
        if (! isset($this->statement)) {
            throw new BadMethodCallException('You must call prepare before trying to execute a prepared statement.');
        }

        /** @var Connection $db */
        $db = $this->db;

        try {
            $result = $db->getOps()->run($this->preparedSql, $this->bridgeBindings($data));
        } catch (\Throwable $e) {
            $db->stageError($e);
            $this->errorCode   = (int) $e->getCode();
            $this->errorString = $e->getMessage();

            log_message('error', (string) $e);

            if ($db->DBDebug) {
                throw new DatabaseException($e->getMessage(), (int) $e->getCode(), $e);
            }

            $this->result = false;

            return false;
        }

        $this->result = $db->stageResult($result);

        return true;
    }

    /**
     * Returns the result object for the prepared query, or false on failure.
     */
    public function _getResult(): ResultId|false
    {
        return $this->result;
    }

    /**
     * There is no native statement handle to deallocate.
     */
    protected function _close(): bool
    {
        $this->result = false;

        return true;
    }

    /**
     * Coerce the bound values to what the bridge can carry: null, bool, int,
     * float, and string only (bool binds as 1/0). Anything else is rejected
     * with a clear message instead of letting the C wrapper throw mid-call.
     *
     * @param array<int|string, mixed> $data
     *
     * @return list<null|bool|int|float|string>
     */
    private function bridgeBindings(array $data): array
    {
        $params = [];

        foreach (array_values($data) as $i => $value) {
            if ($value !== null && ! is_scalar($value)) {
                throw new DatabaseException(sprintf(
                    'ephpm-db: unsupported bound value of type %s at position %d — the '
                    . 'ePHPm DB bridge only binds null, bool, int, float, and string parameters.',
                    get_debug_type($value),
                    $i + 1,
                ));
            }

            $params[] = is_bool($value) ? (int) $value : $value;
        }

        return $params;
    }
}
