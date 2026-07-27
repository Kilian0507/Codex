<?php
define('ABSPATH', '/tmp/');
// Minimale WordPress-Stubs für die reine Logik
function __($s,$d=null){return $s;}
function apply_filters($t,$v){return $v;}
function esc_html($s){return $s;}
class WP_Error { private $m; function __construct($c,$m=''){$this->m=$m;} function get_error_message(){return $this->m;} }
function is_wp_error($t){return $t instanceof WP_Error;}
class SVM_Fields { public static function to_number($v){ $v=str_replace([' ',"\xc2\xa0"],'',(string)$v); return (float)str_replace(',','.',$v);} }

$base = dirname(__DIR__) . '/schwimmverein-mitgliederverwaltung/includes/';
require $base.'core/class-svm-iban.php';
require $base.'engine/class-svm-formula.php';

$fails = 0;
function check($label, $actual, $expected) {
    global $fails;
    $ok = $actual === $expected;
    if (!$ok) $fails++;
    printf("%-52s %s  (%s)\n", $label, $ok ? 'OK  ' : 'FEHL', var_export($actual, true));
}

// --- IBAN-Prüfziffern (echte Testwerte) ---
check('IBAN DE89370400440532013000 gueltig', SVM_IBAN::is_valid('DE89 3704 0044 0532 0130 00'), true);
check('IBAN GB82WEST12345698765432 gueltig', SVM_IBAN::is_valid('GB82WEST12345698765432'), true);
check('IBAN AT611904300234573201 gueltig',   SVM_IBAN::is_valid('AT61 1904 3002 3457 3201'), true);
check('IBAN mit falscher Pruefziffer',       SVM_IBAN::is_valid('DE88370400440532013000'), false);
check('IBAN zu kurz',                        SVM_IBAN::is_valid('DE89'), false);
check('IBAN maskiert',                       SVM_IBAN::mask('DE89370400440532013000'), 'DE89**************3000');
check('IBAN formatiert',                     SVM_IBAN::format('DE89370400440532013000'), 'DE89 3704 0044 0532 0130 00');
check('BIC gueltig',                         SVM_IBAN::is_valid_bic('COBADEFFXXX'), true);
check('BIC ungueltig',                       SVM_IBAN::is_valid_bic('123'), false);

// --- Formelparser ---
check('Formel Grundrechenarten',   SVM_Formula::evaluate('2 + 3 * 4'), 14.0);
check('Formel Klammern',           SVM_Formula::evaluate('(2 + 3) * 4'), 20.0);
check('Formel Variablen',          SVM_Formula::evaluate('{grund} * 0.5 + 10', ['grund' => 120]), 70.0);
check('Formel Komma-Dezimaltrenn.',SVM_Formula::evaluate('{betrag} * 2', ['betrag' => '12,50']), 25.0);
check('Formel max()',              SVM_Formula::evaluate('max(30, {alter} * 1.5)', ['alter' => 40]), 60.0);
check('Formel min()/round()',      SVM_Formula::evaluate('round(min(10.567, 99), 2)'), 10.57);
check('Formel unbekannte Variable', SVM_Formula::evaluate('{gibtsnicht} + 5'), 5.0);
check('Formel unaeres Minus',      SVM_Formula::evaluate('-5 + 8'), 3.0);
$err = SVM_Formula::evaluate('1 / 0');
check('Formel Division durch null -> Fehler', is_wp_error($err), true);
$err2 = SVM_Formula::evaluate('2 +');
check('Formel Syntaxfehler -> Fehler', is_wp_error($err2), true);
$err3 = SVM_Formula::evaluate('system("rm -rf /")');
check('Formel: kein Code-Aufruf moeglich', is_wp_error($err3), true);

echo $fails === 0 ? "\nAlle Tests bestanden.\n" : "\n$fails Test(s) fehlgeschlagen.\n";
exit($fails === 0 ? 0 : 1);
