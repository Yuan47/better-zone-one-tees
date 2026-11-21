const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const sources = ['checkout.js', 'checkout-addresses.js'].map(name => [name,
  fs.readFileSync(path.join(__dirname, '../../public', name), 'utf8')]);

class Event {
  constructor(type, options = {}) {
    Object.assign(this, { type, bubbles: false, isTrusted: false, defaultPrevented: false }, options);
  }
  preventDefault() { this.defaultPrevented = true; }
}

class Element {
  constructor(value = '') {
    Object.assign(this, { value, checked: false, required: false, disabled: false, hidden: false,
      textContent: '', dataset: {}, listeners: new Map(), attributes: new Map() });
  }
  addEventListener(type, callback) {
    this.listeners.set(type, [...(this.listeners.get(type) || []), callback]);
  }
  dispatchEvent(event) {
    if (!event.target) event.target = this;
    this.pending = Promise.all((this.listeners.get(event.type) || []).map(callback => callback(event)));
    if (event.bubbles && this.parent) this.parent.dispatchEvent(event);
    return !event.defaultPrevented;
  }
  get validity() { return { valid: !this.required || (this.type === 'checkbox' ? this.checked : this.value.trim() !== '') }; }
  setAttribute(name, value) { this.attributes.set(name, value); }
  removeAttribute(name) { this.attributes.delete(name); }
  toggleAttribute(name, value) { if (value) this.setAttribute(name, ''); else this.removeAttribute(name); }
}

function checkout(options = {}) {
  const document = new Element(), form = new Element(), nodes = {}, calls = [], timers = new Map();
  let timerId = 0;
  function element(id, value = '') { return (nodes[id] = new Element(value)); }
  const recipient = element('recipient', 'Profile Customer');
  const phone = element('phone', '09999999999');
  recipient.required = phone.required = true;
  const method = element('delivery-method', options.method || 'pickup');
  const panel = element('delivery-fields');
  const quoteButton = element('quote-button');
  quoteButton.dataset.url = '/checkout/quote';
  element('quote-result');
  const total = element('checkout-total');
  total.dataset = { subtotal: '30000', invoiceTotal: '30000' };
  const shipping = element('shipping-value');
  const payButton = element('pay-button');
  const pin = element('pin-confirmed');
  pin.type = 'checkbox';
  const lat = element('delivery-lat', '14.400000');
  const lng = element('delivery-lng', '120.900000');
  const map = element('delivery-map');
  map.dataset = { startLat: '14.425143', startLng: '120.940319' };
  element('pin-status');
  const locate = element('locate-button');
  const inputs = ['street', 'city', 'province', 'postal', 'country'].map((name, index) =>
    element(`address-${name}`, ['Profile Street', 'Imus', 'Cavite', '4103', 'Philippines'][index]));
  const saved = { recipient: 'Saved Recipient', phone: '+639123456789', street_address: 'Saved Street',
    city: 'Dasmarinas', province: 'Cavite', postal_code: '4114', country: 'Philippines', lat: '14.300000', lng: '120.950000' };
  const select = options.noSavedAddress ? null : element('checkout-address-id', '12');
  if (select) {
    const choices = [{ value: '', dataset: {} }, { value: '12', dataset: { address: JSON.stringify(saved) } }];
    Object.defineProperty(select, 'selectedOptions', { get() { return choices.filter(choice => choice.value === select.value); } });
  }
  const deliveryInputs = [...inputs, lat, lng, pin];
  const deliveryControls = [...deliveryInputs, ...(select ? [select] : []), locate, quoteButton];
  panel.querySelectorAll = selector => selector === 'input' ? deliveryInputs : deliveryControls.filter(control => !deliveryInputs.includes(control));
  const progress = element('checkout-progress');
  progress.querySelectorAll = () => [1, 2, 3, 4].map(number => Object.assign(new Element(), { dataset: { checkoutStep: String(number) } }));
  nodes['checkout-form'] = form;
  for (const node of [recipient, phone, method, ...deliveryControls]) node.parent = form;
  form.querySelector = selector => selector.includes('recipient') ? recipient : selector.includes('phone') ? phone : null;
  form.checkValidity = () => [recipient, phone, ...deliveryControls].every(control => control.disabled || control.validity.valid);
  document.getElementById = id => nodes[id] || null;
  document.querySelector = selector => selector.startsWith('meta') ? { content: 'fixture-csrf' } : form.querySelector(selector);
  const context = vm.createContext({ document, window: {}, navigator: { geolocation: options.geolocation }, Event,
    requestAnimationFrame(callback) { callback(); },
    fetch(url, request) {
      calls.push({ url, ...request });
      return options.response ? options.response(url, request) : Promise.resolve(response());
    },
    setTimeout(callback, delay) { const id = ++timerId; timers.set(id, { callback, delay }); return id; },
    clearTimeout(id) { timers.delete(id); },
  });
  for (const [filename, source] of sources) vm.runInContext(source, context, { filename });
  document.dispatchEvent(new Event('DOMContentLoaded'));
  function userInput(node, value) { node.value = value; node.dispatchEvent(new Event('input', { bubbles: true, isTrusted: true })); }
  function chooseMethod(value) { method.value = value; method.dispatchEvent(new Event('change', { bubbles: true, isTrusted: true })); }
  function confirmPin() { pin.checked = true; pin.dispatchEvent(new Event('change', { bubbles: true, isTrusted: true })); }
  function quote() { quoteButton.dispatchEvent(new Event('click', { isTrusted: true })); return quoteButton.pending; }
  function submit() { const event = new Event('submit', { isTrusted: true }); form.dispatchEvent(event); return event; }
  return { nodes, inputs, deliveryInputs, deliveryControls, recipient, phone, method, panel, select, pin, lat, lng,
    payButton, quoteButton, locate, total, shipping, calls, timers, saved, userInput, chooseMethod, confirmPin, quote, submit };
}

