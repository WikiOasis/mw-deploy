<x-layouts.app :title="'Edit '.$patch->name">
    <div class="mx-auto max-w-2xl space-y-6">
        <x-card :title="'Edit '.$patch->name">
            <form method="POST" action="{{ route('patches.update', $patch) }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                @method('PUT')

                @include('patches._form', ['patch' => $patch])

                <div class="flex items-center gap-3">
                    <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                        Save
                    </button>
                    <a href="{{ route('patches.index') }}" class="text-sm text-slate-500 hover:text-slate-900">Cancel</a>
                </div>
            </form>
        </x-card>

        @can('delete', $patch)
            <x-card title="Deactivate">
                <p class="text-sm text-slate-600">
                    Past deployments reference this patch, so it is deactivated rather than deleted.
                </p>

                <form method="POST" action="{{ route('patches.destroy', $patch) }}" class="mt-3"
                      onsubmit="return confirm('Deactivate {{ $patch->name }}?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="rounded-md border border-rose-300 px-3 py-1.5 text-sm text-rose-700 hover:bg-rose-50">
                        Deactivate
                    </button>
                </form>
            </x-card>
        @endcan
    </div>
</x-layouts.app>
