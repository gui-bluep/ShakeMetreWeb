<script setup>
import { computed, reactive, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import AppCard from '@/Components/AppCard.vue';
import Badge from '@/Components/Badge.vue';
import DangerButton from '@/Components/DangerButton.vue';
import Icon from '@/Components/Icon.vue';
import Modal from '@/Components/Modal.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import StatTile from '@/Components/StatTile.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { useDebouncedRowSave } from '@/composables/useDebouncedRowSave';
import { localToday } from '@/localDate';
import { offerUrl } from '@/fileMakerLink';
import { apiRequest as request, csrfToken } from '@/apiRequest';
import { u } from '@/basePath';

/**
 * One métré's own page. Header fields save as they are edited, through the same debounced
 * mechanism as the grids; the totals and the real ratio are read-only, materialized by
 * RecalculateMetreTotals and MET_Metre::Ratio_c respectively.
 *
 * La date d'accord est un champ modifiable ET une valeur posée automatiquement quand on coche
 * « Accepté » : les deux sont normales ici, ce qui explique que `toggle()` réapplique toute la
 * réponse plutôt que le seul champ qu'on vient de changer.
 */
const props = defineProps({
    metre: { type: Object, required: true },
    project: { type: Object, required: true },
    languages: { type: Array, required: true },
    lineCount: { type: Number, default: 0 },
    lotBreakdown: { type: Object, required: true },
    /** Les offres client du métré, ou `null` si ShakeDesign n'a pas répondu. */
    offers: { type: Array, default: null },
    /**
     * L'hôte et le fichier ShakeDesign, pour ouvrir une offre dans le client FileMaker. Une
     * propriété de l'installation ; le zkp de l'offre vient de la ligne du tableau.
     */
    filemakerLink: { type: Object, default: null },
    /**
     * Le lot à retrouver sélectionné dans le cadre Fournisseurs, quand on revient d'une vue
     * ouverte depuis lui. La sélection reste un état d'écran : ceci ne fait que la rendre après
     * un aller-retour.
     */
    initialLotId: { type: String, default: null },
});

const page = usePage();
const readOnly = computed(() => page.props.auth?.canWrite === false);
const readOnlyReason = computed(() =>
    form.is_locked_b ? 'Métré verrouillé' : 'Compte en lecture seule'
);

/** Local working copy: edits land here first, the server confirms afterwards. */
const form = reactive({ ...props.metre, totals: { ...props.metre.totals } });

const error = ref(null);
const busy = ref(false);
const confirmingDelete = ref(false);

/**
 * Re-seeds the working copy whenever the page is pointed at a different métré.
 *
 * Without this, duplicating showed the ORIGINAL's values under the COPY's id: Inertia can
 * reuse this component across a navigation between two métrés, so `props.metre` changed while
 * `form` - seeded once at setup - did not. Worse than cosmetic, because edits are keyed on
 * `props.metre.id`: typing in a field would have written the previous métré's stale values
 * onto the new one.
 */
watch(
    () => props.metre.id,
    () => {
        Object.assign(form, props.metre, { totals: { ...props.metre.totals } });
        error.value = null;
    }
);

const { queue, flush } = useDebouncedRowSave({
    endpoint: '/api/metres',
    delay: 500,
    onError: ({ rollback, message }) => {
        Object.assign(form, rollback);
        error.value = message;
    },
});

function edit(field, value) {
    if (readOnly.value) {
        return;
    }

    const previous = form[field];

    if (value === previous) {
        return;
    }

    form[field] = value;
    error.value = null;
    queue(props.metre.id, field, value, previous);
}

function number(field, raw) {
    edit(field, raw === '' ? null : Number(raw));
}

function text(field, raw) {
    edit(field, raw === '' ? null : raw);
}

/**
 * Les deux drapeaux ne sont pas de simples étiquettes : ils conditionnent les totaux stockés
 * côté serveur, et cocher « Accepté » date l'accord (la décocher vide la date). L'enregistrement
 * est donc envoyé tout de suite au lieu d'attendre les 500 ms, et la réponse est réappliquée en
 * entier — sans quoi la date resterait celle d'avant à l'écran alors qu'elle a changé en base.
 *
 * La date posée est celle du navigateur, donc le jour de la personne qui coche, quel que soit le
 * fuseau du serveur. Les deux champs partent dans le même PATCH (`queue` les groupe par ligne)
 * et le champ se remplit à l'écran sans attendre la réponse. Le serveur applique la même règle
 * en repli, à son horloge, pour tout appel qui n'enverrait pas la date.
 *
 * Les quatre tuiles, elles, ne bougent pas avec les drapeaux : elles lisent les colonnes non
 * conditionnées (voir MetreController::payload()).
 */
async function toggle(field, checked) {
    edit(field, checked);

    if (field === 'is_accepted_b') {
        edit('date_agreement', checked ? localToday() : null);
    }

    const body = await flush(props.metre.id);

    if (body) {
        Object.assign(form, body, { totals: { ...body.totals } });
    }
}

async function flushField() {
    const body = await flush(props.metre.id);

    if (body) {
        Object.assign(form, body, { totals: { ...body.totals } });
    }
}

// --- actions ------------------------------------------------------------------------------

async function duplicate() {
    if (readOnly.value || busy.value) {
        return;
    }

    busy.value = true;

    // Any debounced edit is written before the copy is taken, so the duplicate reflects what is
    // on screen rather than the last saved state.
    await flush(props.metre.id);

    router.post(u(`/metres/${props.metre.id}/duplicate`), {}, {
        onFinish: () => { busy.value = false; },
    });
}

/**
 * Verrouiller / déverrouiller - MET_LockUnlock, qui n'est qu'un `Set Field` sur `isLocked_b`.
 *
 * L'état visé est envoyé plutôt que basculé : deux onglets ouverts sur le même métré se
 * renverraient sinon le verrou l'un à l'autre. Les modifications en attente sont écrites avant,
 * comme pour la duplication - verrouiller juste après avoir tapé ne doit pas perdre la frappe.
 */
async function setLocked(locked) {
    if (readOnly.value || busy.value) {
        return;
    }

    busy.value = true;
    await flush(props.metre.id);

    try {
        const response = await fetch(u(`/api/metres/${props.metre.id}/lock`), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ locked }),
        });

        const body = await response.json().catch(() => null);

        if (! response.ok) {
            throw new Error(body?.message ?? `Échec (HTTP ${response.status})`);
        }

        Object.assign(form, body.data, { totals: { ...body.data.totals } });
    } catch (e) {
        lockError.value = e.message;
    } finally {
        busy.value = false;
    }
}

