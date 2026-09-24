# Qaiyo Admin Booster

> WordPress plugin that fixes the small, everyday wp-admin friction points — one-click plugin upload, page collections, a broken-plugin-folder finder, update crash protection, and 20 more modules you can switch on or off individually.

[![WordPress 5.9+](https://img.shields.io/badge/WordPress-5.9%2B-21759b.svg)](https://wordpress.org/)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://www.php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![Version](https://img.shields.io/badge/version-1.4.8-6c5ce7.svg)](#)

This repository hosts the **free** Qaiyo Admin Booster plugin — live on
[WordPress.org](https://wordpress.org/plugins/qaiyo-admin-booster/). The optional
[Qaiyo Admin Booster Pro](https://qaiyo-plugins.com) add-on unlocks a per-role admin menu editor,
inline list-table editing, a login screen customizer, admin dark mode, SVG sanitization,
per-role upload rules, targeted update alerts and a core update bridge for sites stuck on old PHP.

- **Website:** [qaiyo-plugins.com](https://qaiyo-plugins.com)
- **Support:** info@qaiyo-plugins.com
- **Issues:** [GitHub Issues](../../issues)

---

## Why this plugin

wp-admin has dozens of small rough edges that every WordPress site owner runs into: uploading a
plugin takes an extra click, finding the homepage in a 400-item page list means guessing, and a
plugin folder left behind by a failed update quietly blocks every future install with no way to
see or remove it from the UI. Admin Booster fixes these one at a time, as independent modules —
it does not redesign wp-admin, and nothing is on by default that you cannot turn back off.

The plugin is designed to be:

- **Modular** — 24 independent modules, each with its own on/off switch; disabling one never
  affects another.
- **Safe by default** — every destructive action (deleting a broken plugin folder, restoring a
  crashed update, entering Safe Mode) requires an explicit click and is capability- and
  nonce-checked; nothing runs automatically without a clear reason logged.
- **Ecosystem-aware, never coupled** — the Pro add-on and sibling Qaiyo plugins talk to Admin
  Booster only through documented filters and actions (see [HOOKS.md](HOOKS.md)); the free plugin
  has zero knowledge of what, if anything, is installed alongside it.
- **Translation-ready** — ten languages bundled (`.po` + `.mo`), Polylang/WPML/TranslatePress
  compatible, locale-variant fallback (e.g. `de_AT → de_DE`, `pt_BR → pt_PT`).

---

## Features

### wp-admin UX fixes

| Module | What it does |
|---|---|
| **1-click plugin upload** | "Add Plugin" opens the upload screen directly instead of the browse tabs. |
| **Gutenberg UX** | Keeps the block inserter open and the editor out of fullscreen. |
| **Quick admin navigation** | New Page / Post / Product (and any custom post type) shortcuts in the admin bar. |
| **Larger list views** | Raises the default items-per-page in admin list tables. |
| **Tidy admin notices** | Collapses stacked admin notices into a small counter tray — nothing deleted, dismiss buttons still work. |
| **Sticky table headers** | Column headers stay pinned while scrolling long list tables. |
| **Last edited column** | Who last edited each item, and how long ago. |
| **Quick status switch** | Publish/unpublish straight from the list, no editor needed. |

### Content organization

| Module | What it does |
|---|---|
| **Page Collections** | Group pages/posts into visual collections (auto-detects Homepage, Legal, WooCommerce system pages); colored badge column + filter dropdown. |
| **Page Filter** | See homepage / orphan / not-in-menu / noindex / status at a glance, isolate any with one click. |
| **Bulk create** | Create many pages/posts at once from an indented title list — Tab-indent to build a page tree in one step. |
| **Add to menu from the editor** | A **Menus** panel in the editor sidebar: pick a menu, add/move/remove at the top level or as a submenu, without leaving the editor. |
| **Menu item visibility** | Per-menu-item Always / Mobile only / Desktop only, via a CSS media query (768px) — the menu structure stays identical in every view. |
| **Scheduled countdown** | "Publishing in 3 days" next to scheduled post titles. |

### File handling

| Module | What it does |
|---|---|
| **File handling** | Raise the max upload size from wp-admin (no cPanel/FTP), enable WebP/AVIF/SVG, add custom file types, filename slug cleanup. |
| **Broken plugin folders** | Finds `wp-content/plugins/` folders WordPress cannot recognise as a plugin (interrupted update/deletion leftovers) — the usual cause of "Destination folder already exists" — diagnoses why, and deletes them after confirmation. See [Broken plugin folders](#broken-plugin-folders) below. |

### Dashboard widgets

| Module | What it does |
|---|---|
| **Update center widget** | Lists every plugin/theme update in one place, with a server-side "Update all" that works even when the built-in updater gets stuck on filesystem credentials. |
| **Comments widget** | Comment counts (approved/pending/spam/trash) + latest comments, one-click/bulk trashing. |
| **Dashboard greeting** | A personal greeting at the top of the dashboard. |
| **Dashboard cleanup** | Hide default dashboard widgets you never use. |
| **Qaiyo ecosystem widget** | Gathers a small summary card from every active Qaiyo plugin into one widget — only appears when something is actually installed. |

### Updates & recovery

| Module | What it does |
|---|---|
| **Separate table for pending updates** | On the Plugins screen, updates get their own table at the top; every other plugin stays below. |
| **Update notification emails** | Daily/weekly digest of waiting plugin/theme/core updates, with version-tracked spam protection so you only hear about genuine changes. |
| **Update crash protection** | After *any* update — manual click, WP-CLI, or a remote manager like ManageWP/MainWP — loads the site and automatically restores the previous version on a fatal error. On by default. |
| **Safe Mode link** | A link you save *before* anything breaks: opens a locked-down recovery console (every plugin off, default theme) to deactivate the culprit. The secret never touches server logs or the database in plaintext. Off by default. |

### Ecosystem

| Module | What it does |
|---|---|
| **Qaiyo ecosystem panel** | A settings tab with one card per Qaiyo plugin — live summary if installed, a one-line pitch + WordPress.org link if not. |
| **Explore Qaiyo** | A directory of the whole Qaiyo plugin family with update notices. |

---

### Broken plugin folders

A dedicated deep dive, because it is the module most likely to save you a support ticket:

WordPress refuses to reinstall a plugin whose folder already exists (`update.php?action=upload-plugin`
→ "Destination folder already exists"), and it only offers to *overwrite* that folder when it can
recognise a plugin inside it. If an earlier update or deletion was interrupted, the folder is left
holding stray files with no `Plugin Name:` header — WordPress can neither list it (`get_plugins()`
finds nothing) nor offer to replace it, so from the UI it is invisible and un-removable.

This module:

1. Lists every such folder at the top of the Plugins screen, with a diagnosis — an interrupted
   update, a ZIP packed one folder too deep, unreadable files, or simply empty — the folder's
   size, file count and last-modified time.
2. Adds a "Review the damaged folder" link directly on the upload error page when the conflicting
   folder is one of these leftovers.
3. Deletes only after an explicit confirmation, re-diagnoses the folder at the moment of deletion
   (in case something else installed a working plugin there in the meantime), and never touches a
   folder that is a symlink or contains one — a recursive delete would follow the link outside the
   plugins directory.

See [`qwab_broken_plugin_folders_ignore`](HOOKS.md#broken-plugin-folders-hooks) to exclude a folder
your host places there on purpose, and
[`qwab_broken_plugin_folder_deleted`](HOOKS.md#broken-plugin-folders-hooks) to log deletions.

---

## Installation

### From a ZIP file

1. Install directly from **Plugins → Add New** (search "Qaiyo Admin Booster"), or download a
   [release ZIP](../../releases) and use **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Go to **Admin Booster** in the wp-admin sidebar to switch modules on or off.

### From source (developers)

```bash
git clone https://github.com/qaiyo/qaiyo-admin-booster.git
cd qaiyo-admin-booster
# Symlink or copy the folder into wp-content/plugins/
ln -s "$(pwd)" /path/to/wordpress/wp-content/plugins/qaiyo-admin-booster
```

**Requirements:** WordPress 5.9+, PHP 7.4+.

---

## Developer API

The Pro add-on and sibling Qaiyo plugins extend Admin Booster only through these documented hooks —
your own code can do the same. Full details, parameters and examples are in [HOOKS.md](HOOKS.md).

### Filters

| Filter | Purpose |
|---|---|
| `qwab_pro_active` | Set to `true` by the Pro plugin on activation; the free plugin never checks a license itself. |
| `qwab_pro_unlocked_modules` | The list of Pro module slugs the active license has unlocked. |
| `qwab_upgrade_url` | Override the "Upgrade to Pro" destination. |
| `qwab_ecosystem_widgets` | Register a summary card for the Qaiyo ecosystem dashboard widget/panel. |
| `qwab_update_notification_items` | Adjust the items considered for the update digest. |
| `qwab_update_notification_frequencies` | Add a custom digest schedule. |
| `qwab_update_notification_recipients` | Change who receives the digest email. |
| `qwab_update_notification_subject` / `qwab_update_notification_message` | Customize the digest email content. |
| `qwab_update_notification_send_email` | Short-circuit or redirect delivery. |
| `qwab_nav_visibility_breakpoint` | Override the fixed 768px breakpoint used by Menu item visibility. |
| `qwab_broken_plugin_folders_ignore` | Folder names the Broken plugin folders panel should never report. |

### Actions

| Action | Fires |
|---|---|
| `qwab_update_notification_sent` | After a digest email is sent — with the item list, successes and failures. |
| `qwab_broken_plugin_folder_deleted` | After a broken plugin folder is deleted and verifiably gone. |

---

## Translations

The plugin ships with ten languages in `/languages/`:

```
qaiyo-admin-booster.pot
qaiyo-admin-booster-hu_HU.po + .mo    qaiyo-admin-booster-it_IT.po + .mo
qaiyo-admin-booster-de_DE.po + .mo    qaiyo-admin-booster-ru_RU.po + .mo
qaiyo-admin-booster-fr_FR.po + .mo    qaiyo-admin-booster-tr_TR.po + .mo
qaiyo-admin-booster-es_ES.po + .mo    qaiyo-admin-booster-pl_PL.po + .mo
qaiyo-admin-booster-ja.po + .mo       qaiyo-admin-booster-pt_PT.po + .mo
```

New source strings are added to a Python translation table and merged into every catalog by a
single script (see *Development* below) — no `msgfmt` needed by hand, and `Plural-Forms` headers
and printf placeholders are validated automatically.

The plugin uses `load_textdomain()` directly (not `load_plugin_textdomain()`) to avoid the
WordPress.org Plugin Check warning about discouraged functions.

---

## Standards & security

The codebase follows the WordPress Coding Standards and the WordPress.org Plugin Check rules:

- Class prefix `Qwab_` (matches the plugin slug), constants `QWAB_*`.
- `$_POST`/`$_GET` data is always unslashed and sanitized before use; every state-changing request
  is capability- and nonce-checked, and the nonce is bound to the specific resource it acts on
  (e.g. `qwab_delete_plugin_folder|{folder-name}`, not a generic site-wide token).
- File operations resolve paths with `realpath()` and reject anything outside the intended
  directory or that is or contains a symbolic link, before any write or delete.
- Destructive actions re-verify their precondition at the moment of execution, not just when the
  confirmation was shown (the decision and the effect can be seconds apart).
- Every dynamic output uses `esc_html`, `esc_attr`, `esc_url` or `wp_kses`.
- No raw SQL — only WP APIs. No `eval`, `extract`, `create_function`.
- Full multisite-aware uninstall (`uninstall.php`) — options, transients, user meta and
  server-level files (`.htaccess` / `.user.ini` blocks, the Safe Mode mu-plugin) are all removed.

See [SECURITY.md](SECURITY.md) for the vulnerability disclosure policy.

---

## Development

### Repository layout

```
qaiyo-admin-booster.php         Main plugin file (header, constants, bootstrap)
uninstall.php                   Full multisite-aware data + file cleanup on deletion
includes/
  class-qwab-plugin.php         Module registry + composition root
  class-qwab-settings.php       Settings storage + defaults + sanitization
  class-qwab-brand-menu.php     "QAIYO PLUGINOK" admin menu separator chip
  class-qwab-more-plugins.php   "Discover Qaiyo" panel (WordPress.org API, no phone-home)
  class-qwab-pro-catalog.php    Pro module catalog (teaser copy for the Modules grid)
  class-qwab-pro-teaser.php     Lock icon + upsell UI for Pro-only modules
  class-qwab-ecosystem-panel.php  The Qaiyo ecosystem settings tab
  class-qwab-sealed-box.php     Symmetric encryption for the Safe Mode secret (never stored plain)
  class-qwab-safe-mode.php      Safe Mode mu-plugin install/sync/cleanup
  class-qwab-server-limits.php  .htaccess/.user.ini upload-limit writer (atomic, journaled)
  class-qwab-plugin-folder-inspector.php       Filesystem diagnosis for broken plugin folders
  class-qwab-broken-plugin-folder-finder.php   What counts as "broken" according to WordPress
  class-qwab-plugin-folder-remover.php         Deletion, re-verified at execution time
  modules/                      One class per module (registers its own hooks in register())
  storage/                      File-store/KV-store interfaces + atomic file transaction
  admin/                        Admin page controller, view models, the Qwab_View renderer
  safe-mode/                    The standalone recovery-console mu-plugin
templates/admin/                Display-only templates, rendered by Qwab_View (traversal-guarded)
assets/                         css/ and js/, one file per module where it needs one
languages/                      Ten bundled translations (see above)
HOOKS.md                        Full developer hook reference
SECURITY.md                     Vulnerability disclosure policy
readme.txt                      WordPress.org readme
```

Everything in this folder is what runs on a site — it is copied verbatim to the WordPress.org SVN.
The test suite, static analysis config, build scripts and the translation generator live in the
sibling `qaiyo-admin-booster-dev-tools/` folder instead:

```
../qaiyo-admin-booster-dev-tools/
  composer.json                 PHPUnit 9 + Brain Monkey + PHPStan (dev only)
  phpunit.xml.dist              Test suite + coverage scope
  phpstan.neon.dist             Level 5, WordPress stubs, no worker-memory trap
  tests/                        Unit tests (Unit/) + fixtures (Support/)
  build-zips.sh                 Release ZIP builder (full + WordPress.org variant)
  languages/                    i18n.py + translations.py — the translation source of truth
```

### Quality gates

Run from `../qaiyo-admin-booster-dev-tools/` before every release:

```bash
composer install
composer test        # PHPUnit 9 + Brain Monkey — no WordPress install needed
composer analyse      # PHPStan level 5 — must be zero errors
composer coverage     # line + branch + path coverage via Xdebug
python3 languages/i18n.py --check
```

From the workspace root:

```bash
phpcs --standard=WordPress --sniffs=WordPress.Security.EscapeOutput,\
WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput,\
WordPress.DB.PreparedSQL --ignore-annotations qaiyo-admin-booster
wp plugin check qaiyo-admin-booster --include-experimental
```

### Building a release ZIP

```bash
cd ../qaiyo-admin-booster-dev-tools
./build-zips.sh
```

Produces `../qaiyo-admin-booster.zip` (full, with translations — for self-hosted installs) and
`../qaiyo-admin-booster-wporg.zip` (no `.po`/`.mo`, since translate.wordpress.org serves those once
the plugin is listed). Both are verified free of hidden files and dev artifacts before writing.
For WordPress.org itself the plugin folder is copied to SVN directly — the ZIPs are for everything
else.

---

## Contributing

Bug reports and pull requests are welcome via [GitHub Issues](../../issues).

Please follow the existing coding style (4-space indentation, WPCS-compliant, PHPDoc on public
methods), add tests for new logic in `qaiyo-admin-booster-dev-tools/tests/Unit/`, and add an entry
to the `readme.txt` changelog under the next version.

---

## License

GPL-2.0-or-later. See <https://www.gnu.org/licenses/gpl-2.0.html>.

---

## Credits

Made by **[Qaiyo](https://qaiyo-plugins.com)**.
Part of the Qaiyo plugin family — a set of WordPress plugins that share a brand, a design system,
and a coordinated admin experience.

Contact: info@qaiyo-plugins.com
