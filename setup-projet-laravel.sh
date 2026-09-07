#!/bin/bash

# ==========================================================
# Script de mise en route du projet Laravel (macOS)
# À lancer depuis la racine du projet : ./setup-projet-laravel.sh
# ==========================================================

set -e  # arrête le script à la première erreur

echo "🔍 Vérification des prérequis..."
echo ""

# ----------------------------------------------------------
# 1. Homebrew
# ----------------------------------------------------------
if ! command -v brew &> /dev/null; then
    echo "📦 Homebrew non trouvé, installation..."
    /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"

    # Ajoute brew au PATH pour la suite du script (Apple Silicon)
    if [ -f /opt/homebrew/bin/brew ]; then
        eval "$(/opt/homebrew/bin/brew shellenv)"
    fi
else
    echo "✅ Homebrew déjà installé"
fi

# ----------------------------------------------------------
# 2. PHP
# ----------------------------------------------------------
if ! command -v php &> /dev/null; then
    echo "📦 PHP non trouvé, installation..."
    brew install php
else
    echo "✅ PHP déjà installé ($(php -v | head -n 1))"
fi

# ----------------------------------------------------------
# 3. Composer
# ----------------------------------------------------------
if ! command -v composer &> /dev/null; then
    echo "📦 Composer non trouvé, installation..."
    brew install composer
else
    echo "✅ Composer déjà installé ($(composer -V))"
fi

# ----------------------------------------------------------
# 4. MySQL
# ----------------------------------------------------------
if ! command -v mysql &> /dev/null; then
    echo "📦 MySQL non trouvé, installation..."
    brew install mysql
    brew services start mysql
    echo "⚠️  Pense à lancer 'mysql_secure_installation' pour sécuriser l'installation."
else
    echo "✅ MySQL déjà installé"
    # Démarre le service s'il n'est pas déjà lancé
    if ! brew services list | grep mysql | grep -q started; then
        echo "▶️  Démarrage du service MySQL..."
        brew services start mysql
    fi
fi

# ----------------------------------------------------------
# 5. Node.js / npm
# ----------------------------------------------------------
if ! command -v node &> /dev/null; then
    echo "📦 Node.js non trouvé, installation..."
    brew install node
else
    echo "✅ Node.js déjà installé ($(node -v))"
fi

echo ""
echo "🔍 Vérifications terminées."
echo ""

# ----------------------------------------------------------
# 6. Dépendances PHP du projet
# ----------------------------------------------------------
echo "📦 Réinstallation des dépendances PHP (vendor/)..."
rm -rf vendor
composer install

# ----------------------------------------------------------
# 7. Fichier .env
# ----------------------------------------------------------
if [ ! -f .env ]; then
    echo "⚠️  Aucun fichier .env trouvé, copie de .env.example..."
    cp .env.example .env
    php artisan key:generate
else
    echo "✅ Fichier .env déjà présent (envoyé avec le projet)."
    echo "⚠️  Pense à vérifier/adapter manuellement : DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_HOST, APP_URL."
fi

# ----------------------------------------------------------
# 8. Base de données
# ----------------------------------------------------------
read -p "➡️  Nom de la base de données à créer (laisser vide pour passer cette étape) : " DB_NAME
if [ -n "$DB_NAME" ]; then
    echo "📦 Création de la base '$DB_NAME'..."
    mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;"
    echo "⚠️  Vérifie que DB_DATABASE=$DB_NAME correspond bien dans le fichier .env."
fi

read -p "➡️  Lancer les migrations maintenant ? (o/n) " RUN_MIGRATE
if [ "$RUN_MIGRATE" = "o" ]; then
    php artisan migrate
    read -p "➡️  Lancer aussi les seeders ? (o/n) " RUN_SEED
    if [ "$RUN_SEED" = "o" ]; then
        php artisan db:seed
    fi
fi

# ----------------------------------------------------------
# 9. Dépendances front-end
# ----------------------------------------------------------
echo "📦 Réinstallation des dépendances front-end (node_modules/)..."
rm -rf node_modules
npm install

# ----------------------------------------------------------
# Fin
# ----------------------------------------------------------
echo ""
echo "✅ Installation terminée !"
echo ""

# Vérifie si le script "dev" existe dans composer.json (Laravel 11+, package concurrently)
if grep -q '"dev"' composer.json 2>/dev/null; then
    echo "Pour lancer le projet, deux options :"
    echo "  • Recommandé : composer run dev   (lance artisan serve + Vite en parallèle, un seul terminal)"
    echo "  • Manuel : php artisan serve  puis  npm run dev  (dans deux terminaux séparés)"
else
    echo "Pour lancer le projet, ouvrir deux terminaux :"
    echo "  1) php artisan serve"
    echo "  2) npm run dev"
fi