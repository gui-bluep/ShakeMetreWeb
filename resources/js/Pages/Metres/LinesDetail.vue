<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppTopBar from '@/Components/AppTopBar.vue';
import GridToasts from '@/Components/GridToasts.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ReferenceCatalogueModal from '@/Components/ReferenceCatalogueModal.vue';
import Icon from '@/Components/Icon.vue';
import { useDebouncedRowSave } from '@/composables/useDebouncedRowSave';
import { fold, highlightParts, matches } from '@/searchMatch';

/**
 * Les quatre vues monétaires des lignes d'un métré : « Achats — Ventes — Commandes » et ses trois
 * coupes plus étroites (Achats — Ventes, Achats — Commandes, Ventes).
 *
 * Une seule page pour les quatre, parce qu'elles ne diffèrent que par les blocs à l'écran : une
 * ligne, ce qu'elle contient et ce qu'on peut y écrire sont les mêmes partout. `blocks` arrive du
 * serveur (MetreLineDetailController::VIEWS) et commande le gabarit de colonnes, les deux niveaux
 * d'en-tête et les cellules ; la page ne connaît que l'apparence d'un bloc — sa teinte, ses
 * libellés, ses champs — dans BLOCKS ci-dessous.
 *
 * Cell edits go through PATCH /api/metre-lines/{id}, the same endpoint and whitelist as the
 * other grid, so no view can disagree with another about what is writable.
 *
 * The Achats and Vendu client blocks share one quantity field, and that is the data model, not
 * a shortcut: PriceTotalBuy and PriceTotalSales both multiply by METL::Quantity in the source -
 * only the order total has its own (QuantityOrdered). Both inputs bind to `quantity`, so editing
 * either visibly moves the other rather than hiding the sharing. Cela ne se dit à l'écran que
 * dans les vues qui montrent les deux : ailleurs, il n'y a pas de partage visible à expliquer.
 *
 * L'écran prend toute la hauteur et ne passe donc pas par AuthenticatedLayout ; il monte la
 * barre supérieure lui-même, pour que la vue la plus utilisée de l'application ne soit pas la
 * seule à ne pas porter la marque.
 */
const props = defineProps({
    /** Le slug de la vue, tel qu'il est dans l'URL. */
    view: { type: String, required: true },
    /** Les blocs monétaires à afficher, dans l'ordre : `['achats', 'ventes', 'commandes']`. */
    blocks: { type: Array, required: true },
    metre: { type: Object, required: true },
    lines: { type: Array, required: true },
    units: { type: Array, required: true },
    lots: { type: Array, required: true },
});

const page = usePage();
const readOnly = computed(() => props.metre.is_locked_b || page.props.auth?.canWrite === false);
const readOnlyReason = computed(() =>
    props.metre.is_locked_b ? 'Métré verrouillé' : 'Compte en lecture seule'
);

function clone(line) {
    return { ...line, computed: { ...line.computed } };
}

const rows = ref(props.lines.map(clone));

/**
 * Remet les lignes dans l'ordre du serveur — METL_Sort, que la source rejoue après chaque
 * création (METL_New et METL_New_Multi terminent par un appel à ce script).
 *
 * Sans cela, une ligne ajoutée reste en fin de liste et le regroupement, qui ne rassemble que des
 * lignes consécutives, lui ouvre un deuxième intitulé de section sous le premier.
 *
 * Croissant, vides d'abord, comme le ORDER BY : `ref_code, refs_code, refs_title, ref_order`,
 * puis `sort_order` pour départager.
 */
/** Croissant, vides d'abord — partagé par le tri des lignes et par les listes de sous-sections. */
const byNumber = (a, b) => {
    if (a === b) return 0;
    if (a === null || a === undefined) return -1;
    if (b === null || b === undefined) return 1;

    return Number(a) - Number(b);
};

function sortRows() {
    rows.value.sort((a, b) =>
        byNumber(a.ref_code, b.ref_code)
        || byNumber(a.refs_code, b.refs_code)
        || (a.refs_title ?? '').localeCompare(b.refs_title ?? '', 'fr')
        || byNumber(a.ref_order, b.ref_order)
        || byNumber(a.sort_order, b.sort_order)
    );
}

// Re-seeded if the page is pointed at another métré - Inertia can reuse this component, and a
// copy seeded once at setup would show the previous métré's lines while writing to the new one.
// La vue compte aussi : passer d'une coupe à l'autre est une navigation, qui ramène des lignes
// fraîches du serveur ; garder la copie locale de la visite précédente afficherait un état plus
// vieux que les props qui viennent d'arriver.
watch(
    () => [props.metre.id, props.view],
    () => {
        rows.value = props.lines.map(clone);
        selected.value = new Set();
    }
);

// --- grouping switch, search, selection ---------------------------------------------------

/**
 * Lots / Tags. Lots is the default and is fully wired: the column shows the assigned lot and
 * offers this project's lots. Tags shows the line's stored tag1 read-only - METL carries tag1
 * and tag2 columns AND there is a separate TAG table keyed to the métré, and nothing in the
 * export says which of the two this switch is meant to drive, so it is not guessed at here.
 */
const grouping = ref('lots');

const search = ref('');
const selected = ref(new Set());

/**
 * La clé d'un groupe, construite ici et nulle part ailleurs : le repliage et l'ensemble trouvé la
 * retrouvent à partir d'une ligne, et deux façons de la fabriquer finiraient par ne plus se
 * répondre. Celle d'une sous-section porte celle de sa section, parce qu'un même couple code/titre
 * peut se retrouver sous deux sections et qu'il faut une clé unique dans toute la vue.
 */
const sectionKeyOf = (row) => `${row.ref_code ?? ''}`;
const subSectionKeyOf = (row) => `${sectionKeyOf(row)}::${row.refs_code ?? ''}|${row.refs_title ?? ''}`;

// --- ensemble trouvé ------------------------------------------------------------------------

/**
 * « Réduire » et « étendre » l'affichage, c'est-à-dire l'ensemble trouvé de FileMaker. Quatre
 * scripts, quatre opérations d'ensembles, et c'est tout ce qu'elles font :
 *
 * - METL_View_Constrain_REF   : Rechercher REF_Code = "=" & $REF          puis Réduire  → ∩
 * - METL_View_Constrain_REFS  : Rechercher REF_Code + REFS_Code           puis Réduire  → ∩
 * - METL_View_Extend_REF      : Rechercher zkf_MET + REF_Code             puis Étendre  → ∪
 * - METL_View_Extend_All      : Rechercher zkf_MET seul                   puis Étendre  → tout
 *
 * D'où l'asymétrie, qui n'est pas un oubli : réduire n'ajoute jamais rien (une intersection ne
 * peut que retirer), étendre n'enlève jamais rien (une union ne peut qu'ajouter). Réduire à une
 * section qui n'affichait que deux de ses quatre sous-sections en laisse deux ; étendre une
 * section alors que tout est affiché ne réduit pas à elle.
 *
 * Retenu comme un ensemble de clés de sous-section, et non de lignes : c'est le seul niveau sur
 * lequel les quatre scripts savent chercher, et une ligne créée ensuite dans une sous-section
 * affichée est alors affichée elle aussi, sans qu'il faille tenir une liste d'identifiants à jour.
 *
 * `null` veut dire « tout le métré » — l'état de départ, et celui où METL_View_Extend_All ramène.
 * Un ensemble qui finit par couvrir toutes les clés y est ramené, sinon l'écran continuerait
 * d'annoncer un affichage réduit qui ne réduit plus rien.
 *
 * La recherche reste un filtre séparé et vivant : elle se vide et les lignes reviennent. Les
 * mélanger figerait son résultat dans l'ensemble trouvé, et l'effacer ne le défiltrerait pas.
 */
const foundSet = ref(null);

const isConstrained = computed(() => foundSet.value !== null);

const allSubSectionKeys = computed(() => new Set(rows.value.map(subSectionKeyOf)));

/** Les clés de sous-section d'une section dans TOUT le métré, affichées ou non : étendre a besoin
 *  d'ajouter ce que l'affichage écarte, donc rien ici ne peut passer par `groupedRows`. */
const subSectionKeysOfSection = (sectionKey) =>
    rows.value.filter((row) => sectionKeyOf(row) === sectionKey).map(subSectionKeyOf);

/** Ramène à `null` (tout le métré) un ensemble qui couvre déjà tout. */
function normalise(keys) {
    return keys.size >= allSubSectionKeys.value.size ? null : keys;
}

function constrain(keys) {
    const keep = new Set(keys);

    foundSet.value = normalise(
        foundSet.value === null
            ? keep
            : new Set([...foundSet.value].filter((key) => keep.has(key)))
    );
}

function extend(keys) {
    // Étendre à partir de « tout » ne peut rien ajouter.
    if (foundSet.value === null) {
        return;
    }

    const next = new Set(foundSet.value);
    keys.forEach((key) => next.add(key));
    foundSet.value = normalise(next);
}

function constrainToSection(section) {
    constrain(subSectionKeysOfSection(section.key));
    popover.value = null;
}

function constrainToSubSection(sub) {
    constrain([sub.key]);
    popover.value = null;
}

function extendToSection(section) {
    extend(subSectionKeysOfSection(section.key));
    popover.value = null;
}

/** METL_View_Extend_All : l'ensemble trouvé redevient le métré entier. */
function showAllLines() {
    foundSet.value = null;
    popover.value = null;
}

/** Replié une fois pour toute la liste : sans accents ni casse (voir `searchMatch.js`). */
const searchTerm = computed(() => fold(search.value.trim()));

