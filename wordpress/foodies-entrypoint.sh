#!/bin/bash
# Starts WordPress as the official image does, and installs the plugins in the
# background once wp-config.php is written and the database answers.
set -e
install-plugins.sh > /proc/1/fd/1 2>&1 &
exec docker-entrypoint.sh "$@"
