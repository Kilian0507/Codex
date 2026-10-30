<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Die Einstellungen des Vereins: eine Liste, eine Prüfung je Eintrag.
 *
 * Der interne Bereich hat dafür eine Liste erlaubter Schlüssel und
 * schreibt jeden mit `sanitize_text_field` durch — ungeprüft. Zwei Dinge
 * fallen dabei auf:
 *
 * 1. **`blocked_user_ids` steht in der Schreibliste, aber nicht in der
 *    Leseliste.** Der Haken ist dort also schreibbar und wird nie
 *    angezeigt: Wer ein Konto gesperrt hat, sieht es in der Oberfläche
 *    nicht wieder. In SwimSys wird nicht über diese Liste gesperrt,
 *    sondern je Konto (`LSV07S_Rechte::sperren`), damit im Protokoll
 *    steht, WER gesperrt wurde.
 * 2. **Die Abrechnungssätze (`km_satz`, `km_min`, `wk_pauschale`) stehen
 *    dort mit drin.** Sie gehören zur Übungsleiterabrechnung — SwimSys
 *    rechnet nicht ab und stellt sie darum auch nicht ein. Wer sie
 *    ändert, tut das dort.
 *
 * Jeder Eintrag hier hat eine ART, und die ART prüft. Eine Zahl, die als
 * „dreissig" ankommt, wird nicht zu 0: Sie wird abgewiesen, mit einem
 * Satz. „Still auf Standard zurückfallen" ist bei einer Vorwarnzeit die
 * schlechteste Antwort — niemand merkt, dass die Warnung nun woanders
 * steht.
 */
class LSV07S_Konfig {

    const FUNKTION = 'verwaltung/einstellungen';

    /**
     * Was eingestellt werden darf: Schlüssel => [ Art, Name, Hinweis ].
     *
     * Arten: `schalter` (0/1), `zahl` (mit Grenzen), `text`, `langtext`.
     */
    public static function felder() {
        $f = [
            'mail_deaktiviert' => [
                'art'  => 'schalter',
                'name' => 'Alle E-Mails abschalten',
                'was'  => 'Solange das an ist, verschickt SwimSys keine einzige '
                        . 'Mail — auch keine Probemail. Für die Zeit, in der der '
                        . 'Mailserver klemmt.',
                /* Standard 0, nicht 1: Ein Schalter, der „alles aus"
                   heisst, ist von sich aus AUS. Ohne diese Zeile zeigte
                   die Oberfläche in einer Anlage ohne diese Zeile „alle
                   Mails abgeschaltet" an, während die Mailklasse (die mit
                   dem richtigen Standard liest) sie verschickte. */
                'standard' => 0,
                'umgekehrt' => true, 'gruppe' => 'mail',
            ],
            'attest_warnung_tage' => [
                'art'  => 'zahl', 'min' => 1, 'max' => 365, 'standard' => 30,
                'name' => 'Vorwarnzeit für Atteste (Tage)',
                'was'  => 'So viele Tage vor dem Ablauf gilt ein Attest als '
                        . '„läuft ab".',
            ],
            'anwesenheit_mahnung_stunden' => [
                'art'  => 'zahl', 'min' => 0, 'max' => 720, 'standard' => 48,
                'name' => 'Erinnerung an fehlende Anwesenheit (Stunden)',
                'was'  => 'So viele Stunden nach einem Training gilt die '
                        . 'Anwesenheit als überfällig. 0 schaltet die Erinnerung '
                        . 'ab.',
            ],
            'attest_mail_betreff' => [
                'art'  => 'text', 'laenge' => 200,
                'name' => 'Betreff der Attesterinnerung',
                'was'  => 'Der Betreff der Mail an die Kontaktperson.',
                'gruppe' => 'mail',
            ],
            'attest_mail_text' => [
                'art'  => 'langtext', 'laenge' => 4000,
                'name' => 'Text der Attesterinnerung',
                'was'  => 'Platzhalter: {name} für die Person, {datum} für den '
                        . 'Ablauftag, {kontakt} für die angeschriebene Person.',
                'gruppe' => 'mail',
            ],
        ];
        /* Die eigenen Benachrichtigungen. */
        foreach ( LSV07S_Mail::SCHALTER as $k => $name ) {
            $f[ $k ] = [ 'art' => 'schalter', 'name' => $name, 'gruppe' => 'mail',
                         'standard' => 1 ];
        }
        /* Und die, die ein anderes Plugin verschickt. Sie stehen hier,
           weil der Verein den Haken finden muss — nicht, weil SwimSys sie
           verschickt. */
        foreach ( LSV07S_Mail::SCHALTER_FREMD as $k => $name ) {
            $f[ $k ] = [ 'art' => 'schalter', 'name' => $name, 'gruppe' => 'fremd',
                         'standard' => 1 ];
        }
        return $f;
    }

