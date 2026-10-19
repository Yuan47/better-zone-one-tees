const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../public/customer-session.js'), 'utf8');
const START = 1_800_000_000_000;
const MINUTE = 60_000;

class Events {
  constructor() { this.listeners = new Map(); }
  addEventListener(name, listener) {
    this.listeners.set(name, [...(this.listeners.get(name) || []), listener]);
  }
  emit(name, event = {}) {
    const payload = { target: this, isTrusted: true, preventDefault() {}, ...event };
    for (const listener of this.listeners.get(name) || []) listener(payload);
  }
}

function world() {
  let now = START, timerId = 0;
  const timers = new Map(), storage = new Map(), tabs = [], channels = [];
  const tickMicrotasks = async () => { for (let i = 0; i < 8; i++) await Promise.resolve(); };

  async function advance(milliseconds) {
    const until = now + milliseconds;
    let calls = 0;
    while (true) {
      const next = [...timers].filter(([, task]) => task.at <= until).sort((a, b) => a[1].at - b[1].at || a[0] - b[0])[0];
      if (!next) break;
      assert.ok(++calls < 1000, 'Client created an unbounded timer loop');
      now = next[1].at;
      timers.delete(next[0]);
      next[1].callback();
      await tickMicrotasks();
    }
    now = until;
    await tickMicrotasks();
  }

  function createTab(options = {}) {
    const document = new Events(), window = new Events(), calls = [], redirects = [], nodes = {};
    document.hidden = false;
    function element(id) {
      const node = new Events();
      Object.assign(node, {
        id, hidden: false, disabled: false, open: false, textContent: '', isConnected: true,
        focus() { document.activeElement = node; },
        showModal() { node.open = true; },
        close() { node.open = false; },
        setAttribute(name) { if (name === 'open') node.open = true; },
        removeAttribute(name) { if (name === 'open') node.open = false; },
      });
      nodes[id] = node;
      return node;
    }
    for (const suffix of ['warning', 'stay', 'message', 'title', 'description']) element(`customer-session-${suffix}`);
    const priorFocus = element('previous-input');
    document.activeElement = priorFocus;
    const panel = nodes['customer-session-warning'];
    panel.dataset = {
      syncKey: options.syncKey || 'same-customer-login', serverTime: String(START / 1000),
      expiresAt: String(START / 1000 + 900), activityUrl: '/session/activity',
      statusUrl: '/session/status', loginUrl: '/login',
    };
    document.getElementById = id => options.withoutPanel && id === panel.id ? null : nodes[id] || null;
    document.querySelector = () => ({ content: 'test-csrf-token' });
    window.location = { replace(url) { redirects.push(url); } };
    window.AbortController = AbortController;
    const tab = { document, window, panel, nodes, calls, redirects, priorFocus };
    tabs.push(tab);
    const localStorage = {
      getItem(key) { return storage.get(key) || null; },
      setItem(key, value) {
        if (options.storageUnavailable) throw new Error('Storage is unavailable');
        storage.set(key, value);
        for (const other of tabs) if (other !== tab) other.window.emit('storage', { key, newValue: value });
      },
    };
    class BroadcastChannel {
      constructor(name) { this.name = name; this.onmessage = null; channels.push(this); }
      postMessage(value) {
        for (const other of channels) if (other !== this && other.name === this.name && other.onmessage) other.onmessage({ data: value });
      }
    }
    if (options.broadcastChannel) window.BroadcastChannel = BroadcastChannel;
    const fetch = async (url, request) => {
      calls.push({ url, ...request, sentAt: now });
      if (options.response) return options.response({ url, request, now, calls });
      return { status: 200, ok: true, json: async () => ({ expires_at: Math.floor(now / 1000) + 900, server_time: Math.floor(now / 1000) }) };
    };
    class ClockDate extends Date { static now() { return now; } }
    const context = vm.createContext({
      document, window, localStorage, BroadcastChannel, AbortController, Date: ClockDate, fetch,
      setTimeout(callback, delay) { const id = ++timerId; timers.set(id, { at: now + Math.max(0, delay || 0), callback }); return id; },
      clearTimeout(id) { timers.delete(id); },
    });
    vm.runInContext(source, context, { filename: 'customer-session.js' });
    tab.interact = (name = 'pointerdown', extra = {}) => document.emit(name, extra);
    tab.clickStay = () => nodes['customer-session-stay'].emit('click');
    tab.visible = visible => { document.hidden = !visible; document.emit('visibilitychange'); };
    return tab;
  }
  return { createTab, advance, tickMicrotasks, elapseWithoutTimers(milliseconds) { now += milliseconds; } };
}

