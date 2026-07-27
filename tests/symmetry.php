<?php
// Prüft: Zu jeder Aktion, die einen Datensatz anlegt, gibt es eine Löschaktion,
// und jede Löschaktion ist in der Oberfläche auch erreichbar.
$dir = dirname(__DIR__) . '/schwimmverein-mitgliederverwaltung/';
$fails = 0;
function fail($m){ global $fails; $fails++; echo "  FEHLT: $m\n"; }

$router = file_get_contents($dir.'includes/core/class-svm-router.php');
preg_match("/private static function actions\(\).*?return array\((.*?)\n\t\t\);/s", $router, $m);
preg_match_all("/'([a-z_]+)'\s*=>\s*'[a-z_]+'/", $m[1], $am);
$actions = $am[1];

// Aktionen, die keinen eigenen Datensatz anlegen -> keine Löschaktion noetig.
$no_record = [
    'save_field_access',   // aktualisiert Rechtezeilen
    'save_settings',       // WordPress-Optionen
    'save_own_profile',    // Feldwerte des eigenen Datensatzes
    'save_member',         // Loeschen ueber delete_member
    'assign_user',         // Zuordnung, leert sich durch leere Auswahl
];

// Zuordnung Anlegen -> Loeschen
$pairs = [
    'save_member' => 'delete_member',
    'save_family' => 'delete_family',
    'add_family_member' => 'remove_family_member',
    'assign_member_fee' => 'remove_member_fee',
    'save_field' => 'delete_field',
    'save_unit' => 'delete_unit',
    'save_unit_type' => 'delete_unit_type',
    'save_status' => 'delete_status',
    'save_transition' => 'delete_transition',
    'save_role' => 'delete_role',
    'save_payment_method' => 'delete_payment_method',
    'save_number_range' => 'delete_number_range',
    'save_template' => 'delete_template',
    'save_fee_type' => 'delete_fee_type',
    'save_rule' => 'delete_rule',
    'commit_fee_run' => 'delete_fee_run',
    'create_invoice' => 'delete_invoice',
    'record_payment' => 'delete_payment',
    'save_mandate' => 'delete_mandate',
    'save_file_profile' => 'delete_file_profile',
    'create_payment_run' => 'delete_payment_run',
    'save_dunning_level' => 'delete_dunning_level',
    'save_message' => 'delete_message',
    'save_message_category' => 'delete_message_category',
    'save_export_profile' => 'delete_export_profile',
];

echo "1. Legt an -> kann loeschen\n";
$creators = array_filter($actions, fn($a) => preg_match('/^(save|add|assign|create|commit|record)_/', $a));
foreach ($creators as $c) {
    if (in_array($c, $no_record, true) && !isset($pairs[$c])) continue;
    if (!isset($pairs[$c])) { fail("Zuordnung fuer '$c' nicht definiert"); continue; }
    if (!in_array($pairs[$c], $actions, true)) fail("Loeschaktion '{$pairs[$c]}' zu '$c'");
}
printf("   %d Anlege-Aktionen geprueft\n", count($creators));

echo "2. Loeschaktion in der Oberflaeche erreichbar\n";
$ui = '';
foreach (glob($dir.'includes/frontend/views/*.php') as $f) $ui .= file_get_contents($f);
$deleters = array_filter($actions, fn($a) => preg_match('/^(delete|remove|revoke)_/', $a));
foreach ($deleters as $d) {
    if (!preg_match("/'".preg_quote($d,'/')."'/", $ui)) fail("Knopf fuer '$d' in keiner Ansicht");
}
printf("   %d Loeschaktionen geprueft\n", count($deleters));

echo "3. Jede Loeschaktion hat einen Handler\n";
preg_match_all('/private static function do_([a-z_]+)\(/', $router, $hm);
foreach ($deleters as $d) {
    if (!in_array($d, $hm[1], true)) fail("Handler do_$d()");
}

echo $fails === 0 ? "\nAlles Anlegbare ist auch loeschbar.\n" : "\n$fails Problem(e).\n";
exit($fails === 0 ? 0 : 1);
