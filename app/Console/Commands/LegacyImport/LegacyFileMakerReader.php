<?php

namespace App\Console\Commands\LegacyImport;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * TEMPORAIRE — À SUPPRIMER AVEC L'IMPORT.
 *
 * Lecture seule de l'ancien ShakeMetre FileMaker, par le Data API, pour la migration des
 * données et rien d'autre. Voir le bloc `shakemetre_filemaker` de config/services.php : ce
 * n'est pas une dépendance de l'application, aucune requête HTTP ne doit pouvoir arriver
 * ici, et le dossier entier disparaît quand la migration est finie.
 *
 * Ce que ce lecteur sait, et pourquoi :
 *
 *  - **`_offset` commence à 1, pas à 0.** Un `_offset=0` fait répondre 960 « Parameter is
 *    invalid », ce qui se lit comme une mise en page absente. Payé une fois.
 *  - **Un jeton de session meurt au bout de 15 minutes d'inactivité**, et l'import dure plus
 *    longtemps que ça. Le code 952 (« Invalid FileMaker Data API token ») déclenche une
 *    réauthentification et un rejeu, une seule fois par appel.
 *  - **Le code 401 n'est pas une erreur** : c'est « aucun enregistrement trouvé », ce que
 *    répond un `_find` sur un métré sans ligne. Il vaut une liste vide.
 *  - **Un `_find` sur un champ résumé ne coûte que son found set.** C'est la raison d'être de
 *    findAll() : `API_MIGRATION_METL` porte `zsm_zkf_VAT_List`, un résumé « liste de » qui
 *    rend la liste de TOUTES les clés TVA du found set sur CHAQUE enregistrement — 2,1 Mo par
 *    ligne quand on lit la table entière, soit 124 Go pour 57 816 lignes. Lues métré par
 *    métré, le found set tombe à 470 lignes au pire et le champ à 17 ko : ~370 Mo en tout.
 *    Retirer ce champ de la mise en page ramènerait le total à ~50 Mo, mais l'import n'en a
 *    pas besoin pour tenir.
 */
class LegacyFileMakerReader
{
    private ?string $token = null;

    private int $requests = 0;

    private int $bytes = 0;

    /** @var array<string, int> effectifs par mise en page, voir totalRecords() */
    private array $counts = [];

    /** @var array{host: string, database: string, username: string, password: string, version: string, timeout: int, connect_timeout: int, verify: bool} */
    private array $config;

    public function __construct(?array $config = null)
    {
        /** @var array $resolved */
        $resolved = $config ?? config('services.shakemetre_filemaker');

        foreach (['host', 'database', 'username', 'password'] as $key) {
            if (blank($resolved[$key] ?? null)) {
                throw new RuntimeException(
                    'Configuration FileMaker incomplète : SHAKEMETRE_FM_'.strtoupper($key).' est absent du .env. '.
                    'Ces variables sont volontairement hors de .env.example — voir config/services.php.'
                );
            }
        }

        $this->config = $resolved;
    }

    /**
     * L'hôte est normalisé comme dans ShakeDesignClient : la variable d'environnement peut
     * porter un schéma ou pas, et concaténer sans regarder donne « https://https://… ».
     */
    private function baseUrl(): string
    {
        $host = rtrim((string) $this->config['host'], '/');

        if (! str_starts_with($host, 'http://') && ! str_starts_with($host, 'https://')) {
            $host = 'https://'.$host;
        }

        return sprintf(
            '%s/fmi/data/%s/databases/%s',
            $host,
            $this->config['version'] ?? 'vLatest',
            rawurlencode((string) $this->config['database']),
        );
    }

