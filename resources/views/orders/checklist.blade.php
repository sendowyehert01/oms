<x-order-checklist :order="$workflowOrder" :summary="$paymentSummary" />

@if ($statusHistory !== null)
    <template data-checklist-history>
        <x-order-workflow-panel :order="$workflowOrder" :history="$statusHistory" />
    </template>
@endif
