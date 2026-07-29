<script setup>
/**
 * The review step: every Salt call that will run, in order, grouped by phase.
 *
 * This screen is the reason the wizard has three steps instead of one. A
 * deployment shells out to `salt` dozens of times across the fleet; the operator
 * gets to read the sequence first. The calls come from the same DeploymentPlanner
 * the runner uses, so this is not an approximation of what will happen.
 */
import { pluralise } from '../../../format';

defineProps({
    plan: { type: Object, required: true },
});
</script>

<template>
    <div class="space-y-5">
        <div
            v-if="plan.removes_anything"
            class="rounded-md border border-danger-line bg-danger-surface px-4 py-3 text-sm text-danger-text"
        >
            <p class="font-medium">This deployment removes files from the fleet.</p>
            <p class="mt-1 text-xs">
                Each removal is an <code class="font-mono">rm -rf</code> of the checkout's directory, run once per
                host. It is reversible — rolling this deployment back clones the removed checkouts and restores
                their refs — but the wikis will be missing that code until you do.
            </p>
        </div>

        <div>
            <h3 class="text-sm font-medium text-fg">Line items</h3>
            <ul class="mt-1 divide-y divide-line rounded-md border border-line text-sm">
                <li v-for="item in plan.items" :key="item.checkout_id" class="flex flex-wrap gap-2 px-3 py-2">
                    <span
                        class="rounded-sm border px-1.5 py-0.5 text-2xs font-medium"
                        :class="
                            item.action === 'undeploy'
                                ? 'border-warning-line bg-warning-surface text-warning-text'
                                : 'border-info-line bg-info-surface text-info-text'
                        "
                    >
                        {{ item.action }}
                    </span>
                    <span class="font-medium">{{ item.name }}</span>
                    <code class="font-mono text-xs text-fg-subtle">{{ item.path }}</code>
                    <span v-if="item.ref_value" class="ms-auto font-mono text-xs">→ {{ item.ref_value }}</span>
                </li>
            </ul>
        </div>

        <div v-if="plan.patches.length > 0">
            <h3 class="text-sm font-medium text-fg">Patches to apply</h3>
            <ul class="mt-1 space-y-1 text-sm">
                <li v-for="patch in plan.patches" :key="patch.id">
                    {{ patch.name }}
                    <code class="ms-1 font-mono text-xs text-fg-subtle">{{ patch.target_path }}</code>
                </li>
            </ul>
        </div>

        <!-- Registered patches for these checkouts that were not ticked. A patch
             silently dropped because someone forgot it is a wiki that comes back
             up without a fix it had yesterday. -->
        <div
            v-if="plan.unselected_patches.length > 0"
            class="rounded-md border border-warning-line bg-warning-surface px-4 py-3 text-sm text-warning-text"
        >
            <p class="font-medium">
                {{ plan.unselected_patches.length }} registered patch(es) for these checkouts are not selected.
            </p>
            <ul class="mt-1 list-disc space-y-0.5 ps-5 text-xs">
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
                <h3 class="text-sm font-medium text-fg">Salt calls</h3>
                <span class="text-xs text-fg-subtle">{{ pluralise(plan.call_count, 'call') }}</span>
            </div>

            <div class="mt-1 space-y-3">
                <section v-for="(calls, phase) in plan.phases" :key="phase">
                    <h4 class="label-caps">{{ phase }}</h4>
                    <ol class="mt-1 space-y-1">
                        <li
                            v-for="(call, index) in calls"
                            :key="`${phase}-${index}`"
                            class="rounded border border-line bg-sunken px-3 py-2"
                        >
                            <div class="flex flex-wrap items-baseline gap-2 text-sm">
                                <code class="font-mono text-xs text-fg-subtle">{{ call.target }}</code>
                                <span>{{ call.label }}</span>
                            </div>
                            <pre class="mt-1 overflow-x-auto font-mono text-xs text-fg-muted">{{ call.command }}</pre>
                        </li>
                    </ol>
                </section>
            </div>
        </div>

        <div>
            <h3 class="text-sm font-medium text-fg">Options</h3>
            <ul class="mt-1 flex flex-wrap gap-2 text-xs">
                <li
                    v-for="flag in plan.option_flags"
                    :key="flag"
                    class="rounded bg-sunken px-2 py-0.5 text-fg"
                >
                    {{ flag }}
                </li>
            </ul>
        </div>
    </div>
</template>
