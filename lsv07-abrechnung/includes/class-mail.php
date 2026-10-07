<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Mitteilungen per E-Mail.
 *
 * Versendet wird über den Mailversand von WordPress (wp_mail) — also über
 * den Weg, den die Seite ohnehin benutzt. Dieses Plugin baut keine eigene
 * Verbindung nach draußen auf.
 *
 * Drei Schalter, alle in der Verwaltung:
 *   1. Der Versand insgesamt. Ab Werk AUS — wohin Post geht, soll jemand
 *      bewusst einschalten und nicht nach einem Update vorfinden.
 *   2. Jede Art einzeln. Wer nur über Genehmigungen Post will, stellt den
 *      Rest ab.
 *   3. Betreff und Text je Art, mit Platzhaltern.
 *
 * Was in einer Mail steht, entscheidet die Administration über die Texte.
 * Die Vorgaben nennen bewusst KEINE Beträge und keine Zahlungsdaten: Eine
 * E-Mail liegt im Postfach, oft auf fremden Servern, und lässt sich nicht
 * zurückholen. Wer den Betrag sehen darf, sieht ihn beim Öffnen.
 */
class LSV07A_Mail {

    /** Die Arten, über die es Post geben kann. */
    const ARTEN = [ 'eingereicht', 'genehmigt', 'zurueck', 'bezahlt', 'offen', 'faellig', 'satz' ];

    public static function art_name( $art ) {
        return [
            'eingereicht' => 'Abrechnung eingereicht (an die Warte)',
            'genehmigt'   => 'Abrechnung genehmigt (an die Person und die Kasse)',
            'zurueck'     => 'Abrechnung zurückgegeben (an die Person)',
            'bezahlt'     => 'Abrechnung bezahlt (an die Person)',
            'offen'       => 'Abrechnung wieder geöffnet (an die Person)',
            'faellig'     => 'Quartal kann abgerechnet werden (an die Trainer)',
            'satz'        => 'Vorgaben geändert (an die Person)',
        ][ $art ] ?? $art;
    }

    /**
     * Die Vorgabetexte. Platzhalter in geschweiften Klammern werden
     * ersetzt; was nicht passt, bleibt stehen, statt still zu verschwinden.
     */
    public static function vorgabe( $art ) {
        $texte = [
            'eingereicht' => [
                'Neue Abrechnung zur Prüfung: {name}, {zeitraum}',
                "Hallo,\n\n{name} hat die Abrechnung für {zeitraum} eingereicht.\n\n"
                . "Sie wartet auf die Prüfung: {link}\n\n{verein}",
            ],
            'genehmigt' => [
                'Ihre Abrechnung für {zeitraum} wurde genehmigt',
                "Hallo {name},\n\nIhre Abrechnung für {zeitraum} wurde geprüft und genehmigt. "
                . "Sie liegt jetzt bei der Kasse.\n\n{link}\n\n{verein}",
            ],
            'zurueck' => [
                'Ihre Abrechnung für {zeitraum} kommt zurück',
                "Hallo {name},\n\nIhre Abrechnung für {zeitraum} wurde zur Überarbeitung "
                . "zurückgegeben.\n\nGrund: {grund}\n\n{link}\n\n{verein}",
            ],
            'bezahlt' => [
                'Ihre Abrechnung für {zeitraum} ist bezahlt',
                "Hallo {name},\n\nIhre Abrechnung für {zeitraum} ist als bezahlt vermerkt.\n\n"
                . "{link}\n\n{verein}",
            ],
            'offen' => [
                'Ihre Abrechnung für {zeitraum} wurde wieder geöffnet',
                "Hallo {name},\n\ndie Administration hat Ihre Abrechnung für {zeitraum} wieder "
                . "zur Bearbeitung geöffnet.\n\n{link}\n\n{verein}",
            ],
            'faellig' => [
                '{zeitraum} kann abgerechnet werden',
                "Hallo {name},\n\ndas Quartal {zeitraum} ist vorbei. Trainings und Wettkämpfe "
                . "lassen sich jetzt vollständig übernehmen.\n\n{link}\n\n{verein}",
            ],
            'satz' => [
                'Ihre Abrechnungsvorgaben haben sich geändert',
                "Hallo {name},\n\n{grund}\n\nOffene Abrechnungen rechnen sich damit neu; "
                . "Eingereichtes bleibt unberührt.\n\n{link}\n\n{verein}",
            ],
        ];
        return $texte[ $art ] ?? [ 'Mitteilung aus der Abrechnung', "{name}\n\n{link}" ];
    }

