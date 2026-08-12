<script setup>
import { computed, ref, watch } from 'vue';

import AppButton from '../../../components/AppButton.vue';
import FormField from '../../../components/FormField.vue';
import { pluralise } from '../../../format';

/**
 * Selects every checkout of one type in one core version, in one go.
 *
 * This exists for the upgrade case, which is the one where the per-repository
 * rows below are unusable: reconstructing 1.46 means putting a hundred-odd
 * extensions on REL1_46, and ticking them one at a time is both tedious and the
 * kind of thing you get wrong on row eighty-three. Naming the type, the version
 * and the branch once is the same statement an operator already makes out loud.
 *
 * It only ever *sets* rows in the selection the picker already owns — nothing is
 * hidden behind this control. Every checkout it ticks appears below with its own
 * ref, editable individually, and the review step still lists every Salt call.
 */
const props = defineProps({
    repositories: { type: Array, required: true },
    types: { type: Array, required: true },
    intent: { type: String, default: 'deploy' },
    /** { [checkoutId]: { refType, refValue } }, read-only here. */
    selection: { type: Object, required: true },
});

const emit = defineEmits(['apply', 'clear']);

const isUndeploy = computed(() => props.intent === 'undeploy');

/**
 * A version's worth of one type is the unit; "every version" is offered as well.
 * The sentinel is a string so it cannot collide with a real id — nor with the
 * `null` that config's unversioned checkouts legitimately carry.
 */
const ALL_VERSIONS = '*';

const type = ref('');
// Settled by the watch below to the newest version this type is in, which is the
// one an upgrade is building out.
const versionId = ref(null);
const refValue = ref('');

const checkoutsOfType = computed(() =>
    props.repositories
        .filter((repository) => repository.type === type.value)
        .flatMap((repository) => repository.checkouts),
);

/** Only types that actually have checkouts on offer, so no dead options. */
const availableTypes = computed(() =>
    props.types.filter((entry) =>
        props.repositories.some(
            (repository) => repository.type === entry.value && repository.checkouts.length > 0,
        ),
    ),
);

/**
 * Versions this type is checked out in, newest first. Derived from the offered
 * checkouts rather than the version list so a version the operator may not touch
 * — or one this type is not in — is never selectable here.
 */
const availableVersions = computed(() => {
    const seen = new Map();

    checkoutsOfType.value.forEach((checkout) => {
        const key = checkout.version_id ?? 'unversioned';

        if (!seen.has(key)) {
            seen.set(key, { id: checkout.version_id ?? null, label: checkout.version_label });
        }
    });

    return [...seen.values()].sort((a, b) =>
        String(b.label).localeCompare(String(a.label), undefined, { numeric: true }),
    );
});

// Extensions are what an upgrade is mostly made of, so start there when they are
// on offer.
watch(
    availableTypes,
    (entries) => {
        if (entries.length === 0) {
            type.value = '';

            return;
        }

        if (!entries.some((entry) => entry.value === type.value)) {
            type.value = (entries.find((entry) => entry.value === 'extension') ?? entries[0]).value;
        }
    },
    { immediate: true },
);

// "Every version" is an explicit choice, so switching type keeps it rather than
// silently narrowing back to one version.
watch(
    availableVersions,
    (versions) => {
        if (versionId.value === ALL_VERSIONS) {
            return;
        }

        if (!versions.some((version) => version.id === versionId.value)) {
            versionId.value = versions[0]?.id ?? null;
        }
    },
    { immediate: true },
);

/** The checkouts the current three answers name. */
const matched = computed(() =>
    checkoutsOfType.value.filter(
        (checkout) =>
            versionId.value === ALL_VERSIONS || (checkout.version_id ?? null) === versionId.value,
    ),
);

const alreadySelected = computed(
    () =>
        matched.value.filter((checkout) =>
            Object.prototype.hasOwnProperty.call(props.selection, checkout.id),
        ).length,
);

const typeLabel = computed(
    () => availableTypes.value.find((entry) => entry.value === type.value)?.plural_label ?? 'Checkouts',
);

const versionLabel = computed(
    () => availableVersions.value.find((version) => version.id === versionId.value)?.label ?? null,
);

