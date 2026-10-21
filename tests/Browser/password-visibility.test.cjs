const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../public/password-visibility.js'), 'utf8');

class Element {
  constructor(tagName, attributes = {}, text = '') {
    this.tagName = tagName.toUpperCase();
    this.attributes = { ...attributes };
    this.childNodes = [];
    this.dataset = {};
    this.listeners = new Map();
    this.text = text;
    this.value = attributes.value || '';
  }
  get id() { return this.attributes.id || ''; }
  set id(value) { this.attributes.id = value; }
  get type() { return this.attributes.type || (this.tagName === 'BUTTON' ? 'submit' : 'text'); }
  set type(value) { this.attributes.type = value; }
  get disabled() { return 'disabled' in this.attributes; }
  set disabled(value) { if (value) this.attributes.disabled = ''; else delete this.attributes.disabled; }
  get htmlFor() { return this.attributes.for || ''; }
  set htmlFor(value) { this.attributes.for = value; }
  get textContent() { return this.text + this.childNodes.map(node => node.textContent).join(''); }
  set textContent(value) { this.text = value; this.childNodes = []; }
  get nextSibling() {
    const siblings = this.parentNode?.childNodes || [];
    return siblings[siblings.indexOf(this) + 1] || null;
  }
  get labels() {
    let root = this;
    while (root.parentNode) root = root.parentNode;
    return all(root).filter(node => node.tagName === 'LABEL' &&
      ((this.id && node.htmlFor === this.id) || this.closest('label') === node));
  }
  append(...nodes) {
    for (const node of nodes) {
      if (node.parentNode) node.parentNode.childNodes.splice(node.parentNode.childNodes.indexOf(node), 1);
      node.parentNode = this;
      this.childNodes.push(node);
    }
  }
  insertBefore(node, before) {
    if (node.parentNode) node.parentNode.childNodes.splice(node.parentNode.childNodes.indexOf(node), 1);
    node.parentNode = this;
    this.childNodes.splice(this.childNodes.indexOf(before), 0, node);
  }
  closest(selector) {
    for (let node = this.parentNode; node; node = node.parentNode) {
      if (node.tagName.toLowerCase() === selector) return node;
    }
    return null;
  }
  setAttribute(name, value) { this.attributes[name] = value; }
  getAttribute(name) { return this.attributes[name] ?? null; }
  addEventListener(name, listener) { this.listeners.set(name, listener); }
  click() {
    if (this.disabled) return;
    this.listeners.get('click')?.({ target: this });
    if (this.tagName === 'BUTTON' && this.type === 'submit') this.closest('form').submissions++;
  }
}

function all(root) { return [root, ...root.childNodes.flatMap(all)]; }

function page() {
  const root = new Element('main');
  const form = new Element('form');
  form.submissions = 0;
  root.append(form);
  const document = {
    querySelectorAll: () => all(root).filter(node => node.tagName === 'INPUT' && node.type === 'password'),
    getElementById: id => all(root).find(node => node.id === id) || null,
    createElement: tag => new Element(tag),
  };
  return {
    root, form,
    run() { vm.runInNewContext(source, { document }, { filename: 'password-visibility.js' }); },
    field(labelText, attributes = {}, hintText) {
      const label = new Element('label', {}, labelText);
      const input = new Element('input', { type: 'password', name: 'password', required: '', ...attributes });
      label.append(input);
      if (hintText) label.append(new Element('small', {}, hintText));
      form.append(label);
      return input;
    },
    toggles() { return all(root).filter(node => node.tagName === 'BUTTON'); },
  };
}

test('each password starts hidden and its native button independently shows and hides only that input', () => {
  const view = page();
  const current = view.field('Current password', { name: 'current_password', value: 'Sample-current!2026' });
  const next = view.field('New password', { value: 'Sample-new!2026' });
  const confirm = view.field('Confirm new password', { name: 'password_confirmation', value: 'Sample-new!2026' });
  view.run();
  const buttons = view.toggles();
  assert.equal(buttons.length, 3);
  for (const [index, input] of [current, next, confirm].entries()) {
    assert.equal(input.type, 'password');
    assert.equal(buttons[index].type, 'button');
    assert.equal(buttons[index].textContent, 'Show');
    assert.equal(buttons[index].getAttribute('aria-controls'), input.id);
    assert.equal(buttons[index].getAttribute('aria-pressed'), 'false');
  }
  buttons[1].click();
  assert.equal(next.type, 'text');
  assert.equal(current.type, 'password');
  assert.equal(confirm.type, 'password');
  assert.equal(buttons[1].textContent, 'Hide');
  assert.equal(buttons[1].getAttribute('aria-label'), 'Hide new password');
  assert.equal(buttons[1].getAttribute('aria-pressed'), 'true');
  buttons[0].click();
  buttons[1].click();
  assert.equal(current.type, 'text');
  assert.equal(next.type, 'password');
  assert.equal(next.value, 'Sample-new!2026');
  assert.equal(view.form.submissions, 0);
});

test('implicit and explicit labels retain only the field name and staff guidance stays below the input', () => {
  const view = page();
  const input = view.field('Initial password', { id: 'staff-password' }, 'Use at least 10 characters.');
  const originalLabel = input.closest('label');
  originalLabel.htmlFor = input.id;
  view.run();
  const [button] = view.toggles();
  assert.equal(input.labels.length, 1);
  assert.equal(input.labels[0], originalLabel);
  assert.equal(originalLabel.textContent, 'Initial password');
  assert.equal(button.closest('label'), null);
  assert.equal(button.getAttribute('aria-label'), 'Show initial password');
  assert.equal(input.parentNode.nextSibling.textContent, 'Use at least 10 characters.');
  button.click();
  assert.equal(originalLabel.textContent, 'Initial password');
});

test('enhancement keeps the original input, submitted values, autocomplete and validation constraints', () => {
  const view = page();
  const input = view.field('Confirm new password', {
    id: 'reset-password-confirmation', name: 'password_confirmation', autocomplete: 'new-password',
    minlength: '10', maxlength: '255', value: 'Sample-password!2026', 'aria-describedby': 'existing-hint',
  });
  const attributes = { ...input.attributes };
  view.run();
  assert.deepEqual(input.attributes, attributes);
  assert.equal(all(view.form).filter(node => node.tagName === 'INPUT')[0], input);
  view.toggles()[0].click();
  view.toggles()[0].click();
  assert.deepEqual(input.attributes, attributes);
  assert.equal(input.value, 'Sample-password!2026');
  assert.equal(view.form.submissions, 0);
});

test('generated ids avoid existing elements and a repeated script load creates no duplicate controls', () => {
  const view = page();
  const current = view.field('Current password');
  view.root.append(new Element('div', { id: 'password-field-1' }));
  const next = view.field('New password');
  view.run();
  assert.equal(current.id, 'password-field-2');
  assert.equal(next.id, 'password-field-3');
  view.toggles()[0].click();
  view.run();
  assert.equal(view.toggles().length, 2);
  assert.equal(current.type, 'text');
  assert.equal(next.type, 'password');
});

test('separate labels and disabled password fields keep their original behavior', () => {
  const view = page();
  const input = new Element('input', { id: 'separate-password', type: 'password', disabled: '' });
  const label = new Element('label', { for: input.id }, 'Current password');
  view.form.append(label, input);
  view.run();
  const [button] = view.toggles();
  assert.equal(input.labels[0], label);
  assert.equal(button.getAttribute('aria-label'), 'Show current password');
  assert.equal(button.disabled, true);
  button.click();
  assert.equal(input.type, 'password');
});
