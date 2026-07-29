<script setup>
import AppButton from '../../components/AppButton.vue';
</script>

<template>
    <div class="panel mx-auto max-w-lg px-6 py-14 text-center">
        <h1 class="text-xl font-semibold text-balance">No such screen</h1>
        <p class="mx-auto mt-2.5 max-w-prose text-sm text-pretty text-fg-muted">
            That path is not part of this console, or it belongs to an app your account cannot open. A link to a
            deployment or repository that has since been removed will land here too.
        </p>
        <div class="mt-6 flex justify-center">
            <AppButton to="/" variant="primary" icon="grid">Back to your apps</AppButton>
        </div>
    </div>
</template>
