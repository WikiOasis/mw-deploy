<script setup>
import { computed, nextTick, ref, useId, watch } from 'vue';

import AppIcon from './AppIcon.vue';

/**
 * A type-to-filter input backed by a listbox, replacing the native `<select>`
 * and bare text `<input>` the ref picker used to use. Free text is preserved:
 * typing something that matches no option still updates modelValue, which is
 * what lets a pasted SHA or a not-yet-listed branch name through.
 *
 * Options are `{ value, label, ...rest }`; `rest` is exposed to the `option`
 * slot so callers (e.g. the commit list) can render author/date alongside the
 * label without this component knowing what those fields mean.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    options: { type: Array, required: true },
    placeholder: { type: String, default: 'Search…' },
    /** Shown when nothing matches the typed text. */
    emptyLabel: { type: String, default: 'No matches' },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

/**
 * Per-instance ids. The option ids used to be `combobox-option-<index>`, which is
 * fine until a screen renders more than one of these — the ref picker renders one
 * per repository — and then every instance's `aria-activedescendant` points at the
 * first instance's option.
 */
const uid = useId();
const listId = `${uid}-listbox`;
const optionId = (index) => `${uid}-option-${index}`;

const open = ref(false);
const query = ref(props.modelValue ?? '');
const highlighted = ref(-1);
const inputEl = ref(null);
const listEl = ref(null);

watch(
    () => props.modelValue,
    (value) => {
        if (value !== query.value) {
            query.value = value ?? '';
        }
    },
);

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (needle === '') {
        return props.options;
    }

    return props.options.filter(
        (option) =>
            option.value.toLowerCase().includes(needle) || (option.label ?? '').toLowerCase().includes(needle),
    );
});

const commit = (value) => {
    query.value = value;
    emit('update:modelValue', value);
};

const select = (option) => {
    commit(option.value);
    open.value = false;
    highlighted.value = -1;
};

const onInput = (event) => {
    commit(event.target.value);
    open.value = true;
    highlighted.value = -1;
};

const show = () => {
    open.value = true;
};

const close = () => {
    // A short delay lets a click on an option register before the listbox
    // unmounts out from under it.
    setTimeout(() => {
        open.value = false;
        highlighted.value = -1;
    }, 150);
};

const move = (delta) => {
    if (!open.value) {
        open.value = true;

        return;
    }

    const count = filtered.value.length;

    if (count === 0) {
        return;
    }

    highlighted.value = (highlighted.value + delta + count) % count;

    nextTick(() => {
        listEl.value
            ?.querySelector('[data-highlighted="true"]')
            ?.scrollIntoView({ block: 'nearest', behavior: 'instant' });
    });
};

const chooseHighlighted = () => {
    if (open.value && highlighted.value >= 0 && filtered.value[highlighted.value]) {
        select(filtered.value[highlighted.value]);
    } else {
        open.value = false;
    }
};

const escape = () => {
    open.value = false;
    highlighted.value = -1;
    inputEl.value?.blur();
};

defineExpose({ focus: () => inputEl.value?.focus() });
</script>

<template>
    <div class="relative">
        <input
            ref="inputEl"
            type="text"
            role="combobox"
            aria-autocomplete="list"
            :aria-expanded="open"
            :aria-controls="listId"
            :aria-activedescendant="highlighted >= 0 ? optionId(highlighted) : undefined"
            :value="query"
            :placeholder="placeholder"
            class="input-control block w-full font-mono"
            @input="onInput"
            @focus="show"
            @blur="close"
            @keydown.down.prevent="move(1)"
            @keydown.up.prevent="move(-1)"
            @keydown.enter.prevent="chooseHighlighted"
            @keydown.esc="escape"
        />

        <ul
            v-if="open"
            :id="listId"
            ref="listEl"
            role="listbox"
            class="absolute z-20 mt-1.5 max-h-64 w-full overflow-auto overscroll-contain rounded-lg border border-line bg-raised py-1 shadow-raised"
        >
            <li v-if="loading" class="flex items-center gap-2 px-3 py-2 text-xs text-fg-subtle">
                <AppIcon name="spinner" class="size-3.5" />
                Loading…
            </li>
            <li v-else-if="filtered.length === 0" class="px-3 py-2 text-xs text-fg-subtle">{{ emptyLabel }}</li>
            <li
                v-for="(option, index) in filtered"
                :id="optionId(index)"
                :key="option.value"
                role="option"
                :aria-selected="option.value === modelValue"
                :data-highlighted="index === highlighted"
                class="cursor-pointer px-3 py-1.5 text-sm"
                :class="index === highlighted ? 'bg-accent text-accent-fg' : 'text-fg hover:bg-sunken'"
                @mousedown.prevent="select(option)"
                @mouseenter="highlighted = index"
            >
                <slot name="option" :option="option">
                    <span class="font-mono text-xs">{{ option.label ?? option.value }}</span>
                </slot>
            </li>
        </ul>
    </div>
</template>
