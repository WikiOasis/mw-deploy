<x-layouts.guest title="Sign in">
    {{-- A failed single sign-on attempt comes back here rather than to an error
         page, because "try the password form" is a useful thing to be able to do
         from wherever the failure lands you. --}}
    @error('oidc')
        <div class="mb-5 rounded-lg border border-danger-line bg-danger-surface px-3.5 py-3">
            <p class="text-sm text-pretty text-danger-text">{{ $message }}</p>
        </div>
    @enderror

    @if ($oidc->isUsable())
        <a href="{{ route('oidc.redirect') }}" class="btn btn-primary w-full">
            Sign in with {{ $oidc->label }}
        </a>

        {{-- Password sign-in stays available underneath, deliberately: this
             console can deploy to production, and a way in that does not depend
             on a third party being up is worth keeping. --}}
        <div class="my-5 flex items-center gap-3">
            <span class="h-px grow bg-line" aria-hidden="true"></span>
            <span class="label-caps text-fg-subtle">or use a password</span>
            <span class="h-px grow bg-line" aria-hidden="true"></span>
        </div>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="space-y-5">
        @csrf

        <x-field label="Email" name="email" type="email" value="{{ old('email') }}" required autofocus
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
                {{ $oidc->label }}, which creates the account on first use. There is no self-registration form,
                because this console can push code to every production appserver.
            @else
                Accounts are created by an administrator — there is no self-registration, because this console can
                push code to every production appserver.
            @endif
        </p>
    </form>
</x-layouts.guest>
