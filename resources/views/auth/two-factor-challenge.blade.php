<x-layouts.guest title="Two-factor authentication">
    <div x-data="{ recovery: false }" class="space-y-4">
        <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-4">
            @csrf

            <div x-show="! recovery">
                <x-field label="Authentication code" name="code" hint="Six digits from your authenticator app.">
                    <x-input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                             x-bind:required="! recovery" autofocus />
                </x-field>
            </div>

            <div x-show="recovery" x-cloak>
                <x-field label="Recovery code" name="recovery_code">
                    <x-input type="text" name="recovery_code" autocomplete="one-time-code"
                             x-bind:required="recovery" />
                </x-field>
            </div>

            <button type="submit" class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                Verify
            </button>
        </form>

        <button type="button" @click="recovery = ! recovery" class="w-full text-center text-sm text-slate-500 hover:text-slate-900">
            <span x-show="! recovery">Use a recovery code instead</span>
            <span x-show="recovery" x-cloak>Use an authentication code instead</span>
        </button>
    </div>
</x-layouts.guest>
