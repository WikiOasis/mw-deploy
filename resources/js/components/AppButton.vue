<script setup>
import { computed, useSlots } from 'vue';
import { RouterLink } from 'vue-router';

import AppIcon from './AppIcon.vue';

/**
 * Every button and button-shaped link in the console.
 *
 * The visual styles live in resources/css/app.css as `btn-*` utilities rather
 * than here, because the server-rendered sign-in pages need the same buttons and
 * do not load this bundle.
 *
 * `loading` is the one piece of behaviour worth centralising: it disables the
 * control and shows a spinner while keeping the label, which is what stops a
 * queued deploy from being submitted twice without the label changing under the
 * cursor mid-click.
 */
const props = defineProps({
    variant: {
        type: String,
        default: 'secondary',
        validator: (value) => ['primary', 'secondary', 'ghost', 'danger', 'danger-quiet'].includes(value),
    },
    /** Router destination; makes this a RouterLink. */
    to: { type: [String, Object], default: null },
    /** External or server-rendered destination; makes this an anchor. */
    href: { type: String, default: null },
    type: { type: String, default: 'button' },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    /** Icon-only: square, and `label` becomes the accessible name. */
    icon: { type: String, default: null },
    label: { type: String, default: null },
    /** Trailing icon beside a text label. */
    trailingIcon: { type: String, default: null },
});

const tag = computed(() => {
    if (props.to !== null) {
        return RouterLink;
    }

    return props.href !== null ? 'a' : 'button';
});

const slots = useSlots();

/** Square only when the icon really is the whole button. */
const iconOnly = computed(() => props.icon !== null && slots.default === undefined);

const classes = computed(() => ['btn', `btn-${props.variant}`, iconOnly.value ? 'btn-icon' : '']);
</script>

<template>
    <component
        :is="tag"
        :to="to ?? undefined"
        :href="href ?? undefined"
        :type="tag === 'button' ? type : undefined"
        :class="classes"
        :disabled="tag === 'button' && (disabled || loading) ? true : undefined"
        :aria-disabled="tag !== 'button' && (disabled || loading) ? 'true' : undefined"
        :aria-label="iconOnly ? label : undefined"
        :aria-busy="loading ? 'true' : undefined"
    >
        <AppIcon v-if="loading" name="spinner" class="size-4" />
        <AppIcon v-else-if="icon" :name="icon" class="size-4" />

        <slot />

        <AppIcon v-if="trailingIcon && !loading" :name="trailingIcon" class="size-4" />
    </component>
</template>
