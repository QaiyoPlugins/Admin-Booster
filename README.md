aiyo Admin Booster removes the small daily frictions in wp-admin. It does not redesign the WordPress dashboard — it just fixes the things that take three clicks when they should take one.

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
