(() => {
    'use strict';

    document.querySelectorAll('table[data-sortable]:not([data-server-sort])').forEach((table) => {
        if (table.closest('form') || table.querySelector('input, select, textarea, [contenteditable="true"]')) return;
        const body = table.tBodies[0];
        const original = body ? Array.from(body.rows) : [];
        const sortable = original.filter((row) => row.hasAttribute('data-sort-row'));
        if (!body || sortable.length < 2) return;

        const positions = new Map(original.map((row, index) => [row, index]));
        const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });
        const headers = Array.from(table.tHead?.rows[0]?.cells || []);
        const controls = [];
        let active = null;
        let direction = 'default';

        const status = document.createElement('p');
        status.className = 'table-sort-status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.textContent = 'Sorting: Default';
        table.insertAdjacentElement('afterend', status);

        const valueFor = (row, column, type) => {
            const cell = row.cells[column];
            const raw = cell?.getAttribute('data-sort-value');
            if (raw === null || raw === undefined || raw.trim() === '') return null;
            if (type === 'number') {
                const number = Number(raw);
                return Number.isFinite(number) ? number : null;
            }
            return raw;
        };

        headers.forEach((header, column) => {
            const type = header.getAttribute('data-sort-type');
            if (!['number', 'text', 'date'].includes(type)) return;
            const label = header.textContent.trim();
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'table-sort-button';
            const name = document.createElement('span');
            name.textContent = label;
            const indicator = document.createElement('span');
            indicator.className = 'table-sort-indicator';
            indicator.setAttribute('aria-hidden', 'true');
            button.append(name, indicator);
            header.replaceChildren(button);
            header.scope = 'col';
            controls.push({ header, button, indicator, label, column, type });

            button.addEventListener('click', () => {
                const first = type === 'text' ? 'ascending' : 'descending';
                const second = first === 'ascending' ? 'descending' : 'ascending';
                direction = active !== column ? first : direction === first ? second : 'default';
                active = direction === 'default' ? null : column;
                let ordered = [...sortable];
                if (direction !== 'default') {
                    ordered.sort((left, right) => {
                        const a = valueFor(left, column, type);
                        const b = valueFor(right, column, type);
                        if (a === null || b === null) {
                            return a === b ? positions.get(left) - positions.get(right) : a === null ? 1 : -1;
                        }
                        const compared = type === 'number' ? a - b : type === 'date' ? (a < b ? -1 : a > b ? 1 : 0) : collator.compare(a, b);
                        return compared === 0 ? positions.get(left) - positions.get(right) : compared * (direction === 'descending' ? -1 : 1);
                    });
                }
                let index = 0;
                // Keep explanatory/empty rows in place and restore the exact initial order on Default.
                original.forEach((row) => body.append(row.hasAttribute('data-sort-row') ? ordered[index++] : row));
                update();
            });
        });

        function update() {
            controls.forEach(({ header, button, indicator, label, column, type }) => {
                const current = active === column ? direction : 'default';
                const first = type === 'text' ? 'ascending' : 'descending';
                const next = current === 'default' ? first : current === first ? (first === 'ascending' ? 'descending' : 'ascending') : 'default';
                const description = type === 'text'
                    ? { descending: 'Z to A', ascending: 'A to Z', default: 'Default' }
                    : type === 'date'
                        ? { descending: 'Newest to Oldest', ascending: 'Oldest to Newest', default: 'Default' }
                        : { descending: 'Highest to Lowest', ascending: 'Lowest to Highest', default: 'Default' };
                header.setAttribute('aria-sort', current === 'default' ? 'none' : current);
                button.setAttribute('aria-label', `${label}. ${description[current]}. Click for ${description[next]}.`);
                button.title = `Click for ${description[next]}`;
                indicator.textContent = current === 'descending' ? '↓' : current === 'ascending' ? '↑' : '↕';
                if (active === column) status.textContent = `Sorting: ${label} · ${description[current]}`;
            });
            if (active === null) status.textContent = 'Sorting: Default';
        }
        update();
    });
})();
