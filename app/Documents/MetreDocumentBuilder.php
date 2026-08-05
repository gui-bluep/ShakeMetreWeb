<?php

namespace App\Documents;

use App\Models\Metre;
use App\Models\MetreLine;
use Illuminate\Support\Collection;

/**
 * Transpose un état imprimé de FileMaker en une suite de lignes à rendre.
 *
 * Une mise en page d'impression FileMaker n'est pas un arbre : c'est une bande de parts qui
 * s'impriment dans l'ordre des enregistrements triés, une sous-totalisation s'imprimant chaque
 * fois que sa clé de tri change. Le résultat produit ici est donc **plat** - en-tête de groupe,
 * ligne, composant, total - et non imbriqué : c'est le modèle de rendu de la source, il se rend
 * en Blade sans récursion, et il rend impossible le décalage entre l'ordre de tri et l'ordre des
 * titres, qui est le bug classique de ce genre d'état.
 *
 * Le tri vient de `METL_Sort`, qui a quatre variantes selon `$Option` et `MET::Sort_OrderTags` -
 * lues dans le script, pas devinées. Les sous-totaux sont les `zsm_SumTotal*_All` de la source :
 * la somme, sur le groupe, du total de ligne options comprises. Le total final est le
 * `zsm_SumTotal*_noOptions` de la sous-totalisation de queue.
 */
class MetreDocumentBuilder
{
    public function __construct(
        private readonly Metre $metre,
        private readonly MetreDocument $document,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, total: float, lineCount: int}
     */
    public function build(): array
    {
        $lines = $this->lines();

        return [
            'rows' => $this->rows($lines),
            // Le total imprimé exclut les options, dans les sept documents : le budget client
            // les montre sans les compter, le budget fournisseur ne les charge même pas.
            'total' => $this->sum($lines->reject->is_option_b),
            'lineCount' => $lines->count(),
        ];
    }

    /**
     * Le jeu trouvé de `METL_GoTo_Print` : les lignes du métré, moins les options quand le
     * document les exclut, dans l'ordre de la variante de `METL_Sort` qui lui correspond.
     */
    private function lines(): Collection
    {
        /*
         * Le montant de chaque ligne est calculé par la base, pas par l'accesseur du modèle : voir
         * MetreLine::SQL_SALES_ALL. Un document et le métré qui le porte doivent afficher le même
         * total au centime près, et l'arrondi flottant de PHP ne le garantit pas.
         */
        $amount = $this->document->money() === 'sales'
            ? MetreLine::SQL_SALES_ALL
            : MetreLine::SQL_ORDERED_ALL;

        $lines = $this->metre->metreLines()
            ->select('metre_lines.*')
            ->selectRaw("{$amount} as document_amount")
            ->when($this->document->showsComposition(), fn ($q) => $q->with('metreLineComponents'))
            ->get();

        if (! $this->document->includesOptions()) {
            $lines = $lines->reject->is_option_b;
        }

        return $this->sort($lines);
    }

    /**
     * `METL_Sort`, dont les quatre variantes se combinent en une seule liste de clés :
     *
     *   $Option                    → isOption_b en tête
     *   Sort_OrderTags ∈ {1, 2}    → TAG_Choice1_cU, TAG_Choice2_cU juste après
     *   puis, toujours             → REF_Code, REFS_Code, REFS_Title, Order, REFSL_Code_c
     *
     * Le budget fournisseur prend la variante sans `isOption_b` (il n'a plus d'option à trier)
     * mais **garde** les niveaux de tag si le métré en déclare un, alors que sa mise en page n'a
     * pas de sous-totalisation par tag. La source fait exactement cela ; l'état s'en trouve
     * découpé en sections répétées, ce qui est une bizarrerie de la source et non une erreur de
     * transposition. Reproduite telle quelle.
     */
    private function sort(Collection $lines): Collection
    {
        $keys = [];

        if ($this->document->includesOptions()) {
            $keys[] = fn (MetreLine $l) => $l->is_option_b ? 1 : 0;
        }

        if ($this->tagOrder() !== null) {
            $keys[] = fn (MetreLine $l) => $this->tagChoice($l, 1) ?? '';
            $keys[] = fn (MetreLine $l) => $this->tagChoice($l, 2) ?? '';
        }

        // Sans traitement particulier des vides : un nombre vide se trie avant 0 en FileMaker,
        // donc une ligne sans section passe en tête - la même règle que les vues de lignes.
        $keys[] = fn (MetreLine $l) => $l->ref_code ?? -PHP_INT_MAX;
        $keys[] = fn (MetreLine $l) => $l->refs_code ?? -PHP_INT_MAX;
        $keys[] = fn (MetreLine $l) => $l->refs_title ?? '';
        $keys[] = fn (MetreLine $l) => $l->ref_order ?? 0;
        $keys[] = fn (MetreLine $l) => $l->sort_order ?? 0;

        return $lines->sortBy(
            fn (MetreLine $l) => array_map(fn ($k) => $k($l), $keys),
        )->values();
    }

