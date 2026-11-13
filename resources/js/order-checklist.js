let checklist = document.querySelector('[data-order-checklist]');

if (checklist && typeof HTMLDialogElement !== 'undefined' && typeof HTMLDialogElement.prototype.showModal === 'function') {
    let processing = false;
    let refreshRequired = false;
    const pendingButtons = new Map();
    const dialogTriggers = new WeakMap();

    const showMessage = (message, isError = false) => {
        const notice = checklist.querySelector(isError ? '[data-checklist-error]' : '[data-checklist-message]');
        checklist.querySelector(isError ? '[data-checklist-message]' : '[data-checklist-error]').hidden = true;
        notice.textContent = message;
        notice.hidden = false;
        return notice;
    };

    const openDialog = (dialog, trigger = null) => {
        if (processing || (refreshRequired && dialog.querySelector('[data-checklist-form]'))) {
            if (refreshRequired && !processing) {
                showMessage('Refresh the checklist before making another change.', true).focus({ preventScroll: true });
            }
            return;
        }
        dialogTriggers.set(dialog, trigger);
        dialog.closest('details')?.setAttribute('open', '');
        dialog.showModal();
        document.body.setAttribute('data-checklist-dialog-open', '');
        const focusTarget = dialog.querySelector('[aria-invalid="true"]:not([type="hidden"])')
            ?? dialog.querySelector('select, input:not([type="hidden"]), textarea')
            ?? dialog.querySelector('h3');
        focusTarget?.focus({ preventScroll: true });
    };

    const initializeChecklist = () => {
        checklist.querySelector('#order-checklist-refresh').hidden = false;
        checklist.querySelectorAll('[data-checklist-dialog]').forEach((dialog) => {
            dialog.addEventListener('cancel', (event) => {
                if (processing) {
                    event.preventDefault();
                }
            });
            dialog.addEventListener('close', () => {
                if (!document.querySelector('[data-checklist-dialog][open]')) {
                    document.body.removeAttribute('data-checklist-dialog-open');
                }
                const trigger = dialogTriggers.get(dialog);
                if (trigger?.isConnected) {
                    trigger.focus({ preventScroll: true });
                }
            });
        });
        const failedDialog = checklist.querySelector('[data-checklist-auto-open]');
        if (failedDialog) {
            openDialog(failedDialog, checklist.querySelector('[aria-controls="' + CSS.escape(failedDialog.id) + '"]'));
        }
    };

    const setBusy = (isBusy, form = null, submitter = null) => {
        processing = isBusy;
        checklist.setAttribute('aria-busy', String(isBusy));
        if (isBusy) {
            checklist.querySelectorAll('button').forEach((button) => {
                pendingButtons.set(button, { disabled: button.disabled, content: button.innerHTML });
                button.disabled = true;
            });
            if (submitter) {
                submitter.textContent = form.dataset.busyForm;
            }
        } else {
            pendingButtons.forEach((original, button) => {
                button.disabled = original.disabled;
                button.innerHTML = original.content;
            });
            pendingButtons.clear();
        }
        checklist.querySelector('[data-checklist-refresh-label]').textContent = isBusy ? 'Refreshing...' : 'Refresh checklist';
        checklist.querySelector('#order-checklist-refresh svg').classList.toggle('animate-spin', isBusy);
        form?.setAttribute('aria-busy', String(isBusy));
        form?.closest('dialog')?.setAttribute('aria-busy', String(isBusy));
        checklist.querySelectorAll('a[aria-haspopup="dialog"]').forEach((link) => {
            const dialog = checklist.querySelector('#' + CSS.escape(link.getAttribute('aria-controls')));
            link.setAttribute('aria-disabled', String(isBusy || (refreshRequired && Boolean(dialog?.querySelector('[data-checklist-form]')))));
        });
    };

    const clearFormErrors = (dialog) => {
        dialog.querySelector('[data-checklist-dialog-error]').hidden = true;
        dialog.querySelector('[data-checklist-refresh]').hidden = true;
        dialog.querySelector('[data-checklist-server-errors]')?.remove();
        dialog.querySelectorAll('[aria-invalid]').forEach((control) => {
            control.removeAttribute('aria-invalid');
            control.removeAttribute('aria-describedby');
        });
    };

    const showFormErrors = (dialog, message, errors = {}) => {
        const notice = dialog.querySelector('[data-checklist-dialog-error]');
        notice.replaceChildren();
        const heading = document.createElement('p');
        heading.textContent = message;
        notice.append(heading);
        const list = document.createElement('ul');
        list.className = 'mt-2 list-inside list-disc';
        Object.entries(errors).forEach(([field, messages]) => {
            const entry = document.createElement('li');
            entry.id = dialog.id + '-' + field + '-error';
            entry.textContent = Array.isArray(messages) ? messages.join(' ') : String(messages);
            list.append(entry);
            const control = dialog.querySelector('[name="' + CSS.escape(field) + '"]');
            if (control && !control.matches('[type="hidden"]')) {
                control.setAttribute('aria-invalid', 'true');
                control.setAttribute('aria-describedby', entry.id);
            }
        });
        if (list.children.length > 0) {
            notice.append(list);
        }
        notice.hidden = false;
        dialog.querySelector('[data-checklist-refresh]').hidden = !refreshRequired && !errors.workflow_version && !errors.action && !errors.submission_key;
        notice.focus({ preventScroll: true });
    };

    const captureDraft = (dialog) => {
        const form = dialog?.querySelector('[data-checklist-form]');
        return form ? { dialogId: dialog.id, action: form.elements.action.value, fields: new FormData(form) } : null;
    };

    const refreshChecklist = async ({ message = 'Checklist refreshed.', savedVersion = null, focusId = 'order-checklist-refresh', draft = null } = {}) => {
        const previousVersion = checklist.dataset.workflowVersion;
        const expandedSteps = Array.from(checklist.querySelectorAll('details[open]'), (details) => details.id);
        const tableScroll = checklist.querySelector('[aria-label="Item checklist table"]')?.scrollLeft ?? 0;
        const response = await fetch(checklist.dataset.checklistUrl, {
            headers: { Accept: 'text/html', 'X-Order-Checklist-Fragment': '1', 'X-Order-Checklist-History': '1' },
            credentials: 'same-origin',
            cache: 'no-store',
            signal: AbortSignal.timeout(30000),
        });
        if (!response.ok) {
            throw new Error(response.status === 404 ? 'This order is no longer available.' : 'The checklist could not be refreshed. Please try again.');
        }
        const documentFragment = new DOMParser().parseFromString(await response.text(), 'text/html');
        const replacement = documentFragment.querySelector('[data-order-checklist]');
        if (!replacement || replacement.dataset.orderId !== checklist.dataset.orderId) {
            throw new Error('Your session may have ended. Refresh the page and sign in again before continuing.');
        }
        replacement.querySelectorAll('noscript').forEach((fallback) => fallback.remove());
        const relatedStatus = document.querySelector('[data-order-info-status]');
        if (relatedStatus) {
            relatedStatus.replaceChildren(...replacement.querySelector('[data-checklist-status]').cloneNode(true).childNodes);
        }
        if (replacement.dataset.workflowVersion !== previousVersion || savedVersion !== null) {
            const history = documentFragment.querySelector('[data-checklist-history]')?.content.querySelector('[data-order-history]');
            if (history) {
                document.querySelector('[data-order-history]')?.replaceWith(history);
            }
        }
        checklist.querySelector('dialog[open]')?.close();
        checklist.replaceWith(replacement);
        checklist = replacement;
        refreshRequired = false;
        expandedSteps.forEach((id) => {
            checklist.querySelector('#' + CSS.escape(id))?.setAttribute('open', '');
        });
        const table = checklist.querySelector('[aria-label="Item checklist table"]');
        if (table) {
            table.scrollLeft = tableScroll;
        }
        if (savedVersion !== null && Number(checklist.dataset.workflowVersion) === savedVersion) {
            document.querySelectorAll('form input[name="workflow_version"]').forEach((input) => {
                if (!checklist.contains(input) && input.value === previousVersion) {
                    input.value = checklist.dataset.workflowVersion;
                }
            });
        }
        initializeChecklist();
        showMessage(message);
        const restoredDialog = draft ? checklist.querySelector('#' + CSS.escape(draft.dialogId)) : null;
        const restoredForm = restoredDialog?.querySelector('[data-checklist-form]');
        if (restoredForm && restoredForm.elements.action.value === draft.action) {
            restoredForm.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach((control) => {
                if (control.type === 'checkbox') {
                    control.checked = draft.fields.has(control.name);
                } else if (draft.fields.has(control.name)) {
                    control.value = draft.fields.get(control.name);
                }
            });
            return restoredDialog;
        }
        (document.getElementById(focusId) ?? checklist.querySelector('#order-checklist-heading')).focus({ preventScroll: true });
        return null;
    };

    document.addEventListener('click', async (event) => {
        const target = event.target.closest('a[data-order-step-link], [data-checklist-close], [data-checklist-refresh]');
        if (!target || !checklist.contains(target) || event.defaultPrevented) {
            return;
        }
        if (target.matches('[data-checklist-close]')) {
            if (!processing) {
                target.closest('dialog').close();
            }
            return;
        }
        if (target.matches('[data-checklist-refresh]')) {
            if (processing) {
                return;
            }
            event.preventDefault();
            const draft = captureDraft(target.closest('dialog'));
            setBusy(true);
            let restoredDialog = null;
            try {
                restoredDialog = await refreshChecklist({ draft });
            } catch (error) {
                const dialog = target.closest('dialog');
                if (dialog) {
                    showFormErrors(dialog, error.message);
                    dialog.querySelector('[data-checklist-refresh]').hidden = false;
                } else {
                    showMessage(error.message, true).focus({ preventScroll: true });
                }
            } finally {
                setBusy(false);
            }
            if (restoredDialog) {
                openDialog(restoredDialog, checklist.querySelector('[aria-controls="' + CSS.escape(restoredDialog.id) + '"]'));
            }
            return;
        }
        const dialog = checklist.querySelector('#' + CSS.escape(target.hash.slice(1)));
        if (dialog?.matches('dialog')) {
            event.preventDefault();
            if (!dialog.open) {
                openDialog(dialog, target);
            }
        }
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-checklist-form]');
        if (!form || !checklist.contains(form)) {
            return;
        }
        event.preventDefault();
        const dialog = form.closest('dialog');
        if (processing) {
            return;
        }
        if (refreshRequired) {
            showFormErrors(dialog, 'Refresh the checklist before trying again.');
            return;
        }
        const body = new FormData(form);
        const itemId = body.get('order_item_id');
        const trigger = dialogTriggers.get(dialog);
        clearFormErrors(dialog);
        setBusy(true, form, event.submitter);
        let saved = false;
        let responseReceived = false;
        try {
            const response = await fetch(form.getAttribute('action'), {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-Order-Checklist-Fragment': '1', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body,
                signal: AbortSignal.timeout(30000),
            });
            const result = await response.json();
            responseReceived = true;
            if (!response.ok) {
                if (response.status === 401 || response.status === 419) {
                    showFormErrors(dialog, 'Your session has ended. Refresh the page and sign in again before continuing.');
                } else {
                    showFormErrors(dialog, result.message ?? 'The change could not be saved. Please try again.', result.errors ?? {});
                }
                return;
            }
            saved = true;
            refreshRequired = true;
            await refreshChecklist({
                message: result.message,
                savedVersion: result.workflow_version,
                focusId: itemId ? 'order-checklist-item-' + itemId : (trigger?.id ?? 'order-checklist-heading'),
            });
        } catch (error) {
            refreshRequired = saved || !responseReceived;
            if (saved) {
                dialog.close();
                showMessage('Your change was saved, but the checklist could not be refreshed. Use Refresh checklist to see the latest progress.', true).focus({ preventScroll: true });
            } else {
                showFormErrors(dialog, 'The connection was interrupted. Refresh the checklist to check whether your change was saved before trying again.');
            }
        } finally {
            setBusy(false, form);
        }
    }, true);

    initializeChecklist();
}
