<?php

namespace App;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

class OrderDrafts
{
    public function start(Session $session): string
    {
        $submissionKey = (string) Str::uuid();
        $drafts = $session->get('orders.creation_drafts', []);
        $drafts[$submissionKey] = ['customer_type' => null, 'customer' => [], 'customer_inputs' => [], 'order' => []];
        $session->put('orders.creation_drafts', array_slice($drafts, -20, null, true));

        return $submissionKey;
    }

    /** @return array{customer_type: ?string, customer: array<string, mixed>, customer_inputs: array<string, array<string, mixed>>, order: array<string, mixed>}|null */
    public function find(Session $session, mixed $submissionKey): ?array
    {
        if (! is_string($submissionKey) || ! Str::isUuid($submissionKey)) {
            return null;
        }

        return $session->get('orders.creation_drafts.'.$submissionKey);
    }

    /** @param array<string, mixed> $customer */
    public function selectCustomer(Session $session, string $submissionKey, string $customerType, array $customer): void
    {
        $session->put('orders.creation_drafts.'.$submissionKey.'.customer_type', $customerType);
        $session->put('orders.creation_drafts.'.$submissionKey.'.customer', $customer);
        $this->rememberCustomerInput($session, $submissionKey, $customerType, $customer);
    }

    /** @param array<string, mixed> $customer */
    public function rememberCustomerInput(Session $session, string $submissionKey, string $customerType, array $customer): void
    {
        $session->put('orders.creation_drafts.'.$submissionKey.'.customer_inputs.'.$customerType, $customer);
    }

    /** @param array<string, mixed> $order */
    public function rememberOrder(Session $session, string $submissionKey, array $order): void
    {
        $session->put('orders.creation_drafts.'.$submissionKey.'.order', $order);
    }

    public function forget(Session $session, string $submissionKey): void
    {
        $session->forget('orders.creation_drafts.'.$submissionKey);
    }
}
