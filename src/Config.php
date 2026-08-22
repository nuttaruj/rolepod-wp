<?php
declare(strict_types=1);

namespace Rolepod\Wp;

/**
 * Read/write companion config stored in wp_options::rolepod_wp_config.
 *
 * Shape:
 *   [
 *     'endpoints_enabled'   => bool,    // master toggle (Settings page)
 *     'execute_php_enabled' => bool,    // v0.1 stays false; v0.2 default true
 *   ]
 */
final class Config
{
    private const OPTION = 'rolepod_wp_config';

    public static function all(): array
    {
        $raw = get_option(self::OPTION, []);
        return is_array($raw) ? $raw : [];
    }

    /**
     * v2.8.9: deprecated. Plugin activation is now the single consent
     * gate for read + scoped-write endpoints. Always returns true so
     * the existing permission-callback guard pattern keeps working but
     * never blocks. Execute-php still has its own opt-in toggle.
     *
     * Stored `endpoints_enabled` value is ignored. Power users who need
     * a kill switch should deactivate the plugin.
     */
    public static function endpointsEnabled(): bool
    {
        return true;
    }

    public static function executePhpEnabled(): bool
    {
        return (bool) (self::all()['execute_php_enabled'] ?? false);
    }

    /**
     * The full-access toggle (stored as `execute_php_enabled` for wire and
     * option back-compat) is the access mode for the whole power surface:
     * ON = full access, OFF = guarded — the safe subset recommended for live
     * sites. One switch, one owner decision. The companion never tries to
     * guess whether the site is production; that call belongs to the user.
     */
    public static function fullAccess(): bool
    {
        return self::executePhpEnabled();
    }

    /**
     * Webhook URL for event streaming (Slack / Discord / generic JSON).
     * Empty string = streaming off.
     */
    public static function webhookUrl(): string
    {
        $url = self::all()['webhook_url'] ?? '';
        return is_string($url) ? trim($url) : '';
    }

    /**
     * Which events to stream: 'errors' (default — rejected/error + execute-php)
     * or 'all'. Any unknown value falls back to 'errors'.
     */
    public static function webhookLevel(): string
    {
        $level = self::all()['webhook_level'] ?? 'errors';
        return $level === 'all' ? 'all' : 'errors';
    }

    public static function update(array $patch): void
    {
        $current = self::all();
        update_option(self::OPTION, array_merge($current, $patch));
    }
}