/**
 * La recherche porte aussi sur la section et la sous-section, et pas seulement sur la ligne : un
 * titre de section cherché ramène toutes les lignes de cette section, pas uniquement celles qui
 * portent le mot dans leur propre libellé.
 *
 * Ce que cela demande, c'est deux champs de plus dans la liste — et rien d'autre. Une ligne porte
 * la copie du titre de sa section et de sa sous-section (`ref_title`, `refs_title`) ; il n'y a donc
 * ni jointure ni deuxième passe à écrire, et c'est aussi ce que ferait une recherche FileMaker,
 * où ces deux champs sont sur METL comme les autres.
 *
 * Le désordre que cela pourrait créer n'est pas dans le résultat — il reste groupé par section,
 * donc une section entière ramenée se lit comme une section entière — mais dans le fait de ne plus
 * savoir POURQUOI une ligne est là. D'où le marquage des intitulés : un intitulé surligné dit que
 * c'est lui qui a ramené tout son groupe, un intitulé non surligné dit que seules ses lignes
 * trouvées sont là. Une ligne trouvée par son propre libellé n'a rien à expliquer, elle porte le
 * mot à l'écran.
 */
const SEARCHED_FIELDS = [
    'refsl_title', 'description', 'unit', 'lot_name', 'sor_title_ref',
    // Le groupe, copié sur la ligne : c'est ce qui fait qu'une section se cherche par son nom.
    'ref_title', 'refs_title',
];

const visibleRows = computed(() => {
    const term = searchTerm.value;
    const found = foundSet.value;

    if (term === '' && found === null) {
        return rows.value;
    }

    return rows.value.filter(
        (row) =>
            (found === null || found.has(subSectionKeyOf(row)))
            && (term === '' || SEARCHED_FIELDS.some((field) => matches(row[field], term)))
    );
});

/**
 * L'état d'une ligne, dit par la couleur de son texte — le format conditionnel de la liste source :
 * rouge quand le prix est estimé, bleu et penché quand la ligne est en option.
 *
 * Une seule couleur est rendue à la fois, jamais les deux : `text-danger-700` et `text-info-700`
 * sont deux utilitaires de même spécificité, donc leur ordre dans l'attribut `class` ne déciderait
 * rien — seul l'ordre du CSS compilé trancherait, ce qui est un tirage au sort. L'estimation gagne :
 * elle dit qu'un chiffre n'est pas fiable, ce qui pèse plus lourd que le statut d'option. Et une
 * ligne en option reste penchée dans les deux cas, l'inclinaison n'entrant en conflit avec rien.
 */
const toneColour = (row) => {
    if (row.is_estimated_price_b) {
        return 'line-tone-danger';
    }

    return row.is_option_b ? 'line-tone-info' : '';
};

const toneItalic = (row) => (row.is_option_b ? 'italic' : '');

/** L'encre d'une cellule qui pose la sienne : celle de l'état s'il y en a un, la sienne sinon. */
const toneInk = (row, fallback) => {
    if (row.is_estimated_price_b) {
        return 'text-danger-700';
    }

    return row.is_option_b ? 'text-info-700' : fallback;
};

/** Les morceaux d'un libellé, marqués ou non, pour le terme courant. */
const parts = (text) => highlightParts(text, searchTerm.value);

/** Y a-t-il quelque chose à marquer ici ? Ce qui décide si un champ éditable se fait doubler. */
const hasHit = (text) => searchTerm.value !== '' && matches(text, searchTerm.value);

/** Les libellés d'intitulé, au même endroit que leur repli de secours. */
const sectionLabel = (section) =>
    section.title || (section.code === null ? 'Sans section' : 'Section sans titre');
const subSectionLabel = (sub) => sub.title || 'Sous-section sans titre';

/**
 * La liste telle que FileMaker la met en page (METL_MetreComplete_List_Full) : deux niveaux
 * d'intitulés au-dessus des lignes, chacun avec ses sous-totaux, et un total général en pied.
 *
 * Ce sont, dans le fichier source, deux « sub-summary » — par REF_Code puis par REFS_Title — et
 * une « trailing grand summary ». Ils n'existent que pour les groupes réellement présents dans le
 * métré : une section sans ligne n'a pas d'intitulé, parce qu'un sub-summary ne s'imprime que
 * lorsqu'un enregistrement le déclenche.
 *
 * Les sous-totaux additionnent les montants HORS options (zsm_SumTotal*_noOptions), alors que la
 * colonne Total d'une ligne montre son montant même si elle est en option. C'est voulu dans la
 * source : une option affiche ce qu'elle coûterait sans peser sur le total.
 *
 * Calculés ici et non sur le serveur : les cellules se recalculent déjà à la frappe, donc un
 * sous-total qui attendrait la réponse serait le seul chiffre en retard de l'écran.
 */
const groupedRows = computed(() => {
    const zero = () => ({ buy: 0, sales: 0, ordered: 0 });
    const add = (into, row) => {
        into.buy += row.computed.price_total_buy_no_options ?? 0;
        into.sales += row.computed.price_total_sales_no_options ?? 0;
        into.ordered += row.computed.price_total_ordered_no_options ?? 0;
    };

    const sections = [];
    let section = null;
    let subSection = null;

    for (const row of visibleRows.value) {
        const sectionKey = sectionKeyOf(row);
        const subKey = subSectionKeyOf(row);

        if (section === null || section.key !== sectionKey) {
            section = {
                key: sectionKey, code: row.ref_code, title: row.ref_title,
                totals: zero(), count: 0, subSections: [],
            };
            sections.push(section);
            subSection = null;
        }

        if (subSection === null || subSection.key !== subKey) {
            subSection = { key: subKey, code: row.refs_code, title: row.refs_title, totals: zero(), rows: [] };
            section.subSections.push(subSection);
        }

        subSection.rows.push(row);
        section.count += 1;
        add(subSection.totals, row);
        add(section.totals, row);
    }

    return sections;
});

// --- repliage -----------------------------------------------------------------------------

/**
 * Les groupes repliés, par clé. Un ensemble de replis plutôt qu'un drapeau « ouvert » par groupe :
 * tout est déroulé, donc l'ensemble vide est l'état de départ, et un groupe qui apparaît — une
 * recherche qu'on efface, une section inventée sur le champ — arrive déroulé sans qu'il y ait
 * quoi que ce soit à initialiser.
 *
 * Le repliage ne touche ni les sous-totaux ni le total général : ils sont calculés sur
 * `groupedRows`, qui ignore les replis. Replier n'est pas filtrer — c'est bien ce qu'on veut,
 * sinon replier une section pour lire l'écran ferait bouger les montants.
 *
 * La sélection suit la même règle : elle porte sur ce que la recherche laisse passer, pas sur ce
 * qui est déplié. Une ligne sélectionnée puis masquée par un repli reste sélectionnée, et le
 * compteur du bouton la compte toujours.
 */
const collapsed = ref(new Set());

const isCollapsed = (key) => collapsed.value.has(key);

function toggleCollapsed(key) {
    const next = new Set(collapsed.value);
    next.has(key) ? next.delete(key) : next.add(key);
    collapsed.value = next;
}

/** Ce qu'il faut dessiner : rien sous un groupe replié. */
const subSectionsOf = (section) => (isCollapsed(section.key) ? [] : section.subSections);
const rowsOf = (subSection) => (isCollapsed(subSection.key) ? [] : subSection.rows);

const allCollapsed = computed(
    () => groupedRows.value.length > 0 && groupedRows.value.every((section) => isCollapsed(section.key))
);

/** Tout replier ne replie que les sections : leurs sous-sections réapparaissent déroulées. */
function toggleCollapseAll() {
    collapsed.value = allCollapsed.value
        ? new Set()
        : new Set(groupedRows.value.map((section) => section.key));
}

/**
 * Fait le nécessaire pour qu'une ligne qu'on vient de créer soit visible : déplier son groupe, et
 * la faire entrer dans l'ensemble trouvé. Sans cela, créer une ligne depuis un groupe replié — ou
 * pendant un affichage réduit, dans une sous-section inventée à l'instant — n'aurait l'air de rien
 * faire du tout.
 */
function reveal(row) {
    if (collapsed.value.size > 0) {
        const next = new Set(collapsed.value);
        next.delete(sectionKeyOf(row));
        next.delete(subSectionKeyOf(row));
        collapsed.value = next;
    }

    if (foundSet.value !== null) {
        extend([subSectionKeyOf(row)]);
    }
}

/** Le total général du pied — la « trailing grand summary » du même écran. */
const grandTotals = computed(() =>
    groupedRows.value.reduce(
        (into, section) => ({
            buy: into.buy + section.totals.buy,
            sales: into.sales + section.totals.sales,
            ordered: into.ordered + section.totals.ordered,
        }),
        { buy: 0, sales: 0, ordered: 0 }
    )
);

/** La clé de bloc → la clé de sous-total, pour aligner un montant de groupe sous sa colonne. */
const SUBTOTAL_BY_BLOCK = { achats: 'buy', ventes: 'sales', commandes: 'ordered' };

const allVisibleSelected = computed(() =>
    visibleRows.value.length > 0 && visibleRows.value.every((row) => selected.value.has(row.id))
);

function toggleSelectAll() {
    const next = new Set(selected.value);

    if (allVisibleSelected.value) {
        visibleRows.value.forEach((row) => next.delete(row.id));
    } else {
        visibleRows.value.forEach((row) => next.add(row.id));
    }

    selected.value = next;
}

