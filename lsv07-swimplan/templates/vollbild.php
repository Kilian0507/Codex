<?php
/**
 * Die eigene Seitenvorlage.
 *
 * Sie ruft `get_header()` und `get_footer()` nicht auf — damit gibt es
 * Kopfleiste, Navigation, Seitenleiste und Fusszeile des Themes schlicht
 * nicht. Sie werden nicht per CSS versteckt, sondern gar nicht erst
 * erzeugt. Das ist der Unterschied zu einer Liste bekannter Klassennamen,
 * die bei jedem unbekannten Theme stillschweigend versagt.
 *
 * `wp_head()` und `wp_footer()` bleiben: Daran hängen die Stilvorlage und
 * das Skript von SwimPlan. Damit kommen auch die Stilvorlagen des Themes
 * mit — sie schaden nicht, weil es kein Theme-Markup gibt, auf das sie
 * wirken könnten. `wp_body_open` wird nicht aufgerufen; was sich dort
 * einhängt, gehört nicht in eine Vollbildanwendung.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?><!DOCTYPE html>
<html <?php language_attributes(); ?> class="lsv07p-vollbild">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<?php
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
    the_content();
}

/* Falls der Shortcode im Inhalt nicht erreichbar war — etwa weil ein
   Seitenbaukasten den Inhalt anders ausliefert —, wird die Anwendung hier
   nachgeliefert. Ohne das wäre die Seite leer. */
if ( ! LSV07P_Shortcode::wurde_ausgegeben() ) {
    echo LSV07P_Shortcode::render();
}

wp_footer();
?>
</body>
</html>
