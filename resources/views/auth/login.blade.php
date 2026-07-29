<x-layouts.guest title="Sign in">
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
            Accounts are created by an administrator — there is no self-registration, because this console can
            push code to every production appserver.
        </p>
    </form>
</x-layouts.guest>