function response() {
  return { ok: true, headers: { get() { return 'application/json'; } },
    async json() { return { amount: 4400, expires_at: new Date(Date.now() + 300000).toISOString() }; } };
}

test('pickup hides and disables the entire address section without replacing profile contact details', () => {
  const page = checkout();
  assert.equal(page.panel.hidden, true);
  assert.ok(page.deliveryControls.every(control => control.disabled));
  assert.ok(page.deliveryInputs.every(input => !input.required));
  assert.equal(page.recipient.value, 'Profile Customer');
  assert.equal(page.phone.value, '09999999999');
  assert.equal(page.recipient.disabled, false);
  assert.equal(page.phone.disabled, false);
  assert.equal(page.shipping.textContent, 'Free pickup');
  assert.equal(page.total.dataset.invoiceTotal, '30000');
  assert.equal(page.payButton.disabled, false);
  assert.equal(page.calls.length, 0);
  assert.equal(page.submit().defaultPrevented, false);
});

test('choosing Lalamove applies the saved destination and local phone but still requires a confirmed pin and quotation', () => {
  const page = checkout();
  page.chooseMethod('lalamove');
  assert.equal(page.panel.hidden, false);
  assert.ok(page.deliveryControls.every(control => !control.disabled));
  assert.ok(page.deliveryInputs.every(input => input.required));
  assert.equal(page.select.required, false, 'Entering an unsaved address remains available');
  assert.equal(page.recipient.value, 'Saved Recipient');
  assert.equal(page.phone.value, '09123456789');
  assert.equal(page.inputs[0].value, 'Saved Street');
  assert.equal(page.lat.value, '14.300000');
  assert.equal(page.lng.value, '120.950000');
  assert.equal(page.pin.checked, false);
  assert.equal(page.select.value, '12');
  assert.equal(page.payButton.disabled, true);
  assert.equal(page.calls.length, 0, 'Selecting delivery never contacts a provider by itself');
  assert.equal(page.submit().defaultPrevented, true);
});

test('manual destination survives pickup and delivery toggles without a hidden default overwriting it', () => {
  const page = checkout();
  page.chooseMethod('lalamove');
  page.userInput(page.inputs[0], 'Customer entered entrance');
  assert.equal(page.select.value, '');
  page.chooseMethod('pickup');
  assert.equal(page.panel.hidden, true);
  assert.ok(page.deliveryControls.every(control => control.disabled));
  assert.equal(page.inputs[0].value, 'Customer entered entrance');
  assert.equal(page.payButton.disabled, false);
  assert.equal(page.submit().defaultPrevented, false);
  page.chooseMethod('lalamove');
  assert.equal(page.inputs[0].value, 'Customer entered entrance');
  assert.equal(page.pin.checked, false);
  assert.equal(page.payButton.disabled, true);
  assert.equal(page.calls.length, 0);
});

