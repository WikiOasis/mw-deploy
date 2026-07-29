<x-layouts.guest title="Confirm your password">
    <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-4">
        @csrf

        <p class="text-sm text-slate-600">
            Confirm your password to continue. This is a sensitive area of the console.
        </p>

        <x-field label="Password" name="password" required>
            <x-input type="password" name="password" required autofocus autocomplete="current-password" />
        </x-field>

        <button type="submit" class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
            Confirm
        </button>
    </form>
</x-layouts.guest>
