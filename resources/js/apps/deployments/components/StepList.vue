<script setup>
import { ref } from 'vue';

import { duration } from '../../../format';
import StatusBadge from './StatusBadge.vue';

/**
 * The per-host step list — what the curses UI's scrolling output was, except it
 * keeps the whole history and the exact argv of every call.
 *
 * Logs and commands are collapsed by default. During a rollout across a dozen
 * appservers the useful signal is which host is where; the rsync file list is
 * what you open when something has already gone wrong.
 */
defineProps({
    stepsByHost: { type: Object, required: true },
    stagingHost: { type: String, default: null },
});

const expanded = ref(new Set());

const toggle = (id) => {
    const next = new Set(expanded.value);

    next.has(id) ? next.delete(id) : next.add(id);
    expanded.value = next;
};

const isExpanded = (id) => expanded.value.has(id);

const hostLabel = (host, stagingHost) => (host === stagingHost ? `Staging — ${host}` : host);
</script>

<template>
    <div class="space-y-4">
        <section v-for="(steps, host) in stepsByHost" :key="host" class="rounded-md border border-slate-200">
            <header class="flex items-center justify-between border-b border-slate-100 bg-slate-50 px-4 py-2">
                <h3 class="font-mono text-sm font-medium">{{ hostLabel(host, stagingHost) }}</h3>
                <span class="text-xs text-slate-500">{{ steps.length }} step(s)</span>
            </header>

            <ul class="divide-y divide-slate-100">
                <li v-for="step in steps" :key="step.id" class="px-4 py-2">
                    <div class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="w-4 text-center font-mono" :aria-label="step.status">{{ step.icon }}</span>
                        <span class="font-medium">{{ step.label }}</span>
                        <StatusBadge :label="step.status" :classes="step.status_classes" />
                        <span class="text-xs text-slate-500">{{ duration(step.elapsed) }}</span>

                        <button
                            v-if="step.log || step.command"
                            type="button"
                            class="ml-auto text-xs text-slate-500 underline hover:text-slate-900"
                            @click="toggle(step.id)"
                        >
                            {{ isExpanded(step.id) ? 'Hide' : 'Show' }} detail
                        </button>
                    </div>

                    <div v-if="isExpanded(step.id)" class="mt-2 space-y-2">
                        <div v-if="step.command">
                            <p class="text-xs font-medium text-slate-500">Command</p>
                            <pre class="mt-1 overflow-x-auto rounded bg-slate-900 p-3 font-mono text-xs text-slate-100">{{ step.command }}</pre>
                        </div>
                        <div v-if="step.log">
                            <p class="text-xs font-medium text-slate-500">Log</p>
                            <pre class="mt-1 max-h-80 overflow-auto rounded bg-slate-50 p-3 font-mono text-xs text-slate-700">{{ step.log }}</pre>
                        </div>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
