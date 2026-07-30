<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LSV07I_Wettkampf_Oeffentlich
 * ----------------------------
 * Öffentliche Übersichtsseite freigegebener Wettkämpfe.
 * Shortcode: [lsv07_wettkaempfe]
 *
 * Kein Login nötig — bewusst getrennt vom internen Bereich
 * ([lsv07_intern]). Zeigt ausschließlich Wettkämpfe mit freigegeben = 1
 * und ausschließlich die dafür freigegebenen Felder (Name, Ort, Zeitraum).
 * Von den Dokumenten wird jeweils genau das verlinkt, was tatsächlich
 * hochgeladen wurde (Ausschreibung, Meldeergebnis, Protokoll) — alle
 * anderen internen Daten (Anwesenheit, Abrechnung, Mannschaften) sind
 * hier nicht sichtbar. Vergangene Wettkämpfe verschwinden 10 Tage nach
 * ihrem Ende von dieser Seite (der interne Bereich zeigt sie weiterhin
 * unbefristet, dort geht es um Verwaltung/Archiv, nicht um Außendarstellung).
 *
 * Sicherheit:
 *  - Reine Lese-Ansicht, keine Formulare, kein AJAX-Endpunkt wird von
 *    dieser Seite aus aufgerufen.
 *  - Jede Ausgabe ist escaped (esc_html/esc_url/esc_attr).
 *  - Die Dokument-Downloads laufen über LSV07I_Ajax_Wettkampf::dok_download(),
 *    das serverseitig erneut prüft, dass der Wettkampf freigegeben ist —
 *    ein direkt aufgerufener Download-Link liefert für nicht freigegebene
 *    oder gelöschte Wettkämpfe grundsätzlich nichts aus.
 */
class LSV07I_Wettkampf_Oeffentlich {

    public static function init() {
        add_shortcode( 'lsv07_wettkaempfe', [ __CLASS__, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
    }

    private static $has_sc_cache = null;
    private static function page_has_shortcode() {
        if ( self::$has_sc_cache !== null ) return self::$has_sc_cache;
        if ( ! is_singular() ) return false;
        $post = ! empty( $GLOBALS['post'] ) ? $GLOBALS['post'] : get_post( get_queried_object_id() );
        if ( ! $post ) return false;
        self::$has_sc_cache = has_shortcode( $post->post_content, 'lsv07_wettkaempfe' );
        return self::$has_sc_cache;
    }

    public static function enqueue_assets() {
        if ( ! self::page_has_shortcode() ) return;
        wp_enqueue_style( 'lsv07i-wk-oeffentlich', LSV07I_URL . 'assets/css/wettkaempfe-oeffentlich.css', [], LSV07I_VERSION );
    }

    public static function render( $atts = [] ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $heute = current_time( 'Y-m-d' );
        // Vergangene Wettkämpfe verschwinden 10 Tage nach ihrem Ende von
        // der öffentlichen Seite.
        $vergangen_ab = date( 'Y-m-d', strtotime( $heute . ' -10 days' ) );

        $anstehend = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, ort, datum_von, datum_bis
               FROM {$p}lsv07i_wettkampf
              WHERE freigegeben = 1 AND datum_bis >= %s
           ORDER BY datum_von ASC",
            $heute
        ), ARRAY_A );

        $vergangen = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, ort, datum_von, datum_bis
               FROM {$p}lsv07i_wettkampf
              WHERE freigegeben = 1 AND datum_bis < %s AND datum_bis >= %s
           ORDER BY datum_von DESC
              LIMIT 30",
            $heute, $vergangen_ab
        ), ARRAY_A );

        // Alle Dokumente (Ausschreibung, Meldeergebnis, Protokoll) in einem
        // Rutsch nachladen (N+1 vermeiden), gruppiert nach Wettkampf + Typ.
        $alle_ids = array_map( fn( $r ) => (int) $r['id'], array_merge( $anstehend, $vergangen ) );
        $dokumente = [];
        if ( ! empty( $alle_ids ) ) {
            $in = implode( ',', $alle_ids );
            $rows = $wpdb->get_results(
                "SELECT id, wettkampf_id, typ, dateiname FROM {$p}lsv07i_wettkampf_dokument
                  WHERE wettkampf_id IN ($in)", ARRAY_A );
            foreach ( $rows as $r ) $dokumente[ (int) $r['wettkampf_id'] ][ $r['typ'] ] = $r;
        }

        ob_start();
        ?>
        <div class="lsv07wk-wrap">
            <?php if ( empty( $anstehend ) && empty( $vergangen ) ): ?>
                <p class="lsv07wk-leer">Aktuell sind keine Wettkämpfe freigegeben.</p>
            <?php else: ?>
                <?php if ( ! empty( $anstehend ) ): ?>
                    <h2 class="lsv07wk-h2">Anstehende Wettkämpfe</h2>
                    <div class="lsv07wk-liste">
                        <?php foreach ( $anstehend as $w ) echo self::render_karte( $w, $dokumente ); ?>
                    </div>
                <?php endif; ?>
                <?php if ( ! empty( $vergangen ) ): ?>
                    <h2 class="lsv07wk-h2 lsv07wk-h2-vergangen">Vergangene Wettkämpfe</h2>
                    <div class="lsv07wk-liste lsv07wk-liste-vergangen">
                        <?php foreach ( $vergangen as $w ) echo self::render_karte( $w, $dokumente ); ?>
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

    private static function render_karte( $w, $dokumente ) {
        $von = date_i18n( 'd.m.Y', strtotime( $w['datum_von'] ) );
        $bis = date_i18n( 'd.m.Y', strtotime( $w['datum_bis'] ) );
        $zeitraum = $w['datum_von'] === $w['datum_bis'] ? $von : ( $von . ' – ' . $bis );

        $tag  = date_i18n( 'd', strtotime( $w['datum_von'] ) );
        $monat = date_i18n( 'M', strtotime( $w['datum_von'] ) );

        $docs = $dokumente[ (int) $w['id'] ] ?? [];

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
            </div>
            <?php if ( ! empty( $docs ) ): ?>
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
                </div>
            <?php endif; ?>
        </article>
        <?php
        return ob_get_clean();
    }
}
