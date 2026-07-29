<script setup>
import { ref } from 'vue';

import AppIcon from '../../../components/AppIcon.vue';
import StatusBadge from '../../../components/StatusBadge.vue';
import { duration, pluralise } from '../../../format';

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
    <div class="space-y-3">
        <!-- 10px inside the panel's 14px, which is what a nested surface needs to
             look concentric rather than pinched. -->
        <section v-for="(steps, host) in stepsByHost" :key="host" class="overflow-hidden rounded-lg border border-line">
            <header class="flex items-center justify-between gap-3 border-b border-line bg-sunken px-4 py-2.5">
                <h3 class="truncate font-mono text-sm font-medium">{{ hostLabel(host, stagingHost) }}</h3>
                <span class="numeric flex-none text-xs text-fg-subtle">{{ pluralise(steps.length, 'step') }}</span>
            </header>

            <ul class="divide-y divide-line">
                <li v-for="step in steps" :key="step.id" class="px-4 py-2">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1.5 text-sm">
                        <!-- Decorative: the badge beside it already carries the
                             status as text, and an `aria-label` on a bare span is
                             not reliably announced anyway. -->
                        <span class="w-4 text-center font-mono text-fg-subtle" aria-hidden="true">{{ step.icon }}</span>
                        <span class="font-medium">{{ step.label }}</span>
                        <StatusBadge :label="step.status" :tone="step.status_tone" bare />
                        <span class="numeric text-xs text-fg-subtle">{{ duration(step.elapsed) }}</span>

                        <button
                            v-if="step.log || step.command"
                            type="button"
                            class="ms-auto inline-flex min-h-6 items-center gap-1 rounded-sm px-1.5 text-xs text-fg-subtle hover:text-fg"
                            :aria-expanded="isExpanded(step.id)"
                            @click="toggle(step.id)"
                        >
                            {{ isExpanded(step.id) ? 'Hide' : 'Show' }} detail
                            <AppIcon
                                name="chevron-down"
                                class="size-3 motion-safe:transition-transform motion-safe:duration-150"
                                :class="isExpanded(step.id) ? 'rotate-180' : ''"
                            />
                        </button>
                    </div>

                    <div v-if="isExpanded(step.id)" class="mt-2.5 space-y-2.5">
                        <div v-if="step.command">
                            <p class="label-caps">Command</p>
                            <pre class="terminal mt-1 p-3">{{ step.command }}</pre>
                        </div>
                        <div v-if="step.log">
                            <p class="label-caps">Log</p>
                            <pre class="terminal mt-1 max-h-80 p-3">{{ step.log }}</pre>
                        </div>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
