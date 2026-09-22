# Qaiyo Admin Booster — integration hooks

Public extension points other plugins can use. Everything here is a plain
WordPress action/filter — **no shared library, no bundled package, no load-order
requirement**. Subscribing to a hook that never fires is harmless, so a sibling
plugin can ship this integration whether or not Admin Booster is installed.

---

## `qwab_ecosystem_widgets` (filter)

Report a summary card to the **Qaiyo ecosystem** dashboard widget.

Admin Booster collects every reported card and renders them in a single
dashboard widget, so each Qaiyo plugin does not have to add its own widget and
clutter the dashboard. If no plugin reports anything, the widget is not
registered at all.

### Registering a card

```php
add_filter( 'qwab_ecosystem_widgets', function ( $widgets ) {
    $widgets['qsve_videos'] = array(
        // Required.
        'title'      => __( 'Videos', 'qaiyo-social-video-embed' ),
        'render'     => array( 'Qsve_Dashboard_Card', 'render' ), // any callable, echoes HTML

        // Optional.
        'plugin'     => __( 'Social Video Embed', 'qaiyo-social-video-embed' ),
        'icon'       => 'dashicons-video-alt3',   // dashicon class
        'url'        => admin_url( 'admin.php?page=qaiyo-social-video-embed' ),
        'capability' => 'edit_posts',             // card is hidden without it
        'priority'   => 10,                       // lower renders first
    );
    return $widgets;
} );
```

### Field reference

| Key | Required | Notes |
|---|---|---|
| `title` | yes | Card heading. Escaped by the host. |
| `render` | yes | Any `callable`. **Echoes** the card body. Must not return. |
| `plugin` | no | Small badge showing which plugin the card came from. |
| `icon` | no | Dashicon class. Sanitised with `sanitize_html_class()`. Defaults to `dashicons-marker`. |
| `url` | no | Adds an "Open →" link under the card. |
| `capability` | no | The card is skipped unless `current_user_can()` passes. |
| `priority` | no | Integer, default `10`. Ties are broken alphabetically by title. |
| `slug` | no* | The reporting plugin's WordPress.org slug. **Required** to appear in the Qaiyo ecosystem panel (see below); optional for the dashboard widget. |
| `locked` | no | `true` if the deeper content of this card lives in the reporting plugin's own Pro. Adds a "More detail in … Pro" link. |
| `upgrade` | no | Where `locked` should link to. Ignored unless `locked` is `true`. |
| `covers` | no | Array of other plugin slugs whose numbers this card already includes. The panel then draws no separate (or ghost) card for them. Use it for a **combined** card — see below. |

### Combined cards (`covers`)

Two plugins whose data belongs together can present a single card instead of two.
The plugin that renders the combined card lists the other one in `covers`, and the
panel skips that slug:

```php
$widgets['qaiyo_clean_gallery_media'] = array(
    'slug'   => 'qaiyo-clean-gallery',
    'title'  => $video_plugin_active
        ? __( 'Media statistics', 'qaiyo-clean-gallery' )   // galleries + videos
        : __( 'Galleries', 'qaiyo-clean-gallery' ),         // galleries only
    'render' => array( 'Qaiyo_Clean_Gallery_Ecosystem', 'render_card' ),
    'covers' => $video_plugin_active ? array( 'qaiyo-social-video-embed' ) : array(),
);
```

So with both plugins running the panel shows one **Media statistics** card; with
only one of them running, that one reports its own card and the other appears as
a ghost. Only claim `covers` when your card genuinely shows the other plugin's
numbers — and only while that plugin is actually active.

### Rules for the `render` callback

- **Escape your own output.** The host cannot escape it, because the body is
  deliberately arbitrary markup — exactly like a normal
  `wp_add_dashboard_widget()` callback.
- **Check your own capabilities** for the data you show (or declare
  `capability` above).
- **Keep it cheap.** It runs on every dashboard load. Cache expensive queries in
  a transient.
- Errors are contained: if the callback throws, only that one card is replaced
  with a short error notice — the rest of the dashboard keeps working.

### What the host guarantees

