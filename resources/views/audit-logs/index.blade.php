<x-app-layout title="Audit Logs">
    <x-page-heading title="Audit logs" eyebrow="Administration" description="Review user navigation, order progress, and account activity in one place." />

    <x-form-errors message="Please check your activity filters:" />
    <form method="GET" action="{{ route('audit-logs.index') }}" class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-2 xl:grid-cols-6 dark:border-slate-800 dark:bg-slate-900">
        <div class="sm:col-span-2">
            <x-form-input name="search" label="Search activity" :required="false" :value="$filters['search'] ?? ''" placeholder="User, client, order number, or activity" maxlength="255" />
        </div>
        <div>
            <label for="actor_id" class="mb-2 block text-sm font-medium">User</label>
            <select id="actor_id" name="actor_id" class="oms-input min-h-12 w-full">
                <option value="">All users</option>
                @foreach ($actors as $actor)
                    <option value="{{ $actor->actor_id }}" @selected(($filters['actor_id'] ?? '') == $actor->actor_id)>{{ $actor->actor_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="action" class="mb-2 block text-sm font-medium">Action</label>
            <select id="action" name="action" class="oms-input min-h-12 w-full">
                <option value="">All actions</option>
                @foreach ($actions as $action)
                    <option value="{{ $action->value }}" @selected(($filters['action'] ?? '') === $action->value)>{{ $action->label() }}</option>
                @endforeach
            </select>
        </div>
        <x-form-input name="date_from" label="Date from" type="date" :required="false" :value="$filters['date_from'] ?? ''" />
        <x-form-input name="date_to" label="Date to" type="date" :required="false" :value="$filters['date_to'] ?? ''" />
        <div class="flex flex-wrap items-center justify-between gap-4 sm:col-span-2 xl:col-span-6">
            <p class="text-xs text-slate-500 dark:text-slate-400">Date filters include both dates. Times shown in {{ $timezone }}.</p>
            <div class="flex items-center gap-3">
                <a href="{{ route('audit-logs.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800">Clear</a>
                <button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">Apply filters</button>
            </div>
        </div>
    </form>

    <section aria-labelledby="activity-heading" class="mt-6 min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-5 sm:px-6 dark:border-slate-800">
            <div>
                <h2 id="activity-heading" class="text-lg font-semibold">Activity history</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Latest activity first</p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ number_format($logs->total()) }} {{ $logs->total() === 1 ? 'entry' : 'entries' }}</span>
        </div>

        <table class="block w-full text-left text-sm md:table">
            <caption class="sr-only">User activity recorded in OMS</caption>
            <thead class="hidden bg-slate-50 text-xs text-slate-500 md:table-header-group dark:bg-slate-950/40 dark:text-slate-400">
                <tr>
                    <th scope="col" class="px-6 py-4">Date and time</th>
                    <th scope="col" class="px-6 py-4">User</th>
                    <th scope="col" class="px-6 py-4">Action</th>
                    <th scope="col" class="px-6 py-4">Activity</th>
                </tr>
            </thead>
            <tbody class="block divide-y divide-slate-100 md:table-row-group dark:divide-slate-800">
                @forelse ($logs as $entry)
                    <tr class="grid gap-3 p-5 md:table-row md:p-0">
                        <td class="block align-top md:table-cell md:px-6 md:py-5">
                            <span class="mb-1 block text-xs text-slate-500 md:hidden dark:text-slate-400">Date and time</span>
                            <time datetime="{{ $entry->created_at->toIso8601String() }}" class="whitespace-nowrap font-medium">{{ $entry->created_at->timezone($timezone)->format('M d, Y') }}</time>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $entry->created_at->timezone($timezone)->format('h:i:s A') }}</p>
                        </td>
                        <td class="block align-top md:table-cell md:px-6 md:py-5">
                            <span class="mb-1 block text-xs text-slate-500 md:hidden dark:text-slate-400">User</span>
                            <p class="break-words font-semibold">{{ $entry->actor_name }}</p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $entry->actor_role === \App\UserRole::Admin ? 'Admin' : 'Staff' }}</p>
                        </td>
                        <td class="block align-top md:table-cell md:px-6 md:py-5">
                            <span class="mb-1 block text-xs text-slate-500 md:hidden dark:text-slate-400">Action</span>
                            <span class="inline-flex rounded-lg bg-brand-50 px-2.5 py-1 text-xs font-semibold text-brand-700 dark:bg-brand-950 dark:text-brand-200">{{ $entry->action->label() }}</span>
                        </td>
                        <td class="block align-top md:table-cell md:px-6 md:py-5">
                            <p class="break-words leading-6 [overflow-wrap:anywhere]">{{ $entry->statement }}</p>
                            @if ($entry->order_id !== null)
                                @if ($entry->order !== null)
                                    <a href="{{ route('orders.show', $entry->order_id) }}" class="mt-2 inline-flex text-xs font-semibold text-brand-700 hover:underline dark:text-brand-300">View {{ $entry->order_number }}</a>
                                @else
                                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ $entry->order_number }} · Deleted order</p>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr class="block md:table-row">
                        <td colspan="4" class="block px-6 py-14 text-center md:table-cell">
                            <p class="font-semibold">No activity to show</p>
                            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Activity appears here as your team uses OMS. If filters are applied, try a wider date range or clear them.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <x-pagination :paginator="$logs" resource-label="audit entries" />
    </section>
</x-app-layout>
