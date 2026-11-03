const form = document.querySelector('[data-create-order]');

if (form) {
    const items = form.querySelector('[data-order-items]');
    const itemTemplate = form.querySelector('[data-item-template]');
    const addItemButton = form.querySelector('[data-add-item]');
    const itemsError = form.querySelector('[data-items-error]');
    const submitButton = form.querySelector('[data-submit-order]');
    const wholeAmountFormatter = new Intl.NumberFormat('en-PH', { maximumFractionDigits: 0 });
    let nextItemIndex = 0;
    let isSubmitting = false;

    const updateChoiceInputs = (row) => {
        row.querySelectorAll('[data-item-choice]').forEach((choice) => {
            const isOther = choice.querySelector('[data-choice-select]').value === 'Others';
            const customField = choice.querySelector('[data-choice-other]');
            const customInput = customField.querySelector('input');
            customField.hidden = !isOther;
            customInput.disabled = !isOther;
            customInput.required = isOther;
        });
    };

    const priceInCents = (value) => {
        if (!/^\d+(?:\.\d{1,2})?$/.test(value)) {
            return 0n;
        }

        const [whole, fraction = ''] = value.split('.');
        return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
    };

    const formatAmount = (cents) => `${wholeAmountFormatter.format(cents / 100n)}.${String(cents % 100n).padStart(2, '0')}`;

    const updateTotals = () => {
        let totalInCents = 0n;
        const rows = items.querySelectorAll('[data-order-item]');

        rows.forEach((row) => {
            const quantityInput = row.querySelector('[data-quantity]');
            const priceInput = row.querySelector('[data-unit-price]');
            const quantity = Number(quantityInput.value);
            const subtotalInCents = quantityInput.validity.valid && priceInput.validity.valid && Number.isInteger(quantity)
                ? BigInt(quantity) * priceInCents(priceInput.value)
                : 0n;

            row.querySelector('[data-subtotal]').value = formatAmount(subtotalInCents);
            totalInCents += subtotalInCents;
        });

        form.querySelector('[data-order-total]').value = formatAmount(totalInCents);
        itemsError.hidden = rows.length > 0;
        addItemButton.disabled = rows.length >= 100;
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
        updateChoiceInputs(newRow);
        newRow.querySelector('input').focus();
        updateTotals();
    });

    items.addEventListener('input', updateTotals);
    items.addEventListener('change', (event) => {
        if (event.target.matches('[data-choice-select]')) {
            updateChoiceInputs(event.target.closest('[data-order-item]'));
        }
    });
    items.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-remove-item]');
        if (removeButton) {
            removeButton.closest('[data-order-item]').remove();
            addItemButton.focus();
            updateTotals();
        }
    });

    form.addEventListener('submit', (event) => {
        if (event.submitter?.hasAttribute('formnovalidate')) {
            return;
        }

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
        items.querySelectorAll('[data-order-item]').forEach(updateChoiceInputs);
        updateTotals();
    });

    items.querySelectorAll('[data-order-item]').forEach(updateChoiceInputs);
    updateTotals();
}