- A card missing `title` or a non-callable `render` is silently skipped.
- Nothing is rendered for plugins that are not installed or not active — a card
  only exists if a running plugin reported it.
- The widget is a native WordPress dashboard widget, so users can collapse,
  reorder or hide it through Screen Options.

---

## The Qaiyo ecosystem panel

The same registration also feeds a panel on the Admin Booster settings page
(**Qaiyo ecosystem** tab), which shows one card per Qaiyo plugin in three states:

| State | When | What is shown |
|---|---|---|
| **Active** | The plugin is running and reported a card | The card's real output, via `render` |
| **Locked** | Reported with `'locked' => true` | The real free output plus a "More detail in … Pro" link to `upgrade` |
| **Ghost** | The plugin is not installed or not active | A one-line description of what its card would show, plus a "Details" link to its WordPress.org page |

To take part, add `slug` to your registration so the panel knows which catalog
entry your card fills:

```php
$widgets['qaiyte_new_reviews'] = array(
    'slug'    => 'qaiyo-testimonials',      // matches the WordPress.org slug
    'title'   => __( 'New reviews', 'qaiyo-testimonials' ),
    'render'  => array( 'Qaiyte_Eco_Card', 'render' ),
    'url'     => admin_url( 'edit-comments.php' ),

    // Only if the richer version of this card is a Pro feature of YOUR plugin:
    'locked'  => ! qaiyte_is_pro(),
    'upgrade' => 'https://qaiyo-plugins.com',
);
```

Ghost cards never carry an install button — only a link to the plugin's
WordPress.org page, opened in a new tab.

### Rolling the panel into another Qaiyo plugin

The panel is a drop-in (`includes/class-qwab-ecosystem-panel.php`) that is
**copied** into each plugin and renamed to that plugin's own prefix — class
name, filter name, CSS handle and text domain. Do not share one brand-level
`Qaiyo_*` copy between plugins: WordPress.org requires every identifier and
declared hook name to carry the individual plugin's prefix.

Each plugin then declares its own filter (`{prefix}_ecosystem_widgets`) and every
sibling subscribes to all of them:

```php
foreach ( array( 'qwab', 'qaiyte', 'qsab', 'qwps', 'qsve', 'qaiyo_clean_gallery' ) as $host ) {
    add_filter( $host . '_ecosystem_widgets', array( __CLASS__, 'report_card' ) );
}
```

Declaring only your own filter and subscribing to everyone else's keeps every
plugin prefix-clean while still letting them all see each other.

---

## Why there is no shared "bridge" library

An earlier design used a shared, brand-namespaced runtime (`Qaiyo_Bridge`,
`qaiyo_is_active()`, a version-guarded singleton bundled into every plugin).
That was dropped on purpose:

- WordPress.org requires every class, function, constant, global and **hook name
  a plugin declares** to carry that plugin's own prefix. A brand-level
  `Qaiyo_*` namespace shared across plugins fails that check — this plugin
  already had to rename a shared drop-in for exactly that reason.
- The version-guard pattern needs an identical class name in every plugin, which
  is fundamentally incompatible with per-plugin prefixes.

Host-owned hooks avoid the conflict completely: the host declares
`qwab_ecosystem_widgets` under its own prefix, and siblings merely `add_filter()`
to it. Hooking into someone else's hook is always allowed and never flagged.

---

## Update notification hooks

The `update_notifications` module emails every administrator a daily or weekly
digest of the plugin, theme and WordPress updates that are waiting. The whole
pipeline is filterable so an add-on can retarget, annotate or reroute it
without the free plugin carrying any of that logic.

The free module reads the three live update transients (`update_plugins`,
`update_themes`, `update_core`) at send time, so an update that was already
installed before the digest ran is correctly never reported. Anti-spam is the
`qwab_update_notified` option — an item key → already-notified version map, so
the same version is never announced twice even though WordPress rewrites those
transients roughly twice a day.

Each item in `$items` is an array:

| Key | Meaning |
|---|---|
| `type` | `core`, `plugin` or `theme` |
| `key` | stable identity — `core`, `plugin:dir/file.php`, `theme:stylesheet` |
| `name` | display name |
| `current` | installed version |
| `new` | available version |
| `url` | details URL from the update API, or `''` |
| `security` | *optional* — set truthy to get a “Security release” flag in the email |
| `excerpt` | *optional* — short text rendered under the item (e.g. a changelog slice) |

