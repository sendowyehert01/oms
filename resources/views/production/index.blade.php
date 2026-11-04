<x-app-layout title="Production">
    <x-page-heading title="Production" eyebrow="Operations" description="Monitor item readiness, blockers, and delivery schedules for orders you can access." />
    <x-form-errors message="Please check your production filters:" />
    <form method="GET" action="{{ route('production.index') }}" class="oms-surface grid gap-4 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-3 dark:border-slate-800 dark:bg-slate-900">
        <x-form-input name="search" label="Order number or customer" :required="false" :value="$filters['search'] ?? ''" maxlength="255" />
        <div><label for="status" class="mb-2 block text-sm font-medium">Status</label><select id="status" name="status" class="oms-input min-h-12 w-full"><option value="">All statuses</option>@foreach ($statuses as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>@endforeach</select></div>
        <x-form-input name="target_delivery_date" type="date" label="Target delivery date" :required="false" :value="$filters['target_delivery_date'] ?? ''" />
        <x-form-input name="customer_search" label="Find customer choices" :required="false" :value="$filters['customer_search'] ?? ''" maxlength="255" />
        <div><label for="customer_id" class="mb-2 block text-sm font-medium">Customer (up to 50 matches)</label><select id="customer_id" name="customer_id" class="oms-input min-h-12 w-full"><option value="">All accessible customers</option>@foreach ($customers as $customer)<option value="{{ $customer->id }}" @selected((string)($filters['customer_id'] ?? '') === (string)$customer->id)>{{ $customer->company_name }}</option>@endforeach</select></div>
        <x-form-input name="creator_search" label="Find responsible staff choices" :required="false" :value="$filters['creator_search'] ?? ''" maxlength="255" />
        <div><label for="created_by" class="mb-2 block text-sm font-medium">Responsible staff — order creator</label><select id="created_by" name="created_by" class="oms-input min-h-12 w-full"><option value="">All accessible creators</option>@foreach ($creators as $creator)<option value="{{ $creator->id }}" @selected((string)($filters['created_by'] ?? '') === (string)$creator->id)>{{ $creator->name }}</option>@endforeach</select></div>
        <div><label for="view" class="mb-2 block text-sm font-medium">Display</label><select id="view" name="view" class="oms-input min-h-12 w-full"><option value="board">Board</option><option value="list" @selected(($filters['view'] ?? '') === 'list')>Accessible list</option></select></div>
        <label class="flex items-center gap-3 text-sm"><input name="late" type="checkbox" value="1" @checked($filters['late'] ?? false)> Late undelivered orders only</label>
        <div class="flex flex-wrap items-end gap-3"><button type="submit" class="min-h-11 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white">Apply filters</button><a href="{{ route('production.index') }}" class="min-h-11 rounded-lg border border-slate-300 px-4 py-3 text-sm">Clear</a></div>
    </form>
    <p class="my-5 text-sm text-slate-500">Showing {{ $orders->count() }} of {{ $orders->total() }} matching orders, up to 20 per page. Dates use {{ config('app.reporting_timezone') }}. Status totals cover all matching orders.</p>
    @if ($orders->isEmpty())
        <div class="rounded-2xl border border-dashed border-slate-300 p-8 text-center"><p class="font-semibold">No matching production orders</p><p class="mt-2 text-sm text-slate-500">Change the filters or create an order to begin tracking.</p></div>
    @elseif (($filters['view'] ?? 'board') === 'list')
        <ol aria-label="Production orders" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($orders as $order)<li class="min-w-0"><x-production-card :order="$order" :board="$board" :today="$today" /></li>@endforeach
        </ol>
    @else
        <div class="grid items-start gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($statuses as $status)
                <section class="min-w-0 rounded-2xl bg-slate-100 p-3 dark:bg-slate-950" aria-label="{{ $status }} orders">
                    <h2 class="mb-4 flex flex-wrap items-center justify-between gap-2 text-sm font-semibold"><span>{{ $status }}</span><span class="rounded-full bg-white px-3 py-1 tabular-nums dark:bg-slate-800">{{ $counts->get($status, 0) }}</span></h2>
                    <ol class="space-y-3">
                        @forelse ($orders->where('status', $status) as $order)<li><x-production-card :order="$order" :board="$board" :today="$today" /></li>
                        @empty<li class="py-4 text-xs text-slate-500">No orders in this stage on this page.</li>@endforelse
                    </ol>
                </section>
            @endforeach
            @if ($orders->contains(fn ($order) => !in_array($order->status, $statuses, true)))
                <section aria-label="Other historical statuses"><h2 class="mb-3 font-semibold">Other historical statuses</h2><ol class="space-y-3">@foreach ($orders->filter(fn ($order) => !in_array($order->status, $statuses, true)) as $order)<li><x-production-card :order="$order" :board="$board" :today="$today" /></li>@endforeach</ol></section>
            @endif
        </div>
    @endif
    @if ($orders->hasPages())<div class="mt-6">{{ $orders->links() }}</div>@endif
</x-app-layout>
