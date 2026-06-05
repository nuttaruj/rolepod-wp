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
