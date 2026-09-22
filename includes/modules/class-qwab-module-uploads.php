<?php
/**
 * Modul: Fájlkezelés.
 *
 * - Feltöltési méretkorlát felemelése a WP rétegben.
 * - Engedélyezett MIME / fájltípusok kezelése (WebP, AVIF, SVG, egyedi típusok).
 * - SVG biztonságos átengedése a valós MIME-ellenőrzésen.
 * - Érthetőbb hibaüzenet, ha nem engedélyezett típust próbálnak feltölteni.
 *
 * A veszélyes fájltípusok figyelmeztetése a beállító oldalon jelenik meg
 * (lásd Qwab_Admin), nem itt.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Uploads {

    /**
     * Beépített „bekapcsolható" típusok ext → mime.
     *
     * @var array<string,string>
     */
    const TOGGLE_MIMES = array(
        'svg'  => 'image/svg+xml',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    );

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        add_filter( 'upload_size_limit', array( $this, 'filter_size_limit' ), 10, 3 );
        add_filter( 'upload_mimes', array( $this, 'filter_mimes' ) );
        add_filter( 'wp_check_filetype_and_ext', array( $this, 'fix_filetype_check' ), 10, 4 );

        if ( Qwab_Settings::get( 'friendly_errors', 1 ) ) {
            add_filter( 'wp_handle_upload_prefilter', array( $this, 'friendly_error' ) );
        }

        if ( Qwab_Settings::get( 'friendly_filenames', 0 ) ) {
            // Csak a tényleges média-feltöltésekre hatunk (a `wp_handle_upload_prefilter`
            // fájl-nevén), NEM a globális `sanitize_file_name` szűrőn — így a más
            // pluginok/téma/core által generált fájlnevek érintetlenek maradnak.
            add_filter( 'wp_handle_upload_prefilter', array( $this, 'clean_upload_filename' ) );
        }
    }

    /**
     * A feltöltött fájl nevét slug-barát alakra hozza (kizárólag feltöltéskor).
     *
     * @param array $file A feltöltött fájl ($_FILES egy eleme + 'error').
     * @return array
     */
    public function clean_upload_filename( $file ) {
        if ( ! empty( $file['name'] ) ) {
            $file['name'] = $this->friendly_filename( (string) $file['name'] );
        }
        return $file;
    }

    /**
     * Slug-barát fájlnevet készít a feltöltésekből.
     *
     * Az ékezeteket ASCII-ra alakítja, a szóközöket/aláhúzásokat kötőjelre
     * cseréli, eltávolítja a nem alfanumerikus jeleket (a pont kivételével),
     * és kisbetűsít. Így a feltöltött fájlok URL-barát, jól hivatkozható
     * neveket kapnak (pl. „Árvíz Tükör.JPG" → „arviz-tukor.jpg").
     *
     * @param string $filename A feltöltött fájl neve.
     * @return string
     */
    public function friendly_filename( string $filename ): string {
        $name = remove_accents( (string) $filename ); // Ékezetek → ASCII.

        // Alap cserék: szóköz, kódolt szóköz és aláhúzás → kötőjel.
        $invalid = array(
            ' '   => '-',
            '%20' => '-',
            '_'   => '-',
        );
        $name = str_replace( array_keys( $invalid ), array_values( $invalid ), $name );

        $name = preg_replace( '/[^A-Za-z0-9\-\. ]/', '', $name ); // Csak alfanumerikus + - . marad.
        $name = preg_replace( '/\.(?=.*\.)/', '', $name );        // Csak az utolsó pontot tartjuk meg.
        $name = preg_replace( '/-+/', '-', $name );               // Több egymás utáni kötőjel → egy.
        $name = str_replace( '-.', '.', $name );                  // Kötőjel a kiterjesztés előtt nem kell.
        $name = strtolower( $name );                              // Kisbetűsítés.

        $name = trim( $name, '-' );

        // Ha üresre tisztult (pl. csupa nem-ASCII név), hagyjuk az eredetit.
        return '' !== $name ? $name : $filename;
    }

    /**
     * WP feltöltési méretkorlát (byte-ban). 0 = ne módosítsuk.
     *
     * A beállított érték CSAK CSÖKKENTHETI a szerver limitjét, növelni nem
     * tudja: az `upload_max_filesize` / `post_max_size` PHP_INI_PERDIR
     * direktívák, futás közben (`ini_set`) nem írhatók. Ha a szerver limitje
     * fölé engednénk, a WP nagyobb limitet hirdetne és a feltöltő átengedné a
     * fájlt, a PHP viszont csendben eldobná a kérés törzsét — a felhasználó a
     * tiszta „túl nagy a fájl" hiba helyett egy értelmetlen „Váratlan válasz a
     * kiszolgálótól" üzenetet kapna.
     *
     * @param int $bytes   Jelenlegi limit.
     * @param int $u_bytes PHP upload_max_filesize byte-ban.
     * @param int $p_bytes PHP post_max_size byte-ban.
     * @return int
     */
    public function filter_size_limit( $bytes, $u_bytes = 0, $p_bytes = 0 ) {
        $mb = (int) Qwab_Settings::get( 'upload_max_size_mb', 0 );
        if ( $mb <= 0 ) {
            return $bytes;
        }

        return min( (int) $bytes, $mb * 1024 * 1024 );
    }

    /**
     * A szerver (php.ini) tényleges feltöltési limitje, a WP szűrők nélkül.
     *
     * @return int Byte. 0, ha nem állapítható meg.
     */
    public static function server_limit(): int {
        $u = wp_convert_hr_to_bytes( (string) ini_get( 'upload_max_filesize' ) );
        $p = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );

        // A post_max_size = 0 PHP-ben „nincs limit" jelentésű.
        if ( $p <= 0 ) {
            return max( 0, $u );
        }

        return min( $u, $p );
    }

    /**
     * Engedélyezett MIME típusok kiegészítése.
     *
     * @param array $mimes Jelenlegi engedélyezett típusok ext => mime.
     * @return array
     */
    public function filter_mimes( $mimes ) {
        foreach ( self::TOGGLE_MIMES as $ext => $mime ) {
            $enabled = (bool) Qwab_Settings::get( 'enable_' . $ext, 0 );
            if ( $enabled ) {
                $mimes[ $ext ] = $mime;
            }
        }

        $dangerous = self::dangerous_extensions();
        $extra     = (array) Qwab_Settings::get( 'extra_mimes', array() );
        foreach ( $extra as $ext => $mime ) {
            $ext = preg_replace( '/[^a-z0-9|]/', '', strtolower( (string) $ext ) );
            if ( '' === $ext || ! is_string( $mime ) || '' === $mime ) {
                continue;
            }
            // Végrehajtható / script kiterjesztéseket SOHA nem engedünk feltölteni,
            // akkor sem, ha egy adminisztrátor kézzel beírta őket (biztonság).
            if ( array_intersect( explode( '|', $ext ), $dangerous ) ) {
                continue;
            }
            $mimes[ $ext ] = $mime;
        }

        return $mimes;
    }

    /**
     * SVG (és más bekapcsolt típusok) átengedése a valós-MIME ellenőrzésen.
     *
     * A WP a fájl tényleges MIME-ját finfo-val ellenőrzi; SVG-nél ez gyakran
     * üres ext/type-ot ad vissza, ezért a feltöltés elbukna. Ha a típus
     * engedélyezett a beállításainkban, visszaállítjuk a helyes ext/type-ot.
     *
     * @param array  $data     Ellenőrzés eredménye.
     * @param string $file     Fájl elérési út.
     * @param string $filename Fájlnév.
     * @param array  $mimes    Engedélyezett MIME-ok.
     * @return array
     */
    public function fix_filetype_check( $data, $file, $filename, $mimes ) {
        if ( ! empty( $data['ext'] ) && ! empty( $data['type'] ) ) {
            return $data;
        }

        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

        if ( 'svg' === $ext && Qwab_Settings::get( 'enable_svg', 0 ) ) {
            $data['ext']  = 'svg';
            $data['type'] = 'image/svg+xml';
            return $data;
        }

        // Egyedi típusok: ha az ext szerepel az allowlistben, fogadjuk el.
        $allowed = $this->filter_mimes( array() );
        foreach ( $allowed as $exts => $mime ) {
            if ( in_array( $ext, explode( '|', $exts ), true ) ) {
                $data['ext']  = $ext;
                $data['type'] = $mime;
                return $data;
            }
        }

        return $data;
    }

    /**
     * Érthetőbb hibaüzenet nem engedélyezett típusnál.
     *
     * @param array $file A feltöltött fájl ($_FILES egy eleme + 'error').
     * @return array
     */
    public function friendly_error( $file ) {
        if ( ! empty( $file['error'] ) ) {
            return $file;
        }

        $name      = isset( $file['name'] ) ? $file['name'] : '';
        $check     = wp_check_filetype_and_ext( isset( $file['tmp_name'] ) ? $file['tmp_name'] : '', $name );
        $ext       = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        $allowed   = get_allowed_mime_types();
        $permitted = false;

        foreach ( $allowed as $exts => $mime ) {
            if ( in_array( $ext, explode( '|', $exts ), true ) ) {
                $permitted = true;
                break;
            }
        }

        if ( $permitted || ! empty( $check['ext'] ) ) {
            return $file;
        }

        $type_list = $this->human_allowed_extensions();

        $file['error'] = sprintf(
            /* translators: 1: the file extension that was rejected, 2: comma-separated list of allowed extensions. */
            __( 'The file type ".%1$s" is not allowed on this site. Allowed types: %2$s. You can enable more types under Qaiyo Admin Booster → File handling.', 'qaiyo-admin-booster' ),
            $ext ? $ext : esc_html__( 'unknown', 'qaiyo-admin-booster' ),
            $type_list
        );

        return $file;
    }

    /**
     * Olvasható lista az engedélyezett kiterjesztésekről.
     *
     * @return string
     */
    private function human_allowed_extensions(): string {
        $allowed = get_allowed_mime_types();
        $exts    = array();
        foreach ( array_keys( $allowed ) as $group ) {
            foreach ( explode( '|', $group ) as $e ) {
                $exts[] = $e;
            }
        }
        $exts = array_slice( array_unique( $exts ), 0, 30 );
        return implode( ', ', $exts );
    }

    /**
     * Veszélyesnek tekintett kiterjesztések (figyelmeztetéshez az adminban).
     *
     * @return array
     */
    public static function dangerous_extensions(): array {
        return array(
            // Szerveroldali / futtatható kód.
            'php', 'php3', 'php4', 'php5', 'php7', 'phps', 'pht', 'phtml', 'phar',
            'exe', 'com', 'bat', 'cmd', 'sh', 'bash', 'cgi', 'pl', 'py', 'rb',
            'asp', 'aspx', 'jsp', 'jspx', 'htaccess', 'htpasswd', 'dll', 'so',
            // Böngészőben futtatható / scriptelhető (XSS, tartalom-injektálás).
            'js', 'mjs', 'html', 'htm', 'xhtml', 'shtml', 'dhtml', 'hta',
            'css', 'xml', 'xsl', 'xslt', 'svgz', 'swf', 'jar', 'vbs', 'ps1',
        );
    }
}