/*
 * Le cadre « Fournisseurs » de MET_Form : un lot se choisit, puis deux actions portent sur lui.
 *
 * Le choix vit à l'écran et nulle part ailleurs, comme dans la source où `MET_LOT_Set` écrit dans
 * `MET::zkg_LOT_Chosen` - un champ GLOBAL, donc propre à la session et jamais enregistré. Deux
 * personnes sur le même métré ne se volent pas leur sélection.
 */
const chosenLotId = ref(props.initialLotId);

/** Le popover de choix du lot - le cadre de MET_Form en a un, la liste étant longue. */
const lotPickerOpen = ref(false);

/*
 * Copie locale de la répartition par lot : créer une commande déplace des montants de « acheté »
 * vers « commandé », et le serveur renvoie la répartition recalculée pour que la carte le montre
 * tout de suite. Re-semée quand la page change de métré, comme `form` - le piège documenté.
 */
const breakdown = ref(props.lotBreakdown);
watch(() => props.lotBreakdown, (value) => { breakdown.value = value; });

const chosenLot = computed(
    () => breakdown.value.lots.find((l) => l.id === chosenLotId.value) ?? null
);

// Un métré chasse l'autre : Inertia réutilise ce composant d'un métré au suivant, et une
// sélection qui survivrait désignerait un lot qu'on ne voit plus. Même raison que `form`.
watch(() => props.metre.id, () => { chosenLotId.value = props.initialLotId; lotPickerOpen.value = false; });

function chooseLot(lot) {
    chosenLotId.value = lot.id;
    lotPickerOpen.value = false;
}

const orderButtonTitle = computed(() => {
    if (!chosenLot.value) return 'Choisissez un lot';
    if (readOnly.value) return readOnlyReason.value;

    return `Commander « ${chosenLot.value.name || 'ce lot'} » chez ${chosenLot.value.company || 'son fournisseur'}`;
});

/**
 * L'écran de contrôle avant création - `Action = "Validation"` de MET_SOR_CreateCSupplierOrder.
 *
 * Le serveur dit ce qui partirait ET ce qui l'empêche, en une réponse : la source pose ses
 * refus un par un, en boîtes de dialogue successives, si bien qu'on corrige un problème pour
 * découvrir le suivant. Ici les quatre sont montrés ensemble.
 */
const orderPreview = ref(null);
const orderError = ref(null);
const orderCreated = ref(null);

async function openSupplierOrder() {
    if (!chosenLot.value || readOnly.value || busy.value) {
        return;
    }

    busy.value = true;
    orderError.value = null;

    try {
        const body = await request(
            `/api/metres/${props.metre.id}/lots/${chosenLot.value.id}/supplier-order`,
            'GET'
        );
        orderPreview.value = body.data;
    } catch (e) {
        orderError.value = e.message;
    } finally {
        busy.value = false;
    }
}

/**
 * La bande de l'écran de contrôle : en-tête de section, en-tête de sous-section, lignes.
 *
 * Plate et non imbriquée, comme les états imprimés et pour la même raison - c'est ce que fait une
 * sous-totalisation FileMaker, et cela rend impossible un décalage entre l'ordre des lignes et
 * celui des titres. Les sous-totaux sont recalculés ici plutôt que renvoyés par le serveur : les
 * cellules changent sous les doigts, et un sous-total venu du serveur serait le seul chiffre
 * périmé à l'écran. Même règle que les vues de lignes.
 */
const orderRows = computed(() => {
    if (orderPreview.value === null) return [];

    const rows = [];
    let ref = null;
    let refs = null;

    const sum = (predicate) => round2(
        orderPreview.value.lines
            .filter((l) => predicate(l) && ! l.is_option_b)
            .reduce((total, l) => total + (l.total ?? 0), 0)
    );

    for (const line of orderPreview.value.lines) {
        const refKey = `${line.ref_code} ${line.ref_title}`;
        const refsKey = `${refKey} ${line.refs_title}`;

        if (refKey !== ref) {
            ref = refKey;
            refs = null;
            rows.push({
                kind: 'ref', key: `ref:${refKey}`, code: line.ref_code, title: line.ref_title,
                total: sum((l) => `${l.ref_code} ${l.ref_title}` === refKey),
            });
        }

        if (refsKey !== refs) {
            refs = refsKey;
            rows.push({
                kind: 'refs', key: `refs:${refsKey}`, code: line.refs_code, title: line.refs_title,
                total: sum((l) => `${l.ref_code} ${l.ref_title} ${l.refs_title}` === refsKey),
            });
        }

        rows.push({ kind: 'line', key: line.id, line });
    }

    return rows;
});

/** Le total engagé : options exclues, comme le montant envoyé à ShakeDesign. */
const orderTotal = computed(() => round2(
    (orderPreview.value?.lines ?? [])
        .filter((l) => ! l.is_option_b)
        .reduce((total, l) => total + (l.total ?? 0), 0)
));

const round2 = (value) => Math.round(value * 100) / 100;
const numberOrNull = (raw) => (raw === '' ? null : Number(raw));

const { queue: queueLine } = useDebouncedRowSave({
    endpoint: '/api/metre-lines',
    delay: 500,
    onError: ({ rowId, rollback, message }) => {
        const line = orderPreview.value?.lines.find((l) => l.id === rowId);

        if (line) {
            Object.assign(line, rollback);
            line.total = round2((line.price_ordered ?? 0) * (line.quantity_ordered ?? 0));
        }

        orderError.value = message;
    },
});

/**
 * Une cellule de l'écran de contrôle.
 *
 * Le total de la ligne est recalculé sur place - `Round ( PriceOrdered * QuantityOrdered ; 2 )`,
 * la formule de `PriceTotalOrderedAll_c` - pour que le sous-total et le total suivent la frappe
 * plutôt que d'attendre la réponse. Le serveur reste l'autorité : une erreur remet la valeur
 * d'avant et le dit.
 */
function editOrderLine(line, field, value) {
    if (readOnly.value || busy.value || line[field] === value) {
        return;
    }

    const previous = line[field];
    line[field] = value;
    line.total = round2((line.price_ordered ?? 0) * (line.quantity_ordered ?? 0));
    orderError.value = null;

    queueLine(line.id, field, value, previous);
}

async function createSupplierOrder() {
    if (!orderPreview.value || readOnly.value || busy.value) {
        return;
    }

    busy.value = true;
    orderError.value = null;

    try {
        const body = await request(
            `/api/metres/${props.metre.id}/lots/${orderPreview.value.lot.id}/supplier-order`,
            'POST'
        );

        orderPreview.value = null;
        orderCreated.value = body.data.supplier_order;

        // Les montants « commandé » de la carte viennent de changer : le serveur renvoie la
        // répartition recalculée plutôt que de laisser l'écran mentir jusqu'au prochain
        // rafraîchissement.
        if (body.data.lot_breakdown) {
            breakdown.value = body.data.lot_breakdown;
        }
    } catch (e) {
        orderError.value = e.message;
    } finally {
        busy.value = false;
    }
}

