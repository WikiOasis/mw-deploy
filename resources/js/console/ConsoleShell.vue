<script setup>
import { computed, defineAsyncComponent } from 'vue';
import { RouterLink, RouterView, useRoute } from 'vue-router';

import { manifestFor } from '../apps';
import AppButton from '../components/AppButton.vue';
import AppIcon from '../components/AppIcon.vue';
import MenuPopover from '../components/MenuPopover.vue';
import ThemeSwitch from '../components/ThemeSwitch.vue';
import ToastStack from '../components/ToastStack.vue';
import { assertive, polite } from '../announce';
import { appById, can, consoleName, session } from '../store';

/**
 * The console chrome: which app you are in, that app's nav, the flash messages
 * and the routed screen.
 *
 * The shell knows nothing about any app. The active app is whatever the matched
 * route says it belongs to; its nav, its chrome buttons and its own setup banner
 * all come from that app's manifest. On the launcher there is no active app, so
 * the chrome is just the console.
 *
 * Two rows rather than one. The top row is the console — where you are, and the
 * controls that belong to your account — and it does not change as you move
 * around inside an app. The second row is the app's own nav, and it is only there
 * when there is an app to navigate. One wrapping row of eleven mixed links, which
 * is what this was, gave the app's screens and the sign-out button the same
 * weight.
 */
const route = useRoute();

const activeApp = computed(() => (typeof route.meta.app === 'string' ? appById(route.meta.app) : null));

const manifest = computed(() => (activeApp.value ? manifestFor(activeApp.value.id) : null));

/** `requires` is an ability from the bootstrap payload; no entry means always. */
const permitted = (entry) => typeof entry.requires !== 'string' || can(entry.requires);

const links = computed(() => (manifest.value?.nav ?? []).filter(permitted));

const actions = computed(() => (manifest.value?.actions ?? []).filter(permitted));

/** The active app's own banner, if it ships one. */
const notice = computed(() => (manifest.value?.notice ? defineAsyncComponent(manifest.value.notice) : null));

const needsTwoFactor = computed(
    () => session.user && !session.user.two_factor_enabled && session.user.two_factor_required,
);
</script>

