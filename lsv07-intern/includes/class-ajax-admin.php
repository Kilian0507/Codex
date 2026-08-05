<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LSV07I_Ajax_Admin {

    public static function init() {
        $actions = [
            'lsv07i_admin_get_data',
            'lsv07i_admin_save_trainer',
            'lsv07i_admin_delete_trainer',
            'lsv07i_admin_save_mannschaft',
            'lsv07i_admin_delete_mannschaft',
            'lsv07i_admin_save_slot',
            'lsv07i_admin_delete_slot',
            'lsv07i_admin_get_slots',
            'lsv07i_admin_get_wp_users',
            'lsv07i_admin_save_config',
            'lsv07i_admin_get_config',
            'lsv07i_admin_save_schwimmer',
            'lsv07i_admin_delete_schwimmer',
            'lsv07i_admin_get_schwimmer',
            'lsv07i_admin_mail_test',
        ];
        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, str_replace( 'lsv07i_admin_', '', $action ) ] );
        }
    }

    /**
     * Slots für den Admin-Slot-Tab laden — mit Saison-Filter aus dem Request.
     * Akzeptiert: 'aktiv' (default), 'alle', oder eine konkrete Saison-ID.
     */
    public static function get_slots() {
        LSV07I_Access::check( 'admin' );
        $filter = isset( $_POST['saison'] ) ? sanitize_text_field( wp_unslash( $_POST['saison'] ) ) : 'aktiv';
        wp_send_json_success( [ 'slots' => LSV07I_DB::get_slots( 0, $filter ) ] );
    }

    public static function get_data() {
        LSV07I_Access::check( 'admin' );
        wp_send_json_success( [
            'trainer'     => LSV07I_DB::get_all_trainer(),
            'mannschaften'=> LSV07I_DB::get_mannschaften(),
            'slots'       => LSV07I_DB::get_slots(),
            'schwimmer'   => LSV07I_DB::get_schwimmer(),
        ] );
    }

    public static function get_wp_users() {
        LSV07I_Access::check( 'admin' );
        $users = get_users( [ 'fields' => [ 'ID', 'display_name', 'user_email' ] ] );
        $result = array_map( function( $u ) {
            return [
                'id'    => $u->ID,
                'name'  => $u->display_name,
                'email' => $u->user_email,
            ];
        }, $users );
        wp_send_json_success( $result );
    }

    public static function save_trainer() {
        LSV07I_Access::check( 'admin' );
        global $wpdb;
        $p = $wpdb->prefix;

        $id               = absint( $_POST['id'] ?? 0 );
        $nachname         = sanitize_text_field( $_POST['nachname']          ?? '' );
        $vorname          = sanitize_text_field( $_POST['vorname']           ?? '' );
        $geburtsdatum     = sanitize_text_field( $_POST['geburtsdatum']      ?? '' );
        $telefon          = sanitize_text_field( $_POST['telefon']           ?? '' );
        $email_privat     = sanitize_email(      $_POST['email_privat']      ?? '' );
        $email_geschaeft  = sanitize_email(      $_POST['email_geschaeft']   ?? '' );
        $trainervertrag   = absint( $_POST['trainervertrag']  ?? 0 );
        $fuehrungszeugnis = sanitize_text_field( $_POST['fuehrungszeugnis']  ?? '' );
        $verhaltenskodex  = absint( $_POST['verhaltenskodex'] ?? 0 );
        $datenschutz      = absint( $_POST['datenschutz']     ?? 0 );
        $rettungsschwimmer= sanitize_text_field( $_POST['rettungsschwimmer'] ?? '' );
        $trainerlizenz    = absint( $_POST['trainerlizenz']   ?? 0 );
        $lizenznummer     = sanitize_text_field( $_POST['lizenznummer']      ?? '' );
        $lizenz_gueltig   = sanitize_text_field( $_POST['lizenz_gueltig']    ?? '' );
        $wp_user_id       = absint( $_POST['wp_user_id'] ?? 0 );
        $mannschaften     = array_filter( array_map( 'absint', (array) ( $_POST['mannschaften'] ?? [] ) ) );

        if ( ! $nachname || ! $vorname || ! $geburtsdatum ) {
            wp_send_json_error( [ 'message' => 'Nachname, Vorname und Geburtsdatum sind Pflichtfelder.' ] );
        }

        // display_name berechnen
        $jahr         = $geburtsdatum ? date( 'Y', strtotime( $geburtsdatum ) ) : '';
        $display_name = $nachname . ', ' . $vorname . ( $jahr ? ' (' . $jahr . ')' : '' );
        // name-Feld (Abwärtskompatibilität) = "Vorname Nachname"
        $name_compat  = trim( $vorname . ' ' . $nachname );

        // wp_user_id eindeutig prüfen
        if ( $wp_user_id ) {
            $existing = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}lsv07i_trainer WHERE wp_user_id = %d AND id != %d AND aktiv = 1 LIMIT 1",
                $wp_user_id, $id
            ) );
            if ( $existing ) wp_send_json_error( [ 'message' => 'Dieses WordPress-Konto ist bereits einem anderen Trainer zugeordnet.' ] );
        }

        $data = [
            'name'             => $name_compat,
            'vorname'          => $vorname,
            'email'            => $email_privat, // Abwärtskompatibilität
            'email_privat'     => $email_privat,
            'email_geschaeft'  => $email_geschaeft,
            'telefon'          => $telefon,
            'geburtsdatum'     => $geburtsdatum ?: null,
            'trainervertrag'   => $trainervertrag,
            'fuehrungszeugnis' => $fuehrungszeugnis ?: null,
            'verhaltenskodex'  => $verhaltenskodex,
            'datenschutz'      => $datenschutz,
            'rettungsschwimmer'=> $rettungsschwimmer ?: null,
            'trainerlizenz'    => $trainerlizenz,
            'lizenznummer'     => $lizenznummer,
            'lizenz_gueltig'   => $lizenz_gueltig ?: null,
            'display_name'     => $display_name,
            'wp_user_id'       => $wp_user_id,
        ];
        $formats = [ '%s','%s','%s','%s','%s','%s','%s','%d','%s','%d','%d','%s','%d','%s','%s','%s','%d' ];

        if ( $id ) {
            $is_new = false;
            $wpdb->update( $p . 'lsv07i_trainer', $data, [ 'id' => $id ], $formats, [ '%d' ] );
        } else {
            $is_new = true;
            $data['aktiv'] = 1;
            $formats[] = '%d';
            $wpdb->insert( $p . 'lsv07i_trainer', $data, $formats );
            $id = $wpdb->insert_id;
            if ( ! $id ) { error_log( 'LSV07I admin DB: ' . $wpdb->last_error ); wp_send_json_error( [ 'message' => 'Datenbankfehler. Bitte erneut versuchen.' ] ); }
        }

        // Mannschafts-Zuordnungen
        $wpdb->delete( $p . 'lsv07i_trainer_mannschaft', [ 'trainer_id' => $id ], [ '%d' ] );
        foreach ( $mannschaften as $mid ) {
            $wpdb->insert( $p . 'lsv07i_trainer_mannschaft',
                [ 'trainer_id' => $id, 'mannschaft_id' => $mid ], [ '%d', '%d' ] );
        }

        // WP-Rolle setzen
        if ( $wp_user_id ) {
            $wp_user = get_user_by( 'ID', $wp_user_id );
            if ( $wp_user ) {
                $has_role = array_intersect( (array) $wp_user->roles,
                    [ LSV07I_ROLE_INTERN, LSV07I_ROLE_SCHWIMMWART, LSV07I_ROLE_FINANZWART, 'administrator' ] );
                if ( ! $has_role ) $wp_user->add_role( LSV07I_ROLE_INTERN );
            }
        }

        LSV07I_Log::write( $is_new ? 'trainer.create' : 'trainer.update', [
            'bereich'   => 'Schwimmen',
            'ziel_typ'  => 'trainer',
            'ziel_id'   => $id,
            'ziel_name' => trim( $nachname . ', ' . $vorname ),
        ] );
        wp_send_json_success( [ 'id' => $id, 'trainer' => LSV07I_DB::get_all_trainer() ] );
    }


    public static function delete_trainer() {
        LSV07I_Access::check( 'admin' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Fehlende ID.' ] );

        // Name für Log holen, bevor wir deaktivieren
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$p}lsv07i_trainer WHERE id = %d", $id ) );

        $wpdb->update( $p . 'lsv07i_trainer', [ 'aktiv' => 0 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        LSV07I_Log::write( 'trainer.delete', [
            'bereich'   => 'Schwimmen',
            'ziel_typ'  => 'trainer',
            'ziel_id'   => $id,
            'ziel_name' => $name ?: ('ID ' . $id),
        ] );
        wp_send_json_success( [ 'trainer' => LSV07I_DB::get_all_trainer() ] );
    }

    public static function save_mannschaft() {
        LSV07I_Access::check( 'admin' );
        global $wpdb;
        $p    = $wpdb->prefix;
        $id   = absint( $_POST['id'] ?? 0 );
        $name = sanitize_text_field( $_POST['name'] ?? '' );
        $sort = absint( $_POST['sort_order'] ?? 0 );

        if ( ! $name ) wp_send_json_error( [ 'message' => 'Name darf nicht leer sein.' ] );

        // Duplikat-Check
        $dup = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}lsv07_gruppen WHERE name = %s AND id != %d LIMIT 1",
            $name, $id
        ) );
        if ( $dup ) wp_send_json_error( [ 'message' => 'Eine Mannschaft mit diesem Namen existiert bereits.' ] );

        if ( $id ) {
            $is_new = false;
            $wpdb->update( $p . 'lsv07_gruppen',
                [ 'name' => $name, 'sort_order' => $sort ],
                [ 'id'   => $id ],
                [ '%s', '%d' ], [ '%d' ]
            );
        } else {
            $is_new = true;
            $wpdb->insert( $p . 'lsv07_gruppen',
                [ 'name' => $name, 'sort_order' => $sort ],
                [ '%s', '%d' ]
            );
            $id = $wpdb->insert_id;
        }

        LSV07I_Log::write( $is_new ? 'mannschaft.create' : 'mannschaft.update', [
            'bereich'   => 'Schwimmen',
            'ziel_typ'  => 'mannschaft',
            'ziel_id'   => $id,
            'ziel_name' => $name,
        ] );
        wp_send_json_success( [
            'id'          => $id,
            'mannschaften' => LSV07I_DB::get_mannschaften(),
        ] );
    }

    public static function delete_mannschaft() {
        LSV07I_Access::check( 'admin' );
        global $wpdb;
        $p  = $wpdb->prefix;
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Fehlende ID.' ] );

        // Pruefen ob Schwimmer zugeordnet
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}mv_swimmers WHERE team_id = %d AND active = 1", $id
        ) );
        if ( $count > 0 ) {
            wp_send_json_error( [ 'message' => "Die Mannschaft hat noch $count aktive Schwimmer. Bitte zuvor umbuchen." ] );
        }
        // Name für Log holen
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$p}lsv07_gruppen WHERE id = %d", $id ) );
        $wpdb->delete( $p . 'lsv07_gruppen', [ 'id' => $id ], [ '%d' ] );
        LSV07I_Log::write( 'mannschaft.delete', [
            'bereich'   => 'Schwimmen',
            'ziel_typ'  => 'mannschaft',
            'ziel_id'   => $id,
            'ziel_name' => $name ?: ('ID ' . $id),
        ] );
        wp_send_json_success( [ 'mannschaften' => LSV07I_DB::get_mannschaften() ] );
    }

    public static function save_slot() {
        LSV07I_Access::check( 'admin' );
        global $wpdb;
        $p             = $wpdb->prefix;
        $id            = absint( $_POST['id'] ?? 0 );
        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        $wochentag     = absint( $_POST['wochentag'] ?? 1 );
        $zeit_von      = sanitize_text_field( $_POST['zeit_von'] ?? '00:00' );
        $zeit_bis      = sanitize_text_field( $_POST['zeit_bis'] ?? '00:00' );

        if ( ! $mannschaft_id ) wp_send_json_error( [ 'message' => 'Bitte Mannschaft auswaehlen.' ] );
        if ( $wochentag < 1 || $wochentag > 7 ) wp_send_json_error( [ 'message' => 'Ungueltiger Wochentag.' ] );

        // Saison-ID: explizit übergeben oder aktive Saison als Default.
        // Beim Update wird saison_id nicht geändert, außer es wurde explizit
        // mitgegeben — Slot-Migration zwischen Saisons ist kein normaler Workflow.
        $saison_id = isset( $_POST['saison_id'] ) ? absint( $_POST['saison_id'] ) : 0;

        $data    = [ 'mannschaft_id' => $mannschaft_id, 'wochentag' => $wochentag, 'zeit_von' => $zeit_von, 'zeit_bis' => $zeit_bis ];
        $formats = [ '%d', '%d', '%s', '%s' ];

        if ( $id ) {
            $is_new = false;
            // saison_id beim Update nur ändern, wenn explizit übergeben
            if ( $saison_id ) {
                $data['saison_id'] = $saison_id;
                $formats[] = '%d';
            }
            $wpdb->update( $p . 'lsv07i_training_slots', $data, [ 'id' => $id ], $formats, [ '%d' ] );
        } else {
            $is_new = true;
            // Beim Anlegen: explizite saison_id oder aktive Saison als Fallback
            $data['saison_id'] = $saison_id ?: LSV07I_Saison::active_id();
            $formats[] = '%d';
            $wpdb->insert( $p . 'lsv07i_training_slots', $data, $formats );
            $id = $wpdb->insert_id;
        }
        $wt_namen = [1=>'Mo',2=>'Di',3=>'Mi',4=>'Do',5=>'Fr',6=>'Sa',7=>'So'];
        LSV07I_Log::write( $is_new ? 'slot.create' : 'slot.update', [
            'bereich'   => 'Schwimmen',
            'ziel_typ'  => 'slot',
            'ziel_id'   => $id,
            'details'   => 'Mannschaft-ID: ' . $mannschaft_id . ', ' . ( $wt_namen[ $wochentag ] ?? '?' ) . ' ' . $zeit_von . '-' . $zeit_bis,
        ] );
        wp_send_json_success( [ 'id' => $id, 'slots' => LSV07I_DB::get_slots() ] );
    }

    public static function delete_slot() {
        LSV07I_Access::check( 'admin' );
        global $wpdb;
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Fehlende ID.' ] );
        $wpdb->delete( $wpdb->prefix . 'lsv07i_training_slots', [ 'id' => $id ], [ '%d' ] );
        LSV07I_Log::write( 'slot.delete', [
            'bereich'  => 'Schwimmen',
            'ziel_typ' => 'slot',
            'ziel_id'  => $id,
        ] );
        wp_send_json_success( [ 'slots' => LSV07I_DB::get_slots() ] );
    }

    public static function save_config() {
        LSV07I_Access::check( 'admin' );
        $allowed = [
            'km_satz', 'km_min', 'wk_pauschale', 'mail_schwimmwart',
            'anwesenheit_mahnung_stunden', 'mail_deaktiviert',
            'attest_warnung_tage', 'blocked_user_ids',
            // Mail-Toggles
            'mail_attest_trainer', 'mail_attest_kontakt',
            'mail_anwesenheit_trainer', 'mail_springer_eintrag',
            'mail_springer_austrag', 'mail_bestzeiten_trainer',
            'mail_abr_schwimmwart', 'mail_abr_kassenwart', 'mail_abr_trainer',
            'mail_wk_approve_anfrage', 'mail_wk_erinnerung_meldeergebnis',
            'mail_wk_erinnerung_protokoll',
        ];
        $changed = [];
        foreach ( $allowed as $key ) {
            if ( isset( $_POST[ $key ] ) ) {
                LSV07I_DB::set_config( $key, sanitize_text_field( $_POST[ $key ] ) );
                $changed[] = $key;
            }
        }
        if ( $changed ) {
            LSV07I_Log::write( 'konfig.update', [
                'bereich' => 'Admin',
                'ziel_typ' => 'konfig',
                'details' => implode( ', ', $changed ),
            ] );
        }
        wp_send_json_success();
    }

    public static function get_config() {
        LSV07I_Access::check( 'admin' );
        $keys = [
            'km_satz', 'km_min', 'wk_pauschale', 'mail_schwimmwart',
            'anwesenheit_mahnung_stunden', 'mail_deaktiviert',
            'attest_warnung_tage',
            // Mail-Toggles (Standard: aktiviert)
            'mail_attest_trainer', 'mail_attest_kontakt',
            'mail_anwesenheit_trainer', 'mail_springer_eintrag',
            'mail_springer_austrag', 'mail_bestzeiten_trainer',
            'mail_abr_schwimmwart', 'mail_abr_kassenwart', 'mail_abr_trainer',
            'mail_wk_approve_anfrage', 'mail_wk_erinnerung_meldeergebnis',
            'mail_wk_erinnerung_protokoll',
        ];
        $result = [];
        foreach ( $keys as $k ) {
            // Mail-Toggles default: '1' (aktiviert)
            $default = ( substr( $k, 0, 5 ) === 'mail_' ) ? '1' : '';
            $result[ $k ] = LSV07I_DB::get_config( $k, $default );
        }
        wp_send_json_success( $result );
    }

    /**
     * Testmail für eine einzelne Benachrichtigung: exakt der Wortlaut der
     * echten Mail, mit Beispieldaten, an eine frei eingegebene Adresse —
     * unabhängig vom Ein/Aus-Zustand des jeweiligen Hakens. Prüft das
     * tatsächliche Ergebnis von wp_mail() und meldet einen Fehlschlag
     * (z. B. fehlende SMTP-Konfiguration) explizit zurück, statt still
     * "erfolgreich" zu melden.
     */
    public static function mail_test() {
        LSV07I_Access::check( 'admin' );
        $to  = sanitize_email( wp_unslash( $_POST['to'] ?? '' ) );
        $key = sanitize_key( $_POST['toggle_key'] ?? '' );
        if ( ! $to || ! is_email( $to ) ) {
            wp_send_json_error( [ 'message' => 'Bitte eine gültige Test-E-Mail-Adresse angeben.' ] );
        }
        if ( ! array_key_exists( $key, LSV07I_Mail::TOGGLES ) ) {
            wp_send_json_error( [ 'message' => 'Unbekannte Benachrichtigung.' ] );
        }
        $inhalt = LSV07I_Mail::test_inhalt( $key );
        if ( ! $inhalt ) {
            wp_send_json_error( [ 'message' => 'Für diese Benachrichtigung gibt es noch keine Testmail-Vorlage.' ] );
        }
        [ $subject, $body ] = $inhalt;
        $ok = LSV07I_Mail::send_test( $to, $subject, $body );
        if ( ! $ok ) {
            wp_send_json_error( [ 'message' => 'Versand fehlgeschlagen (Mailserver). Bitte die SMTP-Konfiguration in WordPress prüfen.' ] );
        }
        LSV07I_Log::write( 'admin.mail_test', [
            'bereich'   => 'Admin',
            'ziel_typ'  => 'mail_toggle',
            'ziel_name' => $key,
            'details'   => $to,
        ] );
        wp_send_json_success( [ 'message' => 'Testmail an ' . $to . ' gesendet.' ] );
    }

    public static function save_schwimmer() {
        LSV07I_Access::check( 'admin' );
        global $wpdb;
        $p             = $wpdb->prefix;
        $id            = absint( $_POST['id'] ?? 0 );
        $last_name     = sanitize_text_field( $_POST['last_name']  ?? '' );
        $first_name    = sanitize_text_field( $_POST['first_name'] ?? '' );
        $birth_date    = sanitize_text_field( $_POST['birth_date'] ?? '' );
        $attest        = sanitize_text_field( $_POST['attest_expires'] ?? '' );
        $dsv_id        = sanitize_text_field( $_POST['dsv_id']     ?? '' );
        $datenschutz   = absint( $_POST['datenschutz']  ?? 0 );
        $notes         = sanitize_textarea_field( $_POST['notes']  ?? '' );
        $gruppe_ids    = array_filter( array_map( 'absint', (array) ( $_POST['gruppe_ids'] ?? [] ) ) );
        $kontakte_raw  = sanitize_text_field( $_POST['kontakte_json'] ?? '[]' );
        $kontakte      = json_decode( stripslashes( $kontakte_raw ), true );
        if ( ! is_array( $kontakte ) ) $kontakte = [];

        if ( ! $last_name || ! $first_name || ! $birth_date ) {
            wp_send_json_error( [ 'message' => 'Nachname, Vorname und Geburtsdatum sind Pflichtfelder.' ] );
        }

        $team_id = ! empty( $gruppe_ids ) ? $gruppe_ids[0] : 0;

        // display_name berechnen
        $jahr         = $birth_date ? date( 'Y', strtotime( $birth_date ) ) : '';
        $display_name = $last_name . ', ' . $first_name . ( $jahr ? ' (' . $jahr . ')' : '' );

        $data    = [
            'team_id'        => $team_id,
            'last_name'      => $last_name,
            'first_name'     => $first_name,
            'birth_date'     => $birth_date,
            'attest_expires' => $attest ?: null,
            'dsv_id'         => $dsv_id,
            'datenschutz'    => $datenschutz,
            'display_name'   => $display_name,
            'notes'          => $notes,
            'active'         => 1,
        ];
        $formats = [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' ];

        if ( $id ) {
            $is_new = false;
            unset( $data['active'] );
            array_pop( $formats );
            $wpdb->update( $p . 'mv_swimmers', $data, [ 'id' => $id ], $formats, [ '%d' ] );
        } else {
            $is_new = true;
            $wpdb->insert( $p . 'mv_swimmers', $data, $formats );
            $id = $wpdb->insert_id;
        }

        // Bestzeiten folgen dem Schwimmer: mannschaft_id der Bestzeiten auf
        // die aktuelle Mannschaft des Schwimmers aktualisieren.
        if ( $id ) {
            $wpdb->update(
                $p . 'lsv07i_bestzeiten',
                [ 'mannschaft_id' => (int) $team_id ],
                [ 'swimmer_id'    => (int) $id ],
                [ '%d' ], [ '%d' ]
            );
        }

        // Mehrfach-Mannschaft-Zuordnung
        $wpdb->delete( $p . 'lsv07i_swimmer_gruppen', [ 'swimmer_id' => $id ], [ '%d' ] );
        foreach ( array_unique( $gruppe_ids ) as $gid ) {
            $wpdb->insert( $p . 'lsv07i_swimmer_gruppen',
                [ 'swimmer_id' => $id, 'gruppe_id' => $gid ], [ '%d', '%d' ] );
        }

        LSV07I_DB::save_kontakte( $id, $kontakte );
        LSV07I_Log::write( $is_new ? 'schwimmer.create' : 'schwimmer.update', [
            'bereich'   => 'Schwimmen',
            'ziel_typ'  => 'schwimmer',
            'ziel_id'   => $id,
            'ziel_name' => trim( ( $first_name ?? '' ) . ' ' . ( $last_name ?? '' ) ),
        ] );
        wp_send_json_success( [ 'id' => $id, 'schwimmer' => LSV07I_DB::get_schwimmer() ] );
    }

    public static function delete_schwimmer() {
        LSV07I_Access::check( 'admin' );
        global $wpdb;
        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( [ 'message' => 'Fehlende ID.' ] );
        // Vor Soft-Delete den Namen holen
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT display_name FROM {$wpdb->prefix}mv_swimmers WHERE id = %d", $id ) );
        $wpdb->update( $wpdb->prefix . 'mv_swimmers', [ 'active' => 0 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
        LSV07I_Log::write( 'schwimmer.delete', [
            'bereich'   => 'Schwimmen',
            'ziel_typ'  => 'schwimmer',
            'ziel_id'   => $id,
            'ziel_name' => $name ?: ('ID ' . $id),
        ] );
        wp_send_json_success( [ 'schwimmer' => LSV07I_DB::get_schwimmer() ] );
    }

    public static function get_schwimmer() {
        LSV07I_Access::check( 'admin' );
        $mannschaft_id = absint( $_POST['mannschaft_id'] ?? 0 );
        wp_send_json_success( LSV07I_DB::get_schwimmer( $mannschaft_id ) );
    }
}
