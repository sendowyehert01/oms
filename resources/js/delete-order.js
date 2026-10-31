const dialog = document.getElementById('delete-order-dialog');

if (dialog && typeof dialog.showModal === 'function') {
    const confirmButton = dialog.querySelector('[data-confirmation-confirm]');
    const cancelButton = dialog.querySelector('[data-confirmation-cancel]');
    const dialogError = dialog.querySelector('[data-confirmation-error]');
    const successNotice = document.querySelector('[data-order-success]');
    const errorNotice = document.querySelector('[data-order-error]');
    let selectedButton = null;
    let isDeleting = false;

    const enableDeleteButtons = () => {
        document.querySelectorAll('[data-delete-order]').forEach((button) => {
            button.disabled = isDeleting;
        });
    };

    const setProcessing = (processing) => {
        isDeleting = processing;
        confirmButton.disabled = processing;
        cancelButton.disabled = processing;
        confirmButton.textContent = processing ? 'Deleting...' : 'Confirm Delete';
        dialog.setAttribute('aria-busy', String(processing));
        enableDeleteButtons();
    };

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-delete-order]');
        if (!button || isDeleting) {
            return;
        }

        selectedButton = button;
        dialog.querySelector('[data-delete-message]').textContent = `Are you sure you want to delete Order ${button.dataset.orderNumber}? This action cannot be undone.`;
        dialogError.hidden = true;
        successNotice.hidden = true;
        errorNotice.hidden = true;
        dialog.showModal();
    });

    dialog.addEventListener('cancel', (event) => {
        if (isDeleting) {
            event.preventDefault();
        }
    });

    dialog.addEventListener('close', () => {
        if (selectedButton?.isConnected) {
            selectedButton.focus();
        }
        selectedButton = null;
    });

    confirmButton.addEventListener('click', async () => {
        if (isDeleting || !selectedButton) {
            return;
        }

        setProcessing(true);
        dialogError.hidden = true;
        let deletionResult = null;

        try {
            const response = await fetch(selectedButton.dataset.deleteUrl, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ confirmed: true }),
            });
            const result = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw new Error(result.message || (response.status === 419 ? 'Your session has expired. Reload the page and try again.' : 'The order could not be deleted. Please try again.'));
            }

            deletionResult = result;
            const updatedDocument = new DOMParser().parseFromString(result.html || '', 'text/html');
            const updatedList = updatedDocument.querySelector('[data-orders-list]');
            if (!updatedList) {
                window.location.assign(result.redirect_url || document.querySelector('[data-orders-list]').dataset.listUrl);
                return;
            }

            document.querySelector('[data-orders-list]').replaceWith(updatedList);
            window.history.replaceState(window.history.state, '', updatedList.dataset.listUrl);
            setProcessing(false);
            dialog.close();
            successNotice.textContent = result.message;
            successNotice.hidden = false;
            (document.querySelector('[data-delete-order], [data-view-order]') || document.getElementById('order-search'))?.focus();
        } catch (error) {
            if (deletionResult) {
                window.location.assign(deletionResult.redirect_url || document.querySelector('[data-orders-list]').dataset.listUrl);
                return;
            }

            setProcessing(false);
            const message = error instanceof TypeError ? 'The server could not be reached. Please check your connection and try again.' : error.message;
            dialogError.textContent = message;
            dialogError.hidden = false;
            errorNotice.textContent = message;
            errorNotice.hidden = false;
            confirmButton.focus();
        }
    });

    window.addEventListener('pageshow', () => {
        setProcessing(false);
        if (dialog.open) {
            dialog.close();
        }
    });

    enableDeleteButtons();
}
