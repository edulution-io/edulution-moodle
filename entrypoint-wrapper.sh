#!/bin/bash

#apt-get update
#apt-get install -y curl

# Start Bitnami entrypoint in background
/opt/bitnami/scripts/moodle/entrypoint.sh /opt/bitnami/scripts/moodle/run.sh &

curl -fsSL https://raw.githubusercontent.com/edulution-io/edulution-moodle/refs/heads/main/setup.sh -o /setup.sh
source /setup.sh

# Warte ein bisschen (alternativ: auf HTTP warten wie du es schon tust)
sleep 300

# Jetzt eigenes Skript ausführen
/custom_scripts/setup.sh

# Warten, bis Moodle-Prozess beendet wird
wait
