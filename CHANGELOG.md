# Changelog

All notable changes to this plugin are documented here. Follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Plugin versions track `@rolepod/wplab` MCP family. See `MIN_COMPANION_VERSION` in `rolepod-wplab/src/companion/constants.ts` for the floor the MCP client expects.

## [2.15.0] — 2026-06-05 — Bulk media optimize

### Added

- **`POST /media-optimize`** — bulk-recompress (and optionally downscale)
  media-library image originals over a byte threshold; the "optimize all images
  over 200KB" workflow, drawn from a WPWriter feature comparison. Params:
  `min_bytes` (default 200000), `max_dimension` (0 = no resize), `quality`
  (default 82), `limit` (default 20, max 100), `apply` (default false).
  - **dry-run by default** — reports candidates + current sizes, writes nothing.
  - on `apply=true`, every original is copied to
    `uploads/rolepod-wp/media-backups/` BEFORE overwrite and each optimize is
    recorded in the Change Ledger (`category=media`, reversible).
  - a re-encode that doesn't actually shrink the file restores the original
    (never trades quality for zero gain).
  - refuses with `423 SAFE_MODE` when the guardian safe-mode flag is on.
  - only the full-size original is touched; thumbnails are left as-is; stored
    attachment metadata (`filesize`, and `width`/`height` when downscaled) is
    kept consistent. `src/Endpoint/MediaOptimize.php`.

### Tests

- `tests/Unit/media-optimize-test.php` (7) — candidate filter/sort/cap. The
  WP_Image_Editor encode path is verified live.

## [2.14.0] — 2026-06-05 — SproutOS-parity hardening: symbol-conflict screen, boot-loop auto-heal, webhook stream, wider Abilities

Four infra/safety deltas drawn from a comparison with the SproutOS MCP plugin.
Each is independent and opt-in where it could regress behaviour.

### Added

- **Symbol-conflict screen for `/execute-php`.** Before `eval()`, the payload is
  scanned for global `function` / `class` / `interface` / `trait` / `enum`
  declarations whose names already exist in the runtime. A "Cannot redeclare"
  fatal is not reliably catchable by the existing `try/catch`, so the call is
  now rejected with `409 SYMBOL_CONFLICT` (listing the offending symbols) before
  it can crash the request. Brace-depth tracking means methods inside a class
  body, closures, arrow-fns, anonymous classes, and `Foo::class` are correctly
  ignored. Bypass per-call with the new `allow_redeclare=true` parameter.
  `src/Security/AstScreen.php`, `src/Endpoint/ExecutePhp.php`.
- **Boot-loop auto-heal in the recovery guardian (opt-in, default OFF).** When
  the *same* plugin/theme/mu-plugin file fatals on N consecutive guardian-
  observed requests (`ROLEPOD_WP_GUARDIAN_AUTOHEAL_THRESHOLD`, default 3), the
  guardian renames it `.disabled` (whole plugin for a plugin file; single file
  for theme/mu-plugin) and raises safe-mode — breaking a WSOD loop with no SSH.
  Scoped to `wp-content/{plugins,themes,mu-plugins}`; never touches core or the
  guardian itself. New `POST /wplab-recovery/v1/autoheal {enabled}` toggle and
  `autoheal_enabled` / `autoheal_log` / `fatal_streak` fields on `/status`.
  Guardian bumped to 2.8.0. `guardian/rolepod-wp-guardian.php`.
- **Webhook event streaming.** `Audit\Notifier` POSTs audited companion calls to
  a Slack, Discord, or generic JSON webhook, wired into the `Audit\Log::append`
  choke point. Level `errors` (default — rejected/error results plus every
  `execute-php` call) or `all`. Delivery is non-blocking and self-guarded, so a
  slow/dead webhook never adds latency or raises. Configurable from
  Settings → Rolepod (URL + level + Send-test button); provider auto-detected
  from the URL. `src/Audit/Notifier.php`, `src/Config.php`, `src/Admin/SettingsPage.php`.
- **Wider Abilities API coverage.** Three read-only abilities added to the
  WP 7.0 Abilities bridge so the native WP AI Client / any Abilities consumer
  can orient and read content without the external MCP CLI: `rolepod/site-info`,
  `rolepod/list-posts`, `rolepod/get-post`. All gated by `manage_options`.
  `src/Abilities/{SiteInfoAbility,ListPostsAbility,GetPostAbility,Bridge}.php`.

### Tests

- `tests/Unit/astscreen-symbols-test.php` (14), `guardian-autoheal-test.php`
  (20), `notifier-test.php` (14) — standalone, no wp-env. 48 checks total.

## [2.13.2] — 2026-05-29 — Fix: fs-write bytes_written on append

### Fixed

- `/fs-write` reported the wrong `bytes_written` on `mode=append` — it returned
  `filesize()`, whose stat result is cached from the pre-write backup `copy()`,
  so callers saw the file's *old* size instead of the bytes written. Now returns
  the actual return value of `file_put_contents()` (bytes appended on append;
  equals content length on overwrite). `src/Endpoint/FsWrite.php`.

## [2.13.1] — 2026-05-29 — Consolidated data namespace + deny-htaccess

### Added

- On `rest_api_init`, ensure `wp-content/uploads/rolepod-wp/` exists with a
  deny-all `.htaccess` (`Require all denied`), mirroring the existing
  `wp-content/private/` guard. This is the single home for Rolepod runtime
  artifacts (backups, tmp payloads written by `@rolepod/wplab` 1.21.3), so
  backups are never web-readable. Idempotent; cheap.

## [2.13.0] — 2026-05-29 — Site-owned skills

Adds CPT-backed agent skills — site-specific playbooks (build conventions,
brand voice, schema quirks) that travel with the WordPress install and are
discoverable by any agent that connects. Pairs with `@rolepod/wplab` 1.21.0.

### Added

- **Skills CPT (`rolepod_wp_skill`)** — one skill per post (title→slug,
  excerpt→trigger description, content→SKILL.md body) with 15 native
  revisions for recovery. `src/Skills/Cpt.php`.
- **`src/Skills/Parser.php`** — lenient SKILL.md frontmatter parser
  (name / description / enable_agentic / enable_prompt) + canonical renderer
  + slug normalizer. Smart double-encode unescape: expands literal `\n`/`\t`
  ONLY when the body has no real newline (a flattened double-JSON-encoded
  payload), so legitimate escapes inside code samples survive. `enable_prompt`
  defaults OFF (opt-in). Soft warnings for empty description, empty body, and
  oversized (>50 KB) bodies.
- **`src/Skills/Catalog.php`** — structured read side (find / all /
  discoverable / catalog). Single user-CPT source — no premature registry
  abstraction.
- **REST endpoints** under `wplab/v1/skills` (`src/Endpoint/Skills.php`):
  - `GET /skills` — compact catalog (slug + description, no bodies)
  - `GET /skills/<slug>` — full record + rendered SKILL.md
  - `POST /skills` — create/update (session-token), `on_conflict`
    fail | replace | rename
  - `POST /skills/<slug>/edit` — patch fields
  - `DELETE /skills/<slug>` — trash (recoverable, never force-deleted)
  - Mutations are audited via `Log`; reads need `manage_options`, writes also
    require a valid session token. Collision responses carry `suggested_slug`.
- **`skills` capability** advertised in the Handshake + Pair responses so the
  MCP client can feature-gate the skill tools.

## [2.12.1] — 2026-05-28 — Section `_css_classes` rendering fix

Pairs with `@rolepod/wplab` 1.17.1. Fixes a known Elementor 4.x free-
version quirk that the WalnutZtudio polish session surfaced.

### Fixed

