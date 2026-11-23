<?php
declare(strict_types=1);

/**
 * Standalone test for Backup\Db keyset pagination: key detection (single PK int /
 * varchar, unique NOT NULL fallback, composite / missing → OFFSET) and the
 * generated query + cursor advance, against a fake $wpdb.
 * Run: php tests/Unit/backup-keyset-test.php
 */

define('ARRAY_A', 'ARRAY_A');
define('ARRAY_N', 'ARRAY_N');
require __DIR__ . '/../../src/Backup/Db.php';

use Rolepod\Wp\Backup\Db;

final class FakeWpdb
{
    /** @var string[] */
    public array $queries = [];
    /** @var array<int,array<int,array<string,mixed>>> */
    public array $queue = [];
    /** @var array<string,array<int,array<string,mixed>>> */
    public array $byPrefix = [];

    public function prepare(string $q, ...$args): string
    {
        $i = 0;
        $q = str_replace('%%', "\0", $q);
        $q = (string) preg_replace_callback('/%([sd])/', function ($m) use (&$i, $args) {
            $v = $args[$i++];
            return $m[1] === 'd' ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
        }, $q);
        return str_replace("\0", '%', $q);
    }

    public function get_results(string $q, $o = null)
    {
        $this->queries[] = $q;
        foreach ($this->byPrefix as $prefix => $rows) {
            if (strpos($q, $prefix) === 0) {
                return $rows;
            }
        }
        return array_shift($this->queue) ?? [];
    }

    public function _real_escape(string $s): string
    {
        return addslashes($s);
    }

    public function remove_placeholder_escape(string $s): string
    {
        return $s;
    }
}

$fails = 0;
function check(string $label, $got, $want): void
{
    global $fails;
    if ($got !== $want) {
        $fails++;
        fwrite(STDERR, "FAIL {$label}\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n");
        return;
    }
    echo "ok   {$label}\n";
}

function kx(string $name, string $col, int $seq = 1, int $nonUnique = 0, string $null = '', $sub = null): array
{
    return ['Key_name' => $name, 'Column_name' => $col, 'Seq_in_index' => $seq, 'Non_unique' => $nonUnique, 'Null' => $null, 'Sub_part' => $sub];
}
function col(string $f, string $t): array
{
    return ['Field' => $f, 'Type' => $t];
}

// --- key detection (pure) ---
check('single int PK', Db::pickKey([kx('PRIMARY', 'id')], [col('id', 'bigint(20) unsigned')]), ['col' => 'id', 'num' => true]);
check('single varchar PK', Db::pickKey([kx('PRIMARY', 'option_name')], [col('option_name', 'varchar(191)')]), ['col' => 'option_name', 'num' => false]);
check('composite PK → offset', Db::pickKey([kx('PRIMARY', 'a', 1), kx('PRIMARY', 'b', 2)], [col('a', 'int(11)'), col('b', 'int(11)')]), null);
check('no key → offset', Db::pickKey([], [col('a', 'int(11)')]), null);
check('non-unique index only → offset', Db::pickKey([kx('idx', 'a', 1, 1)], [col('a', 'int(11)')]), null);
check('unique NOT NULL fallback', Db::pickKey([kx('uq', 'slug')], [col('slug', 'varchar(64)')]), ['col' => 'slug', 'num' => false]);
check('unique nullable → offset', Db::pickKey([kx('uq', 'slug', 1, 0, 'YES')], [col('slug', 'varchar(64)')]), null);
check('composite PK is not rescued by a unique index', Db::pickKey([kx('PRIMARY', 'a', 1), kx('PRIMARY', 'b', 2), kx('uq', 'c')], [col('a', 'int'), col('b', 'int'), col('c', 'int')]), null);
check('prefix PK → offset', Db::pickKey([kx('PRIMARY', 't', 1, 0, '', 20)], [col('t', 'varchar(255)')]), null);
check('decimal / blob key → offset', Db::pickKey([kx('PRIMARY', 'd')], [col('d', 'decimal(10,2)')]), null);

