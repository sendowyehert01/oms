<?php

namespace App;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Throwable;

class DataExports
{
    public function __construct(private readonly PaymentEntries $payments, private readonly OrderEntries $orderEntries) {}

    public function workbook(string $type, string $dateFrom, string $dateTo, User $user): ExcelWorkbook
    {
        Gate::forUser($user)->authorize('export-data');
        $title = match ($type) {
            'clients' => 'OMS | Client report',
            'orders' => 'OMS | Order report',
            default => throw new InvalidArgumentException('Unknown export type.'),
        };
        $timezone = config('app.reporting_timezone');
        $generatedAt = CarbonImmutable::now($timezone);
        $period = CarbonImmutable::parse($dateFrom)->format('M j, Y').' to '.CarbonImmutable::parse($dateTo)->format('M j, Y');
        $workbook = new ExcelWorkbook($title, $period, $user->name, $generatedAt, $timezone);

        try {
            if ($type === 'clients') {
                $this->clients($workbook, $dateFrom, $dateTo, $timezone);
            } else {
                $this->orders($workbook, $dateFrom, $dateTo, $generatedAt, $timezone);
            }

            return $workbook;
        } catch (Throwable $exception) {
            $workbook->cleanup();

            throw $exception;
        }
    }

    private function overview(ExcelWorkbook $workbook): ExcelWorksheet
    {
        return $workbook->addSheet('Overview', [
            ['label' => 'Metric', 'width' => 32],
            ['label' => 'Value', 'width' => 26],
            ['label' => 'Notes', 'width' => 88],
        ], filterable: false, frozenColumns: 0);
    }

    private function clientSheet(ExcelWorkbook $workbook): ExcelWorksheet
    {
        return $workbook->addSheet('Clients', [
            ['label' => 'Client ID', 'width' => 14, 'type' => 'integer'],
            ['label' => 'Company name', 'width' => 36],
            ['label' => 'Contact person', 'width' => 26],
            ['label' => 'Contact number', 'width' => 22],
            ['label' => 'Address', 'width' => 52],
            ['label' => 'Created at', 'width' => 26, 'type' => 'datetime'],
            ['label' => 'Created by', 'width' => 26],
        ]);
    }

    private function appendClient(ExcelWorksheet $sheet, Customer $client, string $timezone): void
    {
        $sheet->appendRow([
            $client->id, $client->company_name, $client->contact_person, $client->contact_number, $client->address,
            $this->localTimestamp($client->created_at, $timezone), $client->creator?->name,
        ]);
    }

