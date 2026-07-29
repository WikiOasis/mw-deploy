<script setup>
import { computed } from 'vue';

import AppIcon from './AppIcon.vue';

/**
 * A status pill.
 *
 * The server sends a *tone* — `success`, `danger`, `warning`, `info`, `neutral` —
 * from the same enum methods the Blade views used, and the mapping from tone to
 * colour lives here. It used to send Tailwind classes directly, which meant the
 * palette was half in PHP and could only ever describe one appearance.
 *
 * Every tone carries an icon as well as a colour, because "failed" and "done" have
 * to be tellable apart by someone who cannot separate red from green — and, on the
 * day it matters, by someone reading a screenshot in a chat window.
 */
const props = defineProps({
    label: { type: String, required: true },
    tone: {
        type: String,
        default: 'neutral',
        validator: (value) => ['neutral', 'info', 'success', 'warning', 'danger'].includes(value),
    },
    /** Drops the icon where the row already carries one, as in the step list. */
    bare: { type: Boolean, default: false },
});

const TONES = {
    neutral: { classes: 'bg-sunken text-fg-muted border-line-strong', icon: 'minus' },
    info: { classes: 'bg-info-surface text-info-text border-info-line', icon: 'clock' },
    success: { classes: 'bg-success-surface text-success-text border-success-line', icon: 'check' },
    warning: { classes: 'bg-warning-surface text-warning-text border-warning-line', icon: 'warning' },
    danger: { classes: 'bg-danger-surface text-danger-text border-danger-line', icon: 'error' },
};

const tone = computed(() => TONES[props.tone] ?? TONES.neutral);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="tone.classes"
    >
        <AppIcon v-if="!bare" :name="tone.icon" class="size-3.5 shrink-0" />
        {{ label }}
    </span>
</template>
