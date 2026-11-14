(() => {
    'use strict';

    const root = document.querySelector('[data-report-live]');
    if (!root) return;

    const form = root.querySelector('[data-report-filters]');
    const status = root.querySelector('[data-report-status]');
    const updated = root.querySelector('[data-report-updated]');
    const buttons = [...root.querySelectorAll('[data-report-refresh]')];
    const interval = Math.max(15000, Math.min(60000, Number(root.dataset.reportInterval) || 15000));
    const idleLimit = 15 * 60 * 1000;
    const rawRegions = new Map();
    let timer, idleTimer, request, failures = 0, dirty = false, stopped = false, ended = false;
    let lastActivity = Date.now();
    let refreshUrl;

    try {
        refreshUrl = new URL(root.dataset.reportRefreshUrl, location.href);
        if (refreshUrl.origin !== location.origin) return;
    } catch (_) { return; }

    const readyMessage = 'Auto refresh is on · checks every ' + Math.round(interval / 1000) + ' seconds.';
    const isIdle = () => Date.now() - lastActivity >= idleLimit;
    const canRefresh = () => !dirty && !stopped && !ended && !document.hidden && navigator.onLine !== false && !isIdle();

    function message(text, state = 'ready') {
        if (status) {
            if (status.textContent !== text) status.textContent = text;
            status.dataset.state = state;
        }
    }

    function busy(value) {
        buttons.forEach((button) => {
            button.disabled = value || stopped;
            button.setAttribute('aria-busy', String(value));
        });
    }

    function cancel() {
        clearTimeout(timer);
        if (request) request.abort();
    }

    function schedule() {
        clearTimeout(timer);
        if (canRefresh()) timer = setTimeout(() => refresh(false), Math.min(120000, interval * 2 ** failures));
    }

    function armIdlePause() {
        clearTimeout(idleTimer);
        idleTimer = setTimeout(() => {
            if (stopped || ended || !isIdle()) return;
            cancel();
            message('Auto refresh paused after 15 minutes without activity. Use the page or Refresh data to continue.', 'paused');
        }, Math.max(1, idleLimit - (Date.now() - lastActivity)));
    }

    function activity(event) {
        // Programmatic form updates, report rendering and timer activity are not user activity.
        if (event && event.isTrusted === false) return;
        const wasIdle = isIdle();
        lastActivity = Date.now();
        armIdlePause();
        if (wasIdle && canRefresh()) refresh(false);
    }

    function regionMap(scope) {
        const result = new Map();
        scope.querySelectorAll('[data-report-region]').forEach((region) => {
            const key = region.dataset.reportRegion;
            if (!key || result.has(key) || region.querySelector('[data-report-region]')) throw new Error('Invalid report regions');
            result.set(key, region);
        });
        if (!result.size) throw new Error('Missing report regions');
        return result;
    }

    function saveState(region) {
        const scrolls = [...region.querySelectorAll('.table-scroll,.revenue-chart,.forecast-chart-scroll,.sales-chart-scroll')]
            .map((element) => ({left: element.scrollLeft, top: element.scrollTop}));
        return {
            details: [...region.querySelectorAll('details')].map((element) => ({
                label: element.querySelector('summary')?.textContent.trim(), open: element.open,
            })),
            scrolls,
            tables: [...region.querySelectorAll('table[data-sortable]')].map((table) => {
                const column = table.dataset.sortColumn;
                const header = column === undefined || column === '' ? null : table.tHead?.rows[0]?.cells[Number(column)];
                return {column, direction: table.dataset.sortDirection,
                    label: header?.querySelector('.table-sort-button span')?.textContent.trim() || header?.textContent.trim()};
            }),
        };
    }

    function restoreState(region, state) {
        region.querySelectorAll('details').forEach((element, index) => {
            const previous = state.details[index];
            if (previous && previous.label === element.querySelector('summary')?.textContent.trim()) element.open = previous.open;
        });
        region.querySelectorAll('table[data-sortable]').forEach((table, index) => {
            const previous = state.tables[index];
            if (!previous || previous.column === undefined || previous.column === '') return;
            const header = table.tHead?.rows[0]?.cells[Number(previous.column)];
            if (!header || header.textContent.trim() !== previous.label) return;
            table.dataset.sortColumn = previous.column;
            table.dataset.sortDirection = previous.direction;
        });
        const restoreScrolls = () => region.querySelectorAll('.table-scroll,.revenue-chart,.forecast-chart-scroll,.sales-chart-scroll')
            .forEach((element, index) => {
                const previous = state.scrolls[index];
                if (!previous) return;
                element.dataset.scrollPositioned = 'true';
                element.scrollLeft = previous.left;
                element.scrollTop = previous.top;
            });
        restoreScrolls();
        return restoreScrolls;
    }

    function lockReports() {
        stopped = true;
        cancel();
        root.querySelectorAll('[data-report-region]').forEach((region) => region.replaceChildren());
        rawRegions.clear();
        const guidance = document.createElement('p');
        guidance.className = 'report-access-message';
        guidance.textContent = 'Your session or workspace access changed. Sign in again to view reports. ';
        let loginUrl;
        try {
            loginUrl = new URL(root.dataset.reportLoginUrl || '/admin/login', location.href);
            if (loginUrl.origin !== location.origin) loginUrl = new URL('/admin/login', location.origin);
        } catch (_) { loginUrl = new URL('/admin/login', location.origin); }
        const link = document.createElement('a');
        link.className = 'text-link';
        link.href = loginUrl.href;
        link.textContent = 'Open workspace sign-in →';
        guidance.appendChild(link);
        root.querySelector('[data-report-region]')?.appendChild(guidance);
        message('Sign in again to view updated reports.', 'error');
        busy(false);
    }

    function applySnapshot(payload) {
        if (!payload || typeof payload.html !== 'string' || typeof payload.checked_at !== 'string'
            || !Number.isFinite(Date.parse(payload.checked_at))) throw new Error('Invalid report snapshot');
        const parsed = new DOMParser().parseFromString(payload.html, 'text/html');
        const incomingRoot = parsed.querySelector('[data-report-live]');
        if (!incomingRoot) throw new Error('Missing report page');
        const current = regionMap(root), incoming = regionMap(incomingRoot);
        if (current.size !== incoming.size || [...current.keys()].some((key) => !incoming.has(key))) throw new Error('Report version changed');

        // Validate every replacement first; a failed response must not produce a half-updated report.
        const changes = [];
        current.forEach((region, key) => {
            const replacement = incoming.get(key);
            if (replacement.querySelector('script,form,input,select,textarea')) throw new Error('Unexpected report controls');
            const html = replacement.innerHTML;
            if (rawRegions.get(key) !== html) changes.push({region, key, html, state: saveState(region)});
        });

        const focus = document.activeElement;
        const focusRegion = focus?.closest('[data-report-region]');
        const focusKey = focusRegion?.dataset.reportRegion;
        const focusIdentity = focusRegion && focus ? {
            tag: focus.tagName, id: focus.id, href: focus.getAttribute('href'),
            text: focus.textContent.trim(),
        } : null;
        const focusedButton = focusRegion && focus?.classList.contains('table-sort-button')
            ? {table: [...focusRegion.querySelectorAll('table')].indexOf(focus.closest('table')),
                column: focus.closest('th')?.cellIndex} : null;
        const scrollRestorers = [];
        changes.forEach(({region, key, html, state}) => {
            region.innerHTML = html;
            rawRegions.set(key, html);
            scrollRestorers.push(restoreState(region, state));
        });
        if (changes.length) {
            document.dispatchEvent(new CustomEvent('reports:updated'));
            requestAnimationFrame(() => {
                scrollRestorers.forEach((restore) => restore());
                if (focusedButton && focusKey) {
                    const table = current.get(focusKey)?.querySelectorAll('table')[focusedButton.table];
                    table?.tHead?.rows[0]?.cells[focusedButton.column]?.querySelector('button')?.focus({preventScroll: true});
                } else if (focusIdentity && focusKey && !focus.isConnected) {
                    const matching = [...current.get(focusKey).querySelectorAll('a,button,summary,[tabindex]')].find((element) =>
                        element.tagName === focusIdentity.tag && (focusIdentity.id ? element.id === focusIdentity.id
                            : focusIdentity.href ? element.getAttribute('href') === focusIdentity.href
                                : element.textContent.trim() === focusIdentity.text));
                    matching?.focus({preventScroll: true});
                }
            });
        }
        if (form) {
            const incomingForm = incomingRoot.querySelector('[data-report-filters]');
            if (incomingForm?.dataset.reportToday) form.dataset.reportToday = incomingForm.dataset.reportToday;
            ['start_date', 'end_date'].forEach((name) => {
                const field = form.elements.namedItem(name), source = incomingForm?.elements.namedItem(name);
                if (field && source?.max) field.max = source.max;
            });
        }
        if (updated) {
            updated.dateTime = payload.checked_at;
            updated.textContent = new Intl.DateTimeFormat('en-PH', {
                timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'medium',
            }).format(new Date(payload.checked_at));
        }
    }

    async function refresh(manual) {
        if (request || stopped || ended) return;
        if (dirty) {
            message('Apply your selected dates with View sales before refreshing.', 'pending');
            return;
        }
        if (!canRefresh()) {
            if (navigator.onLine === false) message('You are offline. The last loaded figures are kept; updates resume when connected.', 'offline');
            return;
        }
        const controller = new AbortController();
        request = controller;
        let timedOut = false;
        const timeout = setTimeout(() => { timedOut = true; controller.abort(); }, 12000);
        busy(true);
        if (manual) message('Checking confirmed sales…', 'loading');
        try {
            const response = await fetch(refreshUrl.href, {
                method: 'GET', credentials: 'same-origin', cache: 'no-store', redirect: 'error',
                headers: {'Accept': 'application/json', 'X-Report-Refresh': '1'}, signal: controller.signal,
            });
            if (response.status === 401 || response.status === 403) {
                lockReports();
                return;
            }
            if (!response.ok || !/^application\/json(?:\s*;|$)/i.test(response.headers.get('content-type') || '')) throw new Error('Report unavailable');
            const payload = await response.json();
            if (controller.signal.aborted || !canRefresh()) return;
            applySnapshot(payload);
            failures = 0;
            message(readyMessage);
        } catch (_) {
            if (stopped || ended || (controller.signal.aborted && !timedOut)) return;
            failures = Math.min(3, failures + 1);
            message(navigator.onLine === false
                ? 'You are offline. The last loaded figures are kept; updates resume when connected.'
                : 'Could not refresh sales. The last loaded figures are kept. Try Refresh data; automatic retry is on.',
            navigator.onLine === false ? 'offline' : 'error');
        } finally {
            clearTimeout(timeout);
            if (request === controller) request = null;
            busy(false);
            schedule();
        }
    }

    function presets() {
        const value = form?.dataset.reportToday;
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return {};
        const today = new Date(value + 'T12:00:00Z');
        if (!Number.isFinite(today.getTime())) return {};
        const date = (offset = 0) => {
            const result = new Date(today);
            result.setUTCDate(result.getUTCDate() + offset);
            return result.toISOString().slice(0, 10);
        };
        const weekOffset = (today.getUTCDay() + 6) % 7;
        return {today: [value, value], yesterday: [date(-1), date(-1)], last7: [date(-6), value],
            last30: [date(-29), value], this_week: [date(-weekOffset), value],
            this_month: [value.slice(0, 8) + '01', value], this_year: [value.slice(0, 4) + '-01-01', value]};
    }

    if (form) {
        const start = form.elements.namedItem('start_date'), end = form.elements.namedItem('end_date');
        const preset = form.querySelector('[data-report-preset]');
        if (start && end && preset) {
            const ranges = presets();
            const selected = Object.keys(ranges).find((key) => ranges[key][0] === start.value && ranges[key][1] === end.value);
            preset.value = selected || 'custom';
            const wrapper = form.querySelector('[data-report-presets]');
            if (wrapper) wrapper.hidden = false;
            preset.addEventListener('change', () => {
                const range = presets()[preset.value];
                if (!range) { start.focus(); return; }
                start.value = range[0];
                end.value = range[1];
                const period = form.elements.namedItem('period');
                if (period) period.value = preset.value === 'this_year' ? 'monthly' : 'daily';
                dirty = true;
                cancel();
                form.requestSubmit();
            });
        }
        const changed = (event) => {
            if (event.target === preset) return;
            if (!['period', 'start_date', 'end_date'].includes(event.target.name)) return;
            dirty = true;
            cancel();
            if (preset && event.target.name !== 'period') preset.value = 'custom';
            message('Dates or grouping changed. Click View sales to apply.', 'pending');
        };
        form.addEventListener('input', changed);
        form.addEventListener('change', changed);
        form.addEventListener('submit', cancel);
    }

    buttons.forEach((button) => button.addEventListener('click', (event) => {
        activity(event);
        refresh(true);
    }));
    ['pointerdown', 'keydown', 'wheel', 'touchstart'].forEach((type) => document.addEventListener(type, activity, {passive: true}));
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) { cancel(); return; }
        if (stopped || ended) return;
        lastActivity = Date.now();
        armIdlePause();
        refresh(false);
    });
    window.addEventListener('offline', () => {
        cancel();
        if (!stopped) message('You are offline. The last loaded figures are kept; updates resume when connected.', 'offline');
    });
    window.addEventListener('online', () => { failures = 0; refresh(false); });
    window.addEventListener('pagehide', () => { ended = true; cancel(); clearTimeout(idleTimer); });
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted || stopped) return;
        ended = false;
        lastActivity = Date.now();
        armIdlePause();
        refresh(false);
    });

    try { regionMap(root); } catch (_) { return; }
    buttons.forEach((button) => { button.hidden = false; });
    message(readyMessage);
    armIdlePause();
    schedule();
})();
