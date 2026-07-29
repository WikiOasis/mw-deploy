<x-layouts.guest title="Sign in">
    <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
        @csrf

        <x-field label="Email" name="email" required>
            <x-input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" />
        </x-field>

        <x-field label="Password" name="password" required>
            <x-input type="password" name="password" required autocomplete="current-password" />
        </x-field>

        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="remember" class="rounded border-slate-300">
            Remember this device
        </label>

        <button type="submit" class="w-full rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
            Sign in
        </button>

        <p class="text-center text-sm">
            <a href="{{ route('password.request') }}" class="text-slate-500 hover:text-slate-900">Forgot your password?</a>
        </p>

        <p class="border-t border-slate-100 pt-4 text-center text-xs text-slate-500">
            Accounts are created by an administrator. There is no self-registration —
            this console can push code to every production appserver.
        </p>
    </form>
</x-layouts.guest>