test('idle page warns after 14 minutes and checks expiry without keeping the login alive', async () => {
  const clock = world();
  const tab = clock.createTab({ response: async () => ({ status: 401, ok: false, json: async () => ({ code: 'customer_session_expired' }) }) });
  await clock.advance(14 * MINUTE - 1);
  assert.equal(tab.panel.open, false);
  assert.equal(tab.calls.length, 0);
  await clock.advance(1);
  assert.equal(tab.panel.open, true);
  assert.equal(tab.document.activeElement.id, 'customer-session-stay');
  assert.equal(tab.calls.length, 0, 'Displaying the warning must not extend the server session');
  await clock.advance(MINUTE + 50);
  assert.deepEqual(tab.calls.map(call => call.url), ['/session/status']);
  assert.deepEqual(tab.redirects, ['/login?session_expired=1']);
  await clock.advance(30 * MINUTE);
  assert.equal(tab.calls.length, 1, 'Expired pages must stop sending requests');
});

test('explicit stay signed in renews the server deadline and returns keyboard focus', async () => {
  const clock = world(), tab = clock.createTab();
  await clock.advance(14 * MINUTE);
  tab.clickStay();
  await clock.tickMicrotasks();
  assert.equal(tab.calls.length, 1);
  assert.equal(tab.calls[0].url, '/session/activity');
  assert.equal(tab.calls[0].method, 'POST');
  assert.equal(tab.calls[0].credentials, 'same-origin');
  assert.equal(tab.calls[0].cache, 'no-store');
  assert.equal(tab.calls[0].headers['X-CSRF-TOKEN'], 'test-csrf-token');
  assert.equal(JSON.parse(tab.calls[0].body).idle_seconds, 0);
  assert.equal(tab.panel.open, false);
  assert.equal(tab.document.activeElement, tab.priorFocus);
  await clock.advance(14 * MINUTE - 1);
  assert.equal(tab.panel.open, false);
  assert.equal(tab.calls.length, 1, 'Renewal must not create a keepalive interval');
  await clock.advance(1);
  assert.equal(tab.panel.open, true);
});

test('an expired or invalid session redirects even when the server returns no JSON body', async () => {
  for (const status of [401, 419]) {
    const clock = world();
    const tab = clock.createTab({ response: async () => ({ status, ok: false, json: async () => { throw new Error('HTML response'); } }) });
    await clock.advance(15 * MINUTE + 50);
    assert.deepEqual(tab.redirects, ['/login?session_expired=1']);
    assert.equal(tab.calls.length, 1);
  }
});

test('trusted interactions send at most one renewal per minute and idle time adds no polling', async () => {
  const clock = world(), tab = clock.createTab();
  for (let i = 0; i < 59; i++) { tab.interact('wheel'); await clock.advance(1000); }
  assert.equal(tab.calls.length, 0);
  await clock.advance(1000);
  assert.equal(tab.calls.length, 1);
  for (let i = 0; i < 60; i++) { tab.interact('keydown'); await clock.advance(1000); }
  assert.equal(tab.calls.length, 2);
  assert.ok(tab.calls[1].sentAt - tab.calls[0].sentAt >= MINUTE);
  await clock.advance(13 * MINUTE);
  assert.equal(tab.calls.length, 2);
  assert.equal(tab.panel.open, false);
});

test('synthetic events and hidden-tab activity cannot renew an unattended session', async () => {
  const clock = world(), tab = clock.createTab();
  await clock.advance(61_000);
  tab.interact('pointerdown', { isTrusted: false });
  tab.visible(false);
  tab.interact('keydown');
  await clock.advance(2 * MINUTE);
  assert.equal(tab.calls.length, 0);
  tab.visible(true);
  assert.equal(tab.calls.length, 0, 'Merely revealing a tab must not count as activity');
  tab.interact('input');
  await clock.tickMicrotasks();
  assert.equal(tab.calls.length, 1);
  assert.equal(tab.calls[0].url, '/session/activity');
});

