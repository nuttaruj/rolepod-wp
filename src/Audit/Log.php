<?php
declare(strict_types=1);

namespace Rolepod\Wp\Audit;

/**
 * Append-only audit log for power-endpoint calls. v0.1 writes:
 *   - File: wp-content/uploads/rolepod-wp-audit/{audit_id}.log  (mode 0600)
 *   - Option: rolepod_wp_audit_log (capped 1000 entries, FIFO eviction)
 *
 * v0.2 will swap the option-array store for a rolling-file index to avoid
 * the 1000-entry cap on heavy use.
 */
final class Log
{
    private const OPTION = 'rolepod_wp_audit_log';
    private const OPTION_CAP = 1000;

    /**
     * @param array{
     *   endpoint: string,
     *   payload_sha256?: string,
     *   payload?: string,
     *   user: string,
     *   site_url: string,
     *   result: string,
     *   error?: string,
     * } $record
     * @return string audit_id
     */
    public static function append(array $record): string
    {
        $auditId = 'rolepod_wp_audit_' . bin2hex(random_bytes(4));
        $row = array_merge($record, [
            'audit_id' => $auditId,
            'timestamp' => gmdate('c'),
            'payload_sha256' => $record['payload_sha256'] ?? (
                isset($record['payload']) ? hash('sha256', (string) $record['payload']) : null
            ),
        ]);
        // Never store the raw payload in the option array — file only.
        $payload = $row['payload'] ?? null;
        unset($row['payload']);

        // 1. Append to option array (FIFO cap)
        $existing = get_option(self::OPTION, []);
        if (!is_array($existing)) {
            $existing = [];
        }
        $existing[] = $row;
        if (count($existing) > self::OPTION_CAP) {
            $existing = array_slice($existing, -self::OPTION_CAP);
        }
        update_option(self::OPTION, $existing, false);

        // 2. Write full payload to file (mode 0600) when available
        if (is_string($payload) && $payload !== '') {
            self::writeFile($auditId, $payload);
        }

        // 3. Stream to webhook (Slack/Discord/generic) when configured.
        //    Non-blocking + self-guarded; never raises into the caller.
        Notifier::maybeNotify($row);

        return $auditId;
    }

    private static function writeFile(string $auditId, string $payload): void
    {
        $uploadDir = wp_upload_dir();
        if (!is_array($uploadDir) || empty($uploadDir['basedir'])) {
            return;
        }
        $auditDir = trailingslashit($uploadDir['basedir']) . 'rolepod-wp-audit';
        if (!is_dir($auditDir)) {
            @mkdir($auditDir, 0700, true);
        }
        if (!is_dir($auditDir)) {
            return;
        }
        $path = $auditDir . '/' . $auditId . '.log';
        $written = @file_put_contents($path, $payload);
        if ($written !== false) {
            @chmod($path, 0600);
        }
    }

    /** @return array<int, array<string, mixed>> last N entries (newest first) */
    public static function tail(int $n = 50): array
    {
        $existing = get_option(self::OPTION, []);
        if (!is_array($existing)) {
            return [];
        }
        $slice = array_slice($existing, -$n);
        return array_reverse($slice);
    }
}
