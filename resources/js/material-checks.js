document.querySelectorAll('[data-material-checklist]').forEach((form) => {
    const rows = form.querySelector('[data-material-rows]');
    const template = form.querySelector('[data-material-template]');
    const addButton = form.querySelector('[data-material-add]');
    let nextIndex = rows.children.length;

    const updateRows = () => {
        const materialRows = [...rows.querySelectorAll('[data-material-row]')];
        materialRows.forEach((row, index) => {
            row.querySelector('[data-material-number]').textContent = index + 1;
            row.querySelector('[data-material-remove]').hidden = materialRows.length === 1;
        });
        addButton.disabled = materialRows.length >= 20;
    };

    addButton.hidden = false;
    addButton.addEventListener('click', () => {
        if (rows.children.length >= 20) {
            return;
        }
        const html = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
        rows.insertAdjacentHTML('beforeend', html);
        updateRows();
        rows.lastElementChild.querySelector('input').focus();
    });

    rows.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-material-remove]');
        if (!removeButton || rows.children.length === 1) {
            return;
        }
        removeButton.closest('[data-material-row]').remove();
        updateRows();
        addButton.focus();
    });
    updateRows();
});