    public static function platzhalter() {
        return [
            '{name}'     => 'Name der Person',
            '{zeitraum}' => 'Quartal und Jahr, z. B. „1. Quartal (Jan–Mär) 2026"',
            '{grund}'    => 'Begründung (bei Rückgabe und Änderungen)',
            '{link}'     => 'Adresse der Abrechnungsseite',
            '{verein}'   => 'Name des Vereins aus den Einstellungen',
        ];
    }

    // ── Einstellungen ────────────────────────────────────────────────────
    public static function an() { return LSV07A_DB::config( 'mail_an', '0' ) === '1'; }

    public static function art_an( $art ) {
        if ( ! self::an() ) return false;
        // Ohne Eintrag gilt: an. Wer den Versand einschaltet, will ihn.
        return LSV07A_DB::config( 'mail_art_' . $art, '1' ) === '1';
    }

    public static function betreff( $art ) {
        $w = LSV07A_DB::config( 'mail_betreff_' . $art, '' );
        return $w !== '' ? $w : self::vorgabe( $art )[0];
    }

    public static function text( $art ) {
        $w = LSV07A_DB::config( 'mail_text_' . $art, '' );
        return $w !== '' ? $w : self::vorgabe( $art )[1];
    }

    /** Alle Einstellungen für die Verwaltung, in einem Rutsch. */
    public static function einstellungen() {
        $out = [
            'an'             => self::an(),
            'absender_name'  => LSV07A_DB::config( 'mail_absender_name', '' ),
            'absender'       => LSV07A_DB::config( 'mail_absender', '' ),
            'wart_extra'     => LSV07A_DB::config( 'mail_wart_extra', '' ),
            'kasse_extra'    => LSV07A_DB::config( 'mail_kasse_extra', '' ),
            'link'           => LSV07A_DB::config( 'mail_link', '' ),
            'platzhalter'    => self::platzhalter(),
            'arten'          => [],
        ];
        foreach ( self::ARTEN as $a ) {
            $out['arten'][] = [
                'art'      => $a,
                'name'     => self::art_name( $a ),
                'an'       => LSV07A_DB::config( 'mail_art_' . $a, '1' ) === '1',
                'betreff'  => self::betreff( $a ),
                'text'     => self::text( $a ),
                'vorgabe_betreff' => self::vorgabe( $a )[0],
                'vorgabe_text'    => self::vorgabe( $a )[1],
            ];
        }
        return $out;
    }

    // ── Empfänger ────────────────────────────────────────────────────────
    /**
     * Die Adresse einer Person: zuerst die hier hinterlegte, sonst die des
     * WordPress-Kontos. Eine ungültige Adresse wird verworfen statt
     * verschickt — sonst scheitert der Versand still.
     */
    public static function adresse( $wp_user_id ) {
        $p = LSV07A_Person::holen( $wp_user_id );
        $a = trim( (string) ( $p['mail'] ?? '' ) );
        /* Ist die hinterlegte Adresse unbrauchbar, wird auf die des
           WordPress-Kontos zurückgefallen statt gar nichts zu schicken —
           lieber eine Mitteilung an die bekannte Adresse als eine, die
           niemand bekommt. */
        if ( $a === '' || ! is_email( $a ) ) {
            $u = get_userdata( (int) $wp_user_id );
            $a = $u ? (string) $u->user_email : '';
        }
        return is_email( $a ) ? $a : '';
    }

    /** Zusätzliche Adressen einer Rolle, z. B. kasse@verein.de. */
    public static function extra( $rolle ) {
        $roh = LSV07A_DB::config( 'mail_' . $rolle . '_extra', '' );
        $out = [];
        foreach ( preg_split( '/[,;\s]+/', (string) $roh ) as $a ) {
            $a = trim( $a );
            if ( $a !== '' && is_email( $a ) ) $out[] = $a;
        }
        return $out;
    }

