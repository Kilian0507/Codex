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
     * Die eigenen Tabellen — immer, auch wenn der interne Bereich läuft.
     *
     * Es sind zwei, und sie tragen den eigenen Präfix (`lsv07s_`): die
     * Schalter. Der interne Bereich kennt sie nicht, es gibt also
     * nichts, worüber zwei Plugins hier gegeneinander arbeiten könnten.
     * Ohne sie wäre SwimSys für jeden ausser der Administration leer.
     */
    public static function eigene_anlegen() {
        global $wpdb;
        $charset = method_exists( $wpdb, 'get_charset_collate' )
            ? $wpdb->get_charset_collate()
            : 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        foreach ( LSV07S_Schalter::tabellen() as $name => $spalten ) {
            $wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}$name ("
                . $spalten . "\n            ) ENGINE=InnoDB $charset" );
        }
        LSV07S_Schalter::vergessen();
        return true;
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
        /* Die eigenen Tabellen zuerst und unabhängig davon, ob der
           interne Bereich läuft: Sie gehören SwimSys. */
        if ( ! LSV07S_Schalter::tabelle_da( LSV07S_Schalter::T_GLOBAL )
             || ! LSV07S_Schalter::tabelle_da( LSV07S_Schalter::T_KONTO ) ) {
            self::eigene_anlegen();
        }
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
        self::eigene_anlegen();
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

        /* Geweitete Typen. Das ist die eine Ausnahme von „nie ändern":
           Der interne Bereich hat denselben Typ selbst geweitet (aus
           `status ENUM('offen','abgegeben')` wurde einer mit
           `archiviert`), und `tabellen()` legt eine neue Tabelle schon
           so an. Nötig ist das nur für eine Tabelle, die eine ältere
           Fassung von SwimSys eng angelegt hat — `CREATE TABLE IF NOT
           EXISTS` rührt eine vorhandene nicht an. Ein wiederholtes
           MODIFY mit demselben Typ ist in MySQL in Ordnung. */
        foreach ( LSV07S_Schema::weiten() as $w ) {
            [ $tabelle, $spalte, $typ ] = $w;
            $voll = $wpdb->prefix . $tabelle;
            $da = $wpdb->get_row( $wpdb->prepare(
                "SHOW COLUMNS FROM $voll LIKE %s", $spalte ) );
            if ( ! $da ) continue;
            if ( strcasecmp( (string) ( $da->Type ?? '' ),
                             (string) strtok( $typ, ' ' ) ) === 0 ) continue;
            $wpdb->query( "ALTER TABLE $voll MODIFY COLUMN $spalte $typ" );
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
            'Unter Verwaltung → Funktionen einschalten, was gelten soll — oder die '
            . 'alten Rechte einmalig übernehmen und danach durchsehen. Konten, für '
            . 'die nichts eingeschaltet ist, sehen nach dem Umschalten nichts.',
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