    private function clients(ExcelWorkbook $workbook, string $dateFrom, string $dateTo, string $timezone): void
    {
        $overview = $this->overview($workbook);
        $clients = $this->clientSheet($workbook);
        $start = CarbonImmutable::parse($dateFrom, $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($dateTo, $timezone)->startOfDay()->addDay()->utc();

        foreach (Customer::query()->with('creator:id,name')->where('created_at', '>=', $start)->where('created_at', '<', $end)->lazyById(200) as $client) {
            $this->appendClient($clients, $client, $timezone);
        }

        $overview->appendRow(['Clients exported', ['value' => $clients->count(), 'type' => 'integer'], 'One row per client.']);
        $overview->appendRow(['Selection basis', 'Client creation date', 'Both dates are inclusive in '.$timezone.'.']);
        $overview->appendRow(['Timestamps', $timezone, 'Creation timestamps are displayed in the reporting timezone.']);
        $overview->appendRow(['Workbook guide', 'Clients', 'Company, contact person, phone, address, creation timestamp, and creator.']);
    }

    private function orders(ExcelWorkbook $workbook, string $dateFrom, string $dateTo, CarbonImmutable $generatedAt, string $timezone): void
    {
        $overview = $this->overview($workbook);
        $orders = $workbook->addSheet('Orders', [
            ['label' => 'Order ID', 'width' => 14, 'type' => 'integer'],
            ['label' => 'Order number', 'width' => 24],
            ['label' => 'Order date', 'width' => 18, 'type' => 'date'],
            ['label' => 'Client ID', 'width' => 14, 'type' => 'integer'],
            ['label' => 'Company name', 'width' => 36],
            ['label' => 'Contact person', 'width' => 26],
            ['label' => 'Contact number', 'width' => 22],
            ['label' => 'Address', 'width' => 52],
            ['label' => 'Order status', 'width' => 24, 'type' => 'status'],
            ['label' => 'Payment status', 'width' => 22, 'type' => 'status'],
            ['label' => 'Due status', 'width' => 22, 'type' => 'status'],
            ['label' => 'Overdue', 'width' => 14, 'type' => 'status'],
            ['label' => 'Days overdue', 'width' => 18, 'type' => 'integer'],
            ['label' => 'Payment due date', 'width' => 20, 'type' => 'date'],
            ['label' => 'Terms of payment', 'width' => 28],
            ['label' => 'Order total (PHP)', 'width' => 24, 'type' => 'money'],
            ['label' => 'Total paid (PHP)', 'width' => 24, 'type' => 'money'],
            ['label' => 'Balance (PHP)', 'width' => 24, 'type' => 'money'],
            ['label' => 'Items', 'width' => 14, 'type' => 'integer'],
            ['label' => 'Payments', 'width' => 14, 'type' => 'integer'],
            ['label' => 'Target delivery date', 'width' => 22, 'type' => 'date'],
            ['label' => 'Delivery date', 'width' => 18, 'type' => 'date'],
            ['label' => 'Delivery recipient', 'width' => 26],
            ['label' => 'Delivery notes', 'width' => 44],
            ['label' => 'Delivery confirmed by', 'width' => 28],
            ['label' => 'Delivery confirmed at', 'width' => 26, 'type' => 'datetime'],
            ['label' => 'Created at', 'width' => 26, 'type' => 'datetime'],
            ['label' => 'Created by', 'width' => 26],
        ], frozenColumns: 2);
        $clients = $this->clientSheet($workbook);
        $items = $workbook->addSheet('Order Items', [
            ['label' => 'Order ID', 'width' => 14, 'type' => 'integer'],
            ['label' => 'Order number', 'width' => 24],
            ['label' => 'Company name', 'width' => 36],
            ['label' => 'Item ID', 'width' => 14, 'type' => 'integer'],
            ['label' => 'Product name', 'width' => 32],
            ['label' => 'Quantity', 'width' => 20, 'type' => 'decimal'],
            ['label' => 'Unit', 'width' => 12],
            ['label' => 'Unit price (PHP)', 'width' => 24, 'type' => 'money'],
            ['label' => 'Subtotal (PHP)', 'width' => 24, 'type' => 'money'],
            ['label' => 'Material / color', 'width' => 30],
            ['label' => 'Size', 'width' => 30],
            ['label' => 'Print / design / colors', 'width' => 36],
            ['label' => 'Sealing', 'width' => 24],
            ['label' => 'Other sealing', 'width' => 30],
            ['label' => 'Puncher', 'width' => 24],
            ['label' => 'Other puncher', 'width' => 30],
            ['label' => 'Customization notes', 'width' => 44],
            ['label' => 'Stock availability', 'width' => 24, 'type' => 'status'],
            ['label' => 'Availability notes', 'width' => 44],
            ['label' => 'Availability checked by', 'width' => 28],
            ['label' => 'Availability checked at', 'width' => 26, 'type' => 'datetime'],
            ['label' => 'Production status', 'width' => 24, 'type' => 'status'],
            ['label' => 'Ready check required', 'width' => 24],
            ['label' => 'Ready confirmed by', 'width' => 28],
            ['label' => 'Ready confirmed at', 'width' => 26, 'type' => 'datetime'],
        ], frozenColumns: 2);
        $payments = $workbook->addSheet('Payments', [
            ['label' => 'Payment ID', 'width' => 14, 'type' => 'integer'],
            ['label' => 'Order ID', 'width' => 14, 'type' => 'integer'],
            ['label' => 'Order number', 'width' => 24],
            ['label' => 'Company name', 'width' => 36],
            ['label' => 'Payment date', 'width' => 18, 'type' => 'date'],
            ['label' => 'Payment type', 'width' => 22],
            ['label' => 'Amount (PHP)', 'width' => 24, 'type' => 'money'],
            ['label' => 'Receipt filename', 'width' => 36],
            ['label' => 'Recorded at', 'width' => 26, 'type' => 'datetime'],
        ]);
        $total = 0;
        $paid = 0;
        $balance = 0;
        $overdueBalance = 0;
        $overdueCount = 0;
        $paymentStatuses = array_fill_keys(['Fully paid', 'Partially paid', 'Unpaid'], 0);
        $orderStatuses = array_fill_keys(array_column(OrderStatus::cases(), 'value'), 0);
        $today = $generatedAt->toDateString();

        $query = Order::query()->whereBetween('order_date', [$dateFrom, $dateTo])->with([
            'customer', 'creator:id,name', 'items',
            'payments' => fn (HasMany $query): HasMany => $query->orderBy('payment_date')->orderBy('id'),
        ]);
        foreach ($query->lazyById(200) as $order) {
            $summary = $this->payments->summary($order);
            $dueStatus = $this->dueStatus($order, $summary['balance_in_cents'], $today);
            $isOverdue = $dueStatus === 'Overdue';
            $daysOverdue = $isOverdue ? (int) CarbonImmutable::parse($order->payment_due_date->toDateString(), $timezone)->diffInDays($generatedAt->startOfDay()) : 0;
            $number = $this->orderEntries->number($order->id, $order->order_date->toDateString());
            $total += $summary['total_amount_in_cents'];
            $paid += $summary['total_paid_in_cents'];
            $balance += $summary['balance_in_cents'];
            $overdueBalance += $isOverdue ? $summary['balance_in_cents'] : 0;
            $overdueCount += (int) $isOverdue;
            $paymentStatuses[$summary['status']]++;
            $orderStatuses[$order->status] = ($orderStatuses[$order->status] ?? 0) + 1;

            $orders->appendRow([
                $order->id, $number, $order->order_date, $order->customer_id,
                $order->customer->company_name, $order->customer->contact_person, $order->customer->contact_number, $order->customer->address,
                $order->status, $summary['status'], $dueStatus, $isOverdue ? 'Yes' : 'No', $daysOverdue,
                $order->payment_due_date, $order->terms_of_payment,
                MoneyAmount::decimal($summary['total_amount_in_cents']), MoneyAmount::decimal($summary['total_paid_in_cents']), MoneyAmount::decimal($summary['balance_in_cents']),
                $order->items->count(), $order->payments->count(), $order->target_delivery_date, $order->delivery_date,
                $order->delivery_recipient, $order->delivery_notes, $order->delivery_confirmed_by_name,
                $this->localTimestamp($order->delivery_confirmed_at, $timezone), $this->localTimestamp($order->created_at, $timezone), $order->creator?->name,
            ]);

            foreach ($order->items as $item) {
                $measurement = $item->measurement();
                $items->appendRow([
                    $order->id, $number, $order->customer->company_name, $item->id, $item->product_name,
                    $measurement->hundredths / 100, $measurement->unit, $item->unit_price, MoneyAmount::decimal($item->subtotalInCents()),
                    $item->material_color, $item->size, $item->print_design_colors, $item->sealing, $item->sealing_other,
                    $item->puncher, $item->puncher_other, $item->customization_notes,
                    $item->stock_availability?->value, $item->availability_notes, $item->availability_checked_by_name,
                    $this->localTimestamp($item->availability_checked_at, $timezone), $item->production_status?->value,
                    $item->ready_check_required ? 'Yes' : 'No', $item->ready_confirmed_by_name, $this->localTimestamp($item->ready_confirmed_at, $timezone),
                ]);
            }

            foreach ($order->payments as $payment) {
                $payments->appendRow([
                    $payment->id, $order->id, $number, $order->customer->company_name, $payment->payment_date,
                    Payment::TYPES[$payment->payment_type] ?? $payment->payment_type, $payment->amount, $payment->receipt_name,
                    $this->localTimestamp($payment->created_at, $timezone),
                ]);
            }
        }

        $clientQuery = Customer::query()->with('creator:id,name')->whereHas('orders', fn (Builder $query): Builder => $query->whereBetween('order_date', [$dateFrom, $dateTo]));
        foreach ($clientQuery->lazyById(200) as $client) {
            $this->appendClient($clients, $client, $timezone);
        }

        foreach (['Orders exported' => $orders->count(), 'Clients represented' => $clients->count(), 'Line items' => $items->count(), 'Payments recorded' => $payments->count()] as $label => $value) {
            $overview->appendRow([$label, ['value' => $value, 'type' => 'integer'], 'Selected orders and their related records.']);
        }
        foreach (['Order value (PHP)' => $total, 'Total paid (PHP)' => $paid, 'Outstanding balance (PHP)' => $balance, 'Overdue balance (PHP)' => $overdueBalance] as $label => $value) {
            $overview->appendRow([$label, ['value' => MoneyAmount::decimal($value), 'type' => 'money'], 'Each order is counted once. All recorded payments are included.']);
        }
        $overview->appendRow(['Overdue orders', ['value' => $overdueCount, 'type' => 'integer'], 'Positive balance with a payment due date before '.$today.'.']);
        foreach ($paymentStatuses as $status => $count) {
            $overview->appendRow(['Payment status: '.$status, ['value' => $count, 'type' => 'integer'], 'Based on actual amounts paid against the order total.']);
        }
        foreach ($orderStatuses as $status => $count) {
            $overview->appendRow(['Order status: '.$status, ['value' => $count, 'type' => 'integer'], 'Current order status at export time.']);
        }
        $overview->appendRow(['Selection basis', 'Order date', 'Both dates are inclusive. Related clients may have been created outside this range.']);
        $overview->appendRow(['Balance as of', ['value' => $generatedAt, 'type' => 'datetime'], 'Current balances in '.$timezone.'; this is not a historical balance snapshot.']);
        $overview->appendRow(['Payment coverage', 'All related payments', 'Payments are included even when their payment date is outside the selected order-date range.']);
        $overview->appendRow(['Overdue rule', 'Balance > 0 and past due', 'Due today is not overdue. No due date is inferred from payment terms or delivery dates.']);
        $overview->appendRow(['Workbook guide', 'Orders / Clients / Order Items / Payments', 'Use order numbers to match detail sheets. Monetary columns are in Philippine pesos (PHP).']);
    }

    private function dueStatus(Order $order, int $balanceInCents, string $today): string
    {
        if ($balanceInCents === 0) {
            return 'Settled';
        }
        if ($order->payment_due_date === null) {
            return 'No due date';
        }
        $dueDate = $order->payment_due_date->toDateString();
        if ($dueDate < $today) {
            return 'Overdue';
        }

        return $dueDate === $today ? 'Due today' : 'Not overdue';
    }

    private function localTimestamp(?DateTimeInterface $timestamp, string $timezone): ?CarbonImmutable
    {
        return $timestamp === null ? null : CarbonImmutable::instance($timestamp)->setTimezone($timezone);
    }
}
