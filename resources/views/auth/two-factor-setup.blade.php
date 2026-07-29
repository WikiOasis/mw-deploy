<x-layouts.account title="Two-factor authentication">
    <div class="mx-auto max-w-2xl space-y-6">
        <div>
            <h1 class="text-xl font-semibold">Your account</h1>
            <p class="mt-1.5 max-w-prose text-sm text-pretty text-fg-muted">
                Two-factor authentication and your password. Everything else about this account — roles, and which
                of each app's permissions they grant — is set by an administrator.
            </p>
        </div>

        @if ($required && ! $enabled)
            <div class="flex items-start gap-2.5 rounded-xl border border-warning-line bg-warning-surface px-4 py-3.5 text-sm text-warning-text">
                <svg class="mt-0.5 size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 9.75v3.75m0 3h.008M10.7 3.94 2.7 17.06A1.5 1.5 0 0 0 4 19.31h16a1.5 1.5 0 0 0 1.3-2.25L13.3 3.94a1.5 1.5 0 0 0-2.6 0Z" vector-effect="non-scaling-stroke"></path>
                </svg>
                <div class="min-w-0">
                    <p class="font-medium">An authenticator app is required for this account.</p>
                    <p class="mt-1 text-pretty">
                        Your roles let you change production, so a password on its own is not enough. Enrol below to
                        carry on using the console.
                    </p>
                    @if ($reasons !== [])
                        <ul class="mt-2 space-y-1">
                            @foreach ($reasons as $reason)
                                <li><code class="rounded bg-warning-line/40 px-1.5 py-0.5 font-mono text-2xs">{{ $reason }}</code></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endif

        <x-card :title="$enabled ? 'Two-factor authentication is on' : 'Set up two-factor authentication'">
            @if ($enabled)
                <p class="max-w-prose text-sm text-pretty text-fg-muted">
                    Confirmed {{ $user->two_factor_confirmed_at->diffForHumans() }}. Keep your recovery codes somewhere
                    you can reach without this device.
                </p>

                <div class="mt-5 flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('two-factor.recovery-codes') }}">
                        @csrf
                        <button type="submit" class="btn btn-secondary">Regenerate recovery codes</button>
                    </form>

                    @unless ($required)
                        <form method="POST" action="{{ route('two-factor.disable') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-danger-quiet">Turn off two-factor</button>
                        </form>
                    @endunless
                </div>

                @if ($required)
                    <p class="mt-3 text-xs text-fg-subtle">
                        You cannot turn this off while your account holds deploy permissions.
                    </p>
                @endif
            @elseif ($pendingConfirmation)
                <p class="max-w-prose text-sm text-pretty text-fg-muted">
                    Scan the QR code with your authenticator app, then enter the six-digit code it shows to finish
                    enrolling.
                </p>

                {{-- Fortify serves the QR svg and the secret from their own endpoints
                     rather than rendering them into the page, so resources/js/auth.js
                     fetches both into here.

                     The white plate is not a light-theme leftover: a QR code has to
                     stay dark-on-light to scan, whichever appearance the rest of the
                     console is in. --}}
                <div class="mt-5 rounded-xl border border-line bg-sunken p-4"
                     data-qr-code="{{ route('two-factor.qr-code') }}"
                     data-secret-key="{{ route('two-factor.secret-key') }}">
                    <div data-qr-target class="w-fit rounded-lg bg-white p-3 [&_svg]:size-40">
                        <p class="text-xs text-neutral-500">Loading the QR code…</p>
                    </div>
                    <p class="mt-3 text-xs text-fg-subtle">
                        Cannot scan it? Enter this key by hand:
                        <code class="rounded bg-surface px-1.5 py-0.5 font-mono break-all text-fg" data-secret-target>…</code>
                    </p>
                </div>

                <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-5 space-y-4">
                    @csrf

                    <x-field label="Six-digit code" name="code" inputmode="numeric" autocomplete="one-time-code"
                             required autofocus class="font-mono tracking-[0.2em] sm:max-w-56" />

                    <button type="submit" class="btn btn-primary">Confirm and turn on</button>
                </form>
            @else
                <p class="max-w-prose text-sm text-pretty text-fg-muted">
                    You will be shown a QR code to scan with an authenticator app (TOTP), then asked to confirm a code
                    from it.
                </p>

                <form method="POST" action="{{ route('two-factor.enable') }}" class="mt-5">
                    @csrf
                    <button type="submit" class="btn btn-primary">Begin enrolment</button>
                </form>
            @endif
        </x-card>

        <x-card title="Change password">
            <form method="POST" action="{{ route('user-password.update') }}" class="max-w-md space-y-4">
                @csrf
                @method('PUT')

                <x-field label="Current password" name="current_password" type="password" required
                         autocomplete="current-password" />

                <x-field label="New password" name="password" type="password" hint="Use at least 12 characters."
                         required autocomplete="new-password" />

                <x-field label="Confirm new password" name="password_confirmation" type="password" required
                         autocomplete="new-password" />

                <button type="submit" class="btn btn-secondary">Update password</button>
            </form>
        </x-card>
    </div>
</x-layouts.account>
