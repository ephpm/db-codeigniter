<?php

declare(strict_types=1);

namespace Ephpm\Db\CodeIgniter\Tests;

use Ephpm\Db\CodeIgniter\Connection;
use Ephpm\Db\CodeIgniter\SqlitePdoDbOps;
use PHPUnit\Framework\TestCase;

/**
 * The escaping contract — the single most important correctness property of a
 * non-prepared statement path against litewire's tenant parser.
 */
final class EscapingTest extends TestCase
{
    private function connection(): Connection
    {
        $conn = new Connection(['ops' => new SqlitePdoDbOps(), 'DBDebug' => true]);
        $conn->initialize();

        return $conn;
    }

    /**
     * The single quote MUST be doubled (`''`), never backslash-escaped
     * (`\'`): litewire's tenant-path parser rejects `\'` as malformed SQL
     * (github.com/ephpm/db-wordpress issue #1). Backslashes are still doubled
     * so a string can never be broken out of.
     *
     * @dataProvider escapeStringCases
     */
    public function testEscapeStringMatchesTheBridgeContract(string $input, string $expected): void
    {
        self::assertSame($expected, $this->connection()->escapeString($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function escapeStringCases(): iterable
    {
        yield 'empty'                => ['', ''];
        yield 'plain'                => ['abc', 'abc'];
        yield 'single quote doubled' => ["O'Brien", "O''Brien"];
        yield 'backslash doubled'    => ['a\\b', 'a\\\\b'];
        yield 'backslash then quote' => ["a\\'b", "a\\\\''b"];
        yield 'double quote'         => ['a"b', 'a\\"b'];
        yield 'newline'              => ["a\nb", 'a\\nb'];
        yield 'carriage return'      => ["a\rb", 'a\\rb'];
        yield 'nul'                  => ["a\0b", 'a\\0b'];
        yield 'ctrl-z'               => ["a\x1ab", 'a\\Zb'];
    }

    public function testEscapeWrapsStringsInSingleQuotes(): void
    {
        $conn = $this->connection();

        self::assertSame("'O''Brien'", $conn->escape("O'Brien"));
        self::assertStringNotContainsString("\\'", $conn->escape("O'Brien"));
    }

    public function testEscapePassesScalarsThrough(): void
    {
        $conn = $this->connection();

        self::assertSame(30, $conn->escape(30));
        self::assertSame(1, $conn->escape(true));
        self::assertSame(0, $conn->escape(false));
        self::assertSame('NULL', $conn->escape(null));
    }

    public function testQuoteDoubledLiteralRoundTripsThroughSqlite(): void
    {
        // The whole point of doubling the single quote instead of
        // backslash-escaping it: `''` is standard SQL, so the embedded
        // literal survives being read back by SQLite (as it does by litewire's
        // MySQL parser). Backslash/control-char escapes are MySQL-dialect and
        // are decoded by litewire, NOT by raw SQLite, so they are asserted as
        // string output above rather than round-tripped through the polyfill.
        $ops = new SqlitePdoDbOps();
        $ops->pdo()->exec('CREATE TABLE t (v TEXT)');
        $conn = new Connection(['ops' => $ops, 'DBDebug' => true]);
        $conn->initialize();

        $value = "O'Brien and O''Malley";
        $ops->run('INSERT INTO t (v) VALUES (' . $conn->escape($value) . ')');

        self::assertSame($value, $ops->run('SELECT v FROM t')['rows'][0]['v']);
    }
}
