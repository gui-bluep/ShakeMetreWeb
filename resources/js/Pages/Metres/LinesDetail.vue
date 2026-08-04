<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppTopBar from '@/Components/AppTopBar.vue';
import GridToasts from '@/Components/GridToasts.vue';
import Icon from '@/Components/Icon.vue';
import { useDebouncedRowSave } from '@/composables/useDebouncedRowSave';

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

const visibleRows = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (term === '') {
        return rows.value;
    }

    return rows.value.filter((row) =>
        [row.description, row.unit, row.lot_name, row.sor_title_ref]
            .some((field) => String(field ?? '').toLowerCase().includes(term))
    );
});

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

/** `{ id, kind }` for the one open popover, or null. One at a time, closed on outside click. */
const popover = ref(null);

function togglePopover(row, kind) {
    popover.value = popover.value?.id === row.id && popover.value?.kind === kind
        ? null
        : { id: row.id, kind };
}

function isOpen(row, kind) {
    return popover.value?.id === row.id && popover.value?.kind === kind;
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

    queue(row.id, 'lot_id', row.lot_id, previousId);
    await flushRow(row);
}

// --- line actions -------------------------------------------------------------------------

const busy = ref(false);

async function addLine() {
    if (readOnly.value || busy.value) {
        return;
    }

    busy.value = true;

    try {
        const body = await request(`/api/metres/${props.metre.id}/lines`, 'POST', {});
        rows.value.push(clone(body.data));
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
        totalKey: 'price_total_buy_no_options',
    },
    ventes: {
        key: 'ventes',
        label: 'Vendu client',
        bandClass: 'bg-olive-100 text-olive-700',
        headClass: 'bg-olive-50',
        cellClass: 'bg-olive-50/70',
        quantityField: 'quantity',
        priceField: 'price_sales',
        totalKey: 'price_total_sales_no_options',
    },
    commandes: {
        key: 'commandes',
        label: 'Commande',
        bandClass: 'bg-mallow-100 text-mallow-700',
        headClass: 'bg-mallow-50',
        cellClass: 'bg-mallow-50/70',
        quantityField: 'quantity_ordered',
        priceField: 'price_ordered',
        totalKey: 'price_total_ordered_no_options',
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

/** Column widths, so the header and the rows stay aligned across the horizontal scroll. */
const TEMPLATE = computed(() => [
    'minmax(15rem, 1.4fr)',                 // titre
    '2.5rem', '2.5rem',                     // est. / option
    '5.5rem',                               // unité
    ...blocks.value.flatMap((block) => [
        '5rem', '6rem', '7rem',             // qté / p.u. / total
        // Le ratio se glisse juste après le bloc achats, entre les deux prix qu'il divise.
        ...(showRatio.value && block.key === 'achats' ? ['4rem'] : []),
    ]),
    '2.5rem',                               // select
    '2rem', '2rem',                         // actions / comments
    '6.5rem',                               // delivered
    'minmax(8rem, 0.8fr)',                  // lot / tag
    'minmax(8rem, 0.8fr)',                  // SOR
].join(' '));

/**
 * Largeur plancher de la grille, en rem, pour que les colonnes ne se compriment pas sous leur
 * lisibilité : 92 pour les trois blocs, moins 18 par bloc retiré et 4 si le ratio ne s'affiche
 * pas. Sans ce calcul, la vue « Ventes » garderait un plancher prévu pour trois fois plus de
 * colonnes et traînerait un défilement horizontal sur du vide.
 */
const minWidth = computed(
    () => `${92 - 18 * (3 - blocks.value.length) - (showRatio.value ? 0 : 4)}rem`
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
                    placeholder="Rechercher une ligne…"
                    class="block w-full py-1.5 pl-8 pr-2.5 text-xs"
                    @input="search = $event.target.value"
                />
            </div>

            <button type="button" class="btn btn-secondary btn-sm" @click="toggleSelectAll">
                {{ allVisibleSelected ? 'Tout désélectionner' : 'Tout sélectionner' }}
                <span v-if="selected.size" class="badge badge-accent">{{ selected.size }}</span>
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

                <div
                    v-for="row in visibleRows"
                    :key="row.id"
                    class="grid items-center border-b border-sand-200/70 text-xs transition-colors"
                    :class="selected.has(row.id)
                        ? 'bg-accent-100 shadow-[inset_2px_0_0_0_var(--color-accent-500)]'
                        : 'bg-white hover:bg-accent-100/40'"
                    :style="{ gridTemplateColumns: TEMPLATE }"
                >
                    <!-- Titre -->
                    <input
                        type="text"
                        :value="row.description"
                        :disabled="readOnly"
                        class="cell-input focus:bg-white"
                        @input="editText(row, 'description', $event.target.value)"
                        @blur="flushRow(row)"
                    />

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
                        <div
                            class="px-1.5 py-1 text-right tabular-nums text-sand-700"
                            :class="block.cellClass"
                        >
                            {{ money(row.computed[block.totalKey]) }}
                        </div>

                        <!-- Derived from the two unit prices either side of it, so it cannot drift
                             out of step with them. Read-only by construction: there is nothing to write. -->
                        <div
                            v-if="showRatio && block.key === 'achats'"
                            class="px-1.5 py-1 text-right tabular-nums text-sand-600"
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
                        <button
                            type="button"
                            class="w-full truncate rounded px-1 py-0.5 text-left text-xs transition-colors hover:bg-sand-100 disabled:hover:bg-transparent"
                            :class="row.lot_name ? 'text-sand-900' : 'text-sand-400'"
                            :disabled="readOnly"
                            :title="row.lot_name || 'Aucun lot'"
                            @click="togglePopover(row, 'lot')"
                        >
                            {{ row.lot_name || '—' }}
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
                                <span class="min-w-0 truncate">{{ lot.name || 'Lot sans titre' }}</span>
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
                        >
                            {{ row.sor_title_ref }}
                        </span>
                        <span v-else class="text-sand-300">—</span>
                    </div>
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

    <GridToasts :toasts="toasts" @dismiss="dismiss" />
</template>
