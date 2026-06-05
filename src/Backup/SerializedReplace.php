<?php
declare(strict_types=1);

namespace Rolepod\Wp\Backup;

/**
 * Serialized-data-safe search/replace.
 *
 * Naively str_replace()-ing a URL across a DB corrupts any PHP-serialized value
 * (e.g. widget/theme options) because serialized strings carry a byte-length
 * prefix — `s:19:"https://old.example"` — that no longer matches after the
 * replacement. The robust technique (used by every serious migration tool) is
 * to recurse: unserialize → replace inside the structure → re-serialize, so the
 * length prefixes are regenerated correctly. Plain strings are replaced
 * directly.
 *
 * Used by RestoreEngine's optional URL-rewrite stage so a backup can be restored
 * onto a different domain/path (same-site restore needs no rewrite).
 */
final class SerializedReplace
{
    /**
     * Apply $map (old => new) to $data, descending into serialized strings,
     * arrays, and objects. Returns the transformed value.
     *
     * @param array<string,string> $map
     */
    public static function apply($data, array $map, int $depth = 0)
    {
        if ($depth > 30) {
            return $data; // guard against pathological nesting
        }

        if (is_string($data)) {
            $unser = self::tryUnserialize($data);
            if ($unser !== self::NOT_SERIALIZED) {
                return serialize(self::apply($unser, $map, $depth + 1));
            }
            return strtr($data, $map);
        }

        if (is_array($data)) {
            $out = [];
            foreach ($data as $k => $v) {
                $newKey = is_string($k) ? strtr($k, $map) : $k;
                $out[$newKey] = self::apply($v, $map, $depth + 1);
            }
            return $out;
        }

        if (is_object($data)) {
            // Preserve class for typed objects; __PHP_Incomplete_Class is left as-is.
            if ($data instanceof \__PHP_Incomplete_Class) {
                return $data;
            }
            $clone = clone $data;
            foreach (get_object_vars($clone) as $k => $v) {
                $clone->$k = self::apply($v, $map, $depth + 1);
            }
            return $clone;
        }

        return $data; // int / float / bool / null
    }

    /** Convenience: apply to a string column value, returning the new string. */
    public static function applyToValue(string $value, array $map): string
    {
        $r = self::apply($value, $map);
        return is_string($r) ? $r : (string) $value;
    }

    /** Sentinel distinct from any real unserialized value (including false). */
    private const NOT_SERIALIZED = "\0__rp_not_serialized__\0";

    /**
     * Unserialize ONLY if the string is genuinely serialized; otherwise return
     * the NOT_SERIALIZED sentinel (so a literal "false"/"b:0;" is handled right).
     */
    private static function tryUnserialize(string $data)
    {
        if (!self::looksSerialized($data)) {
            return self::NOT_SERIALIZED;
        }
        // Allow only stdClass (covers the common WP option/meta case); any
        // other class is left as __PHP_Incomplete_Class and passed through
        // untouched — no arbitrary object instantiation (object-injection safe).
        $result = @unserialize($data, ['allowed_classes' => ['stdClass']]);
        if ($result === false) {
            // 'b:0;' legitimately unserializes to false.
            return $data === 'b:0;' ? false : self::NOT_SERIALIZED;
        }
        return $result;
    }

    /** Cheap structural sniff before attempting unserialize. */
    private static function looksSerialized(string $data): bool
    {
        $data = trim($data);
        if (strlen($data) < 2) {
            return false;
        }
        if ($data === 'N;') {
            return true;
        }
        if (!preg_match('/^([adObis]):/', $data, $m)) {
            return false;
        }
        switch ($m[1]) {
            case 'a': // a:N:{...}
            case 'O': // O:N:"Class":M:{...}
                return (bool) preg_match('/^' . $m[1] . ':\d+:/', $data) && substr($data, -1) === '}';
            case 's': // s:N:"...";
                return (bool) preg_match('/^s:\d+:"/', $data) && substr($data, -1) === ';';
            case 'b': // b:0; / b:1;
            case 'i':
            case 'd':
                return (bool) preg_match('/^' . $m[1] . ':[^;]+;$/', $data);
        }
        return false;
    }
}
