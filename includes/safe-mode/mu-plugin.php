<?php
/**
 * Plugin Name: Qaiyo Admin Booster — Safe Mode
 * Description: Emergency access for when a plugin or theme update locks you out. Installed and managed by Qaiyo Admin Booster (Admin Booster → Safe Mode). Does nothing unless a valid Safe Mode key is used.
 * Author: Qaiyo
 * Author URI: https://qaiyo-plugins.com
 * License: GPL-2.0-or-later
 * Text Domain: qaiyo-admin-booster
 *
 * NE SZERKESZD: ezt a fájlt a Qaiyo Admin Booster írja és frissíti.
 *
 * MIÉRT MU-PLUGIN: a `wp-settings.php` az aktív pluginokat feltétel nélkül
 * betölti, a mu-pluginok viszont ELŐTTÜK futnak — csak innen lehet egy hibás
 * plugin elé állni. A WP saját Recovery Mode-ja csak e-mailes, naponta egyszer
 * küldött linkkel érhető el, és multisite-on nem is létezik.
 *
 * SZÁNDÉKOSAN ÖNÁLLÓ: a főplugin egyetlen osztályára sem támaszkodik, mert
 * akkor is működnie kell, ha éppen maga az Admin Booster a hibás plugin.
 *
 * BIZTONSÁGI MODELL (ez egy mesterkulcs, ennek megfelelően kezeljük):
 *  1. A kulcs az URL `#` részében van → a böngésző SOHA nem küldi el a
 *     szervernek, így nem kerül webszerver-, CDN- vagy proxy-naplóba, sem
 *     Referer fejlécbe. Egy apró oldal POST-tal adja át.
 *  2. Az adatbázisban csak a kulcs kulcsolt hash-e van; a kulcsolás a
 *     wp-config.php biztonsági sóiból jön → DB-szivárgásból nem visszafejthető,
 *     DB-írással nem hamisítható.
 *  3. Egy használat után a link visszavonódik, amint az oldal újra működik
 *     (ezt a főplugin végzi, amikor rendes módban betölt).
 *  4. Safe Mode-ban csak adminisztrátor léphet be, saját login-limiterrel
 *     (a biztonsági pluginok ilyenkor nem futnak).
 *  5. Szűkített helyreállító konzol: csak Vezérlőpult / Bővítmények /
 *     Megjelenés; telepítés, feltöltés, fájlszerkesztés, felhasználókezelés
 *     tiltva — link + jelszó birtokában se lehessen hátsó ajtót nyitni.
 *  6. Minden belépésről azonnali e-mail riasztás az adminoknak + napló.
 *  7. HTTPS-oldalon csak HTTPS-en fogad kulcsot; opcionális IP-korlátozás.
 *  8. A munkamenet-süti a böngészőhöz és az aktuális kulcshoz kötött, 1 órás.
 *
 * ⚠️ Az `active_plugins` opciót NEM írjuk át: a Bővítmények oldal be/ki gombja
 * olvas–módosít–ír ciklusban kezeli, és egy hamis üres listára írva az ÖSSZES
 * plugin aktiválása elveszne. Csak a `plugins_loaded` előtti betöltési
 * ablakban adunk vissza üres listát.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'QWAB_SAFE_MODE_LOADED' ) ) {
	return;
}
define( 'QWAB_SAFE_MODE_LOADED', true );

// phpcs:disable WordPress.Security.NonceVerification -- A titkos kulcs maga a hitelesítés (a belépéskor még semmilyen WP-munkamenet nincs); a kilépés csak a saját sütit törli.

// A normál forgalom 99,9%-án se adatbázis-hívás, se semmi más ne fusson.
if ( ! isset( $_GET['qwab_safe_mode'] ) && ! isset( $_GET['qwab_safe_mode_exit'] ) && ! isset( $_COOKIE['qwab_safe_mode'] ) ) {
	return;
}

// Feltételes blokk: így a PHP csak VÉGREHAJTÁSKOR deklarálja a függvényeket
// (a korai return után), és egy véletlen második példány sem okoz
// „Cannot redeclare" fatal hibát.
if ( ! function_exists( 'qwab_sm_get' ) ) :

/* -------------------------------------------------------------------------
 * Tárolás és kriptográfia
 * ---------------------------------------------------------------------- */

/**
 * Opció olvasása (multisite-on hálózati szinten).
 *
 * @param string $name    Név.
 * @param mixed  $default Alapérték.
 * @return mixed
 */
function qwab_sm_get( $name, $default = false ) {
	return is_multisite() ? get_site_option( $name, $default ) : get_option( $name, $default );
}