function toggleSelected(row) {
    const next = new Set(selected.value);
    next.has(row.id) ? next.delete(row.id) : next.add(row.id);
    selected.value = next;
}

// --- popovers -----------------------------------------------------------------------------

/**
 * `{ id, kind }` for the one open popover, or null. One at a time, closed on outside click.
 *
 * `id` is a line id for a row's menu and a group key for a heading's, which is why the pair is
 * keyed on an opaque id rather than on a row: a section has no line to be identified by.
 */
const popover = ref(null);

function togglePopoverAt(id, kind) {
    popover.value = popover.value?.id === id && popover.value?.kind === kind ? null : { id, kind };
}

function isOpenAt(id, kind) {
    return popover.value?.id === id && popover.value?.kind === kind;
}

function togglePopover(row, kind) {
    togglePopoverAt(row.id, kind);
}

function isOpen(row, kind) {
    return isOpenAt(row.id, kind);
}

// --- toasts -------------------------------------------------------------------------------

const toasts = ref([]);
let toastId = 0;

function notify(rowId, message) {
    const index = rows.value.findIndex((row) => row.id === rowId);
    const id = ++toastId;
    toasts.value.push({ id, rowLabel: index >= 0 ? index + 1 : '?', message });
    setTimeout(() => dismiss(id), 6000);
}

function dismiss(id) {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

// --- editing ------------------------------------------------------------------------------

const { queue, flush } = useDebouncedRowSave({
    endpoint: '/api/metre-lines',
    delay: 500,
    onError: ({ rowId, rollback, message }) => {
        const row = rows.value.find((candidate) => candidate.id === rowId);

        if (row) {
            Object.assign(row, rollback);
            recomputeLocally(row);
        }

        notify(rowId, message);
    },
});

/**
 * Mirrors the three PriceTotal*_noOptions_c accessors for instant feedback. The server's answer
 * replaces it, so a drift here corrects itself on save rather than sticking.
 */
function recomputeLocally(row) {
    const round2 = (v) => Math.round(v * 100) / 100;
    const option = row.is_option_b;

    row.computed = {
        // Ce que ce miroir ne recalcule pas doit survivre à la frappe. `MetreLineGridResource`
        // envoie neuf valeurs calculées, celui-ci en refait sept : sans ce report, `ref_line_code`
        // et `price_total_gain_no_options` disparaissaient dès la première touche, et la colonne
        // Code affichait « — » jusqu'au rechargement de la page — le serveur les renvoie bien, mais
        // `flushRow()` ne réassigne rien quand la sauvegarde différée est déjà partie d'elle-même.
        // Même piège que celui décrit dans CLAUDE.md pour la réponse du PATCH, côté client cette
        // fois.
        ...row.computed,

        // Mirrors MetreLine::priceRatio(): null when either price is absent, and null rather
        // than an error when the purchase price is zero.
        price_ratio:
            row.price_sales === null || row.price_sales === undefined
            || row.price_buy === null || row.price_buy === undefined || Number(row.price_buy) === 0
                ? null
                : round2(Number(row.price_sales) / Number(row.price_buy)),
        price_total_buy_no_options: option ? 0 : round2((row.price_buy ?? 0) * (row.quantity ?? 0)),
        price_total_sales_no_options: option ? 0 : round2((row.price_sales ?? 0) * (row.quantity ?? 0)),
        price_total_ordered_no_options: option
            ? 0
            : round2((row.price_ordered ?? 0) * (row.quantity_ordered ?? 0)),

        // PriceTotal*All_c : sans le garde-fou « option ». C'est ce que la colonne Total d'une
        // ligne affiche, les sous-totaux étant les seuls à écarter les options.
        price_total_buy_all: round2((row.price_buy ?? 0) * (row.quantity ?? 0)),
        price_total_sales_all: round2((row.price_sales ?? 0) * (row.quantity ?? 0)),
        price_total_ordered_all: round2((row.price_ordered ?? 0) * (row.quantity_ordered ?? 0)),
    };
}

function edit(row, field, value) {
    if (readOnly.value) {
        return;
    }

    const previous = row[field];

    if (value === previous) {
        return;
    }

    row[field] = value;
    recomputeLocally(row);
    queue(row.id, field, value, previous);
}

function editNumber(row, field, raw) {
    edit(row, field, raw === '' || raw === null ? null : Number(raw));
}

function editText(row, field, raw) {
    edit(row, field, raw === '' ? null : raw);
}

async function flushRow(row) {
    const stored = await flush(row.id);

    if (stored) {
        Object.assign(row, stored, { computed: { ...stored.computed } });
    }
}

/** Toggles that should land immediately rather than after a typing pause. */
async function toggleField(row, field, value) {
    edit(row, field, value);
    await flushRow(row);
}

async function assignLot(row, lot) {
    popover.value = null;

    if (readOnly.value || row.lot_id === (lot?.id ?? null)) {
        return;
    }

    const previousId = row.lot_id;
    row.lot_id = lot?.id ?? null;
    row.lot_name = lot?.name ?? null;
    row.lot_code = lot?.code ?? null;

    queue(row.id, 'lot_id', row.lot_id, previousId);
    await flushRow(row);
}

// --- line actions -------------------------------------------------------------------------

const busy = ref(false);

/**
 * Une ligne, éventuellement classée d'emblée — METL_NewFromREF.
 *
 * Sans section, c'est l'ancien bouton « Ligne ». Avec, deux cas, exactement les deux branches du
 * script source : une ligne de plus dans un groupe existant (on répète ses quatre valeurs), ou une
 * sous-section définie sur le champ, code et titre saisis, qui n'existe dans aucun catalogue.
 */
async function addLine(section = null) {
    if (readOnly.value || busy.value) {
        return;
    }

    busy.value = true;

    try {
        const body = await request(`/api/metres/${props.metre.id}/lines`, 'POST', section ?? {});
        rows.value.push(clone(body.data));
        sortRows();
        reveal(body.data);

        return body.data;
    } catch (e) {
        notify(null, e.message);

        return null;
    } finally {
        busy.value = false;
    }
}

/**
 * « Nouvelle ligne de métré » depuis l'intitulé d'une section : METL_NewFromREF demande d'abord où
 * la poser, et ses deux branches sont les deux moitiés de ce volet — une sous-section que la
 * section possède déjà, ou une définie sur le champ (code et titre), qui n'existe dans aucun
 * catalogue. Ce sont les deux champs globaux zg_REF_SelectedNewCode / zg_REF_SelectedNewTitle,
 * déplacés de l'intitulé lui-même dans le volet qui pose la question.
 */
const newSubSection = ref(null);

/** Ouvre le volet « où ? » du menu d'une section, sa saisie de nouvelle sous-section vierge. */
function openNewLinePane(section) {
    newSubSection.value = { sectionKey: section.key, code: null, title: '' };
    togglePopoverAt(section.key, 'section-new-line');
}

/**
 * Les sous-sections que la section possède dans le métré, y compris celles que l'affichage réduit
 * ou la recherche écartent : on choisit où poser une ligne, pas parmi ce qui est à l'écran. Une
 * sous-section absente de la liste serait impossible à retrouver autrement qu'en rétablissant
 * l'affichage d'abord.
 */
function sectionSubSections(sectionKey) {
    const seen = new Map();

    for (const row of rows.value) {
        if (sectionKeyOf(row) !== sectionKey) {
            continue;
        }

        const key = subSectionKeyOf(row);

        if (! seen.has(key)) {
            seen.set(key, { key, code: row.refs_code, title: row.refs_title, lines: 0 });
        }

        seen.get(key).lines += 1;
    }

    return [...seen.values()].sort(
        (a, b) => byNumber(a.code, b.code) || (a.title ?? '').localeCompare(b.title ?? '', 'fr')
    );
}

/** Une ligne vierge dans une sous-section qui existe déjà : la première branche du script. */
async function addLineInSubSection(section, sub) {
    popover.value = null;
    newSubSection.value = null;

    await addLine({
        ref_code: section.code,
        ref_title: section.title,
        refs_code: sub.code,
        refs_title: sub.title,
    });
}

/** La seconde branche : la sous-section est inventée ici, et la ligne vierge y naît avec elle. */
async function createSubSectionWithLine(section) {
    const draft = newSubSection.value;

    if (draft === null || draft.code === null || draft.code === '' || draft.title.trim() === '') {
        notify(null, 'Une nouvelle sous-section a besoin d\'un code et d\'un titre.');

        return;
    }

    const created = await addLine({
        ref_code: section.code,
        ref_title: section.title,
        refs_code: Number(draft.code),
        refs_title: draft.title.trim(),
    });

    if (created) {
        newSubSection.value = null;
        popover.value = null;
    }
}

/**
 * « Depuis le catalogue » — METL_New_Multi : une ligne par article coché, avec son libellé, son
 * unité et son prix d'achat. Le serveur recopie la section sur chaque ligne et lui attribue son
 * rang ; les lignes reviennent dans la réponse et sont ajoutées telles quelles, sans recharger.
 */
const showCatalogue = ref(false);

/**
 * Le lot d'une sélection, en un geste - METL_Lot_AssignToSelection.
 *
 * Le script source n'écrit pas depuis le bouton : il ouvre une carte (METL_ListAssignLot_Card) qui
 * liste les lignes trouvées par leurs identifiants, triées par METL_Sort, et attend Enregistrer ou
 * Annuler. D'où une fenêtre qui montre ce qui va changer plutôt qu'un menu qui l'a déjà changé : le
 * geste porte sur des centaines de lignes d'argent, et la seule façon de vérifier une sélection est
 * de la voir.
 *
 * Et comme le script, la sélection est vidée après un succès : elle a été consommée, la garder
 * ferait croire qu'il reste quelque chose à faire.
 */
const assigningLot = ref(false);
const lotToAssign = ref(null);

/** Les lignes cochées, dans l'ordre de l'écran - le METL_Sort de la carte source. */
const selectedRows = computed(() => rows.value.filter((row) => selected.value.has(row.id)));

function openLotAssignment() {
    // Le lot du premier coché, s'ils le partagent déjà : la fenêtre s'ouvre sur l'état, pas sur un
    // choix vide qui ressemblerait à « aucun lot ».
    const first = selectedRows.value[0]?.lot_id ?? null;
    const shared = selectedRows.value.every((row) => (row.lot_id ?? null) === first);

    lotToAssign.value = shared ? first : null;
    assigningLot.value = true;
}

async function assignLotToSelection() {
    if (readOnly.value || busy.value || selected.value.size === 0) {
        return;
    }

    busy.value = true;

    try {
        const body = await request(`/api/metres/${props.metre.id}/lines/assign-lot`, 'POST', {
            line_ids: selectedRows.value.map((row) => row.id),
            lot_id: lotToAssign.value,
        });

        // Les lignes reviennent à jour : on remplace, on ne devine pas.
        for (const updated of body.data ?? []) {
            const row = rows.value.find((candidate) => candidate.id === updated.id);

            if (row) {
                Object.assign(row, clone(updated));
            }
        }

        assigningLot.value = false;
        selected.value = new Set();
    } catch (e) {
        notify(null, e.message);
    } finally {
        busy.value = false;
    }
}

/** L'infobulle de la cellule Lot : le lot en entier, même quand la colonne le tronque. */
const lotTitle = (row) => {
    if (! row.lot_id) {
        return 'Aucun lot';
    }

    return [row.lot_code, row.lot_name || 'Lot sans nom']
        .filter((part) => part !== null && part !== undefined && part !== '')
        .join(' — ');
};

const lotLabel = (id) => {
    const lot = props.lots.find((candidate) => candidate.id === id);

    return lot === undefined
        ? 'Aucun lot'
        : [lot.code, lot.name || 'Lot sans nom'].filter((part) => part !== null && part !== '').join(' — ');
};

async function insertFromCatalogue(ids) {
    if (readOnly.value || busy.value) {
        return;
    }

    busy.value = true;

    try {
        const body = await request(`/api/metres/${props.metre.id}/lines/from-catalogue`, 'POST', {
            sub_reference_line_ids: ids,
        });

        rows.value.push(...(body.data ?? []).map(clone));
        sortRows();
        (body.data ?? []).forEach(reveal);
        showCatalogue.value = false;
    } catch (e) {
        notify(null, e.message);
    } finally {
        busy.value = false;
    }
}

async function duplicateLine(row) {
    popover.value = null;

    if (readOnly.value || busy.value) {
        return;
    }

    busy.value = true;

    try {
        const body = await request(`/api/metre-lines/${row.id}/duplicate`, 'POST', {});
        rows.value.splice(rows.value.indexOf(row) + 1, 0, clone(body.data));
        sortRows();
    } catch (e) {
        notify(row.id, e.message);
    } finally {
        busy.value = false;
    }
}

async function deleteLine(row) {
    popover.value = null;

    if (readOnly.value || busy.value) {
        return;
    }

    busy.value = true;
    const index = rows.value.indexOf(row);
    rows.value.splice(index, 1);

    try {
        await request(`/api/metre-lines/${row.id}`, 'DELETE');
        const next = new Set(selected.value);
        next.delete(row.id);
        selected.value = next;
    } catch (e) {
        // Put it back rather than leaving the list disagreeing with the database.
        rows.value.splice(index, 0, row);
        notify(row.id, e.message);
    } finally {
        busy.value = false;
    }
}

// --- transport ----------------------------------------------------------------------------

async function request(url, method, payload = null) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: payload === null ? undefined : JSON.stringify(payload),
    });

    if (response.status === 204) {
        return {};
    }

    const body = await response.json().catch(() => null);

    if (!response.ok) {
        throw new Error(
            body?.errors ? Object.values(body.errors).flat()[0] : (body?.message ?? `Échec (HTTP ${response.status})`)
        );
    }

    return body;
}

