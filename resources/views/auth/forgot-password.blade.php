<x-layouts.guest title="Reset your password">
    <form method="POST" action="{{ route('password.email') }}" class="space-y-5">
        @csrf

        <p class="text-sm text-pretty text-fg-muted">
            Enter your email address and you will be sent a link to choose a new password.
        </p>

        <x-field label="Email" name="email" type="email" value="{{ old('email') }}" required autofocus
                 autocomplete="username" placeholder="you@wikioasis.org" />

        <button type="submit" class="btn btn-primary w-full">Email a reset link</button>

        <p class="text-center text-sm">
            <a href="{{ route('login') }}" class="link">Back to sign in</a>
        </p>
    </form>
</x-layouts.guest>
