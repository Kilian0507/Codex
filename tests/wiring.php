<?php
// Prüft die per Zeichenkette aufgelösten Verknüpfungen (Router-Aktionen, View-Dispatch).
$dir = dirname(__DIR__) . '/schwimmverein-mitgliederverwaltung/';
$fails = 0;
function fail($m){ global $fails; $fails++; echo "FEHLT: $m\n"; }

// --- Router: jede Aktion braucht do_<action>() ---
$router = file_get_contents($dir.'includes/core/class-svm-router.php');
preg_match("/private static function actions\(\).*?return array\((.*?)\n\t\t\);/s", $router, $m);
preg_match_all("/'([a-z_]+)'\s*=>\s*'([a-z_]+)'/", $m[1], $actions, PREG_SET_ORDER);
preg_match_all('/private static function do_([a-z_]+)\(/', $router, $handlers);
$defined = $handlers[1];

// Rechtekatalog einlesen, um Rechteschlüssel zu prüfen.
$perm_src = file_get_contents($dir.'includes/core/class-svm-permissions.php');
preg_match_all("/'([a-z_]+)'\s*=>\s*__\(/", $perm_src, $pm);
$permissions = array_unique($pm[1]);

echo "Router-Aktionen: ".count($actions)."\n";
foreach ($actions as $a) {
    [$full, $action, $perm] = $a;
    if (!in_array($action, $defined, true)) fail("Handler do_$action()");
    if (!in_array($perm, $permissions, true)) fail("Recht '$perm' (Aktion $action) nicht im Katalog");
}

// --- App: jede Ansicht braucht Klasse + Methode ---
$app = file_get_contents($dir.'includes/frontend/class-svm-app.php');
preg_match("/private static function views\(\).*?return array\((.*?)\n\t\t\);/s", $app, $m2);
preg_match_all("/'([a-z_]+)'\s*=>\s*array\(\s*'([a-z]+)',\s*'([a-z_]+)',\s*'(SVM_\w+)',\s*'(\w+)'/", $m2[1], $views, PREG_SET_ORDER);

echo "Ansichten: ".count($views)."\n";
$navs = [];
preg_match("/private static function nav\(\).*?return array\((.*?)\n\t\t\);/s", $app, $m3);
preg_match_all("/'([a-z_]+)'\s*=> array\(/", $m3[1], $nm);
$navs = $nm[1];

foreach ($views as $v) {
    [$full, $view, $nav, $perm, $class, $method] = $v;
    $file = $dir.'includes/frontend/views/class-'.strtolower(str_replace('_','-',$class)).'.php';
    if (!file_exists($file)) { fail("Datei fuer $class"); continue; }
    if (!preg_match('/function\s+'.$method.'\s*\(/', file_get_contents($file))) fail("$class::$method()");
    if (!in_array($perm, $permissions, true)) fail("Recht '$perm' (Ansicht $view) nicht im Katalog");
    if (!in_array($nav, $navs, true)) fail("Navigationspunkt '$nav' (Ansicht $view)");
}

// --- Formulare: jede genutzte Aktion muss im Router registriert sein ---
$action_keys = array_column($actions, 1);
foreach (glob($dir.'includes/frontend/views/*.php') as $f) {
    $src = file_get_contents($f);
    preg_match_all("/(?:form_open|action_button|button_cell)\(\s*'([a-z_]+)'/", $src, $used);
    foreach (array_unique($used[1]) as $u) {
        if (!in_array($u, $action_keys, true)) fail("Aktion '$u' in ".basename($f)." nicht im Router");
    }
}

// --- Tabellennamen: jede genutzte Tabelle muss im Schema stehen ---
$schema = file_get_contents($dir.'includes/core/class-svm-schema.php');
preg_match_all('/CREATE TABLE \{\$p\}(\w+)/', $schema, $tm);
$tables = $tm[1];
foreach (glob($dir.'includes/**/*.php') as $f) {
    $src = file_get_contents($f);
    preg_match_all("/SVM_DB::(?:table|all|insert|update|delete|get)\(\s*'([a-z_]+)'/", $src, $um);
    foreach (array_unique($um[1]) as $t) {
        if (!in_array($t, $tables, true)) fail("Tabelle '$t' in ".basename($f)." fehlt im Schema");
    }
}
echo "Tabellen im Schema: ".count($tables)."\n";

echo $fails === 0 ? "\nAlle Verknüpfungen aufloesbar.\n" : "\n$fails Problem(e).\n";
exit($fails === 0 ? 0 : 1);
