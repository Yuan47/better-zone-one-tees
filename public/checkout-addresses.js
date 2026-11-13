document.addEventListener('DOMContentLoaded', function () {
    const select = document.getElementById('checkout-address-id');
    if (!select) return;

    const fields = {
        recipient:document.querySelector('#checkout-form [name="recipient"]'),
        phone:document.querySelector('#checkout-form [name="phone"]'),
        street_address:document.getElementById('address-street'),
        city:document.getElementById('address-city'),
        province:document.getElementById('address-province'),
        postal_code:document.getElementById('address-postal'),
        country:document.getElementById('address-country'),
        lat:document.getElementById('delivery-lat'),
        lng:document.getElementById('delivery-lng')
    };

    function applyAddress() {
        const option = select.selectedOptions[0];
        if (!option || !option.dataset.address) return;
        const address = JSON.parse(option.dataset.address);
        if (/^\+639[0-9]{9}$/.test(address.phone || '')) address.phone = '0' + address.phone.slice(3);
        Object.entries(fields).forEach(function ([name, input]) {
            if (!input) return;
            input.value = address[name] ?? '';
            input.dispatchEvent(new Event('input', {bubbles:true}));
        });
        fields.lat.dispatchEvent(new Event('change', {bubbles:true}));
        fields.lng.dispatchEvent(new Event('change', {bubbles:true}));
    }

    select.addEventListener('change', applyAddress);
    document.getElementById('checkout-form').addEventListener('delivery-pin-changed', () => { select.value = ''; });
    Object.values(fields).filter(Boolean).forEach(function (input) {
        input.addEventListener('input', function (event) {
            if (event.isTrusted) select.value = '';
        });
    });
    applyAddress();
});