- **Section-level `_css_classes` now lands on the rendered `<section>`
  tag (#19).** Elementor 4.x renders widget-level `_css_classes`
  natively but silently drops section-level entries. New
  `Rolepod\Wp\ElementorCompat` class hooks the
  `elementor/frontend/the_content` filter, walks the post's
  `_elementor_data`, builds a section_id → `_css_classes` map, and
  regex-injects the classes into each `<section data-id="X">` opening
  tag. Inner sections (`isInner=true`) included.

### Detection trail

For posterity (this is a non-obvious Elementor quirk):

- The legacy `elementor/element/before_render` action only fires in
  editor / control panel context — confirmed by tracing the live hook
  tree on a 4.1.1 install.
- `elementor/frontend/before_render` fires only for `widget` elType in
  Elementor 4.x, NOT for `section`. So a hook-based add-render-attribute
  workaround cannot work for sections.
- The `the_content` post-filter approach is the only public surface
  that lets us inject the classes after Elementor's section renderer
  has run.

### Internal

- New: `src/ElementorCompat.php` — single static class with `init()` +
  `rewriteSectionClasses()` + private map builder.
- `rolepod-wp.php` calls `\Rolepod\Wp\ElementorCompat::init()` at
  top-level plugin load (no action wrapper — the filter registration
  itself does not run Elementor code, safe to call when Elementor is
  absent).

### Field-tested

After hot-deploy:

```html
<section class="elementor-section elementor-top-section elementor-element elementor-element-11bf8f9d ... wnz-sec wnz-hero">
```

Was previously:

```html
<section class="elementor-section elementor-top-section elementor-element elementor-element-11bf8f9d ...">
```

(no `wnz-sec wnz-hero` suffix). The WalnutZtudio hero CSS that targets
`.wnz-hero` selector now matches.

## [2.12.0] — 2026-05-28 — widget-attr rehydrate + template-apply + private zone + async jobs

Pairs with `@rolepod/wplab` 1.17.0. Closes the remaining capability gaps
(#6, #10, #13, #15, #17, #18) — see `brief/wplab-capability-gaps.md` in
the MCP repo.

### Added

- **`POST /wp-json/wplab/v1/elementor/widget-attribute`** — stores per-
  widget `data-*` attribute maps in `_rolepod_widget_attrs` post meta.
  Sanitization: attr keys must match `/^[a-z][a-z0-9-]{0,30}$/`; values
  are stringified. Empty `attrs` map clears the widget's entry.
- **Footer bridge emitter** (`wp_footer` priority 5) — outputs a
  `<script id="rolepod-widget-attrs" type="application/json">` carrying
  the post's full attr map plus a 4-line vanilla-JS reader that walks
  the map and applies `data-*` attrs to `[data-id="<widget_id>"]`
  matches. Theme JS that searches for `[data-scramble]` etc finds the
  rehydrated attrs on first paint.
- **`POST /wp-json/wplab/v1/elementor/template-apply`** — companion
  counterpart to `template-export`. Validates target post existence,
  refuses to overwrite non-empty `_elementor_data` without
  `overwrite=true`, applies optional `replace_strings`, regenerates
  every 6-12 char hex element id to avoid collisions with the source,
  writes `_elementor_data` + flags, clears the cached CSS meta.
- **`POST /wp-json/wplab/v1/job/create` + `GET /wp-json/wplab/v1/job/status`** —
  fire-and-poll wp-cli runner. `/job/create` spawns wp-cli detached
  via `popen()`, captures the OS pid, stores a job record (args, pid,
  log paths, started_at) in a 1h transient. `/job/status` checks
  `/proc/<pid>` (or `posix_kill` fallback) and returns `state` +
  `elapsed_seconds` + tail of stdout/stderr (default 8KB, max 64KB).
  Returns 503 EXEC_DISABLED when `exec()` is off.
- **`wp-content/private/` added to scoped dirs** — accepted by FsWrite,
  FsWriteBatch, DirEnsure, FsCopy. First use auto-installs
  `Require all denied` `.htaccess` in that dir (via `rest_api_init`
  priority 99) so dev scratch never leaks over HTTP.

### Internal

- New endpoint classes: `Endpoint\ElementorWidgetAttribute`,
  `Endpoint\ElementorTemplateApply`, `Endpoint\JobCreate`,
  `Endpoint\JobStatus`.
- All four wire into the existing `rest_api_init` hook in
  `rolepod-wp.php`.
- `wp_footer` hook + `.htaccess` installer also added in
  `rolepod-wp.php`.

### Caveats

- The widget-attr bridge runs on `wp_footer` — themes that suppress
  `wp_footer()` (some FSE patterns) won't rehydrate. The existing
  `block_theme_body_open_risk` connect warning already flags this.
- Job logs live under `wp-content/uploads/.rolepod-jobs/<job_id>.{out,err}`.
  Not auto-rotated yet — caller is responsible for cleanup once the
  job's transient TTL expires (1h).
- `/job/status` infers exit code from `posix_kill` + stderr presence; a
  precise exit code requires the spawned wp-cli to write its status to
  a sidecar file (a future refinement — not blocking for the current
  use cases).

## [2.11.0] — 2026-05-28 — Atomic batch write + fs primitives + Elementor introspection

Pairs with `@rolepod/wplab` 1.15.0. Closes companion-coupled gaps from
the WalnutZtudio capability-gap audit (`brief/wplab-capability-gaps.md`
in the MCP repo).

### Added

- **`POST /wp-json/wplab/v1/fs-write-batch`** — atomic multi-file write
  (max 100 entries / call). For every PHP entry runs `php -l`; for every
  bootstrap-class entry (`functions.php`, `header.php`, `footer.php`,
  `mu-plugins/*.php`, `wp-config.php`) walks the cross-file
  `require`/`include` chain — a missing target is OK if it appears as
  another entry in the same batch. Stages every write to
  `<abs>.wplab-stage-<batchId>` first, snapshots existing files to
  `.wplab-bak-<batchId>`, then commits via per-file `rename()`. Failure
  at any phase restores backups and removes stage files — the WP install
  is never left in a half-written state.
- **`POST /wp-json/wplab/v1/dir-ensure`** — idempotent `mkdir -p` for
  scoped wp-content paths. Returns `created: bool` so the caller knows
  whether the directory pre-existed.
- **`POST /wp-json/wplab/v1/fs-copy`** — file → file copy within scoped
  paths. Auto-creates the destination's parent dir; refuses to overwrite
  unless `overwrite=true`. Production-guarded.
- **`GET /wp-json/wplab/v1/fs-list`** — recursive directory listing with
  size + mtime + type. Depth-capped (default 2, max 5) and entry-capped
  (2000; `truncated: true` signals more existed). Read-only; allowed on
  production.
- **`GET /wp-json/wplab/v1/elementor/widget-schema`** — introspect
  Elementor widget controls registry. With `widget=` returns the full
  control map for one widget type; without it returns every registered
  widget type. Returns 503 `ELEMENTOR_NOT_LOADED` when Elementor is
  missing. Read-only.
- **`GET /wp-json/wplab/v1/elementor/template-export`** — exports the
  parsed `_elementor_data` of an existing page plus the deduplicated
  list of widget types used. Lets AI agents learn the structure of
  human-built Elementor pages instead of reverse-engineering JSON.

### Internal

- New endpoint classes: `Endpoint\FsWriteBatch`, `Endpoint\DirEnsure`,
  `Endpoint\FsCopy`, `Endpoint\FsList`, `Endpoint\ElementorIntrospect`.
- All five wire into the existing `rest_api_init` hook in
  `rolepod-wp.php`. No schema changes.
- Re-uses `Security\SessionToken::verify()` + `Security\ProductionGuard`
  + `Config::endpointsEnabled()` exactly like the existing endpoints —
  no new permission surface to audit.

### Caveats

- `fs-write-batch` `php -l` step is skipped when the host disables
  `exec()` — caller can opt out explicitly via `skip_php_lint: true`.
- `fs-list` `entries` are bounded to 2000 per call to keep response
  bodies under HTTP gateway limits; tighten `depth` for narrower scans.
- Elementor widget control schemas can be huge (Counter widget = 179
  controls). When asking for one widget you may want to filter
  client-side to a known subset.

## [2.10.4] — 2026-05-27 — Also surface landing link on Setup wizard Step 0

v2.10.3 updated `Shell::footer()` but the Setup wizard Step 0 renders
its own two-variant footer inline (because path total flips between
4 and 5 based on radio selection). That inline render still showed
the old "Read the docs" text.

Fix: inline render now matches `Shell::footer()` output —
`Step 1 of N · What can AI do here? · Docs & source`.

## [2.10.3] — 2026-05-27 — Surface the public landing page (plain-English capability breakdown)

Footer hint on every Rolepod admin page now links the public landing
page at https://nuttaruj.github.io/rolepod-wplab/ — a 3-tier
plain-English breakdown of what AI can do at each setup level
(MCP-only / + Plugin / + Custom PHP). Existing GitHub link kept as
"Docs & source" for developers.

README in both repos (rolepod-wplab MCP + rolepod-wp plugin) also
links the landing page at the top.

```
Step N of M · What can AI do here? · Docs & source
```

## [2.10.2] — 2026-05-27 — Categories use their own action hook (Abilities API)

v2.10.1 still didn't register any abilities. Root cause #2: WP 7.0
splits the registry into two separate init actions —

- `wp_abilities_api_categories_init` — for `wp_register_ability_category()`
- `wp_abilities_api_init` — for `wp_register_ability()`

Categories must register on the FIRST hook because the SECOND hook
validates that every ability's `category` arg points at an already-registered
slug. Bridge::registerAll() in v2.10.1 tried to call
`wp_register_ability_category()` from inside the abilities init hook,
which `_doing_it_wrong` notices reject — the category never registered,
all four abilities then failed validation against the missing slug.

Fix: Bridge::init() now wires both hooks:

```php
add_action('wp_abilities_api_categories_init', [self::class, 'registerCategory']);
add_action('wp_abilities_api_init',            [self::class, 'registerAll']);
```

`registerCategory()` runs first via WP's natural action order and
declares `rolepod` so the four ability registers find it.

## [2.10.1] — 2026-05-27 — Register ability category before use (Abilities API requirement)

v2.10.0 shipped 4 abilities but only 2 (`health-check`, `recovery-status`)
actually registered at runtime. Browser/REST audit showed
`/wp-json/wp-abilities/v1/abilities` listed only those two; the other
two silently no-oped during init.

### Root cause

`WP_Abilities_Registry::register()` validates that the `category`
arg points to an already-registered category, and on miss calls
`_doing_it_wrong()` + returns `null` — silent at runtime, only
visible with `WP_DEBUG_LOG`. WP 7.0 core ships these built-in
categories: `site`, `user`, `woocommerce-rest`, `yoast-seo`.

`HealthCheckAbility` (category `site`) and `RecoveryStatusAbility`
(category `site`) hit a built-in slug → registered fine.

`ListChangesAbility` (category `content`) and `PanicRevertAbility`
(category `content`) hit an unregistered slug → silent failure.

### Fix

`Bridge::registerAll()` now calls `wp_register_ability_category()`
to declare a namespaced `rolepod` category before registering any
abilities, and all four abilities now use `category: 'rolepod'`.
Cleaner — one Rolepod-owned grouping in any UI that surfaces
categories, no reliance on built-in slugs whose meaning could shift.

## [2.10.0] — 2026-05-27 — WordPress 7.0 Abilities API bridge (initial curated batch)

### Why

WordPress 7.0 "Armstrong" (released 2026-05-20) ships a native AI
stack in core: an Abilities API (server-side capability registry),
a WP AI Client (provider-agnostic Anthropic/OpenAI/Gemini interface),
and a Connectors hub. This means an admin using WP 7.0's built-in AI
assistant can discover and invoke any plugin's registered abilities
directly — no external MCP CLI required.

Rolepod already exposes ~89 tools via the rolepod-wplab MCP server.
This release bridges a curated subset into the Abilities registry
so in-admin AI consumers see them alongside any other plugin's
abilities. The legacy `/wp-json/wplab/v1/*` REST surface that the
external MCP CLI uses is unchanged — both consumers coexist.

### Added — `src/Abilities/`

New namespace, one file per ability for clean review:

- **`Bridge.php`** — bootstrap. Detects `wp_register_ability()` at
  runtime; no-ops cleanly on WP < 7.0. Registers everything from
  the `wp_abilities_api_init` action hook.
- **`HealthCheckAbility.php`** → `rolepod/health-check`
  Returns plugin + WP + PHP version, guardian status, execute-php
  state, safe-mode flag. Pure read.
- **`ListChangesAbility.php`** → `rolepod/list-changes`
  Returns recent Change Ledger rows with optional category +
  limit filter. Pure read.
- **`PanicRevertAbility.php`** → `rolepod/panic-revert`
  Disables every reversible change in the last N minutes (1-1440).
  Requires explicit `confirm: true` flag — soft gate against
  speculative AI invocation.
- **`RecoveryStatusAbility.php`** → `rolepod/recovery-status`
  Returns guardian install status + recent PHP fatals + safe-mode.

All abilities:
- Use `category: 'site'` or `category: 'content'`
- Gate on `current_user_can('manage_options')` via
  `Bridge::adminPermission()`
- Have `show_in_rest: true` so they appear under
  `/wp-json/wp-abilities/v1/...`
- Mirror existing Rolepod domain code (no new behavior introduced
  via the Abilities surface)

### Added — Handshake capability flag

`/wp-json/wplab/v1/handshake` response now includes:

```json
{
  "capabilities": ["...", "abilities_api"],
  "abilities_api": {
    "available": true,
    "registered": [
      "rolepod/health-check",
      "rolepod/list-changes",
      "rolepod/panic-revert",
      "rolepod/recovery-status"
    ]
  }
}
```

Existing fields unchanged. MCP client v1.12+ can now route
ability-invokable calls through the native Abilities REST endpoint
when the server reports the flag, or continue using the legacy
endpoints when not.

### Not in this batch (planned for follow-ups)

- `rolepod/theme-snapshot` — needs ThemeSnapshot endpoint refactor
  to extract a callable domain method from the REST handler
- `rolepod/execute-php` — too dangerous to register without
  per-ability opt-in UI; skipped intentionally
- Pair / connect tools — only useful from outside WP; no fit
- Most write/scaffold tools — schemas need design work to fit
  Abilities' JSON Schema validation cleanly

### Back-compat

- Pure additive. Zero changes to existing `/wplab/v1/*` endpoints.
- WP < 7.0: Bridge::init() returns early, no abilities registered,
  handshake reports `abilities_api.available: false`.
- No new option keys, no DB writes, no new hooks beyond
  `wp_abilities_api_init` (the WP-core hook).

## [2.9.0] — 2026-05-27 — GitHub-based auto-updater (no wp.org listing needed)

### Why

Plugin is hosted on GitHub, not wordpress.org. Without an update
mechanism each user has to manually re-download the zip + reinstall
every release. With many users that doesn't scale. WordPress core's
plugin-update transient filter is the well-known fix: point WP at
GitHub releases instead of wp.org and the standard update notice +
one-click upgrade button just work.

### Added — `src/Updater.php`

Two filter hooks on init:

- `pre_set_site_transient_update_plugins` — when WP refreshes its
  plugin update list (default every 12h via cron), the filter calls
  GitHub's `releases/latest` API for `nuttaruj/rolepod-wp`. If the
  tag is newer than `ROLEPOD_WP_VERSION` it injects an update record
  pointing at the stable
  `releases/latest/download/rolepod-wp.zip` asset (produced by
  scripts/build-zip.sh on every tag push).

- `plugins_api` — handles the "View details" popup that opens when
  the admin clicks the version number in the update notice. Returns a
  basic info card pointing at the GitHub README and CHANGELOG.md.

GitHub API responses are cached in a 6h transient
(`rolepod_wp_github_release`) so admin page loads don't hammer the
API. Rate limit on unauthenticated calls is 60 req/hour per IP —
this plugin's polling footprint is well under that.

`upgrader_process_complete` action clears the cache right after a
successful self-upgrade so the next pageview doesn't show a stale
"update available" notice for the version that was just installed.

### How users get future updates

1. We tag + push `vX.Y.Z` from the rolepod-wp repo.
2. CI builds the zip + uploads it to the GitHub release.
3. Within 12h every install with our plugin active sees the standard
   WP update notice. Click "Update now" → WP downloads our zip →
   extracts to `wp-content/plugins/rolepod-wp/` → reactivates.

No wp.org listing, no separate update server, no admin action
required from existing users beyond the click.

### Back-compat

- Pure additive: no existing API / option / hook surface changes.
- WP cron schedules unchanged — uses the standard
  `wp_update_plugins` polling.
- If GitHub is unreachable the filter silently no-ops — WP shows the
  current version with no update notice (same UX as a wp.org plugin
  during a network blip).

## [2.8.9] — 2026-05-27 — Drop "Enable companion REST endpoints" master toggle (plugin activation IS consent)

### Why

User report: fresh installs ran the onboarding wizard, mint a pair
token, paste the prompt to an AI CLI — and the AI got `HTTP 403
rolepod_wp_disabled` because the master toggle defaulted to OFF.
Most users never opened Settings during onboarding so the failure was
silent and confusing.

The toggle was also redundant with plugin activation:

- WordPress already gives admins a single binary control over the
  plugin: **Plugins → Deactivate**. That IS the kill switch.
- The execute-php sub-toggle is the real safety surface — it gates
  arbitrary PHP. Read endpoints (handshake, introspect, fs-read,
  wp-cli reads) and scoped writes (fs-write under
  `wp-content/uploads/wplab-{tmp,backups}/`) are low-risk by
  comparison and behind session_token + manage_options.

### Changed

- **`Config::endpointsEnabled()` always returns `true`.** The stored
  `endpoints_enabled` value is ignored. Existing endpoint
  permission-callback guards (`if (!Config::endpointsEnabled())…`)
  stay in place as defensive code but never block.
- **Settings UI**: master toggle row removed. Only execute-php remains.
- **`handleSave()`**: no longer writes `endpoints_enabled`.
- **Shell header status dot**: now reflects execute-php state
  (`execute-php ON` / `execute-php OFF`) — the only remaining
  user-controlled gate and the one that actually matters for "is this
  a live customer site or dev/staging".
- **Fresh-install default**: `execute_php_enabled` flipped to `false`.
  Previously it was `true` (gated behind the master toggle being ON).
  Now that master is gone, OFF-by-default for arbitrary-PHP is the
  safer posture. Admin must explicitly turn it on for dev/staging.

### Back-compat

- `endpoints_enabled` key is preserved in the option array for any
  external reader; value is ignored at runtime.
- Existing installs that had `execute_php_enabled = true` keep that
  setting (add_option no-ops when the option already exists).
- Power user who needs a kill switch should deactivate the plugin —
  same effect as the old master toggle, plus more visible in the
  WordPress admin.
- `ProductionGuard` + `production_hosts` config key untouched (also
  preserved for back-compat).

### Migration

None required. The change is invisible to existing installs except:
- Settings page now shows one row instead of two
- Header status dot label changed
- New installs no longer need to flip a switch to start

## [2.8.8] — 2026-05-27 — Drop "Production hostnames" UI, strengthen execute-php warning

### Why

User audit found the "Production hostnames" field had two UX problems:

1. **Silent failure on typo.** A bad pattern (missing dot, trailing
   slash, scheme prefix) doesn't match → guard returns `false` → site
   is treated as non-production → execute-php works. User thinks they
   are protected; they aren't.
2. **Redundant.** The single execute-php toggle is already the
   on/off for arbitrary PHP. Adding a second whitelist on top of it
   creates a false sense of defense-in-depth without actually
   delivering one (because of #1).

A clearer mental model: **one toggle owns the decision**, with copy
that makes the production risk explicit. The user is responsible for
not flipping it on a live customer site — same model as plenty of
other admin-only WP plugins that ship a "developer mode" toggle.

### Changed

- Removed the **Production hostnames** row from Settings.
- Execute-php toggle copy rewritten:
  > Lets the MCP run arbitrary PHP on this site. Even when ON, every
  > call still needs a valid session token and an AST-screen-clean
  > payload — but if you turn this on for a live customer site, an AI
  > mistake can take the site down. **Recommended: keep OFF on
  > production. Turn ON only on dev/staging.**
- Badge upgraded: `Dangerous` → `Dangerous for production`.
- `handleSave()` no longer touches `production_hosts`.

### Back-compat

- The `production_hosts` key in `rolepod_wp_config` is **preserved**
  (Config::update uses array_merge, not full replace). Existing
  installs that set patterns via wp-cli or older UI keep working.
- `ProductionGuard::isProduction()` still functions; it just stops
  being settable from the admin UI.
- `is_production` flag in `/handshake` and `/pair` responses unchanged.
- Power users can still set the option directly:
  `wp option patch update rolepod_wp_config production_hosts '["mysite.com"]' --format=json`

## [2.8.7] — 2026-05-27 — Audit log timeline scrolls inside a fixed-height container

Settings page rendered all 50 audit entries inline → very tall page,
buried the right sidebar (guardian + plugin info) below the fold.

Fix: cap `.rp-timeline` at `max-height: 560px` with `overflow-y: auto`
so the user sees ~10-12 entries by default and scrolls for the rest.
Added a subtle gradient mask at the bottom edge so it's visually clear
more content is below.

Data unchanged — still pulls last 50 via `Log::tail(50)`.

## [2.8.6] — 2026-05-27 — Footer hint "Step 1 of N" also updates live with path selection (Step 0)

v2.8.5 made the stepper labels react live to the path radio but the
bottom footer hint ("Step 1 of 4 · Need help?") still came from the
URL `?path=` query param, so on Step 0 the footer could read "Step 1
of 4" while the stepper showed the 5-step Manual variant.

Fix: same pattern as the stepper — render both footer variants on
Step 0, show the matching one via CSS `:has(input:checked)`. On
Steps 1+ the URL path is committed and a single footer renders.

## [2.8.5] — 2026-05-27 — Stepper labels update live with path selection (Step 0)

User reported: on Choose path (Step 0) the stepper labels stayed on
the Quick variant even after clicking the Manual radio. The stepper
only updated after the form was submitted via Continue. Mockup
expected the labels to flip live as the radio toggles.

Fix: render BOTH steppers on Step 0 (quick 4-step + manual 5-step)
and use a CSS `:has(input[name="path"]:checked)` selector to show
only the one matching the currently-checked radio. No JS, no
roundtrip — the stepper updates the moment the radio state changes.

On Steps 1+ the path is already locked, so only the matching stepper
renders (no waste).

```css
.rp-wizard:has(input[name="path"][value="quick"]:checked)
  [data-rp-stepper-for="manual"] { display: none; }
.rp-wizard:has(input[name="path"][value="manual"]:checked)
  [data-rp-stepper-for="quick"] { display: none; }
```

Extracted the stepper rendering into `SetupWizard::renderStepper()`
since it now runs twice on Step 0.

## [2.8.4] — 2026-05-27 — Copy button falls back to execCommand on clipboard rejection

End-to-end browser audit found that copy buttons (token, prompt,
codeblocks) silently no-oped when `navigator.clipboard.writeText()`
rejected. Causes: page not focused, no HTTPS, permissions denied,
programmatic dispatch without user-gesture token. The `.then()` chain
that flips the label to "Copied" never ran on rejection so the user
got no visual feedback.

Fix: catch the rejection and fall back to the legacy `execCommand`
textarea trick. Visual feedback always runs. Modern browsers still get
the modern path on success.

```js
return navigator.clipboard.writeText(text).catch(function () {
  return execCommandCopy(text);
});
```

## [2.8.3] — 2026-05-27 — Fix: path cards stayed visually selected together

v2.8.2's path-card selector used both `:has(input:checked)` (live state)
AND a server-rendered `is-checked` class fallback. When the user clicked
the other card the radio toggled correctly but the stale `is-checked`
class never got cleared by JS, so both cards rendered with the accent
border + ring at the same time.

Fix: drop the `is-checked` class entirely and rely on the native
`:has(input:checked)` selector. CSS-only, no JS sync needed.

`:has()` browser support is ~95% (Chrome 105+, Safari 15.4+, Firefox
121+, all current evergreen browsers). For older browsers the cards
still render but the selected state appears only via the native radio
button — acceptable degradation.

## [2.8.2] — 2026-05-27 — Setup page rebuilt as multi-step wizard matching mockup

v2.8.0 collapsed admin to one menu but the Setup page kept both Quick
Start and Manual on the same scroll. Mockup design called for a 4-step
(Quick) / 5-step (Manual) onboarding flow with a stepper across the
top, and the sub-nav floating in the top-right of the page header
instead of below it. v2.8.2 ports the mockup precisely.

### Changed — Shell::open() signature

```php
Shell::open(string $activeSlug, string $pageLabel, ?string $subtitle = null)
```

The header now reads `Rolepod for WordPress — <pageLabel>` and shows
a one-line subtitle below the version. The sub-nav (Setup / Changes /
Settings) renders top-right of the header instead of below it. On
narrow viewports (≤720px) the header collapses to a column with the
sub-nav left-aligned underneath.

Also added `Shell::footer()` — renders the "Step N of M · Read the
docs" hint line at the bottom of the page (used on every Rolepod
page, not just Setup).

### Rewritten — SetupWizard as multi-step

Quick path:
  `0 Choose path` → `1 Generate token` → `2 Connect AI CLI` → `3 Verify`

Manual path:
  `0 Choose path` → `1 App password` → `2 Install MCP` → `3 Wire CLI` → `4 Verify`

State encoding:
- Step + path = URL query (`?step=N&path=quick|manual`)
- Minted pair token = WP transient keyed on user_id with the same
  60-min TTL as the underlying `PairToken`; never appears in the URL
  so it can't leak via browser history or referrer headers.

Continue / Back navigation is plain `<a>` links between steps; only
Step 0 (Choose path) and Step 1 (Generate token) use POST forms (path
selection + token mint).

The Verify step polls `WP_Application_Passwords::get_user_application_passwords()`
on render to detect a `wplab-pair-…` (or `rolepod-wp-pair-…`) entry —
no extra REST calls, no setInterval, no JS polling. "Re-test" button
re-renders the step.

### Added CSS

- `.rp-wizard` / `.rp-wizard-stepper` / `.rp-wizard-body` / `.rp-wizard-nav`
- `.rp-path-card` with `:has(input:checked)` selected state
- `.rp-info-tile-grid` / `.rp-info-tile`
- `.rp-verify` + `.rp-verify-spinner` (pure CSS animation)
- `.rp-footer-hint`

Still zero JS framework, no font CDN, no build step. Asset size +2KB
CSS / +0KB JS.

## [2.8.1] — 2026-05-27 — Shorter Quick Start prompt (link to README install docs)

The Setup page's "Generate pair token" output used to bake per-CLI install
snippets (Claude Code / Codex / Cursor / Gemini / npm fallback) directly
into the paste body — ~50 lines total. Two problems:

1. Long prompts get truncated visually in chat UIs and lose impact.
2. Install instructions drifted between three places (this prompt, the
   README, the wplab marketplace listing). Keeping them in sync was
   manual.

v2.8.1 collapses the prompt to ~14 lines and points the AI at the
README's `## Install` section for CLI-specific install steps. Single
prompt now works for every CLI. README stays the single source of
truth for install instructions.

### Changed

- `Admin\SetupWizard::buildPrompt()` — signature `(siteurl, host,
  pairToken)` → `(siteurl, pairToken)` (host parameter unused after
  the trim).
- Output goes from ~50 lines to ~14, references
  `https://github.com/nuttaruj/rolepod-wplab#install` for installer
  details.

## [2.8.0] — 2026-05-27 — Single top-level "Rolepod WP" admin menu (UX consolidation)

### Restructured

Previously the plugin scattered three admin pages across two parent menus:

- **Settings → Rolepod for WordPress** (`options-general.php?page=rolepod-wp`)
- **Tools → Rolepod WP Setup** (`tools.php?page=rolepod-wp-setup`)
- **Tools → Rolepod WP Changes** (`tools.php?page=rolepod-wp-changes`)

Users reported difficulty finding the three pages. v2.8 consolidates
them into one top-level menu:

```
Rolepod WP              (admin.php?page=rolepod-wp)
├── Setup               (admin.php?page=rolepod-wp)
├── Change Ledger       (admin.php?page=rolepod-wp-changes)
└── Settings            (admin.php?page=rolepod-wp-settings)
```

The old slugs and parents are intercepted in `admin_init` and 302-redirected
to the new routes, so bookmarks and the MCP-side "open this page" deeplinks
keep working without code changes on the client.

### Added — design language

- `assets/admin.css` (~10 KB) — CSS-only design tokens (palette, radii,
  shadows, density) and components (Card, Toggle, Badge, Button, Chip,
  Stat tile, Audit timeline, Ledger table, Panic panel, Stepper).
- `assets/admin.js` (~2 KB) — vanilla-JS progressive enhancements only:
  copy-to-clipboard buttons, bulk-select master checkbox, confirm-before-submit
  on danger actions, debounced client-side filter on Change Ledger search.

No React, no Babel-in-browser, no jQuery, no font CDN. System font stack.
All inline icons are raw SVG — zero extra HTTP.

### Added — `src/Admin/Menu.php` + `src/Admin/Shell.php`

`Menu` owns top-level registration + asset enqueue + legacy redirects.
`Shell::open()` / `Shell::close()` render a shared header (logo + version
+ endpoint status pill + sub-nav tabs) so every page has the same chrome.

### Refactored

`Admin\SettingsPage`, `Admin\SetupWizard`, `Admin\ChangeLedgerPage` keep
their handler logic and nonces unchanged; only the markup was rewritten
to use the new design tokens and call `Shell::open/close`.

### Performance posture

- **Zero impact on non-Rolepod admin pages.** `admin_enqueue_scripts`
  callback bails out in O(1) on any hook string that doesn't contain
  `rolepod-wp`. WordPress Posts/Pages/Media/Users/Comments/Plugins
  screens pay no byte cost.
- **No build step.** Plugin stays buildless — assets ship as plain CSS
  and vanilla JS so the source tree is exactly what loads.
- **No external network fetch.** No Google Fonts, no CDN scripts.
- **Page render speed unchanged vs v2.7.** PHP renders HTML in the same
  number of DB queries; the CSS file adds one HTTP request on
  first-paint of a Rolepod page (cached thereafter by the version-pinned
  enqueue URL).

### Backward compat

- All REST endpoints under `/wp-json/wplab/v1/*` unchanged.
- All option keys (`rolepod_wp_config`, `rolepod_wp_safe_mode`, etc.) unchanged.
- All nonce action names unchanged.
- Legacy admin URLs `options-general.php?page=rolepod-wp` and
  `tools.php?page=rolepod-wp-{setup,changes}` 302-redirect to the new
  routes.

## [2.7.3] — 2026-05-27 — `/fs-write` response includes `absolute_path`

MCP client (`@rolepod/wplab` v1.11.10) discovered during R6-7 page-builder
swap test: `CompanionBridge.coerceFsWriteResponse` reads `absolute_path`
from the response body but `/fs-write` only returned `{path, bytes_written,
backup_path}`. Default fallback was `""`, which propagated into
`wp eval file_get_contents("")` → `PHP Fatal error: Uncaught ValueError:
Path must not be empty`.

### Fixed — `/fs-write` returns `absolute_path`

```php
return new WP_REST_Response([
    'ok' => true,
    'path' => $relPath,
    'absolute_path' => $absPath,  // NEW
    'bytes_written' => filesize($absPath),
    'backup_path' => $backupPath,
], 200);
```

`$absPath` already computed inside the handler (`ABSPATH . $relPath`) —
the response just never exposed it.

The MCP client v1.11.10 ships a fallback (`absolutePath || tmpRel`) that
relies on `wp-cli`'s `getcwd() === ABSPATH`, so older callers still work.
This patch closes the contract gap explicitly.

## [2.6.0] — 2026-05-27 — Recovery guardian mu-plugin

WordPress core's WSOD-protection ships with `set_recovery_mode_email`, an
out-of-band recovery channel. v2.6 layers an analogous mechanism for AI-issued
writes: a tiny self-contained mu-plugin that loads BEFORE regular plugins so
it survives parse errors / fatals in the main rolepod-wp plugin (or in any
theme / 3rd-party plugin that loads after it).

### Added — `guardian/rolepod-wp-guardian.php`

Self-contained mu-plugin (no autoload dep on the main plugin's classes).
Auto-installed into `wp-content/mu-plugins/` on main-plugin activate; removed
on deactivate (Option A tight coupling — "deactivate = off completely").

Registers:

- `register_shutdown_function` — catches FATAL/PARSE/COMPILE errors and
  records `{file, line, message, type, ts, request_uri}` into the
  `rolepod_wp_recovery_recent_fatals` transient (last 10, 24h TTL).
- `GET /wp-json/wplab-recovery/v1/status` — main_alive (via
  `defined('ROLEPOD_WP_VERSION')`), recent_fatals, last_fatal, safe_mode,
  wp_version, php_version, siteurl.
- `POST /wp-json/wplab-recovery/v1/disable-plugin` — rename plugin main file
  to `<file>.disabled`, also calls `deactivate_plugins()` for active_plugins
  cleanup.
- `POST /wp-json/wplab-recovery/v1/disable-file` — scope-checked rename of
  any file under wp-content/{plugins,themes,uploads,mu-plugins} or
  wp-config.php.
- `POST /wp-json/wplab-recovery/v1/restore-file` — reverse.
- `POST /wp-json/wplab-recovery/v1/restore-snapshot` — untar a previously
  captured theme snapshot via PharData.
- `GET /wp-json/wplab-recovery/v1/list-changes` — direct DB read of the
  ledger table (bypasses main companion).
- `POST /wp-json/wplab-recovery/v1/safe-mode` — toggle
  `rolepod_wp_safe_mode` option flag.
- `POST /wp-json/wplab-recovery/v1/clear-fatals` — clear recent-fatals
  transient post-review.

Auth = `current_user_can('manage_options')` via WP-native Application
Password (no session token — main plugin's `/handshake` may be unreachable).

### Added — `Rolepod\Wp\Guardian` lifecycle controller (`src/Guardian.php`)

- `Guardian::install()` — copy `guardian/rolepod-wp-guardian.php` into
  `WPMU_PLUGIN_DIR/` (auto-creates dir if missing, chmod 0644).
- `Guardian::remove()` — silent unlink.
- `Guardian::isInstalled()` / `destinationPath()` / `sourcePath()`.

Wired into main plugin:

- `register_activation_hook` → `Guardian::install()` (alongside ledger DB).
- `register_deactivation_hook` → `Guardian::remove()`.
- `plugins_loaded` priority 5 → re-install if missing (covers WP plugin
  UPDATE which replaces files without reactivating; users on 2.5 → 2.6 get
  the guardian on next request without manual reactivation).
- `uninstall.php` → `Guardian::remove()` + clear recovery transients +
  delete `rolepod_wp_safe_mode` option.

### Added — Settings page section "Recovery guardian (mu-plugin)"

- Shows install status + absolute path.
- [Install] / [Reinstall] / [Remove] buttons (nonce-protected).
- Safe-mode banner when `rolepod_wp_safe_mode` is ON; [Clear safe mode]
  button.
- Recent-fatals table (Time, Type, File:Line, Message, URI) + [Clear
  fatal log] button. Only shows when guardian transient has entries.

### Why mu-plugin (not main plugin) for recovery

WP loads `wp-content/mu-plugins/*.php` BEFORE `wp-content/plugins/*`. If the
main plugin file has a parse error, PHP throws at parse time and the file
never runs — its `register_shutdown_function` would never fire. The
mu-plugin parses + registers its shutdown handler BEFORE PHP attempts the
main plugin parse, so the handler is in memory by the time the fatal
happens. WP's own WSOD-protection (5.2+ Recovery Mode) uses the same
trick — we extend it with a REST recovery channel instead of email-based
recovery links.

## [2.7.2] — 2026-05-27 — Guardian restore_snapshot slug parser fixed for ts-with-hyphen

Round 6 stress test exposed: guardian's `/restore-snapshot` extracted to wrong dir `<slug>-<date>/` when snapshot filename was `<slug>-YYYYMMDD-HHMMSS.tar.gz`. The naive `explode('-') + array_pop()` only stripped the `HHMMSS` half, leaving the `YYYYMMDD` as part of the slug.

Fix: replace explode/pop with the same regex used by the main companion ThemeSnapshot::restore — `^([a-zA-Z0-9_\-]+)-\d{8}-\d{6}\.tar\.gz$`. Greedy slug capture before `-YYYYMMDD-HHMMSS`. Returns `SNAPSHOT_NAME_MALFORMED` if filename doesn't match the pattern.

## [2.7.1] — 2026-05-27 — `wp-content/uploads/wplab-tmp/` auto-mkdir + new `/db-query` endpoint

Closes 2 of 3 sub-gaps surfaced during MCP v1.10.1 retest.

### Fixed — `wp-cli` endpoint auto-creates `wp-content/uploads/wplab-tmp/`

`backup_create` failed with `mysqldump: Can't create/write to file 'wp-content/uploads/wplab-tmp/backup-X.sql' (OS errno 2 - No such file or directory)` because the scratch dir didn't exist on first call. `WpCli::handle()` now calls `wp_mkdir_p(WP_CONTENT_DIR . '/uploads/wplab-tmp')` before exec. Idempotent + cheap.

### Added — `POST /wp-json/wplab/v1/db-query`

Read-only DB query endpoint that bypasses two wp-cli `db query` hazards:

1. **No `{prefix}` substitution in wp-cli** — `wp db query` passes SQL literal to mysql. MCP `diagnose` tool wrote `FROM {prefix}postmeta` expecting substitution, got "Unknown table" error. The new endpoint replaces `{prefix}` with `$wpdb->prefix` server-side.
2. **Shell escaping of SQL with quotes** — SQL containing single/double quotes is brittle through `exec()`. The new endpoint accepts SQL via JSON body, binds params via `$wpdb->prepare`.

Safety:
- Refuses any statement that isn't a pure `SELECT` / `SHOW` / `DESCRIBE` / `EXPLAIN` / `WITH` (anchor check on comment-stripped trimmed query).
- Requires session token + `manage_options`.
- Audit-logged with sql_preview + row count.

Body shape:
```json
{ "session_token": "...", "sql": "SELECT * FROM {prefix}options LIMIT 10", "params": [] }
```

Response:
```json
{ "ok": true, "rows": [{...}, ...], "count": N, "audit_id": "..." }
```

### Pairs with

`@rolepod/wplab` v1.11.0 — `Bridge.dbQuery()` + diagnose tool routes
through the new endpoint when target is RestTarget + companion.

## [2.7.0] — 2026-05-27 — `/option-set` + `/option-get` endpoints + server-side ledger capture in `/execute-php`

### Added — `POST /wp-json/wplab/v1/option-set` + `POST /option-get`

Direct wp_options access via `update_option()` / `get_option()`. Bypasses
the WP REST `/wp/v2/settings` allowlist limitation — that endpoint exposes
only ~10 fields under different names than raw wp_options
(title vs blogname, description vs blogdescription, timezone vs
timezone_string), and silently ignores unknown field names.

Safety: refuses to write WP-managed keys (`db_version`, `secret`,
`recovery_keys`, `auth_*`, `nonce_*`, `secure_auth_*`, `rewrite_rules`).
Customizable via the `rolepod_wp_option_set_blocklist` filter.

`/option-set` returns `{ ok, name, changed, previous, current, audit_id }`
so the MCP can record a ledger row with authoritative before/after values
without an extra read round-trip.

### Added — server-side ledger capture in `/execute-php` (Gap #9)

After a successful eval, the endpoint scans the payload for common write
fingerprints via regex:

  update_option / wp_insert_post / wp_update_post / wp_delete_post /
  update_post_meta / activate_plugins / deactivate_plugins / switch_theme /
  wp_insert_user / wp_create_user / wp_create_nav_menu /
  wp_update_nav_menu_item / set_theme_mod

For every match, records a Change Ledger row tagged `source_tool=execute_php`
with `reversible=false` (manual revert required for arbitrary PHP). The
response includes a `ledger` summary so MCP / AI can correlate the
execute-php call with the rows it created.

Best-effort: won't catch dynamic dispatch (call_user_func, variable
function names). Most AI-generated payloads use direct calls so coverage is
high in practice. Reads or pure introspection don't pollute the ledger.

This is the main answer to the e2e finding that 30+ writes via execute-php
left zero ledger entries.

### Notes

REST namespace `wplab/v1` unchanged. MIN_COMPANION_VERSION can stay at
2.1.0 — the new endpoints are additive; older MCP clients ignore them.

## [2.6.10] — 2026-05-27 — Branding cleanup (remove third-party references)

Doc-only patch. Removed references to the third-party WordPress AI plugin
that originally inspired design exploration. README + CHANGELOG entries
rephrased to describe features independently rather than by parity. Version
bump propagates the guardian self-upgrade flow so the new wording lands on
demos / installs via the standard `plugins_loaded:5` hook.

No behavior change. No schema change. All 8 guardian REST endpoints + all
main companion endpoints unchanged.

## [2.6.9] — 2026-05-27 — Improved /status semantics + dispatch_path field

Fix consequence of v2.6.8 design: `main_alive` in `/status` was being
computed from `defined('ROLEPOD_WP_VERSION')`. That was true on the
normal REST path (rest_api_init fires after main plugin loaded) but
FALSE on the early-dispatch path (we shortcircuit at muplugins_loaded,
before main loads). The misleading signal told AI consumers "main is
down" when it was actually fine.

### Changed — `main_alive` now infers from active_plugins + filesystem

`main_alive` is now true when ALL of:
- `rolepod-wp/rolepod-wp.php` is in the `active_plugins` option.
- The main plugin file exists on disk.
- `.disabled` form does NOT exist (would mean guardian or admin disabled it).

This is a "next regular request will boot main" predictor. To know if
the LAST request actually loaded main cleanly, check `recent_fatals` for
fatals in `wp-content/plugins/rolepod-wp/`.

### Added — `dispatch_path`, `main_active_in_options`, `main_file_disabled` fields

- `dispatch_path`: `"rest_api_init"` (WP fully booted, normal REST path)
  or `"early_dispatch"` (muplugins_loaded shortcircuit). Lets AI know
  whether this response came through the recovery path.
- `main_active_in_options`: bool — straight read from active_plugins.
- `main_file_disabled`: bool — `.disabled` form exists.
- `main_version`: now extracted from main plugin's `Version:` header
  via regex (cheap, no autoload, safe even if main fatals at load).

## [2.6.8] — 2026-05-27 — Manual WP_REST_Server (skip rest_api_init / create_initial_rest_routes)

v2.6.7 ran cleanly past auth, but next layer hit:

```
PHP Fatal error: Uncaught Error: Class "WP_Site_Health" not found in
wp-includes/rest-api.php:396
```

Root cause: v2.6.7's early-dispatch called `rest_get_server()` which
fires `rest_api_init` action → WP-core's `create_initial_rest_routes()`
→ references `WP_Site_Health` class (loaded much later in WP boot via
admin includes). At muplugins_loaded that class isn't around yet.

### Fix — manual server, manual route registration

Refactored route registration into
`rolepod_guardian_register_routes($server = null)`:
- `$server === null` (normal path): uses `register_rest_route()` which
  routes through the global REST server during rest_api_init.
- `$server` passed (early dispatch): registers directly on the passed
  `WP_REST_Server` via `$server->register_route()` — bypasses
  `register_rest_route()`'s global state AND avoids firing
  `rest_api_init`.

Early dispatch now does:
```php
require_once ABSPATH . WPINC . '/rest-api.php';
require_once ABSPATH . WPINC . '/rest-api/class-wp-rest-server.php';
require_once ABSPATH . WPINC . '/rest-api/class-wp-rest-request.php';
require_once ABSPATH . WPINC . '/rest-api/class-wp-rest-response.php';
$server = new WP_REST_Server();
rolepod_guardian_register_routes($server);  // only OUR routes
$response = $server->dispatch($request);
```

The early-dispatch server only knows about guardian routes — no WP-core
REST controllers, no plugin REST endpoints. That's intentional: in
recovery mode we want isolation, not "everything WP would normally serve".

### Architectural lesson

Firing `rest_api_init` manually pulls in the entire WP-core REST init
sequence which depends on classes loaded throughout WP boot. For a true
out-of-band escape hatch, instantiate a minimal `WP_REST_Server` and
register only the routes you control. Don't try to piggy-back on
WP-core's REST init — it's a tar pit at early load phases.

### Verified end-to-end on demo

After installing v2.6.8 over a broken site:
- `/wp-json/wplab-recovery/v1/status` → 200 with JSON ✅
- `/wp-json/wplab-recovery/v1/disable-file` → renamed
  `wp-content/themes/twentytwentyfive/functions.php` → `.disabled` ✅
- WP front-end + main REST recovered ✅
- All 8 guardian endpoints (status, disable-plugin, disable-file,
  restore-file, restore-snapshot, list-changes, safe-mode, clear-fatals)
  smoke-tested ✅

## [2.6.7] — 2026-05-27 — Delegate to wp_authenticate_application_password() (handles WP 7.0 hash normalization)

v2.6.5 inlined the iterate-and-check pattern from WP-core, calling
`WP_Application_Passwords::get_user_application_passwords()` then
`wp_check_password()` on each item. Demo testing in v2.6.6 added debug
logging which confirmed:
- `extract_basic_auth()` correctly received `PHP_AUTH_USER=admin` +
  24-char password from Nginx FastCGI.
- The Application Passwords WERE retrieved from DB (2 items).
- `wp_check_password()` against `$item['password']` returned FALSE for
  both, despite WP-core's own auth path accepting the same credentials.

Root cause: WP 7.0 stores Application Password hashes via the new
`$generic$` PHC format. `wp_authenticate_application_password()`
normalizes the input password (trim, NBSP → space, strip whitespace)
BEFORE hashing for compare. Our inline path skipped that step — raw
password never matched normalized hash.

### Fix — delegate to wp_authenticate_application_password()

It's a public function (not just a filter callback) and is callable
from muplugins_loaded once pluggable.php is required. Avoids
re-implementing every normalization quirk WP-core handles.

```php
if (function_exists('wp_authenticate_application_password')) {
    $result = wp_authenticate_application_password(null, $user, $pass);
    if ($result instanceof \WP_User) return $result;
}
// plain-password fallback for local dev / CI
```

Removed v2.6.6 debug logging (one-shot diagnostic).

## [2.6.6] — 2026-05-27 — Add one-shot debug logging to diagnose 401 on recovery namespace

v2.6.5 deployed without WSOD but returned `rest_not_logged_in` 401 for
every recovery call. Server probe (via `/wplab/v1/execute-php`) confirmed
Nginx populates `PHP_AUTH_USER` + `PHP_AUTH_PW` correctly at runtime —
so the 401 meant our `authenticate()` returned null for some other
reason. Added temporary FILE_APPEND log to
`wp-content/uploads/rolepod-guardian-debug.log` capturing extracted
user/pass-length + `$_SERVER` auth keys present + REQUEST_URI. Confirmed
extraction works → narrowed bug to the hash compare step (fixed in v2.6.7).

## [2.6.5] — 2026-05-27 — Fix WP_Application_Passwords API (use get_user_application_passwords + wp_check_password)

v2.6.4 fixed the pluggable.php load order. Demo retest then exposed the
next layer of the same bug: I had assumed `WP_Application_Passwords` has a
static method `validate_application_password()`. It doesn't. The hostinger
error log confirmed:

```
PHP Fatal error: Uncaught Error: Call to undefined method
WP_Application_Passwords::validate_application_password()
in mu-plugins/rolepod-wp-guardian.php:546
```

The actual WP-core API is:
- `WP_Application_Passwords::get_user_application_passwords(int $user_id): array`
  — returns array of stored items: `[{uuid, app_id, name, password
  (hashed), created, last_used, last_ip}]`.
- The validation logic lives in the `wp_authenticate_application_password`
  FUNCTION (not class method), in `wp-includes/user.php`. That function
  iterates passwords and calls `wp_check_password` on each.

We can't call `wp_authenticate_application_password` directly from
muplugins_loaded because it's hooked into the `wp_authenticate` filter
chain which isn't active until later. So we inline the iterate-and-check
pattern:

```php
$passwords = WP_Application_Passwords::get_user_application_passwords($wp_user->ID);
foreach ($passwords as $item) {
    if (wp_check_password($pass, $item['password'], $wp_user->ID)) {
        // best-effort usage recording
        @WP_Application_Passwords::record_application_password_usage($wp_user->ID, $item['uuid']);
        return $wp_user;
    }
}
```

Side benefit: `record_application_password_usage()` updates `last_used` +
`last_ip` on the password row, matching the normal auth path so the user
sees their recovery-app usage in wp-admin → Users → Application Passwords.

### Architectural lesson

When porting WP-core logic into a custom auth path, copy the FUNCTION
body, not the class method assumption. WP separates auth into
filter-hooked functions (`wp_authenticate*`) that wrap class-method
helpers (`WP_Application_Passwords::*`). Reading WP source is the only
authority — autocomplete / IDE inference of "validate_application_password
must exist" was wrong.

## [2.6.4] — 2026-05-27 — Pre-load pluggable.php BEFORE get_user_by()

Critical fix found during demo test of v2.6.3. Recovery endpoint requests
caused a fatal:

```
PHP Fatal error: Uncaught Error: Call to undefined function get_user_by()
in mu-plugins/rolepod-wp-guardian.php:524
```

Root cause: v2.6.3's `authenticate()` called `get_user_by()` BEFORE
requiring pluggable.php. The require was conditional on
`!function_exists('wp_check_password')`, which fired AFTER the
`get_user_by()` call. Since pluggable.php loads AFTER plugins in
wp-settings.php (NOT before mu-plugins), `get_user_by()` was undefined at
`muplugins_loaded`. v2.6.2 had the same bug — passed the v2.6.0 → v2.6.2
upgrade test only because the OLD v2.6.0 guardian (no early dispatch) was
still in memory in cached workers.

Fix: require `pluggable.php` at the TOP of `authenticate()`, before any
call to `get_user_by` / `wp_check_password` / `wp_set_current_user`.
Comment now explicitly enumerates which functions come from pluggable so
future contributors don't repeat the mistake.

Verified via Hostinger error log:
- Before: 100% WSOD on /wplab-recovery/v1/* with fresh workers.
- After: 200 with valid auth, 401 without.

### Architectural lesson recorded

WordPress boot order at `muplugins_loaded`:
- ✅ Loaded: `wp-load.php`, `wp-includes/load.php`, `wp-includes/functions.php`,
  `wp-includes/formatting.php`, `class-wp-rest-server.php`, REST API,
  `class-wp-application-passwords.php` (via autoload if accessed)
- ❌ NOT loaded: `pluggable.php` (and everything it provides:
  `get_user_by`, `wp_check_password`, `wp_set_current_user`,
  `is_user_logged_in`, `wp_get_current_user`, etc.)
- ❌ NOT loaded: regular plugins (loaded after mu-plugins)
- ❌ NOT loaded: theme (loaded at `setup_theme`)

For any mu-plugin code that needs pluggable functions: require
`wp-includes/pluggable.php` explicitly. Don't rely on autoload for
pluggable — there is no autoload for functions in PHP.

## [2.6.3] — 2026-05-27 — Guardian self-upgrade on plugin update

Fix: v2.6.2 install on a site already running v2.6.0 left the OLD
guardian file in place. The plugins_loaded:5 hook only re-copied when
`Guardian::isInstalled()` was false. With a file already present, that
check returned true and skipped the overwrite — so the user's site kept
running the v2.6.0 guardian even after upgrading the main plugin to
v2.6.2.

### Added — `Guardian::isInstalledAtCurrentVersion()` + `readVersion()`

Reads the `ROLEPOD_WP_GUARDIAN_VERSION` define from the first 4 KB of any
guardian file via regex. Cheap (no eval / no autoload). The
plugins_loaded:5 hook now compares installed-version to bundled-version
and overwrites when they differ. Side benefit: downgrade scenarios also
trigger a reinstall (rolls back the guardian to match plugin code).

### Verified on demo

Hostinger demo: v2.6.0 → v2.6.2 plugin upgrade left guardian at v2.6.0.
After v2.6.3, same upgrade path correctly brings guardian to v2.6.3 on
the first post-upgrade request (the plugins_loaded:5 hook fires
every request and is cheap when no copy is needed).

## [2.6.2] — 2026-05-27 — Best-practice auth hardening (pluggable load order + REDIRECT_HTTP_AUTHORIZATION)

Two correctness fixes in the early-dispatch path.

### Fixed — pluggable.php load order in `rolepod_guardian_authenticate()`

`WP_Application_Passwords::validate_application_password()` internally
calls `wp_check_password()`, which lives in `wp-includes/pluggable.php` —
that file loads AFTER plugins in `wp-settings.php`, so at
`muplugins_loaded` it's NOT available yet. v2.6.1 required it only in the
fallback branch, so the primary Application Password validation was
silently failing on some setups. Fix: require `pluggable.php` BEFORE
calling `validate_application_password()`. This also locks in WP-core's
`wp_check_password` (security plugins can't override the recovery escape
hatch — desirable for a recovery path).

### Added — `REDIRECT_HTTP_AUTHORIZATION` + `getallheaders()` fallbacks

Some Apache RewriteRule configurations strip `Authorization` from
`$_SERVER['HTTP_AUTHORIZATION']` and stash it in
`$_SERVER['REDIRECT_HTTP_AUTHORIZATION']`. Other setups only expose it via
`getallheaders()`. Auth extraction now tries, in order: `PHP_AUTH_USER` →
`HTTP_AUTHORIZATION` → `REDIRECT_HTTP_AUTHORIZATION` → `getallheaders()`
scan. Covers Apache mod_php, Apache FastCGI, Nginx FPM, LiteSpeed.

### Refactored

Split `rolepod_guardian_authenticate()` into two functions:
`extract_basic_auth()` (header parsing) + `authenticate()` (user lookup +
password validation). Easier to test + reason about.

`is_email()` check before `get_user_by('email')` — avoids unnecessary
`get_user_by('login')` then `get_user_by('email')` retry chain. Matches
WP-core's `wp_authenticate_application_password` behavior.

## [2.6.1] — 2026-05-27 — Guardian early dispatch (fixes theme-fatal recovery)

Ships the early-dispatch path that was originally deferred to v2.7. Demo
testing during v2.6.0 confirmed that REST endpoints registered via
`rest_api_init` are unreachable when the boot dies during theme load.

### Added — `muplugins_loaded:PHP_INT_MAX` short-circuit

Guardian now detects recovery URLs at the top of mu-plugin load. If the
request URI matches `/wp-json/wplab-recovery/v1/*` or
`?rest_route=/wplab-recovery/v1/*`, it hooks `muplugins_loaded` at max
priority and short-circuits BEFORE WP loads plugins or themes:

1. Sets `REST_REQUEST` const, parses route from URI.
2. Manual Basic-auth check via `WP_Application_Passwords::validate_application_password()`
   (works pre-init because the class is loaded by `wp-load.php`).
3. Falls back to plain `wp_check_password()` for non-app-password tests.
4. Force-fires `rest_api_init` via `rest_get_server()` so guardian routes
   register early.
5. Builds `WP_REST_Request` from `$_SERVER` + JSON body.
6. Dispatches via `WP_REST_Server::dispatch()`.
7. Emits JSON + `exit` — plugins + theme NEVER load for this request.

Adds `X-Rolepod-Guardian: 2.6.1` response header for client identification.

### Now handled

- Theme `functions.php` parse error / fatal — guardian endpoints reachable.
- Plugin file parse errors (other than the guardian mu-plugin itself).
- Any post-mu-plugin fatal that would otherwise kill the request.

### Limitation reduced from ~40% to ~5%

Real-world WSOD distribution (~95% theme + plugin runtime / load) now fully
recoverable through guardian REST. Remaining 5% (DB-dead, wp-config-broken,
core file missing) still require SSH/FTP/cPanel.

### Known limitation — current dispatch path (PRE-v2.6.1 — KEPT FOR HISTORY)

Guardian REST endpoints register via `rest_api_init`. That action only fires
once WP completes the boot sequence through `init`. Since theme `functions.php`
loads at `setup_theme` (BEFORE `init`), a fatal there means `rest_api_init`
never fires, and the guardian's REST routes are never registered for that
request — even though the mu-plugin code itself loaded and its
`register_shutdown_function` did record the fatal into the transient.

What v2.6 DOES correctly handle:

- Records every FATAL/PARSE/COMPILE error to the 24h transient via
  `register_shutdown_function` (this runs regardless of when in the boot
  cycle the fatal happened).
- Surfaces the fatal log + safe-mode toggle via the Settings page when WP
  loads OK.
- Handles fatals that happen AFTER `rest_api_init` (most runtime errors
  inside REST handlers, action callbacks, scheduled tasks).
- Auto-cleanup lifecycle (copy on activate, remove on deactivate, full
  cleanup on uninstall).

What v2.6 does NOT yet handle:

- Fatals during `setup_theme` / theme `functions.php` load (~40% of
  real-world WSODs). The guardian records them in the transient but its
  REST endpoints are unreachable until WP loads OK again.
- Fatals during plugin file parse (plugin own parse error before
  `plugins_loaded`).

### Doesn't cover (boot-stage fatals)

- DB connection failure (mu-plugins load after DB connect).
- wp-config.php parse error (boot dies before mu-plugins).
- WP core file missing (no boot at all).

For these, fall back to SSH/FTP/cPanel manual recovery — no plugin-level
solution exists.

### Planned for v2.7 — early dispatch from muplugins_loaded

Hook `muplugins_loaded:PHP_INT_MAX` and short-circuit when the URL matches
`/wp-json/wplab-recovery/v1/*` or `?rest_route=/wplab-recovery/v1/*`. At
that hook, WP has loaded core + mu-plugins, all regular plugins are about
to load via `include_once` in sequence, and theme has NOT been touched. We
manually:

1. Verify Application Password via `WP_Application_Passwords::validate_application_password()`.
2. Force-init `rest_get_server()` (which fires `rest_api_init`) — registers
   our guardian routes early.
3. Build `WP_REST_Request` from `$_SERVER` + body.
4. Dispatch via `$server->dispatch($request)`.
5. JSON-encode response + send + `exit`.

This bypasses plugins + theme load entirely for recovery URLs, so the
endpoints stay reachable during ANY post-mu-plugin boot fatal — including
theme-load WSODs. Implementation deferred to v2.7 to ship the
infrastructure pieces first.

### Pairs with

`@rolepod/wplab` v1.9.0 — adds:

- `Bridge.recoveryStatus / recoveryDisablePlugin / recoveryDisableFile /
  recoveryRestoreFile / recoveryRestoreSnapshot / recoveryListChanges /
  recoverySafeMode` — Bridge wrappers for `/wplab-recovery/v1/*`.
- `RecoveryModeError` — thrown by the MCP error layer when main namespace
  returns 5xx and guardian reports `main_alive: false`.
- 7 new MCP tools (`rolepod_wp_recovery_*`). Tool count 82 → 89.

## [2.5.0] — 2026-05-27 — One-time admin login + file disable/enable + execute-php crash recovery

### Added — `POST /wplab/v1/admin/one-time-login`

Mints a 5-min single-use transient + returns a `<siteurl>/?rolepod_wp_otl=<hex>`
URL. WP `init` hook intercepts the param, single-use deletes the transient,
calls `wp_set_auth_cookie()` for the issuing admin, and redirects to a
configurable destination (default: dashboard). Closes the "AI needs admin
UI without exposing password" gap so browser-automation flows don't have
to surface plaintext credentials.

### Added — `POST /wplab/v1/fs-rename`

Scope-checked rename (src + dest must resolve under `wp-content/{plugins,
themes,uploads,mu-plugins}` or be `wp-config.php`). Refuses if dest already
exists (no accidental overwrite). Used by the MCP-side `wp_file_disable` /
`wp_file_enable` toggle pair.

### Changed — execute-php crash recovery + duration_ms

`ExecutePhp::handle()` now:
- Catches `\ParseError` separately (returns line number).
- Catches `\Error` (PHP 7+ fatals like "Class not found") without bringing
  down the request.
- Installs a temporary error handler that captures E_WARNING / E_NOTICE /
  E_USER_WARNING into `php_warnings` array instead of failing eval.
- Restores the prior error-handler chain post-eval.
- Measures `duration_ms` via microtime; previously stubbed at 0.

### Pairs with

- `@rolepod/wplab` v1.8.0 — adds:
  - `Bridge.adminOneTimeLink` / `Bridge.fsRename` methods.
  - `wp_admin_one_time_link` MCP tool (Bridge wrapper).
  - `wp_file_disable` / `wp_file_enable` atomic tools (auto-ledger).
  - `wp_jetengine_{read,write}` adapter pair.
  - `wp_metabox_{read,write}` adapter pair.
  - `wp_pods_{read,write}` adapter pair.
  - `wp_conventions_{get,set}` — structured per-site project style guide
    storage at `~/.config/rolepod-wplab/memory/<host>/conventions.json`.
  - `wp_post_create` now reports `block_count` from Gutenberg comment-syntax
    scan (informational only).


## [2.4.0] — 2026-05-27 — Theme safety: syntax-check + theme snapshot/restore

### Added — `POST /wplab/v1/syntax-check`

Server-side validator that the MCP-side `file_write` calls BEFORE committing a
PHP or JSON payload. Eliminates the worst class of theme regression: bad PHP
in functions.php (White Screen of Death on every request) and bad JSON in
theme.json (Site Editor white-page).

- PHP: writes the payload to a temp file with `.php` extension, runs
  `php -l <file>` via exec(), parses stdout for "Parse error" / "syntax
  error", returns line number from "on line N".
- JSON: pure `json_decode` + `json_last_error_msg()`, line number from
  offset.
- Refuses with `EXEC_DISABLED` 503 if the host blocks `exec()`; MCP falls
  back gracefully to writing without server validation.

### Added — `POST /wplab/v1/theme/snapshot` + `POST /wplab/v1/theme/restore`

Companion-side tar/untar of theme directories under
`wp-content/uploads/rolepod-wp-theme-snapshots/`. Uses PHP `PharData`
(built-in, no composer). Restore validates the snapshot path is inside the
managed dir to prevent arbitrary-path extract attacks.

Used by the MCP-side `wp_theme_switch_safe` composite tool: snapshot the
CURRENT theme dir → wp-cli theme activate → post-switch health probe →
auto-rollback (re-activate old + untar snapshot) if the new theme breaks
the frontend.

### Pairs with

- `@rolepod/wplab` v1.7.0 — adds:
  - `Bridge.syntaxCheck/themeSnapshot/themeRestore` methods.
  - `wp_file_write` pre-write validators (`.php` → companion syntax-check;
    `.json` → Node-side JSON.parse).
  - `wp_theme_snapshot` + `wp_theme_restore` MCP tools.
  - `wp_child_theme_create` composite — parent → child scaffolding.
  - `wp_theme_switch_safe` composite — snapshot + activate + auto-rollback.
  - `wp_session_start` — issues `source_session` id; auto-threads via env into
    all auto-ledger writes so multi-file work is atomically revertable.
  - Auto cache flush after `theme.json` and `/wp/v2/global-styles/<id>` writes.
  - Auto-ledger on `rest_request` global-styles writes (before-state captured
    via GET first).
  - New `wp-edit-theme` skill (13th); `wp-edit-design` scope narrows to
    page-builders only.

## [2.3.1] — 2026-05-26 — Auto-install ledger schema on upgrade

### Fixed

- `plugins_loaded` hook now auto-installs the ledger schema when the option-stored
  schema version differs from the bundled constant. Without this, sites that
  UPDATED from v2.1 → v2.3 (instead of fresh-activated) had the recorder API
  silently inserting into a missing table — every `record` call returned `ok:true`
  with a fresh audit_id, but `query` returned empty. Caught in e2e on the demo
  site immediately after the v2.3.0 ship.

## [2.3.0] — 2026-05-26 — AI Change Ledger + per-change toggle + panic-revert

### Added — `wp_rolepod_wp_changes` ledger table

Every write the MCP issues through this companion is now captured in a custom
table (`{prefix}rolepod_wp_changes`) with category, subcategory, before-state,
after-state, applied flag, reversible flag, source tool, source session,
and audit_id. Schema installs idempotently on plugin activate.

### Added — 5 new REST endpoints (`/wplab/v1/changes/*`)

- `POST /changes/record` — MCP records a change (session_token + manage_options auth).
- `GET  /changes` — list/filter (category, applied, since_minutes, source_session).
- `POST /changes/toggle` — flip one row's applied flag + run per-category dispatcher.
- `POST /changes/toggle-bulk` — flip N rows in one call (used for bisect).
- `POST /changes/panic` — disable every applied change in the last N minutes.

### Added — per-category toggle dispatchers

`Toggler::apply()` runs the right inverse per category when a row flips:
- `hook` — wrapper-flag flip via `HookWrapper::isApplied()` (instant, zero file change).
- `option` — `update_option($name, $before_value)` (or delete if before-state was absent).
- `post` — `wp_update_post()` with the prior title / content / status / excerpt.
- `layout` — `update_post_meta()` with the prior meta value (Elementor / Divi / Oxygen / Bricks JSON).
- `file` — `file_put_contents()` with the prior content (or delete if it didn't exist).
- `plugin` — `activate_plugin()` / `deactivate_plugins()`.
- `theme` — `switch_theme()`.
- `execute_php` — flagged `reversible: 0` at record time; toggle flips flag but cannot replay inverse.

### Added — Tools → Rolepod WP Changes admin page

Hand-rolled list table with tabs (All / Hooks / Content / Options / Layouts / Files / Plugins / Themes / execute-php), bulk-select checkboxes, per-row Disable/Re-enable buttons, and a 🚨 panic-disable button with a window selector (10 min / 1 hour / 24 hours).

### Added — `HookWrapper` helper

AI-issued hook callbacks emitted by `wp-scaffold` are wrapped in:
```php
if (!\Rolepod\Wp\Audit\HookWrapper::isApplied('<audit_id>')) return;
```
Per-request cache so many wrapped callbacks on the same hook share one DB read.

### Pairs with

- `@rolepod/wplab` v1.6.0 — adds `Bridge.recordChange/queryChanges/toggleChange/toggleChangesBulk/panicChanges` + 4 MCP tools (`rolepod_wp_changes_{query,toggle,toggle_bulk,panic}`) + auto-ledger wrappers on writer tools (post_update, post_create, option_set, file_write) + the new `wp-changes` skill.

## [2.1.0] — 2026-05-26 — Shared-host fix + auto-bootstrap wp-cli

### Fixed

- **SessionToken now uses WP transients** instead of `wp_cache_*`. v2.0.0 stored
  tokens in the WP object cache which on shared hosts (no Redis/Memcached) is
  per-request only, so the handshake-then-call flow could not work — every
  follow-up call returned `INVALID_OR_EXPIRED_TOKEN`. Transients route through
  the object cache when one is present and fall back to wp_options rows when
  not, so tokens now persist across requests on every host.

### Added

- **`POST /wplab/v1/wp-cli/bootstrap`** — idempotent endpoint that fetches the
  pinned `wp-cli.phar` from `raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/`
  and writes it to `wp-content/uploads/wplab-bin/wp-cli.phar`. Sanity-checks
  the response (must contain `__HALT_COMPILER` marker) before saving. MCP
  side calls this automatically the first time a wp-cli call returns
  `WP_CLI_NOT_BUNDLED`, so shared hosts get a working wp-cli without anyone
  SSH'ing in. Verifies file presence on re-call → returns `already_present: true`.

### Pairs with

- `@rolepod/wplab` v1.4.0 — adds `RestTarget.wpCli` + `Bridge.wpCli` +
  `Bridge.fileRead/fileWrite` so RestTarget gets full shell capability via this
  companion. Composite tools (audit_security, diagnose, cron_tool, cache_tool,
  mail_test, user_session_list) now work over REST without needing local/ssh/docker
  target kind.

## [2.0.0] — 2026-05-26 — Rebrand: rolepod-wp

### Breaking

This is a clean break. **Existing installs from v1.x must be deactivated + deleted, then `rolepod-wp` v2.0.0 installed fresh.** No in-place upgrade path is provided. Old `rolepod_wplab_companion_*` option rows are orphaned in the DB on uninstall of the v1.x plugin (the v1.x `uninstall.php` will clean them up if the user uses the WP-admin "Delete" action; deactivation alone leaves them).

Why no migration code:
- Solo project, small install base. Migration code carries its own bug surface for a one-time event.
- v1.x `uninstall.php` already deletes its own option rows on plugin delete, so the cleanest path is "delete v1.x → install v2.0.0".

### Changed — naming

- **GitHub repo**: `nuttaruj/rolepod-wplab-companion` → `nuttaruj/rolepod-wp`. GitHub auto-redirects old clone URLs + browser links indefinitely; old release zips at `…/rolepod-wplab-companion-1.x.0.zip` URLs continue to resolve (under the new repo path via redirect).
- **WP plugin slug** (directory under `wp-content/plugins/`): `rolepod-wplab-companion` → `rolepod-wp`.
- **WP plugin bootstrap file**: `rolepod-wplab-companion.php` → `rolepod-wp.php`.
- **WP plugin display name** (admin Plugins list): `Rolepod WPLab Companion` → `Rolepod for WordPress`.
- **Text domain**: `rolepod-wplab-companion` → `rolepod-wp`.
- **Admin pages**:
  - Settings → WPLab Companion → renamed **Settings → Rolepod for WordPress** (slug `options-general.php?page=rolepod-wp`).
  - Tools → WPLab Setup → renamed **Tools → Rolepod WP Setup** (slug `tools.php?page=rolepod-wp-setup`).
- **PHP namespace**: `RolepodWplabCompanion\` → `Rolepod\Wp\` across all 18 PHP source files.
- **PHP defines**: `ROLEPOD_WPLAB_COMPANION_VERSION` / `_FILE` / `_DIR` / `_NAMESPACE` → `ROLEPOD_WP_VERSION` / `_FILE` / `_DIR` / `_REST_NAMESPACE`.
- **Option keys**: `rolepod_wplab_companion_version` → `rolepod_wp_version`; `rolepod_wplab_companion_config` → `rolepod_wp_config`; `rolepod_wplab_audit_log` → `rolepod_wp_audit_log`; pair-token rows now `rolepod_wp_pair_<hash>` (was `rolepod_wplab_pair_<hash>`); throttle keys now `rolepod_wp_pair_fail_<ip_md5>`.
- **Pair token wire format**: prefix `wplab_pair_<hex>` → `rolepod_wp_pair_<hex>`. MCP redeems any token sent by user; no MCP-side change needed.
- **WP_Error codes**: `rolepod_wplab_disabled` / `_unauthorized` / `_execute_php_disabled` → `rolepod_wp_*` equivalents. (Surfaced only to MCP-side error handling; not part of public API.)
- **Filter hook for plugin_internals**: `rolepod_wplab_introspect_<slug>` → `rolepod_wp_introspect_<slug>`. Third-party integrations registering with the old filter name must rename their `add_filter()` call.
- **Audit directory**: `wp-content/uploads/wplab-audit/` → `wp-content/uploads/rolepod-wp-audit/`. Audit-id prefix: `wplab_audit_<hex>` → `rolepod_wp_audit_<hex>`.
- **Release asset filename**: `rolepod-wplab-companion.zip` → `rolepod-wp.zip`. Stable URL: `https://github.com/nuttaruj/rolepod-wp/releases/latest/download/rolepod-wp.zip`.

### Unchanged (intentionally — preserves wire compatibility with MCP clients)

- **REST namespace** stays `wplab/v1`. Path `/wp-json/wplab/v1/handshake`, `/pair/redeem`, etc. unchanged. No client config update needed beyond pointing at the new install URL.
- All endpoint request/response JSON shapes.
- Authentication model (WP Application Password + session token).
- Production guard semantics. AST screen rules. Audit log entry shape.
- App Password display name minted by Pair endpoint: `wplab-pair-<UTC-timestamp>` (already user-visible in profile.php; renaming would surprise users).

### Pairs with

- `@rolepod/wplab` v1.3+ (MCP-side reads `companion_version` from Pair response; `MIN_COMPANION_VERSION` = 2.0.0 on the MCP build that depends on the new slug for install URL).

### Why rebrand

Plugin is the WordPress arm of the broader [Rolepod ecosystem](https://github.com/nuttaruj/rolepod), parallel to `rolepod-uiproof`. The previous `rolepod-wplab-companion` name double-stamped the `wplab` brand and positioned the plugin as subordinate ("companion") rather than as the WordPress endpoint hub of the ecosystem. The new name leaves room for future Rolepod tooling to share these endpoints without a second plugin install.

## [1.2.0] — 2026-05-26

### Added

- **REST endpoint** `POST /pair/generate` (admin only) — mints a one-time pair token (256-bit, SHA-256 at rest, 60-min TTL, max 5 active per admin).
- **REST endpoint** `POST /pair/redeem` (public, token-authed) — atomic single-use redeem → mints WP App Password named `wplab-pair-<UTC-timestamp>` under issuing admin. Per-IP throttle: 10 failed redeems / hour.
- **Setup Wizard quick-start** — Tools → WPLab Setup → click "⚡ Generate setup prompt" → companion builds a ready-to-paste prompt with per-CLI MCP install snippets (Claude Code / Cursor / Codex / Gemini) + `rolepod_wp_pair` call.

## [1.1.0] — 2026-05-26

### Added

- **Setup Wizard admin page** (Tools → WPLab Setup) — manual step-by-step (App Password + npm install + claude mcp add + credentials add). Foundation for the one-click flow in v1.2.

## [1.0.0] — 2026-05-25 — Stable (schema-frozen)

### Locked

- 8 REST endpoints under `/wp-json/wplab/v1/`: handshake, introspect (5 scopes), execute-php, wp-cli, fs-read, fs-write, php-session, request-observer (+ poll).
- Endpoint request/response JSON shapes.
- Audit log entry shape (timestamp, audit_id, endpoint, user, site_url, result, error?, payload_sha256?).
- Production-guard semantics (siteurl glob match; no override on execute-php / fs-write).
- Session-token TTL default 30 min (admin can override per-install).
- AST screen forbidden-token list (eval, assert, create_function, system, passthru, shell_exec, exec, proc_open, popen, pcntl_*, dl, backtick, dynamic include/require).
- Config schema (endpoints_enabled, execute_php_enabled, production_hosts).
- Capability map advertised in handshake.

### Pairs with

- `@rolepod/wplab` v1.0 — Node-side bridge consumes these endpoints + locks tool surface.

### Maintainer next actions (post-tag)

- Submit to wordpress.org plugin directory (may take weeks; if rejected, GitHub Releases stays the canonical distribution).
- Bundle `wp-cli.phar` in release zip (currently fetched separately).
- Companion v1.x+ adds endpoints only; no breaking changes.


## [0.2.0] — 2026-05-25

### Added

- **REST endpoint** `POST /wp-cli` — proxy to bundled `wp-cli.phar` via PHP `exec()`. Closes shared-hosting gap: users without host-side wp-cli can run wp-cli through companion. Returns 503 with hint when `exec()` disabled or phar missing. Path: `wp-content/uploads/wplab-bin/wp-cli.phar`. Destructive subcommands refused unconditionally on production-matched siteurl.
- **REST endpoint** `POST /fs-read` — scoped file read under ABSPATH. 5 MiB cap. Path traversal blocked via `realpath()` containment check.
- **REST endpoint** `POST /fs-write` — scoped file write under `wp-content/themes/`, `wp-content/plugins/`, `wp-content/uploads/` (extension allow-list) or `wp-config.php` with `confirm_unsafe_path=true` opt-in. Production guard refuses unconditionally. Optional pre-write backup with `.wplab-bak-YYYYMMDD-HHMMSS` suffix.
- **REST endpoint** `POST /php-session` — persistent PHP eval context keyed by session token. Variables persist across multiple calls within the same FastCGI worker via `wp_cache_*`. Same guard chain as execute-php (AST screen + production block + audit log).
- **REST endpoints** `POST /request-observer` + `GET /request-observer/poll` — register a hook + queue observed state (timestamp + arg_count) for mid-request introspection. Polling returns accumulated observations (capped at 100, 10 min TTL).
- **Introspect scope** `plugin_internals` — third-party plugins can register via `apply_filters('rolepod_wplab_introspect_<slug>', [...])` to surface their own state.

### Changed

- **execute-php default** — flipped from OFF to ON on activation. Endpoints master toggle (`endpoints_enabled`) still defaults OFF; admin must enable endpoints first, after which execute-php is available unless toggled off separately. Production guard still unconditional.

### Not yet implemented (v0.3+)

- `nikic/php-parser`-based AST screen (replaces v0.1/v0.2 token-blocklist). Adds composer dep.
- Audit log rolling-file mode (replaces wp_options-backed cap of 1000).
- Bundled wp-cli.phar inside release zip (currently fetched separately).
- Per-session token TTL configurable via admin.

## [0.1.0] — 2026-05-25

### Added

- REST endpoints: `GET /handshake`, `GET /introspect`, `POST /execute-php` (default-disabled).
- AST screen (token-blocklist via `token_get_all()`).
- SessionToken (in-memory via `wp_cache_*`, TTL 30 min).
- ProductionGuard (siteurl glob match).
- Append-only audit log (wp_options + file under `wp-content/uploads/wplab-audit/`).
- Admin Settings page (`Settings → WPLab Companion`).
- `uninstall.php` cleans up options + audit dir.

### Defaults (security-first)

- `endpoints_enabled` = false on activation.
- `execute_php_enabled` = false in v0.1 (becomes true in v0.2 once defences proven).

## [0.0.0] — 2026-05-25

### Added — scaffold only

- Plugin bootstrap, LICENSE (MIT), README, .gitignore.
