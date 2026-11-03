const form = document.querySelector('[data-record-payment]');

if (form) {
    const amountInput = form.querySelector('[data-payment-amount]');
    const typeInput = form.querySelector('[data-payment-type]');
    const submitButton = form.querySelector('[data-submit-payment]');
    let isSubmitting = false;

    typeInput.addEventListener('change', () => {
        if (typeInput.value === 'full_payment') {
            amountInput.value = form.dataset.paymentBalance;
        }
    });

    form.addEventListener('submit', (event) => {
        if (isSubmitting) {
            event.preventDefault();
            return;
        }

        isSubmitting = true;
        submitButton.disabled = true;
        submitButton.textContent = 'Recording payment...';
        form.setAttribute('aria-busy', 'true');
    });

    window.addEventListener('pageshow', () => {
        isSubmitting = false;
        submitButton.disabled = false;
        submitButton.textContent = 'Record payment';
        form.removeAttribute('aria-busy');
    });
}
