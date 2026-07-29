<x-layouts.guest title="Confirm your password">
    <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-5">
        @csrf

        <p class="text-sm text-pretty text-fg-muted">
            Confirm your password to continue. This is a sensitive area of the console.
        </p>

        <x-field label="Password" name="password" type="password" required autofocus
                 autocomplete="current-password" />

        <button type="submit" class="btn btn-primary w-full">Confirm password</button>
    </form>
</x-layouts.guest>
