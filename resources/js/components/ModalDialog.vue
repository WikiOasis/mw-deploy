<script setup>
import { onMounted, onUnmounted } from 'vue';

/**
 * A modal for the handful of actions that need confirming in place: cutting a
 * version, removing one, answering a canary prompt.
 *
 * Escape closes it and the backdrop does not, on purpose — every dialog in this
 * app is a decision, and dismissing one by clicking slightly off-target is not a
 * mistake worth allowing.
 */
const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    danger: { type: Boolean, default: false },
    wide: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const onKeydown = (event) => {
    if (event.key === 'Escape') {
        emit('close');
    }
};

onMounted(() => {
    document.addEventListener('keydown', onKeydown);
    document.body.classList.add('overflow-hidden');
});

onUnmounted(() => {
    document.removeEventListener('keydown', onKeydown);
    document.body.classList.remove('overflow-hidden');
});
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/40 p-4 sm:p-8">
        <div
            class="w-full rounded-lg bg-white shadow-xl"
            :class="[props.wide ? 'max-w-3xl' : 'max-w-xl', props.danger ? 'ring-2 ring-rose-300' : '']"
            role="dialog"
            aria-modal="true"
        >
            <header class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-3">
                <div>
                    <h2 class="font-semibold tracking-tight" :class="props.danger ? 'text-rose-900' : ''">
                        {{ title }}
                    </h2>
                    <p v-if="subtitle" class="mt-0.5 text-sm text-slate-500">{{ subtitle }}</p>
                </div>
                <button
                    type="button"
                    class="rounded px-2 text-slate-400 hover:text-slate-700"
                    aria-label="Close"
                    @click="emit('close')"
                >
                    ✕
                </button>
            </header>

            <div class="px-5 py-4">
                <slot />
            </div>

            <footer v-if="$slots.footer" class="flex flex-wrap justify-end gap-2 border-t border-slate-200 px-5 py-3">
                <slot name="footer" />
            </footer>
        </div>
    </div>
</template>
