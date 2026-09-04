<?php

declare(strict_types=1);

namespace Rolepod\Wp;

use Plugin_Upgrader;
use Rolepod\Wp\Audit\Log;
use Rolepod\Wp\Bootstrap\EndpointRegistrar;
use WP_Error;
use WP_Upgrader_Skin;

/**
 * Put back source files a host malware scanner emptied.
 *
 * `src/Endpoint/ExecutePhp.php` runs PHP you send it, so a signature scanner
 * reads it as a backdoor. Imunify360 in particular *trims* a file it cannot
 * clean rather than deleting it, and it does so again within the hour, so this
 * is not a one-time accident to recover from — it is a recurring state.
 *
 * Since 2.24.1 the damage is contained: the affected endpoints stop, the site's
 * REST API keeps working, and the admin notice names the files. What was still
 * missing was a way back that did not mean downloading a zip and walking the
 * Plugins → Upload → Replace flow by hand.
 *
 * **Deliberately manual.** A plugin that silently rewrites its own PHP whenever
 * a file goes missing is behaving exactly like malware persistence — recovery
 * after removal is the signature scanners hunt for — and it would fight the
 * scanner in a loop nobody wins. Repair happens when an administrator asks for
 * it, once, and the notice keeps saying that the real fix is an ignore-list
 * entry for the path.
 *
 * @package rolepod-wp
 */
final class Repair
{
    private const REPO = 'nuttaruj/rolepod-wp';

    /**
     * The release zip for the version that is installed — NOT `latest`.
     *
     * `Updater::PACKAGE_URL` points at `releases/latest` because an update is
     * meant to move forward. A repair must not: pulling `latest` here would
     * turn "put my files back" into a silent upgrade the admin never asked for.
     */
    public static function packageUrl(): string
    {
        return sprintf(
            'https://github.com/%s/releases/download/v%s/rolepod-wp.zip',
            self::REPO,
            ROLEPOD_WP_VERSION
        );
    }

    /**
     * Endpoint files that are absent or empty on disk right now.
     *
     * Read from the filesystem rather than trusting the stored option, so the
     * button never reports damage that has already been repaired by other means.
     *
     * @return array<string, string> class => path relative to the plugin dir
     */
    public static function damagedFiles(): array
    {
        $out = [];

        foreach (array_keys(EndpointRegistrar::broken()) as $class) {
            $relative = strpos($class, 'Rolepod\\Wp\\') === 0
                ? substr($class, strlen('Rolepod\\Wp\\'))
                : $class;
            $rel = 'src/' . str_replace('\\', '/', $relative) . '.php';
            $abs = ROLEPOD_WP_DIR . $rel;

            if (!is_file($abs) || filesize($abs) === 0) {
                $out[$class] = $rel;
            }
        }

        return $out;
    }

    public static function isNeeded(): bool
    {
        return self::damagedFiles() !== [];
    }

    /**
     * Reinstall this exact version over the current directory.
     *
     * Uses WordPress's own `Plugin_Upgrader` with `overwrite_package`, the same
     * path the Plugins → Upload screen takes when it offers "Replace current
     * with uploaded". No bespoke download-and-write code lives here on purpose:
     * a plugin that fetches remote PHP and writes it to disk is worth exactly
     * one implementation, and core already maintains it.
     *
     * Activation state is untouched — the directory name does not change, so
     * an active plugin stays active.
     *
     * @return array{ok: bool, error?: string, message?: string, repaired?: array<string, string>}
     */
    public static function run(): array
    {
        if (!current_user_can('manage_options')) {
            return ['ok' => false, 'error' => 'FORBIDDEN'];
        }

        $damaged = self::damagedFiles();
        if ($damaged === []) {
            return [
                'ok' => false,
                'error' => 'NOTHING_TO_REPAIR',
                'message' => 'Every endpoint file is present. Nothing was downloaded.',
            ];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $upgrader = new Plugin_Upgrader(new WP_Upgrader_Skin(['api' => false]));
        $result = $upgrader->install(self::packageUrl(), ['overwrite_package' => true]);

        $failed = $result instanceof WP_Error
            ? $result->get_error_message()
            : ($result === true || $result === null ? null : 'installer returned an unexpected value');

        if ($failed !== null) {
            Log::append([
                'endpoint' => 'admin/repair',
                'user' => (string) wp_get_current_user()->user_login,
                'site_url' => (string) get_option('siteurl'),
                'result' => 'error',
                'error' => $failed,
            ]);

            return ['ok' => false, 'error' => $failed];
        }

        // Let the next request rebuild the list from a fresh registration pass
        // rather than leaving a stale option behind the UI.
        delete_option(EndpointRegistrar::OPTION);

        Log::append([
            'endpoint' => 'admin/repair',
            'user' => (string) wp_get_current_user()->user_login,
            'site_url' => (string) get_option('siteurl'),
            'result' => 'success',
            'error' => null,
        ]);

        $still = self::damagedFiles();

        if ($still !== []) {
            return [
                'ok' => false,
                'error' => 'REPAIR_DID_NOT_STICK',
                'message' => 'Files were reinstalled but are already empty again — the scanner is removing them faster than this can replace them. Add the paths to its ignore list first.',
                'repaired' => $damaged,
            ];
        }

        return ['ok' => true, 'repaired' => $damaged];
    }
}
