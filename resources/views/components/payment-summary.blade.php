@props(['summary', 'recordUrl' => null])

<section aria-labelledby="payments-heading" class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
    <div class="flex flex-wrap items-center justify-between gap-3 p-4 sm:p-6">
        <h2 id="payments-heading" class="text-lg font-semibold">Payment details</h2>
        @if ($recordUrl !== null && $summary['balance_in_cents'] > 0)
            <a href="{{ $recordUrl }}" class="inline-flex min-h-11 items-center rounded-lg bg-indigo-600 px-4 text-sm font-semibold text-white hover:bg-indigo-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600">Record payment</a>
        @endif
    </div>
    <dl class="grid gap-5 px-4 pb-6 sm:grid-cols-2 sm:px-6 lg:grid-cols-4">
        @foreach (['total_amount_in_cents' => 'Order total (PHP)', 'total_paid_in_cents' => 'Total paid (PHP)', 'balance_in_cents' => 'Remaining balance (PHP)'] as $field => $label)
            <div><dt class="text-sm text-slate-500 dark:text-slate-400">{{ $label }}</dt><dd class="mt-1 font-semibold break-words tabular-nums"><x-money :cents="$summary[$field]" /></dd></div>
        @endforeach
        <div><dt class="text-sm text-slate-500 dark:text-slate-400">Payment status</dt><dd class="mt-1 font-semibold">{{ $summary['status'] }}</dd></div>
    </dl>
    @if ($summary['payments'] === [])
        <p class="border-t border-slate-200 p-4 text-sm text-slate-500 sm:p-6 dark:border-slate-800 dark:text-slate-400">No payments recorded yet.</p>
    @else
        <div class="overflow-x-auto border-t border-slate-200 dark:border-slate-800" role="region" aria-label="Payment history" tabindex="0">
            <table class="w-full min-w-100 text-left text-sm">
                <caption class="sr-only">Recorded payment dates, types, and amounts in Philippine pesos</caption>
                <thead class="bg-slate-50 text-slate-500 dark:bg-slate-950/40 dark:text-slate-400"><tr><th scope="col" class="px-6 py-4">Date</th><th scope="col" class="px-6 py-4">Payment type</th><th scope="col" class="px-6 py-4 text-right">Amount (PHP)</th></tr></thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($summary['payments'] as $payment)
                        <tr><td class="px-6 py-4 whitespace-nowrap"><time datetime="{{ $payment['date'] }}">{{ \Illuminate\Support\Carbon::parse($payment['date'])->format('M j, Y') }}</time></td><td class="px-6 py-4">{{ $payment['type'] }}</td><td class="px-6 py-4 text-right whitespace-nowrap tabular-nums"><x-money :cents="$payment['amount_in_cents']" /></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
