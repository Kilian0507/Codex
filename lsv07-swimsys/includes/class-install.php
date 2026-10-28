<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Das Schema anlegen — aber nur, wenn es sonst niemand tut.
 *
 * SwimSys soll den internen Bereich eines Tages ersetzen können. Dafür
 * muss es die Tabellen selbst anlegen können. Solange der interne Bereich
 * läuft, darf es das NICHT: Zwei Plugins, die dieselbe Tabelle anlegen und
 * nachbessern, bessern irgendwann gegeneinander nach, und hinterher ist
 * nicht zu ermitteln, wer welche Spalte gesetzt hat. Genau diese Sorte
 * Fehler kostet Tage.
 *
 * Deshalb die Regel: Ist der interne Bereich aktiv, gehört ihm das Schema
 * und SwimSys rührt es nicht an. Ist er weg, übernimmt SwimSys —
 * einschliesslich der Spalten, die dort nachträglich dazukamen.
 *
 * Geändert wird nie eine bestehende Spalte. Eine fehlende Tabelle wird
 * angelegt, eine fehlende Spalte ergänzt; alles andere bleibt, wie es ist.
 */
class LSV07S_Install {

    const OPTION = 'lsv07s_schema_ver';

    /** Für Prüfungen: die Erkennung des internen Bereichs überschreiben. */
    public static $intern_da = null;

    public static function init() {
        add_action( 'plugins_loaded', [ __CLASS__, 'pruefen' ], 30 );
    }

    /**
     * Läuft der interne Bereich?
     *
     * Erkannt an seinen Klassen, nicht an einer Pluginliste: Die Klassen
     * sind da, wenn der Code geladen ist — und nur dann kann er auch
     * Tabellen anlegen.
     */
    public static function intern_aktiv() {
        if ( self::$intern_da !== null ) return (bool) self::$intern_da;
        return class_exists( 'LSV07I_DB' ) || class_exists( 'LSV07I_Permissions' );
    }

    /**
     * Beim Laden: anlegen, falls nötig.
     *
     * „Nötig" heisst: Der interne Bereich ist weg UND entweder fehlt eine
     * Pflichttabelle oder die gespeicherte Fassung passt nicht mehr. Das
     * ist dasselbe Verfahren wie in den anderen Plugins — es heilt sich
     * selbst, auch wenn die Aktivierung einmal nicht durchlief.
     */
    public static function pruefen() {
        if ( self::intern_aktiv() ) return false;
        if ( get_option( self::OPTION ) === LSV07S_VERSION
             && LSV07S_Tabellen::fehlt() === [] ) {
            return false;
        }
        return self::anlegen();
    }

    /** Die Tabellen anlegen und die nachträglichen Spalten ergänzen. */
    public static function anlegen() {
        global $wpdb;
        if ( self::intern_aktiv() ) return false;

        $charset = method_exists( $wpdb, 'get_charset_collate' )
            ? $wpdb->get_charset_collate()
            : 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        foreach ( LSV07S_Schema::tabellen() as $name => $spalten ) {
            $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}$name ("
                . $spalten . "\n            ) ENGINE=InnoDB $charset" );
        }

        foreach ( LSV07S_Schema::nachtrag() as $n ) {
            [ $tabelle, $spalte, $typ ] = $n;
            $voll = $wpdb->prefix . $tabelle;
            /* Nur ergänzen, nie ändern: Ein ALTER auf eine vorhandene
               Spalte würde Daten umdeuten. */
            $da = $wpdb->get_results( $wpdb->prepare(
                "SHOW COLUMNS FROM $voll LIKE %s", $spalte ) );
            if ( $da ) continue;
            $wpdb->query( "ALTER TABLE $voll ADD COLUMN $spalte $typ" );
        }

        LSV07S_Tabellen::vergessen();
        update_option( self::OPTION, LSV07S_VERSION );

        LSV07S_Log::schreibe( 'schema.angelegt', [
            'bereich' => 'install',
            'details' => 'SwimSys hat das Schema übernommen; der interne Bereich ist nicht aktiv.',
        ] );
        return true;
    }

    /**
     * Was beim Umschalten zu tun ist — als Text, damit es nicht im Kopf
     * eines Einzelnen steht.
     */
    public static function umschaltweg() {
        return [
            'SwimSys aufrufen und prüfen, dass jeder Bereich Daten zeigt.',
            'In der Verwaltung von SwimSys nachsehen, welche Konten noch keine '
            . 'Rechte haben — diese Konten sehen nach dem Umschalten nichts.',
            'Den Shortcode des internen Bereichs auf der Seite durch [swimsys] ersetzen, '
            . 'die alte Seite aber noch nicht löschen.',
            'Eine Woche beide Plugins parallel laufen lassen.',
            'Den internen Bereich deaktivieren, NICHT löschen. SwimSys legt ab dem '
            . 'nächsten Aufruf das Schema selbst nach.',
            'Nach einer weiteren Woche ohne Beanstandung den internen Bereich löschen. '
            . 'Die Tabellen bleiben dabei stehen — sie gehören jetzt SwimSys.',
        ];
    }
}
