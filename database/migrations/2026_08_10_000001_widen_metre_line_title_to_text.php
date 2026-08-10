<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le titre d'une ligne de métré n'a pas de longueur maximale à la source, et 401 lignes le prouvent.
 *
 * `METL::REFSL_Title` est un champ Texte FileMaker, donc sans borne. Ici, `refsl_title` était un
 * `varchar(255)` : sur les 57 816 lignes du fichier vivant, **401 dépassent 255 caractères** (681
 * au plus long — une référence de mobilier avec ses dimensions et ses variantes), et MySQL en mode
 * strict a REFUSÉ ces lignes une à une. C'est tout le trou de l'import : 401 lignes manquantes,
 * 401 titres trop longs, la correspondance est exacte. Rien d'autre n'était en cause — les 54
 * métrés en écart ont été relus en entier et aucune autre colonne `varchar` n'y dépasse 255.
 *
 * Pourquoi `text` et pas un `varchar` plus large : c'est déjà ce que reçoivent `description`,
 * `comment_client` et `comment_supplier`, qui sont du texte libre comme celui-ci (`comment_supplier`
 * monte à 1 128 caractères dans les données réelles et n'a jamais posé de problème, précisément
 * parce qu'il est en `text`). Choisir 512 ou 1 024 ne ferait que repousser la même panne, et une
 * panne qui se manifeste par des lignes silencieusement absentes est la pire espèce.
 *
 * Tronquer n'a jamais été une option : un titre coupé à 255 se lit comme un titre, alors qu'il
 * décrit autre chose que ce que le client a commandé. Même raisonnement que `withinRange()` pour
 * les nombres — plutôt rien qu'un chiffre inventé.
 *
 * `ref_title` et `refs_title` restent en `varchar(255)` : ce sont des titres de section, mesurés à
 * 94 caractères au plus sur les données réelles, et aucune ligne n'a été refusée à cause d'eux.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metre_lines', function (Blueprint $table): void {
            $table->text('refsl_title')->nullable()->change();
        });
    }

    /**
     * Le retour en arrière REFUSERA de tourner s'il reste un titre de plus de 255 caractères, et
     * c'est voulu : MySQL en mode strict rejette plutôt que de tronquer. Videz ou raccourcissez ces
     * titres sciemment avant, plutôt que de les perdre au passage d'une migration.
     */
    public function down(): void
    {
        Schema::table('metre_lines', function (Blueprint $table): void {
            $table->string('refsl_title')->nullable()->change();
        });
    }
};
