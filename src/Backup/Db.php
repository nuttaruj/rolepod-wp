<?php
declare(strict_types=1);

namespace Rolepod\Wp\Backup;

/**
 * Chunked SQL dumper built on $wpdb (no wp-cli / mysqldump dependency, so it
 * works on REST-only targets too). The Engine drives it table-by-table and
 * row-batch-by-row-batch so a large database exports gradually without one long
 * blocking query.
 *
 * Output is plain, greppable mysqldump-style SQL — AI-friendly and restorable.
 * The real table prefix is preserved and recorded in the manifest (no
 * tokenization in phase 1; restore is a later phase).
 */
final class Db
{
    /** @return string[] all base tables in the current database */
    public static function tables(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"', ARRAY_N);
        if (!is_array($rows)) {
            return [];
        }
        return array_map(static fn(array $r): string => (string) $r[0], $rows);
    }

    public static function rowCount(string $table): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . self::esc($table) . '`');
    }

    /** Schema header for a table: DROP + CREATE. */
    public static function schemaSql(string $table): string
    {
        global $wpdb;
        $t = self::esc($table);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $create = $wpdb->get_row('SHOW CREATE TABLE `' . $t . '`', ARRAY_N);
        $ddl = is_array($create) ? (string) ($create[1] ?? '') : '';
        $sql = "\n-- Table: {$table}\nDROP TABLE IF EXISTS `{$t}`;\n";
        if ($ddl !== '') {
            $sql .= $ddl . ";\n";
        }
        return $sql;
    }

    /**
     * One batch of INSERTs for $table starting at $offset. Returns the SQL plus
     * how many rows it covered (0 = no more rows).
     *
     * @return array{sql:string,rows:int}
     */
    public static function rowsSql(string $table, int $offset, int $limit): array
    {
        global $wpdb;
        $t = self::esc($table);
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM `' . $t . '` LIMIT %d, %d', $offset, $limit),
            ARRAY_A
        );
        if (!is_array($rows) || $rows === []) {
            return ['sql' => '', 'rows' => 0];
        }

        return self::format($t, $rows);
    }

    /** @return array{sql:string,rows:int} */
    private static function format(string $t, array $rows): array
    {
        $cols = array_keys($rows[0]);
        $colList = implode(', ', array_map(static fn(string $c): string => '`' . self::esc($c) . '`', $cols));

        $values = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach ($cols as $c) {
                $vals[] = self::value($row[$c]);
            }
            $values[] = '(' . implode(',', $vals) . ')';
        }

        $sql = "INSERT INTO `{$t}` ({$colList}) VALUES\n" . implode(",\n", $values) . ";\n";
        return ['sql' => $sql, 'rows' => count($rows)];
    }

    /**
     * Pick the pagination cursor for a table. A single-column PRIMARY KEY (or,
     * failing that, a single-column UNIQUE NOT NULL index) of an integer or
     * string type pages by key (O(n) overall). Composite or missing keys, and
     * other types, keep the OFFSET path (`LIMIT offset, n`), which is O(n^2) on
     * big tables but needs no key.
     *
     * @return array{mode:string,col?:string,num?:bool,last?:?string}
     */
    public static function cursorFor(string $table): array
    {
        global $wpdb;
        $t = self::esc($table);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $keys = $wpdb->get_results('SHOW KEYS FROM `' . $t . '`', ARRAY_A);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $cols = $wpdb->get_results('SHOW COLUMNS FROM `' . $t . '`', ARRAY_A);
        $pick = self::pickKey(is_array($keys) ? $keys : [], is_array($cols) ? $cols : []);
        return $pick === null
            ? ['mode' => 'offset']
            : ['mode' => 'key', 'col' => $pick['col'], 'num' => $pick['num'], 'last' => null];
    }

    /**
     * Pure key choice from SHOW KEYS / SHOW COLUMNS rows.
     *
     * @param array<int,array<string,mixed>> $keys SHOW KEYS rows
     * @param array<int,array<string,mixed>> $cols SHOW COLUMNS rows
     * @return array{col:string,num:bool}|null
     */
    public static function pickKey(array $keys, array $cols): ?array
    {
        $types = [];
        foreach ($cols as $c) {
            $types[(string) ($c['Field'] ?? '')] = strtolower((string) ($c['Type'] ?? ''));
        }
        $byIndex = [];
        foreach ($keys as $k) {
            $byIndex[(string) ($k['Key_name'] ?? '')][] = $k;
        }
        $usable = static function (array $rows) use ($types): ?array {
            if (count($rows) !== 1) {
                return null; // composite
            }
            $r = $rows[0];
            $col = (string) ($r['Column_name'] ?? '');
            if ($col === '' || ($r['Sub_part'] ?? null) !== null) {
                return null; // prefix index does not order the full value
            }
            $kind = self::keyKind($types[$col] ?? '');
            return $kind === null ? null : ['col' => $col, 'num' => $kind === 'int'];
        };
        if (isset($byIndex['PRIMARY'])) {
            // A PRIMARY KEY decides the path; never mix in a unique index.
            return $usable($byIndex['PRIMARY']);
        }
        foreach ($byIndex as $rows) {
            if ((int) ($rows[0]['Non_unique'] ?? 1) !== 0 || strtoupper((string) ($rows[0]['Null'] ?? 'YES')) === 'YES') {
                continue;
            }
            $hit = $usable($rows);
            if ($hit !== null) {
                return $hit;
            }
        }
        return null;
    }

    /** 'int' | 'str' for orderable key types, null otherwise (decimal, float, blob, binary, date...). */
    private static function keyKind(string $type): ?string
    {
        if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint)\b/', $type) === 1) {
            return 'int';
        }
        if (preg_match('/^(char|varchar)\b/', $type) === 1) {
            return 'str';
        }
        return null;
    }

    /**
     * One batch of INSERTs for $table, advancing $cursor (from cursorFor()).
     * Key mode: `WHERE key > last ORDER BY key LIMIT n`; offset mode: the
     * legacy `LIMIT offset, n` ($cursor['offset']). The returned cursor is the
     * one to persist for the next batch. rows 0 = no more rows.
     *
     * @param array{mode:string,col?:string,num?:bool,last?:?string,offset?:int} $cursor
     * @return array{sql:string,rows:int,cursor:array}
     */
    public static function batchSql(string $table, array $cursor, int $limit): array
    {
        global $wpdb;
        if (($cursor['mode'] ?? 'offset') !== 'key') {
            $off = (int) ($cursor['offset'] ?? 0);
            $chunk = self::rowsSql($table, $off, $limit);
            $cursor['mode'] = 'offset';
            $cursor['offset'] = $off + $chunk['rows'];
            return $chunk + ['cursor' => $cursor];
        }
        $t = self::esc($table);
        $colRaw = (string) $cursor['col'];
        // '%' in an identifier must not read as a prepare() placeholder.
        $col = str_replace('%', '%%', '`' . self::esc($colRaw) . '`');
        $args = [];
        $where = '';
        $last = $cursor['last'] ?? null;
        if ($last !== null) {
            if (!empty($cursor['num'])) {
                // Validated digits inlined: %d would truncate unsigned BIGINT beyond PHP_INT_MAX.
                if (preg_match('/^-?\d+$/', $last) !== 1) {
                    throw new \RuntimeException('Backup cursor for ' . $table . ' is not numeric; refusing to treat the table as drained.');
                }
                $where = " WHERE {$col} > {$last}";
            } else {
                $where = " WHERE {$col} > %s";
                $args[] = $last;
            }
        }
        $args[] = $limit;
        // phpcs:ignore WordPress.DB.PreparedSQL
        $sql = $wpdb->prepare('SELECT * FROM `' . str_replace('%', '%%', $t) . '`' . $where . " ORDER BY {$col} LIMIT %d", ...$args);
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || $rows === []) {
            return ['sql' => '', 'rows' => 0, 'cursor' => $cursor];
        }
        $cursor['last'] = (string) $rows[count($rows) - 1][$colRaw];
        return self::format($t, $rows) + ['cursor' => $cursor];
    }

    /** Quote a cell value safely for SQL. */
    private static function value($v): string
    {
        global $wpdb;
        if ($v === null) {
            return 'NULL';
        }
        return "'" . $wpdb->_real_escape((string) $v) . "'";
    }

    /** Escape a backtick identifier. */
    private static function esc(string $ident): string
    {
        return str_replace('`', '``', $ident);
    }
}
