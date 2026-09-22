<?php
/**
 * Modul: Ütemezett közzététel visszaszámláló.
 *
 * A jövőbeli (ütemezett) bejegyzésekhez a listanézetben a cím mellé egy
 * „Közzététel: X múlva" állapotcímkét tesz, így egy pillantással látszik,
 * mennyi van hátra a megjelenésig — nem kell a dátumot fejben kiszámolni.
 *
 * @package Qaiyo_WP_Admin_Booster
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Qwab_Module_Scheduled_Countdown {

    /**
     * A hook-ok bekötése.
     *
     * Szándékosan NEM a konstruktorban: így az osztály mellékhatás
     * nélkül példányosítható (tesztelhetőség), és a bekötés ideje
     * a hívó döntése.
     */
    public function register(): void {
        add_filter( 'display_post_states', array( $this, 'add_state' ), 10, 2 );
    }

    /**
     * Visszaszámláló cím-állapot hozzáadása jövőbeli posztokhoz.
     *
     * @param array   $states Állapotok.
     * @param WP_Post $post   Poszt.
     * @return array
     */
    public function add_state( $states, $post ) {
        if ( ! is_object( $post ) || 'future' !== $post->post_status ) {
            return $states;
        }

        $now  = time();
        $when = get_post_time( 'U', true, $post );

        if ( $when && $when > $now ) {
            $states['qwab_countdown'] = sprintf(
                /* translators: %s: human-readable time difference, e.g. "3 days". */
                __( 'Publishing in %s', 'qaiyo-admin-booster' ),
                human_time_diff( $now, $when )
            );
        }

        return $states;
    }
}
