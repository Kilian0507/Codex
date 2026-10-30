<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Eine Stelle für jede E-Mail, die SwimSys verschickt.
 *
 * Vorher gab es keine: `LSV07S_Attest::erinnern()` rief `wp_mail()`
 * direkt. Das war ein Fehler, und zwar ein folgenreicher — der interne
 * Bereich kennt zwei Schalter, die jede Mail abfangen:
 *
 *   - `mail_deaktiviert` schaltet **alle** Mails ab. Der Verein benutzt
 *     das, wenn der Mailserver klemmt oder wenn in den Ferien niemand
 *     angeschrieben werden soll.
 *   - je Benachrichtigung ein eigener Schalter (`mail_attest_kontakt`
 *     und die anderen aus `SCHALTER`).
 *
 * SwimSys fragte keinen von beiden. Wer die Mails im internen Bereich
 * abschaltete, bekam sie aus SwimSys weiter — und wusste nicht, woher.
 * Jede Mail geht jetzt durch `senden()`, und `senden()` fragt beide.
 *
 * Und sie schweigt nicht: Wo der interne Bereich einfach `false`
 * zurückgibt, nennt `grund()` den Satz, der dem Nutzer gesagt wird.
 * „Nichts passiert" ist die schlechteste Antwort, die eine Oberfläche
 * geben kann.
 */
class LSV07S_Mail {

    /** Der globale Schalter — dieselbe Einstellung wie im internen Bereich. */
    const GLOBAL_AUS = 'mail_deaktiviert';

    /**
     * Die Benachrichtigungen, die SWIMSYS verschickt, mit ihrem
     * Standardwert (an) und dem Satz, der in den Einstellungen steht.
     *
     * Die Schlüssel sind dieselben wie im internen Bereich: Wer dort
     * einen Haken wegnimmt, hat ihn auch hier weg. Die drei `mail_abr_*`
     * stehen NICHT dabei — die verschickt die
     * Übungsleiterabrechnung, und SwimSys rechnet nicht ab. Die
     * Einstellungen zeigen sie getrennt, solange der interne Bereich sie
     * noch verschickt.
     */
    const SCHALTER = [
        'mail_attest_kontakt' => 'Attest läuft ab → an die Kontaktperson',
        'mail_attest_trainer' => 'Attest läuft ab → an die Trainer der Mannschaft',
        'mail_anwesenheit_trainer' => 'Anwesenheit fehlt → an die Trainer',
        'mail_springer_eintrag' => 'Springer eingetragen → an die Trainer',
        'mail_springer_austrag' => 'Springer ausgetragen → an die Trainer',
        'mail_bestzeiten_trainer' => 'Neue Bestzeiten → an die Trainer',
        'mail_wk_approve_anfrage' => 'Neuer Wettkampf → an die, die freigeben',
        'mail_wk_erinnerung_meldeergebnis' => 'Erinnerung Meldeergebnis (3 Tage vorher)',
        'mail_wk_erinnerung_protokoll' => 'Erinnerung Protokoll (1 Tag danach)',
    ];

    /**
     * Die Schalter, die der interne Bereich bedient und SwimSys nicht.
     *
     * Sie stehen in den Einstellungen, weil sie wirken — nur eben in
     * einem anderen Plugin. Sie zu verstecken hiesse: Der Verein findet
     * den Haken nicht mehr, mit dem er die Abrechnungsmails abstellt.
     */
    const SCHALTER_FREMD = [
        'mail_abr_schwimmwart' => 'Abrechnung eingereicht → an den Wart',
        'mail_abr_kassenwart'  => 'Abrechnung genehmigt → an die Kasse',
        'mail_abr_trainer'     => 'Abrechnung: Stand → an den Trainer',
    ];

    /** Für Prüfungen: die wirklich verschickten Mails mitschreiben. */
    public static $gesendet = [];

    // ── Fragen ───────────────────────────────────────────────────────────

    /** Sind alle Mails abgeschaltet? */
    public static function global_aus() {
        return (string) LSV07S_Tabellen::config( self::GLOBAL_AUS, '0' ) === '1';
    }

    /** Darf diese Benachrichtigung heraus? */
    public static function an( $schluessel = null ) {
        if ( self::global_aus() ) return false;
        if ( $schluessel === null ) return true;
        $standard = '1';
        return (string) LSV07S_Tabellen::config( (string) $schluessel, $standard ) === '1';
    }

    /**
     * Warum nicht — als Satz für die Oberfläche, oder '' wenn sie darf.
     *
     * Zwei Gründe, zwei Sätze: „alle Mails sind aus" ist etwas anderes
     * als „diese Benachrichtigung ist aus", und wer sie wieder einschalten
     * will, muss wissen, welchen Haken er sucht.
     */
    public static function grund( $schluessel = null ) {
        if ( self::global_aus() ) {
            return 'In den Einstellungen sind alle E-Mails abgeschaltet. '
                 . 'Es wurde nichts verschickt.';
        }
        if ( $schluessel !== null && ! self::an( $schluessel ) ) {
            $was = self::SCHALTER[ $schluessel ] ?? $schluessel;
            return 'Diese Benachrichtigung ist in den Einstellungen abgeschaltet '
                 . '(„' . $was . '"). Es wurde nichts verschickt.';
        }
        return '';
    }

    // ── Verschicken ──────────────────────────────────────────────────────

