const form = document.querySelector('[data-create-order]');

if (form) {
    const items = form.querySelector('[data-order-items]');
    const itemTemplate = form.querySelector('[data-item-template]');
    const addItemButton = form.querySelector('[data-add-item]');
    const itemsError = form.querySelector('[data-items-error]');
    const submitButton = form.querySelector('[data-submit-order]');
    const section = form.querySelector('[data-order-items-section]');
    const tableBody = form.querySelector('[data-items-table-body]');
    const tableScroll = form.querySelector('[data-items-table-scroll]');
    const supportsDialogs = typeof HTMLDialogElement !== 'undefined' && typeof HTMLDialogElement.prototype.showModal === 'function';
    const wholeAmountFormatter = new Intl.NumberFormat('en-PH', { maximumFractionDigits: 0 });
    let nextItemIndex = 0;
    let isSubmitting = false;
    let activeEditor = null;

    const itemControls = (item) => Array.from(item.querySelectorAll('input, select, textarea'));
    const savedItems = () => Array.from(items.querySelectorAll('[data-order-item]')).filter((item) => !item.closest('[data-new-item]'));

    const showMessage = (message) => {
        const notice = section.querySelector('[data-items-message]');
        notice.textContent = message;
        notice.hidden = false;
    };

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

    const updateMeasurementInput = (row) => {
        const isWeight = row.querySelector('[data-measurement-unit]').value === 'kgs';
        const amountInput = row.querySelector('[data-quantity]');
        amountInput.min = isWeight ? '0.01' : '1';
        amountInput.step = isWeight ? '0.01' : '1';
        row.querySelector('[data-price-unit]').textContent = isWeight ? 'Price per kg.' : 'Price per piece.';
    };

    const decimalInHundredths = (value) => {
        if (!/^\d+(?:\.\d{1,2})?$/.test(value)) {
            return 0n;
        }

        const [whole, fraction = ''] = value.split('.');
        return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
    };

    const formatAmount = (cents) => `${wholeAmountFormatter.format(cents / 100n)}.${String(cents % 100n).padStart(2, '0')}`;

    const itemSubtotal = (item) => {
        const quantityInput = item.querySelector('[data-quantity]');
        const priceInput = item.querySelector('[data-unit-price]');
        return quantityInput.validity.valid && priceInput.validity.valid
            ? (decimalInHundredths(quantityInput.value) * decimalInHundredths(priceInput.value) + 50n) / 100n
            : 0n;
    };

    const refreshItems = ({ message = null, focusId = null } = {}) => {
        let totalInCents = 0n;
        const rows = savedItems();
        const replacement = document.createDocumentFragment();
        const scrollLeft = tableScroll.scrollLeft;

        rows.forEach((item, position) => {
            const index = item.dataset.itemIndex;
            const name = item.querySelector('[id$="-name"]').value;
            const quantity = item.querySelector('[data-quantity]').value;
            const unit = item.querySelector('[data-measurement-unit]').value;
            const price = item.querySelector('[data-unit-price]').value;
            const subtotalInCents = itemSubtotal(item);
            item.querySelector('[data-subtotal]').value = formatAmount(subtotalInCents);
            totalInCents += subtotalInCents;

            const row = document.createElement('tr');
            row.dataset.itemRow = index;
            row.className = 'align-top hover:bg-slate-50 dark:hover:bg-slate-800/40';
            const heading = document.createElement('th');
            heading.scope = 'row';
            heading.className = 'px-4 py-4';
            const number = document.createElement('span');
            number.className = 'mb-1 block text-xs font-normal text-slate-500 dark:text-slate-400';
            number.textContent = `Item ${position + 1}`;
            const label = document.createElement('span');
            label.id = `order-items-name-${index}`;
            label.tabIndex = -1;
            label.className = 'block max-w-64 break-words font-semibold';
            label.textContent = name || 'Untitled item';
            heading.append(number, label);
            row.append(heading);
            [`${quantity} ${unit}`, price ? formatAmount(decimalInHundredths(price)) : '0.00', formatAmount(subtotalInCents)].forEach((value) => {
                const cell = document.createElement('td');
                cell.className = 'px-4 py-4 text-right whitespace-nowrap tabular-nums';
                cell.textContent = value;
                row.append(cell);
            });
            const actions = document.createElement('td');
            actions.className = 'px-4 py-4';
            const edit = document.createElement('a');
            edit.href = `#order-item-dialog-${index}`;
            edit.dataset.editItem = index;
            edit.setAttribute('aria-haspopup', 'dialog');
            edit.setAttribute('aria-controls', `order-item-dialog-${index}`);
            edit.setAttribute('aria-label', `Edit item ${position + 1}: ${name || 'Untitled item'}`);
            edit.className = 'inline-flex min-h-11 items-center rounded-lg bg-brand-600 px-3 font-semibold text-white hover:bg-brand-700';
            edit.textContent = 'Edit item';
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.dataset.removeItem = index;
            remove.setAttribute('aria-label', `Remove item ${position + 1}: ${name || 'Untitled item'}`);
            remove.className = 'inline-flex min-h-11 items-center rounded-lg px-3 font-medium text-rose-700 hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-rose-950';
            remove.textContent = 'Remove item';
            actions.append(edit, remove);
            row.append(actions);
            replacement.append(row);
        });

        if (rows.length === 0) {
            const row = document.createElement('tr');
            const empty = document.createElement('td');
            empty.colSpan = 5;
            empty.className = 'px-4 py-8 text-center text-slate-500 dark:text-slate-400';
            empty.textContent = 'No items added. Choose Add item to start.';
            row.append(empty);
            replacement.append(row);
        }
        tableBody.replaceChildren(replacement);
        tableScroll.scrollLeft = scrollLeft;
        form.querySelector('[data-order-total]').value = formatAmount(totalInCents);
        if (rows.length > 0) {
            itemsError.hidden = true;
        }
        addItemButton.disabled = rows.length >= 100;
        if (message) {
            showMessage(message);
        }
        if (focusId) {
            (document.getElementById(focusId) ?? addItemButton).focus({ preventScroll: true });
        }
    };

    const openEditor = (dialog, trigger = null, isNew = false) => {
        if (activeEditor || isSubmitting) {
            return;
        }
        const item = dialog.querySelector('[data-order-item]');
        activeEditor = { dialog, trigger, isNew, saved: false, snapshot: itemControls(item).map((control) => [control, control.value]) };
        dialog.showModal();
        document.body.setAttribute('data-item-dialog-open', '');
        const focusTarget = item.querySelector('[aria-invalid="true"]:not([disabled])') ?? item.querySelector('input');
        focusTarget?.focus({ preventScroll: true });
    };

    const initializeItem = (item) => {
        updateChoiceInputs(item);
        updateMeasurementInput(item);
        if (!supportsDialogs) {
            return;
        }
        item.querySelector('[data-inline-remove]').hidden = true;
        const dialog = item.closest('dialog');
        dialog.querySelector('[data-save-item]').hidden = false;
        dialog.addEventListener('close', () => {
            if (activeEditor?.dialog !== dialog) {
                return;
            }
            const editor = activeEditor;
            activeEditor = null;
            document.body.removeAttribute('data-item-dialog-open');
            if (!editor.saved) {
                if (editor.isNew) {
                    dialog.remove();
                } else {
                    editor.snapshot.forEach(([control, value]) => { control.value = value; });
                    updateChoiceInputs(item);
                    updateMeasurementInput(item);
                    item.querySelector('[data-subtotal]').value = formatAmount(itemSubtotal(item));
                }
            }
            if (editor.saved) {
                refreshItems({ message: editor.isNew ? 'Item added.' : 'Item updated.', focusId: `order-items-name-${item.dataset.itemIndex}` });
            } else {
                (editor.trigger?.isConnected ? editor.trigger : addItemButton).focus({ preventScroll: true });
            }
        });
    };

    const saveEditor = () => {
        if (!activeEditor) {
            return;
        }
        const item = activeEditor.dialog.querySelector('[data-order-item]');
        updateChoiceInputs(item);
        updateMeasurementInput(item);
        const invalid = itemControls(item).find((control) => !control.disabled && !control.checkValidity());
        if (invalid) {
            invalid.reportValidity();
            return;
        }
        activeEditor.saved = true;
        activeEditor.dialog.removeAttribute('data-new-item');
        activeEditor.dialog.removeAttribute('data-item-auto-open');
        item.querySelectorAll('[aria-invalid]').forEach((control) => {
            control.removeAttribute('aria-invalid');
            control.removeAttribute('aria-describedby');
        });
        item.querySelectorAll('p[id$="-error"]').forEach((error) => error.remove());
        activeEditor.dialog.querySelector('[data-checklist-server-errors]')?.remove();
        activeEditor.dialog.close();
    };

    addItemButton.addEventListener('click', () => {
        if (savedItems().length >= 100 || activeEditor || isSubmitting) {
            return;
        }

        while (document.getElementById(`item-${nextItemIndex}-name`)) {
            nextItemIndex += 1;
        }

        const template = document.createElement('template');
        template.innerHTML = itemTemplate.innerHTML.replaceAll('__INDEX__', String(nextItemIndex));
        nextItemIndex += 1;
        const dialog = template.content.firstElementChild;
        items.append(dialog);
        initializeItem(dialog.querySelector('[data-order-item]'));
        dialog.setAttribute('data-new-item', '');
        openEditor(dialog, addItemButton, true);
    });

    items.addEventListener('input', (event) => {
        const item = event.target.closest('[data-order-item]');
        if (item) {
            item.querySelector('[data-subtotal]').value = formatAmount(itemSubtotal(item));
        }
    });
    items.addEventListener('change', (event) => {
        if (event.target.matches('[data-measurement-unit]')) {
            updateMeasurementInput(event.target.closest('[data-order-item]'));
            const item = event.target.closest('[data-order-item]');
            item.querySelector('[data-subtotal]').value = formatAmount(itemSubtotal(item));
        }
        if (event.target.matches('[data-choice-select]')) {
            updateChoiceInputs(event.target.closest('[data-order-item]'));
        }
    });
    section.addEventListener('click', (event) => {
        const editButton = event.target.closest('[data-edit-item]');
        if (editButton && supportsDialogs) {
            event.preventDefault();
            openEditor(document.getElementById(editButton.getAttribute('aria-controls')), editButton);
            return;
        }
        if (event.target.closest('[data-save-item]')) {
            saveEditor();
            return;
        }
        if (event.target.closest('[data-item-close]')) {
            activeEditor?.dialog.close();
            return;
        }
        if (event.target.closest('[data-items-refresh]') && !activeEditor && !isSubmitting) {
            refreshItems({ message: 'Items refreshed.', focusId: 'order-items-refresh' });
            return;
        }
        const removeButton = event.target.closest('[data-remove-item]');
        if (removeButton && !activeEditor && !isSubmitting) {
            const dialog = document.getElementById(`order-item-dialog-${removeButton.dataset.removeItem}`) ?? removeButton.closest('dialog');
            dialog?.remove();
            refreshItems({ message: 'Item removed.' });
            addItemButton.focus({ preventScroll: true });
        }
    });

    items.addEventListener('keydown', (event) => {
        if (activeEditor && event.key === 'Enter' && !event.isComposing && event.target.matches('input')) {
            event.preventDefault();
            saveEditor();
        }
    });

    form.addEventListener('submit', (event) => {
        if (event.submitter?.hasAttribute('formnovalidate')) {
            return;
        }

        if (activeEditor) {
            event.preventDefault();
            saveEditor();
            return;
        }
        if (isSubmitting || savedItems().length === 0) {
            event.preventDefault();
            itemsError.hidden = false;
            addItemButton.focus();
            return;
        }

        if (supportsDialogs) {
            const invalid = Array.from(form.elements).find((control) => typeof control.checkValidity === 'function' && !control.disabled && !control.checkValidity());
            if (invalid) {
                event.preventDefault();
                const dialog = invalid.closest('[data-item-dialog]');
                if (dialog) {
                    openEditor(dialog, section.querySelector(`[data-edit-item="${dialog.querySelector('[data-order-item]').dataset.itemIndex}"]`));
                }
                invalid.reportValidity();
                return;
            }
        }

        isSubmitting = true;
        submitButton.disabled = true;
        submitButton.textContent = 'Creating order...';
        form.setAttribute('aria-busy', 'true');
    });

    window.addEventListener('pageshow', () => {
        isSubmitting = false;
        submitButton.disabled = false;
        submitButton.textContent = 'Save order and continue to payment';
        form.removeAttribute('aria-busy');
        if (!activeEditor) {
            items.querySelectorAll('[data-order-item]').forEach(updateChoiceInputs);
            items.querySelectorAll('[data-order-item]').forEach(updateMeasurementInput);
            refreshItems();
        }
    });

    if (supportsDialogs) {
        form.noValidate = true;
        addItemButton.hidden = false;
        section.querySelector('[data-items-refresh]').hidden = false;
        if (items.hasAttribute('data-initial-item')) {
            items.replaceChildren();
        }
    } else {
        items.querySelectorAll('[data-item-dialog]').forEach((dialog) => {
            dialog.setAttribute('open', '');
            dialog.style.position = 'static';
            dialog.querySelectorAll('[data-item-close]').forEach((button) => { button.hidden = true; });
        });
        section.querySelectorAll('[data-item-row-actions]').forEach((actions) => { actions.hidden = true; });
    }
    items.querySelectorAll('[data-order-item]').forEach(initializeItem);
    refreshItems();
    if (supportsDialogs) {
        const failedDialog = items.querySelector('[data-item-auto-open]');
        if (failedDialog) {
            openEditor(failedDialog, section.querySelector(`[aria-controls="${CSS.escape(failedDialog.id)}"]`));
        }
    }
}
