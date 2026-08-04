<script setup>
import Icon from '@/Components/Icon.vue';

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
                class="pointer-events-auto rounded-lg border border-danger-200 bg-white px-3 py-2 shadow-pop"
                role="status"
            >
                <div class="flex items-start gap-2">
                    <Icon name="alert" :size="4" class="mt-px text-danger-500" />
                    <div class="min-w-0 flex-1">
                        <p class="text-[13px] text-sand-900" style="font-variation-settings: 'wght' 550">
                            Ligne {{ toast.rowLabel }} — modification annulée
                        </p>
                        <p class="mt-0.5 truncate text-xs text-sand-600" :title="toast.message">
                            {{ toast.message }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="btn btn-ghost btn-sm shrink-0 px-1 py-0.5"
                        aria-label="Fermer"
                        @click="$emit('dismiss', toast.id)"
                    >
                        <Icon name="x" :size="3.5" />
                    </button>
                </div>
            </div>
        </TransitionGroup>
    </div>
</template>
