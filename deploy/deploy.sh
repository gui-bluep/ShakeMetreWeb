#!/usr/bin/env bash
#
# Déploiement de ShakeMetre sur fms99. Idempotent : relançable sans risque.
#
#   ~/ShakeMetreWeb/deploy/deploy.sh
#
# Ce que ce script sait et qu'une suite de commandes tapées à la main oublie :
#
# - La chaîne d'outils est du Homebrew, ajoutée au PATH par ~/.bashrc, donc invisible à
#   toute exécution non interactive (cron, systemd, ssh non interactif). D'où le shellenv.
# - `php artisan optimize` GÈLE les valeurs du .env. Toute modification du .env exige de le
#   relancer, sinon la nouvelle valeur n'est jamais lue - en silence.
# - Le worker de queue garde le code en mémoire : sans redémarrage il continue de faire
#   tourner l'ancien.
# - Les assets sont construits ici, pas versionnés (public/build est dans .gitignore).

set -euo pipefail

eval "$(/home/linuxbrew/.linuxbrew/bin/brew shellenv bash)"
cd "$(dirname "$(readlink -f "$0")")/.."

echo "▸ mode maintenance"
php artisan down --retry=15 >/dev/null 2>&1 || true
# Quoi qu'il arrive ensuite - migration refusée, build cassé - le site est remis en ligne.
trap 'php artisan up >/dev/null 2>&1 || true' EXIT

echo "▸ code"
git pull --ff-only

echo "▸ dépendances PHP (sans les paquets de développement)"
composer install --no-dev --optimize-autoloader --no-interaction

echo "▸ dépendances front + build"
npm ci --no-audit --no-fund
npm run build

echo "▸ migrations"
php artisan migrate --force

echo "▸ caches (config, routes, vues, events)"
php artisan optimize

echo "▸ worker de queue"
php artisan queue:restart
systemctl --user is-active shakemetre-queue >/dev/null || systemctl --user start shakemetre-queue

echo "▸ vérification"
php artisan about --only=environment | grep -E "Environment|Debug|URL"

echo "✓ déployé — $(git log --oneline -1)"
