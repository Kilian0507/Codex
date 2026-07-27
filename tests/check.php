<?php
// Statische Prüfung: existieren alle aufgerufenen SVM_Klasse::methode()?
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/schwimmverein-mitgliederverwaltung'));
foreach ($it as $f) { if ($f->isFile() && $f->getExtension()==='php') $files[] = $f->getPathname(); }

$methods = []; // class => [method]
$classes = [];
foreach ($files as $file) {
    $src = file_get_contents($file);
    if (preg_match('/^\s*class\s+(SVM_\w+)/m', $src, $m)) {
        $cls = $m[1];
        $classes[$cls] = $file;
        preg_match_all('/function\s+(\w+)\s*\(/', $src, $mm);
        $methods[$cls] = $mm[1];
    }
}

$errors = [];
foreach ($files as $file) {
    $src = file_get_contents($file);
    preg_match_all('/(SVM_\w+)::(\w+)\s*\(/', $src, $mm, PREG_SET_ORDER);
    foreach ($mm as $call) {
        [$full, $cls, $method] = $call;
        if ($cls === 'SVM_DB' && in_array($method, ['table','insert','update','delete','get','all','id_list','now'])) continue;
        if (!isset($classes[$cls])) { $errors[] = "$file: unbekannte Klasse $cls"; continue; }
        if (!in_array($method, $methods[$cls], true)) $errors[] = "$file: $cls::$method() nicht definiert";
    }
}
$errors = array_unique($errors);
echo empty($errors) ? "OK: alle Aufrufe aufloesbar (".count($classes)." Klassen)\n" : implode("\n", $errors)."\n";
