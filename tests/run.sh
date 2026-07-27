#!/usr/bin/env bash
#
# Führt alle Prüfungen aus. Sie brauchen kein WordPress und keine Datenbank —
# geprüft werden Syntax, Schema, Verdrahtung und die reine Rechenlogik.
#
# Aufruf:  bash tests/run.sh

set -u

cd "$(dirname "$0")/.." || exit 1

plugin="schwimmverein-mitgliederverwaltung"
fails=0

run() {
	printf '\n\033[1m== %s\033[0m\n' "$1"
	shift
	if "$@"; then
		return 0
	fi
	fails=$((fails + 1))
	return 1
}

echo "== PHP-Syntax"
lint_errors=$(find "$plugin" -name '*.php' -print0 | xargs -0 -n1 php -l 2>&1 | grep -v '^No syntax errors' || true)

if [ -n "$lint_errors" ]; then
	echo "$lint_errors"
	fails=$((fails + 1))
else
	printf 'Alle Dateien fehlerfrei (%s Dateien).\n' "$(find "$plugin" -name '*.php' | wc -l | tr -d ' ')"
fi

run "Schema (dbDelta-Tauglichkeit)"      php tests/schema.php
run "Klassen und Methodenaufrufe"        php tests/check.php
run "Verdrahtung (Router, Ansichten)"    php tests/wiring.php
run "Anlegen und Löschen im Gleichgewicht" php tests/symmetry.php
run "IBAN und Formelparser"              php tests/smoke.php
run "SEPA-Dateierzeugung"                php tests/sepa.php
run "Beitragszeiträume und Fälligkeit"   php tests/fees.php

printf '\n'

if [ "$fails" -eq 0 ]; then
	printf '\033[32mAlle Prüfungen bestanden.\033[0m\n'
	exit 0
fi

printf '\033[31m%d Prüfung(en) fehlgeschlagen.\033[0m\n' "$fails"
exit 1