/**
 * Une offre client dans ShakeDesign - MET_OFF_CreateClientOffer.
 *
 * Un seul dialogue de confirmation, comme décidé : le source en a deux (un écran de validation
 * puis la création). Le libellé nomme l'application de destination, comme le source qui demande
 * « Confirmez-vous la création d'une offre dans Smarter? » - on écrit chez quelqu'un d'autre.
 */
const confirmingOffer = ref(false);
const offerError = ref(null);
const offerCreated = ref(false);
const offers = ref(props.offers);

watch(() => props.offers, (value) => { offers.value = value; });

/**
 * Le titre d'une offre : un lien qui l'ouvre dans le client FileMaker quand on peut en fabriquer
 * un, le même libellé sinon. Même règle que la commande fournisseur des vues de lignes — voir
 * `sorLink()` dans LinesDetail.vue et fileMakerLink.js.
 *
 * Une offre créée à l'instant est linkable comme les autres : la réponse de création renvoie la
 * liste relue chez ShakeDesign, zkp compris.
 */
const offerLink = (offer) => {
    const href = offerUrl(props.filemakerLink, offer.zkp);
    const label = offer.title || 'Offre sans titre';

    return {
        is: href ? 'a' : 'span',
        href,
        class: href
            ? 'text-sand-800 underline decoration-sand-300 underline-offset-2 hover:text-sand-950 hover:decoration-sand-800'
            : '',
        title: href ? `Ouvrir « ${label} » dans ShakeDesign (FileMaker Pro)` : null,
    };
};

async function createOffer() {
    if (readOnly.value || busy.value) {
        return;
    }

    busy.value = true;
    offerError.value = null;
    await flush(props.metre.id);

    try {
        const response = await fetch(u(`/api/metres/${props.metre.id}/offer`), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
        });

        const body = await response.json().catch(() => null);

        if (! response.ok) {
            throw new Error(body?.message ?? `Échec (HTTP ${response.status})`);
        }

        // La réponse porte la liste rafraîchie : l'offre créée apparaît sans recharger la page.
        offers.value = body.data.offers;
        offerCreated.value = true;
        confirmingOffer.value = false;
    } catch (e) {
        offerError.value = e.message;
    } finally {
        busy.value = false;
    }
}

const lockError = ref(null);

function destroy() {
    busy.value = true;
    router.delete(u(`/metres/${props.metre.id}`), {
        onFinish: () => { busy.value = false; confirmingDelete.value = false; },
    });
}

// --- not yet wired up ---------------------------------------------------------------------

/**
 * Rendered as disabled rows rather than omitted: the set of documents and views a métré
 * offers is part of what this screen is, and hiding them would make the page look finished
 * when it is not. Each is enabled as it gets built.
 *
 * Chacun porte l'étiquette « Bientôt » plutôt qu'un simple bouton grisé : grisé seul, l'écran
 * se lit comme cassé ou comme un droit manquant.
 */
const DOCUMENTS = [
    ['budget-client-complet', 'Budget client — complet'],
    ['budget-client-complet-composition', 'Budget client — complet avec composition'],
    ['budget-client-simplifie', 'Budget client — simplifié'],
    ['budget-client-simplifie-composition', 'Budget client — simplifié avec composition'],
    ['budget-client-sous-categories', 'Budget client — sous-catégories'],
    ['budget-client-categories', 'Budget client — catégories'],
    ['fournisseur-budget-achats', 'Fournisseur — budget achats'],
];

/**
 * Les sept documents, avec l'URL qui les rend.
 *
 * Les slugs doublent ceux de `App\Documents\MetreDocument`, et c'est assumé : le serveur reste
 * l'autorité - il renvoie 404 sur tout ce qu'il ne connaît pas - et un écran qui listerait des
 * documents envoyés par le serveur ne dirait rien de plus tout en ajoutant une clé à la payload
 * de chaque affichage de métré.
 */
const documents = computed(() =>
    DOCUMENTS.map(([slug, label]) => ({
        slug,
        label,
        url: u(`/metres/${props.metre.id}/documents/${slug}`),
    }))
);

/**
 * L'aperçu, qui est ce que fait la source : `METL_GoTo_Print` ouvre une fenêtre en mode
 * Prévisualisation sur la mise en page d'impression, et c'est de là qu'on enregistre un PDF.
 *
 * Ici, une fenêtre modale montrant le PDF dans un `<iframe>` - le lecteur du navigateur - et un
 * lien de téléchargement vers la même URL avec `?download=1`. La même URL des deux côtés : ce
 * qu'on regarde et ce qu'on enregistre sont le même document, pas deux rendus qui pourraient
 * diverger.
 *
 * `src` n'est posé qu'à l'ouverture : sept `<iframe>` montés d'avance déclencheraient sept rendus
 * PDF à chaque affichage de la page.
 */
const preview = ref(null);

function openDocument(document) {
    preview.value = document;
}

/**
 * Les quatre vues monétaires des lignes, toutes construites : une seule page les rend, la coupe
 * étant portée par le slug (MetreLineDetailController::VIEWS). Les libellés sont ceux que la vue
 * affiche elle-même dans son fil d'Ariane et son titre d'onglet.
 */
const VIEWS = [
    { label: 'Achats — Ventes', href: u(`/metres/${props.metre.id}/lines/achats-ventes`) },
    { label: 'Achats — Commandes', href: u(`/metres/${props.metre.id}/lines/achats-commandes`) },
    { label: 'Achats — Ventes — Commandes', href: u(`/metres/${props.metre.id}/lines/achats-ventes-commandes`) },
    { label: 'Ventes', href: u(`/metres/${props.metre.id}/lines/ventes`) },
];

// --- display ------------------------------------------------------------------------------

const currency = new Intl.NumberFormat('fr-BE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function money(value) {
    return value === null || value === undefined ? '—' : `${currency.format(value)} €`;
}

/**
 * Une quantité, sans ses zéros de fin : la colonne est un `decimal(15,4)` et « 2,0000 » se lit
 * comme une précision qu'on n'a pas. Deux décimales au plus, et aucune quand il n'y en a pas.
 */
function quantity(value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return new Intl.NumberFormat('fr-BE', { maximumFractionDigits: 2 }).format(Number(value));
}

/**
 * Les quatre totaux, dans le code couleur du domaine : argile pour les achats, olive pour les
 * ventes, mauve pour les commandes — les mêmes que les trois blocs de la grille de lignes.
 */
