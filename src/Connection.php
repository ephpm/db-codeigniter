<?php

declare(strict_types=1);

namespace Ephpm\Db\CodeIgniter;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use stdClass;

/**
 * CodeIgniter 4 database connection over ePHPm's in-process DB bridge.
 *
 * Modelled on CodeIgniter's own MySQLi driver — same MySQL dialect, same
 * `Builder` / `Forge` / `Utils` (subclassed from MySQLi's, so the SQL the
 * builder and schema tools emit is exactly what litewire's MySQL-dialect
 * translator expects) — but every place the MySQLi driver would touch the
 * `mysqli` extension instead calls the `ephpm_db_*` SAPI functions through a
 * {@see DbOpsInterface}. There is no socket, no wire protocol, and no
 * server-side prepared statement: each statement is a direct C call into the
 * per-thread litewire session embedded next to PHP, the same backend the
 * MySQL wire frontend serves on `127.0.0.1:3306`.
 *
 * Every statement is routed through the unified `ephpm_db_run()`, which
 * executes once and reports `has_rowset` (read from the executed statement,
 * not guessed from the first keyword), the rows, the column metadata (present
 * even for a zero-row result set — issue #262), and the affected-rows /
 * last-insert-id metadata (issue #263). On an ePHPm too old to have
 * `ephpm_db_run()` the ops layer transparently falls back to the
 * `ephpm_db_query()` / `ephpm_db_execute()` split, preserving the v0.6.3
 * minimum.
 *
 * @extends BaseConnection<DbOpsInterface, ResultId>
 */
class Connection extends BaseConnection
{
    /**
     * Database driver.
     *
     * Must be the namespace CodeIgniter resolves the driver classes from —
     * the same value the application sets as `DBDriver` (the FQCN prefix the
     * `Database` factory appends `\Connection`, `\Forge`, `\Utils` to).
     *
     * @var string
     */
    public $DBDriver = 'Ephpm\\Db\\CodeIgniter';

    /**
     * Identifier escape character (MySQL dialect — litewire parses it).
     *
     * @var string
     */
    public $escapeChar = '`';

    /**
     * The bridge backend. Populated by {@see connect()} with a
     * {@see SapiDbOps} inside the ePHPm runtime; tests inject a
     * {@see SqlitePdoDbOps} through the `ops` connection-config key.
     */
    public ?DbOpsInterface $ops = null;

    /**
     * Affected-row count of the most recent statement (mirrors
     * `mysqli::$affected_rows`).
     */
    protected int $ephpmAffectedRows = 0;

    /**
     * Last insert id of the most recent statement (mirrors
     * `mysqli::$insert_id`: 0 for a statement that inserted nothing).
     */
    protected int $ephpmInsertId = 0;

    /** MySQL errno of the most recent statement, 0 when it succeeded. */
    protected int $ephpmErrorCode = 0;

    /** Backend message of the most recent statement, '' when it succeeded. */
    protected string $ephpmErrorMessage = '';

    /**
     * "Connect" to the bridge.
     *
     * There is no socket to open: this just resolves the {@see DbOpsInterface}
     * backend (constructing a {@see SapiDbOps}, which fails fast when the
     * `ephpm_db_*` natives are absent) and returns it as the connection id.
     *
     * @return DbOpsInterface
     *
     * @throws DatabaseException when the SAPI natives are unavailable
     */
    public function connect(bool $persistent = false)
    {
        if ($this->ops === null) {
            try {
                $this->ops = new SapiDbOps();
            } catch (\RuntimeException $e) {
                throw new DatabaseException($e->getMessage(), (int) $e->getCode(), $e);
            }
        }

        return $this->ops;
    }

    /**
     * No-op: the per-thread bridge session is owned by the ePHPm runtime and
     * has no idle timeout to recover from. There is nothing to reconnect.
     *
     * @return void
     */
    public function reconnect()
    {
        // Intentionally empty.
    }

    /**
     * Close the "connection".
     *
     * @return void
     */
    protected function _close()
    {
        // The bridge session belongs to the runtime, not to this object;
        // dropping the ops reference is all there is to do.
        $this->ops = null;
    }

