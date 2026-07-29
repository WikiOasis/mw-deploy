<script setup>
import { RouterLink } from 'vue-router';

import { can, registryIsEmpty } from '../../../store';

/**
 * The deployments app's own banner, rendered by the console chrome above every
 * screen in this app.
 *
 * A fresh install has an empty registry, and if the farm already has MediaWiki on
 * disk then reading the tree is almost always the right first move — five empty
 * lists are a worse welcome than one instruction. Renders nothing once anything
 * is registered, and nothing at all for an account that could not act on it.
 */
</script>

<template>
    <div
        v-if="registryIsEmpty && can('manage_repositories')"
        class="mb-6 rounded-md border border-info-line bg-info-surface px-4 py-3 text-sm text-info-text"
    >
        <p class="font-medium">Nothing is registered yet.</p>
        <p class="mt-1 text-xs">
            If this farm already has MediaWiki on disk, the app can read the tree and fill the registry in from
            it — every version, extension, skin and their current refs.
            <RouterLink to="/deployments/import" class="link font-medium">Scan the tree</RouterLink>.
        </p>
    </div>
</template>
