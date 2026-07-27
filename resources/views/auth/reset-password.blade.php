<x-layouts.guest title="Choose a new password">
    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <x-field label="Email" name="email" required>
            <x-input type="email" name="email" value="{{ old('email', $request->email) }}" required />
        </x-field>

        <x-field label="New password" name="password" hint="At least 12 characters." required>
            <x-input type="password" name="password" required autocomplete="new-password" autofocus />
        </x-field>

        <x-field label="Confirm new password" name="password_confirmation" required>
            <x-input type="password" name="password_confirmation" required autocomplete="new-password" />
        </x-field>

        <button type="submit" class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
            Set password
        </button>
    </form>
</x-layouts.guest>
