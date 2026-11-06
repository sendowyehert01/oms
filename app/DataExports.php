<?php

namespace App;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
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
            'clients' => 'List of Clients',
            'orders' => 'List of Orders',
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
                $this->orders($workbook, $dateFrom, $dateTo, $generatedAt);
            }

            return $workbook;
        } catch (Throwable $exception) {
            $workbook->cleanup();

            throw $exception;
        }
    }

    private function clients(ExcelWorkbook $workbook, string $dateFrom, string $dateTo, string $timezone): void
    {
        $sheet = $workbook->addSheet('Clients', [
            ['label' => 'Client ID', 'type' => 'integer'],
            ['label' => 'Company Name'],
            ['label' => 'Contact Person'],
            ['label' => 'Contact Number'],
            ['label' => 'Address'],
            ['label' => 'Created Date', 'type' => 'date'],
            ['label' => 'Created By'],
        ]);
        $start = CarbonImmutable::parse($dateFrom, $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($dateTo, $timezone)->startOfDay()->addDay()->utc();

        foreach (Customer::query()->with('creator:id,name')->where('created_at', '>=', $start)->where('created_at', '<', $end)->lazyById(200) as $client) {
            $sheet->appendRow([
                $client->id, $client->company_name, $client->contact_person, $client->contact_number, $client->address,
                $this->localTimestamp($client->created_at, $timezone), $client->creator?->name,
            ]);
        }
    }

    private function orders(ExcelWorkbook $workbook, string $dateFrom, string $dateTo, CarbonImmutable $generatedAt): void
    {
        $sheet = $workbook->addSheet('Orders', [
            ['label' => 'Order Status'],
            ['label' => 'Order ID'],
            ['label' => 'Customer Name'],
            ['label' => 'Order Date', 'type' => 'date'],
            ['label' => 'Order Items'],
            ['label' => 'Remarks'],
            ['label' => 'Balance', 'type' => 'money'],
            ['label' => 'Total Amount', 'type' => 'money'],
            ['label' => 'Terms of Payment'],
            ['label' => 'Payment Status'],
        ]);
        $today = $generatedAt->toDateString();
        $query = Order::query()->whereBetween('order_date', [$dateFrom, $dateTo])->with(['customer', 'items', 'payments']);

        foreach ($query->lazyById(200) as $order) {
            $summary = $this->payments->summary($order);
            $dueStatus = $this->dueStatus($order, $summary['balance_in_cents'], $today);
            $remarks = $order->items->whereNotNull('customization_notes')
                ->filter(fn (OrderItem $item): bool => trim($item->customization_notes) !== '')
                ->map(fn (OrderItem $item): string => $item->product_name.': '.$item->customization_notes)->implode('; ');

            $sheet->appendRow([
                $order->status, $this->orderEntries->number($order->id, $order->order_date->toDateString()),
                $order->customer->company_name, $order->order_date, $order->items->pluck('product_name')->implode(', '), $remarks,
                MoneyAmount::decimal($summary['balance_in_cents']), MoneyAmount::decimal($summary['total_amount_in_cents']),
                $order->terms_of_payment, $summary['status'].' ('.$dueStatus.')',
            ]);
        }
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