    // ── Versand ──────────────────────────────────────────────────────────
    private static function kopfzeilen() {
        $kopf = [ 'Content-Type: text/plain; charset=UTF-8' ];
        $adr  = trim( (string) LSV07A_DB::config( 'mail_absender', '' ) );
        $name = trim( (string) LSV07A_DB::config( 'mail_absender_name', '' ) );
        if ( $adr !== '' && is_email( $adr ) ) {
            $kopf[] = 'From: ' . ( $name !== '' ? '"' . $name . '" ' : '' ) . '<' . $adr . '>';
        }
        return $kopf;
    }

    public static function fuellen( $vorlage, array $werte ) {
        $werte = array_merge( [
            '{name}'     => '',
            '{zeitraum}' => '',
            '{grund}'    => '',
            '{link}'     => LSV07A_DB::config( 'mail_link', '' ),
            '{verein}'   => LSV07A_DB::config( 'verein', '' ),
        ], $werte );
        return strtr( (string) $vorlage, $werte );
    }

    /**
     * Eine Mitteilung per Mail. Gibt zurück, an wie viele Adressen sie
     * ging — 0 heisst: abgeschaltet, keine gültige Adresse, oder der
     * Mailversand von WordPress hat abgelehnt.
     */
    public static function senden( $art, $empfaenger, array $werte ) {
        if ( ! self::art_an( $art ) ) return 0;

        $adressen = [];
        foreach ( (array) $empfaenger as $e ) {
            $a = is_numeric( $e ) ? self::adresse( (int) $e ) : ( is_email( $e ) ? $e : '' );
            if ( $a !== '' ) $adressen[ strtolower( $a ) ] = $a;
        }
        if ( ! $adressen ) return 0;

        $betreff = self::fuellen( self::betreff( $art ), $werte );
        $text    = self::fuellen( self::text( $art ), $werte );
        $kopf    = self::kopfzeilen();

        $gesendet = 0;
        foreach ( $adressen as $a ) {
            /* Einzeln statt gesammelt: So sieht niemand, wer sonst noch
               Post bekommt, und ein Fehler bei einer Adresse hält die
               anderen nicht auf. */
            if ( wp_mail( $a, $betreff, $text, $kopf ) ) $gesendet++;
        }
        if ( $gesendet ) {
            LSV07A_Log::schreibe( 'mail.gesendet', [
                'details' => $art . ' an ' . $gesendet . ' Adresse(n)' ] );
        }
        return $gesendet;
    }

    /** Eine Probemail, damit sich der Weg prüfen lässt, bevor es ernst wird. */
    public static function probe( $art, $an ) {
        if ( ! is_email( $an ) ) return [ false, 'Das ist keine gültige E-Mail-Adresse.' ];
        if ( ! in_array( $art, self::ARTEN, true ) ) $art = 'genehmigt';

        $werte = [
            '{name}'     => 'Beispiel Trainerin',
            '{zeitraum}' => '1. Quartal (Jan–Mär) ' . date( 'Y' ),
            '{grund}'    => 'Beispielbegründung',
        ];
        $betreff = '[Probe] ' . self::fuellen( self::betreff( $art ), $werte );
        $text    = self::fuellen( self::text( $art ), $werte )
                 . "\n\n—\nDies ist eine Probemail aus der Abrechnung. "
                 . "Sie wurde von Hand ausgelöst und betrifft keine echte Abrechnung.";

        /* Die Probe geht AUCH, wenn der Versand abgeschaltet ist — genau
           dafür ist sie da: erst prüfen, dann einschalten. */
        $ok = wp_mail( $an, $betreff, $text, self::kopfzeilen() );
        LSV07A_Log::schreibe( 'mail.probe', [ 'details' => $art . ' an ' . $an . ( $ok ? '' : ' (abgelehnt)' ) ] );
        return $ok
            ? [ true, 'Probemail an ' . $an . ' übergeben. Kommt sie nicht an, liegt es am Mailversand von WordPress — dort weitersuchen.' ]
            : [ false, 'WordPress konnte die Mail nicht übergeben. Meist fehlt ein Mail-Plugin (z. B. SMTP) oder der Server verweigert den Versand.' ];
    }
}
