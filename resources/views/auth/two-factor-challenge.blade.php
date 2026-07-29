<x-layouts.guest title="Two-factor authentication">
    {{--
        Both fields are in the page and the toggle swaps which is shown, moving
        `required` with it (see resources/js/auth.js). With JavaScript unavailable
        the authenticator field is the one on screen and still submits, which is the
        case that matters: this page is between an operator and the fleet.
    --}}
    <div class="space-y-5">
        <form method="POST" action="{{ route('two-factor.login.store') }}" class="space-y-5">
            @csrf

            <div data-panel-default="recovery">
                {{-- `numeric` rather than a plain keyboard, and one-time-code so the
                     code can be filled from the OS. Paste is never blocked: a
                     recovery code is 20 characters nobody types by hand. --}}
                <x-field label="Authentication code" name="code" hint="Six digits from your authenticator app."
                         inputmode="numeric" autocomplete="one-time-code" autofocus required
                         class="font-mono tracking-[0.2em]" />
            </div>

            <div data-panel="recovery" hidden>
                <x-field label="Recovery code" name="recovery_code"
                         hint="One of the codes you saved when you enrolled. Each works once."
                         autocomplete="one-time-code" class="font-mono" />
            </div>

            <button type="submit" class="btn btn-primary w-full">Verify code</button>
        </form>

        <button type="button" data-toggle="recovery" class="btn btn-ghost w-full">
            Use a recovery code instead
        </button>
    </div>
</x-layouts.guest>
