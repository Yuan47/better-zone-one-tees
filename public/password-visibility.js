(() => {
  let nextId = 0;

  document.querySelectorAll('input[type="password"]').forEach(input => {
    if (input.dataset.passwordVisibility === 'ready') return;

    if (!input.id) {
      let id;
      do { id = 'password-field-' + ++nextId; } while (document.getElementById(id));
      input.id = id;
    }

    const control = document.createElement('span');
    control.className = 'password-control';
    const label = input.closest('label');

    if (label && input.parentNode === label) {
      // Keep the visibility button outside the input's accessible label.
      const field = document.createElement('div');
      field.className = 'password-field';
      const trailing = [];
      for (let node = input.nextSibling; node; node = node.nextSibling) trailing.push(node);
      label.parentNode.insertBefore(field, label);
      field.append(label);
      label.htmlFor = input.id;
      field.append(control);
      control.append(input);
      trailing.forEach(node => field.append(node));
    } else {
      input.parentNode.insertBefore(control, input);
      control.append(input);
    }

    const fieldLabel = (input.labels?.[0]?.textContent || 'password').trim().replace(/\s+/g, ' ').toLowerCase();
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'password-visibility-toggle';
    button.setAttribute('aria-controls', input.id);
    button.disabled = input.disabled;

    const update = () => {
      const visible = input.type === 'text';
      button.textContent = visible ? 'Hide' : 'Show';
      button.setAttribute('aria-label', (visible ? 'Hide ' : 'Show ') + fieldLabel);
      button.setAttribute('aria-pressed', String(visible));
    };

    button.addEventListener('click', () => {
      input.type = input.type === 'password' ? 'text' : 'password';
      update();
    });
    update();
    control.append(button);
    input.dataset.passwordVisibility = 'ready';
  });
})();
