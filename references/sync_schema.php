<?php

declare(strict_types=1);
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
/**
 * sync_schema.php
 *
 * Uso:
 *   php sync_schema.php <referencia.sql> <destino.sql> [opciones]
 *
 * Opciones:
 *   --data=tabla1,tabla2     Tablas existentes cuyo contenido también se migra.
 *   --clean                  Antes de insertar datos, hace TRUNCATE de esas tablas.
 *   --ignore                 Usa INSERT IGNORE (por defecto).
 *
 * Ejemplo:
 *   php sync_schema.php base_origen.sql base_destino.sql \
 *       --data=tipo_operacion,tipo_ingreso,sector,tipo_costo_gasto_compra \
 *       --clean > migracion.sql
 */

if ($argc < 3) {
    fwrite(STDERR, "Uso: php sync_schema.php <referencia.sql> <destino.sql> [--data=...] [--clean]\n");
    exit(1);
}

$refFile    = $argv[1];
$targetFile = $argv[2];

$dataTables = [];
$clean      = false;
$ignore     = true;

for ($i = 3; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (str_starts_with($arg, '--data=')) {
        $dataTables = array_filter(array_map('trim', explode(',', substr($arg, 7))));
    } elseif ($arg === '--clean') {
        $clean = true;
    } elseif ($arg === '--no-ignore') {
        $ignore = false;
    }
}

/* -------------------------------------------------------------------------
 * Parser SQL
 * ---------------------------------------------------------------------- */

function encontrarCierreParentesis(string $sql, int $start): ?int
{
    $len = strlen($sql);
    $depth = 0;
    $inSingle = $inDouble = $inBacktick = false;
    $escape = false;

    for ($i = $start; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($escape) {
            $escape = false;
            continue;
        }
        if (($inSingle || $inDouble) && $ch === '\\') {
            $escape = true;
            continue;
        }

        if (!$inDouble && !$inBacktick && $ch === "'") {
            $inSingle = !$inSingle;
            continue;
        }
        if (!$inSingle && !$inBacktick && $ch === '"') {
            $inDouble = !$inDouble;
            continue;
        }
        if (!$inSingle && !$inDouble && $ch === '`') {
            $inBacktick = !$inBacktick;
            continue;
        }

        if ($inSingle || $inDouble || $inBacktick) {
            continue;
        }

        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }
    return null;
}

/**
 * Extrae CREATE TABLE + columnas.
 */
function extraerTablas(string $sql): array
{
    $tables = [];
    $offset = 0;

    while (($pos = stripos($sql, 'CREATE TABLE', $offset)) !== false) {
        if (!preg_match('~CREATE TABLE\s+`([^`]+)`~i', $sql, $m, 0, $pos)) {
            $offset = $pos + 12;
            continue;
        }

        $table   = $m[1];
        $nameEnd = $pos + strlen($m[0]);

        $parenStart = strpos($sql, '(', $nameEnd);
        if ($parenStart === false) {
            $offset = $pos + 12;
            continue;
        }

        $closeParen = encontrarCierreParentesis($sql, $parenStart);
        if ($closeParen === null) {
            $offset = $pos + 12;
            continue;
        }

        $semi = strpos($sql, ';', $closeParen);
        if ($semi === false) {
            $semi = $closeParen;
        }

        $create = substr($sql, $pos, $semi - $pos + 1);
        $body   = substr($sql, $parenStart + 1, $closeParen - $parenStart - 1);

        $columns = [];
        foreach (preg_split('/\R/', $body) as $linea) {
            $linea = trim(rtrim($linea, ','));
            if ($linea === '' || $linea[0] !== '`') {
                continue;
            }
            if (preg_match('/^`([^`]+)`\s+(.*)$/s', $linea, $cm)) {
                $columns[$cm[1]] = trim($cm[2]);
            }
        }

        $tables[$table] = [
            'create'  => $create,
            'columns' => $columns,
        ];

        $offset = $semi + 1;
    }

    return $tables;
}

/**
 * Extrae todas las sentencias INSERT INTO `tabla` ... ;
 * Devuelve [tabla => [statements...]].
 */
