<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import BrandMark from '@/Components/BrandMark.vue';
import Breadcrumbs from '@/Components/Breadcrumbs.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import Icon from '@/Components/Icon.vue';

/**
 * La barre supérieure, à l'encre de la marque.
 *
 * Un composant à part et non un morceau du layout, parce que la grille « Achats — Ventes —
 * Commandes » occupe tout l'écran et ne passe donc pas par AuthenticatedLayout : elle monte
 * cette même barre elle-même. Sans cela, l'écran le plus utilisé de l'application serait le
 * seul à ne pas porter la marque.
 *
 * Le badge « lecture seule » est ici, et non répété dans chaque page : c'est une propriété du
 * compte, pas de l'écran. Il doit être visible partout, sans quoi un champ désactivé se lit
 * comme une page cassée.
 */
defineProps({
    breadcrumbs: { type: Array, default: () => [] },
});

const page = usePage();
const user = computed(() => page.props.auth?.user ?? {});
const readOnly = computed(() => page.props.auth?.canWrite === false);
</script>

<template>
    <header class="flex h-12 shrink-0 items-center gap-3 bg-sand-950 px-3">
        <Link
            :href="route('dashboard')"
            class="shrink-0 rounded px-1 py-1.5 transition-opacity hover:opacity-80"
            title="Accueil"
        >
            <BrandMark />
        </Link>

        <span class="h-4 w-px shrink-0 bg-white/20" aria-hidden="true" />

        <Breadcrumbs :items="breadcrumbs" class="min-w-0 flex-1" />

        <span
            v-if="readOnly"
            class="badge badge-warning shrink-0"
            title="Votre compte ShakeDesign est en lecture seule : les champs sont désactivés."
        >
            <Icon name="lock" :size="3" />
            Lecture seule
        </span>

        <Dropdown align="right" width="48">
            <template #trigger>
                <button
                    type="button"
                    class="flex shrink-0 items-center gap-1.5 rounded px-2 py-1 text-[13px] text-sand-300 transition-colors hover:bg-white/10 hover:text-white"
                >
                    <Icon name="user" :size="4" />
                    <span class="max-w-[10rem] truncate">{{ user.name }}</span>
                    <Icon name="chevron-down" :size="3.5" />
                </button>
            </template>

            <template #content>
                <div class="border-b border-sand-200 px-3 py-2">
                    <p class="truncate text-[13px] text-sand-900" style="font-variation-settings: 'wght' 550">
                        {{ user.name }}
                    </p>
                    <p class="truncate text-xs text-sand-600">{{ user.email }}</p>
                </div>

                <DropdownLink :href="route('profile.edit')">Profil</DropdownLink>
                <DropdownLink :href="route('logout')" method="post" as="button">
                    Se déconnecter
                </DropdownLink>
            </template>
        </Dropdown>
    </header>
</template>
