<x-layouts.app title="Two-factor authentication">
    <div class="mx-auto max-w-2xl space-y-6">
        @if ($required && ! $enabled)
            <div class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <p class="font-medium">Two-factor authentication is required for this account.</p>
                <p class="mt-1">
                    Your roles let you change production, so a password on its own is not enough. Enrol below to
                    continue using the portal.
                </p>
                @if ($reasons !== [])
                    <ul class="mt-2 list-disc space-y-0.5 pl-5 text-xs">
                        @foreach ($reasons as $reason)
                            <li><code>{{ $reason }}</code></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        <x-card :title="$enabled ? 'Two-factor authentication is on' : 'Set up two-factor authentication'">
            @if ($enabled)
                <p class="text-sm text-slate-600">
                    Confirmed {{ $user->two_factor_confirmed_at->diffForHumans() }}. Keep your recovery codes somewhere
                    you can reach without this device.
                </p>

                <div class="mt-4 flex flex-wrap gap-3">
                    <form method="POST" action="{{ route('two-factor.recovery-codes') }}">
                        @csrf
                        <button type="submit" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                            Regenerate recovery codes
                        </button>
                    </form>

                    @unless ($required)
                        <form method="POST" action="{{ route('two-factor.disable') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-md border border-rose-300 px-3 py-1.5 text-sm text-rose-700 hover:bg-rose-50">
                                Turn off
                            </button>
                        </form>
                    @endunless
                </div>

                @if ($required)
                    <p class="mt-3 text-xs text-slate-500">
                        You cannot turn this off while your account holds deploy permissions.
                    </p>
                @endif
            @elseif ($pendingConfirmation)
                <p class="text-sm text-slate-600">
                    Scan the QR code with your authenticator app, then enter the six-digit code it shows to finish
                    enrolling.
                </p>

                <div class="mt-4 rounded-md border border-slate-200 bg-white p-4"
                     x-data="{ svg: '', secret: '' }"
                     x-init="
                        fetch('{{ route('two-factor.qr-code') }}', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                            .then(r => r.json()).then(d => svg = d.svg);
                        fetch('{{ route('two-factor.secret-key') }}', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                            .then(r => r.json()).then(d => secret = d.secretKey);
                     ">
                    <div x-html="svg" class="[&_svg]:h-40 [&_svg]:w-40"></div>
                    <p class="mt-3 text-xs text-slate-500">
                        Cannot scan? Enter this key manually: <code class="font-mono" x-text="secret"></code>
                    </p>
                </div>

                <form method="POST" action="{{ route('two-factor.confirm') }}" class="mt-4 space-y-3">
                    @csrf

                    <x-field label="Six-digit code" name="code" required>
                        <x-input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus />
                    </x-field>

                    <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                        Confirm and enable
                    </button>
                </form>
            @else
                <p class="text-sm text-slate-600">
                    You will be shown a QR code to scan with an authenticator app (TOTP), then asked to confirm a code
                    from it.
                </p>

                <form method="POST" action="{{ route('two-factor.enable') }}" class="mt-4">
                    @csrf
                    <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                        Begin enrolment
                    </button>
                </form>
            @endif
        </x-card>

        <x-card title="Change password">
            <form method="POST" action="{{ route('user-password.update') }}" class="space-y-3">
                @csrf
                @method('PUT')

                <x-field label="Current password" name="current_password" required>
                    <x-input type="password" name="current_password" required autocomplete="current-password" />
                </x-field>

                <x-field label="New password" name="password" hint="At least 12 characters." required>
                    <x-input type="password" name="password" required autocomplete="new-password" />
                </x-field>

                <x-field label="Confirm new password" name="password_confirmation" required>
                    <x-input type="password" name="password_confirmation" required autocomplete="new-password" />
                </x-field>

                <button type="submit" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">
                    Update password
                </button>
            </form>
        </x-card>
    </div>
</x-layouts.app>
