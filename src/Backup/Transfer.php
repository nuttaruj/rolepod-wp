<?php
declare(strict_types=1);

namespace Rolepod\Wp\Backup;

/**
 * Chunked, base64-framed transfer of backup archives over REST — so a backup
 * can be pulled offsite (download) or pushed in from another host (import)
 * without a single request carrying the whole (often >100 MB) zip.
 *
 * Download is a pure read: `readChunk()` returns a slice of the archive with a
 * running `total_bytes`/`eof` so the client reassembles a byte-identical file.
 *
 * Import is a resumable append: each chunk must arrive at the exact current
 * end of the staging file (`OFFSET_MISMATCH` otherwise), so a dropped or
 * out-of-order chunk can never silently corrupt the reassembled zip. Once the
 * staging file is complete the endpoint hands it to Engine::importUpload,
 * which is the sole authority on whether the bytes are a real Rolepod backup
 * (NO_MANIFEST / BAD_FORMAT live there).
 *
 * The staging path is derived from a sanitised upload id — the caller's id is
 * never used as a raw path component, so a crafted id cannot traverse.
 */
final class Transfer
{
    /** Max raw bytes served per download chunk (base64 inflates ~33% on the wire). */
    public const MAX_CHUNK = 1_048_576;

    /**
     * Read one slice of $path for download.
     *
     * @return array{ok:bool,error?:string,offset?:int,length?:int,total_bytes?:int,eof?:bool,data?:string}
     */
    public static function readChunk(string $path, int $offset, int $length): array
    {
        if ($path === '' || !is_file($path)) {
            return ['ok' => false, 'error' => 'NOT_FOUND'];
        }
        $total = (int) filesize($path);
        if ($offset < 0 || $offset > $total) {
            return ['ok' => false, 'error' => 'OFFSET_OUT_OF_RANGE', 'total_bytes' => $total];
        }
        $length = max(1, min(self::MAX_CHUNK, $length));

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return ['ok' => false, 'error' => 'READ_FAILED'];
        }
        $bytes = '';
        if ($offset < $total) {
            fseek($fh, $offset);
            $bytes = (string) fread($fh, $length);
        }
        fclose($fh);

        $read = strlen($bytes);
        return [
            'ok' => true,
            'offset' => $offset,
            'length' => $read,
            'total_bytes' => $total,
            'eof' => ($offset + $read) >= $total,
            'data' => base64_encode($bytes),
        ];
    }

    /** Absolute staging path for an in-flight import, derived from a safe id. */
    public static function stagePath(string $dir, string $uploadId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '', $uploadId) ?? '';
        $safe = trim($safe, '.');
        if ($safe === '') {
            $safe = 'anon';
        }
        $safe = substr($safe, 0, 128);
        return rtrim($dir, '/\\') . '/rolepod-xfer-' . $safe . '.part';
    }

    /**
     * Append one import chunk. The chunk MUST start at the current end of the
     * staging file; anything else is rejected so a gap/overlap cannot corrupt
     * the reassembled archive.
     *
     * @return array{ok:bool,error?:string,received?:int,expected_offset?:int}
     */
    public static function appendChunk(string $dir, string $uploadId, int $offset, string $rawBytes): array
    {
        $path = self::stagePath($dir, $uploadId);
        $current = is_file($path) ? (int) filesize($path) : 0;
        if ($offset !== $current) {
            return ['ok' => false, 'error' => 'OFFSET_MISMATCH', 'expected_offset' => $current];
        }
        $fh = fopen($path, $offset === 0 ? 'wb' : 'ab');
        if ($fh === false) {
            return ['ok' => false, 'error' => 'STAGE_FAILED'];
        }
        $written = fwrite($fh, $rawBytes);
        fclose($fh);
        if ($written === false) {
            return ['ok' => false, 'error' => 'STAGE_FAILED'];
        }
        return ['ok' => true, 'received' => (int) filesize($path)];
    }

    /** Drop a staging file (after a successful import or an abort). */
    public static function discard(string $dir, string $uploadId): void
    {
        $path = self::stagePath($dir, $uploadId);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
