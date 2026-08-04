<script setup>
defineProps({
    value: { type: String },
});
</script>

<template>
    <!-- `.field-label` : le même surtitre 11 px capitales que les libellés des cartes, pour
         qu'un champ de modale et un champ de la page métré se ressemblent. -->
    <label class="field-label">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>
