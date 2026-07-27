<script setup>
/**
 * The review step: every Salt call that will run, in order, grouped by phase.
 *
 * This screen is the reason the wizard has three steps instead of one. A
 * deployment shells out to `salt` dozens of times across the fleet; the operator
 * gets to read the sequence first. The calls come from the same DeploymentPlanner
 * the runner uses, so this is not an approximation of what will happen.
 */
defineProps({
    plan: { type: Object, required: true },
});
</script>

<template>
    <div class="space-y-5">
        <div
            v-if="plan.removes_anything"
            class="rounded-md border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-900"
        >
            <p class="font-medium">This deployment removes files from the fleet.</p>
            <p class="mt-1 text-xs">
                Each removal is an <code class="font-mono">rm -rf</code> of the checkout's directory, run once per
                host. It is reversible — rolling this deployment back clones the removed checkouts and restores
                their refs — but the wikis will be missing that code until you do.
            </p>
        </div>

        <div>
            <h3 class="text-sm font-medium text-slate-700">Line items</h3>
            <ul class="mt-1 divide-y divide-slate-100 rounded-md border border-slate-200 text-sm">
                <li v-for="item in plan.items" :key="item.checkout_id" class="flex flex-wrap gap-2 px-3 py-2">
                    <span
                        class="rounded px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset"
                        :class="
                            item.action === 'undeploy'
                                ? 'bg-orange-100 text-orange-900 ring-orange-300'
                                : 'bg-sky-100 text-sky-800 ring-sky-300'
                        "
                    >
                        {{ item.action }}
                    </span>
                    <span class="font-medium">{{ item.name }}</span>
                    <code class="font-mono text-xs text-slate-500">{{ item.path }}</code>
                    <span v-if="item.ref_value" class="ml-auto font-mono text-xs">→ {{ item.ref_value }}</span>
                </li>
            </ul>
        </div>

        <div v-if="plan.patches.length > 0">
            <h3 class="text-sm font-medium text-slate-700">Patches to apply</h3>
            <ul class="mt-1 space-y-1 text-sm">
                <li v-for="patch in plan.patches" :key="patch.id">
                    {{ patch.name }}
                    <code class="ml-1 font-mono text-xs text-slate-500">{{ patch.target_path }}</code>
                </li>
            </ul>
        </div>

        <!-- Registered patches for these checkouts that were not ticked. A patch
             silently dropped because someone forgot it is a wiki that comes back
             up without a fix it had yesterday. -->
        <div
            v-if="plan.unselected_patches.length > 0"
            class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
        >
            <p class="font-medium">
                {{ plan.unselected_patches.length }} registered patch(es) for these checkouts are not selected.
            </p>
            <ul class="mt-1 list-disc space-y-0.5 pl-5 text-xs">
                <li v-for="patch in plan.unselected_patches" :key="patch.id">
                    {{ patch.name }} — {{ patch.target_label }}
                </li>
            </ul>
            <p class="mt-1 text-xs">
                Deploying without them will overwrite the patched files with upstream's version.
            </p>
        </div>

        <div>
            <div class="flex items-baseline justify-between">
                <h3 class="text-sm font-medium text-slate-700">Salt calls</h3>
                <span class="text-xs text-slate-500">{{ plan.call_count }} call(s)</span>
            </div>

            <div class="mt-1 space-y-3">
                <section v-for="(calls, phase) in plan.phases" :key="phase">
                    <h4 class="text-xs font-semibold tracking-wide text-slate-500 uppercase">{{ phase }}</h4>
                    <ol class="mt-1 space-y-1">
                        <li
                            v-for="(call, index) in calls"
                            :key="`${phase}-${index}`"
                            class="rounded border border-slate-100 bg-slate-50 px-3 py-2"
                        >
                            <div class="flex flex-wrap items-baseline gap-2 text-sm">
                                <code class="font-mono text-xs text-slate-500">{{ call.target }}</code>
                                <span>{{ call.label }}</span>
                            </div>
                            <pre class="mt-1 overflow-x-auto font-mono text-xs text-slate-600">{{ call.command }}</pre>
                        </li>
                    </ol>
                </section>
            </div>
        </div>

        <div>
            <h3 class="text-sm font-medium text-slate-700">Options</h3>
            <ul class="mt-1 flex flex-wrap gap-2 text-xs">
                <li
                    v-for="flag in plan.option_flags"
                    :key="flag"
                    class="rounded bg-slate-100 px-2 py-0.5 text-slate-700"
                >
                    {{ flag }}
                </li>
            </ul>
        </div>
    </div>
</template>