const TOTALS = [
    { label: 'Total des achats', key: 'purchases', tone: 'clay' },
    { label: 'Total des ventes', key: 'sales', tone: 'olive' },
    { label: 'Total des commandes', key: 'ordered', tone: 'mallow' },
    { label: 'Gains', key: 'gain', tone: 'accent' },
];

const COMMENTS = [
    { label: 'Commentaires client', key: 'comment_client' },
    { label: 'Commentaires fournisseur', key: 'comment_supplier' },
    { label: 'Commentaires internes', key: 'comment_internal' },
];

/** Un état actif se voit : bordure et fond marqués, plutôt qu'une case cochée de 14 px. */
function statusClasses(active) {
    return active
        ? 'border-accent-500 bg-accent-100 text-sand-900'
        : 'border-sand-300 bg-white text-sand-700 hover:border-sand-400';
}
</script>

<template>
    <Head :title="form.name || 'Métré'" />

    <AuthenticatedLayout
        :title="form.name || 'Métré sans nom'"
        :breadcrumbs="[
            { label: 'Projets', href: route('dashboard') },
            { label: project.name || 'Projet sans nom', href: project.id ? u(`/projects/${project.id}`) : null },
            { label: form.name || 'Métré sans nom' },
        ]"
    >
        <template #meta>
            <Badge :tone="form.is_accepted_b ? 'success' : 'neutral'">
                <Icon v-if="form.is_accepted_b" name="check" :size="3" />
                {{ form.is_accepted_b ? 'Accepté' : 'Non accepté' }}
            </Badge>
            <Badge v-if="form.is_status_site_b" tone="info">Site</Badge>
            <span>{{ lineCount }} ligne{{ lineCount === 1 ? '' : 's' }}</span>
            <span>Ratio réel {{ form.ratio ?? '—' }}</span>
        </template>

        <template #actions>
            <Link v-if="project.id" :href="u(`/projects/${project.id}`)" class="btn btn-secondary">
                <Icon name="arrow-left" :size="4" />
                Retour au projet
            </Link>
        </template>

        <div class="space-y-4">
            <p v-if="error" class="banner banner-danger">
                <Icon name="alert" :size="4" class="mt-px" />
                <span>{{ error }}</span>
            </p>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <StatTile
                    v-for="total in TOTALS"
                    :key="total.key"
                    :label="total.label"
                    :value="money(form.totals[total.key])"
                    :numeric="form.totals[total.key]"
                    :tone="total.tone"
                />
            </div>

            <div class="grid gap-4 lg:grid-cols-12">
                <!-- Informations -->
                <AppCard title="Informations" class="lg:col-span-5">
                    <div class="space-y-3">
                        <div>
                            <label class="field-label" for="metre-name">Nom du métré</label>
                            <input
                                id="metre-name"
                                type="text"
                                :value="form.name"
                                :disabled="readOnly"
                                class="block w-full px-2.5 py-1.5"
                                @input="text('name', $event.target.value)"
                                @blur="flushField"
                            />
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="field-label" for="metre-ratio">Ratio par défaut</label>
                                <input
                                    id="metre-ratio"
                                    type="number"
                                    step="any"
                                    :value="form.ratio_markup"
                                    :disabled="readOnly"
                                    class="block w-full px-2.5 py-1.5 text-right tabular-nums"
                                    @input="number('ratio_markup', $event.target.value)"
                                    @blur="flushField"
                                />
                            </div>

                            <div>
                                <label class="field-label" for="metre-language">Langue</label>
                                <select
                                    id="metre-language"
                                    :value="form.language"
                                    :disabled="readOnly"
                                    class="block w-full px-2.5 py-1.5"
                                    @change="edit('language', $event.target.value || null); flushField()"
                                >
                                    <option value="">—</option>
                                    <option v-for="lang in languages" :key="lang" :value="lang">{{ lang }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="field-label" for="metre-agreement">Date accord</label>
                                <input
                                    id="metre-agreement"
                                    type="date"
                                    :value="form.date_agreement"
                                    :disabled="readOnly"
                                    class="block w-full px-2.5 py-1.5"
                                    @change="text('date_agreement', $event.target.value); flushField()"
                                />
                            </div>

                            <!-- MET_Metre::Ratio_c - a calculation, so there is nothing to write.
                                 Volontairement dessiné autrement qu'un champ désactivé : le pointillé
                                 dit « calculé », le gris dirait « momentanément bloqué ». -->
                            <div>
                                <span class="field-label flex items-center gap-1">
                                    Ratio réel
                                    <Icon name="lock" :size="3" class="text-sand-400" />
                                </span>
                                <p
                                    class="readonly-value"
                                    title="Calculé depuis les totaux du métré — non modifiable"
                                >
                                    {{ form.ratio ?? '—' }}
                                </p>
                            </div>
                        </div>

                        <div>
                            <span class="field-label">Statut</span>
                            <div class="grid grid-cols-2 gap-2">
                                <label
                                    class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-[13px] transition-colors"
                                    :class="statusClasses(form.is_accepted_b)"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="form.is_accepted_b"
                                        :disabled="readOnly"
                                        class="size-4"
                                        @change="toggle('is_accepted_b', $event.target.checked)"
                                    />
                                    Accepté
                                </label>

                                <label
                                    class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2 text-[13px] transition-colors"
                                    :class="statusClasses(form.is_status_site_b)"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="form.is_status_site_b"
                                        :disabled="readOnly"
                                        class="size-4"
                                        @change="toggle('is_status_site_b', $event.target.checked)"
                                    />
                                    Site
                                </label>
                            </div>
                        </div>
                    </div>
                </AppCard>

                <!-- Vues du métré : la raison d'être de l'écran, donc la carte la plus en avant. -->
                <AppCard title="Vues du métré" flush class="lg:col-span-4">
                    <ul class="divide-y divide-sand-200/70">
                        <li v-for="view in VIEWS" :key="view.label">
                            <Link
                                v-if="view.href"
                                :href="view.href"
                                class="group flex items-center justify-between gap-2 px-4 py-2.5 text-[13px] text-sand-900 transition-colors hover:bg-accent-100"
                                style="font-variation-settings: 'wght' 550"
                            >
                                <span class="flex min-w-0 items-center gap-2">
                                    <Icon name="table" :size="4" class="text-sand-500" />
                                    <span class="truncate">{{ view.label }}</span>
                                </span>
                                <Icon
                                    name="chevron-right"
                                    :size="4"
                                    class="text-sand-400 transition-transform group-hover:translate-x-0.5"
                                />
                            </Link>

                            <div
                                v-else
                                class="flex items-center justify-between gap-2 px-4 py-2.5 text-[13px] text-sand-500"
                                title="Pas encore disponible"
                            >
                                <span class="flex min-w-0 items-center gap-2">
                                    <Icon name="table" :size="4" class="text-sand-300" />
                                    <span class="truncate">{{ view.label }}</span>
                                </span>
                                <Badge tone="soon">Bientôt</Badge>
                            </div>
                        </li>
                    </ul>
                </AppCard>

                <!-- Actions -->
                <AppCard title="Actions" class="lg:col-span-3">
                    <div class="flex flex-col gap-2">
                        <SecondaryButton
                            v-if="!readOnly"
                            class="btn-block"
                            :disabled="busy || lineCount === 0"
                            :title="lineCount === 0
                                ? 'Ce métré n\'a aucune ligne : il n\'y a rien à offrir.'
                                : 'Crée une offre client dans ShakeDesign, une ligne par taux de TVA'"
                            @click="confirmingOffer = true"
                        >
                            <Icon name="document" :size="4" />
                            Nouvelle offre client
                        </SecondaryButton>

                        <SecondaryButton
                            v-if="!readOnly"
                            class="btn-block"
                            :disabled="busy"
                            @click="duplicate"
                        >
                            <Icon name="copy" :size="4" />
                            Dupliquer le métré
                        </SecondaryButton>

                        <!-- Le verrou. Ce qu'il empêche est dit sous le bouton : un état dont on
                             ne voit pas l'effet se lit comme un écran cassé. -->
                        <SecondaryButton
                            v-if="!readOnly"
                            class="btn-block"
                            :disabled="busy"
                            @click="setLocked(!form.is_locked_b)"
                        >
                            <Icon name="lock" :size="4" />
                            {{ form.is_locked_b ? 'Déverrouiller le métré' : 'Verrouiller le métré' }}
                        </SecondaryButton>

                        <p v-if="form.is_locked_b" class="text-[12px] text-sand-600">
                            Métré verrouillé : ses lignes ne peuvent plus être modifiées.
                        </p>

                        <p v-if="lockError" class="banner banner-danger">
                            <Icon name="alert" :size="4" class="mt-px" />
                            <span>{{ lockError }}</span>
                        </p>

                        <!-- Variante sourde : le rouge plein est gardé pour la confirmation. -->
                        <button
                            v-if="!readOnly"
                            type="button"
                            class="btn btn-danger-quiet btn-block"
                            :disabled="busy"
                            @click="confirmingDelete = true"
                        >
                            <Icon name="trash" :size="4" />
                            Supprimer le métré
                        </button>

                        <p v-if="readOnly" class="text-[13px] text-sand-600">
                            Aucune action disponible : votre compte est en lecture seule.
                        </p>
                    </div>
                </AppCard>

                <!-- Documents -->
                <AppCard title="Documents" flush class="lg:col-span-8">
                    <!-- Grille séparée par le fond plutôt que par `divide-y` : sur deux colonnes,
                         `divide-y` ne trace des filets qu'entre frères successifs et laisse la
                         grille dépareillée. -->
                    <ul class="grid gap-px bg-sand-200 sm:grid-cols-2">
                        <li
                            v-for="document in documents"
                            :key="document.slug"
                            class="bg-white"
                        >
                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-2 px-4 py-2.5 text-left text-[13px] text-sand-800 hover:bg-sand-50 focus:bg-sand-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-sand-950"
                                :title="`Aperçu de « ${document.label} »`"
                                @click="openDocument(document)"
                            >
                                <span class="flex min-w-0 items-center gap-2">
                                    <Icon name="document" :size="4" class="text-sand-400" />
                                    <span class="truncate">{{ document.label }}</span>
                                </span>
                                <Icon name="chevron-right" :size="4" class="shrink-0 text-sand-300" />
                            </button>
                        </li>

                        <!-- Sept documents sur deux colonnes : sans ce bouche-trou, la case
                             manquante laisse voir le fond qui sert de filet et se lit comme un
                             bloc gris posé là par erreur. -->
                        <li
                            v-if="documents.length % 2 === 1"
                            class="hidden bg-white sm:block"
                            aria-hidden="true"
                        />
                    </ul>
                </AppCard>

                <!-- Fournisseurs : le portail des lots de MET_Form. Les lots du projet qui ne
                     portent aucun montant dans ce métré sont omis - la carte est étroite, et un lot
                     à zéro n'apprend rien ici (la page du projet, elle, les liste tous). -->
                <AppCard title="Fournisseur" class="lg:col-span-4">
                    <div v-if="breakdown.lots.length > 0 || breakdown.unassigned_buy > 0" class="flex flex-col gap-3">
                        <!--
                            La disposition de MET_Form, dans son ordre : deux champs en lecture
                            seule (le lot, son fournisseur) et le bouton qui ouvre le popover de
                            choix ; puis, une fois un lot choisi, une croix pour l'effacer, les
                            trois totaux du lot avec le bouton qui mène à ses lignes, et les deux
                            actions. Rien de tout cela n'est affiché grisé d'avance : la source le
                            fait apparaître, et un écran qui montre six commandes mortes se lit
                            comme un écran cassé.
                        -->
                        <div class="flex items-end gap-2">
                            <div class="min-w-0 flex-1">
                                <span class="field-label">Lot</span>
                                <p class="readonly-value truncate text-left" :class="chosenLot ? '' : 'text-sand-400 italic'">
                                    {{ chosenLot ? (chosenLot.name || 'Lot sans nom') : 'Aucun lot sélectionné' }}
                                </p>
                            </div>
                            <div class="min-w-0 flex-1">
                                <span class="field-label">Fournisseur</span>
                                <p class="readonly-value truncate text-left" :class="chosenLot?.company ? '' : 'text-sand-400 italic'">
                                    {{ chosenLot ? (chosenLot.company || 'Aucun fournisseur') : '—' }}
                                </p>
                            </div>

                            <!-- Le choix : un popover, comme la source. La liste des lots est
                                 longue sur un vrai chantier et n'a pas à occuper la carte en
                                 permanence. -->
                            <div class="relative shrink-0">
                                <button
                                    type="button"
                                    class="btn btn-secondary"
                                    :aria-expanded="lotPickerOpen"
                                    @click="lotPickerOpen = !lotPickerOpen"
                                >
                                    <Icon name="search" :size="4" />
                                </button>

                                <div v-if="lotPickerOpen" class="popover absolute right-0 top-full mt-1 w-80" @keydown.escape="lotPickerOpen = false">
                                    <p class="border-b border-sand-200 px-3 pb-1.5 text-[11px] uppercase tracking-[0.06em] text-sand-600">Choisir un lot</p>
                                    <ul class="max-h-72 overflow-y-auto">
                                        <li v-for="lot in breakdown.lots" :key="lot.id">
                                            <button
                                                type="button"
                                                class="flex w-full items-baseline gap-2 px-3 py-1.5 text-left hover:bg-sand-50"
                                                :class="chosenLotId === lot.id ? 'bg-accent-400/25' : ''"
                                                @click="chooseLot(lot)"
                                            >
                                                <span v-if="lot.code !== null" class="code-chip shrink-0">{{ lot.code }}</span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate text-[13px] text-sand-900">{{ lot.name || 'Lot sans nom' }}</span>
                                                    <span class="block truncate text-[11px]" :class="lot.company ? 'text-sand-600' : 'text-sand-400 italic'">
                                                        {{ lot.company || 'Aucun fournisseur' }}
                                                    </span>
                                                </span>
                                                <span class="num shrink-0 text-[11px] text-clay-700">{{ money(lot.buy) }}</span>
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <!-- N'apparaît qu'une fois un lot choisi - HIDE: IsEmpty ( zkg_LOT_Chosen ). -->
                            <button
                                v-if="chosenLot"
                                type="button"
                                class="btn btn-secondary shrink-0"
                                title="Retirer la sélection"
                                @click="chosenLotId = null"
                            >
                                <Icon name="x" :size="4" />
                            </button>
                        </div>

                        <!--
                            Les totaux du lot et les deux actions. La source ne les dessine qu'une
                            fois un lot choisi, et c'est ce qu'un `v-if` faisait ici - mais leur
                            apparition allongeait la carte au moment du clic : la carte Documents,
                            sur la même rangée de la grille, s'étirait avec elle et tout ce qui
                            suit (Offres client, Commentaires) descendait d'un cran. Le bloc est
                            donc TOUJOURS rendu et seulement rendu invisible : la place est
                            réservée d'avance, rien n'est montré, et `visibility: hidden` sort
                            aussi ses commandes du tab order et des clics - elles restent aussi
                            inatteignables qu'absentes, ce qu'un grisage ne donnerait pas.
                            Les deux liens redeviennent des `<span>` faute de lot à désigner :
                            inventer une URL vaudrait moins qu'un lien qui n'en est pas un. Les
                            montants se lisent donc en `chosenLot?.`, `money( null )` rendant « — ».
                        -->
                        <div class="flex flex-col gap-3" :class="chosenLot ? '' : 'invisible'">
                            <!-- Les trois totaux DU LOT dans ce métré, et le bouton qui mène à ses
                                 lignes - MET_LOT_ShowOrder_METL, qui restreint le jeu trouvé à
                                 `zkf_LOT AND zkf_MET`. -->
                            <div class="flex items-end gap-2">
                                <dl class="grid flex-1 grid-cols-3 gap-2 text-center">
                                    <div class="rounded-md bg-clay-50/70 px-2 py-1.5">
                                        <dt class="eyebrow">Achats</dt>
                                        <dd class="num text-[13px] text-sand-900">{{ money(chosenLot?.buy) }}</dd>
                                    </div>
                                    <div class="rounded-md bg-olive-50/70 px-2 py-1.5">
                                        <dt class="eyebrow">Ventes</dt>
                                        <dd class="num text-[13px] text-sand-900">{{ money(chosenLot?.sales) }}</dd>
                                    </div>
                                    <div class="rounded-md bg-mallow-50/70 px-2 py-1.5">
                                        <dt class="eyebrow">Commandé</dt>
                                        <dd class="num text-[13px] text-sand-900">{{ money(chosenLot?.ordered) }}</dd>
                                    </div>
                                </dl>

                                <!-- `is` explicitement lié, jamais pris dans un v-bind étalé -
                                     le piège documenté du lien vers la commande fournisseur. -->
                                <component
                                    :is="chosenLot ? Link : 'span'"
                                    :href="chosenLot ? u(`/metres/${metre.id}/lines/achats-ventes-commandes?lot=${chosenLot.id}`) : null"
                                    class="btn btn-secondary shrink-0"
                                    title="Voir les lignes de ce lot"
                                >
                                    <Icon name="chevron-right" :size="4" />
                                </component>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="btn btn-accent"
                                    :disabled="!chosenLot || readOnly || busy"
                                    :title="orderButtonTitle"
                                    @click="openSupplierOrder"
                                >
                                    Création d'une commande fournisseur
                                </button>
                                <component
                                    :is="chosenLot ? Link : 'span'"
                                    :href="chosenLot ? u(`/lots/${chosenLot.id}/tender-comparison?metre=${metre.id}`) : null"
                                    class="btn btn-secondary"
                                >
                                    Appel d'offres
                                </component>
                            </div>
                        </div>
                    </div>

                    <div v-else class="flex flex-col items-center gap-1.5 py-6 text-center">
                        <Icon name="user" :size="6" class="text-sand-300" />
                        <p class="text-[13px] text-sand-600">Aucun fournisseur lié à ce métré.</p>
                    </div>
                </AppCard>

                <!-- Offres client déjà rattachées à ce métré (OFF_Offers.zkf_MET). Le montant
                     affiché est hors TVA : c'est celui qui se compare au total des ventes du
                     métré, qui ne porte pas de TVA non plus. -->
                <AppCard title="Offres client" class="lg:col-span-12">
                    <p v-if="offers === null" class="banner banner-warning">
                        <Icon name="alert" :size="4" class="mt-px" />
                        <span>ShakeDesign n'a pas répondu : les offres de ce métré n'ont pas pu être lues.</span>
                    </p>

                    <div v-else-if="offers.length === 0" class="flex flex-col items-center gap-1.5 py-6 text-center">
                        <Icon name="document" :size="6" class="text-sand-300" />
                        <p class="text-[13px] text-sand-600">Aucune offre client pour ce métré.</p>
                    </div>

                    <table v-else class="data-table">
                        <thead>
                            <tr>
                                <th class="text-left">Titre</th>
                                <th class="text-left">Date</th>
                                <th class="text-left">Catégorie</th>
                                <th class="text-left">Langue</th>
                                <th class="text-right">Total HTVA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="offer in offers" :key="offer.zkp">
                                <!-- `is` explicitement lié : le compilateur de Vue ne le prend
                                     pas dans un v-bind étalé, et le lien se rendrait en <span>
                                     sans rien signaler. Même piège que sur les vues de lignes. -->
                                <td>
                                    <component :is="offerLink(offer).is" v-bind="offerLink(offer)">{{ offer.title || 'Offre sans titre' }}</component>
                                </td>
                                <td class="num">{{ offer.date || '—' }}</td>
                                <td>{{ offer.category || '—' }}</td>
                                <td>{{ offer.language || '—' }}</td>
                                <td class="num text-right">
                                    {{ offer.total_no_tax === null ? '—' : money(offer.total_no_tax) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>

                </AppCard>

                <!-- Commentaires : rassemblés dans une carte plutôt que posés nus sur le fond,
                     sinon trois zones de texte flottent sans qu'on sache à quoi elles tiennent. -->
                <AppCard title="Commentaires" class="lg:col-span-12">
                    <div class="grid gap-4 lg:grid-cols-3">
                        <div v-for="comment in COMMENTS" :key="comment.key">
                            <label class="field-label" :for="`metre-${comment.key}`">{{ comment.label }}</label>
                            <textarea
                                :id="`metre-${comment.key}`"
                                rows="4"
                                :value="form[comment.key]"
                                :disabled="readOnly"
                                class="block w-full px-2.5 py-1.5"
                                @input="text(comment.key, $event.target.value)"
                                @blur="flushField"
                            />
                        </div>
                    </div>
                </AppCard>
            </div>
        </div>

        <!-- Deleting a métré destroys its lines too, so the count is stated rather than left
             to be discovered afterwards. -->
        <Modal :show="confirmingDelete" max-width="md" @close="confirmingDelete = false">
            <div>
                <header class="surface-head">
                    <h2 class="text-[15px] text-sand-900" style="font-variation-settings: 'wght' 600">Supprimer le métré</h2>
                </header>

                <div class="flex gap-3 p-5">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-danger-100 text-danger-600">
                        <Icon name="alert" :size="5" />
                    </span>

                    <p class="text-[13px] text-sand-700">
                        Supprimer définitivement
                        <span class="text-sand-900" style="font-variation-settings: 'wght' 600">
                            {{ form.name || 'ce métré' }}</span><span v-if="lineCount > 0">
                            et ses {{ lineCount }} ligne{{ lineCount === 1 ? '' : 's' }} de métré</span>
                        ? Cette action est irréversible.
                    </p>
                </div>

                <div class="flex justify-end gap-2 border-t border-sand-200 bg-sand-50 px-5 py-3">
                    <SecondaryButton @click="confirmingDelete = false">Annuler</SecondaryButton>
                    <DangerButton :disabled="busy" @click="destroy">Supprimer</DangerButton>
                </div>
            </div>
        </Modal>
        <!-- Un seul dialogue, comme décidé. Il nomme l'application de destination : on écrit
             chez quelqu'un d'autre, et le geste n'est pas annulable d'ici. -->
        <Modal :show="confirmingOffer" max-width="md" @close="confirmingOffer = false">
            <div class="p-5">
                <h2 class="text-[15px] text-sand-900" style="font-variation-settings: 'wght' 600">
                    Créer une offre client
                </h2>
                <p class="mt-2 text-[13px] text-sand-700">
                    Une offre sera créée dans <strong>ShakeDesign</strong> pour ce métré, avec
                    <strong>une ligne par taux de TVA</strong> : chacune porte le total des ventes
                    de son taux, options exclues.
                </p>
                <p class="mt-2 text-[12px] text-sand-600">
                    Les totaux du métré sont recalculés juste avant, pour que le montant envoyé soit
                    celui de l'écran.
                </p>

                <p v-if="offerError" class="banner banner-danger mt-3">
                    <Icon name="alert" :size="4" class="mt-px" />
                    <span>{{ offerError }}</span>
                </p>

                <div class="mt-5 flex justify-end gap-2">
                    <SecondaryButton :disabled="busy" @click="confirmingOffer = false">Annuler</SecondaryButton>
                    <button type="button" class="btn btn-accent" :disabled="busy" @click="createOffer">
                        Créer l'offre
                    </button>
                </div>
            </div>
        </Modal>

        <!--
            L'aperçu d'un document, qui tient la place de la fenêtre de prévisualisation de
            FileMaker. Le PDF est affiché par le lecteur du navigateur dans un <iframe> ; le
            bouton « Télécharger » pointe la même URL avec ?download=1, donc le même document.

            L'<iframe> n'est monté qu'à l'ouverture (`v-if="preview"`), sinon chaque affichage de
            la page métré lancerait sept rendus PDF côté serveur.
        -->
        <Modal :show="preview !== null" max-width="6xl" @close="preview = null">
            <div v-if="preview" class="flex h-[85vh] flex-col">
                <div class="flex items-center justify-between gap-3 border-b border-sand-200 px-5 py-3">
                    <h2 class="truncate text-[15px] text-sand-900" style="font-variation-settings: 'wght' 600">
                        {{ preview.label }}
                    </h2>
                    <div class="flex shrink-0 items-center gap-2">
                        <a :href="`${preview.url}?download=1`" class="btn btn-accent" download>
                            Télécharger
                        </a>
                        <SecondaryButton @click="preview = null">Fermer</SecondaryButton>
                    </div>
                </div>

                <iframe
                    :src="preview.url"
                    :title="preview.label"
                    class="min-h-0 flex-1 border-0 bg-sand-100"
                />
            </div>
        </Modal>

        <!--
            L'écran de contrôle avant commande - METL_SupplierOrderValidation. Il montre ce qui
            partirait ligne par ligne, parce que le geste écrit chez quelqu'un d'autre et n'est
            pas défaisable d'ici : le compte API de ShakeDesign n'a même pas le droit de supprimer.
        -->
        <Modal :show="orderPreview !== null" max-width="4xl" @close="orderPreview = null">
            <div v-if="orderPreview" class="flex max-h-[80vh] flex-col">
                <header class="surface-head">
                    <h2 class="text-[15px] text-sand-900" style="font-variation-settings: 'wght' 600">
                        Commande fournisseur — {{ orderPreview.lot.name || 'lot sans nom' }}
                    </h2>
                    <p class="mt-0.5 text-[12px] text-sand-600">
                        {{ orderPreview.lot.company || 'Aucun fournisseur assigné' }}
                    </p>
                </header>

                <div class="min-h-0 flex-1 overflow-y-auto p-5">
                    <!-- Les empêchements d'abord, tous ensemble : la source les pose un par un
                         en boîtes successives, si bien qu'on en corrige un pour découvrir le
                         suivant. -->
                    <ul v-if="orderPreview.blockers.length > 0" class="mb-4 flex flex-col gap-1.5">
                        <li v-for="blocker in orderPreview.blockers" :key="blocker.code" class="banner banner-danger">
                            <Icon name="alert" :size="4" class="mt-px" />
                            <span>{{ blocker.message }}</span>
                        </li>
                    </ul>

                    <p v-else class="mb-4 text-[13px] text-sand-700">
                        Une commande sera créée dans <strong>ShakeDesign</strong> pour ce lot, avec
                        <strong>une seule ligne</strong> portant le total commandé — c'est ce que fait
                        la source. Le détail ci-dessous reste dans le métré.
                    </p>

                    <!--
                        Groupé par section puis sous-section, avec un sous-total par groupe :
                        METL_SupplierOrderValidation a les deux mêmes sous-totalisations que la vue
                        Achats/Ventes/Commandes, et une commande se relit par corps de métier.

                        Les quatre colonnes de droite sont modifiables, comme là-bas : c'est ici
                        qu'on ajuste avant d'engager. Elles passent par PATCH /api/metre-lines/{id},
                        la même surface d'écriture que les deux grilles - aucun écran n'a son propre
                        avis sur ce qui est modifiable. Cocher « Opt. » sort la ligne de la commande,
                        ce qui est la façon dont la source exclut un poste.
                    -->
                    <table v-if="orderPreview.lines.length > 0" class="data-table">
                        <thead>
                            <tr>
                                <th class="w-[4.5rem] text-left">Code</th>
                                <th class="text-left">Poste</th>
                                <th class="w-10 text-center">Opt.</th>
                                <th class="w-20 text-left">Unité</th>
                                <th class="w-24 text-right">Qté cdée</th>
                                <th class="w-28 text-right">P.U.</th>
                                <!-- Assez large pour « 10 000 000,00 € » d'un seul tenant : plus
                                     étroite, la colonne repliait le symbole sous le montant. -->
                                <th class="w-36 whitespace-nowrap text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="row in orderRows" :key="row.key">
                                <tr v-if="row.kind === 'ref'" class="bg-sand-100">
                                    <td colspan="6" class="text-sand-900" style="font-variation-settings: 'wght' 650">
                                        <span v-if="row.code !== null" class="mr-1.5">{{ row.code }}</span>{{ row.title }}
                                    </td>
                                    <td class="num whitespace-nowrap text-right text-sand-900" style="font-variation-settings: 'wght' 650">{{ money(row.total) }}</td>
                                </tr>

                                <tr v-else-if="row.kind === 'refs'" class="bg-sand-50">
                                    <td colspan="6" class="pl-4 text-sand-800">
                                        <span v-if="row.code !== null" class="mr-1.5">{{ row.code }}</span>{{ row.title }}
                                    </td>
                                    <td class="num whitespace-nowrap text-right text-sand-800">{{ money(row.total) }}</td>
                                </tr>

                                <tr v-else>
                                    <td class="num text-sand-600">{{ row.line.code || '—' }}</td>
                                    <td>{{ row.line.title || 'Poste sans libellé' }}</td>
                                    <td class="text-center">
                                        <input
                                            type="checkbox"
                                            :checked="row.line.is_option_b"
                                            :disabled="readOnly || busy"
                                            title="Sortir cette ligne de la commande"
                                            @change="editOrderLine(row.line, 'is_option_b', $event.target.checked)"
                                        />
                                    </td>
                                    <td>
                                        <select
                                            :value="row.line.unit"
                                            :disabled="readOnly || busy"
                                            class="cell-input pr-5 focus:bg-white"
                                            @change="editOrderLine(row.line, 'unit', $event.target.value || null)"
                                        >
                                            <option value="" />
                                            <option v-for="u in orderPreview.units" :key="u" :value="u">{{ u }}</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input
                                            type="number" step="any"
                                            :value="row.line.quantity_ordered"
                                            :disabled="readOnly || busy"
                                            class="cell-input text-right focus:bg-white"
                                            @input="editOrderLine(row.line, 'quantity_ordered', numberOrNull($event.target.value))"
                                        />
                                    </td>
                                    <td>
                                        <div class="relative">
                                            <input
                                                type="number" step="any"
                                                :value="row.line.price_ordered"
                                                :disabled="readOnly || busy"
                                                class="cell-input pr-4 text-right tabular-nums focus:bg-white"
                                                @input="editOrderLine(row.line, 'price_ordered', numberOrNull($event.target.value))"
                                            />
                                            <span class="euro-suffix">€</span>
                                        </div>
                                    </td>
                                    <td class="num whitespace-nowrap text-right" :class="row.line.is_option_b ? 'italic text-sand-400' : ''">
                                        {{ money(row.line.total) }}
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" class="text-right uppercase tracking-[0.04em] text-sand-700">Total commandé</td>
                                <td class="num whitespace-nowrap text-right text-sand-950" style="font-variation-settings: 'wght' 650">
                                    {{ money(orderTotal) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <p v-if="orderError" class="banner banner-danger mx-5 mb-3">
                    <Icon name="alert" :size="4" class="mt-px" />
                    <span>{{ orderError }}</span>
                </p>

                <div class="flex justify-end gap-2 border-t border-sand-200 bg-sand-50 px-5 py-3">
                    <SecondaryButton :disabled="busy" @click="orderPreview = null">Annuler</SecondaryButton>
                    <button
                        type="button"
                        class="btn btn-accent"
                        :disabled="busy || orderPreview.blockers.length > 0"
                        @click="createSupplierOrder"
                    >
                        Créer la commande
                    </button>
                </div>
            </div>
        </Modal>

        <Modal :show="orderCreated !== null" max-width="md" @close="orderCreated = null">
            <div v-if="orderCreated" class="p-5">
                <h2 class="text-[15px] text-sand-900" style="font-variation-settings: 'wght' 600">
                    Commande fournisseur créée
                </h2>
                <p class="mt-2 text-[13px] text-sand-700">
                    <template v-if="orderCreated.number">
                        Créée dans ShakeDesign sous la référence
                        <span class="code-chip">{{ orderCreated.number }}</span>.
                    </template>
                    <template v-else>
                        Créée dans ShakeDesign, <strong>sans numéro</strong> : la numérotation
                        n'a pas pu être obtenue. La commande existe et les lignes y sont
                        rattachées ; il lui manque sa référence.
                    </template>
                </p>
                <div class="mt-5 flex justify-end">
                    <SecondaryButton @click="orderCreated = null">Fermer</SecondaryButton>
                </div>
            </div>
        </Modal>

        <Modal :show="offerCreated" max-width="md" @close="offerCreated = false">
            <div class="p-5">
                <h2 class="text-[15px] text-sand-900" style="font-variation-settings: 'wght' 600">
                    Offre créée
                </h2>
                <p class="mt-2 text-[13px] text-sand-700">
                    L'offre a été créée dans ShakeDesign et apparaît dans la liste ci-dessous.
                </p>
                <div class="mt-5 flex justify-end">
                    <SecondaryButton @click="offerCreated = false">Fermer</SecondaryButton>
                </div>
            </div>
        </Modal>

    </AuthenticatedLayout>
</template>
