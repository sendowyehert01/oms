<x-app-layout title="Data Exports">
    <x-page-heading title="Data Exports" eyebrow="Reports" description="Choose a date range and download an Excel report for your clients or orders." />

    <div class="mt-8 grid items-start gap-6 xl:grid-cols-2">
        @foreach (['clients' => 'Clients', 'orders' => 'Orders'] as $type => $label)
            <section aria-labelledby="{{ $type }}-export-heading" class="oms-surface min-w-0 rounded-2xl border border-slate-200 bg-white p-6 dark:border-slate-800 dark:bg-slate-900">
                <div class="mb-6 flex items-start gap-4">
                    <span class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700 dark:bg-brand-950 dark:text-brand-300" aria-hidden="true">
                        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M14 3H5v18h14V8l-5-5Zm0 0v5h5M8 12h8m-8 4h8m-4-4v7" stroke-linecap="round" stroke-linejoin="round" /></svg>
                    </span>
                    <div>
                        <h2 id="{{ $type }}-export-heading" class="text-xl font-semibold">Export {{ strtolower($label) }}</h2>
                        <p id="{{ $type }}-date-help" class="mt-2 text-sm leading-6 text-slate-500 dark:text-slate-400">
                            @if ($type === 'clients')
                                Select clients by the date they were created. Both dates are included, using {{ $timezone }}.
                            @else
                                Select orders by their order date. Both dates are included.
                            @endif
                        </p>
                    </div>
                </div>

                <x-form-errors :error-bag="$type" message="Please check your export:" />
                <form method="POST" action="{{ route('exports.download', ['type' => $type]) }}" class="space-y-5">
                    @csrf
                    <div class="grid gap-5 sm:grid-cols-2">
                        <x-form-input name="date_from" label="Date from" type="date" :id="$type.'-date-from'" :error-bag="$type" :value="$errors->getBag($type)->any() ? old('date_from') : $defaults['date_from']" aria-describedby="{{ $type }}-date-help" />
                        <x-form-input name="date_to" label="Date to" type="date" :id="$type.'-date-to'" :error-bag="$type" :value="$errors->getBag($type)->any() ? old('date_to') : $defaults['date_to']" aria-describedby="{{ $type }}-date-help" />
                    </div>

                    <div class="rounded-xl bg-slate-50 p-4 text-sm dark:bg-slate-950">
                        <p class="font-semibold">Included in your report</p>
                        @if ($type === 'clients')
                            <p class="mt-2 leading-6 text-slate-600 dark:text-slate-400">A client list with client ID, company name, contact person, contact number, address, created date, and creator.</p>
                        @else
                            <p class="mt-2 leading-6 text-slate-600 dark:text-slate-400">An order list with order status, order ID, customer name, order date, items, remarks, balance, total amount, terms of payment, and payment status including overdue status.</p>
                        @endif
                    </div>

                    @if ($type === 'orders')
                        <p class="text-sm leading-6 text-slate-500 dark:text-slate-400">Balances and overdue status reflect the time of download in {{ $timezone }}. Balances include all recorded payments for these orders, even those outside your selected dates.</p>
                    @endif
                    <button type="submit" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-brand-600 px-5 text-sm font-semibold text-white hover:bg-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M12 3v12m-4-4 4 4 4-4M4 17v4h16v-4" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        Download {{ strtolower($label) }} Excel
                    </button>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Excel (.xlsx) using the approved report template. The download starts when your report is ready.</p>
                </form>
            </section>
        @endforeach
    </div>
</x-app-layout>
