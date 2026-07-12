<?php
declare(strict_types=1);

namespace Rolepod\Wp\Media;

/**
 * Import a single file into the media library from one of three sources — a
 * base64 payload, a remote https URL, or a server-local path already under
 * wp-content. Produces a real attachment (generated sub-sizes + alt text), so
 * the AI can set featured images and insert media without a browser upload.
 *
 * Every source is bounded BEFORE anything is written to the library:
 *   - base64 : decoded size capped, mime validated by wp_handle_sideload
 *   - url    : https-only, run through wp_http_validate_url (blocks private
 *              ranges / loopback → SSRF), size capped after download
 *   - local  : realpath must resolve INSIDE wp-content, so a caller cannot
 *              attach /etc/passwd or wp-config.php
 *
 * The endpoint records the result as a reversible `media/import` ledger row
 * (revert = delete the attachment via Toggler).
 */
final class Importer
{
    /** Hard ceiling on a single import, decoded/downloaded (25 MiB). */
    public const MAX_BYTES = 26_214_400;

    /**
     * @param array{source?:string,data?:string,url?:string,path?:string,filename?:string,alt?:string,title?:string,caption?:string,attach_to_post?:int} $args
     * @return array{ok:bool,error_code?:string,error_message?:string,attachment_id?:int,url?:string,alt?:string,mime?:string,bytes?:int,source?:string}
     */
    public static function import(array $args): array
    {
        $source = (string) ($args['source'] ?? '');

        // Stage the bytes into a temp file that wp_handle_sideload will MOVE.
        $staged = self::stage($source, $args);
        if (!$staged['ok']) {
            return $staged;
        }
        $tmpPath  = $staged['path'];
        $filename = $staged['filename'];

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // wp_handle_sideload MOVES tmp into uploads and enforces the site's
        // allowed-mime map — an executable/disallowed type is rejected here,
        // not attached.
        $file     = ['name' => $filename, 'tmp_name' => $tmpPath];
        $sideload = wp_handle_sideload($file, ['test_form' => false]);
        if (isset($sideload['error'])) {
            @unlink($tmpPath);
            return [
                'ok' => false,
                'error_code' => 'SIDELOAD_REJECTED',
                'error_message' => (string) $sideload['error'],
            ];
        }

        $movedFile = (string) $sideload['file'];
        $mime      = (string) ($sideload['type'] ?? '');
        $attachTo  = max(0, (int) ($args['attach_to_post'] ?? 0));

        $attachId = wp_insert_attachment(
            [
                'post_mime_type' => $mime,
                'post_title'     => self::title($args, $filename),
                'post_content'   => '',
                'post_excerpt'   => (string) ($args['caption'] ?? ''),
                'post_status'    => 'inherit',
            ],
            $movedFile,
            $attachTo,
            true
        );
        if (is_wp_error($attachId) || (int) $attachId <= 0) {
            @unlink($movedFile);
            return [
                'ok' => false,
                'error_code' => 'INSERT_FAILED',
                'error_message' => is_wp_error($attachId) ? $attachId->get_error_message() : 'wp_insert_attachment returned 0',
            ];
        }
        $attachId = (int) $attachId;

        $meta = wp_generate_attachment_metadata($attachId, $movedFile);
        if (is_array($meta)) {
            wp_update_attachment_metadata($attachId, $meta);
        }

        $alt = trim((string) ($args['alt'] ?? ''));
        if ($alt !== '') {
            update_post_meta($attachId, '_wp_attachment_image_alt', $alt);
        }

        return [
            'ok' => true,
            'attachment_id' => $attachId,
            'url' => (string) wp_get_attachment_url($attachId),
            'alt' => $alt,
            'mime' => $mime,
            'bytes' => (int) (@filesize($movedFile) ?: 0),
            'source' => $source,
        ];
    }

    /**
     * Write the source bytes to a temp file wp_handle_sideload can consume.
     *
     * @return array{ok:bool,path?:string,filename?:string,error_code?:string,error_message?:string}
     */
    private static function stage(string $source, array $args): array
    {
        $filename = self::filename($source, $args);

        switch ($source) {
            case 'base64':
                return self::stageBase64((string) ($args['data'] ?? ''), $filename);
            case 'url':
                return self::stageUrl((string) ($args['url'] ?? ''), $filename);
            case 'local_path':
                return self::stageLocalPath((string) ($args['path'] ?? ''), $filename);
            default:
                return [
                    'ok' => false,
                    'error_code' => 'BAD_SOURCE',
                    'error_message' => "source must be one of base64|url|local_path, got '{$source}'",
                ];
        }
    }

