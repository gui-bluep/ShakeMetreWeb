<script setup>
import Icon from '@/Components/Icon.vue';

defineProps({
    message: { type: String },
});
</script>

<template>
    <!-- Une icône avec le message : sur un formulaire dense, une ligne rouge seule se confond
         avec une aide de saisie. -->
    <div v-show="message" class="flex items-start gap-1.5 text-[13px] text-danger-600">
        <Icon name="alert" :size="3.5" class="mt-0.5" />
        <p>{{ message }}</p>
    </div>
</template>