// --- query + cursor advance ---
$wpdb = new FakeWpdb();
$wpdb->byPrefix['SHOW KEYS'] = [kx('PRIMARY', 'meta_id')];
$wpdb->byPrefix['SHOW COLUMNS'] = [col('meta_id', 'bigint(20) unsigned'), col('v', 'text')];
$cur = Db::cursorFor('wp_x');
check('cursorFor key mode', $cur, ['mode' => 'key', 'col' => 'meta_id', 'num' => true, 'last' => null]);

$wpdb->byPrefix = [];
$wpdb->queries = [];
$wpdb->queue = [[['meta_id' => '5', 'v' => 'a'], ['meta_id' => '9', 'v' => "b'q"]], [['meta_id' => '12', 'v' => null]], []];
$b1 = Db::batchSql('wp_x', $cur, 2);
check('first batch has no WHERE', $wpdb->queries[0], 'SELECT * FROM `wp_x` ORDER BY `meta_id` LIMIT 2');
check('cursor advances to last key', $b1['cursor']['last'], '9');
check('batch rows', $b1['rows'], 2);
check('SQL format unchanged', $b1['sql'], "INSERT INTO `wp_x` (`meta_id`, `v`) VALUES\n('5','a'),\n('9','b\\'q');\n");
$b2 = Db::batchSql('wp_x', $b1['cursor'], 2);
check('next batch uses WHERE key > last', $wpdb->queries[1], 'SELECT * FROM `wp_x` WHERE `meta_id` > 9 ORDER BY `meta_id` LIMIT 2');
check('cursor advances again', $b2['cursor']['last'], '12');
$b3 = Db::batchSql('wp_x', $b2['cursor'], 2);
check('drained → 0 rows, cursor kept', [$b3['rows'], $b3['cursor']['last']], [0, '12']);

// string key: prepared %s, quote-escaped
$wpdb->queue = [[['k' => "z'; DROP", 'v' => '1']]];
$s = Db::batchSql('wp_o', ['mode' => 'key', 'col' => 'k', 'num' => false, 'last' => "it's"], 5);
check('string key prepared + escaped', $wpdb->queries[count($wpdb->queries) - 1], "SELECT * FROM `wp_o` WHERE `k` > 'it\\'s' ORDER BY `k` LIMIT 5");
check('string cursor stored raw', $s['cursor']['last'], "z'; DROP");

// numeric cursor that is not digits is refused, never interpolated
$n = count($wpdb->queries);
$threw = false;
try {
    Db::batchSql('wp_x', ['mode' => 'key', 'col' => 'id', 'num' => true, 'last' => '1 OR 1=1'], 5);
} catch (\RuntimeException $e) {
    $threw = true;
}
check('non-digit numeric cursor → throws, no query', [$threw, count($wpdb->queries)], [true, $n]);

// backtick in identifier is doubled
$wpdb->queue = [[]];
Db::batchSql('we`ird', ['mode' => 'key', 'col' => 'c`d', 'num' => true, 'last' => null], 1);
check('identifiers backtick-escaped', $wpdb->queries[count($wpdb->queries) - 1], 'SELECT * FROM `we``ird` ORDER BY `c``d` LIMIT 1');

// offset mode (composite / legacy 2.27.0 job) keeps LIMIT offset, n
$wpdb->queue = [[['a' => '1']]];
$o = Db::batchSql('wp_c', ['mode' => 'offset', 'offset' => 1000], 500);
check('offset path query', $wpdb->queries[count($wpdb->queries) - 1], 'SELECT * FROM `wp_c` LIMIT 1000, 500');
check('offset cursor advances', $o['cursor'], ['mode' => 'offset', 'offset' => 1001]);

exit($fails === 0 ? 0 : 1);
