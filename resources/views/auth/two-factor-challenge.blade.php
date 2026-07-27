<x-layouts.guest title="Two-factor authentication">
    {{--
        Both fields are in the page and the toggle swaps which is shown, moving
        `required` with it (see resources/js/auth.js). With JavaScript unavailable
        the authenticator field is the one on screen and still submits, which is the
        case that matters: this page is between an operator and the fleet.
    --}}
    <div class="space-y-4">
        <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-4">
            @csrf

            <div data-panel-default="recovery">
                <x-field label="Authentication code" name="code" hint="Six digits from your authenticator app.">
                    <x-input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus />
                </x-field>
            </div>

            <div data-panel="recovery" hidden>
                <x-field label="Recovery code" name="recovery_code">
                    <x-input type="text" name="recovery_code" autocomplete="one-time-code" />
                </x-field>
            </div>

            <button type="submit" class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                Verify
            </button>
        </form>

        <button type="button" data-toggle="recovery"
                class="w-full text-center text-sm text-slate-500 hover:text-slate-900">
            Use a recovery code instead
        </button>
    </div>
</x-layouts.guest>
