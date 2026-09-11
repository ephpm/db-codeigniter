<?php

declare(strict_types=1);

namespace Ephpm\Db\CodeIgniter\Tests;

use Ephpm\Db\CodeIgniter\Result;
use Ephpm\Db\CodeIgniter\ResultId;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The Result wrapper over a materialized {@see ResultId}: row iteration,
 * field metadata, and empty-result column reporting (ePHPm issue #262).
 */
final class ResultTest extends TestCase
{
    private function makeResult(ResultId $resultId): Result
    {
        $connID = $resultId; // the driver's "connID" is the ops handle; unused by Result

        return new Result($connID, $resultId);
    }

    private function populated(): ResultId
    {
        return new ResultId(
            [
                ['id' => 1, 'name' => 'alice'],
                ['id' => 2, 'name' => 'bob'],
            ],
            [
                ['name' => 'id', 'type' => 'INTEGER'],
                ['name' => 'name', 'type' => 'TEXT'],
            ],
        );
    }

    public function testFieldMetadata(): void
    {
        $result = $this->makeResult($this->populated());

        self::assertSame(2, $result->getFieldCount());
        self::assertSame(['id', 'name'], $result->getFieldNames());

        $data = $result->getFieldData();
        self::assertSame('id', $data[0]->name);
        self::assertSame('INTEGER', $data[0]->type);
        self::assertSame('name', $data[1]->name);
        self::assertSame('TEXT', $data[1]->type);
    }

    public function testGetResultArray(): void
    {
        $result = $this->makeResult($this->populated());

        self::assertSame(2, $result->getNumRows());
        self::assertSame(
            [
                ['id' => 1, 'name' => 'alice'],
                ['id' => 2, 'name' => 'bob'],
            ],
            $result->getResultArray(),
        );
    }

    public function testGetResultObject(): void
    {
        $result = $this->makeResult($this->populated());
        $rows   = $result->getResultObject();

        self::assertContainsOnlyInstancesOf(stdClass::class, $rows);
        self::assertSame(1, $rows[0]->id);
        self::assertSame('bob', $rows[1]->name);
    }

    public function testDataSeekResetsIteration(): void
    {
        $result = $this->makeResult($this->populated());

        self::assertSame(['id' => 1, 'name' => 'alice'], $result->getRowArray(0));
        self::assertSame(['id' => 2, 'name' => 'bob'], $result->getRowArray(1));
    }

    public function testEmptyResultStillReportsColumns(): void
    {
        $result = $this->makeResult(new ResultId([], [['name' => 'cnt', 'type' => null]]));

        self::assertSame(0, $result->getNumRows());
        self::assertSame(1, $result->getFieldCount());
        self::assertSame(['cnt'], $result->getFieldNames());
        self::assertNull($result->getFieldData()[0]->type);
        self::assertSame([], $result->getResultArray());
    }
}
