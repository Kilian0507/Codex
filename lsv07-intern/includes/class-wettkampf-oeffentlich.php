<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LSV07I_Wettkampf_Oeffentlich
 * ----------------------------
 * Öffentliche Übersichtsseite freigegebener Wettkämpfe.
 *
 * EIN WEG ZUR SEITE: der Shortcode [lsv07_wettkaempfe] auf einer
 * beliebigen WordPress-Seite. Die Seite liegt damit im Menü des
 * Vereins, unter der Adresse des Vereins, und das Theme malt Kopf und
 * Fuß darum. Eine eigene Adresse im Plugin gibt es bewusst nicht —
 * es soll genau eine Adresse geben, unter der diese Liste steht.
 *
 * Kein Login nötig — bewusst getrennt vom internen Bereich
 * ([lsv07_intern]). Zeigt ausschließlich Wettkämpfe mit freigegeben = 1
 * und ausschließlich die dafür freigegebenen Felder: Name, Ort, Zeitraum,
 * die teilnehmenden Mannschaften und die hochgeladenen Dokumente
 * (Ausschreibung, Meldeergebnis, Protokoll) samt frei benannten Links.
 *
 * Datenschutz — was hier NICHT steht:
 *  - Von den Mannschaften steht ausschließlich der NAME der Mannschaft
 *    da (z. B. "Wettkampfmannschaft 1"), also eine Gruppe des Vereins.
 *    Kein Name, kein Jahrgang, keine Zeit, keine Anzahl einer einzelnen
 *    Person verlässt diese Seite. Wer in einer Mannschaft schwimmt, ist
 *    und bleibt eine interne Angabe.
 *  - Anwesenheit, Abrechnung, Meldungen und Atteste sind hier nicht
 *    sichtbar, auch nicht mittelbar.
 *  - Die Freigabe ist das Tor: ohne freigegeben = 1 steht ein Wettkampf
 *    hier mit keinem einzigen Feld. Wird die Freigabe zurückgezogen,
 *    verschwindet er wieder vollständig.
 *  - Vergangene Wettkämpfe verschwinden TAGE_VERGANGEN Tage nach ihrem
 *    Ende von dieser Seite (der interne Bereich zeigt sie weiterhin
 *    unbefristet, dort geht es um Verwaltung/Archiv, nicht um
 *    Außendarstellung).
 *
 * Sicherheit:
 *  - Reine Lese-Ansicht, keine Formulare, kein AJAX-Endpunkt wird von
 *    dieser Seite aus aufgerufen.
 *  - Jede Ausgabe ist escaped (esc_html/esc_url/esc_attr).
 *  - Die Seite bringt kein eigenes Skript mit: sie ist fertiges HTML.
 *  - Die Dokument-Downloads laufen über LSV07I_Ajax_Wettkampf::dok_download(),
 *    das serverseitig erneut prüft, dass der Wettkampf freigegeben ist —
 *    ein direkt aufgerufener Download-Link liefert für nicht freigegebene
 *    oder gelöschte Wettkämpfe grundsätzlich nichts aus.
 */
class LSV07I_Wettkampf_Oeffentlich {

    /** Der Shortcode. Steht genau hier. */
    const SHORTCODE = 'lsv07_wettkaempfe';

    /** So viele Tage nach ihrem Ende bleiben vergangene Wettkämpfe stehen. */
    const TAGE_VERGANGEN = 10;

