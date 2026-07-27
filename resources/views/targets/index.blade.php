<x-layouts.app title="Deploy targets">
    <div class="space-y-6">
        <div class="rounded-md border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
            <p class="font-medium text-slate-800">Hostnames must match Salt minion ids exactly.</p>
            <p class="mt-1">
                The hostname is passed straight through as the Salt target — <code class="font-mono">salt
                '&lt;hostname&gt;' {{ config('mwdeploy.salt.command_module') }} …</code> — so a mismatch means the call
                silently matches nothing. Check against <code class="font-mono">salt-key -L</code>.
            </p>
        </div>

        @foreach ($roles as $role)
            @php $targets = $targetsByRole->get($role->value, collect()); @endphp

            <x-card :title="$role->label()" :subtitle="$targets->count().' registered'">
                @if ($targets->isEmpty())
                    <p class="text-sm text-slate-500">None registered.</p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($targets as $target)
                            <li class="py-3 first:pt-0 last:pb-0">
                                <form method="POST" action="{{ route('targets.update', $target) }}"
                                      class="flex flex-wrap items-end gap-3">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="hostname" value="{{ $target->hostname }}">
                                    <input type="hidden" name="role" value="{{ $target->role->value }}">

                                    <div class="min-w-48 pb-2">
                                        <code class="font-mono text-sm">{{ $target->hostname }}</code>
                                        @unless ($target->active)
                                            <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500">inactive</span>
                                        @endunless
                                    </div>

                                    @if ($role === \App\Enums\TargetRole::Proxy)
                                        <div class="w-44">
                                            <x-field label="HAProxy backend" name="haproxy_backend">
                                                <x-input name="haproxy_backend" value="{{ $target->haproxy_backend }}"
                                                         placeholder="{{ config('mwdeploy.rollout.haproxy_backend') }}"
                                                         class="py-1 text-xs" />
                                            </x-field>
                                        </div>
                                    @endif

                                    @if ($role === \App\Enums\TargetRole::Appserver)
                                        <div class="w-44">
                                            <x-field label="HAProxy server name" name="haproxy_server_name">
                                                <x-input name="haproxy_server_name" value="{{ $target->haproxy_server_name }}"
                                                         placeholder="{{ $target->hostname }}" class="py-1 text-xs" />
                                            </x-field>
                                        </div>
                                        <div class="w-52">
                                            <x-field label="Canary vhost" name="canary_vhost">
                                                <x-input name="canary_vhost" value="{{ $target->canary_vhost }}"
                                                         placeholder="{{ config('mwdeploy.rollout.canary_vhost') }}"
                                                         class="py-1 text-xs" />
                                            </x-field>
                                        </div>
                                    @endif

                                    <div class="w-24">
                                        <x-field label="Order" name="sort_order">
                                            <x-input type="number" name="sort_order" value="{{ $target->sort_order }}"
                                                     min="0" max="9999" class="py-1 text-xs" />
                                        </x-field>
                                    </div>

                                    <label class="flex items-center gap-2 pb-2 text-sm text-slate-600">
                                        <input type="checkbox" name="active" value="1" @checked($target->active)
                                               class="rounded border-slate-300">
                                        Active
                                    </label>

                                    <button type="submit" class="mb-2 rounded border border-slate-300 px-2 py-1 text-xs hover:bg-slate-50">
                                        Save
                                    </button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        @endforeach

        <x-card title="Add a target">
            <form method="POST" action="{{ route('targets.store') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf

                <x-field label="Hostname (Salt minion id)" name="hostname" required>
                    <x-input name="hostname" value="{{ old('hostname') }}" required placeholder="mw-us-east-011" class="font-mono" />
                </x-field>

                <x-field label="Role" name="role" required>
                    <select name="role" required class="block w-full rounded-md bg-white px-3 py-2 text-sm ring-1 ring-inset ring-slate-300">
                        @foreach ($roles as $role)
                            <option value="{{ $role->value }}" @selected(old('role') === $role->value)>{{ $role->label() }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field label="HAProxy backend" name="haproxy_backend" hint="Proxies only. Defaults to config.">
                    <x-input name="haproxy_backend" value="{{ old('haproxy_backend') }}"
                             placeholder="{{ config('mwdeploy.rollout.haproxy_backend') }}" />
                </x-field>

                <x-field label="HAProxy server name" name="haproxy_server_name"
                         hint="Appservers only, if HAProxy knows the box by a different label.">
                    <x-input name="haproxy_server_name" value="{{ old('haproxy_server_name') }}" />
                </x-field>

                <x-field label="Canary vhost" name="canary_vhost" hint="Appservers only. Defaults to config.">
                    <x-input name="canary_vhost" value="{{ old('canary_vhost') }}"
                             placeholder="{{ config('mwdeploy.rollout.canary_vhost') }}" />
                </x-field>

                <x-field label="Sort order" name="sort_order" hint="Rollout order, lowest first.">
                    <x-input type="number" name="sort_order" value="{{ old('sort_order', 0) }}" min="0" max="9999" />
                </x-field>

                <div class="sm:col-span-2">
                    <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                        Add target
                    </button>
                </div>
            </form>
        </x-card>
    </div>
</x-layouts.app>
