<?php

namespace App\Http\Controllers;

use App\Documents\MetreDocument;
use App\Documents\MetreDocumentBuilder;
use App\Documents\PrintLabels;
use App\Models\Metre;
use App\Services\ShakeDesign\ShakeDesignApiException;
use App\Services\ShakeDesign\ShakeDesignClient;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les sept documents d'un métré - le cadre « Documents » de MET_Form.
 *
 * Le PDF est rendu à la demande et n'est jamais écrit : la source ne stocke rien non plus, elle
 * ouvre une fenêtre en mode Prévisualisation sur une mise en page d'impression et laisse la
 * personne enregistrer si elle veut. Un document est donc toujours l'état actuel du métré, ce qui
 * est la propriété qui compte quand il porte des montants.
 *
 * `?download=1` change le `Content-Disposition` et rien d'autre : l'aperçu et le fichier
 * téléchargé sont le même octet pour octet, ce qui évite le grand classique de l'aperçu qui ne
 * ressemble pas au fichier.
 *
 * Lecture seule de bout en bout - un compte `readonly` imprime. Le verrou du métré ne l'empêche
 * pas non plus : verrouiller interdit d'écrire, pas de regarder.
 */
class MetreDocumentController extends Controller
{
    public function show(Request $request, Metre $metre, string $document, ShakeDesignClient $client): Response
    {
        $spec = MetreDocument::tryFrom($document);

        if ($spec === null) {
            abort(404);
        }

        $this->allowRoomForDompdf();

        $built = (new MetreDocumentBuilder($metre, $spec))->build();
        $filename = $this->filename($metre, $spec);

        $pdf = Pdf::loadView('documents.metre', [
            'metre' => $metre,
            'document' => $spec,
            'labels' => new PrintLabels($metre->language),
            'rows' => $built['rows'],
            'projectLabel' => $this->projectLabel($metre, $client),
            'tailComment' => $spec->audience() === 'supplier'
                ? $metre->comment_supplier
                : $metre->comment_client,
            /*
             * MET::LOT_CPYName_Chosen_g dans la source : une variable globale posée ailleurs, que
             * ce bouton ne renseigne pas. Le document n'est d'ailleurs filtré sur aucun lot, donc
             * nommer une entreprise ici serait affirmer quelque chose de faux. Laissé vide tant
             * qu'un document par fournisseur n'est pas demandé.
             */
            'supplierName' => null,
            'logo' => null,
            'filename' => $filename,
            'printedOn' => now()->format('d/m/Y'),
        ]);

        // A4 portrait, comme le « Print Setup » de METL_GoTo_Print pour ces sept mises en page -
        // seules les quatre autres, absentes de cet écran, passent en paysage.
        $pdf->setPaper('a4');

        $this->stampPageNumbers($pdf);

        // `stream()` pose toujours `inline`, `download()` toujours `attachment` : le drapeau
        // choisit la méthode, il ne se passe pas en option (l'option `Attachment` appartient à
        // l'API brute de dompdf, pas à ce wrapper - une heure perdue à croire l'inverse).
        return $request->boolean('download')
            ? $pdf->download($filename)
            : $pdf->stream($filename);
    }

    /**
     * « 2 / 7 » en bas de chaque page.
     *
     * Écrit sur le canevas après le rendu, parce que la pagination n'existe qu'à ce moment-là.
     * L'autre voie que documente dompdf est un `<script type="text/php">` dans le gabarit, qui
     * suppose d'activer l'exécution de PHP à l'intérieur des vues rendues : un interrupteur que
     * ce projet n'a aucune raison d'ouvrir pour poser un numéro de page.
     */
    private function stampPageNumbers(\Barryvdh\DomPDF\PDF $pdf): void
    {
        $pdf->render();

        $canvas = $pdf->getDomPDF()->getCanvas();

        $canvas->page_text(
            x: $canvas->get_width() - 90,
            y: $canvas->get_height() - 28,
            text: '{PAGE_NUM} / {PAGE_COUNT}',
            font: $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans'),
            size: 7,
            color: [0.54, 0.48, 0.45],
        );
    }

    /**
     * dompdf tient tout le document en mémoire, et il en tient beaucoup.
     *
     * Mesuré sur le métré de démonstration - 357 lignes, 12 pages : **128 Mo au pic**, soit
     * exactement la limite par défaut de PHP. Un métré deux fois plus gros échouerait donc en
     * production avec une page blanche, pour une raison invisible depuis l'écran.
     *
     * La limite est relevée pour cette requête seulement, et seulement si elle est plus basse -
     * une configuration de serveur qui a déjà tranché n'est pas écrasée. C'est un aveu du coût de
     * dompdf, pas une optimisation : la vraie réponse serait de paginer le rendu, ce qui ne vaut
     * la peine que si ces documents deviennent lents.
     */
    private function allowRoomForDompdf(): void
    {
        $current = trim((string) ini_get('memory_limit'));

        if ($current === '-1') {
            return;
        }

        $bytes = match (strtoupper(substr($current, -1))) {
            'G' => (int) $current * 1024 ** 3,
            'M' => (int) $current * 1024 ** 2,
            'K' => (int) $current * 1024,
            default => (int) $current,
        };

        if ($bytes < 512 * 1024 ** 2) {
            ini_set('memory_limit', '512M');
        }
    }

    /**
     * Le nom du fichier : le projet n'y figure pas - il vit dans ShakeDesign et une panne de
     * celui-ci ne doit pas changer le nom d'un téléchargement.
     */
    private function filename(Metre $metre, MetreDocument $document): string
    {
        return Str::slug("metre-{$metre->ind_project}-{$metre->name}-{$document->value}").'.pdf';
    }

    /**
     * `PRJ::NameNumberFormatted_c`, qui est `Number_cro & " - " & Name` dans ShakeDesign.
     *
     * Dégradé plutôt que fatal, comme la carte des offres : un document est imprimable même
     * quand l'autre application ne répond pas - il perd sa ligne de projet, pas ses montants.
     */
    private function projectLabel(Metre $metre, ShakeDesignClient $client): ?string
    {
        if ($metre->project_id === null) {
            return null;
        }

        try {
            $project = $client->findProject($metre->project_id);
        } catch (ShakeDesignApiException) {
            return null;
        }

        $parts = array_filter([$project['Number'] ?? null, $project['Name'] ?? null]);

        return $parts === [] ? null : implode(' - ', $parts);
    }
}
