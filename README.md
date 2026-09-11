# ephpm/db-codeigniter

A [CodeIgniter 4](https://codeigniter.com) database driver that runs SQL
through [ePHPm](https://ephpm.dev)'s in-process database bridge via the
`ephpm_db_*` SAPI functions. Same CodeIgniter database API your app already
speaks — the query builder, models, `$db->query()`, transactions, prepared
queries — but every statement is a direct C call into the litewire session
embedded next to PHP, the same backend the MySQL wire frontend serves. No
`mysqli`, no socket, no wire-protocol round trip.

It is modelled on CodeIgniter's own **MySQLi** driver: a `BaseConnection`
subclass speaking MySQL dialect, with the query `Builder`, `Forge`, and
`Utils` reused from MySQLi so the SQL they emit is exactly what litewire's
MySQL-dialect translator expects.

**Compatibility:** `php: ^8.2`, `codeigniter4/framework: ^4.4 || ^4.5`. Tests
run on PHP 8.2 / 8.3 / 8.4 in CI.

```php
// app/Config/Database.php
public array $default = [
    'DBDriver' => 'Ephpm\\Db\\CodeIgniter', // ← the driver namespace (FQCN prefix)
    'DBDebug'  => true,
    // host / username / password / database / port are accepted and ignored
];
```

```php
$db = \Config\Database::connect();

$db->table('posts')->insert(['title' => 'Hello from the bridge']);
$id   = $db->insertID();
$rows = $db->table('posts')->get()->getResultArray();
```

---

## Table of contents

- [Requirements](#requirements)
- [Install](#install)
- [Configuration](#configuration)
- [Usage](#usage)
- [What's verified, what isn't](#whats-verified-what-isnt)
- [Not supported / best-effort](#not-supported--best-effort)
- [Behavior notes](#behavior-notes)
- [Testing without ePHPm](#testing-without-ephpm)
- [IDE stubs](#ide-stubs)
- [How it works](#how-it-works)
- [License](#license)

---

## Requirements

- **PHP 8.2+**
- **`codeigniter4/framework` ^4.4 || ^4.5** (Composer pulls this in
  automatically)
- **ePHPm v0.6.3 or newer** (current release: v0.10.3), **with
  `[db.sqlite]` configured.** The `ephpm_db_*` SAPI functions this driver
  calls first shipped in the v0.6.3 release. They are registered only when
  the embedded database is active (`[db.sqlite]` in `ephpm.toml`); without
  it, every query throws `ephpm_db: no embedded database is active (requires
  [db.sqlite])`. Under PHP-FPM, mod_php, or the stock CLI the functions don't
  exist at all and the connection fails fast. For development without ePHPm,
  see [Testing without ePHPm](#testing-without-ephpm).

You can confirm the SAPI surface is present from any PHP file:

```php
var_dump(function_exists('ephpm_db_query'));   // expect bool(true)
```

---

## Install

ePHPm packages are distributed via their GitHub repositories, not Packagist.
Add this repo as a Composer `vcs` repository, then require the package:

```bash
composer config repositories.ephpm/db-codeigniter vcs https://github.com/ephpm/db-codeigniter
composer require ephpm/db-codeigniter
```

---

## Configuration

CodeIgniter resolves a database driver by treating `DBDriver` as the class
namespace when it contains a backslash (the `Database` factory appends
`\Connection`, `\Builder`, `\Forge`, `\Utils`). So the only required setting
is `DBDriver` set to this package's namespace:

```php
// app/Config/Database.php
public array $default = [
    'DBDriver'  => 'Ephpm\\Db\\CodeIgniter',
    'DBDebug'   => (ENVIRONMENT !== 'production'),
    'DBPrefix'  => '',
    // The following usual keys are ACCEPTED AND IGNORED — there is no socket
    // to connect to; the bridge always talks to the embedded database the
    // ePHPm process was configured with (and, in multi-tenant mode, the
    // request's resolved site):
    'hostname'  => '',
    'username'  => '',
    'password'  => '',
    'database'  => '',
    'port'      => 0,
];
```

No `.env` `database.default.*` credentials are needed. Environment overrides
still work for `DBDriver`/`DBDebug` if you prefer them in `.env`.

---

## Usage

Everything above the driver is stock CodeIgniter:

```php
$db = \Config\Database::connect();

// Query builder
$db->table('users')->where('active', 1)->orderBy('id', 'DESC')->get()->getResult();

// Raw query with bindings (CodeIgniter escapes them into the SQL)
$db->query('SELECT * FROM users WHERE email = ?', ['a@example.com'])->getRowArray();

// Writes + last insert id
$db->table('users')->insert(['email' => 'a@example.com']);
$newId = $db->insertID();
$db->table('users')->where('id', $newId)->update(['active' => 1]);
$affected = $db->affectedRows();

// Transactions
$db->transStart();
$db->table('accounts')->where('id', 1)->update(['balance' => 100]);
$db->table('accounts')->where('id', 2)->update(['balance' => 200]);
$db->transComplete();

// Prepared queries (positional ? placeholders)
$prepared = $db->prepare(static fn ($db) => $db->table('users')->insert([
    'email' => 'x@example.com',
]));
$prepared->execute('x@example.com');
```

Models work unchanged — set the model's `$DBGroup` to a group configured with
this driver.

---

## What's verified, what isn't

The test suite exercises the driver classes directly against a `pdo_sqlite`
fake of the natives ([`SqlitePdoDbOps`](src/SqlitePdoDbOps.php)) that mimics
the bridge's unified `ephpm_db_run()` shape and its error mapping — the same
approach the sibling `ephpm/db-laravel` and `ephpm/db-doctrine` packages use.
"Verified" means covered by that suite; the real runtime additionally depends
on ePHPm's litewire translation layer.

| Area | Status |
| --- | --- |
| `execute()` round-trip: rows, affected-rows, last-insert-id capture | Verified. |
| `insertID()` / `affectedRows()` | Verified. A read reports 0 for both, mirroring `mysqli`. |
| Native scalar types (int / float / null / string) | Verified. |
| `_escapeString()` / `escape()` — the `''` doubling fix | Verified. The **single quote is doubled (`''`)**, backslash specials (`\n`, `\r`, NUL, `\`, `"`, Ctrl-Z) are backslash-escaped MySQL-style. See [Behavior notes](#behavior-notes). |
| Error → MySQL errno mapping (1062 / 1064 / 1105 / …) via `error()` | Verified against the fake's litewire-mirroring `error_map`. |
| Zero-row result sets still report their columns (`getFieldNames()`, `getFieldData()`) | Verified — column metadata comes from the executed statement (ePHPm issue #262). |
| Prepared queries: `?` binds, bool→int coercion, non-scalar rejection, re-execute | Verified. |
| Transactions: `BEGIN` / `COMMIT` / `ROLLBACK` emitted through the bridge, and an actual rollback | Verified against the fake; litewire's per-thread session tracks transaction state on the real runtime. |
| `Result` iteration: `getResultArray()` / `getResultObject()` / `getRowArray()` / field metadata | Verified. |
| Query builder SQL generation (MySQL dialect, backtick identifiers) | Inherited verbatim from CodeIgniter's MySQLi `Builder`; its own suite covers SQL generation. |

---

## Not supported / best-effort

Honesty list — these are limitations of the bridge or of litewire/Turso, not
bugs:

- **No socket / persistent connections.** `connect()` opens nothing; the
  `pConnect` flag and `host`/`port`/`username`/`password` are ignored. There
  is no connection to lose, so `reconnect()` is a no-op.
- **`setDatabase()` cannot switch databases.** The embedded database is fixed
  by ePHPm's `[db.sqlite]` config and, in multi-tenant mode, by the request's
  resolved site. Asking for a different database returns `false` rather than
  silently pretending to have switched.
- **No server-side prepared statements.** There is no wire protocol to
  prepare against; a prepared query retains its SQL in PHP and ships the SQL
  plus freshly bound values on each `execute()`. Functionally identical for
  callers, but there is no server-side statement handle.
- **`upsert()` is not supported.** CodeIgniter's builder compiles it to MySQL
  `INSERT ... ON DUPLICATE KEY UPDATE`, which litewire/Turso rejects, and no
  automatic rewrite preserves its column-level update semantics. Use
  `insertOrIgnore()` followed by an explicit `update()`, or do the
  insert-or-update in application code. (Same limitation as `ephpm/db-laravel`.)
- **Schema introspection is best-effort.** `getFieldData()`, `getIndexData()`,
  `listTables()`, and `_listColumns()` ride litewire's `SHOW COLUMNS` /
  `SHOW INDEX` / `SHOW TABLES` emulation; `getForeignKeyData()` rides
  `information_schema`, which litewire's emulation may not fully implement.
  Field metadata carries the **declared column type only** — the MySQL wire
  type id, byte lengths, and key flags a `mysqli_result` exposes are reported
  as `null`/`0` because the bridge does not carry them.
- **`Forge` / `Utils` DDL** is CodeIgniter's MySQLi DDL; how far a given
  statement gets depends on litewire's MySQL→SQLite DDL translation.
- **No read/write split**, no multiple result sets (the bridge stages exactly
  one result set per statement), no streaming of large binds (they are read
  into memory).
- **`getVersion()`** returns the fixed string `8.0.36-litewire` (the MySQL
  version litewire advertises in its wire handshake), not a live
  `SELECT VERSION()` — deliberately, so version-keyed feature detection lands
  on the MySQL 8.0 behavior a wire client would get.

---

## Behavior notes

**Statement routing.** Every statement goes through the unified
`ephpm_db_run()`, which executes once and reports `has_rowset` (read from the
executed statement, not guessed from the first keyword), the rows, the column
metadata, and the affected-rows / last-insert-id metadata (ePHPm issues
#262 / #263). On an ePHPm too old to have `ephpm_db_run()` the driver
transparently falls back to the `ephpm_db_query()` / `ephpm_db_execute()`
split, preserving the v0.6.3 minimum.

**Escaping — `''`, never `\'`.** A non-prepared query has its bindings escaped
into the SQL by CodeIgniter using this driver's `_escapeString()`. That method
doubles the single quote (`''`) instead of backslash-escaping it (`\'`):
litewire's tenant-path parser rejects a backslash-escaped single quote as
malformed SQL (`'O\'Brien'` fails), whereas `''` is accepted by both MySQL and
SQLite/Turso and stays unambiguous because backslashes are doubled too, so a
string can never be broken out of. Backslash, NUL, LF, CR, double quote, and
Ctrl-Z are backslash-escaped MySQL-style, decoded by litewire's MySQL parser.
See github.com/ephpm/db-wordpress issue #1. Prefer bound parameters over
inline literals regardless.

**Transactions.** `transBegin` / `transCommit` / `transRollback` are plain
`BEGIN` / `COMMIT` / `ROLLBACK` through the bridge; the per-thread litewire
session tracks transaction state exactly as it does on the wire path, and
ePHPm rolls back a transaction still open at request end. CodeIgniter manages
nesting one layer up.

**Error shape.** Bridge errors carry the MySQL errno as the exception code and
a `SQLSTATE[xxxxx]: <message>` message; `error()` returns
`['code' => <errno>, 'message' => <message>]`, and with `DBDebug` on the driver
raises a `CodeIgniter\Database\Exceptions\DatabaseException` carrying the same
code. The errnos ePHPm emits: 1062 (unique), 1064 (parse/syntax), 1205 (lock),
1290 (read-only), 1452 (foreign key), everything else 1105.

---

## Testing without ePHPm

The driver takes an optional bridge backend through the `ops` connection-config
key, so PHPUnit suites can run on plain php-cli with the bundled `pdo_sqlite`
fake:

```php
use Ephpm\Db\CodeIgniter\Connection;
use Ephpm\Db\CodeIgniter\SqlitePdoDbOps;

$ops = new SqlitePdoDbOps();
$ops->pdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT)');

$db = new Connection(['DBDriver' => 'Ephpm\\Db\\CodeIgniter', 'ops' => $ops, 'DBDebug' => true]);
$db->initialize();
```

`SqlitePdoDbOps` is for tests and local development only. It approximates the
runtime — same unified `run()` shape, same error-code mapping — but it is not
litewire: SQL that leans on MySQL-only syntax runs on the real runtime and
fails on the fake (it runs your SQL directly against SQLite). Don't use it in
production.

---

## IDE stubs

This package is self-contained and does **not** depend on `ephpm/db`. If you
want IDE autocompletion / static-analysis stubs for the underlying
`ephpm_db_*` functions, install the base package as a dev dependency — it
ships `stubs/ephpm-db.stub.php`:

```bash
composer require --dev ephpm/db
```

---

## How it works

ePHPm runs PHP inside the same OS process as its embedded database. With
`[db.sqlite]` active, it registers `ephpm_db_run()` / `ephpm_db_query()` /
`ephpm_db_execute()` into PHP's global function table. Each call executes
MySQL-dialect SQL through a per-thread litewire session — the same translation
layer (MySQL → SQLite, `SHOW`/`DESCRIBE` emulation, `SET NAMES` no-ops) that
serves ePHPm's MySQL wire frontend on `127.0.0.1:3306` — without the TCP round
trip, connection pooling, or wire-protocol parsing.

This package implements CodeIgniter 4's database driver contract
(`BaseConnection`, `BaseResult`, `BasePreparedQuery`, plus the MySQL `Builder`
/ `Forge` / `Utils`) on top of those functions. Everything above the driver
boundary is stock CodeIgniter.

See [ephpm.dev](https://ephpm.dev) for the runtime's architecture.

---

## License

MIT — see [LICENSE](LICENSE).
