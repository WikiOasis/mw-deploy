<x-layouts.app title="Deployment history">
    <x-card title="Deployment history"
            subtitle="Every run is a row, not just the last one — the thing the old JSON state file could not give you.">
        <form method="GET" class="mb-5 flex flex-wrap items-end gap-3">
            <div class="w-48">
                <x-field label="Status" name="status">
                    <select name="status" class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected($selectedStatus === $status)>{{ $status->label() }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>
            <button type="submit" class="mb-0.5 rounded-md border border-slate-300 px-3 py-2 text-sm hover:bg-slate-50">
                Filter
            </button>
        </form>

        @if ($deployments->isEmpty())
            <p class="text-sm text-slate-500">No deployments recorded yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="py-2 pr-4">#</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4">What</th>
                            <th class="py-2 pr-4">Who</th>
                            <th class="py-2 pr-4">Duration</th>
                            <th class="py-2 pr-4">Finished</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($deployments as $deployment)
                            <tr>
                                <td class="py-2 pr-4">
                                    <a href="{{ route('deployments.show', $deployment) }}" class="font-medium hover:underline">
                                        {{ $deployment->id }}
                                    </a>
                                </td>
                                <td class="py-2 pr-4"><x-status-badge :status="$deployment->status" /></td>
                                <td class="py-2 pr-4">
                                    <span class="rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $deployment->intent->badgeClasses() }}">
                                        {{ $deployment->intent->label() }}
                                    </span>
                                    @if ($deployment->isRollback())
                                        <a href="{{ route('deployments.show', $deployment->rolls_back_deployment_id) }}"
                                           class="ml-1 rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 hover:underline">
                                            Rollback of #{{ $deployment->rolls_back_deployment_id }}
                                        </a>
                                    @else
                                        <span class="ml-1 text-slate-600">{{ Str::limit($deployment->summary(), 80) }}</span>
                                    @endif
                                    @if ($deployment->rollbacks->isNotEmpty())
                                        <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">
                                            rolled back by #{{ $deployment->rollbacks->pluck('id')->implode(', #') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-slate-500">{{ $deployment->creator?->name ?? 'system' }}</td>
                                <td class="py-2 pr-4 text-slate-500">
                                    {{ $deployment->durationSeconds() !== null ? $deployment->durationSeconds().'s' : '—' }}
                                </td>
                                <td class="py-2 pr-4 text-slate-500">{{ $deployment->finished_at?->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">{{ $deployments->links() }}</div>
        @endif
    </x-card>
</x-layouts.app>
