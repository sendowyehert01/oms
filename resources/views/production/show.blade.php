<x-app-layout :title="$orderNumber.' production'">
    <x-page-heading :title="$orderNumber" eyebrow="Production" description="Check finished stock, confirm required materials, and track each item through production and quality checking." />
    <a href="{{ route('production.index') }}" class="mt-4 inline-flex min-h-11 items-center text-sm font-semibold text-brand-700 dark:text-brand-300">Back to production</a>
    <x-form-errors />
    @if (session('success'))<p role="status" class="mt-4 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">{{ session('success') }}</p>@endif
    @if (session('error'))<p role="alert" class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-800 dark:bg-rose-950 dark:text-rose-200">{{ session('error') }}</p>@endif
    <section class="oms-surface mt-6 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" aria-labelledby="production-order-heading">
        <h2 id="production-order-heading" class="text-lg font-semibold">{{ $order->customer->company_name }}</h2>
        <div class="mt-3"><x-order-status :status="$order->status" /></div>
        <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
            <div><dt class="text-slate-500 dark:text-slate-400">Order date</dt><dd class="mt-1">{{ $order->order_date->format('M j, Y') }}</dd></div>
            <div><dt class="text-slate-500 dark:text-slate-400">Target delivery</dt><dd class="mt-1">{{ $order->target_delivery_date?->format('M j, Y') ?? 'Not scheduled' }}</dd></div>
            <div><dt class="text-slate-500 dark:text-slate-400">Responsible staff</dt><dd class="mt-1">{{ $order->creator?->name ?? 'Creator not recorded' }}</dd></div>
        </dl>
    </section>
    <section class="oms-surface mt-6 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" aria-labelledby="production-specifications-heading">
        <h2 id="production-specifications-heading" class="text-lg font-semibold">Order specifications</h2>
        <div class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($order->items as $item)
                <article class="py-4 first:pt-0">
                    <h3 class="font-semibold">{{ $item->product_name }} · {{ $item->measurement()->displayValue() }}</h3>
                    <dl class="mt-3 grid gap-3 text-sm sm:grid-cols-2">
                        @foreach (['Size' => $item->size, 'Material / color' => $item->material_color, 'Print design / colors' => $item->print_design_colors, 'Sealing' => $item->sealing === 'Others' ? $item->sealing_other : $item->sealing, 'Puncher' => $item->puncher === 'Others' ? $item->puncher_other : $item->puncher, 'Production instructions' => $item->customization_notes] as $label => $value)
                            @if (filled($value))<div><dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt><dd class="mt-1 whitespace-pre-line break-words">{{ $value }}</dd></div>@endif
                        @endforeach
                    </dl>
                </article>
            @endforeach
        </div>
    </section>
    <section class="oms-surface mt-6 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900" aria-labelledby="production-heading">
        <h2 id="production-heading" class="text-lg font-semibold">Stock and production</h2>
        <x-item-production-panel :order="$order" />
    </section>
    <x-order-workflow-panel :order="$order" :history="$statusHistory" />
</x-app-layout>
