<script setup>
import { RouterLink } from 'vue-router';
</script>

<template>
    <div class="rounded-lg border border-slate-200 bg-white p-8 text-center shadow-sm">
        <h1 class="text-lg font-semibold tracking-tight">No such screen</h1>
        <p class="mt-2 text-sm text-slate-600">
            That path is not part of this console, or belongs to an app your account cannot open. If you followed a
            link to a deployment or repository that has since been removed, it will not resolve.
        </p>
        <RouterLink to="/" class="mt-4 inline-block text-sm font-medium underline">Back to your apps</RouterLink>
    </div>
</template>