### `qwab_update_notification_items` (filter)

```php
add_filter( 'qwab_update_notification_items', function ( $items ) {
    foreach ( $items as $i => $item ) {
        // Only report the plugins the user actually watches.
        if ( 'plugin' === $item['type'] && ! my_is_watched( $item['key'] ) ) {
            unset( $items[ $i ] );
            continue;
        }
        // Annotate a security release.
        if ( my_is_security_release( $item ) ) {
            $items[ $i ]['security'] = true;
            $items[ $i ]['excerpt']  = my_changelog_excerpt( $item );
        }
    }
    return $items;
} );
```

### `qwab_update_notification_frequencies` (filter)

Adds choices to the frequency dropdown. A key that is **not** a real WordPress
cron schedule (see `wp_get_schedules()`) switches the free digest cron off
entirely — sending is then the add-on's job, via the queued action below.

```php
add_filter( 'qwab_update_notification_frequencies', function ( $f ) {
    return array( 'immediate' => __( 'As soon as an update appears', 'my-addon' ) ) + $f;
} );
```

### Sending immediately instead of on a schedule

The free plugin deliberately does **not** listen to WordPress' own update
transients. WordPress.org's Plugin Check scans plugin source with a plain text
regex for the plugins update-transient name and reports an error on a match —
even for code that only passively listens — so the free module stays clear of
it and sends purely from cron.

An add-on can listen to core's generic `set_site_transient` action, which
receives the transient name (`update_plugins`, `update_themes` or
`update_core`), then call the public item builder:

```php
add_action( 'set_site_transient', function ( $transient ) {
    if ( 'update_plugins' !== $transient || 'immediate' !== my_frequency() ) {
        return;
    }
    $items = Qwab_Module_Update_Notifications::current_items();
    if ( $items ) {
        my_send_now( $items );
    }
} );
```

`current_items()` applies `qwab_update_notification_items` for you, and
`Qwab_Module_Update_Notifications::notified_map()` gives you the item key →
already-notified version map so you can avoid repeats the same way the digest
does. Note that these core actions fire on every update check (roughly twice a
day), so do your own cheap bail-out first, as in the example above.

Registering a frequency that is not a real cron schedule (see
`qwab_update_notification_frequencies` above) switches the free digest cron off,
leaving sending entirely to the add-on.

### `qwab_update_notification_recipients` (filter)

```php
add_filter( 'qwab_update_notification_recipients', function ( $emails ) {
    return array( 'ops@example.com' );
} );
```

### `qwab_update_notification_subject` / `qwab_update_notification_message` (filters)

Both receive `$items` as the second argument. The message is HTML.

### `qwab_update_notification_send_email` (filter)

Return `false` to suppress the email — e.g. when the add-on only wants to post
to a webhook. The items are still recorded as notified, so the digest will not
retry them.

### `qwab_update_notification_sent` (action)

```php
add_action( 'qwab_update_notification_sent', function ( $items, $sent, $failed ) {
    my_post_to_slack( $items );
}, 10, 3 );
```

**Custom sender:** deliberately *not* implemented in the free plugin, because a
`wp_mail_from` filter is global and would rewrite the sender of every email the
site sends. An add-on should add those filters around its own send only, and
remove them again afterwards.

## `qwab_nav_visibility_breakpoint` (filter)

The **Menu item visibility** module lets each classic menu item be set to
"Always" / "Mobile only" / "Desktop only" (Appearance → Menus, a per-item
field next to CSS Classes). The free plugin hides items purely with CSS media
queries at a fixed 768px breakpoint.

An add-on can offer a configurable breakpoint:

```php
add_filter( 'qwab_nav_visibility_breakpoint', function () {
    return (int) get_option( 'my_addon_nav_breakpoint', 768 );
} );
```

Return an integer between 320 and 2000; anything outside that range is
ignored and the free plugin falls back to 768. The filter is read once per
page load (front-end only, in `wp_head`).
