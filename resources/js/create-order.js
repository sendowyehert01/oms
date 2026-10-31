const form = document.querySelector('[data-create-order]');

if (form) {
    const items = form.querySelector('[data-order-items]');
    const itemTemplate = form.querySelector('[data-item-template]');
    const addItemButton = form.querySelector('[data-add-item]');
    const itemsError = form.querySelector('[data-items-error]');
    const submitButton = form.querySelector('[data-submit-order]');
    const customerSelect = form.querySelector('[data-customer-select]');
    const newCustomerFields = form.querySelector('[data-new-customer]');
    const formatter = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    let nextItemIndex = 0;
    let isSubmitting = false;

    const priceInCents = (value) => {
        if (!/^\d+(?:\.\d{1,2})?$/.test(value)) {
            return 0;
        }

        const [whole, fraction = ''] = value.split('.');
        return Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
    };

    const updateTotals = () => {
        let totalInCents = 0;
        const rows = items.querySelectorAll('[data-order-item]');

        rows.forEach((row) => {
            const quantityInput = row.querySelector('[data-quantity]');
            const priceInput = row.querySelector('[data-unit-price]');
            const quantity = Number(quantityInput.value);
            const subtotalInCents = quantityInput.validity.valid && priceInput.validity.valid && Number.isInteger(quantity)
                ? quantity * priceInCents(priceInput.value)
                : 0;

            row.querySelector('[data-subtotal]').value = formatter.format(subtotalInCents / 100);
            totalInCents += subtotalInCents;
        });

        form.querySelector('[data-order-total]').value = formatter.format(totalInCents / 100);
        itemsError.hidden = rows.length > 0;
        addItemButton.disabled = rows.length >= 100;
    };

    const updateCustomerFields = () => {
        const hasExistingCustomer = customerSelect.value !== '';
        newCustomerFields.hidden = hasExistingCustomer;
        newCustomerFields.disabled = hasExistingCustomer;
    };

    addItemButton.addEventListener('click', () => {
        if (items.querySelectorAll('[data-order-item]').length >= 100) {
            return;
        }

        while (document.getElementById(`item-${nextItemIndex}-name`)) {
            nextItemIndex += 1;
        }

        const template = document.createElement('template');
        template.innerHTML = itemTemplate.innerHTML.replaceAll('__INDEX__', String(nextItemIndex));
        nextItemIndex += 1;
        const newRow = template.content.firstElementChild;
        items.append(newRow);
        newRow.querySelector('input').focus();
        updateTotals();
    });

    items.addEventListener('input', updateTotals);
    items.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-remove-item]');
        if (removeButton) {
            removeButton.closest('[data-order-item]').remove();
            addItemButton.focus();
            updateTotals();
        }
    });
    customerSelect.addEventListener('change', updateCustomerFields);

    form.addEventListener('submit', (event) => {
        if (isSubmitting || !items.querySelector('[data-order-item]')) {
            event.preventDefault();
            updateTotals();
            return;
        }

        isSubmitting = true;
        submitButton.disabled = true;
        submitButton.textContent = 'Creating order...';
        form.setAttribute('aria-busy', 'true');
    });

    window.addEventListener('pageshow', () => {
        isSubmitting = false;
        submitButton.disabled = false;
        submitButton.textContent = 'Create Order';
        form.removeAttribute('aria-busy');
        updateCustomerFields();
        updateTotals();
    });

    updateCustomerFields();
    updateTotals();
}
