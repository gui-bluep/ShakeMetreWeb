<?php

namespace App\Services\ShakeDesign;

/**
 * Où le client FileMaker de la personne doit se connecter pour ouvrir un enregistrement
 * ShakeDesign - l'hôte et le fichier d'une URL `fmp://`.
 *
 * Ce n'est pas la même chose que la cible du Data API, même quand c'est la même machine :
 *
 *  - Celle du Data API est résolue par le serveur web, celle-ci par le poste de la personne qui
 *    clique. D'où `SHAKEDESIGN_FMP_HOST`, qui prend le pas quand les deux diffèrent.
 *  - Surtout, `SHAKEDESIGN_HOST` porte couramment un schéma - la valeur en production est
 *    `https://fms23.mycloud.fm` - parce que ShakeDesignClient l'accepte avec ou sans. Une URL
 *    `fmp://` ne veut que le nom d'hôte : `fmp://https://fms23.mycloud.fm/...` ne mène nulle
 *    part. C'est la raison d'être de cette classe, et elle a été trouvée en regardant la config
 *    réelle, pas en la supposant.
 *
 * Rien n'est deviné : sans configuration la cible est vide, et la page affiche alors l'intitulé
 * d'une commande sans lien plutôt qu'un lien vers une machine tirée au sort.
 */
final class FileMakerClientTarget
{
    /**
     * L'hôte, débarrassé de son schéma et de tout ce qui suivrait un « / ».
     *
     * Un port survit (`fms.example.test:5003`) : il fait partie de l'adresse, et le supprimer
     * enverrait le client sur le mauvais service.
     */
    public static function host(): ?string
    {
        $host = trim((string) config('services.shakedesign.fmp_host'));

        // Le schéma, quel qu'il soit : la valeur est saisie à la main dans un .env.
        $host = (string) preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $host);

        // Un chemin, une barre finale : le Data API en tolère, une URL fmp:// n'en veut pas.
        $host = explode('/', $host, 2)[0];

        return $host === '' ? null : $host;
    }

    /** Le nom du fichier hébergé, sans son extension - « ShakeDesign ». */
    public static function database(): ?string
    {
        $database = trim((string) config('services.shakedesign.fmp_database'));

        return $database === '' ? null : $database;
    }

    /**
     * Ce qu'une page envoie à Inertia : une propriété de l'installation, jamais de la ligne.
     *
     * @return array{host: ?string, database: ?string}
     */
    public static function toArray(): array
    {
        return [
            'host' => self::host(),
            'database' => self::database(),
        ];
    }
}