    /** Der Standardwert eines Feldes. */
    private static function standard( array $feld ) {
        if ( isset( $feld['standard'] ) ) return (string) $feld['standard'];
        return $feld['art'] === 'schalter' ? '1' : '';
    }

    // ── Lesen ────────────────────────────────────────────────────────────

    /**
     * Alles, was eingestellt werden kann, mit dem aktuellen Wert.
     *
     * Auch für ein Konto ohne den Schalter? Nein. Die Einstellungen eines
     * Vereins sagen etwas über den Verein; wer sie nicht ändern darf,
     * braucht sie nicht zu sehen.
     */
    public static function alles() {
        if ( ! LSV07S_Schalter::an( self::FUNKTION ) ) {
            return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        }
        $raus = [];
        foreach ( self::felder() as $k => $feld ) {
            $wert = (string) LSV07S_Tabellen::config( $k, self::standard( $feld ) );
            $raus[] = [
                'schluessel' => $k,
                'art'        => $feld['art'],
                'name'       => $feld['name'],
                'was'        => (string) ( $feld['was'] ?? '' ),
                'gruppe'     => (string) ( $feld['gruppe'] ?? 'allgemein' ),
                'wert'       => $wert,
                'an'         => $feld['art'] === 'schalter' ? $wert === '1' : null,
                /* „Umgekehrt" heisst: an ist die Ausnahme, nicht die
                   Regel. Die Oberfläche soll das anders zeigen. */
                'umgekehrt'  => ! empty( $feld['umgekehrt'] ),
                'min'        => $feld['min'] ?? null,
                'max'        => $feld['max'] ?? null,
            ];
        }
        return $raus;
    }

    // ── Schreiben ────────────────────────────────────────────────────────

    /**
     * Eine Einstellung setzen.
     *
     * Ein Schlüssel, der nicht in der Liste steht, wird abgewiesen — und
     * zwar als unbekannt, nicht stillschweigend übergangen. Sonst meldet
     * die Oberfläche „gespeichert", und nichts ist gespeichert.
     */
    public static function setzen( $schluessel, $wert ) {
        if ( ! LSV07S_Schalter::an( self::FUNKTION ) ) {
            return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        }
        $schluessel = (string) $schluessel;
        $felder = self::felder();
        if ( ! isset( $felder[ $schluessel ] ) ) {
            return 'Diese Einstellung gibt es nicht.';
        }
        if ( ! LSV07S_Tabellen::da( 'config' ) ) return 'Die Tabelle fehlt.';
        $feld = $felder[ $schluessel ];

        switch ( $feld['art'] ) {
            case 'schalter':
                $neu = in_array( (string) $wert, [ '1', 'true', 'on' ], true ) ? '1' : '0';
                break;
            case 'zahl':
                $roh = trim( (string) $wert );
                /* Nicht `(int)`: Daraus würde „dreissig" eine 0, und eine
                   Vorwarnzeit von 0 Tagen heisst, dass niemand mehr
                   gewarnt wird. */
                if ( $roh === '' || ! preg_match( '/^\d{1,6}$/', $roh ) ) {
                    return 'Bitte eine Zahl angeben.';
                }
                $z = (int) $roh;
                $min = (int) ( $feld['min'] ?? 0 );
                $max = (int) ( $feld['max'] ?? 999999 );
                if ( $z < $min || $z > $max ) {
                    return 'Bitte eine Zahl zwischen ' . $min . ' und ' . $max . '.';
                }
                $neu = (string) $z;
                break;
            case 'langtext':
                $neu = mb_substr( sanitize_textarea_field( (string) $wert ), 0,
                    (int) ( $feld['laenge'] ?? 4000 ) );
                if ( trim( $neu ) === '' ) return 'Bitte einen Text angeben.';
                break;
            default:
                $neu = mb_substr( sanitize_text_field( (string) $wert ), 0,
                    (int) ( $feld['laenge'] ?? 200 ) );
                if ( trim( $neu ) === '' ) return 'Bitte einen Text angeben.';
        }

        $alt = (string) LSV07S_Tabellen::config( $schluessel, self::standard( $feld ) );
        if ( $alt === $neu ) return true;
        LSV07S_Tabellen::config_setzen( $schluessel, $neu );

        /* Im Protokoll steht, WAS sich geändert hat — bei einem Schalter
           und einer Zahl auch der Wert, denn der ist die Auskunft. Beim
           Mailtext nur, dass er geändert wurde: Der ganze Text im
           Protokoll wäre eine zweite Kopie, die mit jeder Änderung
           wächst. */
        $details = in_array( $feld['art'], [ 'text', 'langtext' ], true )
            ? 'geändert'
            : $alt . ' → ' . $neu;
        LSV07S_Log::schreibe( 'konfig.gesetzt', [
            'bereich' => 'verwaltung', 'ziel_typ' => 'konfig',
            'ziel_name' => $schluessel, 'details' => $details,
        ] );
        return true;
    }
}
