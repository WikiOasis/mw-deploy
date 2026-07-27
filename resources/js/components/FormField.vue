<script setup>
/**
 * Label, control, hint and error for one field. `error` takes the message the
 * server sent for this field, so validation reads the same in the SPA as it did
 * on the server-rendered forms.
 */
defineProps({
    label: { type: String, required: true },
    hint: { type: String, default: null },
    error: { type: String, default: null },
    required: { type: Boolean, default: false },
});
</script>

<template>
    <label class="block">
        <span class="block text-sm font-medium text-slate-700">
            {{ label }}
            <span v-if="required" class="text-rose-600">*</span>
        </span>

        <div class="mt-1">
            <slot />
        </div>

        <span v-if="hint" class="mt-1 block text-xs text-slate-500">{{ hint }}</span>
        <span v-if="error" class="mt-1 block text-xs text-rose-600">{{ error }}</span>
    </label>
</template>