<template>
    <div class="flex min-h-full flex-col bg-canvas">
        <!-- First focusable thing on the page, so a keyboard user is not walked
             through the whole nav on every screen. -->
        <a
            href="#content"
            class="sr-only focus-visible:not-sr-only focus-visible:absolute focus-visible:top-3 focus-visible:left-3 focus-visible:z-50 focus-visible:rounded-md focus-visible:bg-accent focus-visible:px-3 focus-visible:py-2 focus-visible:text-sm focus-visible:font-medium focus-visible:text-accent-fg"
        >
            Skip to content
        </a>

        <!-- The two live regions for the whole console. Always present, never
             re-rendered; see resources/js/announce.js. -->
        <p class="sr-only" role="status">{{ polite }}</p>
        <p class="sr-only" role="alert">{{ assertive }}</p>

        <header class="sticky top-0 z-20 border-b border-line bg-surface/85 backdrop-blur-md">
            <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-2.5 sm:px-6">
                <!-- The launcher is where the apps are, so the brand goes there
                     rather than to any one app's dashboard. -->
                <RouterLink
                    to="/"
                    class="flex shrink-0 items-center gap-2 rounded-md text-sm font-semibold text-fg"
                >
                    <span
                        class="inline-flex size-6 items-center justify-center rounded-md bg-accent text-2xs font-bold text-accent-fg"
                        aria-hidden="true"
                        >wo</span
                    >
                    <span class="max-sm:sr-only">{{ consoleName }}</span>
                </RouterLink>

                <template v-if="activeApp">
                    <AppIcon name="chevron-right" class="size-3.5 shrink-0 text-fg-faint" />
                    <!-- Not `link-quiet`: this one stays at full strength, so the
                         explicit colour rather than a utility that also sets one. -->
                    <RouterLink
                        :to="activeApp.path"
                        class="truncate rounded-md text-sm font-medium text-fg hover:underline"
                    >
                        {{ activeApp.name }}
                    </RouterLink>
                </template>

                <div class="ms-auto flex shrink-0 items-center gap-1.5">
                    <AppButton
                        v-for="action in actions"
                        :key="action.to"
                        :to="action.to"
                        :variant="action.variant === 'primary' ? 'primary' : 'secondary'"
                        class="max-md:hidden"
                    >
                        {{ action.label }}
                    </AppButton>

                    <ThemeSwitch class="max-sm:hidden" />

                    <MenuPopover :label="session.user?.name ?? 'Account'">
                        <template #trigger>
                            <span
                                class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-accent-subtle text-2xs font-semibold text-accent-text"
                                aria-hidden="true"
                            >
                                {{ (session.user?.name ?? '?').slice(0, 1).toUpperCase() }}
                            </span>
                            <span class="truncate max-sm:sr-only">{{ session.user?.name }}</span>
                            <!-- The account that has to enrol should see that from
                                 the chrome, not only after opening the menu. -->
                            <span
                                v-if="needsTwoFactor"
                                class="size-1.5 shrink-0 rounded-full bg-warning-solid"
                                aria-hidden="true"
                            />
                        </template>

                        <template #default>
                            <p class="px-2.5 pt-1 pb-2 text-xs text-fg-subtle">
                                Signed in as
                                <span class="block truncate font-medium text-fg">{{ session.user?.email }}</span>
                            </p>

                            <div class="my-1 border-t border-line sm:hidden" />
                            <div class="flex items-center justify-between gap-2 px-2.5 py-1.5 sm:hidden">
                                <span class="text-sm text-fg-muted">Theme</span>
                                <ThemeSwitch />
                            </div>

                            <div class="my-1 border-t border-line" />

                            <a
                                href="/two-factor/setup"
                                role="menuitem"
                                class="flex items-center gap-2 rounded-md px-2.5 py-1.5 text-sm text-fg-muted hover:bg-sunken hover:text-fg"
                            >
                                <AppIcon name="shield" class="size-4 shrink-0" />
                                Two-factor authentication
                                <span
                                    v-if="needsTwoFactor"
                                    class="ms-auto rounded-full bg-warning-surface px-1.5 py-0.5 text-2xs font-medium text-warning-text"
                                >
                                    Required
                                </span>
                            </a>

                            <RouterLink
                                v-if="can('manage_users')"
                                to="/access"
                                role="menuitem"
                                class="flex items-center gap-2 rounded-md px-2.5 py-1.5 text-sm text-fg-muted hover:bg-sunken hover:text-fg"
                            >
                                <AppIcon name="users" class="size-4 shrink-0" />
                                Users and access
                            </RouterLink>

                            <div class="my-1 border-t border-line" />

                            <!-- Sign-out is a real form post: Fortify owns the
                                 session, and logging out is not something to
                                 route client-side. -->
                            <form method="POST" action="/logout">
                                <input type="hidden" name="_token" :value="$csrf" />
                                <button
                                    type="submit"
                                    role="menuitem"
                                    class="flex w-full items-center gap-2 rounded-md px-2.5 py-1.5 text-start text-sm text-fg-muted hover:bg-sunken hover:text-fg"
                                >
                                    <AppIcon name="logout" class="size-4 shrink-0" />
                                    Sign out
                                </button>
                            </form>
                        </template>
                    </MenuPopover>
                </div>
            </div>

            <!-- The app's own nav. Scrolls rather than wraps on a narrow screen,
                 with the next tab left peeking past the edge so it is visible
                 that there is more. -->
            <nav
                v-if="activeApp && links.length > 0"
                class="mx-auto max-w-7xl px-4 sm:px-6"
                :aria-label="`${activeApp.name} sections`"
            >
                <ul class="-mb-px flex gap-1 overflow-x-auto pe-8 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <li v-for="link in links" :key="link.to" class="shrink-0">
                        <RouterLink
                            :to="link.to"
                            class="inline-flex border-b-2 border-transparent px-2.5 py-2 text-sm whitespace-nowrap text-fg-muted hover:border-line-strong hover:text-fg motion-safe:transition-[color,border-color] motion-safe:duration-150"
                            active-class="border-accent! font-medium text-fg!"
                        >
                            {{ link.label }}
                        </RouterLink>
                    </li>
                </ul>
            </nav>
        </header>

        <main id="content" class="mx-auto w-full max-w-7xl grow px-4 py-8 sm:px-6">
            <!-- The app's actions live in the chrome on a wide screen; on a narrow
                 one the chrome has no room for them, so they come back here rather
                 than becoming unreachable. -->
            <div v-if="actions.length > 0" class="mb-6 flex flex-wrap gap-2 md:hidden">
                <AppButton
                    v-for="action in actions"
                    :key="action.to"
                    :to="action.to"
                    :variant="action.variant === 'primary' ? 'primary' : 'secondary'"
                >
                    {{ action.label }}
                </AppButton>
            </div>

            <component :is="notice" v-if="notice" />

            <div
                v-if="needsTwoFactor"
                class="mb-6 flex items-start gap-2.5 rounded-xl border border-warning-line bg-warning-surface px-4 py-3.5"
            >
                <AppIcon name="warning" class="mt-0.5 size-4 shrink-0 text-warning-text" />
                <p class="text-sm text-pretty text-warning-text">
                    Your account can change production, so an authenticator app is required.
                    <a href="/two-factor/setup" class="link font-medium">Enrol now</a>.
                </p>
            </div>

            <ToastStack class="mb-6" />

            <RouterView />
        </main>
    </div>
</template>
