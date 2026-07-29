<script setup>
import { computed, useId } from 'vue';

import AppIcon from './AppIcon.vue';

/**
 * Label, control, hint and error for one field.
 *
 * The control is wrapped by the label, so it is named without anything having to
 * be wired up. What does have to be wired up is the rest of it: the hint and the
 * error are only reachable by a screen reader if the control points at them, and
 * a red border is not an error message. The slot hands the caller the exact
 * attributes for that:
 *
 *   <FormField v-slot="field" label="Core ref" :error="errors.core_ref?.[0]">
 *     <input v-model="form.core_ref" class="input-control" v-bind="field" />
 *   </FormField>
 *
 * `field` carries `id`, `aria-describedby` and `aria-invalid`, so a field either
 * announces its own error or the binding is visibly missing from the markup.
 */
const props = defineProps({
    label: { type: String, required: true },
    hint: { type: String, default: null },
    /** The message the server sent for this field. */
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
    /** Set for a field whose label is worth hiding but whose name is not. */
    labelHidden: { type: Boolean, default: false },
});

const uid = useId();

const hintId = computed(() => `${uid}-hint`);
const errorId = computed(() => `${uid}-error`);

const describedBy = computed(
    () =>
        [props.hint ? hintId.value : null, props.error ? errorId.value : null].filter(Boolean).join(' ') ||
        undefined,
);

/** Spread onto the control inside the slot. */
const field = computed(() => ({
    id: uid,
    'aria-describedby': describedBy.value,
    'aria-invalid': props.error ? 'true' : undefined,
}));
</script>

<template>
    <!--
        A real `for`/`id` pair rather than a label wrapped round the whole field.
        Wrapping is tempting because it needs no ids, but the accessible name of an
        implicitly labelled control is *all* the text inside the label — so the hint
        and the error below get read out as part of the field's name, and "Core ref"
        is announced as "Core ref (required) Leave blank to use REL1_46". The hint
        and the error belong on `aria-describedby`, which is what `field` carries.
    -->
    <div>
        <label
            :for="uid"
            class="flex items-center gap-1 text-sm font-medium text-fg"
            :class="labelHidden ? 'sr-only' : ''"
        >
            {{ label }}
            <!-- The asterisk is decoration; the word is what gets announced, and
                 it is what someone who cannot pick a thin red glyph out of a
                 label reads too. -->
            <template v-if="required">
                <span aria-hidden="true" class="text-danger-text">*</span>
                <span class="sr-only">(required)</span>
            </template>
        </label>

        <div class="mt-1.5">
            <slot v-bind="field" />
        </div>

        <p v-if="hint" :id="hintId" class="mt-1.5 text-xs text-pretty text-fg-subtle">
            {{ hint }}
        </p>
        <p
            v-if="error"
            :id="errorId"
            class="mt-1.5 flex items-start gap-1.5 text-xs text-pretty text-danger-text"
        >
            <AppIcon name="error" class="mt-0.5 size-3.5 shrink-0" />
            {{ error }}
        </p>
    </div>
</template>
