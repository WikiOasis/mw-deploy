<script setup>
import { computed } from 'vue';

/**
 * The console's icons.
 *
 * One set, drawn on one 24px grid at one stroke weight, so nothing on a surface
 * ever looks like it came from somewhere else. They take their colour from
 * `currentColor` and their states from CSS — there is no second asset for hover
 * or for the dark theme.
 *
 * Icons here are decorative by default: they sit next to a label that already
 * says the thing. Where one has to stand on its own, the button around it carries
 * the accessible name and this stays hidden from the tree.
 */
const props = defineProps({
    name: { type: String, required: true },
    /** Set when the icon is the only content and nothing else names it. */
    label: { type: String, default: null },
    /**
     * In real pixels, matched to the weight of the text beside it: 1.5 next to
     * regular and medium, 2 next to semibold.
     *
     * `non-scaling-stroke` below is what makes that literal. Without it the
     * width is in viewBox units, so the same icon drawn at 12px would render a
     * 0.75px hairline and at 20px a 1.25px one — the two would not look like
     * they belong to the same set, which is the whole point of having one.
     */
    strokeWidth: { type: [Number, String], default: 1.5 },
});
const PATHS = {
    check: 'm4.5 12.75 6 6 9-13.5',
    close: 'M6 18 18 6M6 6l12 12',
    plus: 'M12 4.5v15m7.5-7.5h-15',
    minus: 'M5.25 12h13.5',
    'chevron-down': 'm19.5 8.25-7.5 7.5-7.5-7.5',
    'chevron-right': 'm8.25 4.5 7.5 7.5-7.5 7.5',
    'arrow-left': 'M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18',
    'arrow-uturn-left': 'M9 15 3 9m0 0 6-6M3 9h10.5a6 6 0 0 1 0 12H9',
    search: 'm21 21-5.197-5.197m2.197-5.303a7.5 7.5 0 1 1-15 0 7.5 7.5 0 0 1 15 0Z',
    info: 'M11.25 11.25h1.5v5.25M12 7.5h.008M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    warning: 'M12 9.75v3.75m0 3h.008M10.7 3.94 2.7 17.06A1.5 1.5 0 0 0 4 19.31h16a1.5 1.5 0 0 0 1.3-2.25L13.3 3.94a1.5 1.5 0 0 0-2.6 0Z',
    error: 'm9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    clock: 'M12 6.75v5.25l3.75 2.25M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    lock: 'M16.5 10.5V6.75a4.5 4.5 0 0 0-9 0v3.75M6.75 21h10.5a2.25 2.25 0 0 0 2.25-2.25v-6a2.25 2.25 0 0 0-2.25-2.25H6.75A2.25 2.25 0 0 0 4.5 12.75v6A2.25 2.25 0 0 0 6.75 21Z',
    shield: 'm9 12.75 2.25 2.25 4.5-5.25M12 3.75c-2.3 1.4-4.9 2.25-7.5 2.25v5.25c0 4.5 3 8.4 7.5 9.75 4.5-1.35 7.5-5.25 7.5-9.75V6A15 15 0 0 1 12 3.75Z',
    grid: 'M4.5 6.75A2.25 2.25 0 0 1 6.75 4.5h1.5a2.25 2.25 0 0 1 2.25 2.25v1.5A2.25 2.25 0 0 1 8.25 10.5h-1.5A2.25 2.25 0 0 1 4.5 8.25v-1.5Zm9 0A2.25 2.25 0 0 1 15.75 4.5h1.5a2.25 2.25 0 0 1 2.25 2.25v1.5a2.25 2.25 0 0 1-2.25 2.25h-1.5a2.25 2.25 0 0 1-2.25-2.25v-1.5Zm-9 9A2.25 2.25 0 0 1 6.75 13.5h1.5a2.25 2.25 0 0 1 2.25 2.25v1.5a2.25 2.25 0 0 1-2.25 2.25h-1.5a2.25 2.25 0 0 1-2.25-2.25v-1.5Zm9 0A2.25 2.25 0 0 1 15.75 13.5h1.5a2.25 2.25 0 0 1 2.25 2.25v1.5a2.25 2.25 0 0 1-2.25 2.25h-1.5a2.25 2.25 0 0 1-2.25-2.25v-1.5Z',
    users: 'M15 19.128a9.4 9.4 0 0 0 2.625.372 9 9 0 0 0 3.712-.777 3 3 0 0 0-5.865-1.11M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.3 12.3 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.169M4.5 9.75a3 3 0 1 1 6 0 3 3 0 0 1-6 0Zm10.5 1.5a2.25 2.25 0 1 1 4.5 0 2.25 2.25 0 0 1-4.5 0Z',
    refresh: 'M16.023 9.348h4.992V4.356m0 4.992-3.181-3.183a8.25 8.25 0 0 0-13.803 3.7M4.031 9.865v4.99m0 0h4.99m-4.99 0 3.18 3.185a8.25 8.25 0 0 0 13.804-3.7',
    sun: 'M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z',
    moon: 'M21.752 15.002A9.72 9.72 0 0 1 18 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 0 0 3 11.25c0 5.385 4.365 9.75 9.75 9.75 4.096 0 7.6-2.527 9.002-6.098Z',
    monitor: 'M9 17.25v1.007a3 3 0 0 1-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0 1 15 18.257V17.25m6-12V15a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 15V5.25A2.25 2.25 0 0 1 5.25 3h13.5A2.25 2.25 0 0 1 21 5.25Z',
    logout: 'M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l3 3m0 0-3 3m3-3H2.25',
    server: 'M5.25 14.25h13.5m-13.5 0a2.25 2.25 0 0 1-2.25-2.25V6a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 6v6a2.25 2.25 0 0 1-2.25 2.25m-13.5 0V18a2.25 2.25 0 0 0 2.25 2.25h9A2.25 2.25 0 0 0 18.75 18v-3.75M6.75 9h.008v.008H6.75V9Zm0 8.25h.008v.008H6.75v-.008Z',
};

const path = computed(() => PATHS[props.name] ?? '');
</script>

<template>
    <svg
        v-if="name === 'spinner'"
        class="animate-spin"
        viewBox="0 0 24 24"
        fill="none"
        :aria-hidden="label === null ? 'true' : undefined"
        :aria-label="label ?? undefined"
        :role="label === null ? undefined : 'img'"
    >
        <circle
            cx="12"
            cy="12"
            r="9"
            stroke="currentColor"
            stroke-opacity="0.25"
            :stroke-width="strokeWidth"
            vector-effect="non-scaling-stroke"
        />
        <path
            d="M21 12a9 9 0 0 0-9-9"
            stroke="currentColor"
            :stroke-width="strokeWidth"
            stroke-linecap="round"
            vector-effect="non-scaling-stroke"
        />
    </svg>

    <svg
        v-else
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        :stroke-width="strokeWidth"
        stroke-linecap="round"
        stroke-linejoin="round"
        :aria-hidden="label === null ? 'true' : undefined"
        :aria-label="label ?? undefined"
        :role="label === null ? undefined : 'img'"
    >
        <!-- `vector-effect` does not inherit, so it belongs on the shape. -->
        <path :d="path" vector-effect="non-scaling-stroke" />
    </svg>
</template>
