<?php

declare(strict_types=1);

namespace Ephpm\Db\CodeIgniter;

/**
 * The driver-specific "result resource" the bridge produces for one
 * statement.
 *
 * CodeIgniter's {@see \CodeIgniter\Database\BaseConnection::query()} stashes
 * whatever {@see Connection::execute()} returns into `$this->resultID` and
 * hands it to a {@see Result}. The mysqli driver's resultID is a live
 * `mysqli_result` with an internal cursor; the ePHPm bridge instead
 * materializes the whole result set in one FFI call, so this object owns the
 * already-fetched rows, the column metadata (present even for a zero-row
 * result set — ePHPm issue #262), and a cursor {@see Result} advances.
 *
 * @internal produced by {@see Connection::execute()} and {@see PreparedQuery}
 */
final class ResultId
{
    private int $cursor = 0;

    /**
     * @param list<array<string, null|int|float|string>> $rows    associative rows, column order preserved
     * @param list<array{name: string, type: ?string}>   $columns column metadata in SELECT-list order
     * @param int                                         $affectedRows affected-row count for a write
     * @param int                                         $insertId     last insert id for a write
     */
    public function __construct(
        public array $rows,
        public array $columns,
        public int $affectedRows = 0,
        public int $insertId = 0,
    ) {
    }

    /**
     * Return the row at the cursor and advance, or false at the end — the
     * bridge equivalent of `mysqli_result::fetch_assoc()`.
     *
     * @return array<string, null|int|float|string>|false
     */
    public function fetchAssoc(): array|false
    {
        if (!isset($this->rows[$this->cursor])) {
            return false;
        }

        return $this->rows[$this->cursor++];
    }

    /**
     * Move the cursor. `mysqli_result::data_seek()` returns false for an
     * out-of-range offset; matched here so BaseResult's seek-then-fetch
     * loop behaves identically.
     */
    public function seek(int $n): bool
    {
        if ($n < 0 || ($n !== 0 && !isset($this->rows[$n]))) {
            return false;
        }

        $this->cursor = $n;

        return true;
    }

    public function numRows(): int
    {
        return \count($this->rows);
    }
}
