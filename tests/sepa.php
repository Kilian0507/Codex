<?php
define('ABSPATH','/tmp/');
function __($s,$d=null){return $s;}
function apply_filters($t,$v){return $v;}
class WP_Error { private $c,$m; function __construct($c,$m=''){$this->c=$c;$this->m=$m;} function get_error_message(){return $this->m;} }
function is_wp_error($t){return $t instanceof WP_Error;}
class SVM_File_Profiles { public static function direction($f){ return (0===strpos($f,'pain.001')) ? 'credit':'debit'; } }

$base=dirname(__DIR__) . '/schwimmverein-mitgliederverwaltung/includes/';
require $base.'core/class-svm-iban.php';
require $base.'payments/class-svm-sepa-generator.php';

$profile = [
  'label'=>'Hauptkonto','format'=>'pain.008.001.02',
  'creditor_id'=>'DE98ZZZ09999999999','creditor_name'=>'Schwimmverein Musterstadt e.V.',
  'creditor_iban'=>'DE89370400440532013000','creditor_bic'=>'COBADEFFXXX',
  'purpose_template'=>'','batch_booking'=>1,'lead_days_frst'=>5,'lead_days_rcur'=>2,
  'csv_mapping'=>'','csv_delimiter'=>';',
];

$items = [
  ['invoice_id'=>1,'member_id'=>1,'member_name'=>'Müller, Jörg','amount'=>120.00,
   'purpose'=>'Beitrag 2026 SV-2026-00001','end_to_end_id'=>'SV-2026-00001-1','name'=>'Jörg Müller',
   'mandate_id'=>1,'mandate_ref'=>'MND-000001','iban'=>'DE89370400440532013000','bic'=>'COBADEFFXXX',
   'signed_at'=>'2024-05-01','sequence_type'=>'RCUR'],
  ['invoice_id'=>2,'member_id'=>2,'member_name'=>'Groß & Söhne','amount'=>72.50,
   'purpose'=>'Beitrag 2026 SV-2026-00002','end_to_end_id'=>'SV-2026-00002-2','name'=>'Anna Groß',
   'mandate_id'=>2,'mandate_ref'=>'MND-000002','iban'=>'AT611904300234573201','bic'=>'',
   'signed_at'=>'2026-01-15','sequence_type'=>'FRST'],
];

$xml = SVM_SEPA_Generator::generate($profile, $items, '2026-08-05', 'SVM20260726120000123');
if (is_wp_error($xml)) { echo "FEHLER: ".$xml->get_error_message()."\n"; exit(1); }

$fails=0;
function check($l,$a,$e){ global $fails; $ok=$a===$e; if(!$ok)$fails++; printf("%-50s %s\n",$l,$ok?'OK':'FEHL -> '.var_export($a,true)); }

// XML muss wohlgeformt sein
$dom = new DOMDocument(); $loaded = $dom->loadXML($xml);
check('XML ist wohlgeformt', $loaded, true);

$xp = new DOMXPath($dom);
$xp->registerNamespace('p','urn:iso:std:iso:20022:tech:xsd:pain.008.001.02');

check('Wurzel CstmrDrctDbtInitn vorhanden', $xp->query('//p:CstmrDrctDbtInitn')->length, 1);
check('Anzahl Transaktionen im Kopf', $xp->query('//p:GrpHdr/p:NbOfTxs')->item(0)->nodeValue, '2');
check('Kontrollsumme im Kopf', $xp->query('//p:GrpHdr/p:CtrlSum')->item(0)->nodeValue, '192.50');
check('Zwei Bloecke (FRST + RCUR getrennt)', $xp->query('//p:PmtInf')->length, 2);
check('Glaeubiger-ID gesetzt', $xp->query('//p:CdtrSchmeId//p:Othr/p:Id')->item(0)->nodeValue, 'DE98ZZZ09999999999');
check('Zahlungsart DD', $xp->query('//p:PmtInf/p:PmtMtd')->item(0)->nodeValue, 'DD');
check('Einzugsdatum gesetzt', $xp->query('//p:ReqdColltnDt')->item(0)->nodeValue, '2026-08-05');
check('Mandatsreferenz uebernommen', $xp->query('//p:MndtId')->item(0)->nodeValue, 'MND-000001');
check('Betrag mit Punkt und Waehrung', $xp->query('//p:InstdAmt')->item(0)->nodeValue, '120.00');
check('Waehrungsattribut EUR', $xp->query('//p:InstdAmt')->item(0)->getAttribute('Ccy'), 'EUR');
check('Fehlende BIC -> NOTPROVIDED', (bool)preg_match('/NOTPROVIDED/', $xml), true);
check('Umlaut transliteriert (Mueller)', (bool)preg_match('/Joerg Mueller/', $xml), true);
check('Kaufmanns-Und ersetzt', (bool)strpos($xml,'&amp;')===false, true);
check('Keine rohen Umlaute im Namen', (bool)preg_match('/<Nm>[^<]*[äöüß]/u', $xml), false);

// Fehlerfaelle
$bad = $profile; $bad['creditor_iban']='DE00000000000000000000';
check('Ungueltige Vereins-IBAN wird abgelehnt', is_wp_error(SVM_SEPA_Generator::generate($bad,$items,'2026-08-05','X')), true);
$bad2 = $profile; $bad2['creditor_id']='';
check('Fehlende Glaeubiger-ID wird abgelehnt', is_wp_error(SVM_SEPA_Generator::generate($bad2,$items,'2026-08-05','X')), true);
check('Leerer Lauf wird abgelehnt', is_wp_error(SVM_SEPA_Generator::generate($profile,[],'2026-08-05','X')), true);

// pain.001 (Ueberweisung)
$credit = $profile; $credit['format']='pain.001.001.03';
$xml2 = SVM_SEPA_Generator::generate($credit,$items,'2026-08-05','SVMCREDIT1');
$dom2 = new DOMDocument();
check('pain.001 wohlgeformt', $dom2->loadXML($xml2), true);
$xp2 = new DOMXPath($dom2); $xp2->registerNamespace('q','urn:iso:std:iso:20022:tech:xsd:pain.001.001.03');
check('pain.001 Wurzel CstmrCdtTrfInitn', $xp2->query('//q:CstmrCdtTrfInitn')->length, 1);
check('pain.001 Zahlungsart TRF', $xp2->query('//q:PmtMtd')->item(0)->nodeValue, 'TRF');

echo "\n".($fails===0 ? "Alle SEPA-Tests bestanden.\n" : "$fails Test(s) fehlgeschlagen.\n");
echo "\n--- Auszug der erzeugten Datei ---\n".implode("\n", array_slice(explode("\n",$xml),0,22))."\n";
exit($fails===0?0:1);
