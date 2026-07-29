<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRouter } from 'vue-router';

import { ApiError, api, endpoint } from '../../../api';
import AppButton from '../../../components/AppButton.vue';
import AppIcon from '../../../components/AppIcon.vue';
import CardPanel from '../../../components/CardPanel.vue';
import LoadState from '../../../components/LoadState.vue';
import RepoFileBrowser from '../components/RepoFileBrowser.vue';
import StatusBadge from '../../../components/StatusBadge.vue';
import { relative, shortRef } from '../../../format';
import { flash, flashError } from '../../../store';

/**
 * One repository: its checkouts across versions, what the tree says about them, and
 * the branches and commits available to deploy.
 */
const props = defineProps({
    id: { type: [String, Number], required: true },
});

const router = useRouter();

const data = ref(null);
const loading = ref(true);
const error = ref(null);
const busy = ref(false);

const load = async () => {
    loading.value = true;

    try {
        data.value = await api.get(endpoint(`repositories/${props.id}`));
        error.value = null;
    } catch (thrown) {
        if (thrown instanceof ApiError) {
            error.value = thrown;
        }
    } finally {
        loading.value = false;
    }
};

onMounted(load);

const repository = computed(() => data.value?.data ?? null);

/**
 * Any present checkout can browse the tree — they all share one remote, same
 * reasoning the backend already uses to pick a readable checkout for refs().
 */
const browsableCheckouts = computed(() => (repository.value?.checkouts ?? []).filter((checkout) => checkout.present));

const browseCheckoutId = ref('');

/** The three grants, as a list, so the panel renders them the same way each time. */
const abilities = computed(() => [
    { label: 'Deploy this repository', granted: repository.value?.can.deploy === true },
    { label: 'Undeploy this repository', granted: repository.value?.can.undeploy === true },
    { label: 'Manage the registry entry', granted: repository.value?.can.manage === true },
]);

watch(browsableCheckouts, (checkouts) => {
    if (browseCheckoutId.value === '' && checkouts.length > 0) {
        browseCheckoutId.value = String(checkouts[0].id);
    }
});