    /**
     * `MET::Sort_OrderTags`, s'il désigne un ordre de tags. 1 = TAG1 puis TAG2, 2 = l'inverse ;
     * toute autre valeur, vide comprise, et les deux niveaux disparaissent - `TAG_Choice1_cU`
     * rend alors la chaîne vide, ce qui ne produit aucun groupe.
     */
    private function tagOrder(): ?int
    {
        $order = (int) $this->metre->sort_order_tags;

        return in_array($order, [1, 2], true) ? $order : null;
    }

    /** `TAG_Choice1_cU` / `TAG_Choice2_cU` : le tag que l'ordre choisi place à ce niveau. */
    private function tagChoice(MetreLine $line, int $level): ?string
    {
        $order = $this->tagOrder();

        if ($order === null) {
            return null;
        }

        $first = $order === 1 ? $line->tag1 : $line->tag2;
        $second = $order === 1 ? $line->tag2 : $line->tag1;

        return $level === 1 ? $first : $second;
    }

    /**
     * La bande imprimée, dans l'ordre.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $lines): array
    {
        $rows = [];
        $previous = null;

        foreach ($lines as $line) {
            foreach ($this->breaks($line, $previous) as $break) {
                $rows[] = $break === 'option'
                    ? $this->optionBreak($line, $lines)
                    : $this->groupHeading($break, $line, $lines);
            }

            if ($this->document->showsLines()) {
                $rows[] = $this->lineRow($line);

                if ($this->document->showsComposition()) {
                    foreach ($line->metreLineComponents->sortBy('sort_order') as $component) {
                        $rows[] = [
                            'kind' => 'composition',
                            'description' => $component->description,
                            'value' => $component->value_sales,
                        ];
                    }
                }
            }

            $previous = $line;
        }

        return $this->withTrailingTotal($rows, $lines);
    }

    /**
     * Les niveaux dont la clé change entre deux lignes - donc les sous-totalisations qui
     * s'impriment. Un changement à un niveau force tous les niveaux plus fins, exactement comme
     * FileMaker : une nouvelle section réimprime sa première sous-section.
     *
     * @return list<string>
     */
    private function breaks(MetreLine $line, ?MetreLine $previous): array
    {
        $levels = [];

        if ($this->document->includesOptions()) {
            $levels['option'] = fn (MetreLine $l) => (bool) $l->is_option_b;
        }

        if ($this->document->groupsByTag() && $this->tagOrder() !== null) {
            $levels['tag1'] = fn (MetreLine $l) => $this->tagChoice($l, 1);
            $levels['tag2'] = fn (MetreLine $l) => $this->tagChoice($l, 2);
        }

        $levels['ref'] = fn (MetreLine $l) => [$l->ref_code, $l->ref_title];

        if ($this->document->deepestGroup() === 'refs') {
            $levels['refs'] = fn (MetreLine $l) => $l->refs_title;
        }

        $broken = [];
        $force = false;

        foreach ($levels as $name => $key) {
            if ($force || $previous === null || $key($line) !== $key($previous)) {
                $broken[] = $name;
                $force = true;
            }
        }

        return $broken;
    }

