<?php

declare(strict_types=1);

namespace Rolepod\Wp\Bootstrap;

use Throwable;

/**
 * Registers the REST endpoint classes, defensively.
 *
 * The plugin autoloads with `if (is_file($file)) { require $file; }`, so a
 * file that exists but is empty loads cleanly and defines nothing. The
 * `Class not found` that follows used to escape the `rest_api_init` handler
 * and take every REST route on the site down with it — core `wp/v2` routes
 * included, and even routes that belong to other plugins.
 *
 * That is not hypothetical. Host malware scanners truncate ExecutePhp.php to
 * zero bytes: its whole job is to eval arbitrary PHP, so it reads as a
 * backdoor. One unloadable endpoint must cost that endpoint and nothing else.
 *
 * @package rolepod-wp
 */
final class EndpointRegistrar
{
    /**
     * Autoloaded on purpose — the healthy path reads it from `alloptions`
     * rather than paying a query on every REST request.
     */
    public const OPTION = 'rolepod_wp_broken_endpoints';

    /**
     * Ordered. SafeModeGuard arms before any endpoint so a mutating request is
     * refused while safe-mode is on.
     *
     * @return string[]
     */
    public static function classes(): array
    {
        return [
            \Rolepod\Wp\Security\SafeModeGuard::class,
            \Rolepod\Wp\Endpoint\Handshake::class,
            \Rolepod\Wp\Endpoint\Introspect::class,
            \Rolepod\Wp\Endpoint\ExecutePhp::class,
            \Rolepod\Wp\Endpoint\WpCli::class,
            \Rolepod\Wp\Endpoint\FsRead::class,
            \Rolepod\Wp\Endpoint\FsWrite::class,
            \Rolepod\Wp\Endpoint\PhpSession::class,
            \Rolepod\Wp\Endpoint\RequestObserver::class,
            \Rolepod\Wp\Endpoint\Pair::class,
            // v2.3 — change ledger
            \Rolepod\Wp\Endpoint\Changes::class,
            // v2.4 — pre-write syntax check + theme snapshot/restore
            \Rolepod\Wp\Endpoint\SyntaxCheck::class,
            \Rolepod\Wp\Endpoint\ThemeSnapshot::class,
            // v2.5 — one-time admin login + file disable/enable + field-plugin adapters
            \Rolepod\Wp\Endpoint\OneTimeLogin::class,
            \Rolepod\Wp\Endpoint\FsRename::class,
            // v2.7 — direct wp_options access (bypass REST /wp/v2/settings allowlist)
            \Rolepod\Wp\Endpoint\Options::class,
            // v2.7.2 — SELECT-only DB query endpoint
            \Rolepod\Wp\Endpoint\DbQuery::class,
            // v2.11 — atomic batch write, fs primitives, Elementor introspection
            \Rolepod\Wp\Endpoint\FsWriteBatch::class,
            \Rolepod\Wp\Endpoint\DirEnsure::class,
            \Rolepod\Wp\Endpoint\FsCopy::class,
            \Rolepod\Wp\Endpoint\FsList::class,
            \Rolepod\Wp\Endpoint\ElementorIntrospect::class,
            // v2.12 — widget data-attr rehydrate, template-apply, async jobs
            \Rolepod\Wp\Endpoint\ElementorWidgetAttribute::class,
            \Rolepod\Wp\Endpoint\ElementorTemplateApply::class,
            \Rolepod\Wp\Endpoint\JobCreate::class,
            \Rolepod\Wp\Endpoint\JobStatus::class,
            // v2.13 — site-owned agent skills
            \Rolepod\Wp\Endpoint\Skills::class,
            // v2.14 — bulk media optimize
            \Rolepod\Wp\Endpoint\MediaOptimize::class,
            // v2.17 — throttled site backup
            \Rolepod\Wp\Endpoint\Backup::class,
            // v2.23 — import media from base64 / https url / wp-content local path
            \Rolepod\Wp\Endpoint\MediaImport::class,
        ];
    }

    /**
     * Register every endpoint, surviving any that cannot load or that throws.
     *
     * @param string[]|null $classes Defaults to self::classes(); injectable for tests.
     *
     * @return array<string, string> class => reason. Empty when all registered.
     */
    public static function registerAll(?array $classes = null): array
    {
        $failed = [];

        foreach ($classes ?? self::classes() as $class) {
            try {
                if (!class_exists($class)) {
                    $failed[$class] = 'class not found — file missing or empty';
                    continue;
                }
                $class::register();
            } catch (Throwable $e) {
                $failed[$class] = get_class($e) . ': ' . $e->getMessage();
            }
        }

        return $failed;
    }

    /**
     * Persist the failure set, writing only when it actually changed — this
     * runs on every REST request.
     *
     * @param array<string, string> $failed
     */
    public static function recordFailures(array $failed): void
    {
        $stored = get_option(self::OPTION, []);
        if (!is_array($stored)) {
            $stored = [];
        }

        if ($stored === $failed) {
            return;
        }

        if ($failed === []) {
            delete_option(self::OPTION);
            return;
        }

        update_option(self::OPTION, $failed, true);
    }

    /**
     * Endpoints that failed to register on the last REST request.
     *
     * @return array<string, string>
     */
    public static function broken(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }
}