test('automatic scrolling cannot renew a session but trusted wheel and touch gestures can', async () => {
  const clock = world(), tab = clock.createTab();
  await clock.advance(61_000);
  tab.interact('scroll');
  await clock.tickMicrotasks();
  assert.equal(tab.calls.length, 0, 'Browsers can mark programmatically generated scroll events as trusted');
  tab.interact('wheel');
  await clock.tickMicrotasks();
  assert.equal(tab.calls.length, 1);
  await clock.advance(MINUTE);
  assert.equal(tab.calls.length, 1);
  tab.interact('touchmove');
  await clock.tickMicrotasks();
  assert.equal(tab.calls.length, 2);
  assert.ok(tab.calls.every(call => call.url === '/session/activity'));
});

test('tabs share acknowledged activity using storage without renewing the server twice', async () => {
  const clock = world(), first = clock.createTab(), second = clock.createTab();
  await clock.advance(13 * MINUTE);
  first.interact();
  await clock.tickMicrotasks();
  assert.equal(first.calls.length, 1);
  assert.equal(second.calls.length, 0);
  await clock.advance(2 * MINUTE);
  assert.equal(first.panel.open, false);
  assert.equal(second.panel.open, false, 'The other tab must use the acknowledged later deadline');
  assert.equal(second.calls.length, 0);
  await clock.advance(12 * MINUTE);
  assert.equal(first.panel.open, true);
  assert.equal(second.panel.open, true);
});

test('broadcast channel synchronizes tab expiry even when browser storage is unavailable', async () => {
  const clock = world();
  const first = clock.createTab({ broadcastChannel: true, storageUnavailable: true, response: async () => ({ status: 401, ok: false, json: async () => ({}) }) });
  const second = clock.createTab({ broadcastChannel: true, storageUnavailable: true });
  await clock.advance(15 * MINUTE + 50);
  assert.deepEqual(first.redirects, ['/login?session_expired=1']);
  assert.deepEqual(second.redirects, ['/login?session_expired=1']);
  assert.equal(second.calls.length, 0, 'Another tab already confirmed expiry');
});

test('returning to a suspended background tab checks the elapsed server deadline immediately', async () => {
  const clock = world();
  const tab = clock.createTab({ response: async () => ({ status: 401, ok: false, json: async () => ({}) }) });
  tab.visible(false);
  clock.elapseWithoutTimers(16 * MINUTE);
  assert.equal(tab.calls.length, 0);
  tab.visible(true);
  await clock.tickMicrotasks();
  assert.equal(tab.calls.length, 1);
  assert.equal(tab.calls[0].url, '/session/status');
  assert.deepEqual(tab.redirects, ['/login?session_expired=1']);
});

test('a status check near expiry does not delay an explicit Stay signed in request', async () => {
  const clock = world();
  const tab = clock.createTab({ response: async ({ url, now }) => ({
    status: 200, ok: true,
    json: async () => ({ expires_at: url === '/session/status' ? START / 1000 + 900 : Math.floor(now / 1000) + 900, server_time: Math.floor(now / 1000) }),
  }) });
  tab.visible(false);
  await clock.advance(14 * MINUTE + 30_000);
  tab.visible(true);
  await clock.tickMicrotasks();
  assert.deepEqual(tab.calls.map(call => call.url), ['/session/status']);
  assert.equal(tab.panel.open, true);
  tab.clickStay();
  await clock.tickMicrotasks();
  assert.deepEqual(tab.calls.map(call => call.url), ['/session/status', '/session/activity']);
  assert.equal(tab.calls[1].sentAt, tab.calls[0].sentAt, 'A read-only status check must not start the activity throttle');
  assert.equal(tab.panel.open, false);
  assert.deepEqual(tab.redirects, []);
});

