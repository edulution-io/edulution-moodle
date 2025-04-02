#!/bin/bash

set -e

# Starte Moodle im Vordergrund (wichtig, dass dieser Prozess zuletzt kommt!)
/opt/bitnami/scripts/moodle/entrypoint.sh /opt/bitnami/scripts/moodle/run.sh &

MOODLE_PID=$!

echo "Warte darauf, dass Moodle fertig installiert ist..."

# Warte darauf, dass Moodle erreichbar ist (HTTP 200)
until curl -s -f http://localhost/login/index.php > /dev/null; do
  echo "Moodle noch nicht erreichbar, warte..."
  sleep 5
done

echo "Moodle ist erreichbar – führe Setup im Hintergrund aus."

# Setup im Hintergrund starten
/custom_scripts/setup.sh &

# Warte auf Moodle-Prozess (damit Container aktiv bleibt)
wait "$MOODLE_PID"
