<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LSV07I_Nachrichten_Altdaten — räumt die Reste der Nachrichtenfunktion weg.
 *
 * Die Nachrichtenfunktion (Chat, Verteiler, E-Mail an Externe, IMAP-Abruf,
 * Antwort-Links) ist seit 9.12.0 entfernt. Ihre Daten blieben bis zur
 * Entscheidung des Vereins liegen. Der Verein hat entschieden: Alles wird
 * gelöscht.
 *
 * Läuft bei jedem Versionswechsel und ist wiederholbar: Was schon weg ist,
 * wird übersprungen. Gelöscht wird:
 *   - die Tabellen der Unterhaltungen, Teilnehmer, Nachrichten, Anhänge,
 *     Reaktionen, der Mail-Warteschlange und die alte Nachrichtentabelle,
 *   - die (verschlüsselten) Anhänge unter lsv07i-private/nachrichten/,
 *   - die IMAP-Zugangsdaten und die Antwort-Domain in den Einstellungen,
 *   - der Online-Status („zuletzt gesehen") je Nutzer,
 *   - zwischengespeicherte „tippt …"-Anzeigen und Antwortzähler,
 *   - die Protokolleinträge der Nachrichtenfunktion.
 *
 * Nicht berührt: Tickets, Benachrichtigungen (Glocke), Kontakte.
 */
class LSV07I_Nachrichten_Altdaten {

    /** Kind vor Eltern: erst die abhängigen Tabellen. */
    const TABELLEN = [
        'lsv07i_konv_reaktion',
        'lsv07i_konv_anhang',
        'lsv07i_konv_msg',
        'lsv07i_konv_teilnehmer',
        'lsv07i_konv',
        'lsv07i_email_queue',
        'lsv07i_nachrichten',
    ];

    const EINSTELLUNGEN = [
        'email_imap_host', 'email_imap_user', 'email_imap_pass', 'email_imap_port',
        'email_reply_domain',
    ];

    const ERLEDIGT = 'lsv07i_nachrichten_geloescht';

    /**
     * Alles entfernen. Gibt die Zahlen zurück (für Protokoll und Prüfung).
     *
     * @return array{tabellen:int,dateien:int,einstellungen:int,status:int,zwischenspeicher:int,protokoll:int,rest:string[]}
     */
    public static function entfernen() {
        global $wpdb;
        $p = $wpdb->prefix;
        $n = [ 'tabellen' => 0, 'dateien' => 0, 'einstellungen' => 0, 'status' => 0,
               'zwischenspeicher' => 0, 'protokoll' => 0, 'rest' => [] ];

        /* 1. Anhänge zuerst: Sind die Tabellen weg, ist der Ordner trotzdem
              vollständig erfasst — gelöscht wird der ganze Ordner. */
        $n['dateien'] = self::ordner_leeren();

        /* 2. Tabellen. */
        foreach ( self::TABELLEN as $t ) {
            if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $p . $t ) ) ) ) continue;
            $wpdb->query( "DROP TABLE IF EXISTS `{$p}{$t}`" );
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $p . $t ) ) ) ) {
                /* Ohne DROP-Recht wenigstens den Inhalt löschen. */
                $wpdb->query( "DELETE FROM `{$p}{$t}`" );
                $n['rest'][] = $t;
            } else {
                $n['tabellen']++;
            }
        }

        /* 3. Zugangsdaten und Einstellungen der Nachrichtenfunktion. */
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $p . 'lsv07i_config' ) ) ) ) {
            $platz = implode( ',', array_fill( 0, count( self::EINSTELLUNGEN ), '%s' ) );
            $n['einstellungen'] = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$p}lsv07i_config WHERE cfg_key IN ($platz)", self::EINSTELLUNGEN ) );
        }

        /* 4. Online-Status je Nutzer. */
        $n['status'] = (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", 'lsv07i_last_seen' ) );
        if ( $n['status'] && function_exists( 'wp_cache_flush_group' ) ) wp_cache_flush_group( 'user_meta' );

        /* 5. Zwischenspeicher: „tippt …", Antwortzähler, Übernahme-Merker. */
        foreach ( [ 'lsv07i_typing_', 'lsv07i_reply_n_' ] as $vor ) {
            foreach ( [ '_transient_', '_transient_timeout_' ] as $art ) {
                $n['zwischenspeicher'] += (int) $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $wpdb->esc_like( $art . $vor ) . '%' ) );
            }
        }
        if ( delete_option( 'lsv07i_konv_migrated' ) ) $n['zwischenspeicher']++;

        /* 6. Protokolleinträge der Nachrichtenfunktion. */
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $p . 'lsv07i_log' ) ) ) ) {
            $n['protokoll'] = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$p}lsv07i_log WHERE aktion LIKE %s OR bereich = %s",
                $wpdb->esc_like( 'nachrichten.' ) . '%', 'Nachrichten' ) );
        }

        /* Nur wenn wirklich etwas weg ist, ein Eintrag — ohne Namen, nur Zahlen. */
        if ( $n['tabellen'] || $n['dateien'] || $n['einstellungen'] || $n['status'] || $n['protokoll'] || $n['rest'] ) {
            LSV07I_Log::write( 'datenschutz.nachrichten_geloescht', [
                'bereich' => 'Datenschutz',
                'erfolg'  => empty( $n['rest'] ),
                'details' => sprintf( 'Reste der Nachrichtenfunktion gelöscht: %d Tabellen, %d Dateien, %d Einstellungen, %d Online-Status, %d Protokolleinträge%s',
                    $n['tabellen'], $n['dateien'], $n['einstellungen'], $n['status'], $n['protokoll'],
                    $n['rest'] ? '; nur geleert (kein DROP-Recht): ' . implode( ', ', $n['rest'] ) : '' ),
            ] );
        }
        if ( empty( $n['rest'] ) && ! self::ordner_da() ) update_option( self::ERLEDIGT, gmdate( 'Y-m-d H:i:s' ), false );
        return $n;
    }

    private static function ordner() {
        $up = wp_upload_dir( null, false );
        if ( ! empty( $up['error'] ) || empty( $up['basedir'] ) ) return '';
        return trailingslashit( $up['basedir'] ) . 'lsv07i-private/nachrichten';
    }

    public static function ordner_da() {
        $o = self::ordner();
        return $o !== '' && file_exists( $o );
    }

    /**
     * Den Ordner lsv07i-private/nachrichten samt Inhalt löschen.
     *
     * Sicher gegen Umlenkung: Symlinks werden nie verfolgt, nur selbst
     * entfernt. Jeder Pfad muss im echten privaten Ordner liegen.
     */
    private static function ordner_leeren() {
        $o = self::ordner();
        if ( $o === '' || ! file_exists( $o ) ) return 0;
        if ( is_link( $o ) ) { @unlink( $o ); return 0; }   // nie dem Link folgen
        $basis = realpath( dirname( $o ) );
        $echt  = realpath( $o );
        if ( ! $basis || ! $echt || strpos( $echt, $basis . DIRECTORY_SEPARATOR ) !== 0 ) return 0;

        $zahl = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $echt, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST );
        foreach ( $it as $f ) {
            $pfad = $f->getPathname();
            if ( strpos( $pfad, $echt . DIRECTORY_SEPARATOR ) !== 0 ) continue;
            $link = $f->isLink();
            if ( $link || $f->isFile() ) { if ( @unlink( $pfad ) && ! $link ) $zahl++; }
            elseif ( $f->isDir() ) @rmdir( $pfad );
        }
        @rmdir( $echt );
        return $zahl;
    }
}