for (const gesture of ['wheel', 'stay']) {
  test(`a ${gesture} interaction during an in-flight status check is acknowledged after the response`, async () => {
    const clock = world();
    let finishStatus;
    const tab = clock.createTab({ response: ({ url, now }) => {
      if (url === '/session/status') return new Promise(resolve => { finishStatus = () => resolve({
        status: 200, ok: true,
        json: async () => ({ expires_at: START / 1000 + 900, server_time: Math.floor(now / 1000) }),
      }); });
      return Promise.resolve({ status: 200, ok: true,
        json: async () => ({ expires_at: Math.floor(now / 1000) + 900, server_time: Math.floor(now / 1000) }),
      });
    } });
    tab.visible(false);
    await clock.advance(14 * MINUTE + 30_000);
    tab.visible(true);
    await clock.tickMicrotasks();
    assert.deepEqual(tab.calls.map(call => call.url), ['/session/status']);
    if (gesture === 'stay') {
      assert.equal(tab.nodes['customer-session-stay'].disabled, false, 'A read-only check must allow the customer to request renewal');
      tab.clickStay();
    } else tab.interact(gesture);
    await clock.advance(1000);
    finishStatus();
    await clock.tickMicrotasks();
    await clock.tickMicrotasks();
    assert.deepEqual(tab.calls.map(call => call.url), ['/session/status', '/session/activity']);
    assert.equal(JSON.parse(tab.calls[1].body).idle_seconds, 1, 'The queued gesture must keep its original activity time');
    assert.deepEqual(tab.redirects, []);
    await clock.advance(MINUTE);
    assert.equal(tab.calls.length, 2, 'Acknowledging the queued gesture must not create an idle keepalive');
    assert.equal(tab.panel.open, false);
  });
}

test('server-confirmed expiry wins over activity queued during a status check', async () => {
  const clock = world();
  let finishStatus;
  const tab = clock.createTab({ response: () => new Promise(resolve => {
    finishStatus = () => resolve({ status: 401, ok: false, json: async () => ({}) });
  }) });
  tab.visible(false);
  await clock.advance(14 * MINUTE + 30_000);
  tab.visible(true);
  await clock.tickMicrotasks();
  tab.interact('wheel');
  finishStatus();
  await clock.tickMicrotasks();
  assert.deepEqual(tab.calls.map(call => call.url), ['/session/status']);
  assert.deepEqual(tab.redirects, ['/login?session_expired=1']);
  await clock.advance(2 * MINUTE);
  assert.equal(tab.calls.length, 1, 'Queued activity cannot revive an expired server session');
});

test('an unavailable expiry check asks the customer to sign in and does not silently extend or poll', async () => {
  const clock = world();
  const tab = clock.createTab({ response: async () => { throw new Error('Connection unavailable'); } });
  await clock.advance(15 * MINUTE + 50);
  assert.equal(tab.calls.length, 1);
  assert.equal(tab.panel.open, true);
  assert.equal(tab.nodes['customer-session-title'].textContent, 'Please sign in again');
  assert.equal(tab.nodes['customer-session-stay'].hidden, true);
  assert.equal(tab.nodes['customer-session-message'].textContent, 'Connection unavailable');
  assert.deepEqual(tab.redirects, []);
  await clock.advance(30 * MINUTE);
  assert.equal(tab.calls.length, 1);
});

test('a stalled renewal times out, restores the control and still checks the original expiry', async () => {
  const clock = world();
  let aborted = false;
  const tab = clock.createTab({ response: ({ url, request }) => {
    if (url === '/session/status') return Promise.resolve({ status: 401, ok: false, json: async () => ({}) });
    return new Promise((resolve, reject) => {
      request.signal.addEventListener('abort', () => { aborted = true; reject(new Error('Request timed out')); });
    });
  } });
  await clock.advance(14 * MINUTE);
  tab.clickStay();
  await clock.tickMicrotasks();
  assert.equal(tab.nodes['customer-session-stay'].disabled, true);
  await clock.advance(10_000);
  assert.equal(aborted, true, 'A stalled request must be canceled after a bounded wait');
  assert.equal(tab.nodes['customer-session-stay'].disabled, false);
  assert.ok(tab.nodes['customer-session-message'].textContent.length > 0);
  assert.equal(tab.panel.open, true);
  assert.equal(tab.calls.length, 1);
  await clock.advance(50_050);
  assert.deepEqual(tab.calls.map(call => call.url), ['/session/activity', '/session/status']);
  assert.deepEqual(tab.redirects, ['/login?session_expired=1']);
});

test('pages without a customer session dialog do not attach an idle timer or make requests', async () => {
  const clock = world(), tab = clock.createTab({ withoutPanel: true });
  tab.interact();
  await clock.advance(60 * MINUTE);
  assert.equal(tab.calls.length, 0);
  assert.deepEqual(tab.redirects, []);
});
