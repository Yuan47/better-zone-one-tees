(() => {
    const form = document.getElementById('walk-in-checkout');
    if (!form) return;
    const variants = new Map(JSON.parse(document.getElementById('walk-in-variants').textContent).map(item => [String(item.id), item]));
    const lines = document.getElementById('walk-in-lines');
    const template = lines.firstElementChild?.cloneNode(true);
    const option = document.getElementById('walk-in-payment-option');
    const customerType = document.getElementById('walk-in-customer-type');
    const amount = document.getElementById('walk-in-amount');
    let automaticAmount = amount.dataset.autoAmount === '1';
    const partialMinimum = Number(form.dataset.partialMinimum);
    const currency = new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP'});
    let nextIndex = [...lines.querySelectorAll('[data-variant]')].reduce((next, input) => Math.max(next, Number(input.name.match(/lines\[(\d+)\]/)?.[1] ?? -1) + 1), 0);
    const money = value => currency.format(value / 100);
    const write = (selector, value) => { form.querySelector(selector).textContent = value; };
    const toggle = (selector, visible, required) => {
        form.querySelectorAll(selector).forEach(field => {
            field.hidden = !visible;
            field.querySelectorAll('input, select, textarea').forEach(input => {
                input.disabled = !visible;
                input.required = visible && required;
            });
        });
    };
    const update = () => {
        const partial = option.value.startsWith('partial_');
        toggle('[data-partial-field]', partial, true);
        toggle('[data-cash-field]', option.value.endsWith('_cash'), true);
        toggle('[data-guest-field]', customerType.value === 'guest', true);
        toggle('[data-registered-field]', customerType.value === 'registered', true);
        document.getElementById('walk-in-phone').required = option.value.endsWith('_paymongo') && customerType.value === 'guest';
        let quantity = 0;
        const entries = [...lines.children].map(row => {
            const item = variants.get(row.querySelector('[data-variant]').value);
            const count = Number(row.querySelector('[data-quantity]').value);
            const valid = item && Number.isInteger(count) && count > 0;
            if (valid) quantity += count;
            return {row, item, count, valid};
        });
        const tier = quantity >= 101 ? 'bulk' : quantity >= 51 ? 'reseller' : quantity >= 6 ? 'wholesale' : 'retail';
        const priceTier = tier === 'bulk' ? 'reseller' : tier;
        let base = 0;
        let stockIssue = false;
        const quantities = new Map();
        entries.forEach(({row, item, count, valid}) => {
            const label = row.querySelector('[data-line-price]');
            if (!valid) { label.textContent = 'Choose an item and enter a positive whole quantity.'; return; }
            const price = item[priceTier];
            base += price * count;
            quantities.set(item.id, (quantities.get(item.id) || 0) + count);
            label.textContent = `${money(price)} each · ${money(price * count)} before bulk discount · ${item.available} available`;
        });
        quantities.forEach((count, id) => { if (count > variants.get(String(id)).available || count > 10000) stockIssue = true; });
        const discount = tier === 'bulk' ? Math.round(base * 0.05) : 0;
        const total = base - discount;
        const initialMinimum = Math.ceil(total / 2);
        amount.min = (Math.max(100, initialMinimum) / 100).toFixed(2);
        amount.max = (Math.max(0, total - 100) / 100).toFixed(2);
        if (automaticAmount && total > 0) amount.value = (initialMinimum / 100).toFixed(2);
        const partialAmount = Number(amount.value);
        const payment = partial ? (Number.isFinite(partialAmount) && partialAmount > 0 ? Math.round(partialAmount * 100) : 0) : total;
        write('[data-summary-quantity]', quantity);
        write('[data-summary-tier]', {retail: 'Retail / SRP', wholesale: 'Wholesale', reseller: 'Re-seller', bulk: 'Bulk'}[tier]);
        write('[data-summary-base]', money(base));
        write('[data-summary-discount]', money(discount));
        write('[data-summary-total]', money(total));
        write('[data-summary-payment]', money(payment));
        write('[data-summary-balance]', money(Math.max(0, total - payment)));
        document.getElementById('walk-in-summary-note').textContent = stockIssue
            ? 'One or more quantities exceed available stock. Adjust the items before saving.'
            : partial && total <= partialMinimum
                ? `Partial payment is available for clothing totals above ${money(partialMinimum)}.`
            : partial && payment >= total && total > 0
                ? 'A partial payment must be below the invoice total. Choose full payment to pay the whole invoice.'
            : partial && payment < initialMinimum
                ? `The first payment must be at least ${money(initialMinimum)} (50% of the invoice total, rounded up).`
            : partial && total - payment > 0 && total - payment < 100
                ? 'Choose full payment, or leave at least ₱1.00 outstanding.'
                : 'Final prices and stock are checked when the invoice is saved.';
        entries.forEach(({row}) => { row.querySelector('[data-remove-line]').disabled = entries.length === 1; });
    };
    document.getElementById('walk-in-add-line').addEventListener('click', () => {
        if (!template || lines.children.length >= 100) return;
        const row = template.cloneNode(true);
        row.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace(/lines\[\d+\]/, `lines[${nextIndex}]`); });
        row.querySelector('[data-variant]').value = '';
        row.querySelector('[data-quantity]').value = '1';
        lines.appendChild(row);
        nextIndex++;
        update();
        row.querySelector('[data-variant]').focus();
    });
    lines.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-line]');
        if (button && lines.children.length > 1) { button.closest('[data-walk-in-line]').remove(); update(); }
    });
    form.addEventListener('input', event => { if (event.target === amount) automaticAmount = false; update(); });
    form.addEventListener('change', update);
    update();
})();
