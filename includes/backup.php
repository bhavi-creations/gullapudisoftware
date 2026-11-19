<?php
/*
|--------------------------------------------------------------------------
| DATABASE BACKUP / RESTORE (pure PHP, no shell commands)
|--------------------------------------------------------------------------
*/

function backup_dir(): string
{
    $dir = realpath(BACKUP_DIR) ?: BACKUP_DIR;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    // Block direct web access to backup files.
    if (!is_file($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    return rtrim($dir, '/\\');
}

/** Creates a gzip-compressed SQL dump. Returns [file_name, size]. */
function backup_create(): array
{
    $pdo = db();
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    $name = 'gullapudi_backup_' . date('Y-m-d_His') . '.sql.gz';
    $path = backup_dir() . '/' . $name;
    $gz = gzopen($path, 'wb6');
    if (!$gz) {
        throw new RuntimeException('Cannot write backup file. Check folder permissions for /backups.');
    }

    gzwrite($gz, "-- Gullapudi database backup\n-- Created: " . date('Y-m-d H:i:s') . "\n\n");
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");

    foreach ($tables as $table) {
        if ($table === 'backup_operations') {
            continue; // history stays with the live database
        }
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
        gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n$create;\n\n");

        $stmt = $pdo->query("SELECT * FROM `$table`");
        $batch = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $vals = array_map(function ($v) use ($pdo) {
                return $v === null ? 'NULL' : $pdo->quote((string)$v);
            }, $row);
            $batch[] = '(' . implode(',', $vals) . ')';
            if (count($batch) >= 200) {
                gzwrite($gz, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            gzwrite($gz, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        gzwrite($gz, "\n");
    }

    gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);

    return [$name, filesize($path)];
}

/** Restores from an .sql or .sql.gz file. Returns number of statements run. */
function backup_restore(string $file): int
{
    $sql = substr($file, -3) === '.gz' ? gzdecode(file_get_contents($file)) : file_get_contents($file);
    if ($sql === false || stripos($sql, 'CREATE TABLE') === false) {
        throw new RuntimeException('This file is not a valid Gullapudi backup.');
    }

    $pdo = db();
    $statements = backup_split_sql($sql);
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    $count = 0;
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '' || strpos($statement, '--') === 0 && strpos($statement, "\n") === false) {
            continue;
        }
        // Never let a restore touch the backup history table.
        if (preg_match('/`backup_operations`/i', $statement)) {
            continue;
        }
        $pdo->exec($statement);
        $count++;
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    return $count;
}

/** Split SQL text into statements, respecting quoted strings. */
function backup_split_sql(string $sql): array
{
    $out = [];
    $buf = '';
    $len = strlen($sql);
    $quote = null;

    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];

        if ($quote !== null) {
            $buf .= $c;
            if ($c === '\\' && $i + 1 < $len) {
                $buf .= $sql[++$i];
            } elseif ($c === $quote) {
                if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                    $buf .= $sql[++$i];
                } else {
                    $quote = null;
                }
            }
            continue;
        }

        if ($c === "'" || $c === '"' || $c === '`') {
            $quote = $c;
            $buf .= $c;
        } elseif ($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-' && ($buf === '' || trim($buf) === '' || substr($buf, -1) === "\n")) {
            // skip comment line
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
        } elseif ($c === ';') {
            if (trim($buf) !== '') {
                $out[] = $buf;
            }
            $buf = '';
        } else {
            $buf .= $c;
        }
    }
    if (trim($buf) !== '') {
        $out[] = $buf;
    }
    return $out;
}
