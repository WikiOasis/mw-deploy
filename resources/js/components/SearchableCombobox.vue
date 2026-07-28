<script setup>
import { computed, nextTick, ref, watch } from 'vue';

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
        listEl.value?.querySelector('[data-highlighted="true"]')?.scrollIntoView({ block: 'nearest' });
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
            aria-expanded="open"
            :aria-activedescendant="highlighted >= 0 ? `combobox-option-${highlighted}` : undefined"
            :value="query"
            :placeholder="placeholder"
            class="block w-full rounded-md bg-white px-3 py-2 font-mono text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-slate-900 focus:outline-none"
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
            ref="listEl"
            role="listbox"
            class="absolute z-10 mt-1 max-h-64 w-full overflow-auto rounded-md border border-slate-200 bg-white py-1 shadow-lg"
        >
            <li v-if="loading" class="px-3 py-1.5 text-xs text-slate-500">Loading…</li>
            <li v-else-if="filtered.length === 0" class="px-3 py-1.5 text-xs text-slate-500">{{ emptyLabel }}</li>
            <li
                v-for="(option, index) in filtered"
                :id="`combobox-option-${index}`"
                :key="option.value"
                role="option"
                :aria-selected="option.value === modelValue"
                :data-highlighted="index === highlighted"
                class="cursor-pointer px-3 py-1.5 text-sm"
                :class="index === highlighted ? 'bg-slate-900 text-white' : 'hover:bg-slate-50'"
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