/**
 * Opció írása (autoload nélkül).
 *
 * @param string $name  Név.
 * @param mixed  $value Érték.
 */
function qwab_sm_set( $name, $value ) {
	if ( is_multisite() ) {
		update_site_option( $name, $value );
	} else {
		update_option( $name, $value, false );
	}
}

/**
 * A kulcsoláshoz használt titok a wp-config.php sóiból.
 *
 * A `wp_salt()` pluggable, itt még nem létezik — ugyanazt a forrást olvassuk:
 * a konstansokat, és ha azok hiányoznak vagy az alapértelmezett szövegek, a
 * WordPress által generált, adatbázisban tárolt értékeket.
 *
 * ⚠️ A főplugin `Qwab_Safe_Mode::site_key()` ugyanezt számolja — a kettőnek
 * bitre egyeznie kell.
 *
 * @return string '' ha nem állapítható meg.
 */
function qwab_sm_site_key() {
	$out = '';
	foreach ( array( 'SECURE_AUTH_KEY' => 'secure_auth_key', 'SECURE_AUTH_SALT' => 'secure_auth_salt' ) as $const => $option ) {
		$value = defined( $const ) ? (string) constant( $const ) : '';
		if ( strlen( $value ) < 32 || false !== stripos( $value, 'put your unique phrase here' ) ) {
			$value = (string) get_site_option( $option, '' );
		}
		$out .= $value;
	}

	return strlen( $out ) >= 64 ? $out : '';
}

/**
 * A tárolt kulcs-hash.
 *
 * @return string 64 hex karakter vagy ''.
 */
function qwab_sm_stored_hash() {
	$hash = qwab_sm_get( 'qwab_safe_mode_hash', '' );

	return ( is_string( $hash ) && 64 === strlen( $hash ) ) ? $hash : '';
}

/**
 * Kulcs → kulcsolt hash.
 *
 * @param string $key      A felhasználó által megadott kulcs.
 * @param string $site_key Webhely-kulcs.
 * @return string
 */
function qwab_sm_hash_key( $key, $site_key ) {
	return hash_hmac( 'sha256', $key, 'qwab-safe-mode-key|' . $site_key );
}

/**
 * A kliens IP-címe. Szándékosan CSAK a REMOTE_ADDR: az X-Forwarded-For
 * hamisítható, egy biztonsági döntés nem épülhet rá.
 *
 * @return string
 */
function qwab_sm_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

	return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
}

/**
 * Engedélyezett-e az IP (ha van beállítva korlátozás).
 *
 * @return bool
 */
function qwab_sm_ip_allowed() {
	$list = qwab_sm_get( 'qwab_safe_mode_ips', array() );
	if ( ! is_array( $list ) || ! $list ) {
		return true;
	}

	$ip  = qwab_sm_ip();
	$bin = '' !== $ip ? inet_pton( $ip ) : false;
	if ( false === $bin ) {
		return false;
	}

	foreach ( $list as $rule ) {
		$parts = explode( '/', (string) $rule, 2 );
		$net   = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Érvénytelen bejegyzés esetén egyszerűen nem illeszkedik.
		if ( false === $net || strlen( $net ) !== strlen( $bin ) ) {
			continue;
		}
		$bits = isset( $parts[1] ) ? (int) $parts[1] : strlen( $bin ) * 8;
		$bits = max( 0, min( strlen( $bin ) * 8, $bits ) );

		$bytes = intdiv( $bits, 8 );
		if ( substr( $bin, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
			continue;
		}
		$rest = $bits % 8;
		if ( $rest ) {
			$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
			if ( ( $bin[ $bytes ] & $mask ) !== ( $net[ $bytes ] & $mask ) ) {
				continue;
			}
		}
		return true;
	}

	return false;
}

/**
 * Napló (utolsó 30 esemény).
 *
 * @param string $type Esemény típusa.
 * @param string $user Felhasználónév (ha van).
 */
function qwab_sm_log( $type, $user = '' ) {
	$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 180 ) : '';
	$log = qwab_sm_get( 'qwab_safe_mode_log', array() );
	$log = is_array( $log ) ? $log : array();

	array_unshift(
		$log,
		array(
			'time' => time(),
			'type' => $type,
			'ip'   => qwab_sm_ip(),
			'ua'   => $ua,
			'user' => substr( sanitize_user( $user ), 0, 60 ),
		)
	);
	qwab_sm_set( 'qwab_safe_mode_log', array_slice( $log, 0, 30 ) );
}