    /**
     * Eine Mail verschicken — der einzige Weg nach draussen.
     *
     * Rückgabe: `true`, der Satz des Grundes (abgeschaltet), oder ein
     * Satz über den fehlgeschlagenen Versand. Nie ein stilles `false`:
     * Jeder Aufrufer kann den Satz weitergeben.
     */
    public static function senden( $an, $betreff, $text, $schluessel = null ) {
        $grund = self::grund( $schluessel );
        if ( $grund !== '' ) return $grund;

        $an = trim( (string) $an );
        if ( $an === '' || ! is_email( $an ) ) {
            return 'Für diesen Empfänger ist keine gültige Adresse hinterlegt.';
        }

        $kopf = [
            'Content-Type: text/plain; charset=UTF-8',
        ];
        /* Der Absender ist die Seite selbst. Ohne einen gesetzten
           Absender schickt WordPress von `wordpress@domain`, und das
           landet bei vielen Empfängern im Spam. */
        if ( function_exists( 'get_bloginfo' ) && function_exists( 'get_option' ) ) {
            $name  = (string) get_bloginfo( 'name' );
            $adr   = (string) get_option( 'admin_email' );
            if ( $adr !== '' ) {
                $kopf[] = 'From: ' . ( $name !== '' ? $name . ' ' : '' ) . '<' . $adr . '>';
            }
        }

        self::$gesendet[] = [ 'an' => $an, 'betreff' => (string) $betreff,
                              'schluessel' => $schluessel ];
        $ok = wp_mail( $an, (string) $betreff, (string) $text, $kopf );
        if ( ! $ok ) {
            return 'Der Versand ist fehlgeschlagen. Bitte den Mailversand von '
                 . 'WordPress prüfen (SMTP).';
        }
        return true;
    }

    // ── Probemail ────────────────────────────────────────────────────────

    /**
     * Eine Probemail mit dem ECHTEN Wortlaut und Beispieldaten.
     *
     * Sie geht auch dann heraus, wenn der Haken dieser Benachrichtigung
     * aus ist — man will ja prüfen, wie sie aussieht. Der GLOBALE
     * Schalter gilt aber: Steht er auf aus, ist der Mailweg bewusst zu,
     * und eine Probemail wäre genau das, was er verhindern soll.
     */
    public static function probe( $an, $schluessel ) {
        if ( ! LSV07S_Schalter::an( 'verwaltung/einstellungen' ) ) {
            return 'Diese Funktion ist für Sie nicht eingeschaltet.';
        }
        $schluessel = (string) $schluessel;
        if ( ! isset( self::SCHALTER[ $schluessel ] ) ) {
            return 'Diese Benachrichtigung gibt es in SwimSys nicht.';
        }
        if ( self::global_aus() ) {
            return 'In den Einstellungen sind alle E-Mails abgeschaltet. '
                 . 'Auch eine Probemail geht dann nicht heraus.';
        }
        $an = trim( (string) $an );
        if ( $an === '' || ! is_email( $an ) ) {
            return 'Bitte eine gültige Adresse für die Probemail angeben.';
        }

        [ $betreff, $text ] = self::vorlage( $schluessel );
        $kopf = [ 'Content-Type: text/plain; charset=UTF-8' ];
        self::$gesendet[] = [ 'an' => $an, 'betreff' => $betreff,
                              'schluessel' => $schluessel, 'probe' => true ];
        $ok = wp_mail( $an, $betreff, $text, $kopf );
        if ( ! $ok ) {
            return 'Der Versand ist fehlgeschlagen. Bitte den Mailversand von '
                 . 'WordPress prüfen (SMTP).';
        }

        LSV07S_Log::schreibe( 'mail.probe', [
            'bereich' => 'verwaltung', 'ziel_typ' => 'mail',
            'ziel_name' => $schluessel,
            /* Die Adresse NICHT ins Protokoll: Wohin die Probe ging, weiss
               der, der sie verschickt hat. */
            'details' => 'Probemail verschickt',
        ] );
        return true;
    }

    /**
     * Betreff und Text einer Benachrichtigung, mit Beispieldaten.
     *
     * Für die Attesterinnerung steht der Wortlaut in den Einstellungen —
     * dann ist das hier genau der Text, der wirklich herausgeht. Für die
     * anderen steht er hier, weil es sie noch nicht gibt; dann sagt die
     * Probemail das auch.
     */
    public static function vorlage( $schluessel ) {
        $beispiel = [
            '{name}'   => 'Lang, Lea',
            '{datum}'  => LSV07S_Datum::de( date( 'Y-m-d',
                              strtotime( LSV07S_Datum::heute() . ' +21 days' ) ) ),
            '{kontakt}'=> 'Mama Lang',
        ];

        if ( $schluessel === 'mail_attest_kontakt' ) {
            $betreff = (string) LSV07S_Tabellen::config( 'attest_mail_betreff',
                'Ärztliches Attest läuft ab' );
            $text = (string) LSV07S_Tabellen::config( 'attest_mail_text',
                "Guten Tag,\n\ndas ärztliche Attest von {name} läuft am {datum} ab.\n"
                . "Bitte reichen Sie ein neues Attest beim Trainer ein.\n\n"
                . "Mit sportlichen Grüßen\nLSV 07" );
            return [ '[Probe] ' . $betreff,
                     str_replace( array_keys( $beispiel ), array_values( $beispiel ), $text )
                     . "\n\n---\nDies ist eine Probemail aus SwimSys. Die Namen und das "
                     . "Datum sind Beispiele." ];
        }

        $was = self::SCHALTER[ $schluessel ] ?? $schluessel;
        return [ '[Probe] ' . $was,
            "Dies ist eine Probemail aus SwimSys.\n\n"
            . "Benachrichtigung: " . $was . "\n\n"
            . "Für diese Benachrichtigung gibt es in SwimSys noch keinen eigenen "
            . "Wortlaut — sie wird derzeit vom internen Bereich verschickt. Die "
            . "Probe zeigt also nur, DASS der Mailweg funktioniert, nicht wie die "
            . "Mail aussieht.\n" ];
    }
}