/** "Extensions in 1.46", or "Skins in every version" — what the buttons act on. */
const scopeLabel = computed(
    () =>
        `${typeLabel.value} in ${
            versionId.value === ALL_VERSIONS ? 'every version' : (versionLabel.value ?? 'no version')
        }`,
);

/** What the buttons would act on, spelled out beside them. */
const summary = computed(() => {
    const scope = `${scopeLabel.value}: ${pluralise(matched.value.length, 'checkout')}`;

    return alreadySelected.value === 0
        ? `${scope}.`
        : `${scope}, ${alreadySelected.value} already selected — selecting again overwrites their refs.`;
});

/**
 * The release branch the chosen version is named after, as a placeholder only —
 * suggesting REL1_46 for 1.46 saves the typing without deciding anything, and a
 * repository that does not carry that branch would fail loudly at clone time.
 */
const refPlaceholder = computed(() =>
    /^\d+\.\d+$/.test(String(versionLabel.value))
        ? `REL${String(versionLabel.value).replace('.', '_')}`
        : 'branch or SHA',
);

/**
 * What the ref field would give each row. Blank means "each checkout's own pin",
 * which is the honest default — a floating checkout has no pin, and inventing one
 * for it here would deploy something nobody named.
 */
const trimmedRef = computed(() => refValue.value.trim());

const unpinned = computed(() =>
    trimmedRef.value === '' && !isUndeploy.value
        ? matched.value.filter((checkout) => !checkout.resolved_ref).length
        : 0,
);

const apply = () => {
    emit('apply', { checkouts: matched.value, refValue: trimmedRef.value });
};

const clear = () => {
    emit('clear', { checkouts: matched.value });
};
</script>

<template>
    <div v-if="availableTypes.length > 0" class="rounded-md border border-line bg-sunken p-3">
        <!-- Named for what it does — it selects rows; the deploy still happens at
             the end of the wizard, after the plan has been read. -->
        <p class="text-sm font-medium">Select a whole type at once</p>
        <p class="mt-1 max-w-prose text-xs text-pretty text-fg-subtle">
            <template v-if="isUndeploy">
                Ticks every checkout of one type in one core version — how you strip a version back before
                removing it.
            </template>
            <template v-else>
                Ticks every checkout of one type in one core version and puts them all on one ref — the
                shape of a core upgrade. Each row stays editable below.
            </template>
        </p>

        <div class="mt-3 flex flex-wrap items-end gap-3">
            <FormField v-slot="field" label="Type" class="w-44">
                <select v-bind="field" v-model="type" class="input-control block w-full">
                    <option v-for="entry in availableTypes" :key="entry.value" :value="entry.value">
                        {{ entry.plural_label }}
                    </option>
                </select>
            </FormField>

            <FormField v-slot="field" label="Core version" class="w-44">
                <select v-bind="field" v-model="versionId" class="input-control block w-full">
                    <option
                        v-for="version in availableVersions"
                        :key="version.id ?? 'unversioned'"
                        :value="version.id"
                    >
                        {{ version.label }}
                    </option>
                    <option v-if="availableVersions.length > 1" :value="ALL_VERSIONS">Every version</option>
                </select>
            </FormField>

            <FormField
                v-if="!isUndeploy"
                v-slot="field"
                label="Ref for all of them"
                hint="Blank keeps each checkout's own pin."
                class="w-56"
            >
                <input
                    v-bind="field"
                    v-model="refValue"
                    type="text"
                    class="input-control block w-full font-mono"
                    :placeholder="refPlaceholder"
                />
            </FormField>

            <div class="flex flex-wrap items-center gap-2">
                <AppButton :disabled="matched.length === 0" @click="apply">
                    Select {{ pluralise(matched.length, 'checkout') }}
                </AppButton>
                <AppButton variant="ghost" :disabled="alreadySelected === 0" @click="clear">
                    Clear them
                </AppButton>
            </div>
        </div>

        <p class="numeric mt-2 text-xs text-fg-subtle">{{ summary }}</p>

        <!-- A floating checkout has no pin to fall back on, so a blank ref would
             leave it with nothing to deploy and the server would refuse the lot. -->
        <p v-if="unpinned > 0" class="mt-2 text-xs text-warning-text">
            {{ pluralise(unpinned, 'of these checkouts has', 'of these checkouts have') }} no pinned ref.
            Give a ref above, or set theirs individually below.
        </p>
    </div>
</template>