    private static function stageBase64(string $data, string $filename): array
    {
        $raw = self::decodeBase64($data);
        if ($raw === null) {
            return ['ok' => false, 'error_code' => 'INVALID_BASE64', 'error_message' => 'data is not valid base64'];
        }
        if ($raw === '') {
            return ['ok' => false, 'error_code' => 'EMPTY_PAYLOAD', 'error_message' => 'decoded payload is empty'];
        }
        if (strlen($raw) > self::MAX_BYTES) {
            return ['ok' => false, 'error_code' => 'TOO_LARGE', 'error_message' => 'decoded payload exceeds ' . self::MAX_BYTES . ' bytes'];
        }
        $tmp = wp_tempnam($filename);
        if (!$tmp || file_put_contents($tmp, $raw) === false) {
            return ['ok' => false, 'error_code' => 'STAGE_FAILED', 'error_message' => 'could not write temp file'];
        }
        return ['ok' => true, 'path' => $tmp, 'filename' => $filename];
    }

    private static function stageUrl(string $url, string $filename): array
    {
        if (!self::isHttps($url)) {
            return ['ok' => false, 'error_code' => 'NOT_HTTPS', 'error_message' => 'url must be https'];
        }
        // wp_http_validate_url rejects loopback + private IP ranges → SSRF guard.
        if (!wp_http_validate_url($url)) {
            return ['ok' => false, 'error_code' => 'URL_BLOCKED', 'error_message' => 'url failed WP validation (private/loopback host not allowed)'];
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $tmp = download_url($url, 300);
        if (is_wp_error($tmp)) {
            return ['ok' => false, 'error_code' => 'DOWNLOAD_FAILED', 'error_message' => $tmp->get_error_message()];
        }
        if ((int) @filesize($tmp) > self::MAX_BYTES) {
            @unlink($tmp);
            return ['ok' => false, 'error_code' => 'TOO_LARGE', 'error_message' => 'downloaded file exceeds ' . self::MAX_BYTES . ' bytes'];
        }
        return ['ok' => true, 'path' => $tmp, 'filename' => $filename];
    }

    private static function stageLocalPath(string $path, string $filename): array
    {
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            return ['ok' => false, 'error_code' => 'NOT_FOUND', 'error_message' => 'path does not resolve to a file'];
        }
        if (!self::withinContent($real)) {
            return ['ok' => false, 'error_code' => 'PATH_OUT_OF_SCOPE', 'error_message' => 'path must resolve inside wp-content'];
        }
        if ((int) @filesize($real) > self::MAX_BYTES) {
            return ['ok' => false, 'error_code' => 'TOO_LARGE', 'error_message' => 'file exceeds ' . self::MAX_BYTES . ' bytes'];
        }
        // Copy to a temp file — sideload MOVES its input and we must not move
        // the caller's original out of wp-content.
        $tmp = wp_tempnam($filename);
        if (!$tmp || !@copy($real, $tmp)) {
            return ['ok' => false, 'error_code' => 'STAGE_FAILED', 'error_message' => 'could not stage local file'];
        }
        return ['ok' => true, 'path' => $tmp, 'filename' => $filename];
    }

    // ---- pure helpers (unit-tested via reflection) -----------------------

    /** Strict base64 decode; null on malformed input. */
    private static function decodeBase64(string $data): ?string
    {
        // Tolerate a data: URI prefix (data:image/png;base64,....).
        if (stripos($data, 'base64,') !== false) {
            $data = substr($data, strpos($data, 'base64,') + 7);
        }
        $data = trim($data);
        $raw = base64_decode($data, true);
        return $raw === false ? null : $raw;
    }

    private static function isHttps(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    /** True when $realPath (already realpath'd) sits inside WP_CONTENT_DIR. */
    private static function withinContent(string $realPath): bool
    {
        $base = defined('WP_CONTENT_DIR') ? realpath(WP_CONTENT_DIR) : false;
        if ($base === false) {
            return false;
        }
        $base = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return strncmp($realPath, $base, strlen($base)) === 0;
    }

    /** Derive a safe filename with an extension the mime map can accept. */
    private static function filename(string $source, array $args): string
    {
        $explicit = trim((string) ($args['filename'] ?? ''));
        if ($explicit !== '') {
            return sanitize_file_name($explicit);
        }
        if ($source === 'url') {
            $path = (string) parse_url((string) ($args['url'] ?? ''), PHP_URL_PATH);
            $base = sanitize_file_name(basename($path));
            if ($base !== '' && strpos($base, '.') !== false) {
                return $base;
            }
        }
        if ($source === 'local_path') {
            $base = sanitize_file_name(basename((string) ($args['path'] ?? '')));
            if ($base !== '' && strpos($base, '.') !== false) {
                return $base;
            }
        }
        // Fall back to a generic name; sideload will reject if the real bytes
        // do not match an allowed image/document type.
        return 'rolepod-import.png';
    }

    private static function title(array $args, string $filename): string
    {
        $title = trim((string) ($args['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }
        return sanitize_text_field(pathinfo($filename, PATHINFO_FILENAME));
    }
}
