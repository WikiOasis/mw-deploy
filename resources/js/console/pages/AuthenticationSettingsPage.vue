<script setup>
import { computed, onMounted, ref } from 'vue';

import { ApiError, api, endpoint } from '../../api';
import AppButton from '../../components/AppButton.vue';
import CardPanel from '../../components/CardPanel.vue';
import FormField from '../../components/FormField.vue';
import LoadState from '../../components/LoadState.vue';
import { flash, flashError } from '../../store';

/**
 * How people sign in: the single sign-on provider, and which of its groups grant
 * which console roles.
 *
 * Configuration lives here rather than in the environment because rotating a
 * client secret, following the IdP to a new hostname or adding a group is
 * ordinary access administration — it should not need a shell on the appserver
 * and a php-fpm reload. The screen is behind `settings.manage`, which is the
 * sharpest grant in the console: it decides which identity provider this console
 * believes about who you are.
 *
 * Intended order of use: paste the issuer, press Read configuration, check what
 * came back, add the client credentials, map at least one group to a role, then
 * switch it on. The server refuses to enable a configuration it cannot use, so
 * the switch cannot be flipped on a half-filled form.
 */
const data = ref(null);
const loading = ref(true);
const error = ref(null);
const busy = ref(false);
const discovering = ref(false);
const errors = ref({});

/** The editable copy. The stored client secret is never sent to the browser. */
const form = ref(null);
const secret = ref('');
const mappings = ref([]);
const allowedGroups = ref('');

/** What the last discovery found, so the endpoints it filled can be seen. */
const discovered = ref(null);

