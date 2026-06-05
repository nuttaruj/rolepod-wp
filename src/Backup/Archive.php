<?php
declare(strict_types=1);

namespace Rolepod\Wp\Backup;

use ZipArchive;

/**
 * ZIP archive wrapper for backups.
 *
 * ZIP is chosen over tar/tar.gz because it is the only common container that is
 * BOTH small AND browsable-without-extracting: its central directory (an index
 * at the end of the file) lets us list contents and read a single member
 * without unpacking the whole archive, and each entry is compressed
 * independently for random access.
 *
 * CPU is kept low with a per-entry compression strategy: already-compressed
 * media is STORED (deflating a JPEG wastes CPU for ~0 gain) while text/SQL/code
 * is DEFLATED (big wins). Combined with the throttled Engine, compression never
 * spikes the box.
 *
 * The archive is built incrementally across cron ticks: each tick opens the
 * existing zip, appends a batch (existing entries are copied as-is, only new
 * ones are deflated), and closes — so a large site archives gradually.
 */
final class Archive
{
    /** Extensions stored without compression — already compressed, deflate is wasted CPU. */
    private const STORE_EXT = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico',
        'mp4', 'mov', 'webm', 'avi', 'mkv', 'mp3', 'wav', 'ogg', 'm4a',
        'zip', 'gz', 'tgz', 'bz2', 'xz', 'rar', '7z',
        'woff', 'woff2', 'pdf', 'eot',
    ];

    private ZipArchive $zip;
    private string $path;

    private function __construct(ZipArchive $zip, string $path)
    {
        $this->zip = $zip;
        $this->path = $path;
    }

    /**
     * Open (creating if absent) for appending a batch. Returns null if the zip
     * extension is unavailable or the file can't be opened.
     */
    public static function openForAppend(string $path): ?self
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }
        $zip = new ZipArchive();
        $flags = is_file($path) ? 0 : ZipArchive::CREATE;
        if ($zip->open($path, $flags) !== true) {
            return null;
        }
        return new self($zip, $path);
    }

    /** Add a real file from disk, picking STORE vs DEFLATE by extension. */
    public function addFileSmart(string $localPath, string $entryName): bool
    {
        if (!$this->zip->addFile($localPath, $entryName)) {
            return false;
        }
        $this->applyCompression($entryName);
        return true;
    }

    /** Add in-memory content (manifest, sql, …) — always DEFLATE unless media-typed name. */
    public function addStringSmart(string $entryName, string $content): bool
    {
        if (!$this->zip->addFromString($entryName, $content)) {
            return false;
        }
        $this->applyCompression($entryName);
        return true;
    }

    public function close(): bool
    {
        return $this->zip->close();
    }

    private function applyCompression(string $entryName): void
    {
        $ext = strtolower((string) pathinfo($entryName, PATHINFO_EXTENSION));
        $method = in_array($ext, self::STORE_EXT, true) ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE;
        // setCompressionName exists on libzip 0.11+ (PHP 7.0+ bundled). Guard anyway.
        if (method_exists($this->zip, 'setCompressionName')) {
            @$this->zip->setCompressionName($entryName, $method);
        }
    }

    // --- browse-without-extract -------------------------------------------

    /**
     * List archive members by reading only the central directory — no
     * extraction. Powers the admin "view inside" + the MCP inspect tool.
     *
     * @return array{ok:bool,entries?:list<array{name:string,size:int,compressed:int,method:int}>,error?:string}
     */
    public static function listEntries(string $path, int $limit = 5000): array
    {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'ZIP_UNAVAILABLE'];
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return ['ok' => false, 'error' => 'OPEN_FAILED'];
        }
        $entries = [];
        $n = min($zip->numFiles, $limit);
        for ($i = 0; $i < $n; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $entries[] = [
                'name' => (string) $stat['name'],
                'size' => (int) $stat['size'],
                'compressed' => (int) $stat['comp_size'],
                'method' => (int) $stat['comp_method'],
            ];
        }
        $total = $zip->numFiles;
        $zip->close();
        return ['ok' => true, 'entries' => $entries, 'total' => $total, 'truncated' => $total > $n];
    }

    /**
     * Read a single member's content without extracting the whole archive.
     *
     * @return array{ok:bool,content?:string,bytes?:int,error?:string}
     */
    public static function readEntry(string $path, string $entryName, int $maxBytes = 1_000_000): array
    {
        if (!class_exists('ZipArchive')) {
            return ['ok' => false, 'error' => 'ZIP_UNAVAILABLE'];
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return ['ok' => false, 'error' => 'OPEN_FAILED'];
        }
        $stat = $zip->statName($entryName);
        if ($stat === false) {
            $zip->close();
            return ['ok' => false, 'error' => 'ENTRY_NOT_FOUND'];
        }
        $content = $zip->getFromName($entryName, $maxBytes);
        $zip->close();
        if ($content === false) {
            return ['ok' => false, 'error' => 'READ_FAILED'];
        }
        return ['ok' => true, 'content' => $content, 'bytes' => (int) $stat['size'], 'truncated' => (int) $stat['size'] > strlen($content)];
    }
}
