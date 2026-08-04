<script setup>
defineProps({
    type: { type: String, default: 'submit' },
});
</script>

<template>
    <!-- Les variantes vivent dans app.css : un bouton écrit directement `class="btn btn-primary"`
         dans un gabarit dense est alors strictement le même que celui-ci. -->
    <button :type="type" class="btn btn-primary">
        <slot />
    </button>
</template>