    /**
     * Select a specific database to use.
     *
     * The embedded database is fixed by ePHPm's configuration (`[db.sqlite]`)
     * and, in multi-tenant mode, by the request's resolved site — PHP cannot
     * switch it. Returns true only when asked for the database already in
     * use; any real switch request returns false rather than silently
     * pretending to have honoured it.
     */
    public function setDatabase(string $databaseName): bool
    {
        return $databaseName === '' || $databaseName === $this->database;
    }

    /**
     * The database server version.
     *
     * Returns the MySQL version litewire advertises in its wire handshake,
     * so any CodeIgniter code that keys off the version (Forge feature
     * detection) sees the same MySQL 8.0 server a wire client would. It is
     * deliberately NOT read from `SELECT VERSION()`, which litewire currently
     * answers with a `-litewire` suffix that `version_compare()` mis-orders.
     */
    public function getVersion(): string
    {
        return $this->dataCache['version'] ??= '8.0.36-litewire';
    }

    /**
     * Execute a statement against the bridge and return the driver-specific
     * result id ({@see ResultId}), or false on failure.
     *
     * CodeIgniter interpolates a normal query's bindings into the SQL text
     * before this is called (escaped via {@see _escapeString()}), so the
     * statement arrives fully formed and no parameters are passed here;
     * {@see PreparedQuery} is the path that ships bound `?` values.
     *
     * @return false|ResultId
     */
    protected function execute(string $sql)
    {
        if ($this->ops === null) {
            $this->initialize();
        }

        try {
            /** @var array{has_rowset: bool, rows: list<array<string, null|int|float|string>>, columns: list<array{name: string, type: ?string}>, affected_rows: int, last_insert_id: int} $result */
            $result = $this->ops->run($sql);
        } catch (\Throwable $e) {
            $this->stageError($e);

            log_message('error', (string) $e);

            if ($this->DBDebug) {
                throw new DatabaseException($e->getMessage(), (int) $e->getCode(), $e);
            }

            return false;
        }

        return $this->stageResult($result);
    }

    /**
     * The bridge backend. {@see PreparedQuery} shares it so both statement
     * paths run through the one `ephpm_db_run()` surface.
     *
     * @throws DatabaseException when the bridge is unavailable
     */
    public function getOps(): DbOpsInterface
    {
        if ($this->ops === null) {
            $this->initialize();
        }

        return $this->ops;
    }

    /**
     * Record a successful statement's metadata and wrap its rows/columns in a
     * {@see ResultId}. Shared by {@see execute()} and {@see PreparedQuery}.
     *
     * @param array{has_rowset?: bool, rows?: list<array<string, null|int|float|string>>, columns?: list<array{name: string, type: ?string}>, affected_rows?: int, last_insert_id?: int} $result
     *
     * @internal
     */
    public function stageResult(array $result): ResultId
    {
        $this->ephpmErrorCode    = 0;
        $this->ephpmErrorMessage = '';
        $this->ephpmAffectedRows = (int) ($result['affected_rows'] ?? 0);
        $this->ephpmInsertId     = (int) ($result['last_insert_id'] ?? 0);

        return new ResultId(
            $result['rows'] ?? [],
            $result['columns'] ?? [],
            $this->ephpmAffectedRows,
            $this->ephpmInsertId,
        );
    }

    /**
     * Record a failed statement's error so {@see error()} / {@see insertID()}
     * report it after the exception is handled. Shared with {@see PreparedQuery}.
     *
     * @internal
     */
    public function stageError(\Throwable $e): void
    {
        $this->ephpmErrorCode    = (int) $e->getCode();
        $this->ephpmErrorMessage = $e->getMessage();
    }

    /**
     * Returns the total number of rows affected by the last statement.
     */
    public function affectedRows(): int
    {
        return $this->ephpmAffectedRows;
    }

    /**
     * Insert id of the last statement.
     */
    public function insertID(): int
    {
        return $this->ephpmInsertId;
    }

