<script setup>
import AppButton from './AppButton.vue';

/**
 * Previous / next for the paginated tables.
 *
 * Both screens that page had the same twenty lines of markup, and they had drifted
 * apart by a hairline; one component means the history and repository tables page
 * identically.
 *
 * The buttons use native `disabled` at the ends of the range, which is the case it
 * is meant for: there is genuinely no previous page from page one, and no wording
 * would make one appear.
 */
defineProps({
    /** Laravel's pagination `meta`: current_page, last_page, total. */
    meta: { type: Object, required: true },
    /** What is being counted, for the summary line. */
    unit: { type: String, default: 'result' },
});

const emit = defineEmits(['go']);
</script>

<template>
    <nav v-if="meta.last_page > 1" class="flex items-center justify-between gap-3" aria-label="Pagination">
        <AppButton
            icon="arrow-left"
            :disabled="meta.current_page <= 1"
            @click="emit('go', meta.current_page - 1)"
        >
            Previous
        </AppButton>

        <p class="numeric text-center text-xs text-fg-subtle">
            Page {{ meta.current_page }} of {{ meta.last_page }}
            <span class="max-sm:hidden">· {{ meta.total }} {{ meta.total === 1 ? unit : `${unit}s` }}</span>
        </p>

        <AppButton
            trailing-icon="chevron-right"
            :disabled="meta.current_page >= meta.last_page"
            @click="emit('go', meta.current_page + 1)"
        >
            Next
        </AppButton>
    </nav>
</template>
