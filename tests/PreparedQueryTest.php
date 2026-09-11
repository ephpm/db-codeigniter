<?php

declare(strict_types=1);

namespace Ephpm\Db\CodeIgniter\Tests;

use CodeIgniter\Database\Exceptions\DatabaseException;
use Ephpm\Db\CodeIgniter\Connection;
use Ephpm\Db\CodeIgniter\PreparedQuery;
use Ephpm\Db\CodeIgniter\ResultId;
use Ephpm\Db\CodeIgniter\SqlitePdoDbOps;
use PHPUnit\Framework\TestCase;

/**
 * The prepared-statement path: `?` positional binds shipped through
 * ephpm_db_run(), value coercion, and result staging.
 */
final class PreparedQueryTest extends TestCase
{
    private Connection $conn;

    protected function setUp(): void
    {
        $ops = new SqlitePdoDbOps();
        $ops->pdo()->exec(
            'CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, v TEXT, n INTEGER)'
        );

        $this->conn = new Connection(['ops' => $ops, 'DBDebug' => true]);
        $this->conn->initialize();
    }

    public function testPrepareExecuteInsertCapturesInsertId(): void
    {
        $query = new PreparedQuery($this->conn);
        $query->_prepare('INSERT INTO t (v, n) VALUES (?, ?)');

        self::assertTrue($query->_execute(['hello', 7]));
        self::assertSame(1, $this->conn->insertID());
        self::assertSame(1, $this->conn->affectedRows());
    }

    public function testPrepareExecuteSelectReturnsResultId(): void
    {
        $insert = new PreparedQuery($this->conn);
        $insert->_prepare('INSERT INTO t (v, n) VALUES (?, ?)');
        $insert->_execute(['hello', 7]);

        $select = new PreparedQuery($this->conn);
        $select->_prepare('SELECT v, n FROM t WHERE id = ?');

        self::assertTrue($select->_execute([1]));

        $result = $select->_getResult();
        self::assertInstanceOf(ResultId::class, $result);
        self::assertSame(['v' => 'hello', 'n' => 7], $result->fetchAssoc());
    }

    public function testReExecuteWithDifferentBinds(): void
    {
        $query = new PreparedQuery($this->conn);
        $query->_prepare('INSERT INTO t (v) VALUES (?)');

        self::assertTrue($query->_execute(['first']));
        self::assertTrue($query->_execute(['second']));

        $select = new PreparedQuery($this->conn);
        $select->_prepare('SELECT v FROM t ORDER BY id');
        $select->_execute([]);

        self::assertSame(
            ['first', 'second'],
            array_column($select->_getResult()->rows, 'v'),
        );
    }

    public function testBooleanBindsAsInteger(): void
    {
        $query = new PreparedQuery($this->conn);
        $query->_prepare('INSERT INTO t (n) VALUES (?)');
        $query->_execute([true]);

        $select = new PreparedQuery($this->conn);
        $select->_prepare('SELECT n FROM t WHERE id = ?');
        $select->_execute([1]);

        self::assertSame(1, $select->_getResult()->fetchAssoc()['n']);
    }

    public function testNonScalarBindIsRejected(): void
    {
        $query = new PreparedQuery($this->conn);
        $query->_prepare('SELECT ?');

        $this->expectException(DatabaseException::class);
        $query->_execute([['an', 'array']]);
    }

    public function testCloseClearsResult(): void
    {
        $query = new PreparedQuery($this->conn);
        $query->_prepare('SELECT 1');
        $query->_execute([]);

        self::assertTrue($query->close());
    }
}
