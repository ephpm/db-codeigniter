<?php

declare(strict_types=1);

namespace Ephpm\Db\CodeIgniter;

use CodeIgniter\Database\BaseResult;
use CodeIgniter\Entity\Entity;
use stdClass;

/**
 * Result for the ePHPm bridge.
 *
 * The bridge returns a complete result set as PHP arrays in a single FFI
 * call — there is no cursor to stream from — so this class walks the rows a
 * {@see ResultId} already holds. Column order follows the bridge's
 * associative rows, which preserve insertion order (the SELECT-list order),
 * and column metadata comes from the executed statement, so a zero-row
 * result set still reports its column count and names (ePHPm issue #262).
 *
 * @extends BaseResult<ResultId, ResultId>
 */
class Result extends BaseResult
{
    /**
     * Gets the number of fields in the result set.
     */
    public function getFieldCount(): int
    {
        return \is_object($this->resultID) ? \count($this->resultID->columns) : 0;
    }

    /**
     * Generates an array of column names in the result set.
     *
     * @return list<string>
     */
    public function getFieldNames(): array
    {
        if (!\is_object($this->resultID)) {
            return [];
        }

        return array_map(
            static fn (array $c): string => (string) ($c['name'] ?? ''),
            $this->resultID->columns,
        );
    }

    /**
     * Generates an array of objects representing field meta-data.
     *
     * The bridge exposes a column's name and its DECLARED schema type
     * (`ephpm_db_columns()`); it does not carry MySQL wire type ids, byte
     * lengths, or key flags, so those fields are reported as null/0 rather
     * than guessed. `type` is null for an expression or an untyped column
     * (SQLite/Turso makes no distinction).
     *
     * @return list<stdClass>
     */
    public function getFieldData(): array
    {
        if (!\is_object($this->resultID)) {
            return [];
        }

        $retVal = [];

        foreach ($this->resultID->columns as $i => $column) {
            $retVal[$i]              = new stdClass();
            $retVal[$i]->name        = (string) ($column['name'] ?? '');
            $retVal[$i]->type        = $column['type'] ?? null;
            $retVal[$i]->type_name   = $column['type'] ?? null;
            $retVal[$i]->max_length  = null;
            $retVal[$i]->length      = null;
            $retVal[$i]->default     = null;
            $retVal[$i]->primary_key = 0;
        }

        return $retVal;
    }

    /**
     * Frees the current result.
     *
     * @return void
     */
    public function freeResult()
    {
        if (\is_object($this->resultID)) {
            $this->resultID->rows = [];
            $this->resultID       = false;
        }
    }

    /**
     * Moves the internal pointer to the desired offset. This is called
     * internally before fetching results to make sure the result set
     * starts at zero.
     *
     * @return bool
     */
    public function dataSeek(int $n = 0)
    {
        return \is_object($this->resultID) ? $this->resultID->seek($n) : false;
    }

    /**
     * Returns the result set as an associative array.
     *
     * @return array<string, null|int|float|string>|false
     */
    protected function fetchAssoc()
    {
        return \is_object($this->resultID) ? $this->resultID->fetchAssoc() : false;
    }

    /**
     * Returns the result set row as an object.
     *
     * @return Entity|false|object|stdClass
     */
    protected function fetchObject(string $className = 'stdClass')
    {
        $row = $this->fetchAssoc();

        if ($row === false) {
            return false;
        }

        if (is_subclass_of($className, Entity::class)) {
            return (new $className())->injectRawData($row);
        }

        if ($className === 'stdClass') {
            return (object) $row;
        }

        $object = new $className();

        foreach ($row as $key => $value) {
            $object->{$key} = $value;
        }

        return $object;
    }

    /**
     * Returns the number of rows in the result set.
     */
    public function getNumRows(): int
    {
        if (!\is_int($this->numRows)) {
            $this->numRows = \is_object($this->resultID) ? $this->resultID->numRows() : 0;
        }

        return $this->numRows;
    }
}
