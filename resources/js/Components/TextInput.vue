<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <!-- Aucune classe de couleur ni de focus : la couche `base` de app.css habille tous les
         champs de l'application de la même façon, y compris ceux écrits en `<input>` nu dans
         les grilles. Un seul endroit à changer. -->
    <input v-model="model" ref="input" class="px-2.5 py-1.5" />
</template>
