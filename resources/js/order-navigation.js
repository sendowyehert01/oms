const loadingIndicator = document.querySelector('[data-order-loading]');
const mainContent = document.getElementById('main-content');

if (loadingIndicator && mainContent) {
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-view-order]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.target === '_blank') {
            return;
        }

        loadingIndicator.hidden = false;
        mainContent.setAttribute('aria-busy', 'true');
    });

    window.addEventListener('pageshow', () => {
        loadingIndicator.hidden = true;
        mainContent.removeAttribute('aria-busy');
    });
}