    /**
     * Le début d'un bloc « option ».
     *
     * Le titre « Options » ne s'imprime que pour le groupe des options (`Hide when: not
     * isOption_b` sur la source), tandis que la ligne d'en-têtes de colonnes, elle, se réimprime
     * pour les deux groupes - c'est pourquoi les deux vivent dans la même part.
     *
     * @return array<string, mixed>
     */
    private function optionBreak(MetreLine $line, Collection $lines): array
    {
        return [
            'kind' => 'option-break',
            'isOption' => (bool) $line->is_option_b,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function groupHeading(string $level, MetreLine $line, Collection $lines): array
    {
        [$code, $title] = match ($level) {
            'tag1' => [null, $this->tagChoice($line, 1)],
            'tag2' => [null, $this->tagChoice($line, 2)],
            'ref' => [$line->ref_code, $line->ref_title],
            'refs' => [$line->refs_code, $line->refs_title],
        };

        return [
            'kind' => 'group',
            'level' => $level,
            'code' => $code,
            'title' => $title,
            // zsm_SumTotal*_All : le total du groupe, options comprises. Dans le bloc « options »
            // c'est donc bien le montant des options, ce que la source affiche aussi.
            'total' => $this->sum($this->membersOf($level, $line, $lines)),
        ];
    }

    /** Les lignes qui partagent avec `$line` la clé de `$level` et celles de tous ses parents. */
    private function membersOf(string $level, MetreLine $line, Collection $lines): Collection
    {
        return $lines->filter(function (MetreLine $other) use ($level, $line) {
            if ($this->document->includesOptions()
                && (bool) $other->is_option_b !== (bool) $line->is_option_b) {
                return false;
            }

            if ($this->document->groupsByTag() && $this->tagOrder() !== null) {
                if ($this->tagChoice($other, 1) !== $this->tagChoice($line, 1)) {
                    return false;
                }

                if ($level !== 'tag1' && $this->tagChoice($other, 2) !== $this->tagChoice($line, 2)) {
                    return false;
                }
            }

            if (in_array($level, ['ref', 'refs'], true)
                && [$other->ref_code, $other->ref_title] !== [$line->ref_code, $line->ref_title]) {
                return false;
            }

            if ($level === 'refs' && $other->refs_title !== $line->refs_title) {
                return false;
            }

            return true;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function lineRow(MetreLine $line): array
    {
        $sales = $this->document->money() === 'sales';

        return [
            'kind' => 'line',
            'code' => $line->refLineCode(),
            'title' => $line->refsl_title,
            'comment' => $this->document->audience() === 'supplier'
                ? $line->comment_supplier
                : $line->comment_client,
            'unit' => $line->unit,
            'quantity' => $sales ? $line->quantity : $line->quantity_ordered,
            'price' => $sales ? $line->price_sales : $line->price_ordered,
            'total' => (float) $line->document_amount,
            'isOption' => (bool) $line->is_option_b,
        ];
    }

    /**
     * La sous-totalisation de queue : « Total HTVA » et la somme hors options.
     *
     * Elle est posée à la fin du bloc des non-options, pas à la fin du document - les deux
     * objets qui la composent portent `Hide when: isOption_b`, donc le bloc des options n'a
     * aucun total. Sur un document sans option, les deux positions se confondent.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withTrailingTotal(array $rows, Collection $lines): array
    {
        $total = ['kind' => 'total', 'total' => $this->sum($lines->reject->is_option_b)];

        if ($lines->isEmpty()) {
            return $rows;
        }

        // L'index du premier « option-break » qui ouvre le bloc des options, s'il existe.
        foreach ($rows as $i => $row) {
            if (($row['kind'] ?? null) === 'option-break' && $row['isOption']) {
                return array_merge(array_slice($rows, 0, $i), [$total], array_slice($rows, $i));
            }
        }

        return [...$rows, $total];
    }

    private function sum(Collection $lines): float
    {
        return round($lines->sum(fn (MetreLine $l) => (float) $l->document_amount), 2);
    }
}