    /**
     * Platform-dependent string escape.
     *
     * MySQL-dialect quoting done without mysqli: backslash-escape NUL, LF,
     * CR, backslash, double quote, and Ctrl-Z — but the **single quote is
     * doubled (`''`), not backslash-escaped (`\'`)**. litewire's tenant-path
     * parser rejects a backslash-escaped single quote as malformed SQL
     * (`'O\'Brien'` fails), whereas `''` is accepted by both MySQL and
     * SQLite/Turso, and stays unambiguous because backslashes are doubled
     * here too, so a string can never be broken out of. See
     * github.com/ephpm/db-wordpress issue #1.
     *
     * The surrounding single quotes are added by
     * {@see BaseConnection::escape()}, so this returns the inner content only.
     */
    protected function _escapeString(string $str): string
    {
        return strtr($str, [
            "\x00" => '\\0',
            "\n"   => '\\n',
            "\r"   => '\\r',
            '\\'   => '\\\\',
            "'"    => "''",
            '"'    => '\\"',
            "\x1a" => '\\Z',
        ]);
    }

    /**
     * Escape a LIKE string directly, adding the backslash escapes for the
     * `%` and `_` wildcards. Mirrors the MySQLi driver so builder-generated
     * `LIKE` clauses behave identically.
     *
     * @param list<string>|string $str
     *
     * @return list<string>|string
     */
    public function escapeLikeStringDirect($str)
    {
        if (is_array($str)) {
            foreach ($str as $key => $val) {
                $str[$key] = $this->escapeLikeStringDirect($val);
            }

            return $str;
        }

        $str = $this->_escapeString($str);

        return str_replace(
            [$this->likeEscapeChar, '%', '_'],
            ['\\' . $this->likeEscapeChar, '\\%', '\\_'],
            $str
        );
    }

    /**
     * Returns the last error code and message.
     *
     * @return array{code: int, message: string}
     */
    public function error(): array
    {
        return [
            'code'    => $this->ephpmErrorCode,
            'message' => $this->ephpmErrorMessage,
        ];
    }

    /**
     * Begin a transaction — plain `BEGIN` through the bridge; the per-thread
     * litewire session tracks the transaction state exactly as it does on
     * the wire path.
     */
    protected function _transBegin(): bool
    {
        return $this->execute('BEGIN') !== false;
    }

    protected function _transCommit(): bool
    {
        return $this->execute('COMMIT') !== false;
    }

    protected function _transRollback(): bool
    {
        return $this->execute('ROLLBACK') !== false;
    }

    /**
     * Generates the SQL for listing tables in a platform-dependent manner.
     * Rides litewire's `SHOW TABLES` emulation.
     */
    protected function _listTables(bool $prefixLimit = false, ?string $tableName = null): string
    {
        $sql = 'SHOW TABLES';

        if ($tableName !== null) {
            return $sql . ' LIKE ' . $this->escape($tableName);
        }

        if ($prefixLimit !== false && $this->DBPrefix !== '') {
            return $sql . " LIKE '" . $this->escapeLikeStringDirect($this->DBPrefix) . "%'";
        }

        return $sql;
    }

    /**
     * Generates a platform-specific query string so that the column names can
     * be fetched. Rides litewire's `SHOW COLUMNS` emulation.
     *
     * The parameter is left untyped to match `BaseConnection::_listColumns()`,
     * which declares no parameter type (adding `string` would narrow it and
     * PHP rejects the override).
     *
     * @param string $table
     */
    protected function _listColumns($table = ''): string
    {
        return 'SHOW COLUMNS FROM ' . $this->protectIdentifiers($table, true, null, false);
    }

    /**
     * Returns an array of objects with field data.
     *
     * @return list<stdClass>
     *
     * @throws DatabaseException
     */
    protected function _fieldData(string $table): array
    {
        $table = $this->protectIdentifiers($table, true, null, false);

        if (($query = $this->query('SHOW COLUMNS FROM ' . $table)) === false) {
            throw new DatabaseException(lang('Database.failGetFieldData'));
        }
        $query = $query->getResultObject();

        $retVal = [];

        for ($i = 0, $c = count($query); $i < $c; $i++) {
            $retVal[$i]       = new stdClass();
            $retVal[$i]->name = $query[$i]->Field;

            sscanf($query[$i]->Type ?? '', '%[a-z](%d)', $retVal[$i]->type, $retVal[$i]->max_length);

            $retVal[$i]->nullable    = ($query[$i]->Null ?? '') === 'YES';
            $retVal[$i]->default     = $query[$i]->Default ?? null;
            $retVal[$i]->primary_key = (int) (($query[$i]->Key ?? '') === 'PRI');
        }

        return $retVal;
    }

