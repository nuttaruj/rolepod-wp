# rolepod-wp

> **🌐 Curious what AI can do on your WordPress site?** See the [plain-English capability breakdown](https://nuttaruj.github.io/rolepod-wplab/) — 3 setup tiers, what each unlocks.
>
> **WordPress arm of the [Rolepod ecosystem](https://github.com/nuttaruj/rolepod).**
>
> End users: you do not need to read this repo. Everything you need (install one-liner, setup wizard walkthrough, troubleshooting) lives on the [`rolepod-wplab` main product page](https://github.com/nuttaruj/rolepod-wplab#readme).
>
> This repo is the source of the WP plugin that the MCP-side toolkit (`@rolepod/wplab`) ships an install link to. PHP plugin developers, contributors, and maintainers may continue reading.
>
> **Renamed in v2.0.0** from `rolepod-wplab-companion` → `rolepod-wp`. Migration note: clean break — existing v1.x installs must be deactivated + deleted, then `rolepod-wp` installed fresh. Reason in [CHANGELOG.md](./CHANGELOG.md).

---

**WP plugin that pairs with [`@rolepod/wplab`](https://github.com/nuttaruj/rolepod-wplab) to unlock `execute-php`, runtime introspection, and the one-click "⚡ Generate setup prompt" wizard — so AI agents can pair this site with any CLI in 30 seconds instead of walking the user through App Password creation.**

Without this plugin, `@rolepod/wplab` is a complete default-safe wp-cli + REST + scoped-fs toolkit. With this plugin, the toolkit unlocks full runtime control (eval PHP, introspect hooks, browse transients, snapshot + restore themes) — all under opt-in guardrails. MIT, no outbound network calls, no telemetry.

## What it adds

- **One-click pair.** Admin opens **Tools → Rolepod WP Setup → ⚡ Generate setup prompt**. Plugin mints a single-use 60-min pair token + builds a ready-to-paste prompt with CLI-specific install snippets. AI CLIs trade the token for a real WP Application Password (named `wplab-pair-<UTC-timestamp>`, revocable from `profile.php`).
- **execute-php.** Run arbitrary PHP inside the live WP request lifecycle. Two AST screens (Node side + PHP side via `nikic/php-parser`), production-block unconditional, append-only audit log.
- **Runtime introspection.** Snapshot active hooks, transients, `wp_options`, request state. List callbacks on any hook.
- **Mid-request observer + persistent PHP eval session** (`request-observer`, `php-session`).
- **wp-cli over REST** via the bundled `wp-cli.phar` — works on shared hosts where you can't install wp-cli normally (`wp-cli` endpoint).
- **Scoped fs over REST** (`fs-read`, `fs-write`) — same scope rules as the Node MCP.

## When you need it

Install **only if** you want any of: execute-php, runtime introspection, the one-click pair flow, wp-cli on shared hosts, or page-builder write surfaces that need PHP API access.

For scaffold / audit / health / REST CRUD / scoped fs workflows, the Node MCP (`@rolepod/wplab`) is enough on its own — connect via stored App Password and you get 58 of the 62 tools without ever installing this plugin.

## Install

**Option 1 — one-liner via wp-cli (stable URL, always latest):**

```bash
wp --path=<your-wp> plugin install \
  https://github.com/nuttaruj/rolepod-wp/releases/latest/download/rolepod-wp.zip \
  --activate
```

**Option 2 — WP admin upload:**

1. Download `rolepod-wp.zip` from the [latest release](https://github.com/nuttaruj/rolepod-wp/releases/latest).
2. WP admin → Plugins → Add New → **Upload Plugin** → pick the zip → Activate.

## After activation

1. **Settings → Rolepod for WordPress** → toggle **Enable companion REST endpoints**.
2. **Tools → Rolepod WP Setup** → click **⚡ Generate setup prompt** → copy.
3. Paste into your AI CLI (Claude Code / Cursor / Codex / Gemini). The AI runs `rolepod_wp_pair` and you're connected with full power tools.

Verify from the MCP side:

```bash
rolepod-wplab doctor    # companion handshake should report 200 OK
```

## The 10 REST endpoints

Under `/wp-json/wplab/v1/` (REST namespace retained for client compatibility — only the plugin slug + DB keys changed in v2.0.0):

| Endpoint | Method | Auth | Purpose |
|---|---|---|---|
| `handshake` | GET | App Password | Session token issue + capability advertise + production flag. |
| `pair/generate` | POST | `manage_options` | Issue single-use pair token (60-min TTL, 256-bit entropy, SHA-256 at rest). Max 5 active per admin. |
| `pair/redeem` | POST | pair_token | Atomic single-use redeem → mint App Password named `wplab-pair-<UTC-timestamp>`. Per-IP throttle. |
| `execute-php` | POST | App Password + session token | PHP eval. AST-screened. Production-blocked. Audit-logged. |
| `introspect` | GET | App Password + session token | Hooks / transients / options / request-state read. |
| `wp-cli` | POST | App Password + session token | Bundled wp-cli.phar passthrough (same allow-list as Node side). |
| `fs-read` | GET | App Password + session token | Scoped file read (same scope as Node MCP). |
| `fs-write` | POST | App Password + session token | Scoped file write. |
| `php-session` | POST | App Password + session token | Persistent eval context across calls. |
| `request-observer` | GET | App Password + session token | Mid-request snapshot stream. |

## Architecture

- Two admin pages: **Settings → Rolepod for WordPress** (master toggle + production hostnames + audit log viewer) and **Tools → Rolepod WP Setup** (wizard + ⚡ Generate setup prompt).
- Defence in depth: WP Application Password auth + per-session token (TTL 30 min) + **two AST screens** (Node side AND PHP side via `nikic/php-parser`) + production glob match + confirm token + append-only audit log.
- No outbound network calls. No phone-home. No telemetry. No SaaS dependency.
- Code budget: **≤ 500 LOC PHP**, lint-enforced.

## Security model

Every guarded operation passes:

1. **Application Password verification.**
2. **Per-session token check** (TTL 30 min, bound to user + app password).
3. **Production hostname block** — if `siteurl` matches an admin-configured glob pattern, refuse regardless of caller. No override.
4. **AST screen (Node side)** — payload parsed before send; reject `eval` / `system` / `shell_exec` / `exec` / `proc_open` / `popen` / dynamic include / out-of-scope file ops.
5. **AST screen (PHP side)** — re-parsed via `nikic/php-parser` inside this plugin before any eval. Defence in depth.
6. **Append-only audit log** — every call (success + reject) written to `wp_options::rolepod_wp_audit_log` (1000-entry FIFO) AND `wp-content/uploads/rolepod-wp-audit/<audit_id>.log` (mode 0600).

Pair flow adds:

- Token wire format: `rolepod_wp_pair_<48 hex chars>`. 256 bits entropy. **SHA-256 hashed at rest** in `wp_options`, raw never stored.
- **Single-use enforced atomically** (delete-before-act inside `PairToken::redeem`).
- 60-min TTL; max 5 active tokens per admin.
- Per-IP throttle: 10 failed redeems / hour via transient.

Report security issues privately to `nuttaruj@gmail.com` with 90-day disclosure (see SECURITY.md once it lands at v0.5).

## If your host's malware scanner empties a file

`src/Endpoint/ExecutePhp.php` exists to run PHP you send it. To a signature
scanner that is a backdoor, and the detection is fair — abandoned
eval-a-string plugins have been mass-abused in the wild. Imunify360 in
particular *trims* a file it cannot clean instead of deleting it, so the file
stays on disk at zero bytes.

Since 2.24.1 that costs you the affected endpoint and nothing else: the
registrar skips a class it cannot load, the rest of the companion runs, and
your site's REST API is untouched. Since 2.24.2 an admin notice tells you it
happened, names the class, and prints the path to ignore-list. `GET /handshake`
reports the same thing as `broken_endpoints` for the MCP client.

To restore it:

1. Add the path to your scanner's ignore list, or the next scan re-empties it:
   - **Imunify360** → Ignore List → *Add New File or Directory*
   - **Wordfence** → All Options → Scan Options → *Exclude files from scan that
     match these wildcard patterns*
   ```
   wp-content/plugins/rolepod-wp/src/Endpoint/ExecutePhp.php
   ```
2. Reinstall the plugin to bring the file back.

If you never turn on AI Full Control, leaving it emptied is a perfectly valid
choice — execute-php is off in guarded mode anyway, and nothing else changes.

## Versioning

Plugin version tracks the MCP (`@rolepod/wplab`) family it pairs with. `MIN_COMPANION_VERSION` on the MCP side (see `rolepod-wplab/src/companion/constants.ts`) is the version floor that MCP build expects.

| rolepod-wp | Pairs with `@rolepod/wplab` | Highlights |
|---|---|---|
| 0.1 | 0.1 | handshake + introspect (execute-php disabled by default) |
| 0.2 | 0.2 | execute-php opt-in + bundled wp-cli + fs endpoints + observer + persistent session |
| 1.0 | 1.0 | schema-frozen + external-audited |
| 1.2 | 1.2 | Pair endpoint + Setup Wizard one-click flow |
| **2.0** | **1.3+** | **Repo + plugin slug renamed `rolepod-wplab-companion` → `rolepod-wp`. PHP namespace `Rolepod\Wp\`. Option keys `rolepod_wp_*`. Clean break — no in-place upgrade path.** |

## Build a release zip locally

```bash
./scripts/build-zip.sh              # writes dist/rolepod-wp.zip
./scripts/build-zip.sh --upload     # also uploads to the matching gh release tag
```

The zip excludes `.git`, `tests/`, `scripts/`, `README.md`, `CHANGELOG.md` — only runtime files ship. CI does this automatically on `v*` tag push (see `.github/workflows/release.yml`).

## License

MIT — see [LICENSE](./LICENSE). Independent implementation, written from spec; not derived from any GPL/AGPL WordPress AI plugin.