    public static function init() {
        add_shortcode( self::SHORTCODE, [ __CLASS__, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    private static $has_sc_cache = null;
    private static function page_has_shortcode() {
        if ( self::$has_sc_cache !== null ) return self::$has_sc_cache;
        if ( ! is_singular() ) return false;
        $post = ! empty( $GLOBALS['post'] ) ? $GLOBALS['post'] : get_post( get_queried_object_id() );
        if ( ! $post ) return false;
        self::$has_sc_cache = has_shortcode( $post->post_content, self::SHORTCODE );
        return self::$has_sc_cache;
    }

    public static function enqueue_assets() {
        if ( ! self::page_has_shortcode() ) return;
        wp_enqueue_style( 'lsv07i-wk-oeffentlich', LSV07I_URL . 'assets/css/wettkaempfe-oeffentlich.css', [], LSV07I_VERSION );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  DATEN
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Holt alles, was die Seite zeigt: die beiden Listen und je Wettkampf
     * die Mannschaften, Dokumente und Links. Jede Zusatzangabe wird für
     * ALLE Wettkämpfe in genau einer Abfrage geholt (kein N+1).
     */
    private static function daten() {
        global $wpdb;
        $p = $wpdb->prefix;

        $heute        = current_time( 'Y-m-d' );
        $vergangen_ab = date( 'Y-m-d', strtotime( $heute . ' -' . self::TAGE_VERGANGEN . ' days' ) );

        $anstehend = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, ort, datum_von, datum_bis
               FROM {$p}lsv07i_wettkampf
              WHERE freigegeben = 1 AND datum_bis >= %s
           ORDER BY datum_von ASC",
            $heute
        ), ARRAY_A ) ?: [];

        $vergangen = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, ort, datum_von, datum_bis
               FROM {$p}lsv07i_wettkampf
              WHERE freigegeben = 1 AND datum_bis < %s AND datum_bis >= %s
           ORDER BY datum_von DESC
              LIMIT 30",
            $heute, $vergangen_ab
        ), ARRAY_A ) ?: [];

        // Nur echte, positive IDs in die IN-Liste — Defense-in-Depth, obwohl
        // die Werte aus der eigenen Spalte id kommen.
        $alle_ids = array_values( array_filter(
            array_map( fn( $r ) => (int) $r['id'], array_merge( $anstehend, $vergangen ) ),
            fn( $id ) => $id > 0
        ) );

        $d = [
            'anstehend'    => $anstehend,
            'vergangen'    => $vergangen,
            'mannschaften' => [],
            'dokumente'    => [],
            'links'        => [],
        ];
        if ( empty( $alle_ids ) ) return $d;
        $in = implode( ',', $alle_ids );

        // Teilnehmende Mannschaften. Es wird ausschließlich der Name der
        // Mannschaft geholt — keine Spalte, die auf eine Person zeigt.
        $rows = $wpdb->get_results(
            "SELECT wm.wettkampf_id, g.name
               FROM {$p}lsv07i_wettkampf_mannschaft wm
               JOIN {$p}lsv07_gruppen g ON g.id = wm.mannschaft_id
              WHERE wm.wettkampf_id IN ($in)
           ORDER BY g.sort_order ASC, g.name ASC", ARRAY_A ) ?: [];
        foreach ( $rows as $r ) {
            $name = trim( (string) $r['name'] );
            if ( $name === '' ) continue;
            $d['mannschaften'][ (int) $r['wettkampf_id'] ][] = $name;
        }

        // Dokumente (Ausschreibung, Meldeergebnis, Protokoll), gruppiert
        // nach Wettkampf + Typ.
        $rows = $wpdb->get_results(
            "SELECT id, wettkampf_id, typ, dateiname FROM {$p}lsv07i_wettkampf_dokument
              WHERE wettkampf_id IN ($in)", ARRAY_A ) ?: [];
        foreach ( $rows as $r ) $d['dokumente'][ (int) $r['wettkampf_id'] ][ $r['typ'] ] = $r;

        // Frei benannte Links. Die Tabelle kann in einer noch nicht
        // aktualisierten Installation fehlen — dann bleibt die Liste
        // einfach leer, statt die ganze Seite scheitern zu lassen.
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$p}lsv07i_wettkampf_link'" ) ) {
            $rows = $wpdb->get_results(
                "SELECT wettkampf_id, titel, url FROM {$p}lsv07i_wettkampf_link
                  WHERE wettkampf_id IN ($in) ORDER BY sortierung ASC, id ASC", ARRAY_A ) ?: [];
            foreach ( $rows as $r ) $d['links'][ (int) $r['wettkampf_id'] ][] = $r;
        }

        return $d;
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  AUSGABE
    // ═══════════════════════════════════════════════════════════════════════

    public static function render( $atts = [] ) {
        $d = self::daten();

        ob_start();
        ?>
        <div class="lsv07wk-wrap">
            <?php if ( empty( $d['anstehend'] ) && empty( $d['vergangen'] ) ): ?>
                <p class="lsv07wk-leer">
                    Aktuell ist kein Wettkampf freigegeben.
                    <span class="lsv07wk-leer-sub">Vergangene Wettkämpfe stehen hier
                    noch <?php echo (int) self::TAGE_VERGANGEN; ?> Tage nach ihrem Ende.</span>
                </p>
            <?php else: ?>
                <?php if ( ! empty( $d['anstehend'] ) ): ?>
                    <h2 class="lsv07wk-h2">Anstehende Wettkämpfe</h2>
                    <div class="lsv07wk-liste">
                        <?php foreach ( $d['anstehend'] as $w ) echo self::render_karte( $w, $d ); ?>
                    </div>
                <?php endif; ?>
                <?php if ( ! empty( $d['vergangen'] ) ): ?>
                    <h2 class="lsv07wk-h2 lsv07wk-h2-vergangen">Vergangene Wettkämpfe</h2>
                    <div class="lsv07wk-liste lsv07wk-liste-vergangen">
                        <?php foreach ( $d['vergangen'] as $w ) echo self::render_karte( $w, $d ); ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private static $typ_labels = [
        'ausschreibung' => 'Ausschreibung',
        'meldeergebnis' => 'Meldeergebnis',
        'protokoll'     => 'Protokoll',
    ];

    private static function render_karte( $w, $d ) {
        $von = date_i18n( 'd.m.Y', strtotime( $w['datum_von'] ) );
        $bis = date_i18n( 'd.m.Y', strtotime( $w['datum_bis'] ) );
        $zeitraum = $w['datum_von'] === $w['datum_bis'] ? $von : ( $von . ' – ' . $bis );

        $tag  = date_i18n( 'd', strtotime( $w['datum_von'] ) );
        $monat = date_i18n( 'M', strtotime( $w['datum_von'] ) );

        $id        = (int) $w['id'];
        $docs      = $d['dokumente'][ $id ]    ?? [];
        $verweise  = $d['links'][ $id ]        ?? [];
        $mannsch   = $d['mannschaften'][ $id ] ?? [];

        ob_start();
        ?>
        <article class="lsv07wk-karte">
            <div class="lsv07wk-datum">
                <span class="lsv07wk-tag"><?php echo esc_html( $tag ); ?></span>
                <span class="lsv07wk-monat"><?php echo esc_html( $monat ); ?></span>
            </div>
            <div class="lsv07wk-info">
                <h3 class="lsv07wk-name"><?php echo esc_html( $w['name'] ); ?></h3>
                <div class="lsv07wk-meta">
                    <span class="lsv07wk-zeitraum"><?php echo esc_html( $zeitraum ); ?></span>
                    <?php if ( $w['ort'] !== '' ): ?>
                        <span class="lsv07wk-ort"><?php echo esc_html( $w['ort'] ); ?></span>
                    <?php endif; ?>
                </div>
                <?php if ( ! empty( $mannsch ) ): ?>
                    <div class="lsv07wk-mann">
                        <span class="lsv07wk-mann-titel">Mit dabei:</span>
                        <?php foreach ( $mannsch as $m ): ?>
                            <span class="lsv07wk-mann-chip"><?php echo esc_html( $m ); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ( ! empty( $docs ) || ! empty( $verweise ) ): ?>
                <div class="lsv07wk-dokumente">
                    <?php foreach ( self::$typ_labels as $typ => $label ): ?>
                        <?php if ( isset( $docs[ $typ ] ) ): ?>
                            <a class="lsv07wk-pdf"
                               href="<?php echo esc_url( add_query_arg( [
                                   'action' => 'lsv07i_wk_dok_download',
                                   'id'     => (int) $docs[ $typ ]['id'],
                               ], admin_url( 'admin-ajax.php' ) ) ); ?>"
                               target="_blank" rel="noopener noreferrer nofollow">
                                <?php echo esc_html( $label ); ?> (PDF)
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php foreach ( $verweise as $l ): ?>
                        <a class="lsv07wk-pdf lsv07wk-link"
                           href="<?php echo esc_url( $l['url'] ); ?>"
                           target="_blank" rel="noopener noreferrer nofollow">
                            <?php echo esc_html( $l['titel'] ); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
        <?php
        return ob_get_clean();
    }
}