    /**
     * Returns an array of objects with index data.
     *
     * @return array<string, stdClass>
     *
     * @throws DatabaseException
     */
    protected function _indexData(string $table): array
    {
        $table = $this->protectIdentifiers($table, true, null, false);

        if (($query = $this->query('SHOW INDEX FROM ' . $table)) === false) {
            throw new DatabaseException(lang('Database.failGetIndexData'));
        }

        if (! $indexes = $query->getResultArray()) {
            return [];
        }

        $keys = [];

        foreach ($indexes as $index) {
            if (empty($keys[$index['Key_name']])) {
                $keys[$index['Key_name']]       = new stdClass();
                $keys[$index['Key_name']]->name = $index['Key_name'];

                if ($index['Key_name'] === 'PRIMARY') {
                    $type = 'PRIMARY';
                } elseif (($index['Index_type'] ?? '') === 'FULLTEXT') {
                    $type = 'FULLTEXT';
                } elseif (! empty($index['Non_unique'])) {
                    $type = ($index['Index_type'] ?? '') === 'SPATIAL' ? 'SPATIAL' : 'INDEX';
                } else {
                    $type = 'UNIQUE';
                }

                $keys[$index['Key_name']]->type = $type;
            }

            $keys[$index['Key_name']]->fields[] = $index['Column_name'];
        }

        return $keys;
    }

    /**
     * Returns an array of objects with foreign key data. Depends on
     * litewire's `information_schema` emulation, which may be incomplete —
     * see the README's "not supported / best-effort" notes.
     *
     * @return array<string, stdClass>
     *
     * @throws DatabaseException
     */
    protected function _foreignKeyData(string $table): array
    {
        $sql = '
                SELECT
                    tc.CONSTRAINT_NAME,
                    tc.TABLE_NAME,
                    kcu.COLUMN_NAME,
                    rc.REFERENCED_TABLE_NAME,
                    kcu.REFERENCED_COLUMN_NAME,
                    rc.DELETE_RULE,
                    rc.UPDATE_RULE,
                    rc.MATCH_OPTION
                FROM information_schema.table_constraints AS tc
                INNER JOIN information_schema.referential_constraints AS rc
                    ON tc.constraint_name = rc.constraint_name
                    AND tc.constraint_schema = rc.constraint_schema
                INNER JOIN information_schema.key_column_usage AS kcu
                    ON tc.constraint_name = kcu.constraint_name
                    AND tc.constraint_schema = kcu.constraint_schema
                WHERE
                    tc.constraint_type = ' . $this->escape('FOREIGN KEY') . ' AND
                    tc.table_name = ' . $this->escape($table);

        if (($query = $this->query($sql)) === false) {
            throw new DatabaseException(lang('Database.failGetForeignKeyData'));
        }

        $query   = $query->getResultObject();
        $indexes = [];

        foreach ($query as $row) {
            $indexes[$row->CONSTRAINT_NAME]['constraint_name']       = $row->CONSTRAINT_NAME;
            $indexes[$row->CONSTRAINT_NAME]['table_name']            = $row->TABLE_NAME;
            $indexes[$row->CONSTRAINT_NAME]['column_name'][]         = $row->COLUMN_NAME;
            $indexes[$row->CONSTRAINT_NAME]['foreign_table_name']    = $row->REFERENCED_TABLE_NAME;
            $indexes[$row->CONSTRAINT_NAME]['foreign_column_name'][] = $row->REFERENCED_COLUMN_NAME;
            $indexes[$row->CONSTRAINT_NAME]['on_delete']             = $row->DELETE_RULE;
            $indexes[$row->CONSTRAINT_NAME]['on_update']             = $row->UPDATE_RULE;
            $indexes[$row->CONSTRAINT_NAME]['match']                 = $row->MATCH_OPTION;
        }

        return $this->foreignKeyDataToObjects($indexes);
    }

    /**
     * Returns platform-specific SQL to disable foreign key checks.
     *
     * @return string
     */
    protected function _disableForeignKeyChecks()
    {
        return 'SET FOREIGN_KEY_CHECKS=0';
    }

    /**
     * Returns platform-specific SQL to enable foreign key checks.
     *
     * @return string
     */
    protected function _enableForeignKeyChecks()
    {
        return 'SET FOREIGN_KEY_CHECKS=1';
    }
}