    private function request(): PendingRequest
    {
        return Http::timeout((int) ($this->config['timeout'] ?? 30))
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 5))
            ->withOptions(['verify' => $this->config['verify'] ?? true])
            ->acceptJson()
            ->asJson();
    }

    public function login(): void
    {
        $response = $this->request()
            ->withBasicAuth((string) $this->config['username'], (string) $this->config['password'])
            ->post($this->baseUrl().'/sessions', new \stdClass);

        $this->requests++;
        $this->bytes += strlen($response->body());

        $code = (string) ($response->json('messages.0.code') ?? '-1');

        if ($code !== '0') {
            /*
             * Le 802 n'est pas un refus d'authentification, et le confondre coûte une demi-heure :
             * il dit que le FICHIER ne s'ouvre pas — fermé sur le serveur, en sauvegarde, ou hors
             * ligne — et il arrive avec des identifiants parfaitement valides. Le distinguer ici
             * évite d'aller chercher un privilège manquant qui n'a rien à voir. Un contrôle facile :
             * si ShakeDesign répond 802 lui aussi, c'est le serveur, pas ce fichier.
             */
            throw new RuntimeException($code === '802'
                ? sprintf(
                    'Le fichier FileMaker « %s » ne s\'ouvre pas (code 802). Ce n\'est PAS un problème '.
                    'd\'identifiants : le fichier est fermé sur le serveur, en sauvegarde, ou hors ligne. '.
                    'Rouvrez-le dans l\'Admin Console et relancez.',
                    (string) $this->config['database'],
                )
                : sprintf(
                    'Authentification FileMaker refusée (code %s : %s). Le compte a-t-il le privilège étendu fmrest ? '.
                    'Un compte en accès complet SANS ce privilège est refusé avec une erreur 9, qui se lit comme un mauvais mot de passe.',
                    $code,
                    (string) ($response->json('messages.0.message') ?? $response->status()),
                ));
        }

        $this->token = (string) $response->json('response.token');
    }

    public function logout(): void
    {
        if ($this->token === null) {
            return;
        }

        // Rendre la session plutôt que la laisser expirer : le serveur a un nombre fini de
        // sessions Data API concurrentes, et un import interrompu plusieurs fois de suite les
        // consomme toutes.
        $this->request()
            ->withToken($this->token)
            ->delete($this->baseUrl().'/sessions/'.$this->token);

        $this->token = null;
    }

    /**
     * Le nombre total d'enregistrements d'une mise en page, sans en rapatrier le contenu.
     *
     * `_limit=1` parce qu'il n'y a pas d'endpoint de comptage : le compte se lit dans
     * `dataInfo.totalRecordCount`, que le serveur renvoie avec n'importe quelle page.
     *
     * Mémoïsé, et pas par avarice : « un seul enregistrement » d'API_MIGRATION_METL pèse 2,1 Mo
     * à cause du champ résumé, et le compte est demandé deux fois — au contrôle des mises en
     * page puis à la phase elle-même. Le cache économise le transfert et garantit surtout que
     * les deux chiffres rapportés soient le même : le fichier est vivant, deux comptages
     * successifs peuvent différer, et un avancement dont le total bouge en route se lit comme
     * un bug.
     */
    public function totalRecords(string $layout): int
    {
        if (isset($this->counts[$layout])) {
            return $this->counts[$layout];
        }

        $payload = $this->call('GET', "/layouts/{$layout}/records", ['_offset' => 1, '_limit' => 1]);

        return $this->counts[$layout] = (int) ($payload['response']['dataInfo']['totalRecordCount'] ?? 0);
    }

    /**
     * La mise en page telle que le serveur l'expose — ce que la table contient et ce que la
     * mise en page montre sont deux choses différentes, et la différence a déjà causé de vrais
     * bugs. L'import s'en sert pour refuser de tourner s'il manque un champ.
     *
     * @return list<string>
     */
    public function fieldNames(string $layout): array
    {
        $payload = $this->call('GET', "/layouts/{$layout}");

        return array_values(array_map(
            fn (array $field): string => (string) $field['name'],
            $payload['response']['fieldMetaData'] ?? [],
        ));
    }

    /**
     * Toute une mise en page, page par page, en générateur : 57 816 lignes ne tiennent pas en
     * mémoire d'un coup et n'ont pas à y tenir.
     *
     * @return \Generator<int, list<array<string, mixed>>>
     */
    public function pages(string $layout, int $pageSize = 500): \Generator
    {
        $offset = 1;

        while (true) {
            $payload = $this->call('GET', "/layouts/{$layout}/records", [
                '_offset' => $offset,
                '_limit' => $pageSize,
            ]);

            $rows = $payload['response']['data'] ?? [];

            if ($rows === []) {
                return;
            }

            yield array_map(fn (array $row): array => $row['fieldData'], $rows);

            if (count($rows) < $pageSize) {
                return;
            }

            $offset += $pageSize;
        }
    }

    /**
     * Combien d'enregistrements répondent à une requête, sans en rapatrier le contenu.
     *
     * `limit=1` : il n'y a pas d'endpoint de comptage, le nombre se lit dans
     * `dataInfo.foundCount`, que le serveur rend avec n'importe quelle page. Un enregistrement
     * traverse quand même le réseau — sur `API_MIGRATION_METL` le found set est réduit à un
     * métré, donc le champ résumé y pèse ~17 ko et non 2,1 Mo.
     *
     * Un found set vide passe par le 401 de call(), qui rend une réponse sans `dataInfo` : 0,
     * ce qui est la bonne lecture pour un métré sans ligne.
     *
     * @param  array<string, string>  $query
     */
    public function foundCount(string $layout, array $query): int
    {
        $payload = $this->call('POST', "/layouts/{$layout}/_find", [
            'query' => [$query],
            'offset' => '1',
            'limit' => '1',
        ]);

        return (int) ($payload['response']['dataInfo']['foundCount'] ?? 0);
    }

    /**
     * Les enregistrements répondant à une requête, page par page.
     *
     * Sert à lire les lignes d'un métré : la requête restreint le found set, ce qui restreint
     * aussi les champs résumés (voir l'en-tête de classe).
     *
     * **Paginé, et il a fallu une vraie exécution complète pour le savoir.** Un premier essai
     * lisait chaque métré d'un seul appel, au motif que le found set est borné — 470 lignes au
     * plus gros. C'est vrai et ça ne suffit pas : à 17 ko de champ résumé par ligne, ce métré
     * répond 11,6 Mo, et Laravel garde le corps brut ET le tableau décodé vivants en même temps
     * (`Response::body()` mémoïse, `json()` décode par-dessus). Le pic dépassait les 128 Mo par
     * défaut de PHP et l'import mourait à 13 % de la phase, sur `fwrite` du flux Guzzle.
     *
     * Une page de 100 ramène le pic à ~1,8 Mo de corps. Le champ résumé, lui, ne rétrécit pas
     * avec la page — il se calcule sur le found set, qui reste le métré entier — c'est bien le
     * NOMBRE d'enregistrements par réponse qui borne le pic.
     *
     * @param  array<string, string>  $query
     * @return \Generator<int, list<array<string, mixed>>>
     */
    public function findPages(string $layout, array $query, int $pageSize = 100): \Generator
    {
        $offset = 1;

        while (true) {
            $payload = $this->call('POST', "/layouts/{$layout}/_find", [
                'query' => [$query],
                // Chaînes de caractères : le Data API refuse un offset/limit numérique.
                'offset' => (string) $offset,
                'limit' => (string) $pageSize,
            ]);

            $rows = $payload['response']['data'] ?? [];

            if ($rows === []) {
                return;
            }

            yield array_map(fn (array $row): array => $row['fieldData'], $rows);

            if (count($rows) < $pageSize) {
                return;
            }

            $offset += $pageSize;
        }
    }

    /**
     * Un appel, avec réauthentification et rejeu sur jeton périmé.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $payload = [], bool $retried = false): array
    {
        if ($this->token === null) {
            $this->login();
        }

        $url = $this->baseUrl().$path;

        $response = $method === 'GET'
            ? $this->request()->withToken($this->token)->get($url, $payload)
            : $this->request()->withToken($this->token)->post($url, $payload);

        $this->requests++;
        $this->bytes += strlen($response->body());

        $body = $response->json() ?? [];
        $code = (string) ($body['messages'][0]['code'] ?? '-1');

        // 401 = « aucun enregistrement ne correspond ». Un métré sans ligne, pas une panne.
        if ($code === '401') {
            return ['response' => ['data' => []]];
        }

        // 952 = jeton invalide ou expiré. Une seule reprise : boucler sur un refus permanent
        // ferait tourner l'import indéfiniment au lieu d'échouer.
        if ($code === '952' && ! $retried) {
            $this->token = null;

            return $this->call($method, $path, $payload, retried: true);
        }

        if ($code !== '0') {
            throw new RuntimeException(sprintf(
                'FileMaker a refusé %s %s — code %s : %s',
                $method,
                $path,
                $code,
                (string) ($body['messages'][0]['message'] ?? $response->status()),
            ));
        }

        return $body;
    }

    public function requestCount(): int
    {
        return $this->requests;
    }

    public function bytesRead(): int
    {
        return $this->bytes;
    }
}