function extraerInserts(string $sql): array
{
    $inserts = [];
    $offset  = 0;
    $len     = strlen($sql);

    while (($pos = stripos($sql, 'INSERT INTO', $offset)) !== false) {
        if (!preg_match('/INSERT INTO\s+`([^`]+)`/i', $sql, $m, 0, $pos)) {
            $offset = $pos + 11;
            continue;
        }
        $table = $m[1];

        // Buscar el ; final que no esté dentro de strings
        $i = $pos;
        $inSingle = $inDouble = false;
        $escape = false;
        $end = null;

        while ($i < $len) {
            $ch = $sql[$i];
            if ($escape) {
                $escape = false;
                $i++;
                continue;
            }
            if (($inSingle || $inDouble) && $ch === '\\') {
                $escape = true;
                $i++;
                continue;
            }
            if (!$inDouble && $ch === "'") {
                $inSingle = !$inSingle;
                $i++;
                continue;
            }
            if (!$inSingle && $ch === '"') {
                $inDouble = !$inDouble;
                $i++;
                continue;
            }
            if (!$inSingle && !$inDouble && $ch === ';') {
                $end = $i;
                break;
            }
            $i++;
        }

        if ($end === null) {
            $offset = $pos + 11;
            continue;
        }

        $stmt = trim(substr($sql, $pos, $end - $pos + 1));
        $inserts[$table][] = $stmt;
        $offset = $end + 1;
    }

    return $inserts;
}

/* -------------------------------------------------------------------------
 * Lectura y parseo
 * ---------------------------------------------------------------------- */

$refSql    = file_get_contents($refFile);
$targetSql = file_get_contents($targetFile);

if ($refSql === false || $targetSql === false) {
    fwrite(STDERR, "No se pudo leer uno de los archivos SQL.\n");
    exit(2);
}

$ref    = extraerTablas($refSql);
$target = extraerTablas($targetSql);
$datos  = extraerInserts($refSql);

$out = [];
$out[] = "-- Migración generada por sync_schema.php";
$out[] = "-- Referencia: " . $refFile;
$out[] = "-- Destino:    " . $targetFile;
if ($dataTables) {
    $out[] = "-- Tablas con datos: " . implode(', ', $dataTables);
}
$out[] = "SET FOREIGN_KEY_CHECKS=0;";
$out[] = "SET NAMES utf8mb4;";
$out[] = "";

/* -------------------------------------------------------------------------
 * 1) Tablas nuevas: CREATE + INSERT
 * ---------------------------------------------------------------------- */

$nuevas = 0;
foreach ($ref as $table => $refTable) {
    if (isset($target[$table])) {
        continue;
    }

    $nuevas++;
    $out[] = "-- =========================================================";
    $out[] = "-- Tabla NUEVA: `{$table}`";
    $out[] = "-- =========================================================";
    $out[] = $refTable['create'];
    $out[] = "";

    if (!empty($datos[$table])) {
        $out[] = "-- Datos para `{$table}`";
        foreach ($datos[$table] as $ins) {
            $out[] = $ins;
        }
        $out[] = "";
    }
}

/* -------------------------------------------------------------------------
 * 2) Tablas existentes: columnas faltantes + datos si está en whitelist
 * ---------------------------------------------------------------------- */

foreach ($ref as $table => $refTable) {
    if (!isset($target[$table])) {
        continue;
    }

    // 2.1 Columnas nuevas
    $alter = [];
    foreach ($refTable['columns'] as $col => $def) {
        if (!isset($target[$table]['columns'][$col])) {
            $alter[] = "ALTER TABLE `{$table}` ADD COLUMN `{$col}` {$def};";
        }
    }

    if ($alter) {
        $out[] = "-- =========================================================";
        $out[] = "-- Columnas nuevas en `{$table}`";
        $out[] = "-- =========================================================";
        foreach ($alter as $sql) {
            $out[] = $sql;
        }
        $out[] = "";
    }

    // 2.2 Datos para tablas de la whitelist
    if (in_array($table, $dataTables, true) && !empty($datos[$table])) {
        $out[] = "-- =========================================================";
        $out[] = "-- Datos de catálogo: `{$table}`";
        $out[] = "-- =========================================================";

        if ($clean) {
            $out[] = "TRUNCATE TABLE `{$table}`;";
        }

        foreach ($datos[$table] as $ins) {
            if ($ignore && !$clean) {
                $ins = preg_replace('/^INSERT INTO/i', 'INSERT IGNORE INTO', $ins);
            }
            $out[] = $ins;
        }
        $out[] = "";
    }
}

$out[] = "SET FOREIGN_KEY_CHECKS=1;";

echo implode("\n", $out);
