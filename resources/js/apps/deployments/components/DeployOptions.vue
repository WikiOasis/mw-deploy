<script setup>
import { computed } from 'vue';

/**
 * Target and rollout options, shared by the deploy and undeploy wizards. These are
 * the CLI's --servers / --parallel / --rollout / --l10n / --force, with the same
 * meanings.
 */
const props = defineProps({
    /** { stagingOnly, allServers, servers[], rollout, l10n, force, parallel } */
    modelValue: { type: Object, required: true },
    appservers: { type: Array, required: true },
    proxies: { type: Array, required: true },
    intent: { type: String, default: 'deploy' },
    can: { type: Object, required: true },
    maxParallel: { type: Number, default: 8 },
});

const emit = defineEmits(['update:modelValue']);

const isUndeploy = computed(() => props.intent === 'undeploy');

const set = (changes) => emit('update:modelValue', { ...props.modelValue, ...changes });

const toggleServer = (hostname) => {
    const servers = props.modelValue.servers.includes(hostname)
        ? props.modelValue.servers.filter((entry) => entry !== hostname)
        : [...props.modelValue.servers, hostname];

    set({ servers });
};
</script>

<template>
    <div class="space-y-5">
        <label class="flex items-start gap-2 text-sm">
            <input
                type="checkbox"
                class="mt-1 rounded border-slate-300"
                :checked="modelValue.stagingOnly"
                :disabled="!can.target_production"
                @change="set({ stagingOnly: $event.target.checked })"
            />
            <span>
                <span class="font-medium">Staging only</span>
                <span class="block text-xs text-slate-500">
                    <template v-if="isUndeploy">
                        Remove from the staging tree only, leaving the appservers untouched.
                    </template>
                    <template v-else>
                        Prepare and validate on staging without touching any appserver.
                    </template>
                    <template v-if="!can.target_production">
                        You only have permission for staging-only deployments, so this is fixed on.
                    </template>
                </span>
            </span>
        </label>

        <div v-if="!modelValue.stagingOnly" class="space-y-5">
            <div>
                <p class="text-sm font-medium text-slate-700">Appservers</p>
                <label class="mt-1 flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        class="rounded border-slate-300"
                        :checked="modelValue.allServers"
                        @change="set({ allServers: $event.target.checked, servers: [] })"
                    />
                    All active appservers ({{ appservers.length }})
                </label>

                <ul v-if="!modelValue.allServers" class="mt-2 grid gap-1 sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="server in appservers" :key="server.id">
                        <label class="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                class="rounded border-slate-300"
                                :checked="modelValue.servers.includes(server.hostname)"
                                @change="toggleServer(server.hostname)"
                            />
                            <code class="font-mono text-xs">{{ server.hostname }}</code>
                        </label>
                    </li>
                </ul>

                <p v-if="appservers.length === 0" class="mt-1 text-xs text-rose-600">
                    No active appservers are registered. Add them under Targets first.
                </p>
            </div>

            <label class="flex items-start gap-2 text-sm">
                <input
                    type="checkbox"
                    class="mt-1 rounded border-slate-300"
                    :checked="modelValue.rollout"
                    @change="set({ rollout: $event.target.checked })"
                />
                <span>
                    <span class="font-medium">Rollout (depool / repool)</span>
                    <span class="block text-xs text-slate-500">
                        Depool each server from all {{ proxies.length }} registered proxy/proxies before
                        touching it, and repool afterwards.
                    </span>
                </span>
            </label>

            <label class="block w-40">
                <span class="block text-sm font-medium text-slate-700">Parallelism</span>
                <input
                    type="number"
                    min="1"
                    :max="maxParallel"
                    :value="modelValue.parallel"
                    class="mt-1 block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300"
                    @input="set({ parallel: Number($event.target.value) || 1 })"
                />
                <span class="mt-1 block text-xs text-slate-500">Servers updated at once.</span>
            </label>
        </div>

        <label v-if="!isUndeploy" class="flex items-start gap-2 text-sm">
            <input
                type="checkbox"
                class="mt-1 rounded border-slate-300"
                :checked="modelValue.l10n"
                @change="set({ l10n: $event.target.checked })"
            />
            <span>
                <span class="font-medium">Rebuild l10n cache</span>
                <span class="block text-xs text-slate-500">Runs on staging and on each appserver.</span>
            </span>
        </label>

        <label
            v-if="can.force"
            class="flex items-start gap-2 rounded-md border border-rose-200 bg-rose-50 p-3 text-sm"
        >
            <input
                type="checkbox"
                class="mt-1 rounded border-rose-300"
                :checked="modelValue.force"
                @change="set({ force: $event.target.checked })"
            />
            <span>
                <span class="font-medium text-rose-900">Force — ignore canary failures</span>
                <span class="block text-xs text-rose-800">
                    The deployment will not stop or prompt when a canary check fails, and no automatic
                    rollback will be enqueued. This is the most dangerous option in this app.
                </span>
            </span>
        </label>
    </div>
</template>
