<x-layouts.guest title="Reset your password">
    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <p class="text-sm text-slate-600">
            Enter your email address and we will send you a link to choose a new password.
        </p>

        <x-field label="Email" name="email" required>
            <x-input type="email" name="email" value="{{ old('email') }}" required autofocus />
        </x-field>

        <button type="submit" class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
            Email reset link
        </button>

        <p class="text-center text-sm">
            <a href="{{ route('login') }}" class="text-slate-500 hover:text-slate-900">Back to sign in</a>
        </p>
    </form>
</x-layouts.guest>
