<?php

declare(strict_types=1);

namespace Ephpm\Db\CodeIgniter\Tests;

use CodeIgniter\Database\Exceptions\DatabaseException;
use Ephpm\Db\CodeIgniter\ResultId;
use Ephpm\Db\CodeIgniter\SqlitePdoDbOps;
use PHPUnit\Framework\TestCase;

/**
 * The driver's execute() surface: result staging, affected-rows /
 * last-insert-id capture, error reporting, and transaction control, all over
 * the pdo_sqlite polyfill of the ephpm_db_* natives.
 */
final class ConnectionTest extends TestCase
{
    private function connection(bool $debug = true): TestableConnection
    {
        $ops = new SqlitePdoDbOps();
        $ops->pdo()->exec(
            'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE, age INTEGER)'
        );

        $conn = new TestableConnection(['ops' => $ops, 'DBDebug' => $debug]);
        $conn->initialize();

        return $conn;
    }

    public function testInsertReportsAffectedRowsAndInsertId(): void
    {
        $conn = $this->connection();

        $result = $conn->executeRaw("INSERT INTO users (email, age) VALUES ('a@x.com', 30)");

        self::assertInstanceOf(ResultId::class, $result);
        self::assertSame(1, $conn->affectedRows());
        self::assertSame(1, $conn->insertID());
        self::assertSame(['code' => 0, 'message' => ''], $conn->error());
    }

    public function testSelectStagesRowsAndColumns(): void
    {
        $conn = $this->connection();
        $conn->executeRaw("INSERT INTO users (email, age) VALUES ('a@x.com', 30)");

        $result = $conn->executeRaw('SELECT id, email, age FROM users');

        self::assertInstanceOf(ResultId::class, $result);
        self::assertSame([['id' => 1, 'email' => 'a@x.com', 'age' => 30]], $result->rows);
        self::assertSame(['id', 'email', 'age'], array_column($result->columns, 'name'));
        // A row-returning statement reports zero affected rows / insert id.
        self::assertSame(0, $conn->affectedRows());
    }

    public function testZeroRowSelectStillCarriesColumnMetadata(): void
    {
        $conn = $this->connection();

        $result = $conn->executeRaw('SELECT id, email FROM users WHERE 1 = 0');

        self::assertInstanceOf(ResultId::class, $result);
        self::assertSame([], $result->rows);
        self::assertSame(['id', 'email'], array_column($result->columns, 'name'));
    }

    public function testFailureWithoutDebugReturnsFalseAndRecordsError(): void
    {
        $conn = $this->connection(false);

        self::assertFalse($conn->executeRaw('SELECT * FROM does_not_exist'));

        $error = $conn->error();
        // litewire's conservative fallback errno for an unclassified error.
        self::assertSame(1105, $error['code']);
        self::assertStringContainsString('SQLSTATE[HY000]', $error['message']);
    }

    public function testDuplicateKeyReportsMysqlErrno(): void
    {
        $conn = $this->connection(false);
        $conn->executeRaw("INSERT INTO users (email) VALUES ('dup@x.com')");

        self::assertFalse($conn->executeRaw("INSERT INTO users (email) VALUES ('dup@x.com')"));
        self::assertSame(1062, $conn->error()['code']);
    }

    public function testFailureWithDebugThrowsDatabaseException(): void
    {
        $conn = $this->connection(true);

        $this->expectException(DatabaseException::class);
        $conn->executeRaw('SELECT * FROM does_not_exist');
    }

    public function testInsertIdSurvivesUntilNextWrite(): void
    {
        $conn = $this->connection();
        $conn->executeRaw("INSERT INTO users (email) VALUES ('a@x.com')");
        self::assertSame(1, $conn->insertID());

        // A read does not report an insert id (mirrors mysqli::$insert_id).
        $conn->executeRaw('SELECT * FROM users');
        self::assertSame(0, $conn->insertID());
    }

    public function testTransactionControlIsIssuedThroughTheBridge(): void
    {
        $recording = new RecordingOps();
        $conn      = new TestableConnection(['ops' => $recording, 'DBDebug' => true]);
        $conn->initialize();

        self::assertTrue($conn->transBeginRaw());
        self::assertTrue($conn->transCommitRaw());
        self::assertTrue($conn->transRollbackRaw());

        self::assertSame(['BEGIN', 'COMMIT', 'ROLLBACK'], $recording->log);
    }

    public function testTransactionsActuallyRollBack(): void
    {
        $conn = $this->connection();

        $conn->transBeginRaw();
        $conn->executeRaw("INSERT INTO users (email) VALUES ('t@x.com')");
        $conn->transRollbackRaw();

        $count = $conn->executeRaw('SELECT COUNT(*) AS c FROM users');
        self::assertInstanceOf(ResultId::class, $count);
        self::assertSame(0, $count->rows[0]['c']);
    }

    public function testSetDatabaseCannotSwitchTheEmbeddedDatabase(): void
    {
        $conn = $this->connection();

        self::assertTrue($conn->setDatabase(''));
        self::assertFalse($conn->setDatabase('some_other_db'));
    }

    public function testGetVersionReportsTheLitewireAdvertisedVersion(): void
    {
        $conn = $this->connection();

        self::assertSame('8.0.36-litewire', $conn->getVersion());
    }
}
