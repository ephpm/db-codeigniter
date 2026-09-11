<?php

declare(strict_types=1);

namespace Ephpm\Db\CodeIgniter\Tests;

use Ephpm\Db\CodeIgniter\SqlitePdoDbOps;
use PHPUnit\Framework\TestCase;

/**
 * The pdo_sqlite polyfill of the ephpm_db_* natives: the unified run() shape
 * and the litewire-mirroring error-code mapping the whole suite leans on.
 */
final class SqlitePdoDbOpsTest extends TestCase
{
    private function ops(): SqlitePdoDbOps
    {
        $ops = new SqlitePdoDbOps();
        $ops->pdo()->exec('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE)');

        return $ops;
    }

    public function testRunReportsRowsetForSelect(): void
    {
        $ops = $this->ops();
        $ops->run("INSERT INTO t (email) VALUES ('a@x.com')");

        $result = $ops->run('SELECT id, email FROM t');

        self::assertTrue($result['has_rowset']);
        self::assertSame([['id' => 1, 'email' => 'a@x.com']], $result['rows']);
        self::assertSame(['id', 'email'], array_column($result['columns'], 'name'));
        self::assertSame(0, $result['affected_rows']);
    }

    public function testRunReportsWriteMetadata(): void
    {
        $ops    = $this->ops();
        $result = $ops->run("INSERT INTO t (email) VALUES ('a@x.com')");

        self::assertFalse($result['has_rowset']);
        self::assertSame([], $result['rows']);
        self::assertSame(1, $result['affected_rows']);
        self::assertSame(1, $result['last_insert_id']);
    }

    public function testZeroRowSelectCarriesColumns(): void
    {
        $result = $this->ops()->run('SELECT id, email FROM t WHERE 1 = 0');

        self::assertTrue($result['has_rowset']);
        self::assertSame([], $result['rows']);
        self::assertSame(['id', 'email'], array_column($result['columns'], 'name'));
    }

    public function testBoundParametersAndNativeTypes(): void
    {
        $ops = $this->ops();
        $ops->pdo()->exec('CREATE TABLE typed (i INTEGER, f REAL, s TEXT, z TEXT)');
        $ops->run('INSERT INTO typed (i, f, s, z) VALUES (?, ?, ?, ?)', [42, 1.5, 'text', null]);

        $row = $ops->run('SELECT i, f, s, z FROM typed')['rows'][0];

        self::assertSame(42, $row['i']);
        self::assertSame(1.5, $row['f']);
        self::assertSame('text', $row['s']);
        self::assertNull($row['z']);
    }

    /**
     * @dataProvider errorCases
     */
    public function testErrorMappingMirrorsLitewire(string $needleSql, int $expectedErrno, string $expectedSqlstate): void
    {
        $ops = $this->ops();
        $ops->run("INSERT INTO t (id, email) VALUES (1, 'dup@x.com')");

        try {
            $ops->run($needleSql);
            self::fail('Expected the statement to throw');
        } catch (\Exception $e) {
            self::assertSame($expectedErrno, $e->getCode());
            self::assertStringContainsString('SQLSTATE[' . $expectedSqlstate . ']', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function errorCases(): iterable
    {
        yield 'unique violation' => ["INSERT INTO t (id, email) VALUES (2, 'dup@x.com')", 1062, '23000'];
        yield 'syntax error'     => ['SELECTT 1', 1064, '42000'];
        yield 'unknown / other'  => ['SELECT * FROM missing_table', 1105, 'HY000'];
    }

    public function testUnsupportedParameterTypeThrows(): void
    {
        $this->expectException(\Exception::class);
        $this->ops()->run('SELECT ?', [['array']]);
    }
}
