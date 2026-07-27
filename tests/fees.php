<?php
define('ABSPATH','/tmp/'); define('DAY_IN_SECONDS',86400); define('YEAR_IN_SECONDS',31536000);
function __($s,$d=null){return $s;}
function apply_filters($t,$v){return $v;}
function number_format_i18n($n,$d=0){return number_format($n,$d,',','.');}
function date_i18n($f,$t){return date($f,$t);}
function wp_parse_args($a,$d){return array_merge($d,$a);}
class WP_Error{ function __construct($c,$m=''){} }
function is_wp_error($t){return $t instanceof WP_Error;}
class SVM_Fields { public static function to_number($v){return (float)str_replace(',','.',(string)$v);} public static function get_def($i){return null;} }
class SVM_Rules { public static function describe($r){return '';} }
class SVM_Formula { public static function evaluate($e,$v=[]){return 0;} }

require dirname(__DIR__) . '/schwimmverein-mitgliederverwaltung/includes/engine/class-svm-fee-calculator.php';

$fails=0;
function check($l,$a,$e){ global $fails; $ok=($a===$e); if(!$ok)$fails++; printf("%-56s %s\n",$l,$ok?'OK':'FEHL -> '.var_export($a,true)); }

$ft = ['id'=>1,'label'=>'Test','cycle'=>'yearly','due_rule'=>'','proration'=>'none','amount'=>120,'amount_mode'=>'fixed'];

// --- Zeitraumzerlegung ---
$p = SVM_Fee_Calculator::periods_in_range($ft,'2026-01-01','2026-12-31');
check('Jahresbeitrag -> 1 Periode', count($p), 1);
check('Jahresperiode Ende korrekt', $p[0]['end'], '2026-12-31');

$ft['cycle']='monthly';
$p = SVM_Fee_Calculator::periods_in_range($ft,'2026-01-01','2026-12-31');
check('Monatsbeitrag -> 12 Perioden', count($p), 12);
check('Erste Monatsperiode', $p[0]['start'].'/'.$p[0]['end'], '2026-01-01/2026-01-31');
check('Letzte Monatsperiode', $p[11]['start'].'/'.$p[11]['end'], '2026-12-01/2026-12-31');

$ft['cycle']='quarterly';
$p = SVM_Fee_Calculator::periods_in_range($ft,'2026-01-01','2026-12-31');
check('Quartalsbeitrag -> 4 Perioden', count($p), 4);
check('Q2 korrekt', $p[1]['start'].'/'.$p[1]['end'], '2026-04-01/2026-06-30');

$ft['cycle']='halfyearly';
$p = SVM_Fee_Calculator::periods_in_range($ft,'2026-01-01','2026-12-31');
check('Halbjahresbeitrag -> 2 Perioden', count($p), 2);

$ft['cycle']='once';
$p = SVM_Fee_Calculator::periods_in_range($ft,'2026-01-01','2026-12-31');
check('Einmalbeitrag -> 1 Periode', count($p), 1);

$ft['cycle']='yearly';
check('Ungueltiger Zeitraum -> leer', SVM_Fee_Calculator::periods_in_range($ft,'2026-12-31','2026-01-01'), []);

// Schaltjahr / Monatsenden
$ft['cycle']='monthly';
$p = SVM_Fee_Calculator::periods_in_range($ft,'2028-01-01','2028-03-31');
check('Schaltjahr Februar 2028 endet 29.', $p[1]['end'], '2028-02-29');

// --- Faelligkeitsregeln ---
check('Leere Regel -> Periodenbeginn', SVM_Fee_Calculator::due_date(['due_rule'=>''],'2026-04-01'), '2026-04-01');
check('Fester Termin 01.03. nach Beginn', SVM_Fee_Calculator::due_date(['due_rule'=>'01.03.'],'2026-01-01'), '2026-03-01');
check('Fester Termin bereits vorbei -> Folgejahr', SVM_Fee_Calculator::due_date(['due_rule'=>'01.03.'],'2026-06-01'), '2027-03-01');
check('Frist +30d', SVM_Fee_Calculator::due_date(['due_rule'=>'+30d'],'2026-01-01'), '2026-01-31');
check('Frist +2m', SVM_Fee_Calculator::due_date(['due_rule'=>'+2m'],'2026-01-15'), '2026-03-15');
check('Frist +6w', SVM_Fee_Calculator::due_date(['due_rule'=>'+6w'],'2026-01-01'), '2026-02-12');

echo "\n".($fails===0?"Alle Beitragstests bestanden.\n":"$fails Test(s) fehlgeschlagen.\n");
exit($fails===0?0:1);
