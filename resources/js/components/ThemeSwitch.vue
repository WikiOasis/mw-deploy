<script setup>
import { onUnmounted, ref } from 'vue';

import AppIcon from './AppIcon.vue';
import { onThemeChange, setPreference } from '../theme';

/**
 * System / light / dark, as three buttons rather than one that cycles.
 *
 * A cycling toggle hides which of the three you are on, and "system" is
 * meaningfully different from whichever appearance it currently resolves to —
 * you cannot tell them apart from a single sun icon.
 */
const OPTIONS = [
    { value: 'system', icon: 'monitor', label: 'Match the system appearance' },
    { value: 'light', icon: 'sun', label: 'Light appearance' },
    { value: 'dark', icon: 'moon', label: 'Dark appearance' },
];

const current = ref('system');

const stop = onThemeChange((chosen) => {
    current.value = chosen;
});

onUnmounted(stop);
</script>

<template>
    <!-- Concentric: 10px outer radius less the 2px inset leaves 8px inside. -->
    <div
        role="group"
        aria-label="Colour theme"
        class="inline-flex rounded-lg border border-line bg-sunken p-0.5"
    >
        <button
            v-for="option in OPTIONS"
            :key="option.value"
            type="button"
            class="inline-flex size-8 items-center justify-center rounded-md motion-safe:transition-[background-color,color] motion-safe:duration-150"
            :class="
                current === option.value
                    ? 'bg-surface text-fg shadow-panel'
                    : 'text-fg-subtle hover:text-fg'
            "
            :aria-pressed="current === option.value"
            :aria-label="option.label"
            @click="setPreference(option.value)"
        >
            <AppIcon :name="option.icon" class="size-4" />
        </button>
    </div>
</template>
