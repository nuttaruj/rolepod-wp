<?php
declare(strict_types=1);

namespace Rolepod\Wp\Audit;

use Rolepod\Wp\Config;

/**
 * Webhook event streaming (v2.14).
 *
 * Fires a fire-and-forget POST to a configured webhook (Slack, Discord, or a
 * generic JSON endpoint) when an audited companion call matches the chosen
 * level. This is the companion-side analogue of SproutOS's Slack/Discord event
 * stream, wired into our existing audit choke point (Log::append) so every
 * power-endpoint call can surface in a team channel without polling the admin.
 *
 * Levels (config `webhook_level`, default 'errors'):
 *   - 'errors' : only rejected / error results, PLUS every execute-php call
 *                (the one endpoint dangerous enough to always announce).
 *   - 'all'    : every audited event.
 *
 * Delivery is non-blocking (blocking=false) so a slow/dead webhook never adds
 * latency to the WP request, and never raises — failures are swallowed.
 */
final class Notifier
{
    /**
     * Decide whether an audit row should be streamed, and stream it.
     *
     * @param array<string, mixed> $row Audit row as built by Log::append.
     */
    public static function maybeNotify(array $row): void
    {
        // Wrap the ENTIRE method: it is called from Log::append on every
        // audited write, so nothing here — not a config read, not payload
        // shaping, not delivery — may ever raise into the caller and break the
        // operation being audited. (A version-skewed deploy that momentarily
        // lacks Config::webhookUrl() must not fatal the write it is logging.)
        try {
            $url = Config::webhookUrl();
            if ($url === '') {
                return;
            }

            $endpoint = (string) ($row['endpoint'] ?? '');
            $result = (string) ($row['result'] ?? '');
            $isError = $result !== '' && $result !== 'success';

            $level = Config::webhookLevel();
            $send = $level === 'all'
                || $isError
                || $endpoint === 'execute-php';
            if (!$send) {
                return;
            }

            self::dispatch($url, [
                'event' => $isError ? 'companion.error' : 'companion.call',
                'endpoint' => $endpoint,
                'result' => $result,
                'user' => (string) ($row['user'] ?? ''),
                'error' => isset($row['error']) ? (string) $row['error'] : null,
                'site_url' => (string) ($row['site_url'] ?? ''),
                'audit_id' => (string) ($row['audit_id'] ?? ''),
                'timestamp' => (string) ($row['timestamp'] ?? gmdate('c')),
            ]);
        } catch (\Throwable $t) {
            // Swallow — audit + the audited operation must still succeed.
        }
    }

    /**
     * POST a payload to the webhook, shaping it per the detected provider.
     *
     * @param array<string, mixed> $data
     */
    public static function dispatch(string $url, array $data): void
    {
        if ($url === '' || !function_exists('wp_remote_post')) {
            return;
        }

        try {
            $body = self::shapePayload($url, $data);
            wp_remote_post($url, [
                'timeout' => 3,
                'blocking' => false, // fire-and-forget — never block the request
                'redirection' => 2,
                'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode($body),
            ]);
        } catch (\Throwable $t) {
            // Webhook delivery must never break the actual operation.
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function shapePayload(string $url, array $data): array
    {
        $text = self::renderText($data);

        // Slack incoming webhook → { text }
        if (strpos($url, 'hooks.slack.com') !== false) {
            return ['text' => $text];
        }
        // Discord webhook → { content }
        if (strpos($url, 'discord.com/api/webhooks') !== false
            || strpos($url, 'discordapp.com/api/webhooks') !== false) {
            return ['content' => $text];
        }
        // Generic endpoint → structured JSON + a human-readable text field.
        return array_merge($data, ['text' => $text]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function renderText(array $data): string
    {
        $result = (string) ($data['result'] ?? '');
        $icon = $result === 'success' ? '✅' : ($result === 'rejected' ? '⛔' : '⚠️');
        $host = (string) parse_url((string) ($data['site_url'] ?? ''), PHP_URL_HOST);
        $line = sprintf(
            '%s Rolepod · `%s` → %s on *%s* (by %s)',
            $icon,
            (string) ($data['endpoint'] ?? '?'),
            $result !== '' ? $result : 'call',
            $host !== '' ? $host : (string) ($data['site_url'] ?? '?'),
            (string) ($data['user'] ?? '?')
        );
        $err = (string) ($data['error'] ?? '');
        if ($err !== '') {
            $line .= "\n> " . $err;
        }
        return $line;
    }
}
