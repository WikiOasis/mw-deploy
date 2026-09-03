<x-layouts.guest title="Sign in">
    {{-- A failed single sign-on attempt comes back here rather than to an error
         page, because "try the password form" is a useful thing to be able to do
         from wherever the failure lands you. The message itself is rendered by the
         layout's error summary; a second block here showed it twice. --}}
    @if ($oidc->isUsable())
        <a href="{{ route('oidc.redirect') }}" class="btn btn-primary w-full">
            Sign in with {{ $oidc->label }}
        </a>

        {{-- Password sign-in usually stays available underneath: this console can
             deploy to production, and a way in that does not depend on a third
             party being up is worth keeping. An install that would rather insist
             on the provider can switch it off. --}}
        @if ($passwords)
            <div class="my-5 flex items-center gap-3">
                <span class="h-px grow bg-line" aria-hidden="true"></span>
                <span class="label-caps text-fg-subtle">or use a password</span>
                <span class="h-px grow bg-line" aria-hidden="true"></span>
            </div>
        @endif
    @endif

    @if ($passwords)
        <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
            @csrf

            <x-field label="Email" name="email" type="email" value="{{ old('email') }}" required
                     autocomplete="username" placeholder="you@wikioasis.org" />

            <x-field label="Password" name="password" type="password" required autocomplete="current-password" />

            {{-- Label and control share one hit target, so the words are clickable
                 too rather than only the sixteen pixels of the box. --}}
            <label class="flex w-fit items-center gap-2 py-1 text-sm text-fg-muted">
                <input type="checkbox" name="remember" class="size-4 rounded border-line-strong">
                Remember this device
            </label>

            <button type="submit" class="btn btn-primary w-full">Sign in</button>

            <p class="text-center text-sm">
                <a href="{{ route('password.request') }}" class="link">Reset your password</a>
            </p>

            <p class="border-t border-line pt-5 text-xs text-pretty text-fg-subtle">
                @if ($oidc->isUsable() && $oidc->create_users)
                    Password accounts are created by an administrator; everyone else signs in with
                    {{ $oidc->label }}, which creates the account on first use. There is no self-registration
                    form, because this console can push code to every production appserver.
                @else
                    Accounts are created by an administrator — there is no self-registration, because this
                    console can push code to every production appserver.
                @endif
            </p>
        </form>
    @else
        {{-- No form at all rather than a hidden one: a disabled password box that
             is still in the markup is an invitation to wonder whether it works.
             The endpoint refuses these credentials regardless — see
             FortifyServiceProvider — so this is presentation, not the control. --}}
        <p class="mt-5 border-t border-line pt-5 text-xs text-pretty text-fg-subtle">
            Password sign-in is switched off on this console, so {{ $oidc->label }} is the way in. If the
            provider is unavailable, an administrator with access to the server can re-enable passwords by
            setting <code class="font-mono">CONSOLE_FORCE_PASSWORD_LOGIN=true</code>.
        </p>
    @endif
</x-layouts.guest>
