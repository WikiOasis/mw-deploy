<x-layouts.app title="Add patch">
    <div class="mx-auto max-w-2xl">
        <x-card title="Add a patch">
            <form method="POST" action="{{ route('patches.store') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf

                @include('patches._form', ['patch' => null])

                <div class="flex items-center gap-3">
                    <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                        Save patch
                    </button>
                    <a href="{{ route('patches.index') }}" class="text-sm text-slate-500 hover:text-slate-900">Cancel</a>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
