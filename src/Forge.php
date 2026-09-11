<?php

declare(strict_types=1);

namespace Ephpm\Db\CodeIgniter;

use CodeIgniter\Database\MySQLi\Forge as MySQLiForge;

/**
 * Schema Forge for the ePHPm bridge.
 *
 * Reuses CodeIgniter's MySQLi forge so the DDL it emits (`CREATE TABLE`,
 * `ENGINE=`, `AUTO_INCREMENT`, `ALTER TABLE`) is MySQL-dialect, which is what
 * litewire's translator expects. The generated statements run through
 * {@see Connection}. Forge/DDL coverage is bounded by litewire's MySQL→SQLite
 * DDL translation — see the README's limitations.
 */
class Forge extends MySQLiForge
{
}
