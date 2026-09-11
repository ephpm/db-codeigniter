<?php

declare(strict_types=1);

namespace Ephpm\Db\CodeIgniter;

use CodeIgniter\Database\MySQLi\Builder as MySQLiBuilder;

/**
 * Query builder for the ePHPm bridge.
 *
 * The embedded engine speaks MySQL dialect (litewire translates MySQL → SQLite
 * on the way in), so the builder is CodeIgniter's own MySQLi builder verbatim:
 * the SQL it compiles — backtick identifiers, `INSERT ... ON DUPLICATE KEY
 * UPDATE`, `LIMIT`, batch inserts — is exactly what litewire's MySQL parser
 * expects. Nothing here touches the `mysqli` extension; the parent only
 * assembles SQL strings, which then run through {@see Connection::execute()}.
 *
 * @see Connection for the caveats that follow from litewire/Turso (e.g.
 *      `upsert()`'s `ON DUPLICATE KEY UPDATE` is rejected by the engine).
 */
class Builder extends MySQLiBuilder
{
}