/**
 * Sikertelen próbálkozás naplózása — IP-nként 10 percenként legfeljebb egyszer,
 * hogy egy támadó ne tudjon vele adatbázis-írásokat generálni.
 *
 * @param string $type Esemény.
 * @param string $user Felhasználónév.
 */
function qwab_sm_log_throttled( $type, $user = '' ) {
	$flag = 'qwab_sm_t_' . md5( $type . '|' . qwab_sm_ip() );
	if ( get_transient( $flag ) ) {
		return;
	}
	set_transient( $flag, 1, 10 * MINUTE_IN_SECONDS );
	qwab_sm_log( $type, $user );
}

/**
 * Riasztás felvétele (a következő, WordPress-szel teljesen betöltött kérésnél
 * megy ki e-mailben — itt a `wp_mail()` még nem létezik).
 *
 * @param string $type Esemény.
 * @param string $user Felhasználónév.
 */
function qwab_sm_queue_alert( $type, $user = '' ) {
	$queue   = qwab_sm_get( 'qwab_safe_mode_alerts', array() );
	$queue   = is_array( $queue ) ? $queue : array();
	$queue[] = array(
		'time' => time(),
		'type' => $type,
		'ip'   => qwab_sm_ip(),
		'user' => $user,
	);
	qwab_sm_set( 'qwab_safe_mode_alerts', array_slice( $queue, -10 ) );
}

/* -------------------------------------------------------------------------
 * Munkamenet
 * ---------------------------------------------------------------------- */

/**
 * A böngésző ujjlenyomata (a süti ehhez kötött).
 *
 * @return string
 */
function qwab_sm_ua_hash() {
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Csak hash-elve használjuk.

	return hash( 'sha256', $ua );
}

/**
 * Süti-aláírás. A tárolt hash része a kulcsnak, így egy új link (vagy a link
 * visszavonása) azonnal minden nyitott munkamenetet érvénytelenít.
 *
 * @param string $payload  Aláírandó.
 * @param string $hash     Tárolt kulcs-hash.
 * @param string $site_key Webhely-kulcs.
 * @return string
 */
function qwab_sm_sign( $payload, $hash, $site_key ) {
	return hash_hmac( 'sha256', $payload . '|' . qwab_sm_ua_hash(), 'qwab-safe-mode-cookie|' . $hash . '|' . $site_key );
}

/**
 * Süti beállítása vagy törlése.
 *
 * Útvonal „/": a COOKIEPATH konstansok a mu-pluginok UTÁN jönnek létre.
 *
 * @param string $value   Érték ('' = törlés).
 * @param int    $expires Lejárat.
 */
function qwab_sm_cookie( $value, $expires ) {
	setcookie(
		'qwab_safe_mode',
		$value,
		array(
			'expires'  => $expires,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Strict',
		)
	);
}

/**
 * Érvényes-e a Safe Mode munkamenet? Formátum: `lejárat.aláírás`.
 *
 * @param string $hash     Tárolt kulcs-hash.
 * @param string $site_key Webhely-kulcs.
 * @return bool
 */
function qwab_sm_session_valid( $hash, $site_key ) {
	if ( ! isset( $_COOKIE['qwab_safe_mode'] ) || ! is_string( $_COOKIE['qwab_safe_mode'] ) ) {
		return false;
	}

	$parts = explode( '.', sanitize_text_field( wp_unslash( $_COOKIE['qwab_safe_mode'] ) ), 2 );
	if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || (int) $parts[0] < time() || (int) $parts[0] > time() + HOUR_IN_SECONDS + 60 ) {
		return false;
	}

	return hash_equals( qwab_sm_sign( $parts[0], $hash, $site_key ), $parts[1] );
}

/* -------------------------------------------------------------------------
 * Belépő oldal
 * ---------------------------------------------------------------------- */

/**
 * A kérés a helyreállításhoz szükséges felületre szól-e?
 * (`$pagenow` mu-időben még nincs.)
 *
 * @return bool
 */
function qwab_sm_in_scope() {
	return ( defined( 'WP_ADMIN' ) && WP_ADMIN ) || qwab_sm_is_login_screen();
}

/**
 * A wp-login.php szól-e?
 *
 * @return bool
 */
