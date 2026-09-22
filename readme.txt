=== Qaiyo Admin Booster ===
Contributors: qaiyo
Tags: admin, usability, uploads, gutenberg, collections
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.6
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fixes the annoying wp-admin UX/UI rough edges that cost you time every day — without replacing the WordPress admin you already know.

== Description ==

Qaiyo Admin Booster removes the small daily frictions in wp-admin. It does not redesign the WordPress dashboard — it just fixes the things that take three clicks when they should take one.

Every feature is a module you can turn on or off, and everything is on by default.

**Page Collections** — Group your pages and posts into visual collections. The plugin auto-detects the obvious ones (Homepage, Legal pages, WooCommerce system pages), and you can create your own collections (Services, Landing Pages, etc.) and assign content to them. A colored badge column and a filter dropdown appear on the Pages and Posts list screens.

**Page Filter** — See at a glance which page is the homepage, which pages are orphans, which are not in any menu, which are set to noindex, and what status each one has. A filter dropdown above the list lets you isolate any of these in one click.

**1-click plugin upload** — “Add Plugin” opens the upload screen directly instead of the browse tabs, so installing a plugin ZIP is one step. The browse and search tabs stay available on top.

**Gutenberg UX** — Keep the left block inserter open by default, and keep the editor out of fullscreen so the admin sidebar stays visible.

**Quick admin navigation** — New Page / New Post / New Product (and any custom post type) shortcuts in the admin bar.

**Larger list views** — Raise the default number of items per page in admin list tables (respecting any Screen Option you set yourself).

**File handling** — Raise the maximum upload size straight from the admin — even without cPanel, FTP or php.ini access — enable WebP / AVIF / SVG, add your own custom file types, get a warning when you allow risky executable types, and replace the cryptic “file type not permitted” message with a clear explanation. Optionally clean up uploaded filenames automatically (spaces, accents and uppercase become URL-friendly slugs).

**Tidy admin notices** — Collapse the stacked admin notices (plugin promos, nags, update banners) into a small counter tray so they stop pushing your content down. Nothing is deleted — open the tray to read them, dismiss buttons still work.

**Dashboard cleanup** — Hide the default dashboard widgets you never look at (WordPress News, Quick Draft, Activity, At a Glance, Site Health, Welcome panel).

**Scheduled countdown** — Scheduled posts show “Publishing in 3 days” right next to the title, so you never have to do the date math.

**Sticky table headers** — Column headers stay pinned to the top while you scroll long list tables.

**Last edited column** — See who last edited each item and how long ago, right in the list.

**Quick status switch** — Publish a draft or switch a published item back to draft straight from the list, without opening the editor.

**Bulk create** — Create many pages or posts at once from a simple list of titles, one per line. Indent a line with a Tab (or two spaces) to nest it as a child page, so you can scaffold a whole page tree in seconds. Choose the content type, the status (draft, published, pending, private) and an optional top-level parent.

**Update center widget** — A dashboard widget that lists every available plugin and theme update in one place, with an update button on each row and an “Update all” button. Runs the update server-side, so it works even where the built-in one-click updater gets stuck on filesystem credentials.

**Comments widget** — A dashboard widget showing your comment counts (approved, pending, spam, trash) and the latest comments, with one-click or bulk trashing right from the widget.

**Dashboard greeting** — A friendly personal greeting at the top of the main dashboard, so you land on a welcoming screen every time you log in.

**Add to menu from the editor** — WordPress makes you leave the editor, go to Appearance → Menus, find your page in a list and add it by hand. This module puts a **Menus** panel in the editor sidebar for pages, posts and any other content type that supports menus: pick a menu, choose whether it goes in at the top level or under an existing item as a submenu, and click once. Changed your mind? The panel shows where the content currently sits and lets you move it between the top level and any submenu at any time — your custom menu label, CSS classes and other settings are preserved. If the site has no menu yet, you can create the first one right there — optionally assigning it to a theme location — and the content is added to it in the same step. Items already in a menu are listed with a one-click Remove, and removing a parent moves its children up instead of orphaning them.

