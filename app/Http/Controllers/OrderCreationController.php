<?php

namespace App\Http\Controllers;

use App\Http\Requests\SelectOrderCustomerRequest;
use App\Models\Customer;
use App\OrderDrafts;
use App\OrderEntries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

class OrderCreationController extends Controller
{
    public function create(Request $request, OrderDrafts $drafts): View
    {
        $submissionKey = $request->query('draft');

        if ($drafts->find($request->session(), $submissionKey) === null) {
            $submissionKey = $drafts->start($request->session());
        }

        return view('orders.customer-choice', compact('submissionKey'));
    }

    public function customer(Request $request, string $type, OrderDrafts $drafts): View|RedirectResponse
    {
        $submissionKey = $request->query('draft');
        $draft = $drafts->find($request->session(), $submissionKey);

        if ($draft === null) {
            return $this->expiredDraft();
        }

        $customerInput = $request->old('submission_key') === $submissionKey && $request->old('customer_type') === $type
            ? $request->session()->getOldInput()
            : ($draft['customer_inputs'][$type] ?? []);
        $selectedCustomerId = filter_var($customerInput['customer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $selectedCustomer = null;
        $search = $request->query('search', '');
        $search = is_string($search) ? mb_substr(trim($search), 0, 255) : '';
        $customers = null;
        $customerLoadError = false;

        if ($type === 'existing') {
            try {
                $selectedCustomer = $selectedCustomerId === false ? null : Customer::query()->find($selectedCustomerId);
                $customers = Customer::query()
                    ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                        ->whereLike('company_name', '%'.$search.'%')
                        ->orWhereLike('contact_person', '%'.$search.'%')
                        ->orWhereLike('contact_number', '%'.$search.'%')))
                    ->orderBy('company_name')->orderBy('id')->paginate(10)->withQueryString();
            } catch (QueryException $exception) {
                report($exception);
                $customerLoadError = true;
            }
        }

        return view('orders.customer', compact('submissionKey', 'type', 'customerInput', 'customers', 'selectedCustomer', 'search', 'customerLoadError'));
    }

    public function selectCustomer(SelectOrderCustomerRequest $request, OrderDrafts $drafts): RedirectResponse
    {
        $data = $request->validated();

        if ($drafts->find($request->session(), $data['submission_key']) === null) {
            return $this->expiredDraft();
        }

        $drafts->selectCustomer($request->session(), $data['submission_key'], $data['customer_type'], Arr::except($data, ['submission_key', 'customer_type']));

        return to_route('orders.creation.details', ['draft' => $data['submission_key']]);
    }

    public function details(Request $request, OrderDrafts $drafts): View|RedirectResponse
    {
        $submissionKey = $request->query('draft');
        $draft = $drafts->find($request->session(), $submissionKey);

        if ($draft === null) {
            return $this->expiredDraft();
        }

        if ($draft['customer_type'] === null) {
            return to_route('orders.create', ['draft' => $submissionKey])->with('error', 'Choose a customer before entering order details.');
        }

        $customer = $draft['customer'];

        if ($draft['customer_type'] === 'existing') {
            $selectedCustomer = Customer::query()->find($customer['customer_id']);

            if ($selectedCustomer === null) {
                return to_route('orders.creation.customer', ['type' => 'existing', 'draft' => $submissionKey])
                    ->withErrors(['customer_id' => 'The selected customer is no longer available. Please select another customer.']);
            }

            $customer = [
                'customer_name' => $selectedCustomer->company_name,
                'contact_person' => $selectedCustomer->contact_person,
                'address' => $selectedCustomer->address,
                'contact_number' => $selectedCustomer->contact_number,
            ];
        }

        $orderInput = $request->old('submission_key') === $submissionKey ? $request->session()->getOldInput() : $draft['order'];
        $statuses = OrderEntries::STATUSES;

        return view('orders.create', compact('submissionKey', 'customer', 'orderInput', 'statuses'));
    }

    public function back(Request $request, OrderDrafts $drafts): RedirectResponse
    {
        $submissionKey = $request->input('submission_key');
        $draft = $drafts->find($request->session(), $submissionKey);

        if ($draft === null) {
            return $this->expiredDraft();
        }

        if ($request->has('customer_type')) {
            $data = $request->validate(['customer_type' => ['required', 'in:existing,new']]);
            $drafts->rememberCustomerInput($request->session(), $submissionKey, $data['customer_type'], $request->only(['customer_id', 'customer_name', 'contact_person', 'address', 'contact_number']));

            return to_route('orders.create', ['draft' => $submissionKey]);
        }

        $drafts->rememberOrder($request->session(), $submissionKey, $request->only(['order_date', 'due_date', 'status', 'items']));

        return to_route('orders.creation.customer', ['type' => $draft['customer_type'] ?? 'new', 'draft' => $submissionKey]);
    }

    public function cancel(Request $request, OrderDrafts $drafts): RedirectResponse
    {
        $submissionKey = $request->input('submission_key');

        if ($drafts->find($request->session(), $submissionKey) !== null) {
            $drafts->forget($request->session(), $submissionKey);
        }

        return to_route('orders.index');
    }

    private function expiredDraft(): RedirectResponse
    {
        return to_route('orders.create')->with('error', 'This order draft is no longer available. Please start again.');
    }
}
