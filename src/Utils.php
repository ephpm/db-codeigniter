<?php

declare(strict_types=1);

namespace Ephpm\Db\CodeIgniter;

use CodeIgniter\Database\MySQLi\Utils as MySQLiUtils;

/**
 * Database administration utilities for the ePHPm bridge.
 *
 * Reuses CodeIgniter's MySQLi utils (MySQL-dialect `OPTIMIZE TABLE` /
 * `REPAIR TABLE` SQL). Whether those admin statements do anything depends on
 * litewire/Turso — they are typically no-ops on the embedded engine — but the
 * class is provided so the `Database` factory can resolve `Utils` for this
 * driver's namespace.
 */
class Utils extends MySQLiUtils
{
}
