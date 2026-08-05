<?php

namespace App\Documents;

/**
 * Les sept documents qu'un métré sait produire - le cadre « Documents » de MET_Form.
 *
 * Côté FileMaker, chacun est un bouton qui appelle `METL_GoTo_Print` avec un nom de mise en page,
 * `NewWindow = 1` et `MET = zkp`. Le script ne fabrique aucun PDF : il cherche les lignes du
 * métré, va sur la mise en page d'impression, trie, et bascule la fenêtre en mode Prévisualisation
 * - l'aperçu EST le mode prévisualisation, et le PDF sort de « Enregistrer au format PDF » de la
 * barre d'outils. Rien n'est stocké, rien n'est daté : le document est recalculé à chaque clic.
 * Le web fait pareil, en rendant le PDF à la demande sans jamais l'écrire sur disque.
 *
 * Les sept mises en page sont une seule famille. Elles partagent l'en-tête (logo, projet, indice,
 * nom du métré), les niveaux de regroupement, le bloc de commentaires final et le pied de page ;
 * elles ne diffèrent que par ce que porte le corps. D'où un gabarit unique piloté par ce que cet
 * enum déclare, plutôt que sept vues qui divergeront à la première correction.
 *
 * Ce qui a été lu, et où : les boutons et leur paramètre sur `MET_Form` (id 56), le corps de
 * `METL_GoTo_Print` (id 208), et la structure des sept mises en page - parts, champs, conditions
 * de masquage - dans `~/dev/filemaker/ShakeMetre.xml`.
 */
enum MetreDocument: string
{
    case ClientBudget = 'budget-client-complet';
    case ClientBudgetComposition = 'budget-client-complet-composition';
    case ClientBudgetSimplified = 'budget-client-simplifie';
    case ClientBudgetSimplifiedComposition = 'budget-client-simplifie-composition';
    case ClientBudgetSummary = 'budget-client-sous-categories';
    case ClientBudgetSummaryBasic = 'budget-client-categories';
    case SupplierBudgetOrdered = 'fournisseur-budget-achats';

    /** L'intitulé du bouton, celui de MET_Form. */
    public function label(): string
    {
        return match ($this) {
            self::ClientBudget => 'Budget client — complet',
            self::ClientBudgetComposition => 'Budget client — complet avec composition',
            self::ClientBudgetSimplified => 'Budget client — simplifié',
            self::ClientBudgetSimplifiedComposition => 'Budget client — simplifié avec composition',
            self::ClientBudgetSummary => 'Budget client — sous-catégories',
            self::ClientBudgetSummaryBasic => 'Budget client — catégories',
            self::SupplierBudgetOrdered => 'Fournisseur — budget achats',
        };
    }

    /** La mise en page FileMaker transposée, pour que le lien avec la source reste traçable. */
    public function sourceLayout(): string
    {
        return match ($this) {
            self::ClientBudget => 'METL_ClientBudget_Print',
            self::ClientBudgetComposition => 'METL_ClientBudgetComposition_Print',
            self::ClientBudgetSimplified => 'METL_ClientBudgetSimplified_Print',
            self::ClientBudgetSimplifiedComposition => 'METL_ClientBudgetSimplifiedComposition_Print',
            self::ClientBudgetSummary => 'METL_ClientBudgetSummary_Print',
            self::ClientBudgetSummaryBasic => 'METL_ClientBudgetSummaryBasic_Print',
            self::SupplierBudgetOrdered => 'METL_SupplierBudgetOrdered_Print',
        };
    }

    /**
     * Quelle colonne d'argent le document raconte.
     *
     * Un budget client montre le prix de vente ; le budget fournisseur montre le commandé
     * (`QuantityOrdered` / `PriceOrdered` / `PriceTotalOrderedAll_c`), qui a sa propre quantité -
     * c'est la seule des trois à en avoir une.
     */
    public function money(): string
    {
        return $this === self::SupplierBudgetOrdered ? 'ordered' : 'sales';
    }

    /**
     * Les lignes en option sont-elles imprimées ?
     *
     * `METL_GoTo_Print` pose `$Option = 1` par défaut et le remet à 0 pour les quatre mises en
     * page fournisseur/avancement, où il resserre alors le jeu trouvé sur `isOption_b = 0`. Un
     * budget client montre donc ses options - dans leur propre bloc, en fin de document, sans
     * total - et le budget fournisseur ne les voit pas du tout.
     */
    public function includesOptions(): bool
    {
        return $this !== self::SupplierBudgetOrdered;
    }

    /**
     * Le document descend-il jusqu'à la ligne, ou s'arrête-t-il aux totaux de groupe ?
     *
     * Les deux « récapitulatifs » n'ont littéralement pas de part Body : seules les sous-totalisations
     * s'impriment.
     */
    public function showsLines(): bool
    {
        return ! in_array($this, [self::ClientBudgetSummary, self::ClientBudgetSummaryBasic], true);
    }

    /** La ligne porte-t-elle ses quantités et ses prix, ou seulement son intitulé ? */
    public function showsLineAmounts(): bool
    {
        return match ($this) {
            self::ClientBudget, self::ClientBudgetComposition, self::SupplierBudgetOrdered => true,
            default => false,
        };
    }

    /** La composition (METC) de chaque ligne est-elle détaillée sous elle ? */
    public function showsComposition(): bool
    {
        return in_array($this, [
            self::ClientBudgetComposition,
            self::ClientBudgetSimplifiedComposition,
            self::SupplierBudgetOrdered,
        ], true);
    }

    /**
     * Le niveau de regroupement le plus fin.
     *
     * « catégories » s'arrête à REF : sa mise en page n'a pas de sous-totalisation par REFS_Title,
     * ce qui est toute la différence avec « sous-catégories ».
     */
    public function deepestGroup(): string
    {
        return $this === self::ClientBudgetSummaryBasic ? 'ref' : 'refs';
    }

    /**
     * Les deux niveaux de tag, présents sur les budgets clients et absents du budget fournisseur.
     *
     * Ils ne produisent un groupe que si `MET::Sort_OrderTags` vaut 1 ou 2 : `TAG_Choice1_cU` est
     * `Case ( Sort_OrderTags = 1 ; TAG1 ; = 2 ; TAG2 ; "" )` et `TAG_Choice2_cU` l'inverse. Sans
     * valeur, les deux calculs rendent la chaîne vide et les niveaux s'effacent - c'est ce que
     * fait FileMaker aujourd'hui, et ce que la version web fait donc aussi.
     */
    public function groupsByTag(): bool
    {
        return $this !== self::SupplierBudgetOrdered;
    }

    /** Le commentaire de fin, et celui porté par chaque ligne : au client, ou au fournisseur. */
    public function audience(): string
    {
        return $this === self::SupplierBudgetOrdered ? 'supplier' : 'client';
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