const deactivate = async () => {
    busy.value = true;

    try {
        const payload = await api.delete(endpoint(`repositories/${props.id}`));

        flash(payload.message);
        router.push('/deployments/repositories');
    } catch (thrown) {
        flashError(thrown);
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <LoadState :loading="loading" :error="error" @retry="load">
        <div v-if="repository" class="space-y-6">
            <header>
                <AppButton to="/deployments/repositories" variant="ghost" icon="arrow-left" class="-ms-3 mb-2">
                    Repositories
                </AppButton>

                <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <h1 class="text-xl font-semibold break-all">{{ repository.name }}</h1>
                        <span
                            class="rounded-full border border-line-strong bg-sunken px-2 py-0.5 text-xs text-fg-muted"
                        >
                            {{ repository.type_label }}
                        </span>
                        <span
                            v-if="repository.imported"
                            class="rounded-full border border-line-strong bg-sunken px-2 py-0.5 text-xs text-fg-muted"
                        >
                            Imported {{ relative(repository.discovered_at) }}
                        </span>
                    </div>

                    <div v-if="repository.can.manage" class="flex flex-wrap items-center gap-2 text-sm">
                        <RouterLink :to="`/deployments/repositories/${repository.id}/edit`" class="link-quiet">
                            Edit
                        </RouterLink>
                        <button
                            type="button"
                            class="inline-flex min-h-8 items-center rounded-md px-2 text-danger-text hover:bg-danger-surface disabled:opacity-50"
                            :disabled="busy"
                            @click="deactivate"
                        >
                            Deactivate
                        </button>
                    </div>
                </div>
            </header>

            <div class="grid gap-6 lg:grid-cols-3">
                <CardPanel title="Registry" class="lg:col-span-2">
                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <dt class="label-caps">Remote</dt>
                            <dd class="font-mono text-xs break-all">{{ repository.git_url }}</dd>
                        </div>
                        <div>
                            <dt class="label-caps">Default branch</dt>
                            <dd class="font-mono text-xs">{{ repository.default_branch }}</dd>
                        </div>
                        <div>
                            <dt class="label-caps">In use by the farm</dt>
                            <dd>{{ repository.in_use ? 'Yes' : 'No' }}</dd>
                        </div>
                        <div v-if="repository.manifest?.version">
                            <dt class="label-caps">Declared version</dt>
                            <dd class="font-mono text-xs">{{ repository.manifest.version }}</dd>
                        </div>
                        <div v-if="repository.manifest?.['license-name']">
                            <dt class="label-caps">Licence</dt>
                            <dd>{{ repository.manifest['license-name'] }}</dd>
                        </div>
                        <div v-if="repository.manifest?.requires_mediawiki">
                            <dt class="label-caps">Requires MediaWiki</dt>
                            <dd class="font-mono text-xs">{{ repository.manifest.requires_mediawiki }}</dd>
                        </div>
                    </dl>
                </CardPanel>

                <CardPanel title="What you may do">
                    <!-- Colour, an icon and the word: whether you may deploy this
                         repository is not something to convey with a green tick
                         alone. -->
                    <ul class="space-y-2 text-sm">
                        <li
                            v-for="ability in abilities"
                            :key="ability.label"
                            class="flex items-start gap-2"
                            :class="ability.granted ? 'text-fg' : 'text-fg-subtle'"
                        >
                            <AppIcon
                                :name="ability.granted ? 'check' : 'close'"
                                class="mt-0.5 size-4 shrink-0"
                                :class="ability.granted ? 'text-success-text' : 'text-fg-faint'"
                            />
                            <span>
                                {{ ability.label }}
                                <span class="sr-only">— {{ ability.granted ? 'allowed' : 'not allowed' }}</span>
                            </span>
                        </li>
                    </ul>
                    <p v-if="repository.scoped" class="mt-4 border-t border-line pt-3 text-xs text-pretty text-fg-muted">
                        This repository is permission-scoped: only the listed users and roles may act on it, whatever
                        their coarse
                        <code class="font-mono">deploy.{{ repository.type }}</code>
                        grant says.
                    </p>
                </CardPanel>
            </div>

            <CardPanel title="Checkouts" subtitle="One per core version, each with its own pin" flush>
                <table class="w-full text-sm">
                    <thead class="label-caps border-b border-line text-start">
                        <tr>
                            <th class="px-5 py-2">Version</th>
                            <th class="px-5 py-2">Status</th>
                            <th class="px-5 py-2">Pin</th>
                            <th class="px-5 py-2">On disk</th>
                            <th class="px-5 py-2">Path</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="checkout in repository.checkouts ?? []" :key="checkout.id" class="align-top">
                            <td class="px-5 py-2 font-medium">{{ checkout.version_label }}</td>
                            <td class="px-5 py-2">
                                <StatusBadge :label="checkout.status_label" :tone="checkout.status_tone" />
                            </td>
                            <td class="px-5 py-2">
                                <code class="font-mono text-xs">{{ checkout.resolved_ref ?? '—' }}</code>
                                <span class="block text-xs text-fg-subtle">{{ checkout.ref_mode_summary }}</span>
                            </td>
                            <td class="px-5 py-2">
                                <template v-if="checkout.observed_at">
                                    <code class="font-mono text-xs" :class="checkout.has_ref_drift ? 'text-warning-text' : ''">
                                        {{ shortRef(checkout.observed_ref) }}
                                    </code>
                                    <span class="block text-xs text-fg-subtle">{{ relative(checkout.observed_at) }}</span>
                                    <span v-if="checkout.has_ref_drift" class="block text-xs text-warning-text">
                                        drifted from the pin
                                    </span>
                                </template>
                                <span v-else class="text-xs text-fg-subtle">not scanned</span>
                            </td>
                            <td class="px-5 py-2 font-mono text-xs text-fg-subtle">{{ checkout.path }}</td>
                        </tr>
                        <tr v-if="(repository.checkouts ?? []).length === 0">
                            <td colspan="5" class="px-5 py-4 text-fg-subtle">
                                No checkouts. This repository is registered but not checked out anywhere.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </CardPanel>

            <div class="grid gap-6 lg:grid-cols-2">
                <CardPanel
                    title="Branches"
                    :subtitle="
                        data.refs.available
                            ? 'From the clone on staging.'
                            : 'Ref discovery is turned off in this install, so refs are free text in the wizard.'
                    "
                >
                    <p v-if="data.refs.branches.length === 0" class="text-sm text-fg-subtle">
                        Nothing to list — no checkout of this repository is on disk to read refs from.
                    </p>
                    <ul v-else class="max-h-72 space-y-1 overflow-auto text-sm">
                        <li v-for="ref in data.refs.branches" :key="ref.value" class="flex flex-wrap gap-2">
                            <code class="font-mono text-xs">{{ ref.value }}</code>
                            <span v-if="ref.is_default" class="text-xs text-success-text">default</span>
                            <span class="ms-auto text-xs text-fg-subtle">{{ ref.date }}</span>
                        </li>
                    </ul>
                </CardPanel>

                <CardPanel title="Recent commits" subtitle="On the checkout's current branch">
                    <p v-if="data.refs.commits.length === 0" class="text-sm text-fg-subtle">Nothing to list.</p>
                    <ul v-else class="max-h-72 space-y-2 overflow-auto text-sm">
                        <li v-for="ref in data.refs.commits" :key="ref.value">
                            <code class="font-mono text-xs">{{ ref.short }}</code>
                            <span class="ms-1">{{ ref.subject }}</span>
                            <span class="block text-xs text-fg-subtle">{{ ref.author }} · {{ ref.date }}</span>
                        </li>
                    </ul>
                </CardPanel>
            </div>

            <CardPanel
                title="Browse files"
                subtitle="Read-only exploration of a checkout's tree at any branch, tag or commit — not part of deploying."
            >
                <p v-if="browsableCheckouts.length === 0" class="text-sm text-fg-subtle">
                    No present checkout to browse. Deploy this repository somewhere first.
                </p>
                <template v-else>
                    <div v-if="browsableCheckouts.length > 1" class="mb-3 flex flex-wrap gap-2">
                        <button
                            v-for="checkout in browsableCheckouts"
                            :key="checkout.id"
                            type="button"
                            class="rounded border px-2 py-0.5 text-xs font-medium"
                            :class="
                                String(checkout.id) === browseCheckoutId
                                    ? 'border-accent bg-accent text-accent-fg'
                                    : 'border-line-strong text-fg-muted'
                            "
                            @click="browseCheckoutId = String(checkout.id)"
                        >
                            {{ checkout.version_label }}
                        </button>
                    </div>

                    <RepoFileBrowser
                        v-if="browseCheckoutId"
                        :key="browseCheckoutId"
                        :checkout-id="browseCheckoutId"
                        :branches="data.refs.branches"
                        :default-ref="
                            browsableCheckouts.find((checkout) => String(checkout.id) === browseCheckoutId)
                                ?.resolved_ref ?? ''
                        "
                    />
                </template>
            </CardPanel>
        </div>
    </LoadState>
</template>
