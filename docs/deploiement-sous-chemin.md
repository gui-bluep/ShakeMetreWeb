# Déployer ShakeMetre sous `https://fms99.mycloud.fm/metre`

La racine du domaine est prise par FileMaker Server, qui sert ShakeDesign et sa console. L'appli-
cation web est donc montée sous le préfixe `/metre`. Ce document est ce qu'il faut transmettre à
qui administre le serveur.

## Ce qu'il ne faut surtout pas faire

`composer run dev` est un script de développement. `php artisan serve` n'écoute que sur
`127.0.0.1:8000` et est mono-processus (un PDF de 12 pages bloque tout le monde) ; `npm run dev`
écrit `http://localhost:5173` dans le HTML envoyé au navigateur, donc la page arrive chez le
visiteur sans CSS ni JS ; `pail` écrit dans un terminal qui n'existe pas ; et `--kill-others` fait
tomber les quatre dès que l'un s'arrête.

## nginx

Le préfixe doit arriver à PHP dans `SCRIPT_NAME` : c'est de là que Laravel déduit sa base d'URL,
et c'est ce qui fait que `url()`, `route()`, `asset()` et les redirections portent `/metre` sans
qu'une ligne de PHP change.

`alias` + `try_files` est un piège connu de nginx. On passe donc par un lien symbolique dont le
dernier segment **est** le préfixe, ce qui permet un `root` ordinaire :

```bash
mkdir -p /srv/shakemetre
ln -s /home/mycloud/ShakeMetreWeb/public /srv/shakemetre/metre
```

```nginx
# À insérer dans le server { } qui sert déjà https://fms99.mycloud.fm
location ^~ /metre {
    root /srv/shakemetre;                 # /srv/shakemetre/metre -> .../ShakeMetreWeb/public
    index index.php;

    try_files $uri $uri/ /metre/index.php?$query_string;

    location ~ ^/metre/.*\.php$ {
        root /srv/shakemetre;

        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;      # adapter à la version installée
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param SCRIPT_NAME $fastcgi_script_name;  # => /metre/index.php

        # Les sept documents imprimés passent par dompdf : plusieurs secondes sur un gros métré.
        fastcgi_read_timeout 120s;
    }

    # Les assets compilés sont immuables (nom haché par Vite).
    location ~ ^/metre/build/ {
        root /srv/shakemetre;
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }
}
```

Deux points d'attention :

- **PHP-FPM doit pouvoir lire le projet.** S'il tourne en `www-data` alors que le code est dans
  `/home/mycloud`, il faut soit `chmod o+x /home/mycloud`, soit — mieux — un pool FPM tournant
  sous l'utilisateur `mycloud`.
- **`memory_limit` ne doit pas être verrouillé** dans le pool (`php_admin_value`) :
  `MetreDocumentController::allowRoomForDompdf()` le monte à 512 Mo par `ini_set` le temps d'une
  génération de PDF, mesurée à 128 Mo de pic sur un métré de 357 lignes.

Si la configuration nginx est celle de FileMaker Server, elle peut être réécrite à chaque mise à
jour de FMS : garder ce fichier et le réappliquer, ou demander à l'hébergeur un `include` vers un
fichier à nous.

## `.env` de production

```dotenv
APP_ENV=production
APP_DEBUG=false                       # en true, une trace affiche les identifiants FileMaker
APP_URL=https://fms99.mycloud.fm/metre
LOG_LEVEL=info

SESSION_PATH=/metre                   # le cookie ne part pas chez l'application voisine
SESSION_SECURE_COOKIE=true

DB_DATABASE=…  DB_USERNAME=…  DB_PASSWORD=…
SHAKEDESIGN_HOST=https://fms23.mycloud.fm
SHAKEDESIGN_DATABASE=ShakeDesign
SHAKEDESIGN_USERNAME=…  SHAKEDESIGN_PASSWORD=…
```

`APP_KEY` se génère **une fois** (`php artisan key:generate`) et ne se regénère jamais : il
chiffre les sessions et les cookies.

`SHAKEDESIGN_FMP_HOST` ne se renseigne que si l'hôte qui marche depuis le poste de la personne
qui clique un lien `fmp://` diffère de celui que le serveur web utilise pour le Data API.

Les variables `SHAKEMETRE_FM_*` (l'import de l'ancienne base) n'ont rien à faire là en
permanence : on les met le temps d'un import, on les retire après.

## Déploiement

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
```

À refaire à chaque déploiement — un `config:cache` périmé sert l'ancien `.env`.
`storage/` et `bootstrap/cache/` doivent être accessibles en écriture à PHP-FPM.

## Le worker de queue n'est pas optionnel

`MetreLineObserver` place `RecalculateMetreTotals` en file à chaque écriture de ligne. Sans worker,
les totaux d'un métré et le « Ratio réel » ne bougent plus après une modification : l'écran a
l'air cassé et rien ne le signale.

```ini
# /etc/systemd/system/shakemetre-queue.service
[Unit]
Description=ShakeMetre — worker de queue
After=network.target mysql.service

[Service]
User=mycloud
WorkingDirectory=/home/mycloud/ShakeMetreWeb
ExecStart=/usr/bin/php artisan queue:work --tries=3 --timeout=300 --sleep=1
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable --now shakemetre-queue
```

Après chaque déploiement : `php artisan queue:restart` (le worker garde le code en mémoire).

Aucune tâche planifiée n'existe dans ce projet : pas de cron à installer.

## Vérifier

```bash
curl -si https://fms99.mycloud.fm/metre/up | head -1                  # 200
curl -s  https://fms99.mycloud.fm/metre/login | grep -o 'base-path[^>]*'  # content="/metre"
curl -si https://fms99.mycloud.fm/metre/dashboard | grep -i location  # …/metre/login
```

Puis, dans un navigateur et connecté : ouvrir un métré, modifier une quantité, **recharger** —
le chiffre doit avoir tenu. C'est le test qui prouve que les appels `/api` arrivent bien sous le
préfixe, parce que leur échec est silencieux. L'onglet Réseau ne doit montrer aucune requête vers
`/api/…` à la racine du domaine.

## Côté ShakeDesign

- Le lien SSO qu'il construit doit viser `https://fms99.mycloud.fm/metre/sso/consume/{token}`.
- Les jetons Sanctum vivent en base : les émettre **sur le serveur de production**
  (`php artisan shakedesign:issue-token`), ceux d'un poste de développement ne suivent pas.
- Le serveur doit pouvoir joindre l'hôte du Data API FileMaker en sortie, sans quoi tous les
  écrans qui lisent ShakeDesign se dégradent en « n'a pas pu être lu ».
