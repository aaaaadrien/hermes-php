#!/usr/bin/env bash
# =============================================================================
# Hermes (PHP) — serveur de développement
# =============================================================================
# Lance `php -S 0.0.0.0:8888` avec 4 workers (PHP_CLI_SERVER_WORKERS, PHP >= 7.4).
#
# En production utiliser Apache httpd + php-fpm
# =============================================================================
set -euo pipefail
cd "$(dirname "$0")"

PORT=8888
WORKERS=4

# Arrête une instance déjà à l'écoute sur le port.
if command -v fuser >/dev/null 2>&1 && fuser -s "${PORT}/tcp" 2>/dev/null; then
  echo "Port ${PORT} déjà utilisé : arrêt du serveur existant..."
  fuser -k "${PORT}/tcp" 2>/dev/null || true
  sleep 1
fi

export PHP_CLI_SERVER_WORKERS="${WORKERS}"
echo "Hermes (PHP) : http://0.0.0.0:${PORT} — ${WORKERS} workers"
exec php -S 0.0.0.0:"${PORT}" index.php
