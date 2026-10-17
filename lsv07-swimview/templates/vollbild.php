<?php
/**
 * Die Seitenvorlage für den Vollbildmodus.
 *
 * Hier wird das Dokument selbst gebaut — ohne `get_header()` und ohne
 * `get_footer()`. Das Theme steuert also keine einzige Zeile Markup bei:
 * keine Kopfleiste, keine Navigation, keine Seitenleiste, keine Fusszeile,
 * kein Cookie-Banner, keine Brotkrumen.
 *
 * Warum so und nicht per CSS: Vorher wurden die Leisten des Themes über
 * eine Liste bekannter Klassennamen ausgeblendet (`#masthead`,
 * `.site-header`, `#colophon` …). Das funktioniert für die Themes auf der
 * Liste und versagt stillschweigend bei jedem anderen — und „stillschweigend
 * versagen" heisst hier: Die Navigation des Vereins steht mitten in der
 * Anwendung. Was gar nicht erst ausgegeben wird, muss man auch nicht
 * verstecken.
 *
 * `wp_head()` und `wp_footer()` bleiben, und zwar notwendigerweise: Daran
 * hängen die Stilvorlage und das Skript von SwimView selbst. Sie bringen
 * zusätzlich die Stilvorlagen des Themes mit — die schaden nicht, weil es
 * kein Theme-Markup gibt, auf das sie wirken könnten, und weil die eigenen
 * Regeln auf `body.lsv07s-vollbild` liegen und damit gewinnen.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

?><!DOCTYPE html>
<html <?php language_attributes(); ?> class="lsv07s-vollbild">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<?php
/* Den Titel gibt wp_head() nur aus, wenn das Theme `title-tag` unterstützt.
   Klassische Themes tun das oft nicht — und ein Dokument ohne Titel steht
   im Browser-Reiter als nackte Adresse da. */
if ( ! current_theme_supports( 'title-tag' ) ) {
    echo '<title>' . esc_html( wp_get_document_title() ) . '</title>' . "\n";
}
wp_head();
?>
</head>
<body <?php body_class(); ?>>
<?php
while ( have_posts() ) {
    the_post();
    /* `the_content()` und nicht direkt der Shortcode: So kommt durch, was
       auf der Seite wirklich steht — auch wenn jemand oberhalb noch eine
       Überschrift gesetzt hat oder die Seite mit einem Baukasten gebaut
       wurde. Der Shortcode läuft dabei wie immer mit. */
    the_content();
}

/* Rückfall: Manche Seitenbaukästen führen ihren Inhalt an `the_content()`
   vorbei und geben ihn über eine eigene Vorlage aus, die hier nicht läuft.
   Dann stünde die Seite leer da. Kam die Anwendung also nicht durch, wird
   sie hier direkt ausgegeben — lieber ohne das Drumherum des Baukastens als
   gar nicht. */
if ( ! LSV07S_Shortcode::wurde_ausgegeben() ) {
    echo LSV07S_Shortcode::render();
}

wp_footer();
?>
</body>
</html>
