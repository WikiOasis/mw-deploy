<x-layouts.guest title="Choose a new password">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-field label="Email" name="email" type="email" value="{{ old('email', $request->email) }}" required
                 autocomplete="username" />

        <x-field label="New password" name="password" type="password" hint="Use at least 12 characters." required
                 autocomplete="new-password" autofocus />

        <x-field label="Confirm new password" name="password_confirmation" type="password" required
                 autocomplete="new-password" />

        <button type="submit" class="btn btn-primary w-full">Set password</button>
    </form>
</x-layouts.guest>
