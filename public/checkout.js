document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('checkout-form');
    if (!form) return;
    const method = document.getElementById('delivery-method');
    const fields = document.getElementById('delivery-fields');
    const quoteButton = document.getElementById('quote-button');
    const result = document.getElementById('quote-result');
    const total = document.getElementById('checkout-total');
    const shipping = document.getElementById('shipping-value');
    const payButton = document.getElementById('pay-button');
    const payConfigured = !payButton.disabled;
    const progress = document.getElementById('checkout-progress');
    const contactFields = ['recipient', 'phone'].map(name => form.querySelector(`[name="${name}"]`));
    const pinConfirmed = document.getElementById('pin-confirmed');
    const lat = document.getElementById('delivery-lat');
    const lng = document.getElementById('delivery-lng');
    const mapElement = document.getElementById('delivery-map');
    const pinStatus = document.getElementById('pin-status');
    const locate = document.getElementById('locate-button');
    const addressFields = {
        street_address: document.getElementById('address-street'),
        city: document.getElementById('address-city'),
        province: document.getElementById('address-province'),
        postal_code: document.getElementById('address-postal'),
        country: document.getElementById('address-country')
    };
    const money = cents => new Intl.NumberFormat('en-PH', {style:'currency', currency:'PHP'}).format(cents / 100);
    let map, marker, expiryTimer, revision = 0, quoteFresh = false;
    const hasPin = () => lat.value.trim() !== '' && lng.value.trim() !== ''
        && Number.isFinite(Number(lat.value)) && Number.isFinite(Number(lng.value))
        && Math.abs(Number(lat.value)) <= 90 && Math.abs(Number(lng.value)) <= 180;

    function detailsReady() {
        if (!contactFields.every(input => input.value.trim() !== '' && input.validity.valid)) return false;
        if (method.value === 'pickup') return true;
        return method.value === 'lalamove' && quoteFresh && hasPin() && pinConfirmed.checked
            && Object.values(addressFields).every(input => input.value.trim() !== '' && input.validity.valid)
            && addressFields.country.value.trim().toLowerCase() === 'philippines';
    }

    function updateProgress(stage = detailsReady() ? 2 : 1) {
        progress.dataset.checkoutStage = stage;
        progress.querySelectorAll('[data-checkout-step]').forEach(step => {
            const number = Number(step.dataset.checkoutStep);
            if (number === stage) step.setAttribute('aria-current', 'step');
            else step.removeAttribute('aria-current');
            step.toggleAttribute('data-complete', number < stage);
        });
    }

    function resetQuote() {
        revision++;
        quoteFresh = false;
        clearTimeout(expiryTimer);
        payButton.disabled = !payConfigured || method.value === 'lalamove';
        result.textContent = '';
        shipping.textContent = method.value === 'pickup' ? 'Free pickup' : 'Get a delivery fee';
        total.dataset.invoiceTotal = total.dataset.subtotal;
        total.textContent = money(Number(total.dataset.subtotal));
        updateProgress();
    }

    function changeDestination() {
        pinConfirmed.checked = false;
        resetQuote();
    }

    function setPin(point, customerChanged = false) {
        lat.value = Number(point.lat).toFixed(6);
        lng.value = Number(point.lng).toFixed(6);
        changeDestination();
        pinStatus.textContent = 'Pin selected. Check the entrance, then tick the confirmation below.';
        if (customerChanged) form.dispatchEvent(new Event('delivery-pin-changed'));
        if (!map) return;
        if (!marker) {
            marker = L.marker(point, {draggable:true, icon:L.divIcon({className:'delivery-pin', html:'<span aria-hidden="true">●</span>', iconSize:[28,28], iconAnchor:[14,14]})}).addTo(map);
            marker.on('dragend', () => setPin(marker.getLatLng(), true));
        } else marker.setLatLng(point);
    }

    function showMap() {
        if (!window.L) {
            pinStatus.textContent = 'Map unavailable. Use your current location or enter known coordinates below.';
            return;
        }
        if (!map) {
            const start = hasPin() ? [Number(lat.value), Number(lng.value)] : [Number(mapElement.dataset.startLat), Number(mapElement.dataset.startLng)];
            map = L.map(mapElement, {scrollWheelZoom:false}).setView(start, 14);
            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {maxZoom:19, attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>'}).addTo(map);
            map.on('click', event => setPin(event.latlng, true));
            if (hasPin()) setPin({lat:Number(lat.value), lng:Number(lng.value)});
        }
        requestAnimationFrame(() => map.invalidateSize());
    }

    function updateDelivery() {
        const delivery = method.value === 'lalamove';
        fields.hidden = !delivery;
        fields.querySelectorAll('input').forEach(input => { input.disabled = !delivery; input.required = delivery; });
        changeDestination();
        if (delivery) showMap();
    }

    locate.addEventListener('click', () => {
        if (!navigator.geolocation) {
            pinStatus.textContent = 'Device location is unavailable. Tap the map instead.';
            return;
        }
        locate.disabled = true;
        pinStatus.textContent = 'Finding your location…';
        navigator.geolocation.getCurrentPosition(position => {
            setPin({lat:position.coords.latitude, lng:position.coords.longitude}, true);
            map?.setView([Number(lat.value), Number(lng.value)], 17);
            locate.disabled = false;
        }, () => {
            locate.disabled = false;
            pinStatus.textContent = 'Location unavailable or permission declined. Tap the delivery entrance on the map.';
        }, {enableHighAccuracy:true, timeout:10000, maximumAge:60000});
    });

    [...Object.values(addressFields), lat, lng].forEach(input => input.addEventListener('input', changeDestination));
    [lat, lng].forEach(input => input.addEventListener('change', event => {
        if (hasPin()) {
            setPin({lat:Number(lat.value), lng:Number(lng.value)}, event.isTrusted);
            map?.panTo([Number(lat.value), Number(lng.value)]);
        }
    }));
    pinConfirmed.addEventListener('change', resetQuote);
    method.addEventListener('change', updateDelivery);
    form.addEventListener('input', () => updateProgress());
    form.addEventListener('change', () => updateProgress());
    updateDelivery();

    quoteButton.addEventListener('click', async () => {
        if (!hasPin() || !pinConfirmed.checked || Object.values(addressFields).some(input => !input.value.trim())) {
            resetQuote();
            result.textContent = 'Complete your address, choose the delivery entrance, and confirm the pin first.';
            return;
        }
        resetQuote();
        const body = {lat:lat.value, lng:lng.value, pin_confirmed:'1'};
        Object.entries(addressFields).forEach(([name, input]) => { body[name] = input.value; });
        const savedAddress = document.getElementById('checkout-address-id');
        if (savedAddress?.value) body.address_id = savedAddress.value;
        const requestedRevision = revision;
        quoteButton.disabled = true;
        result.textContent = 'Getting your delivery fee…';
        try {
            const response = await fetch(quoteButton.dataset.url, {method:'POST', headers:{'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}, body:JSON.stringify(body)});
            if (!response.headers.get('content-type')?.includes('application/json')) {
                throw new Error('Your checkout session could not be checked. Reload this page before getting a delivery fee.');
            }
            const data = await response.json();
            if (requestedRevision !== revision) return;
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Delivery fee unavailable.');
            const remaining = new Date(data.expires_at).getTime() - Date.now();
            if (!Number.isFinite(remaining) || remaining <= 0) throw new Error('This quote expired. Please get a new delivery fee.');
            shipping.textContent = money(data.amount);
            total.dataset.invoiceTotal = Number(total.dataset.subtotal) + data.amount;
            total.textContent = money(Number(total.dataset.invoiceTotal));
            result.textContent = 'Delivery: ' + money(data.amount) + ' · Valid until ' + new Date(data.expires_at).toLocaleTimeString();
            quoteFresh = true;
            payButton.disabled = !payConfigured;
            updateProgress();
            expiryTimer = setTimeout(() => { resetQuote(); result.textContent = 'Delivery fee expired. Get a fresh fee before paying.'; }, Math.min(remaining, 2147483647));
        } catch (error) {
            if (requestedRevision === revision) result.textContent = error.message;
        } finally { quoteButton.disabled = false; }
    });
    form.addEventListener('submit', event => {
        if (!form.checkValidity() || !detailsReady()) {
            event.preventDefault();
            updateProgress();
            if (method.value === 'lalamove' && (!quoteFresh || !pinConfirmed.checked)) {
                result.textContent = 'Confirm your delivery pin and get a fresh delivery fee before payment.';
            }
            return;
        }
        updateProgress(3);
        payButton.disabled = true;
        payButton.textContent = 'Preparing secure payment…';
    });
});
