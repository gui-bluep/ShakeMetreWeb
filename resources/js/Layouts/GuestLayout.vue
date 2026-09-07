<script setup>
import BrandMark from '@/Components/BrandMark.vue';
import { Link } from '@inertiajs/vue3';
import { u } from '@/basePath';

/**
 * Les écrans hors session (connexion, mot de passe oublié).
 *
 * Même DA que l'application, mais la marque est ici l'élément principal plutôt qu'un repère
 * dans une barre : c'est le premier écran, et c'est lui qui annonce dans quel outil on entre.
 */
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center gap-6 bg-sand-100 px-4 py-10">
        <Link :href="u('/')" class="rounded transition-opacity hover:opacity-80">
            <BrandMark tone="light" size="hero" />
        </Link>

        <div class="w-full max-w-md">
            <div class="surface overflow-hidden shadow-raised">
                <!-- Filet lime en tête de carte : la signature de la marque, sur le seul écran
                     où il y a de la place pour un geste graphique. -->
                <div class="h-1 bg-accent-400" aria-hidden="true" />
                <div class="px-6 py-6">
                    <slot />
                </div>
            </div>
        </div>
    </div>
</template>
