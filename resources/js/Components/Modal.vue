<script setup>
import { computed, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: '2xl',
    },
    closeable: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['close']);
const dialog = ref();
const showSlot = ref(props.show);

/**
 * How many Modal instances currently hold the page-scroll lock.
 *
 * Module-level rather than per-instance because modals nest: a confirmation opened from
 * inside another modal, when closed, must not release a lock the still-open outer modal
 * depends on. Only the last one out restores scrolling.
 */
let scrollLocks = 0;
const holdsScrollLock = ref(false);

function lockScroll() {
    if (holdsScrollLock.value) {
        return;
    }

    holdsScrollLock.value = true;
    scrollLocks++;
    document.body.style.overflow = 'hidden';
}

function releaseScroll() {
    if (! holdsScrollLock.value) {
        return;
    }

    holdsScrollLock.value = false;
    scrollLocks = Math.max(0, scrollLocks - 1);

    if (scrollLocks === 0) {
        document.body.style.overflow = '';
    }
}

watch(
    () => props.show,
    () => {
        if (props.show) {
            lockScroll();
            showSlot.value = true;

            dialog.value?.showModal();
        } else {
            releaseScroll();

            setTimeout(() => {
                dialog.value?.close();
                showSlot.value = false;
            }, 200);
        }
    },
);

const close = () => {
    if (props.closeable) {
        emit('close');
    }
};

/**
 * Escape is handled through the dialog's own `cancel` event rather than a document-level
 * keydown listener. That is what makes nesting work: `cancel` fires only on the topmost
 * dialog in the top layer, so closing a confirmation does not also close the modal that
 * opened it - a document listener fired on every mounted Modal at once and closed them all.
 *
 * preventDefault() stops the browser closing the dialog behind Vue's back; `show` remains
 * the single source of truth, and a non-closeable modal correctly ignores Escape entirely.
 */
const onCancel = (event) => {
    event.preventDefault();
    close();
};

onUnmounted(() => releaseScroll());

const maxWidthClass = computed(() => {
    return {
        sm: 'sm:max-w-sm',
        md: 'sm:max-w-md',
        lg: 'sm:max-w-lg',
        xl: 'sm:max-w-xl',
        '2xl': 'sm:max-w-2xl',
        // Wider tiers, for modals holding a row-per-record table rather than a form.
        '4xl': 'sm:max-w-4xl',
        '6xl': 'sm:max-w-6xl',
        '7xl': 'sm:max-w-7xl',
    }[props.maxWidth];
});
</script>

<template>
    <dialog
        class="z-50 m-0 min-h-full min-w-full overflow-y-auto bg-transparent backdrop:bg-transparent"
        ref="dialog"
        @cancel="onCancel"
    >
        <div
            class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:px-0"
            scroll-region
        >
            <Transition
                enter-active-class="ease-out duration-300"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="ease-in duration-200"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-show="show"
                    class="fixed inset-0 transform transition-all"
                    @click="close"
                >
                    <div
                        class="absolute inset-0 bg-gray-500 opacity-75"
                    />
                </div>
            </Transition>

            <Transition
                enter-active-class="ease-out duration-300"
                enter-from-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                enter-to-class="opacity-100 translate-y-0 sm:scale-100"
                leave-active-class="ease-in duration-200"
                leave-from-class="opacity-100 translate-y-0 sm:scale-100"
                leave-to-class="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
            >
                <div
                    v-show="show"
                    class="relative z-10 mb-6 transform overflow-hidden rounded-lg bg-white shadow-xl transition-all sm:mx-auto sm:w-full"
                    :class="maxWidthClass"
                >
                    <slot v-if="showSlot" />
                </div>
            </Transition>
        </div>
    </dialog>
</template>