function csrfToken() {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie
        ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length))
        : (document.querySelector('meta[name="csrf-token"]')?.content ?? '');
}

// --- display ------------------------------------------------------------------------------

const currency = new Intl.NumberFormat('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/**
 * Un montant avec son symbole. L'euro est marqué deux fois, dans l'en-tête de colonne
 * (« Total (€) ») et sur chaque montant : demandé explicitement, et cohérent avec les six champs
 * P.U. voisins, qui portent le leur en surimpression faute de pouvoir le mettre dans la valeur
 * d'un input[type=number].
 *
 * Espace insécable avant le symbole, comme le veut la typographie française et comme le fait déjà
 * `Intl` pour les milliers : sans elle, un retour à la ligne pourrait séparer le montant de son
 * unité au milieu d'une cellule étroite.
 */
function money(value) {
    return value === null || value === undefined ? '—' : `${currency.format(value)}\u00a0€`;
}

/**
 * L'apparence d'un bloc monétaire : son bandeau, ses trois colonnes, ses deux champs et son
 * total. Seule cette table connaît les teintes ; le serveur ne dit que quels blocs afficher.
 *
 * Les classes sont écrites en clair et jamais composées (`bg-clay-50/70`, pas
 * `bg-${tone}-50/70`) : Tailwind lit les fichiers sources comme du texte, une classe calculée
 * ne serait donc jamais générée. Même raison que dans Icon.vue.
 *
 * Le trio est le code couleur du domaine, identique sur tous les écrans : achats → argile,
 * vendu client → olive, commande → mauve.
 */
const BLOCKS = {
    achats: {
        key: 'achats',
        label: 'Estimation achat',
        bandClass: 'bg-clay-100 text-clay-700',
        headClass: 'bg-clay-50',
        cellClass: 'bg-clay-50/70',
        quantityField: 'quantity',
        priceField: 'price_buy',
        totalKey: 'price_total_buy_all',
        subtotalKey: 'price_total_buy_no_options',
    },
    ventes: {
        key: 'ventes',
        label: 'Vendu client',
        bandClass: 'bg-olive-100 text-olive-700',
        headClass: 'bg-olive-50',
        cellClass: 'bg-olive-50/70',
        quantityField: 'quantity',
        priceField: 'price_sales',
        totalKey: 'price_total_sales_all',
        subtotalKey: 'price_total_sales_no_options',
    },
    commandes: {
        key: 'commandes',
        label: 'Commande',
        bandClass: 'bg-mallow-100 text-mallow-700',
        headClass: 'bg-mallow-50',
        cellClass: 'bg-mallow-50/70',
        quantityField: 'quantity_ordered',
        priceField: 'price_ordered',
        totalKey: 'price_total_ordered_all',
        subtotalKey: 'price_total_ordered_no_options',
    },
};

const blocks = computed(() => props.blocks.map((key) => BLOCKS[key]));

/**
 * Le ratio est P.U. vendu client ÷ P.U. estimation achat : il n'a de sens que là où les deux
 * prix sont à l'écran. Ailleurs, une colonne qui divise un chiffre absent par un autre serait
 * un chiffre qu'on ne peut pas vérifier. Il reste calculé côté serveur pour toutes les vues,
 * simplement non affiché.
 */
const showRatio = computed(() => props.blocks.includes('achats') && props.blocks.includes('ventes'));

/** Même condition : le partage de METL::Quantity ne se dit que là où il se voit. */
const sharedQuantity = showRatio;

/**
 * Les colonnes de la grille : leur largeur minimale en rem, et leur part de l'espace en trop.
 *
 * Une seule liste, d'où sortent ET le `grid-template-columns` ET la largeur plancher du contenu.
 * C'était deux faits séparés, et ils avaient dérivé : le plancher annonçait 96,5rem là où les
 * colonnes en réclament 117. La grille débordait donc de son conteneur de 20,5rem — Livraison, Lot
 * et Commande fourn. — et un enfant de grille qui déborde se dessine EN DEHORS de la boîte de sa
 * ligne. Le fond de la ligne, son survol et sa teinte de sélection s'arrêtaient net à la limite,
 * visible dès que la fenêtre était trop étroite et qu'on défilait vers la droite. Additionner la
 * même liste supprime l'écart par construction.
 */
const COLUMNS = computed(() => [
    { min: 4.5 },                           // code de ligne (REF.REFS.rang)
    { min: 15, grow: 1.4 },                 // titre
    { min: 2.5 }, { min: 2.5 },             // est. / option
    { min: 5.5 },                           // unité
    ...blocks.value.flatMap((block) => [
        { min: 5 }, { min: 6 }, { min: 7 }, // qté / p.u. / total
        // Le ratio se glisse juste après le bloc achats, entre les deux prix qu'il divise.
        ...(showRatio.value && block.key === 'achats' ? [{ min: 4 }] : []),
    ]),
    { min: 2.5 },                           // select
    { min: 2 }, { min: 2 },                 // actions / comments
    { min: 6.5 },                           // delivered
    { min: 8, grow: 0.8 },                  // lot / tag
    { min: 8, grow: 0.8 },                  // SOR
]);

/** Column widths, so the header and the rows stay aligned across the horizontal scroll. */
const TEMPLATE = computed(() => COLUMNS.value
    .map((column) => (column.grow === undefined
        ? `${column.min}rem`
        : `minmax(${column.min}rem, ${column.grow}fr)`))
    .join(' '));

/**
 * Largeur plancher du contenu : la somme des minimums, pour que les colonnes ne se compriment pas
 * sous leur lisibilité et que la ligne couvre tout ce qui se défile. Elle suit d'elle-même les
 * blocs retirés d'une vue plus étroite, qui garderait sinon un plancher prévu pour trois fois plus
 * de colonnes et traînerait un défilement horizontal sur du vide.
 */
const minWidth = computed(
    () => `${COLUMNS.value.reduce((total, column) => total + column.min, 0)}rem`
);

/** Le titre de chaque vue, celui-là même que la page du métré affiche dans sa liste. */
const VIEW_LABELS = {
    'achats-ventes-commandes': 'Achats — Ventes — Commandes',
    'achats-ventes': 'Achats — Ventes',
    'achats-commandes': 'Achats — Commandes',
    ventes: 'Ventes',
};

const viewLabel = computed(() => VIEW_LABELS[props.view] ?? 'Lignes du métré');

/**
 * Le fil d'Ariane s'arrête au métré : le nom du projet vit dans ShakeDesign, et l'aller
 * chercher par l'API de données pour un seul libellé ferait payer un appel distant à l'écran
 * le plus chargé de l'application. La page du métré, elle, porte le chemin complet.
 */
const breadcrumbs = computed(() => [
    { label: 'Projets', href: route('dashboard') },
    { label: props.metre.name || 'Métré', href: `/metres/${props.metre.id}` },
    { label: viewLabel.value },
]);
</script>

<template>
    <Head :title="`${viewLabel} · ${metre.name ?? 'Métré'}`" />

    <div class="flex h-screen flex-col bg-sand-100" @click="popover = null">
        <AppTopBar :breadcrumbs="breadcrumbs" />

        <!-- Barre d'outils : ce qu'on fait de la grille, séparé de où l'on se trouve. -->
        <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-sand-200 bg-white px-3 py-2">
            <Link :href="`/metres/${metre.id}`" class="btn btn-ghost btn-sm">
                <Icon name="arrow-left" :size="3.5" />
                Métré
            </Link>

            <span class="h-5 w-px bg-sand-200" aria-hidden="true" />

            <!-- Tags / Lots switch -->
            <div class="flex shrink-0 rounded-md border border-sand-300 bg-white p-0.5 text-xs">
                <button
                    v-for="mode in [{ key: 'lots', label: 'Lots' }, { key: 'tags', label: 'Tags' }]"
                    :key="mode.key"
                    type="button"
                    class="rounded px-2.5 py-1 transition-colors"
                    :class="grouping === mode.key
                        ? 'bg-accent-400 text-sand-950'
                        : 'text-sand-600 hover:bg-sand-100 hover:text-sand-900'"
                    style="font-variation-settings: 'wght' 550"
                    @click="grouping = mode.key"
                >
                    {{ mode.label }}
                </button>
            </div>

            <div class="relative min-w-0 flex-1">
                <Icon
                    name="search"
                    :size="3.5"
                    class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-sand-400"
                />
                <input
                    type="search"
                    :value="search"
                    placeholder="Rechercher une ligne, une section…"
                    class="block w-full py-1.5 pl-8 pr-2.5 text-xs"
                    @input="search = $event.target.value"
                />
            </div>

            <!-- METL_View_Extend_All. Un affichage réduit doit se dire et savoir se défaire :
                 réduire deux fois de suite ne laisse plus rien pour retrouver le reste. -->
            <button
                v-if="isConstrained"
                type="button"
                class="btn btn-accent btn-sm shrink-0"
                title="METL_View_Extend_All — rétablir toutes les lignes du métré"
                @click="showAllLines"
            >
                <Icon name="layers" :size="3.5" />
                Affichage réduit — tout afficher
                <span class="badge badge-neutral">{{ visibleRows.length }} / {{ rows.length }}</span>
            </button>

            <button
                type="button"
                class="btn btn-secondary btn-sm"
                :title="allCollapsed ? 'Dérouler toutes les sections' : 'Replier toutes les sections'"
                @click="toggleCollapseAll"
            >
                <Icon :name="allCollapsed ? 'chevron-down' : 'chevron-right'" :size="3.5" />
                {{ allCollapsed ? 'Tout dérouler' : 'Tout replier' }}
            </button>

            <button type="button" class="btn btn-secondary btn-sm" @click="toggleSelectAll">
                {{ allVisibleSelected ? 'Tout désélectionner' : 'Tout sélectionner' }}
                <span v-if="selected.size" class="badge badge-accent">{{ selected.size }}</span>
            </button>

            <!-- L'action de la sélection : n'apparaît que lorsqu'il y a une sélection à traiter. -->
            <button
                v-if="!readOnly && selected.size > 0"
                type="button"
                class="btn btn-accent btn-sm shrink-0"
                :disabled="busy"
                title="METL_Lot_AssignToSelection — poser un lot sur les lignes cochées"
                @click="openLotAssignment"
            >
                <Icon name="layers" :size="3.5" />
                Assigner un lot
                <span class="badge badge-neutral">{{ selected.size }}</span>
            </button>

            <button
                v-if="!readOnly"
                type="button"
                class="btn btn-secondary btn-sm"
                :disabled="busy"
                title="Choisir des articles du référentiel : libellé, unité et prix d'achat repris"
                @click="showCatalogue = true"
            >
                <Icon name="table" :size="3.5" />
                Catalogue
            </button>

            <button
                v-if="!readOnly"
                type="button"
                class="btn btn-accent btn-sm"
                :disabled="busy"
                @click="addLine"
            >
                <Icon name="plus" :size="3.5" />
                Ligne
            </button>

            <span v-if="readOnly" class="badge badge-warning shrink-0">
                <Icon name="lock" :size="3" />
                {{ readOnlyReason }}
            </span>
        </div>

        <div class="min-h-0 flex-1 overflow-auto">
            <div :style="{ minWidth }">
                <!-- Les deux niveaux d'en-tête dans un seul conteneur collant : c'est ce qui
                     supprime le `top-[22px]` qu'il fallait sinon recalculer à chaque changement
                     de hauteur de la première ligne. -->
                <div class="sticky top-0 z-20 border-b border-sand-300 bg-white">
                    <!-- Le bandeau des blocs monétaires : un par bloc de la vue -->
                    <div
                        class="grid text-[10px] uppercase tracking-[0.06em]"
                        :style="{ gridTemplateColumns: TEMPLATE, fontVariationSettings: `'wght' 650` }"
                    >
                        <div />
                        <div class="px-1.5 py-1" />
                        <div class="col-span-3" />
                        <template v-for="block in blocks" :key="block.key">
                            <div class="col-span-3 px-1.5 py-1 text-center" :class="block.bandClass">
                                {{ block.label }}
                            </div>
                            <!-- La colonne du ratio n'appartient à aucun bloc : elle sépare les
                                 deux prix qu'elle divise. -->
                            <div v-if="showRatio && block.key === 'achats'" />
                        </template>
                        <div class="col-span-6" />
                    </div>

                    <!-- Les libellés de colonne -->
                    <div
                        class="grid bg-sand-100 text-[10px] uppercase tracking-[0.06em] text-sand-600"
                        :style="{ gridTemplateColumns: TEMPLATE, fontVariationSettings: `'wght' 600` }"
                    >
                        <!-- METL::REFSL_Code_c — section.sous-section.rang, calculé (voir
                             MetreLine::refLineCode()). -->
                        <div class="px-1.5 py-1" title="Section.Sous-section.Rang">Code</div>
                        <div class="px-1.5 py-1">Titre</div>
                        <div class="px-1 py-1 text-center" title="Prix estimé">Est.</div>
                        <div class="px-1 py-1 text-center" title="Option">Opt.</div>
                        <div class="px-1.5 py-1">Unité</div>
                        <template v-for="block in blocks" :key="block.key">
                            <div
                                class="px-1.5 py-1 text-right"
                                :class="block.headClass"
                                :title="sharedQuantity && block.quantityField === 'quantity'
                                    ? `Partagée avec ${block.key === 'achats' ? 'Vendu client' : 'Estimation achat'} (METL::Quantity)`
                                    : undefined"
                            >
                                Qté
                            </div>
                            <div
                                class="px-1.5 py-1 text-right"
                                :class="block.headClass"
                                title="Prix unitaire, en euros"
                            >
                                P.U. (€)
                            </div>
                            <div
                                class="px-1.5 py-1 text-right"
                                :class="block.headClass"
                                title="Quantité × prix unitaire, en euros"
                            >
                                Total (€)
                            </div>
                            <div v-if="showRatio && block.key === 'achats'" class="px-1.5 py-1 text-right">Ratio</div>
                        </template>
                        <div class="px-1 py-1 text-center">Sél.</div>
                        <div class="px-1 py-1" />
                        <div class="px-1 py-1" />
                        <div class="px-1.5 py-1 text-center">Livraison</div>
                        <div class="px-1.5 py-1">{{ grouping === 'lots' ? 'Lot' : 'Tag' }}</div>
                        <div class="px-1.5 py-1">Commande fourn.</div>
                    </div>
                </div>

                <template v-for="section in groupedRows" :key="section.key">
                    <!-- Intitulé de section — « sub-summary by REF_Code », avec ses sous-totaux
                         et, comme dans la mise en page d'origine, la saisie d'une sous-section
                         définie sur le champ. -->
                    <div
                        class="grid items-center border-y border-sand-300 bg-sand-100 text-xs"
                        :style="{ gridTemplateColumns: TEMPLATE }"
                    >
                        <div class="flex items-center gap-0.5 px-1.5 py-1.5 tabular-nums text-sand-700" style="font-variation-settings: 'wght' 650">
                            <button
                                type="button"
                                class="btn btn-ghost shrink-0 rounded p-0.5"
                                :aria-expanded="!isCollapsed(section.key)"
                                :title="isCollapsed(section.key) ? 'Dérouler la section' : 'Replier la section'"
                                @click="toggleCollapsed(section.key)"
                            >
                                <Icon :name="isCollapsed(section.key) ? 'chevron-right' : 'chevron-down'" :size="3.5" />
                            </button>
                            {{ section.code ?? '—' }}
                        </div>
                        <div class="col-span-4 flex min-w-0 items-center gap-2 px-1.5 py-1.5">
                            <span
                                class="cursor-pointer truncate uppercase tracking-[0.04em] text-sand-900"
                                style="font-variation-settings: 'wght' 650"
                                :title="isCollapsed(section.key) ? 'Dérouler la section' : 'Replier la section'"
                                @click="toggleCollapsed(section.key)"
                            ><template v-for="(part, i) in parts(sectionLabel(section))" :key="i"><mark v-if="part.hit" class="search-hit">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span>

                            <!-- Ce qui est caché se dit, sinon une section repliée ne se distingue
                                 pas d'une section vide. -->
                            <span v-if="isCollapsed(section.key)" class="shrink-0 text-[10px] text-sand-600">
                                {{ section.count }} ligne{{ section.count === 1 ? '' : 's' }}
                                dans {{ section.subSections.length }} sous-section{{ section.subSections.length === 1 ? '' : 's' }}
                            </span>

                            <!-- Le menu de la section : une nouvelle ligne (en demandant où), et les
                                 deux opérations d'ensemble trouvé de la mise en page d'origine. -->
                            <div class="relative shrink-0" @click.stop>
                                <button
                                    type="button"
                                    class="btn btn-ghost rounded px-1 py-0.5"
                                    title="Actions de la section"
                                    @click="togglePopoverAt(section.key, 'section-menu')"
                                >
                                    <Icon name="ellipsis" :size="3.5" />
                                </button>

                                <div v-if="isOpenAt(section.key, 'section-menu')" class="popover absolute left-0 top-6 w-72">
                                    <button
                                        type="button"
                                        class="popover-item"
                                        :disabled="readOnly || busy"
                                        @click="openNewLinePane(section)"
                                    >
                                        Nouvelle ligne de métré…
                                    </button>
                                    <div class="my-1 border-t border-sand-200" />
                                    <button
                                        type="button"
                                        class="popover-item"
                                        title="METL_View_Constrain_REF — n'affiche plus que cette section, sans rien y rajouter"
                                        @click="constrainToSection(section)"
                                    >
                                        Réduire l'affichage à cette section
                                    </button>
                                    <button
                                        type="button"
                                        class="popover-item"
                                        :disabled="!isConstrained"
                                        :title="isConstrained
                                            ? 'METL_View_Extend_REF — rajoute toutes les sous-sections de cette section, sans rien retirer'
                                            : 'Tout le métré est déjà affiché : il n\'y a rien à rajouter'"
                                        @click="extendToSection(section)"
                                    >
                                        Étendre l'affichage de cette section
                                    </button>
                                </div>

                                <!-- « Où ? » : les deux branches de METL_NewFromREF. -->
                                <div
                                    v-else-if="isOpenAt(section.key, 'section-new-line')"
                                    class="popover absolute left-0 top-6 w-80"
                                >
                                    <p class="eyebrow px-3 pb-1 pt-1.5">Dans quelle sous-section ?</p>

                                    <div class="max-h-56 overflow-y-auto">
                                        <button
                                            v-for="sub in sectionSubSections(section.key)"
                                            :key="sub.key"
                                            type="button"
                                            class="popover-item flex items-baseline gap-2"
                                            :disabled="busy"
                                            @click="addLineInSubSection(section, sub)"
                                        >
                                            <span class="shrink-0 tabular-nums text-sand-600">
                                                {{ section.code ?? '—' }}.{{ sub.code ?? '—' }}
                                            </span>
                                            <span class="min-w-0 flex-1 truncate">
                                                {{ sub.title || 'Sous-section sans titre' }}
                                            </span>
                                            <span class="shrink-0 text-[10px] text-sand-500">{{ sub.lines }}</span>
                                        </button>
                                        <p
                                            v-if="sectionSubSections(section.key).length === 0"
                                            class="px-3 py-2 text-[12px] text-sand-600"
                                        >
                                            Cette section n'a aucune sous-section.
                                        </p>
                                    </div>

                                    <div class="my-1 border-t border-sand-200" />

                                    <div class="space-y-1.5 px-3 pb-2 pt-1">
                                        <p class="field-label">Ou une nouvelle sous-section</p>
                                        <div class="flex items-center gap-1.5">
                                            <input
                                                type="number"
                                                :value="newSubSection?.code"
                                                placeholder="code"
                                                class="w-16 px-1 py-0.5 text-[11px] tabular-nums"
                                                @input="newSubSection.code = $event.target.value"
                                            />
                                            <input
                                                type="text"
                                                :value="newSubSection?.title"
                                                placeholder="titre"
                                                class="min-w-0 flex-1 px-1 py-0.5 text-[11px]"
                                                @input="newSubSection.title = $event.target.value"
                                                @keydown.enter="createSubSectionWithLine(section)"
                                            />
                                        </div>
                                        <button
                                            type="button"
                                            class="btn btn-accent btn-sm w-full py-0.5 text-[11px]"
                                            :disabled="busy"
                                            @click="createSubSectionWithLine(section)"
                                        >
                                            Créer et y ajouter une ligne
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <template v-for="block in blocks" :key="block.key">
                            <div />
                            <div />
                            <div
                                class="px-1.5 py-1.5 text-right tabular-nums text-sand-900"
                                :class="block.cellClass"
                                style="font-variation-settings: 'wght' 650"
                                title="Total de la section, options exclues"
                            >
                                {{ money(section.totals[SUBTOTAL_BY_BLOCK[block.key]]) }}
                            </div>
                            <div v-if="showRatio && block.key === 'achats'" />
                        </template>
                        <div class="col-span-6" />
                    </div>

                    <template v-for="sub in subSectionsOf(section)" :key="sub.key">
                        <!-- Intitulé de sous-section — « sub-summary by REFS_Title ». -->
                        <div
                            class="grid items-center border-b border-sand-200 bg-sand-50 text-xs"
                            :style="{ gridTemplateColumns: TEMPLATE }"
                        >
                            <div class="flex items-center gap-0.5 px-1.5 py-1 tabular-nums text-sand-600">
                                <button
                                    type="button"
                                    class="btn btn-ghost shrink-0 rounded p-0.5"
                                    :aria-expanded="!isCollapsed(sub.key)"
                                    :title="isCollapsed(sub.key) ? 'Dérouler la sous-section' : 'Replier la sous-section'"
                                    @click="toggleCollapsed(sub.key)"
                                >
                                    <Icon :name="isCollapsed(sub.key) ? 'chevron-right' : 'chevron-down'" :size="3" />
                                </button>
                                {{ section.code ?? '—' }}.{{ sub.code ?? '—' }}
                            </div>
                            <div class="col-span-4 flex min-w-0 items-center gap-2 px-1.5 py-1">
                                <span
                                    class="cursor-pointer truncate text-sand-800"
                                    style="font-variation-settings: 'wght' 600"
                                    :title="isCollapsed(sub.key) ? 'Dérouler la sous-section' : 'Replier la sous-section'"
                                    @click="toggleCollapsed(sub.key)"
                                ><template v-for="(part, i) in parts(subSectionLabel(sub))" :key="i"><mark v-if="part.hit" class="search-hit">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span>

                                <span v-if="isCollapsed(sub.key)" class="shrink-0 text-[10px] text-sand-600">
                                    {{ sub.rows.length }} ligne{{ sub.rows.length === 1 ? '' : 's' }}
                                </span>
                                <!-- Le menu de la sous-section. Pas d'« étendre » ici : la mise en
                                     page d'origine n'en a pas non plus — il n'existe pas de
                                     METL_View_Extend_REFS, une sous-section n'ayant rien sous elle
                                     qu'une union pourrait rajouter. -->
                                <div class="relative shrink-0" @click.stop>
                                    <button
                                        type="button"
                                        class="btn btn-ghost rounded px-1 py-0.5"
                                        title="Actions de la sous-section"
                                        @click="togglePopoverAt(sub.key, 'sub-menu')"
                                    >
                                        <Icon name="ellipsis" :size="3" />
                                    </button>

                                    <div v-if="isOpenAt(sub.key, 'sub-menu')" class="popover absolute left-0 top-5 w-72">
                                        <button
                                            type="button"
                                            class="popover-item"
                                            :disabled="readOnly || busy"
                                            @click="addLineInSubSection(section, sub)"
                                        >
                                            Nouvelle ligne de métré
                                        </button>
                                        <div class="my-1 border-t border-sand-200" />
                                        <button
                                            type="button"
                                            class="popover-item"
                                            title="METL_View_Constrain_REFS — n'affiche plus que cette sous-section, sous l'intitulé de sa section"
                                            @click="constrainToSubSection(sub)"
                                        >
                                            Réduire l'affichage à cette sous-section
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <template v-for="block in blocks" :key="block.key">
                                <div />
                                <div />
                                <div
                                    class="px-1.5 py-1 text-right tabular-nums text-sand-700"
                                    :class="block.cellClass"
                                    style="font-variation-settings: 'wght' 600"
                                    title="Total de la sous-section, options exclues"
                                >
                                    {{ money(sub.totals[SUBTOTAL_BY_BLOCK[block.key]]) }}
                                </div>
                                <div v-if="showRatio && block.key === 'achats'" />
                            </template>
                            <div class="col-span-6" />
                        </div>

                <div
                    v-for="row in rowsOf(sub)"
                    :key="row.id"
                    class="grid items-center border-b border-sand-200/70 text-xs transition-colors"
                    :class="[
                        selected.has(row.id)
                            ? 'bg-accent-100 shadow-[inset_2px_0_0_0_var(--color-accent-500)]'
                            : 'bg-white hover:bg-accent-100/40',
                        toneColour(row),
                        toneItalic(row),
                    ]"
                    :style="{ gridTemplateColumns: TEMPLATE }"
                >
                    <!-- Le code de la ligne dans le métré : lecture seule, il se déduit de la
                         section et du rang, comme METL::REFSL_Code_c. -->
                    <div
                        class="truncate px-1.5 py-1 text-[11px] tabular-nums"
                        :class="toneInk(row, 'text-sand-600')"
                        :title="row.ref_title
                            ? `${row.ref_title}${row.refs_title ? ' — ' + row.refs_title : ''}`
                            : 'Ligne sans section'"
                    >
                        {{ row.computed.ref_line_code ?? '—' }}
                    </div>

                    <!-- Titre. Marquer une trouvaille dans un champ éditable : on ne met pas de
                         `<mark>` dans un `<input>`, donc le texte marqué est dessiné par-dessus et
                         le champ ne montre le sien qu'au focus (`text-transparent` levé par
                         `focus:`, doublure masquée par `peer-focus:hidden`). Même retrait, même
                         taille, même encre des deux côtés : l'échange ne se voit pas, et il ne
                         touche pas au chemin d'édition — c'est du CSS, sans état à tenir.
                         La doublure ne s'interpose que s'il y a réellement quelque chose à
                         marquer ; hors recherche, la cellule est exactement celle d'avant. -->
                    <div class="relative min-w-0">
                        <input
                            type="text"
                            :value="row.refsl_title"
                            :disabled="readOnly"
                            class="cell-input peer focus:bg-white"
                            :class="hasHit(row.refsl_title) ? 'text-transparent focus:text-sand-900' : ''"
                            @input="editText(row, 'refsl_title', $event.target.value)"
                            @blur="flushRow(row)"
                        />
                        <div
                            v-if="hasHit(row.refsl_title)"
                            class="pointer-events-none absolute inset-0 truncate px-1.5 py-1 text-xs text-sand-900 peer-focus:hidden"
                        ><template v-for="(part, i) in parts(row.refsl_title)" :key="i"><mark v-if="part.hit" class="search-hit">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></div>
                    </div>

                    <div class="flex justify-center">
                        <input
                            type="checkbox"
                            :checked="row.is_estimated_price_b"
                            :disabled="readOnly"
                            class="size-3.5"
                            title="Prix estimé"
                            @change="toggleField(row, 'is_estimated_price_b', $event.target.checked)"
                        />
                    </div>

                    <div class="flex justify-center">
                        <input
                            type="checkbox"
                            :checked="row.is_option_b"
                            :disabled="readOnly"
                            class="size-3.5"
                            title="Option"
                            @change="toggleField(row, 'is_option_b', $event.target.checked)"
                        />
                    </div>

                    <select
                        :value="row.unit ?? ''"
                        :disabled="readOnly"
                        class="cell-input focus:bg-white"
                        @change="editText(row, 'unit', $event.target.value); flushRow(row)"
                    >
                        <option value="">—</option>
                        <option v-for="unit in units" :key="unit" :value="unit">{{ unit }}</option>
                    </select>

                    <!-- Les blocs monétaires de la vue, dans l'ordre, et le ratio entre les
                         deux prix qu'il divise. Une ligne ne connaît pas la vue : elle porte
                         toujours les mêmes champs, seuls les blocs affichés changent. -->
                    <template v-for="block in blocks" :key="block.key">
                        <input
                            type="number" step="any"
                            :value="row[block.quantityField]"
                            :disabled="readOnly"
                            class="cell-input text-right tabular-nums focus:bg-white"
                            :class="block.cellClass"
                            :title="sharedQuantity && block.quantityField === 'quantity'
                                ? `METL::Quantity — partagée avec ${block.key === 'achats' ? 'Vendu client' : 'Estimation achat'}`
                                : undefined"
                            @input="editNumber(row, block.quantityField, $event.target.value)"
                            @blur="flushRow(row)"
                        />
                        <div class="relative" :class="block.cellClass">
                            <input
                                type="number" step="any"
                                :value="row[block.priceField]"
                                :disabled="readOnly"
                                class="cell-input pr-4 text-right tabular-nums focus:bg-white"
                                @input="editNumber(row, block.priceField, $event.target.value)"
                                @blur="flushRow(row)"
                            />
                            <span class="euro-suffix">€</span>
                        </div>
                        <!-- `totalKey` est le montant SANS garde-fou « option » : une ligne en
                             option montre ce qu'elle coûterait, et seuls les sous-totaux
                             l'écartent. Grisée pour que la différence se voie. -->
                        <div
                            class="px-1.5 py-1 text-right tabular-nums"
                            :class="[block.cellClass, row.is_option_b ? 'text-sand-400 italic' : toneInk(row, 'text-sand-700')]"
                            :title="row.is_option_b ? 'Option : ce montant ne compte dans aucun total' : undefined"
                        >
                            {{ money(row.computed[block.totalKey]) }}
                        </div>

                        <!-- Derived from the two unit prices either side of it, so it cannot drift
                             out of step with them. Read-only by construction: there is nothing to write. -->
                        <div
                            v-if="showRatio && block.key === 'achats'"
                            class="px-1.5 py-1 text-right tabular-nums"
                            :class="toneInk(row, 'text-sand-600')"
                            title="P.U. vendu client ÷ P.U. estimation achat — calculé, non modifiable"
                        >
                            {{ row.computed.price_ratio ?? '—' }}
                        </div>
                    </template>

                    <!-- Selection -->
                    <div class="flex justify-center">
                        <input
                            type="checkbox"
                            :checked="selected.has(row.id)"
                            class="size-3.5"
                            @change="toggleSelected(row)"
                        />
                    </div>

                    <!-- Actions popover -->
                    <div class="relative flex justify-center" @click.stop>
                        <button
                            type="button"
                            class="btn btn-ghost rounded px-1 py-0.5"
                            title="Actions"
                            @click="togglePopover(row, 'actions')"
                        >
                            <Icon name="ellipsis" :size="4" />
                        </button>
                        <div v-if="isOpen(row, 'actions')" class="popover absolute right-0 top-6 w-44">
                            <button
                                type="button"
                                class="popover-item"
                                :disabled="readOnly || busy"
                                @click="duplicateLine(row)"
                            >
                                Dupliquer la ligne
                            </button>
                            <button
                                type="button"
                                class="popover-item popover-item-danger"
                                :disabled="readOnly || busy"
                                @click="deleteLine(row)"
                            >
                                Supprimer
                            </button>
                        </div>
                    </div>

                    <!-- Comments popover -->
                    <div class="relative flex justify-center" @click.stop>
                        <button
                            type="button"
                            class="btn btn-ghost rounded px-1 py-0.5"
                            :class="row.comment_client || row.comment_supplier ? 'text-mallow-600' : 'text-sand-400'"
                            title="Commentaires"
                            @click="togglePopover(row, 'comments')"
                        >
                            <Icon name="pencil" :size="3.5" />
                        </button>
                        <div v-if="isOpen(row, 'comments')" class="popover absolute right-0 top-6 w-80 space-y-2 p-3">
                            <div>
                                <label class="field-label">Commentaire client</label>
                                <textarea
                                    rows="3"
                                    :value="row.comment_client"
                                    :disabled="readOnly"
                                    class="block w-full px-2 py-1.5 text-xs"
                                    @input="editText(row, 'comment_client', $event.target.value)"
                                    @blur="flushRow(row)"
                                />
                            </div>
                            <div>
                                <label class="field-label">Commentaire fournisseur</label>
                                <textarea
                                    rows="3"
                                    :value="row.comment_supplier"
                                    :disabled="readOnly"
                                    class="block w-full px-2 py-1.5 text-xs"
                                    @input="editText(row, 'comment_supplier', $event.target.value)"
                                    @blur="flushRow(row)"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Delivered: a status that is also the control that changes it. -->
                    <div class="flex justify-center px-1.5">
                        <button
                            type="button"
                            class="w-full rounded-full border px-2 py-0.5 text-[10px] transition-colors disabled:opacity-60"
                            :class="row.is_delivered_b
                                ? 'border-success-200 bg-success-50 text-success-700 hover:bg-success-100'
                                : 'border-sand-200 bg-sand-100 text-sand-600 hover:bg-sand-200'"
                            style="font-variation-settings: 'wght' 550"
                            :disabled="readOnly"
                            :title="row.is_delivered_b ? 'Livré — cliquer pour annuler' : 'Non livré — cliquer pour marquer livré'"
                            @click="toggleField(row, 'is_delivered_b', !row.is_delivered_b)"
                        >
                            {{ row.is_delivered_b ? 'Livré' : 'Non livré' }}
                        </button>
                    </div>

                    <!-- Lot (or tag) -->
                    <div v-if="grouping === 'lots'" class="relative min-w-0 px-1" @click.stop>
                        <!-- Le code ET le nom : un lot sans nom reste un lot, et n'a pas à se lire
                             comme une ligne qui n'en porte aucun. Seul « — » veut dire « aucun lot ». -->
                        <button
                            type="button"
                            class="flex w-full items-center gap-1 rounded px-1 py-0.5 text-left text-xs transition-colors hover:bg-sand-100 disabled:hover:bg-transparent"
                            :class="row.lot_id ? 'text-sand-900' : 'text-sand-400'"
                            :disabled="readOnly"
                            :title="lotTitle(row)"
                            @click="togglePopover(row, 'lot')"
                        >
                            <template v-if="row.lot_id">
                                <span v-if="row.lot_code !== null && row.lot_code !== undefined" class="code-chip shrink-0">
                                    {{ row.lot_code }}
                                </span>
                                <span
                                    v-if="row.lot_name"
                                    class="min-w-0 truncate"
                                ><template v-for="(part, i) in parts(row.lot_name)" :key="i"><mark v-if="part.hit" class="search-hit">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span>
                                <span v-else class="min-w-0 truncate italic text-sand-500">Lot sans nom</span>
                            </template>
                            <span v-else>—</span>
                        </button>
                        <div
                            v-if="isOpen(row, 'lot')"
                            class="popover absolute right-0 top-6 max-h-64 w-56 overflow-y-auto"
                        >
                            <button type="button" class="popover-item text-sand-600" @click="assignLot(row, null)">
                                Aucun lot
                            </button>
                            <button
                                v-for="lot in lots"
                                :key="lot.id"
                                type="button"
                                class="popover-item flex items-center gap-1.5"
                                :class="lot.id === row.lot_id ? 'bg-accent-100' : ''"
                                @click="assignLot(row, lot)"
                            >
                                <span v-if="lot.code" class="code-chip shrink-0">{{ lot.code }}</span>
                                <span class="min-w-0 truncate">{{ lot.name || 'Lot sans nom' }}</span>
                            </button>
                            <p v-if="lots.length === 0" class="px-3 py-2 text-xs text-sand-600">
                                Aucun lot sur ce projet.
                            </p>
                        </div>
                    </div>

                    <!-- Tags mode: the stored tag, read-only until the switch's meaning is settled. -->
                    <div
                        v-else
                        class="min-w-0 truncate px-1.5 py-1 text-xs text-sand-600"
                        title="METL::Tag1 — le rôle exact du bascule Tags reste à définir"
                    >
                        {{ row.tag1 || '—' }}
                    </div>

                    <!-- Linked ShakeDesign supplier order. Displayed only: opening it in the
                         FileMaker client needs a confirmed fmp:// target - see the page note. -->
                    <div class="min-w-0 truncate px-1.5 py-1 text-xs">
                        <span
                            v-if="row.sor_title_ref"
                            class="text-sand-700"
                            :title="`${row.sor_title_ref} — l'ouverture dans FileMaker n'est pas encore branchée`"
                        ><template v-for="(part, i) in parts(row.sor_title_ref)" :key="i"><mark v-if="part.hit" class="search-hit">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span>
                        <span v-else class="text-sand-300">—</span>
                    </div>
                </div>
                    </template>
                </template>

                <!-- Total général — la « trailing grand summary » du même écran. Options exclues,
                     comme les sous-totaux au-dessus. -->
                <div
                    v-if="visibleRows.length > 0"
                    class="sticky bottom-0 z-20 grid items-center border-t-2 border-sand-300 bg-sand-100 text-xs"
                    :style="{ gridTemplateColumns: TEMPLATE }"
                >
                    <div />
                    <div class="col-span-4 px-1.5 py-2 uppercase tracking-[0.04em] text-sand-900" style="font-variation-settings: 'wght' 650">
                        Total du métré
                    </div>
                    <template v-for="block in blocks" :key="block.key">
                        <div />
                        <div />
                        <div
                            class="px-1.5 py-2 text-right tabular-nums text-sand-900"
                            :class="block.cellClass"
                            style="font-variation-settings: 'wght' 650"
                            title="Total du métré, options exclues"
                        >
                            {{ money(grandTotals[SUBTOTAL_BY_BLOCK[block.key]]) }}
                        </div>
                        <div v-if="showRatio && block.key === 'achats'" />
                    </template>
                    <div class="col-span-6" />
                </div>

                <div v-if="visibleRows.length === 0" class="flex flex-col items-center gap-2 bg-white py-16 text-center">
                    <Icon name="table" :size="6" class="text-sand-300" />
                    <p class="text-[13px] text-sand-700">
                        {{ rows.length === 0 ? "Ce métré n'a aucune ligne." : 'Aucune ligne ne correspond à la recherche.' }}
                    </p>
                    <button
                        v-if="rows.length === 0 && !readOnly"
                        type="button"
                        class="btn btn-secondary btn-sm mt-1"
                        :disabled="busy"
                        @click="addLine"
                    >
                        <Icon name="plus" :size="3.5" />
                        Ajouter une ligne
                    </button>
                </div>
            </div>
        </div>

        <footer class="flex shrink-0 items-center gap-3 border-t border-sand-200 bg-white px-3 py-1.5 text-[11px] text-sand-600">
            <span>{{ visibleRows.length }} / {{ rows.length }} ligne{{ rows.length === 1 ? '' : 's' }}</span>
            <span v-if="selected.size" class="text-sand-900">
                {{ selected.size }} sélectionnée{{ selected.size === 1 ? '' : 's' }}
            </span>
            <span v-if="sharedQuantity" class="ml-auto flex items-center gap-1.5">
                <span class="size-2 rounded-full bg-clay-500" aria-hidden="true" />
                <span class="size-2 rounded-full bg-olive-500" aria-hidden="true" />
                Qté « Estimation achat » et « Vendu client » sont le même champ (METL::Quantity)
            </span>
        </footer>
    </div>

    <ReferenceCatalogueModal
        :show="showCatalogue"
        :read-only="readOnly"
        :busy="busy"
        @insert="insertFromCatalogue"
        @close="showCatalogue = false"
    />

    <!-- La carte de METL_Lot_AssignToSelection : ce qui va changer, puis le lot, puis on écrit. -->
    <Modal :show="assigningLot" max-width="2xl" @close="assigningLot = false">
        <div class="p-5">
            <h2 class="text-[15px] text-sand-900" style="font-variation-settings: 'wght' 600">
                Assigner un lot
            </h2>
            <p class="mt-1 text-[13px] text-sand-700">
                {{ selected.size }} ligne{{ selected.size === 1 ? '' : 's' }} sélectionnée{{ selected.size === 1 ? '' : 's' }}.
                Le lot choisi remplace celui qu'elles portent, s'il y en a un.
            </p>

            <div class="mt-4">
                <p class="field-label">Lot</p>
                <div class="mt-1 max-h-48 space-y-1 overflow-y-auto pr-1">
                    <label
                        class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] transition-colors"
                        :class="lotToAssign === null ? 'bg-accent-100' : 'hover:bg-sand-100'"
                    >
                        <input v-model="lotToAssign" type="radio" :value="null" class="size-3.5" />
                        <span class="text-sand-600">Aucun lot — détacher</span>
                    </label>
                    <label
                        v-for="lot in lots"
                        :key="lot.id"
                        class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-[13px] transition-colors"
                        :class="lotToAssign === lot.id ? 'bg-accent-100' : 'hover:bg-sand-100'"
                    >
                        <input v-model="lotToAssign" type="radio" :value="lot.id" class="size-3.5" />
                        <span v-if="lot.code !== null" class="code-chip shrink-0">{{ lot.code }}</span>
                        <span class="min-w-0 truncate text-sand-900">{{ lot.name || 'Lot sans nom' }}</span>
                    </label>
                    <p v-if="lots.length === 0" class="px-2 py-3 text-[13px] text-sand-600">
                        Ce projet n'a aucun lot. Ils se créent depuis la page du projet.
                    </p>
                </div>
            </div>

            <!-- La liste de la carte source : on voit ce qu'on change, avec le lot actuel de chacune. -->
            <div class="mt-4">
                <p class="field-label">Lignes concernées</p>
                <div class="mt-1 max-h-56 overflow-y-auto rounded-md border border-sand-200">
                    <div
                        v-for="row in selectedRows"
                        :key="row.id"
                        class="flex items-baseline gap-2 border-b border-sand-200/70 px-2 py-1 text-xs last:border-b-0"
                    >
                        <span class="w-14 shrink-0 tabular-nums text-sand-600">
                            {{ row.computed.ref_line_code ?? '—' }}
                        </span>
                        <span class="min-w-0 flex-1 truncate text-sand-900">
                            {{ row.refsl_title || 'Ligne sans libellé' }}
                        </span>
                        <span class="shrink-0 text-[11px] text-sand-500">{{ lotLabel(row.lot_id) }}</span>
                    </div>
                </div>
            </div>

            <div class="mt-5 flex items-center justify-end gap-2">
                <SecondaryButton :disabled="busy" @click="assigningLot = false">Annuler</SecondaryButton>
                <button
                    type="button"
                    class="btn btn-accent"
                    :disabled="busy || selected.size === 0"
                    @click="assignLotToSelection"
                >
                    Assigner à {{ selected.size }} ligne{{ selected.size === 1 ? '' : 's' }}
                </button>
            </div>
        </div>
    </Modal>

    <GridToasts :toasts="toasts" @dismiss="dismiss" />
</template>
