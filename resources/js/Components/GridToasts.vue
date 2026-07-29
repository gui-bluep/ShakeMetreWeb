<script setup>
/**
 * Non-blocking failure notices, stacked bottom-right. Deliberately not a modal: a save
 * failure must never interrupt typing in the grid.
 */
defineProps({
    toasts: { type: Array, required: true },
});

defineEmits(['dismiss']);
</script>

<template>
    <div class="pointer-events-none fixed bottom-4 right-4 z-50 flex w-80 flex-col gap-2" aria-live="polite">
        <TransitionGroup
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="translate-y-1 opacity-0"
            leave-active-class="transition duration-100 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="pointer-events-auto rounded-md border border-red-200 bg-white px-3 py-2 shadow-sm"
                role="status"
            >
                <div class="flex items-start gap-2">
                    <span class="mt-0.5 size-2 shrink-0 rounded-full bg-red-500" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-medium text-gray-900">
                            Ligne {{ toast.rowLabel }} — modification annulée
                        </p>
                        <p class="mt-0.5 truncate text-xs text-gray-500" :title="toast.message">
                            {{ toast.message }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 text-gray-400 transition hover:text-gray-600"
                        aria-label="Fermer"
                        @click="$emit('dismiss', toast.id)"
                    >
                        &times;
                    </button>
                </div>
            </div>
        </TransitionGroup>
    </div>
</template>