const load = async () => {
    loading.value = true;

    try {
        data.value = await api.get(endpoint('settings/oidc'));
        reset();
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

const reset = () => {
    form.value = { ...data.value.settings };
    secret.value = '';
    mappings.value = data.value.role_mappings.map((entry) => ({ group: entry.group, role_id: entry.role_id }));
    allowedGroups.value = (data.value.settings.allowed_groups ?? []).join(', ');
    errors.value = {};
};

const roles = computed(() => data.value?.roles ?? []);

/** The URL to register at the provider. Getting this wrong is failure mode one. */
const redirectUri = computed(() => data.value?.redirect_uri ?? '');

const linkedAccounts = computed(() => data.value?.linked_accounts ?? 0);

const passwordAccounts = computed(() => data.value?.password_accounts ?? 0);

/**
 * What the server says is actually true, as against what the checkbox says. They
 * differ when single sign-on is unusable or the environment override is set, and
 * an administrator looking at a password box the setting claims to have removed
 * deserves to be told which it is.
 */
const passwordLoginEffective = computed(() => data.value?.password_login_effective === true);

const passwordLoginForced = computed(() => data.value?.password_login_forced === true);

const scopeList = computed(() =>
    (form.value?.scopes ?? '')
        .split(/[\s,]+/)
        .filter((scope) => scope !== ''),
);

/**
 * Whether the groups scope is being asked for at all. Without it the IdP will
 * authenticate people perfectly well and tell us nothing about their groups, so
 * every account arrives with no roles — which reads as a broken console rather
 * than a missing scope.
 */
const groupsScopeMissing = computed(
    () => mappings.value.length > 0 && !scopeList.value.some((scope) => scope === form.value?.groups_claim || scope === 'groups'),
);

/** Scopes the provider advertised but this install is not asking for. */
const unsupportedScopes = computed(() => {
    const supported = discovered.value?.scopes_supported ?? [];

    return supported.length === 0 ? [] : scopeList.value.filter((scope) => !supported.includes(scope));
});

const discover = async () => {
    discovering.value = true;
    errors.value = {};

    try {
        const payload = await api.post(endpoint('settings/oidc/discover'), {
            discovery_url: form.value.discovery_url || form.value.issuer,
        });

        discovered.value = payload.discovery;

        // Filled in, not saved: an IdP behind split-horizon DNS advertises
        // endpoints this host cannot reach, and that is worth seeing before
        // sign-in is handed over to it.
        Object.assign(form.value, {
            issuer: payload.discovery.issuer,
            authorization_endpoint: payload.discovery.authorization_endpoint,
            token_endpoint: payload.discovery.token_endpoint,
            userinfo_endpoint: payload.discovery.userinfo_endpoint,
            jwks_uri: payload.discovery.jwks_uri,
            end_session_endpoint: payload.discovery.end_session_endpoint,
        });

        flash(payload.message);
    } catch (thrown) {
        if (thrown instanceof ApiError && thrown.isValidation) {
            errors.value = thrown.errors;
        } else {
            flashError(thrown);
        }
    } finally {
        discovering.value = false;
    }
};

const addMapping = () => {
    mappings.value.push({ group: '', role_id: roles.value[0]?.id ?? null });
};

const removeMapping = (index) => {
    mappings.value.splice(index, 1);
};

const save = async () => {
    busy.value = true;
    errors.value = {};

    const payload = {
        ...form.value,
        allowed_groups: allowedGroups.value
            .split(',')
            .map((group) => group.trim())
            .filter((group) => group !== ''),
        role_mappings: mappings.value.filter((entry) => entry.group.trim() !== '' && entry.role_id !== null),
    };

    // An empty box means "leave the stored secret alone", which is what it has
    // to mean: the screen never sees the secret it is showing a placeholder for.
    if (secret.value !== '') {
        payload.client_secret = secret.value;
    }

    delete payload.client_secret_set;
    delete payload.usable;
    delete payload.discovered_at;

    try {
        const response = await api.put(endpoint('settings/oidc'), payload);

        data.value = response;
        reset();
        flash(response.message);
    } catch (thrown) {
        if (thrown instanceof ApiError && thrown.isValidation) {
            errors.value = thrown.errors;
        } else {
            flashError(thrown);
        }
    } finally {
        busy.value = false;
    }
};
</script>

<template>
    <div class="space-y-4">
        <header>
            <h1 class="text-xl font-semibold">Sign-in and single sign-on</h1>
            <p class="mt-1.5 max-w-prose text-sm text-pretty text-fg-muted">
                Sign people in through your OpenID Connect provider, and let its groups decide which console
                roles they hold. Password sign-in stays available either way — a console that can only be
                entered through a third party is one you cannot get into on the day that third party is what
                broke.
            </p>
        </header>

        <LoadState :loading="loading" :error="error" @retry="load">
            <div v-if="form" class="space-y-6">
                <CardPanel
                    title="Provider"
                    subtitle="Paste the issuer and read its configuration, then check the endpoints before switching anything on."
                >
                    <div class="space-y-4">
                        <label class="flex items-start gap-2.5">
                            <input
                                v-model="form.enabled"
                                type="checkbox"
                                class="mt-0.5 size-4 rounded border-line-strong"
                            />
                            <span>
                                <span class="text-sm font-medium">Offer single sign-on on the sign-in page</span>
                                <span class="block text-xs text-fg-subtle">
                                    Cannot be switched on until the issuer, client credentials and endpoints below
                                    are filled in.
                                </span>
                            </span>
                        </label>

                        <p v-if="errors.enabled" class="text-xs text-danger-text">{{ errors.enabled[0] }}</p>

                        <label class="flex items-start gap-2.5">
                            <input
                                v-model="form.password_login_enabled"
                                type="checkbox"
                                class="mt-0.5 size-4 rounded border-line-strong"
                            />
                            <span>
                                <span class="text-sm font-medium">Also allow signing in with a password</span>
                                <span class="block text-xs text-fg-subtle">
                                    Off means {{ form.label || 'the provider' }} is the only way in. It can only be
                                    switched off while single sign-on is on and working, it switches itself back on
                                    if single sign-on is ever disabled, and
                                    <span class="font-mono">CONSOLE_FORCE_PASSWORD_LOGIN=true</span> in the
                                    environment brings it back on the day the provider is what broke.
                                </span>
                            </span>
                        </label>

                        <p v-if="errors.password_login_enabled" class="text-xs text-danger-text">
                            {{ errors.password_login_enabled[0] }}
                        </p>

                        <p
                            v-if="!form.password_login_enabled && passwordLoginEffective"
                            class="rounded-md border border-warning-line bg-warning-surface px-3 py-2 text-xs text-pretty text-warning-text"
                        >
                            <template v-if="passwordLoginForced">
                                The password form is still being shown, because
                                <span class="font-mono">CONSOLE_FORCE_PASSWORD_LOGIN</span> is set in this
                                install's environment. Unset it to have this take effect.
                            </template>
                            <template v-else>
                                The password form is still being shown, because single sign-on is not currently
                                usable. It will disappear once single sign-on is on and configured.
                            </template>
                        </p>

                        <p
                            v-else-if="!form.password_login_enabled"
                            class="rounded-md border border-warning-line bg-warning-surface px-3 py-2 text-xs text-pretty text-warning-text"
                        >
                            {{ passwordAccounts }} account{{ passwordAccounts === 1 ? '' : 's' }} can currently
                            sign in with a password. Make sure you can sign in with {{ form.label || 'the provider' }}
                            before relying on this.
                        </p>

                        <FormField
                            v-slot="field"
                            label="Button label"
                            required
                            hint="What the sign-in page says: “Sign in with …”."
                            :error="errors.label?.[0]"
                        >
                            <input v-bind="field" v-model="form.label" type="text" class="input-control block w-full" />
                        </FormField>

                        <FormField
                            v-slot="field"
                            label="Issuer or discovery URL"
                            hint="Either the issuer, or the full /.well-known/openid-configuration URL."
                            :error="errors.discovery_url?.[0]"
                        >
                            <div class="flex flex-wrap gap-2">
                                <input
                                    v-bind="field"
                                    v-model="form.discovery_url"
                                    type="url"
                                    class="input-control min-w-0 grow"
                                    placeholder="https://sso.example.org/application/o/console/"
                                />
                                <AppButton :loading="discovering" @click="discover">Read configuration</AppButton>
                            </div>
                        </FormField>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField v-slot="field" label="Client ID" :error="errors.client_id?.[0]">
                                <input
                                    v-bind="field"
                                    v-model="form.client_id"
                                    type="text"
                                    class="input-control block w-full font-mono text-xs"
                                />
                            </FormField>

                            <FormField
                                v-slot="field"
                                label="Client secret"
                                :hint="
                                    form.client_secret_set
                                        ? 'A secret is stored. Leave blank to keep it, or paste a new one to replace it.'
                                        : 'From the client you registered at the provider.'
                                "
                                :error="errors.client_secret?.[0]"
                            >
                                <input
                                    v-bind="field"
                                    v-model="secret"
                                    type="password"
                                    autocomplete="new-password"
                                    class="input-control block w-full"
                                    :placeholder="form.client_secret_set ? '••••••••••••' : ''"
                                />
                            </FormField>
                        </div>

                        <!-- Shown rather than described: an unregistered or
                             mistyped redirect URI is the most common reason a
                             first OIDC setup fails. -->
                        <div class="rounded-md border border-line bg-sunken px-3 py-2.5">
                            <p class="label-caps text-fg-subtle">Redirect URI to register at the provider</p>
                            <p class="mt-1 font-mono text-xs break-all">{{ redirectUri }}</p>
                        </div>
                    </div>
                </CardPanel>

                <CardPanel
                    title="Endpoints"
                    subtitle="Filled in by reading the provider's configuration, and editable for a provider that publishes none."
                >
                    <div class="space-y-4">
                        <FormField v-slot="field" label="Issuer" :error="errors.issuer?.[0]">
                            <input v-bind="field" v-model="form.issuer" type="url" class="input-control block w-full font-mono text-xs" />
                        </FormField>

                        <FormField v-slot="field" label="Authorization endpoint" :error="errors.authorization_endpoint?.[0]">
                            <input v-bind="field" v-model="form.authorization_endpoint" type="url" class="input-control block w-full font-mono text-xs" />
                        </FormField>

                        <FormField v-slot="field" label="Token endpoint" :error="errors.token_endpoint?.[0]">
                            <input v-bind="field" v-model="form.token_endpoint" type="url" class="input-control block w-full font-mono text-xs" />
                        </FormField>

                        <FormField
                            v-slot="field"
                            label="Key set (JWKS) URL"
                            hint="Required: every ID token's signature is checked against these keys."
                            :error="errors.jwks_uri?.[0]"
                        >
                            <input v-bind="field" v-model="form.jwks_uri" type="url" class="input-control block w-full font-mono text-xs" />
                        </FormField>

                        <FormField
                            v-slot="field"
                            label="Userinfo endpoint"
                            hint="Used when the ID token does not carry the groups claim, which is how several providers behave."
                            :error="errors.userinfo_endpoint?.[0]"
                        >
                            <input v-bind="field" v-model="form.userinfo_endpoint" type="url" class="input-control block w-full font-mono text-xs" />
                        </FormField>
                    </div>
                </CardPanel>

                <CardPanel
                    title="Groups and roles"
                    subtitle="Which of the provider's groups grant which console roles. Permissions are never read from the provider directly — a group grants a role, and the role is what the access screen already explains."
                >
                    <div class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField
                                v-slot="field"
                                label="Scopes"
                                required
                                hint="Space separated. `openid` is always sent; add the scope your provider needs before it will release groups."
                                :error="errors.scopes?.[0]"
                            >
                                <input v-bind="field" v-model="form.scopes" type="text" class="input-control block w-full font-mono text-xs" />
                            </FormField>

                            <FormField
                                v-slot="field"
                                label="Groups claim"
                                required
                                hint="The claim group membership arrives in. Dot notation works for a nested claim."
                                :error="errors.groups_claim?.[0]"
                            >
                                <input v-bind="field" v-model="form.groups_claim" type="text" class="input-control block w-full font-mono text-xs" />
                            </FormField>
                        </div>

                        <p
                            v-if="groupsScopeMissing"
                            class="rounded-md border border-warning-line bg-warning-surface px-3 py-2 text-xs text-pretty text-warning-text"
                        >
                            You have mapped groups to roles, but the scopes above do not ask for a groups scope.
                            Most providers will authenticate people and release no group membership at all, so
                            every account will arrive with no roles.
                        </p>

                        <p
                            v-if="unsupportedScopes.length > 0"
                            class="rounded-md border border-warning-line bg-warning-surface px-3 py-2 text-xs text-pretty text-warning-text"
                        >
                            The provider did not advertise
                            <span class="font-mono">{{ unsupportedScopes.join(', ') }}</span
                            >. That is not always fatal — some providers under-report — but it is worth checking
                            before blaming the mapping.
                        </p>

                        <div class="space-y-2">
                            <p class="text-sm font-medium">Group to role</p>

                            <div v-for="(mapping, index) in mappings" :key="index" class="flex flex-wrap gap-2">
                                <input
                                    v-model="mapping.group"
                                    type="text"
                                    class="input-control min-w-0 grow font-mono text-xs"
                                    placeholder="mediawiki-admins"
                                    aria-label="Provider group"
                                />
                                <select v-model="mapping.role_id" class="input-control" aria-label="Console role">
                                    <option v-for="role in roles" :key="role.id" :value="role.id">
                                        {{ role.name }}
                                    </option>
                                </select>
                                <AppButton
                                    variant="danger-quiet"
                                    icon="minus"
                                    label="Remove this mapping"
                                    @click="removeMapping(index)"
                                />
                            </div>

                            <p v-if="mappings.length === 0" class="text-xs text-fg-subtle">
                                Nothing mapped. While the mapping is empty, single sign-on never changes anybody's
                                roles — so switching it on before writing the mapping cannot lock the console's
                                administrators out of it.
                            </p>

                            <AppButton icon="plus" :disabled="roles.length === 0" @click="addMapping">
                                Add a mapping
                            </AppButton>
                        </div>

                        <label class="flex items-start gap-2.5">
                            <input v-model="form.create_users" type="checkbox" class="mt-0.5 size-4 rounded border-line-strong" />
                            <span>
                                <span class="text-sm font-medium">Create an account on first sign-in</span>
                                <span class="block text-xs text-fg-subtle">
                                    Off means only people who already have an account here can use single sign-on.
                                </span>
                            </span>
                        </label>

                        <label class="flex items-start gap-2.5">
                            <input v-model="form.sync_roles" type="checkbox" class="mt-0.5 size-4 rounded border-line-strong" />
                            <span>
                                <span class="text-sm font-medium">Re-apply the mapping on every sign-in</span>
                                <span class="block text-xs text-fg-subtle">
                                    The provider becomes the source of truth: removing someone from a group there
                                    removes the role here, and roles granted by hand on the access screen are
                                    replaced. Off means the mapping only seeds a new account.
                                </span>
                            </span>
                        </label>

                        <FormField
                            v-slot="field"
                            label="Restrict sign-in to these groups"
                            hint="Comma separated, and optional. Empty means anyone the provider authenticates may sign in, holding whatever their groups map to."
                            :error="errors.allowed_groups?.[0]"
                        >
                            <input v-bind="field" v-model="allowedGroups" type="text" class="input-control block w-full font-mono text-xs" />
                        </FormField>
                    </div>
                </CardPanel>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-xs text-fg-subtle">
                        {{ linkedAccounts }} account{{ linkedAccounts === 1 ? '' : 's' }} currently sign in this
                        way. This console does not ask any of them to enrol TOTP — the second factor is the
                        provider's to enforce, so make sure it does. An account that keeps a password alongside
                        can still be entered without the provider seeing it, which is what switching password
                        sign-in off above closes.
                    </p>

                    <div class="flex gap-2">
                        <AppButton :disabled="busy" @click="reset">Discard changes</AppButton>
                        <AppButton variant="primary" :loading="busy" @click="save">Save</AppButton>
                    </div>
                </div>
            </div>
        </LoadState>
    </div>
</template>
