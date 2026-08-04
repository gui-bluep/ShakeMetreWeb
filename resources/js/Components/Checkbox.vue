<script setup>
import { computed } from 'vue';

const emit = defineEmits(['update:checked']);

const props = defineProps({
    checked: {
        type: [Array, Boolean],
        required: true,
    },
    value: {
        default: null,
    },
});

const proxyChecked = computed({
    get() {
        return props.checked;
    },

    set(val) {
        emit('update:checked', val);
    },
});
</script>

<template>
    <!-- Couleurs et focus viennent de la couche `base` : case à l'encre, coche blanche. -->
    <input type="checkbox" :value="value" v-model="proxyChecked" class="size-4" />
</template>
