<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Access {

    private static function is_blocked() {
        $uid = get_current_user_id();
        if ( ! $uid ) return false;
        $blocked = LSV07I_DB::get_config( 'blocked_user_ids', '' );
        if ( ! $blocked ) return false;
        return in_array( $uid, array_map( 'absint', explode( ',', $blocked ) ), true );
    }

    private static function user_roles() {
        if ( ! is_user_logged_in() ) return [];
        return (array) wp_get_current_user()->roles;
    }

    private static function raw_is_admin() {
        if ( self::is_blocked() ) return false;
        return in_array( 'administrator', self::user_roles(), true );
    }

    // ── Schwimmen ────────────────────────────────────────────────────────────
    // Achtung: ab Version 7.22 basieren ALLE is_*-Methoden ausschließlich auf dem
    // granularen Rechtesystem (lsv07i_user_rechte). WP-Administratoren haben
    // weiterhin Vollzugriff als Sicherheitsventil. Die alten WP-Rollen sind nur
    // noch für die einmalige Migration relevant — danach folgen Berechtigungen
    // den expliziten Rechten in der DB.

    public static function is_admin() {
        if ( ! self::raw_is_admin() ) return false;
        // WP-Admin = Vollzugriff. Kein zusätzlicher Permission-Check.
        return true;
    }

    public static function is_admin_raw() {
        return self::raw_is_admin();
    }

    public static function is_schwimmwart() {
        if ( self::is_blocked() ) return false;
        if ( self::raw_is_admin() ) return true;
        // Schwimmwart = wer Mannschaften anlegen darf (klassische Schwimmwart-Aufgabe)
        return LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_MANNSCHAFT_CREATE );
    }

    public static function is_finanzwart() {
        if ( self::is_blocked() ) return false;
        if ( self::raw_is_admin() ) return true;
        // Finanzwart = wer auf die Wart-Sicht der Abrechnungen zugreifen darf
        return LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_WART_READ );
    }

    public static function is_intern() {
        if ( self::is_blocked() ) return false;
        if ( self::raw_is_admin() ) return true;
        // Intern = irgendein Schwimmen-Recht
        return LSV07I_Permissions::has_any_in_bereich( get_current_user_id(), 'schwimmen.' );
    }

    public static function is_trainer() {
        if ( self::is_blocked() ) return false;
        if ( self::raw_is_admin() ) return true;
        // is_trainer ist DATEN-basiert (eigentlicher Trainer-Datensatz), nicht
        // permission-basiert. Bleibt wie gehabt — wer im Trainer-Datensatz steht,
        // ist Trainer.
        $t = LSV07I_DB::get_trainer_by_user( get_current_user_id() );
        return ! empty( $t );
    }

    public static function get_trainer_id() {
        $t = LSV07I_DB::get_trainer_by_user( get_current_user_id() );
        if ( $t ) return (int) $t['id'];
        return 0;
    }

    // Triathlon-Trainer-ID des aktuellen Nutzers (aus lsv07i_tri_trainer)
    public static function get_tri_trainer_id() {
        global $wpdb;
        $uid = get_current_user_id();
        if ( ! $uid ) return 0;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}lsv07i_tri_trainer WHERE wp_user_id = %d AND aktiv = 1 LIMIT 1",
            $uid
        ) );
        return $id ? (int) $id : 0;
    }

    // ── Triathlon ────────────────────────────────────────────────────────────
    public static function is_triathlonwart() {
        if ( self::is_blocked() ) return false;
        if ( self::raw_is_admin() ) return true;
        return LSV07I_Permissions::can_current( LSV07I_Permissions::TRIATHLON_GRUPPE_CREATE );
    }

    public static function is_tri_intern() {
        if ( self::is_blocked() ) return false;
        if ( self::raw_is_admin() ) return true;
        return LSV07I_Permissions::has_any_in_bereich( get_current_user_id(), 'triathlon.' );
    }

    // ── Fitness ──────────────────────────────────────────────────────────────
    public static function is_fitnesswart() {
        if ( self::is_blocked() ) return false;
        if ( self::raw_is_admin() ) return true;
        return LSV07I_Permissions::can_current( LSV07I_Permissions::FITNESS_GRUPPE_CREATE );
    }

    public static function is_fit_intern() {
        if ( self::is_blocked() ) return false;
        if ( self::raw_is_admin() ) return true;
        return LSV07I_Permissions::has_any_in_bereich( get_current_user_id(), 'fitness.' );
    }

    /**
     * Fitness-Trainer-ID (analog zu get_trainer_id und get_tri_trainer_id).
     */
    public static function get_fit_trainer_id() {
        global $wpdb;
        $uid = get_current_user_id();
        if ( ! $uid ) return 0;
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}lsv07i_fit_trainer WHERE wp_user_id = %d AND aktiv = 1 LIMIT 1",
            $uid
        ) );
        return $id ? (int) $id : 0;
    }

    // ── Allgemein ────────────────────────────────────────────────────────────
    public static function has_any_access() {
        if ( self::is_blocked() ) return false;
        if ( self::is_intern()
            || self::is_trainer()
            || self::is_schwimmwart()
            || self::is_finanzwart()
            || self::is_tri_intern()
            || self::is_triathlonwart()
            || self::is_fit_intern()
            || self::is_fitnesswart()
            || self::is_admin() ) return true;

        // Auch wer per Rechteverwaltung ein Lese-Recht auf einen Bereich
        // besitzt, darf den internen Bereich betreten – sonst greift der
        // Gatekeeper vor der Bereichslogik und sperrt ihn komplett aus.
        if ( class_exists( 'LSV07I_Permissions' ) ) {
            $rechte = [
                LSV07I_Permissions::ABRECHNUNG_EIGEN_READ,
                LSV07I_Permissions::ABRECHNUNG_WART_READ,
                LSV07I_Permissions::ABRECHNUNG_KASSE_READ,
                LSV07I_Permissions::SCHWIMMEN_MANNSCHAFT_READ,
                LSV07I_Permissions::TRIATHLON_GRUPPE_READ,
                LSV07I_Permissions::FITNESS_GRUPPE_READ,
            ];
            foreach ( $rechte as $r ) {
                if ( LSV07I_Permissions::can_current( $r ) ) return true;
            }
        }
        return false;
    }

    public static function get_access_map() {
        $uid = get_current_user_id();
        $raw = self::raw_is_admin();
        $perm = class_exists( 'LSV07I_Permissions' );
        $can_wart  = $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_WART_READ );
        $can_kasse = $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_KASSE_READ );
        $can_eigen = $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_EIGEN_READ );

        $is_sw = self::is_schwimmwart() || $can_wart;
        $is_fw = self::is_finanzwart()  || $can_kasse;

        // Kleiner Helfer: volle Bereichsrolle ODER spezifisches Leserecht.
        $tab = function( $rolle_ok, $recht ) use ( $perm ) {
            return $rolle_ok || ( $perm && LSV07I_Permissions::can_current( $recht ) );
        };
        $sw_intern  = self::is_intern();
        $tri_intern = self::is_tri_intern();
        $fit_intern = self::is_fit_intern();

        // Pro-Tab-Sichtbarkeit: jeder Tab kann einzeln per Leserecht in der
        // Rechteverwaltung freigeschaltet werden. Wer die volle Bereichsrolle
        // hat, sieht ohnehin alle Tabs des Bereichs.
        $P = 'LSV07I_Permissions';
        $tabs = [
            // Schwimmen
            'sw_mann'    => $tab( $sw_intern, $P::SCHWIMMEN_MANNSCHAFT_READ ),
            'sw_anw'     => $tab( $sw_intern, $P::SCHWIMMEN_ANW_READ ),
            'sw_wk'      => $tab( $sw_intern, $P::SCHWIMMEN_WETTKAMPF_READ ),
            'sw_spr'     => $tab( $sw_intern, $P::SCHWIMMEN_SPRINGER_READ ),
            'sw_bz'      => $tab( $sw_intern, $P::SCHWIMMEN_BESTZEIT_READ ),
            'sw_refl'    => $tab( $sw_intern, $P::SCHWIMMEN_REFLEXION_READ ),
            // Wettkampfmeldungen: bewusst OHNE Fallback auf die Bereichsrolle.
            // Der Tab erscheint nur, wenn das Leserecht ausdrücklich vergeben
            // wurde. Admin sieht ihn immer.
            'sw_meld'    => self::is_admin()
                              || ( $perm && LSV07I_Permissions::can_current( $P::SCHWIMMEN_MELDUNG_READ ) ),
            // Trainerübersicht: zeigt Kontaktdaten aller Trainer und ist
            // deshalb standardmäßig nur für Administratoren sichtbar.
            // Alle anderen brauchen das eigene Leserecht, das in der
            // Rechteverwaltung einzeln vergeben werden kann.
            'sw_trainer' => self::is_admin()
                              || ( $perm && LSV07I_Permissions::can_current( $P::SCHWIMMEN_TRAINER_READ ) ),
            // Triathlon
            'tri_mann'   => $tab( $tri_intern, $P::TRIATHLON_GRUPPE_READ ),
            'tri_anw'    => $tab( $tri_intern, $P::TRIATHLON_ANW_READ ),
            'tri_trainer'=> self::is_admin()
                              || ( $perm && LSV07I_Permissions::can_current( $P::TRIATHLON_TRAINER_READ ) ),
            // Fitness
            'fit_mann'   => $tab( $fit_intern, $P::FITNESS_GRUPPE_READ ),
            'fit_anw'    => $tab( $fit_intern, $P::FITNESS_ANW_READ ),
            'fit_trainer'=> self::is_admin()
                              || ( $perm && LSV07I_Permissions::can_current( $P::FITNESS_TRAINER_READ ) ),
            // Admin-Unterbereiche mit bereits vorhandenen, bisher aber nie
            // zur Sichtbarkeit genutzten granularen Rechten. Die zugehörigen
            // Backends (class-ajax-log/-saison/-atteste/-rechte.php) prüfen
            // diese Rechte bereits als Alternative zu is_admin() — hier wird
            // nur die fehlende UI-Seite nachgezogen, damit ein Nutzer mit nur
            // EINEM dieser Rechte auch tatsächlich an den passenden Tab kommt,
            // statt vom pauschalen Admin-Gate ausgesperrt zu bleiben.
            'adm_log'     => self::is_admin()
                               || ( $perm && ( LSV07I_Permissions::can_current( $P::ADMIN_LOG_READ )
                                             || LSV07I_Permissions::can_current( $P::ADMIN_PERMISSIONS_READ ) ) ),
            'adm_saison'  => self::is_admin()
                               || ( $perm && ( LSV07I_Permissions::can_current( $P::ADMIN_SAISON_READ )
                                             || LSV07I_Permissions::can_current( $P::ADMIN_SAISON_MANAGE ) ) ),
            'adm_atteste' => self::is_admin()
                               || ( $perm && LSV07I_Permissions::can_current( $P::ADMIN_ATTEST_MAIL ) ),
            'adm_rechte'  => self::is_admin()
                               || ( $perm && ( LSV07I_Permissions::can_current( $P::ADMIN_PERMISSIONS_READ )
                                             || LSV07I_Permissions::can_current( $P::ADMIN_PERMISSIONS_UPDATE ) ) ),
            // Sonderabrechnung: bewusst OHNE Rollen-Fallback (auch nicht
            // is_trainer()) — dieser Tab soll ausschliesslich per explizit
            // vergebenem Recht sichtbar werden, unabhaengig davon, ob
            // jemand generell Trainer ist. Admin sieht ihn zum Testen immer.
            'tr_sonder'  => self::is_admin()
                              || ( $perm && LSV07I_Permissions::can_current( $P::ABRECHNUNG_SONDER_EIGEN_READ ) ),
        ];

        return [
            'schwimmen'       => $sw_intern || $tabs['sw_mann'] || $tabs['sw_anw'] || $tabs['sw_wk']
                                   || $tabs['sw_spr'] || $tabs['sw_bz'] || $tabs['sw_refl']
                                   || $tabs['sw_meld'],
            'verwaltung'      => $is_sw || $is_fw,
            'trainer'         => self::is_trainer() || $can_eigen,
            'admin'           => self::is_admin(),
            'triathlon'       => $tri_intern || $tabs['tri_mann'] || $tabs['tri_anw'],
            'tri_verwaltung'  => self::is_triathlonwart() || $is_fw,
            'fitness'         => $fit_intern || $tabs['fit_mann'] || $tabs['fit_anw'],
            'fit_verwaltung'  => self::is_fitnesswart() || $is_fw,
            'is_schwimmwart'  => $is_sw,
            'is_finanzwart'   => $is_fw,
            'is_triathlonwart'=> self::is_triathlonwart(),
            'is_fitnesswart'  => self::is_fitnesswart(),
            'is_admin'        => self::is_admin(),
            'is_admin_raw'    => $raw,
            'is_trainer'      => self::is_trainer() || $can_eigen,
            // Wettkampf-Freigabe: eigenes Recht, kein Rollen-Fallback (siehe check()).
            'can_wk_approve'  => self::is_admin()
                                   || ( $perm && LSV07I_Permissions::can_current( $P::SCHWIMMEN_WETTKAMPF_APPROVE ) ),
            'tabs'            => $tabs,
        ];
    }

    public static function check( $required = 'intern' ) {
        if ( ! check_ajax_referer( 'lsv07i_nonce', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Ungueltige Anfrage. Bitte Seite neu laden.' ], 403 );
        }
        $ok = false;
        // Permission-Fallback: wer das passende Recht hat, wird auch dann
        // zugelassen, wenn ihm die klassische WP-Rolle fehlt. So greifen die
        // in der Rechteverwaltung vergebenen Bereichsrechte tatsächlich.
        $perm = class_exists( 'LSV07I_Permissions' );
        switch ( $required ) {
            case 'intern':          $ok = self::is_intern();                                          break;
            // Nur-Lesen-Zugang zum Schwimmbereich (Mannschaftsliste = gemeinsame
            // Basis fast aller Tabs). Deshalb: volle intern-Rolle ODER IRGENDEIN
            // Schwimmen-Tab-Leserecht. So bekommt z.B. auch jemand mit reinem
            // Anwesenheits-Leserecht die zum Befüllen nötige Mannschaftsliste.
            case 'schwimmen_read':  $ok = self::is_intern()
                                          || ( $perm && (
                                                 LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_MANNSCHAFT_READ )
                                              || LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_ANW_READ )
                                              || LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_WETTKAMPF_READ )
                                              || LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_SPRINGER_READ )
                                              || LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_BESTZEIT_READ )
                                              || LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_REFLEXION_READ )
                                              // Meldungen brauchen die Mannschaftsliste zum Auswählen
                                              || LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_MELDUNG_READ ) ) ); break;
            // Tab-spezifische Lese-Zugänge: jeweils volle intern-Rolle ODER
            // das Leserecht des betreffenden Tabs. So kann ein Nutzer z.B.
            // nur die Bestzeiten sehen, ohne Zugriff auf alle anderen Tabs.
            case 'sw_anw_read':     $ok = self::is_intern()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_ANW_READ ) ); break;
            case 'sw_wk_read':      $ok = self::is_intern()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_WETTKAMPF_READ ) ); break;
            case 'sw_spr_read':     $ok = self::is_intern()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_SPRINGER_READ ) ); break;
            case 'sw_bz_read':      $ok = self::is_intern()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_BESTZEIT_READ ) ); break;
            case 'sw_refl_read':    $ok = self::is_intern()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_REFLEXION_READ ) ); break;
            // Wettkampfmeldungen: ausschliesslich per Recht, kein Rollen-Fallback.
            case 'sw_meld_read':    $ok = self::is_admin()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_MELDUNG_READ ) ); break;
            case 'sw_meld_edit':    $ok = self::is_admin()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_MELDUNG_EDIT ) ); break;
            case 'sw_meld_delete':  $ok = self::is_admin()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_MELDUNG_DELETE ) ); break;
            // Wettkampf-Freigabe: ausschliesslich per Recht, kein Rollen-Fallback —
            // wer freigeben darf, wird ausdrücklich in der Rechteverwaltung benannt.
            case 'sw_wk_approve':   $ok = self::is_admin()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_WETTKAMPF_APPROVE ) ); break;
            case 'tri_read':        $ok = self::is_tri_intern()
                                          || ( $perm && (
                                                 LSV07I_Permissions::can_current( LSV07I_Permissions::TRIATHLON_GRUPPE_READ )
                                              || LSV07I_Permissions::can_current( LSV07I_Permissions::TRIATHLON_ANW_READ ) ) ); break;
            case 'tri_anw_read':    $ok = self::is_tri_intern()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::TRIATHLON_ANW_READ ) ); break;
            case 'fit_read':        $ok = self::is_fit_intern()
                                          || ( $perm && (
                                                 LSV07I_Permissions::can_current( LSV07I_Permissions::FITNESS_GRUPPE_READ )
                                              || LSV07I_Permissions::can_current( LSV07I_Permissions::FITNESS_ANW_READ ) ) ); break;
            case 'fit_anw_read':    $ok = self::is_fit_intern()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::FITNESS_ANW_READ ) ); break;
            // Trainerübersicht der Sportbereiche: Admin ODER das eigens dafür
            // vergebene Leserecht. Bewusst KEIN Fallback auf die Bereichsrolle.
            case 'sw_trainer_read': $ok = self::is_admin()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::SCHWIMMEN_TRAINER_READ ) ); break;
            case 'tri_trainer_read':$ok = self::is_admin()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::TRIATHLON_TRAINER_READ ) ); break;
            case 'fit_trainer_read':$ok = self::is_admin()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::FITNESS_TRAINER_READ ) ); break;
            case 'trainer':         $ok = self::is_trainer()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_EIGEN_READ ) ); break;
            // Sonderabrechnung: bewusst KEIN is_trainer()-Fallback — siehe
            // Kommentar bei get_access_map()['tabs']['tr_sonder'].
            case 'sonder_eigen':    $ok = self::is_admin()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_SONDER_EIGEN_READ ) ); break;
            case 'schwimmwart':     $ok = self::is_schwimmwart()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_WART_READ ) ); break;
            case 'finanzwart':      $ok = self::is_finanzwart()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_KASSE_READ ) ); break;
            case 'verwaltung':      $ok = self::is_schwimmwart() || self::is_finanzwart()
                                          || ( $perm && ( LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_WART_READ )
                                                       || LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_KASSE_READ ) ) ); break;
            case 'admin':           $ok = self::is_admin();                                           break;
            case 'admin_raw':       $ok = self::raw_is_admin();                                       break;
            case 'tri_intern':      $ok = self::is_tri_intern();                                      break;
            case 'triathlonwart':   $ok = self::is_triathlonwart();                                   break;
            case 'tri_verwaltung':  $ok = self::is_triathlonwart() || self::is_finanzwart()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_KASSE_READ ) ); break;
            case 'fit_intern':      $ok = self::is_fit_intern();                                      break;
            case 'fitnesswart':     $ok = self::is_fitnesswart();                                     break;
            case 'fit_verwaltung':  $ok = self::is_fitnesswart() || self::is_finanzwart()
                                          || ( $perm && LSV07I_Permissions::can_current( LSV07I_Permissions::ABRECHNUNG_KASSE_READ ) ); break;
        }
        if ( ! $ok ) {
            wp_send_json_error( [ 'message' => 'Sie haben keine Berechtigung fuer diese Aktion.' ], 403 );
        }
    }
}
