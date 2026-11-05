const root = document.documentElement;
const themeButtons = document.querySelectorAll('[data-theme-toggle]');

const updateThemeButtons = () => {
    const isDark = root.dataset.theme === 'dark';
    themeButtons.forEach((button) => {
        button.hidden = false;
        button.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
        button.setAttribute('aria-pressed', String(isDark));
        button.querySelector('[data-theme-sun]').toggleAttribute('hidden', !isDark);
        button.querySelector('[data-theme-moon]').toggleAttribute('hidden', isDark);
    });
};

themeButtons.forEach((button) => {
    button.addEventListener('click', () => {
        root.dataset.theme = root.dataset.theme === 'dark' ? 'light' : 'dark';
        try {
            localStorage.setItem('oms.theme', root.dataset.theme);
        } catch {
            // Theme switching remains available when browser storage is disabled.
        }
        updateThemeButtons();
    });
});
updateThemeButtons();

const accountMenu = document.querySelector('.account-menu');
if (accountMenu) {
    document.addEventListener('click', (event) => {
        if (!accountMenu.contains(event.target)) {
            accountMenu.open = false;
        }
    });
    accountMenu.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            accountMenu.open = false;
            accountMenu.querySelector('summary').focus();
        }
    });
}

const menuButton = document.querySelector('[data-menu-toggle]');
if (menuButton) {
    menuButton.hidden = false;
    document.body.setAttribute('data-menu-ready', '');

    const closeMenu = () => {
        document.body.removeAttribute('data-navigation-open');
        menuButton.setAttribute('aria-expanded', 'false');
    };

    menuButton.addEventListener('click', () => {
        const expanded = document.body.toggleAttribute('data-navigation-open');
        menuButton.setAttribute('aria-expanded', String(expanded));
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && document.body.hasAttribute('data-navigation-open')) {
            closeMenu();
            menuButton.focus();
        }
    });

    window.matchMedia('(min-width: 1024px)').addEventListener('change', closeMenu);
}

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    const input = document.getElementById(button.getAttribute('aria-controls'));
    if (!input) {
        return;
    }
    button.hidden = false;
    button.addEventListener('click', () => {
        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        button.textContent = reveal ? 'Hide' : 'Show';
        button.setAttribute('aria-label', `${reveal ? 'Hide' : 'Show'} ${button.dataset.passwordLabel}`);
        button.setAttribute('aria-pressed', String(reveal));
    });
});

document.querySelectorAll('[data-order-step-link]').forEach((link) => {
    link.addEventListener('click', () => {
        const target = document.getElementById(link.hash.slice(1));
        if (!target) {
            return;
        }
        let disclosure = target.closest('details');
        while (disclosure) {
            disclosure.open = true;
            disclosure = disclosure.parentElement?.closest('details');
        }
        const focusTarget = target.matches('details') ? target.querySelector('summary') : target;
        focusTarget?.focus({ preventScroll: true });
    });
});

const pendingButtons = new Map();
document.addEventListener('submit', (event) => {
    const form = event.target.closest('[data-busy-form]');
    if (!form || event.defaultPrevented) {
        return;
    }
    const button = event.submitter ?? form.querySelector('button[type="submit"]');
    if (!button) {
        return;
    }
    pendingButtons.set(button, button.innerHTML);
    button.textContent = form.dataset.busyForm;
    button.disabled = true;
    form.setAttribute('aria-busy', 'true');
});

window.addEventListener('pageshow', () => {
    pendingButtons.forEach((originalContent, button) => {
        button.innerHTML = originalContent;
        button.disabled = false;
        button.closest('form').removeAttribute('aria-busy');
    });
    pendingButtons.clear();
});

const receipt = document.querySelector('input[name="receipt"]');
const receiptFeedback = document.querySelector('[data-receipt-feedback]');
if (receipt && receiptFeedback) {
    receipt.addEventListener('change', () => {
        const file = receipt.files?.[0];
        receiptFeedback.hidden = !file;
        if (file) {
            const size = (file.size / 1024 / 1024).toFixed(2);
            receiptFeedback.textContent = file.size > 5 * 1024 * 1024
                ? `${file.name} is ${size} MB. Choose a file no larger than 5 MB.`
                : `Selected: ${file.name} (${size} MB). Ready to attach when you record the payment.`;
            receiptFeedback.setAttribute('role', file.size > 5 * 1024 * 1024 ? 'alert' : 'status');
        }
    });
}
