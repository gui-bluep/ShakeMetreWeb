# ShakeMetre en production — `https://fms99.mycloud.fm/metre`

Écrit **après** la mise en service, contre la machine réelle : ce qui suit est constaté, pas
projeté. La racine du domaine appartient à FileMaker Server, d'où le sous-chemin.

## Ce que la machine est

| | |
|---|---|
| OS | Ubuntu 24.04, l'application dans `/home/mycloud/ShakeMetreWeb` |
| Serveur web | nginx de la distribution (`/usr/sbin/nginx`) **lancé par FileMaker Server** avec sa propre configuration ; workers en `fmserver` |
| PHP | **Homebrew**, 8.5.10, dans `/home/linuxbrew/.linuxbrew` |
| php-fpm | brew également, service **utilisateur** systemd, écoute `127.0.0.1:9000` |
| MySQL | brew, service utilisateur, base `shakemetre` |
| Worker de queue | `shakemetre-queue`, service utilisateur |

Trois conséquences qui expliquent presque tous les pièges de cette installation :

- **La chaîne d'outils vient de `brew shellenv`, appelé dans `~/.bashrc`.** Or le `.bashrc`
  d'Ubuntu sort immédiatement pour un shell non interactif, donc `php`, `node`, `composer` et
  `mysql` sont **introuvables** depuis `ssh machine 'commande'`, depuis cron et depuis systemd.
  Tout script doit charger l'environnement lui-même — c'est la première ligne de `deploy.sh`.
- **Les workers php-fpm tournent en `mycloud`, pas en `www-data`.** La directive `user` du pool
  est ignorée parce que le maître n'est pas root. C'est une bonne nouvelle : PHP a déjà tous les
  droits sur le projet, `storage/` compris, et aucun `sudo` n'est nécessaire côté application.
- **Ce sont des services *utilisateur*.** Ils ne démarreraient pas au boot sans
  `loginctl enable-linger mycloud`, qui est appliqué (`Linger=yes`). Sans lui, un redémarrage
  laisse l'application inaccessible jusqu'à ce que quelqu'un ouvre une session SSH. Corollaire :
  un `systemctl --user stop` malencontreux emporte aussi la base de données.

## Le raccordement à nginx

Une seule ligne dans la configuration de FileMaker Server, dans son bloc `server { listen 443 }` :

    include "/home/mycloud/ShakeMetreWeb/deploy/nginx-metre.conf";

Le contenu vit dans le dépôt (`deploy/nginx-metre.conf`) : versionné avec le code qu'il sert, et
modifiable **sans sudo**. Seule cette ligne d'`include` demande les droits, et elle n'est à
poser qu'une fois.

Deux prérequis, tous deux faits :

- `chmod o+x /home/mycloud` — le worker nginx tourne en `fmserver` et devait pouvoir traverser
  le home pour lire `public/`. Traversée seule, pas de listage.
- `~/webroot/metre` → `ShakeMetreWeb/public`, un lien symbolique dont le dernier segment **est**
  le préfixe. C'est ce qui permet un `root` ordinaire là où un `alias` casserait `try_files`, et
  ce qui fait arriver `SCRIPT_NAME=/metre/index.php` à PHP — la valeur dont Laravel déduit sa
  base d'URL. C'est tout ce dont le côté serveur a besoin : `url()`, `route()`, `asset()` et les
  redirections portent le préfixe d'eux-mêmes.

### Recharger nginx — et ce qui ne marche pas

`sudo /usr/sbin/nginx -s reload -c <conf>` puis `sudo kill -HUP $(cat /run/nginx.pid)` ont tous
deux été **sans effet** : les workers gardaient leur heure de démarrage et aucun `emerg`
n'apparaissait au journal, alors que `nginx -t` validait la configuration et que le fichier pid
désignait bien le maître. **Un redémarrage de la machine, lui, a fonctionné.** La cause du refus
n'est pas établie ; le reboot est le recours qui marche.