test('confirmed delivery fee enters the total once and is removed on pickup without being reusable after switching back', async () => {
  const page = checkout();
  page.chooseMethod('lalamove');
  page.confirmPin();
  await page.quote();
  assert.equal(page.calls.length, 1);
  const request = JSON.parse(page.calls[0].body);
  assert.equal(request.address_id, '12');
  assert.equal(request.street_address, 'Saved Street');
  assert.equal(request.pin_confirmed, '1');
  assert.equal(page.total.dataset.invoiceTotal, 34400);
  assert.equal(page.payButton.disabled, false);
  page.chooseMethod('pickup');
  assert.equal(page.total.dataset.invoiceTotal, '30000');
  assert.equal(page.shipping.textContent, 'Free pickup');
  assert.equal(page.timers.size, 0);
  assert.equal(page.select.disabled, true);
  page.chooseMethod('lalamove');
  assert.equal(page.total.dataset.invoiceTotal, '30000');
  assert.equal(page.payButton.disabled, true);
  assert.equal(page.pin.checked, false);
  assert.equal(page.submit().defaultPrevented, true);
});

test('an in-flight delivery quotation cannot change a pickup total or enable its hidden address controls', async () => {
  let finish;
  const page = checkout({ response: () => new Promise(resolve => { finish = resolve; }) });
  page.chooseMethod('lalamove');
  page.confirmPin();
  const pending = page.quote();
  assert.equal(page.quoteButton.disabled, true);
  page.chooseMethod('pickup');
  finish(response());
  await pending;
  assert.equal(page.total.dataset.invoiceTotal, '30000');
  assert.equal(page.shipping.textContent, 'Free pickup');
  assert.equal(page.payButton.disabled, false);
  assert.ok(page.deliveryControls.every(control => control.disabled));
  assert.equal(page.timers.size, 0);
});

for (const outcome of ['success', 'failure']) {
  test(`delayed device location ${outcome} does not re-enable hidden controls after pickup is selected`, () => {
    let succeed, fail;
    const page = checkout({ geolocation: { getCurrentPosition(resolve, reject) { succeed = resolve; fail = reject; } } });
    page.chooseMethod('lalamove');
    page.locate.dispatchEvent(new Event('click', { isTrusted: true }));
    assert.equal(page.locate.disabled, true);
    page.chooseMethod('pickup');
    if (outcome === 'success') succeed({ coords: { latitude: 14.5, longitude: 120.95 } });
    else fail();
    assert.equal(page.panel.hidden, true);
    assert.ok(page.deliveryControls.every(control => control.disabled));
    assert.equal(page.total.dataset.invoiceTotal, '30000');
    assert.equal(page.shipping.textContent, 'Free pickup');
    assert.equal(page.payButton.disabled, false);
    assert.equal(page.calls.length, 0);
  });
}

test('checkout without saved addresses still toggles manual entry correctly', () => {
  const page = checkout({ noSavedAddress: true });
  assert.equal(page.panel.hidden, true);
  page.chooseMethod('lalamove');
  assert.equal(page.panel.hidden, false);
  assert.equal(page.recipient.value, 'Profile Customer');
  assert.equal(page.phone.value, '09999999999');
  assert.ok(page.deliveryInputs.every(input => input.required && !input.disabled));
  assert.equal(page.payButton.disabled, true);
  assert.equal(page.calls.length, 0);
});

test('returning to checkout with Lalamove selected shows the saved address but does not restore an old delivery fee', () => {
  const page = checkout({ method: 'lalamove' });
  assert.equal(page.panel.hidden, false);
  assert.equal(page.recipient.value, 'Saved Recipient');
  assert.equal(page.phone.value, '09123456789');
  assert.equal(page.pin.checked, false);
  assert.equal(page.payButton.disabled, true);
  assert.equal(page.total.dataset.invoiceTotal, '30000');
  assert.equal(page.calls.length, 0);
});