**Qaiyo ecosystem widget** — If you run more than one Qaiyo plugin, each of them can report a small summary card, and this module gathers them into a single dashboard widget instead of every plugin adding its own. Nothing is shown for plugins you do not have: the widget only appears when an installed, active plugin actually reports something, and it disappears again if you deactivate them. It is a normal dashboard widget, so you can collapse, move or hide it through Screen Options. Developers: see HOOKS.md for the one-filter contract.

**Qaiyo ecosystem panel** — A tab on the settings page that shows one card per Qaiyo plugin. Plugins you already run show their live summary (pending reviews, today's bookings, latest performance score and so on); plugins you do not have show a single line describing what their card would add, with a link to their WordPress.org page. No install buttons, no popups — just a quiet overview of what the family offers.

**Separate table for pending updates** — On the Plugins screen, plugins with an available update normally stay in their alphabetical place, so you have to scan the whole list to find them. This module lifts them out into a table of their own at the top, under an “Updates” heading, and lists every other plugin in a separate table below it. Both tables are fully functional: the update buttons, the row actions, the bulk actions and a select-all checkbox all keep working.

**Update notification emails** — WordPress checks for updates in the background and then says nothing, so an available update sits there until you happen to log in. This module emails every administrator a summary of the plugin, theme and WordPress updates that are waiting — once a day or once a week, your choice. It is a digest, not a firehose: WordPress rewrites its update data several times a day, and the module remembers which version of which item it has already told you about, so you get one email when something genuinely changes and nothing at all when there is nothing new. Pick whether you want to hear about plugins, themes, WordPress core or any combination. Delivery uses WordPress' own mail, so an SMTP plugin or your host's mail configuration is picked up automatically — and because mail can fail silently, there is a “Send test email” button that tells you straight away whether mail actually leaves your site.

**Safe Mode link** — When a plugin or theme update breaks the site, WordPress emails a one-time recovery link: at most once a day, never on multisite, and if that email is lost the only way back in is FTP or your hosting panel. Safe Mode gives you a link you save *before* anything goes wrong. Open it, log in as an administrator, and a small recovery console loads with every plugin switched off and a default theme standing in for yours — deactivate the plugin that broke the site, exit Safe Mode, done. It keeps working even if Admin Booster itself is the broken plugin. Because it is effectively a master key, it is locked down hard: the key never reaches server logs, the database only holds a fingerprint of it, the link alone is not enough to get in, the console cannot install anything, edit files or touch users, every use is emailed to all administrators, and a used link retires itself once the site works again. It is off by default.

**Update crash protection** — WordPress keeps a backup of the previous version during every plugin and theme update, but it only checks whether the site still loads (and puts that backup back if not) for the updates it runs by itself in the background. Click “Update now”, run WP-CLI, or let ManageWP, MainWP or a similar dashboard update your sites, and a broken release goes straight to your visitors as a critical error. This module closes that gap: right after every update, whoever started it, it loads the site the same way WordPress does, and if the update caused a fatal error it restores the previous version on the spot and emails the administrators what happened. Nothing is switched off, so a pair like a free plugin and its paid add-on keeps working together on the old version until both are compatible. It is careful not to do harm: it only rolls back when the site demonstrably worked before the update and fails after it, and if your server cannot reach itself it tells you so instead of guessing. It is on by default.

**Menu item visibility** — A "Request a quote" button in the header does not fit on a phone, so it belongs in the hamburger menu instead — but putting it there normally means building and maintaining a second menu just for mobile. This module adds a **Visibility** setting to every menu item on the Appearance → Menus screen: Always, Mobile only, or Desktop only. Put everything in one menu, mark the button "Mobile only", and it appears in the mobile menu and nowhere else. Items are hidden with a CSS media query at 768px, never by dropping them from the HTML, so the menu structure is identical in every view and page caching is unaffected. Works with the classic menus that Bricks, Elementor and classic themes render; the block-based Navigation block draws its own markup and is not affected.

**Explore Qaiyo** — A built-in directory of the whole Qaiyo plugin family, with one-click links and an update notice when a newer version of a plugin you already have is released.

= Pro =

Qaiyo Admin Booster Pro adds smart collection rules (auto-assign by URL pattern / template / regex), pinned pages, saved filter views, bulk noindex & menu actions, per-role upload rules, automatic SVG sanitization, an admin menu editor, inline list-table editing, a login screen customizer, an activity log, admin dark mode, a revision cleaner, a core update bridge that lets a site stuck on an old WordPress step up to an intermediate version its server can still run, and targeted update alerts (choose exactly which plugins and themes you hear about, get security releases flagged, send alerts instantly or to a Slack / Discord webhook). Learn more at https://qaiyo-plugins.com

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the ZIP via Plugins → Add Plugin → Upload Plugin.
2. Activate the plugin.
3. Go to **Admin Booster** in the wp-admin sidebar to configure the modules.

== Frequently Asked Questions ==

= Does this replace the WordPress admin? =

No. It only fixes specific UX/UI rough edges. The standard WordPress admin stays exactly as it is.

= Is enabling SVG safe? =

SVG files can contain scripts, so only enable SVG uploads if you trust everyone who can upload media. The Pro version adds automatic SVG sanitization that strips scripts on upload.

= Can it really raise the upload limit without cPanel or FTP? =

On most hosts, yes. The limit lives in PHP's `upload_max_filesize` and `post_max_size`, which cannot be changed while WordPress runs — not with `ini_set()` and not from wp-config.php, because PHP has already received the upload by then. Turn on "Raise the server limit too" and Admin Booster writes the two values into the files your server reads before each request: `.user.ini` in the WordPress folder on PHP-FPM / FastCGI hosts (applies within about 5 minutes), `.htaccess` on Apache mod_php, and both on LiteSpeed / CloudLinux LSAPI hosts, where it depends on the host which one is honoured. The lines are clearly marked, nothing else in the file is touched, and switching the option off or deleting the plugin removes them.

The settings page reads the limit PHP really applies and tells you whether it worked. If a host has locked the limit (`php_admin_value`), Admin Booster detects it and says so instead of writing anything. If the host simply does not read these files, the page shows a short "Server details" list you can send to your host.

= Does Update crash protection replace backups? =

No. It restores only the plugin or theme files that the update just replaced, using the copy WordPress itself makes during the update. It cannot undo database changes an update makes, and it cannot help if the problem only appears later (for example on a specific page or after a cron job runs). It also needs the server to be able to load its own pages ("loopback requests" — Site Health tests this); if it cannot, the settings page warns you. Keep regular backups.

= An update broke my site and I cannot log in. What now? =

If you turned on the Safe Mode link beforehand, open the link you saved, log in, deactivate the plugin you just updated under Plugins (or switch themes under Appearance), then click "Exit Safe Mode". If you did not, WordPress may have emailed the site's admin address a recovery link; otherwise you need FTP, SSH or your hosting file manager to rename that plugin's folder.

= Is the Safe Mode link a security risk? =

It is built so that a leaked link is not enough to take over the site:

* **The key never reaches the server's logs.** It sits after the `#` in the link, which browsers never send to the server, so it cannot end up in web server, CDN or proxy logs, or in a Referer header.
* **A stolen database does not reveal it.** Only a fingerprint is stored, keyed with the security keys in wp-config.php, so a database leak neither reveals the link nor lets anyone plant their own.
* **The link alone does not log anyone in.** Only administrators can sign in, always with their password (an existing login cookie is not accepted), and after 5 failed passwords logins lock for 15 minutes — security and two-factor plugins are off in Safe Mode, so this limit is built in.
* **It cannot be used to plant a backdoor.** Safe Mode is a recovery console: Dashboard, Plugins and Themes only. Installing or uploading plugins and themes, editing files, and creating or changing users are blocked.
* **It cannot be used quietly.** Every entry and every Safe Mode login is emailed to all administrators, logged with IP address and browser, and shown to administrators as a notice until dismissed.
* **A used link does not stay valid.** It is retired automatically as soon as the admin loads normally again — but not before, so you cannot lock yourself out if the first fix did not work.
* The Safe Mode session lasts one hour and is tied to the browser; on HTTPS sites the key is only accepted over HTTPS; you can optionally restrict it to fixed IP addresses; and "Create a new link" instantly invalidates the old link and every open session. The public site is never affected.

= Will it work with my SEO plugin? =

The noindex detection recognizes Yoast SEO, Rank Math and SEOPress.

= How do I report a security issue? =

Please report security vulnerabilities privately by email to info@qaiyo-plugins.com rather than in the public support forum. See the SECURITY.md file included with the plugin for details. We aim to acknowledge reports within 72 hours.

== Screenshots ==

1. The Admin Booster settings page with all modules.
2. Page Collections and Page status columns on the Pages list.
3. File handling: upload size and allowed file types.

== Changelog ==

= 1.4.6 =
* Internal quality pass after an external code review — no behaviour changes, but the plugin is stricter about its own correctness.
* Fixed: the Free↔Pro "unlocked modules" filter was called with a different number of arguments in two places, which could crash an add-on that hooked it with two parameters. There is now a single source of truth for it.
* Fixed: uninstall left data behind — the update-protection log, the "could not verify" flag, the server-limit status and two caches are now removed, and on multisite every site in the network is cleaned instead of only the current one.
* Fixed: "Everything is on by default" on the Modules tab was not accurate — the Safe Mode link is deliberately off.
* All output now goes through an escaping function on its own line, with no suppression comments; inline card icons are filtered through an allowlist.
* Every module now registers its hooks in an explicit method instead of its constructor, so the classes can be created without side effects (testability).
* Parameter and return types added throughout. Hook callbacks deliberately keep untyped parameters: WordPress and other plugins pass those values, and a strict type would turn someone else's sloppy value into a fatal error on your site.

= 1.4.5 =
* New module — **Menu item visibility**: every menu item on the Appearance → Menus screen gets a "Visibility" setting — Always, Mobile only, or Desktop only. The usual reason: a header button like "Request a quote" does not fit on mobile and belongs in the hamburger menu instead, which until now meant building and maintaining a second, mobile-only menu.
* The item is hidden with a CSS media query at 768px, never by leaving it out of the HTML, so the menu structure stays the same in every view and page caching is unaffected. (Pro can set the breakpoint to match your theme.)
* Works with classic menus rendered by `wp_nav_menu()` — the same menus Bricks, Elementor and classic themes use. The block-based Navigation block draws its own markup and is not affected.

= 1.4.4 =
* New module — **Safe Mode link**: a way back into wp-admin when a plugin or theme update breaks the site, without FTP or a hosting panel. WordPress' own Recovery Mode only works through a link it emails once a day after the crash — and not at all on multisite — so if that email is lost you are locked out. Safe Mode gives you a link to save in advance instead.
* Opening the link and logging in as an administrator loads a recovery console with every plugin switched off and a default theme in place of yours. The Plugins screen still shows the real active plugins, so deactivating the broken one leaves every other plugin exactly as it was. Visitors keep seeing the normal site.
* It runs as a small, self-contained file in `wp-content/mu-plugins`, so it keeps working even when Admin Booster itself is the plugin that broke.
* Hardened as a master key: the key lives after the `#` and never reaches server, CDN or proxy logs; only a fingerprint keyed with the wp-config.php security keys is stored; only administrators can log in, always with a password, with a built-in 5-attempt lockout; the console blocks installing, uploading, file editing and user management; every use is emailed to all administrators and logged; a used link retires itself once the site works again; one-hour, browser-bound sessions; HTTPS-only key entry on HTTPS sites; optional IP restriction.
* The link is shown only once, when it is created. Off by default; turning it off, deactivating or deleting the plugin removes the file and the link.
* New module — **Update crash protection**: after every plugin or theme update — a manual click, a bulk update, WP-CLI, or a remote dashboard such as ManageWP or MainWP — the site is loaded once, and if the update caused a fatal error the previous version is restored automatically. WordPress does this only for its own background auto-updates.
* Only rolls back when the site worked right before the update and fails right after it; an update on an already broken site is left alone.
* Every intervention is logged under Admin Booster → Update crash protection and emailed to all administrators, including when an automatic restore did not succeed (with the next steps).
* If the server blocks loopback requests, the protection cannot check anything; it says so on the settings page instead of rolling back blindly.
* WordPress' own background auto-updates are left to WordPress, which already runs the same check for them.

= 1.4.3 =
* New: **raise the server upload limit from the admin**, without cPanel, FTP or php.ini access. PHP's `upload_max_filesize` / `post_max_size` cannot be changed at runtime (not even from wp-config.php), so the new "Raise the server limit too" option writes them into `.user.ini` on PHP-FPM / FastCGI hosts, into `.htaccess` on Apache mod_php, and into both on LiteSpeed / CloudLinux LSAPI hosts (common with DirectAdmin and cPanel), where it depends on the host which one is honoured. Existing lines in those files are kept, the `.htaccess` lines are wrapped so a later server change cannot take the site down, and switching the option off or uninstalling removes them again.
* The File handling tab now shows the limit PHP really applies and whether the raise took effect or is still pending. A host-locked limit (`php_admin_value`) is detected up front and reported instead of written, and if a host does not read the files at all, a "Server details" list shows exactly how PHP runs there, ready to send to the host.
* Fixed: setting an upload size larger than the server allowed made uploads fail with a confusing "Unexpected response from the server" error, because WordPress advertised the higher limit while PHP silently discarded the file. The WordPress-side limit is now never higher than what the server really accepts, so oversized files get WordPress' clear "exceeds the maximum upload size" message instead.

= 1.4.2 =
* New module — **Update notification emails**. Every administrator gets an emailed summary of the plugin, theme and WordPress updates that are waiting, once a day or once a week. Choose which of the three you want to hear about.
* It is a real digest, not one email per update check: the module records which version of which item it has already announced, so the same release is never reported twice, and no email is sent at all when nothing has changed.
* Updates that were already installed before the summary went out are correctly left out, and when a plugin moves twice between two digests you are told about the version that is actually available now.
* Added a "Send test email" button, because WordPress can only hand mail over to your server or SMTP plugin and cannot guarantee delivery. If a summary could not be handed over, the settings page now says so.
* Delivery uses WordPress' own `wp_mail()`, so an SMTP plugin or a host-configured mail setup is used automatically — there is nothing to configure here.
* Fixed: two strings ("Pending" in the comments widget, and the friendly "file type not allowed" upload message) were never included in the bundled translations and stayed English in every language.
* Developers: the whole notification pipeline is filterable — recipients, item list, subject, message, frequency choices and whether email is sent at all — so an add-on can retarget or reroute it. See the bundled HOOKS.md.

= 1.4.1 =
* New module — **Separate table for pending updates**. On the Plugins screen, plugins with an available update are pulled out of the alphabetical list into a table of their own at the top, under an "Updates" heading, with every other plugin in a separate table below it. Nothing changes when every plugin is already up to date — no empty heading or empty table is ever shown.
* The updates table is a real, working list table: each row keeps its update button and row actions, it stays inside the bulk-action form so "Update" and the other bulk actions apply to it, and it has its own select-all checkbox.
* Fixed: the update-center's "check for updates" AJAX endpoint returned a hardcoded, untranslated "Forbidden" message when the current user lacked permission; it now uses a proper, translatable message.

= 1.4.0 =
* New — **Qaiyo ecosystem**. Other Qaiyo plugins can now report a summary card, and Admin Booster shows those cards in two places: a **Qaiyo ecosystem tab** on the settings page, and an optional **dashboard widget** that gathers them into one box instead of every plugin adding its own.
* The settings tab lists every Qaiyo plugin in one of three states: live data for the plugins you already run, a "More detail in … Pro" link where the richer version of a card is a Pro feature of that plugin, and a one-line description plus a WordPress.org link for the plugins you do not have.
* Cards for plugins you do not have carry no install button — only a "Details" link to the plugin's WordPress.org page, opened in a new tab.
* The dashboard widget is only registered when an installed, active plugin actually reports a card, so it never appears empty.
* The whole integration is a single documented filter (`qwab_ecosystem_widgets`) — no shared library, no bundled package and no load-order requirement, so a sibling plugin can ship its side whether or not Admin Booster is installed. The contract is documented in the bundled HOOKS.md.
* A card that fails is contained: only that card is replaced with a short notice, and the rest of the page keeps working.

= 1.3.0 =
* New module — **Add to menu from the editor**. A "Menus" panel now appears in the editor sidebar for pages, posts and any other content type that supports navigation menus, so you no longer have to save your work, leave the editor and hunt for the page in Appearance → Menus.
* Choose the menu and the level: add the content at the top level, or nest it under an existing top-level item as a submenu entry.
* The level can be changed at any time afterwards. The panel preselects the menu and the level the content currently sits at, and the button becomes "Update level" — moving an entry between the top level and a submenu keeps its custom menu label, CSS classes, link target and description intact.
* If the site has no navigation menu at all yet, you can create the first one straight from the panel — with an optional theme display location — and the content is added to it in the same step.
* Menus the content is already in are listed with a one-click Remove. Removing an item that has children moves those children up a level instead of leaving them orphaned and invisible.
* Duplicates are prevented, and on block themes without registered menu locations the panel explains that classic menus will not show up on their own.
* Fixed: the bundled translation files could contain a duplicate entry, which made them invalid for tools such as Loco Translate or msgfmt. The translation build now collapses duplicates.
* Re-checked against the released WordPress 7.1: no further changes were needed. The new panel is a classic meta box, which renders outside the editor iframe and is unaffected by the 7.1 iframe change.

= 1.2.0 =
* WordPress 7.1 ready. The post editor is now always served inside an iframe in WordPress 7.1; the Gutenberg module was reviewed against this and needs no workaround, because it only talks to the editor data store and never touches the editor canvas.
* Fixed: opening the block inserter now uses the current editor store API. The previous call has been deprecated since WordPress 6.5 and printed a deprecation warning in the browser console on every editor load. Older WordPress versions keep working through a fallback.
* Tested up to WordPress 7.1.

= 1.1.0 =
* New: Dashboard greeting — a friendly personal greeting at the top of the main WordPress dashboard (toggleable module).
* Performance: the Update center widget now caches the available-update list, so the dashboard no longer re-scans plugin and theme files on every load.
* Performance: faster Page Collections auto-detection on large Pages/Posts lists.
* Security: the optional filename cleanup now only affects real media uploads (no longer touches other filename handling), and custom upload file types can no longer enable risky executable or script extensions (php, js, html, css, etc.).
* Added a SECURITY.md with a vulnerability disclosure policy and security contact.
* Housekeeping: removed unused code and moved inline styles into stylesheets.

= 1.0.0 =
* Initial release: Page Collections, Page Filter, 1-click plugin upload, Gutenberg UX, quick add menu, larger list views and full file handling.
* Plus: tidy admin notices tray, dashboard cleanup, scheduled-post countdown, sticky list-table headers, last-edited column and quick status switching.
* Plus: bulk page/post creation (with Tab-indented child hierarchy), an update-center dashboard widget, a comments dashboard widget and the Explore Qaiyo plugin directory with update notices.

== Upgrade Notice ==

= 1.4.6 =
Internal quality and robustness pass after an external code review: complete uninstall (including multisite), stricter output escaping, and a fix for an add-on filter that could crash.

= 1.4.5 =
Adds Menu item visibility: show a menu item on mobile only or on desktop only, so a header button can live in the mobile menu without a second menu.

= 1.4.4 =
Adds Update crash protection — if a plugin or theme update (including one run by ManageWP, MainWP or WP-CLI) crashes the site, the previous version is restored automatically — and an optional Safe Mode link: a saved-in-advance way back into wp-admin.

= 1.4.3 =
Raise the server upload limit straight from the admin, without cPanel or FTP. Also fixes uploads failing with "Unexpected response from the server" when the upload size was set higher than the server allowed.

= 1.4.2 =
Adds emailed update notifications: a daily or weekly summary of waiting plugin, theme and WordPress updates for every administrator, with a test-email button. Also fixes two strings that were never translated.

= 1.4.1 =
Adds a module that lists plugins with a pending update in a separate "Updates" table at the top of the Plugins screen, with all the other plugins in their own table below.

= 1.4.0 =
Adds a Qaiyo ecosystem tab and dashboard widget showing what each Qaiyo plugin contributes — live data for the ones you run, a short description for the ones you do not.

= 1.3.0 =
Adds a Menus panel to the editor sidebar: put a page or post into a navigation menu — at the top level or as a submenu item — without leaving the editor.

= 1.2.0 =
WordPress 7.1 compatibility, and the block inserter no longer triggers a deprecation warning in the editor.

= 1.1.0 =
Adds a dashboard greeting, caches the update-center widget for faster dashboard loads, and hardens file-upload handling.

= 1.0.0 =
Initial release.