Un reboot est ici peu risqué, et c'est un fait à connaître : le FileMaker Server de `fms99`
n'héberge que le fichier `Sample`. **ShakeDesign et l'ancien ShakeMetre sont tous les deux sur
`fms23`.** Redémarrer cette machine n'interrompt donc ni ShakeDesign, ni la source de l'import.

**À vérifier après chaque redémarrage ou mise à jour de FMS :**

    grep -n "nginx-metre" "/opt/FileMaker/FileMaker Server/NginxServer/conf/fms_nginx.conf"

`NginxServer/.conf/fms_nginx.conf` est un **gabarit** (`[FM_CERT_PEM_NAME]`, `[FM_HTTP_INCLUDE]`) :
FMS compose sa configuration à partir de là et peut donc la réécrire. Elle l'a été une fois
(14 h 37) sans intervention humaine. La ligne a survécu au premier reboot, mais si elle disparaît
un jour, il faut la reposer — et le remède durable serait un petit service systemd qui la remet
et recharge nginx après le démarrage de FMS.

## Déployer

    ~/ShakeMetreWeb/deploy/deploy.sh

Le cycle : commit et push, puis ce script sur le serveur. Aucun `sudo`, et nginx n'a **pas** à
être rechargé pour un déploiement ordinaire — seulement si `deploy/nginx-metre.conf` change.

Il encode trois pièges : le PATH de brew ; le fait que `artisan optimize` **gèle** les valeurs du
`.env`, donc qu'une variable ajoutée ensuite ne serait jamais lue ; et le worker de queue qui
garde le code en mémoire tant qu'on ne le redémarre pas. La remise en ligne est sous `trap … EXIT`,
donc une migration refusée ou un build cassé ne laisse pas le site éteint.

## Le `.env` de production

    APP_ENV=production
    APP_DEBUG=false                       # sinon une trace expose les identifiants FileMaker
    APP_URL=https://fms99.mycloud.fm/metre
    SESSION_PATH=/metre                   # le cookie ne part pas chez l'application voisine

`APP_KEY` se génère une fois et ne se regénère jamais : il chiffre les sessions.

## Les tests

**Sur le Mac.** Sur le serveur, `deploy.sh` retire les paquets de développement (`--no-dev`), donc
il faut `composer install` d'abord, puis restaurer `--no-dev`. Les tests tournent sur du SQLite en
mémoire : la base de production n'est pas touchée (vérifié après une exécution complète).

`APP_URL` est **fixé dans `phpunit.xml`** : le client de test préfixe toute URI relative avec
`config('app.url')`, et l'`APP_URL` de production ferait demander `/metre/login` à un routeur qui
ne connaît que `/login`. 415 tests sur 616 échouaient ainsi sur le serveur, aucun ailleurs.

## L'import des données

La base de production ne contient que les comptes ; les 877 métrés sont sur le Mac. Deux
variables manquent au `.env` du serveur, sans quoi l'import ne peut pas se connecter :

    printf 'SHAKEMETRE_FM_HOST=https://fms23.mycloud.fm\nSHAKEMETRE_FM_DATABASE=ShakeMetre\n' >> .env
    php artisan config:cache                     # sinon la valeur ajoutée n'est jamais lue
    php artisan shakemetre:import --dry-run      # puis sans --dry-run, puis --audit

Le serveur joint bien `fms23` (`productInfo` → 200, vérifié).

## Vérifier que tout répond

    curl -s -o /dev/null -w "%{http_code}\n" https://fms99.mycloud.fm/metre/up          # 200
    curl -s -L -o /dev/null -w "%{url_effective}\n" https://fms99.mycloud.fm/metre/     # …/metre/login

Puis, connecté : ouvrir un métré, modifier une quantité, **recharger**. C'est le seul test qui
prouve que les appels `/api` arrivent sous le préfixe, parce que leur échec est silencieux.

## Côté ShakeDesign

- Le lien SSO doit viser `https://fms99.mycloud.fm/metre/sso/consume/{token}`.
- Les jetons Sanctum vivent en base : les émettre **sur le serveur** (`shakedesign:issue-token`).