function qwab_sm_is_login_screen() {
	$script = isset( $_SERVER['SCRIPT_NAME'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : '';

	return 'wp-login.php' === basename( $script );
}

/**
 * A csomagolt fordítás betöltése (a főplugin ilyenkor nem fut).
 */
function qwab_sm_textdomain() {
	if ( is_textdomain_loaded( 'qaiyo-admin-booster' ) || ! function_exists( 'determine_locale' ) ) {
		return;
	}
	$locale = determine_locale();
	$locale = ( 'ja_JP' === $locale ) ? 'ja' : $locale;
	$mo     = WP_PLUGIN_DIR . '/qaiyo-admin-booster/languages/qaiyo-admin-booster-' . $locale . '.mo';
	if ( file_exists( $mo ) ) {
		load_textdomain( 'qaiyo-admin-booster', $mo );
	}
}

/**
 * Szigorú biztonsági fejlécek a belépő oldalhoz.
 *
 * @param string $nonce CSP nonce az inline szkripthez.
 */
function qwab_sm_headers( $nonce ) {
	nocache_headers();
	header( 'Referrer-Policy: no-referrer' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'X-Frame-Options: DENY' );
	header( 'X-Content-Type-Options: nosniff' );
	header( "Content-Security-Policy: default-src 'none'; script-src 'nonce-" . $nonce . "'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'" );
}

/**
 * A belépő oldal kiírása és kilépés.
 *
 * @param int  $status HTTP státusz.
 * @param bool $failed Sikertelen volt-e az előző próbálkozás.
 */
function qwab_sm_render_entry( $status, $failed ) {
	qwab_sm_textdomain();
	$nonce = bin2hex( random_bytes( 16 ) );
	status_header( $status );
	header( 'Content-Type: text/html; charset=utf-8' );
	qwab_sm_headers( $nonce );

	$action = add_query_arg( 'qwab_safe_mode', '1', site_url( 'wp-login.php', is_ssl() ? 'https' : null ) );
	?>
<!doctype html>
<html lang="<?php echo esc_attr( str_replace( '_', '-', determine_locale() ) ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php esc_html_e( 'Safe Mode', 'qaiyo-admin-booster' ); ?></title>
<style>
body{margin:0;background:#f0f0f1;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1d2327}
main{max-width:420px;margin:12vh auto;padding:28px;background:#fff;border:1px solid #dcdcde;border-radius:8px}
h1{font-size:20px;margin:0 0 8px}p{margin:0 0 14px;color:#50575e}
.err{color:#b32d2e;font-weight:600}
input{width:100%;box-sizing:border-box;padding:8px 10px;font:13px monospace;border:1px solid #8c8f94;border-radius:4px}
button{margin-top:12px;padding:8px 16px;background:#2271b1;color:#fff;border:0;border-radius:4px;font-size:14px;cursor:pointer}
</style>
</head>
<body>
<main>
<h1><?php esc_html_e( 'Safe Mode', 'qaiyo-admin-booster' ); ?></h1>
<?php if ( $failed ) : ?>
<p class="err" role="alert"><?php esc_html_e( 'This Safe Mode key is not valid.', 'qaiyo-admin-booster' ); ?></p>
<?php endif; ?>
<p id="qwab-sm-wait" hidden><?php esc_html_e( 'Checking your Safe Mode key…', 'qaiyo-admin-booster' ); ?></p>
<form id="qwab-sm-form" method="post" action="<?php echo esc_url( $action ); ?>" autocomplete="off">
<p><label for="qwab-sm-key"><?php esc_html_e( 'Paste the key from your saved Safe Mode link (the part after #).', 'qaiyo-admin-booster' ); ?></label></p>
<input type="password" id="qwab-sm-key" name="qwab_safe_mode_key" required spellcheck="false">
<button type="submit"><?php esc_html_e( 'Enter Safe Mode', 'qaiyo-admin-booster' ); ?></button>
</form>
</main>
<script nonce="<?php echo esc_attr( $nonce ); ?>">
(function(){
	var key = window.location.hash.replace(/^#/, '');
	if (key && window.history && history.replaceState) {
		history.replaceState(null, '', window.location.pathname + window.location.search);
	}
	<?php if ( ! $failed ) : ?>
	if (/^[a-f0-9]{64}$/.test(key)) {
		document.getElementById('qwab-sm-key').value = key;
		document.getElementById('qwab-sm-form').hidden = true;
		document.getElementById('qwab-sm-wait').hidden = false;
		document.getElementById('qwab-sm-form').submit();
	}
	<?php endif; ?>
})();
</script>
</body>
</html>
	<?php
	exit;
}

/**
 * Átirányítás (a pluggable.php még nincs betöltve; a cél a webhely saját
 * címe, nem felhasználói bemenet).
 *
 * @param string $url  Cél.
 * @param int    $code Kód.
 */
function qwab_sm_redirect( $url, $code ) {
	nocache_headers();
	header( 'Referrer-Policy: no-referrer' );
	header( 'Location: ' . $url, true, $code ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- pluggable.php is not loaded yet; target is the site's own URL.
	exit;
}

/**
 * Egy alap WordPress téma, ami nem az aktív (hibás lehet) téma.
 *
 * @param array $current A valódi template és stylesheet.
 * @return string
 */
function qwab_sm_fallback_theme( $current ) {
	$root = get_theme_root();

	// Újdonsági sorrend — a betűrend itt félrevezető lenne.
	$candidates = array( 'twentytwentyfive', 'twentytwentyfour', 'twentytwentythree', 'twentytwentytwo', 'twentytwentyone', 'twentytwenty' );
	$found      = glob( $root . '/twenty*', GLOB_ONLYDIR );
	foreach ( $found ? $found : array() as $dir ) {
		$candidates[] = basename( $dir );
	}

	foreach ( array_unique( $candidates ) as $slug ) {
		if ( ! in_array( $slug, $current, true ) && file_exists( $root . '/' . $slug . '/style.css' ) ) {
			return $slug;
		}
	}

	return '';
}

endif;

/* -------------------------------------------------------------------------
 * Vezérlés
 * ---------------------------------------------------------------------- */

$qwab_sm_hash     = qwab_sm_stored_hash();
$qwab_sm_site_key = qwab_sm_site_key();

// Kilépés — akkor is működjön, ha közben a link már visszavonódott.
if ( isset( $_GET['qwab_safe_mode_exit'] ) ) {
	if ( isset( $_COOKIE['qwab_safe_mode'] ) ) {
		qwab_sm_cookie( '', time() - YEAR_IN_SECONDS );
		qwab_sm_redirect( site_url( 'wp-admin/' ), 302 );
	}
	return;
}

if ( '' === $qwab_sm_hash || '' === $qwab_sm_site_key ) {
	return;
}

// Belépés.
if ( isset( $_GET['qwab_safe_mode'] ) ) {
	$qwab_sm_https_site = 0 === strpos( (string) get_option( 'siteurl' ), 'https://' );

	// HTTPS-oldalon kulcsot csak titkosított kapcsolaton fogadunk. (A böngésző
	// az átirányításkor megtartja a # részt, így a link HTTP-n is működik.)
	if ( $qwab_sm_https_site && ! is_ssl() ) {
		qwab_sm_redirect( add_query_arg( 'qwab_safe_mode', '1', site_url( 'wp-login.php', 'https' ) ), 301 );
	}

	$qwab_sm_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	if ( 'POST' !== $qwab_sm_method ) {
		qwab_sm_render_entry( 200, false );
	}

	$qwab_sm_key = isset( $_POST['qwab_safe_mode_key'] ) && is_string( $_POST['qwab_safe_mode_key'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['qwab_safe_mode_key'] ) ) ) ) : '';

	// Ugyanaz a válasz rossz kulcsra és tiltott IP-re: az IP-korlátozás
	// létezése se szivárogjon ki.
	$qwab_sm_ok = qwab_sm_ip_allowed()
		&& 1 === preg_match( '/^[a-f0-9]{64}$/', $qwab_sm_key )
		&& hash_equals( $qwab_sm_hash, qwab_sm_hash_key( $qwab_sm_key, $qwab_sm_site_key ) );

	if ( ! $qwab_sm_ok ) {
		qwab_sm_log_throttled( qwab_sm_ip_allowed() ? 'bad_key' : 'ip_denied' );
		qwab_sm_render_entry( 403, true );
	}

	$qwab_sm_expires = time() + HOUR_IN_SECONDS;
	qwab_sm_cookie( $qwab_sm_expires . '.' . qwab_sm_sign( (string) $qwab_sm_expires, $qwab_sm_hash, $qwab_sm_site_key ), $qwab_sm_expires );
	qwab_sm_log( 'entry' );
	qwab_sm_queue_alert( 'entry' );

	// A főplugin ebből tudja, hogy a linket használták → visszavonja, amint az
	// oldal rendes módban újra betölt.
	qwab_sm_set( 'qwab_safe_mode_used', array( 'time' => time(), 'ip' => qwab_sm_ip() ) );

	// reauth=1: a WordPress törli a meglévő belépési sütiket, és jelszót kér.
	qwab_sm_redirect( add_query_arg( 'reauth', '1', site_url( 'wp-login.php' ) ), 303 );
}

// Aktív munkamenet.
if ( ! qwab_sm_in_scope() || ! qwab_sm_session_valid( $qwab_sm_hash, $qwab_sm_site_key ) || ! qwab_sm_ip_allowed() ) {
	return;
}
// phpcs:enable WordPress.Security.NonceVerification

define( 'QWAB_SAFE_MODE_ACTIVE', true );

// A Safe Mode munkamenet azonosítója — a friss belépést ehhez kötjük.
$qwab_sm_session = substr( hash( 'sha256', sanitize_text_field( wp_unslash( $_COOKIE['qwab_safe_mode'] ) ) ), 0, 40 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- A qwab_sm_session_valid() fent már ellenőrizte.

// Helyreállító konzol: fájlmódosítás és fájlszerkesztés tiltva.
if ( ! defined( 'DISALLOW_FILE_MODS' ) ) {
	define( 'DISALLOW_FILE_MODS', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant, deliberately set for the Safe Mode request only.
}
if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
	define( 'DISALLOW_FILE_EDIT', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant, deliberately set for the Safe Mode request only.
}

// Pluginok: csak a betöltési ablakban üres a lista.
add_filter(
	'pre_option_active_plugins',
	static function ( $pre ) {
		return did_action( 'plugins_loaded' ) ? $pre : array();
	}
);
add_filter(
	'pre_site_option_active_sitewide_plugins',
	static function ( $pre ) {
		return did_action( 'plugins_loaded' ) ? $pre : array();
	}
);

// Téma: csak a functions.php betöltéséig cseréljük le egy alap témára.
$qwab_sm_all      = wp_load_alloptions();
$qwab_sm_fallback = qwab_sm_fallback_theme(
	array(
		isset( $qwab_sm_all['template'] ) ? (string) $qwab_sm_all['template'] : '',
		isset( $qwab_sm_all['stylesheet'] ) ? (string) $qwab_sm_all['stylesheet'] : '',
	)
);
if ( '' !== $qwab_sm_fallback ) {
	$qwab_sm_theme_filter = static function ( $pre ) use ( $qwab_sm_fallback ) {
		return did_action( 'after_setup_theme' ) ? $pre : $qwab_sm_fallback;
	};
	add_filter( 'pre_option_template', $qwab_sm_theme_filter );
	add_filter( 'pre_option_stylesheet', $qwab_sm_theme_filter );
}

if ( ! function_exists( 'qwab_sm_user_allowed' ) ) :
/**
 * Jogosult-e a felhasználó Safe Mode-ra?
 *
 * @param WP_User|int $user Felhasználó.
 * @return bool
 */
function qwab_sm_user_allowed( $user ) {
	$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );
	if ( ! $user || ! $user->exists() ) {
		return false;
	}

	return is_multisite() ? is_super_admin( $user->ID ) : user_can( $user, 'manage_options' );
}
endif;

if ( ! function_exists( 'qwab_sm_map_meta_cap' ) ) :
/**
 * Tiltott képességek Safe Mode alatt — link + jelszó birtokában se lehessen
 * hátsó ajtót nyitni (új admin, webshell-feltöltés, fájlszerkesztés).
 * A pluginok ki-/bekapcsolása és a témaváltás szándékosan engedélyezett.
 *
 * @param array  $caps Szükséges alap-képességek.
 * @param string $cap  Kért képesség.
 * @return array
 */
function qwab_sm_map_meta_cap( $caps, $cap ) {
	static $denied = array(
		'install_plugins', 'upload_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins',
		'install_themes', 'upload_themes', 'update_themes', 'delete_themes', 'edit_themes',
		'edit_files', 'update_core', 'install_languages', 'update_languages',
		'create_users', 'add_users', 'promote_users', 'edit_users', 'delete_users', 'remove_users',
		'promote_user', 'edit_user', 'delete_user', 'remove_user',
		'unfiltered_upload', 'unfiltered_html', 'import', 'export', 'manage_options',
		'upload_files', 'edit_theme_options', 'customize', 'manage_network_users',
		'manage_network_options', 'create_sites', 'delete_sites', 'manage_sites',
	);

	return in_array( $cap, $denied, true ) ? array( 'do_not_allow' ) : $caps;
}
endif;
add_filter( 'map_meta_cap', 'qwab_sm_map_meta_cap', PHP_INT_MAX, 2 );

// A `user_can( …, 'manage_options' )` a jogosultság-ellenőrzéshez kell, de a
// fenti szűrő azt is tiltja — ezért a saját ellenőrzésünk idejére kikapcsoljuk.
add_filter(
	'authenticate',
	static function ( $user ) {
		if ( ! $user instanceof WP_User ) {
			return $user;
		}

		$lock = (int) get_transient( 'qwab_sm_login_fails' );
		if ( $lock >= 5 ) {
			qwab_sm_log_throttled( 'login_locked', $user->user_login );
			return new WP_Error( 'qwab_safe_mode_locked', esc_html__( 'Too many failed logins in Safe Mode. Try again in 15 minutes.', 'qaiyo-admin-booster' ) );
		}

		remove_filter( 'map_meta_cap', 'qwab_sm_map_meta_cap', PHP_INT_MAX );
		$allowed = qwab_sm_user_allowed( $user );
		add_filter( 'map_meta_cap', 'qwab_sm_map_meta_cap', PHP_INT_MAX, 2 );

		if ( ! $allowed ) {
			qwab_sm_log( 'login_not_admin', $user->user_login );
			return new WP_Error( 'qwab_safe_mode_not_admin', esc_html__( 'Only administrators can log in while Safe Mode is on.', 'qaiyo-admin-booster' ) );
		}

		return $user;
	},
	PHP_INT_MAX
);

// Login-limiter: a biztonsági pluginok Safe Mode-ban nem futnak. (A zárolást
// a PHP_INT_MAX `authenticate` szűrő érvényesíti: a core
// `wp_authenticate_username_password` a korábbi prioritású WP_Error-t
// figyelmen kívül hagyja.)
add_action(
	'wp_login_failed',
	static function ( $username ) {
		$fails = (int) get_transient( 'qwab_sm_login_fails' ) + 1;
		set_transient( 'qwab_sm_login_fails', $fails, 15 * MINUTE_IN_SECONDS );
		qwab_sm_log( 'login_failed', (string) $username );
		if ( 5 === $fails ) {
			qwab_sm_queue_alert( 'login_locked', (string) $username );
		}
	}
);
add_action(
	'wp_login',
	static function ( $login, $user ) use ( $qwab_sm_session ) {
		set_transient( 'qwab_sm_s_' . $qwab_sm_session, (int) $user->ID, HOUR_IN_SECONDS + 60 );
		qwab_sm_log( 'login', (string) $login );
		qwab_sm_queue_alert( 'login', (string) $login );
	},
	10,
	2
);

// Bejelentkezett, de nem jogosult felhasználó → kiléptetjük.
add_action(
	'init',
	static function () use ( $qwab_sm_session ) {
		if ( ! is_user_logged_in() ) {
			return;
		}
		remove_filter( 'map_meta_cap', 'qwab_sm_map_meta_cap', PHP_INT_MAX );
		$allowed = qwab_sm_user_allowed( get_current_user_id() );
		add_filter( 'map_meta_cap', 'qwab_sm_map_meta_cap', PHP_INT_MAX, 2 );

		if ( ! $allowed ) {
			wp_logout();
			wp_die( esc_html__( 'Only administrators can use Safe Mode.', 'qaiyo-admin-booster' ), '', array( 'response' => 403 ) );
		}

		// Egy korábbról (akár ellopott) meglévő belépési süti NEM elég: ebben a
		// Safe Mode munkamenetben jelszóval be kell lépni.
		if ( (int) get_transient( 'qwab_sm_s_' . $qwab_sm_session ) !== get_current_user_id() ) {
			wp_logout();
			if ( ! qwab_sm_is_login_screen() ) {
				wp_safe_redirect( add_query_arg( 'reauth', '1', site_url( 'wp-login.php' ) ) );
				exit;
			}
		}
	},
	0
);

// Szűkített admin: csak a helyreállításhoz szükséges képernyők.
add_action(
	'admin_init',
	static function () {
		global $pagenow;

		$allowed = array( 'index.php', 'plugins.php', 'themes.php' );
		if ( wp_doing_ajax() ) {
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Csak engedélyezési döntés, nem adatfeldolgozás.
			if ( 'heartbeat' !== $action ) {
				wp_die( '0', '', array( 'response' => 403 ) );
			}
			return;
		}

		if ( ! in_array( $pagenow, $allowed, true ) ) {
			wp_safe_redirect( is_network_admin() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' ) );
			exit;
		}
	},
	0
);

// A menü-jogosultság ellenőrzése (user_can_access_admin_page) az admin_init
// ELŐTT fut, és a tiltott képességek miatt 403-mal állna meg — egységesen a
// Bővítményekre visszük.
add_action(
	'admin_page_access_denied',
	static function () {
		wp_safe_redirect( is_network_admin() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' ) );
		exit;
	}
);

// Riasztások kiküldése (itt a wp_mail() már létezik).
add_action(
	'init',
	static function () {
		$queue = qwab_sm_get( 'qwab_safe_mode_alerts', array() );
		if ( ! is_array( $queue ) || ! $queue ) {
			return;
		}
		qwab_sm_set( 'qwab_safe_mode_alerts', array() );

		qwab_sm_textdomain();

		$emails = array();
		if ( is_multisite() ) {
			foreach ( get_super_admins() as $login ) {
				$u = get_user_by( 'login', $login );
				if ( $u ) {
					$emails[] = $u->user_email;
				}
			}
		} else {
			$emails = (array) get_users( array( 'role' => 'administrator', 'fields' => 'user_email' ) );
		}
		$emails = array_values( array_unique( array_filter( $emails, 'is_email' ) ) );
		if ( ! $emails ) {
			return;
		}

		$labels = array(
			'entry'        => __( 'Someone entered Safe Mode with the Safe Mode link.', 'qaiyo-admin-booster' ),
			'login'        => __( 'An administrator logged in while Safe Mode was on.', 'qaiyo-admin-booster' ),
			'login_locked' => __( 'Safe Mode logins were locked after 5 failed attempts.', 'qaiyo-admin-booster' ),
		);

		$lines = array();
		foreach ( $queue as $event ) {
			$type    = isset( $labels[ $event['type'] ] ) ? $labels[ $event['type'] ] : $event['type'];
			$lines[] = sprintf(
				/* translators: 1: date and time, 2: event description, 3: IP address, 4: username or "—". */
				__( '%1$s — %2$s IP: %3$s, user: %4$s', 'qaiyo-admin-booster' ),
				wp_date( 'Y-m-d H:i:s', (int) $event['time'] ),
				$type,
				$event['ip'],
				'' !== $event['user'] ? $event['user'] : '—'
			);
		}

		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$body = implode( "\n", $lines ) . "\n\n"
			. __( 'If this was you or a colleague fixing the site, no action is needed: the link is retired automatically once the site works again, and you will be asked to create a new one.', 'qaiyo-admin-booster' ) . "\n\n"
			. __( 'If you do not recognise this: log in, create a new Safe Mode link (the used one stops working at once), change every administrator password, and review the Safe Mode log under Admin Booster → Safe Mode.', 'qaiyo-admin-booster' ) . "\n\n"
			. site_url();

		foreach ( $emails as $email ) {
			wp_mail(
				$email,
				/* translators: %s: site name. */
				sprintf( __( '[%s] Safe Mode was used', 'qaiyo-admin-booster' ), $site ),
				$body
			);
		}
	},
	20
);

if ( ! function_exists( 'qwab_sm_notice' ) ) :
/**
 * Admin sáv.
 */
function qwab_sm_notice() {
	qwab_sm_textdomain();
	printf(
		'<div class="notice notice-error" style="border-left-width:6px;padding:12px 16px;"><p style="font-size:14px;margin:0 0 6px;"><strong>%1$s</strong></p><p style="margin:0;">%2$s</p><p style="margin:10px 0 0;"><a class="button button-primary" href="%3$s">%4$s</a> <a class="button" href="%5$s">%6$s</a></p></div>',
		esc_html__( 'Safe Mode is on — no plugins are running for you, and a default theme stands in for yours.', 'qaiyo-admin-booster' ),
		esc_html__( 'Only the Dashboard, Plugins and Themes screens are available, and installing, uploading, editing files and managing users are blocked. Deactivate the plugin (or switch away from the theme) that broke the site, then exit Safe Mode to check.', 'qaiyo-admin-booster' ),
		esc_url( is_network_admin() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' ) ),
		esc_html__( 'Go to Plugins', 'qaiyo-admin-booster' ),
		esc_url( add_query_arg( 'qwab_safe_mode_exit', '1', admin_url() ) ),
		esc_html__( 'Exit Safe Mode', 'qaiyo-admin-booster' )
	);
}
endif;
add_action( 'admin_notices', 'qwab_sm_notice', 1 );
add_action( 'network_admin_notices', 'qwab_sm_notice', 1 );

add_filter(
	'login_message',
	static function ( $message ) {
		qwab_sm_textdomain();
		return $message . '<p class="message">' . esc_html__( 'Safe Mode is on: plugins are switched off for you until you exit Safe Mode. Only administrators can log in.', 'qaiyo-admin-booster' ) . '</p>';
	}
);
