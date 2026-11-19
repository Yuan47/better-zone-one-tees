document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-photo-editor]').forEach(function (editor) {
    var upload = editor.querySelector('input[type="file"]');
    var remove = editor.querySelector('input[name="remove_photo"]');
    var status = editor.querySelector('[data-photo-change]');
    function update() {
      var replacement = !!(upload && upload.files && upload.files.length);
      if (remove) {
        if (replacement) remove.checked = false;
        remove.disabled = replacement;
      }
      var removing = !!(remove && remove.checked);
      status.hidden = !replacement && !removing;
      status.textContent = replacement ? 'New photo selected. Save this form to replace the current photo.' : removing ? 'Current photo will be removed when you save this form. A default preview may still be shown.' : '';
    }
    if (upload) upload.addEventListener('change', update);
    if (remove) remove.addEventListener('change', update);
    update();
  });
  var source = document.getElementById('catalog-color-templates');
  var form = document.querySelector('[data-color-shortcut]');
  if (!source || !form) return;
  var templates;
  try { templates = JSON.parse(source.textContent); } catch (_) { return; }
  var selection = form.querySelector('[name="template_color"]');
  var color = form.querySelector('[name="color"]');
  var preview = form.querySelector('[data-color-price-preview]');
  var heading = form.querySelector('[data-color-preview-heading]');
  var rows = form.querySelector('[data-color-price-rows]');
  var money = new Intl.NumberFormat('en-PH', {style: 'currency', currency: 'PHP', minimumFractionDigits: 2});
  function showPrices() {
    var sizes = Object.hasOwn(templates, selection.value) ? templates[selection.value] : [];
    rows.replaceChildren();
    preview.hidden = !sizes.length;
    if (!sizes.length) return;
    heading.textContent = sizes.length + ' sizes for ' + (color.value.trim() || 'the new color') + ' · 0 stock per size';
    sizes.forEach(function (size) {
      var row = document.createElement('tr');
      [size.size, money.format(size.reseller / 100), money.format(size.wholesale / 100), money.format(size.retail / 100)].forEach(function (value) {
        var cell = document.createElement('td');
        cell.textContent = value;
        row.appendChild(cell);
      });
      rows.appendChild(row);
    });
  }
  selection.addEventListener('change', showPrices);
  color.addEventListener('input', showPrices);
  showPrices();
});
