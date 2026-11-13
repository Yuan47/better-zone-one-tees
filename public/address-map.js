document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('address-book-form');
    if (!form) return;

    const lat = document.getElementById('saved-lat');
    const lng = document.getElementById('saved-lng');
    const manualLat = document.getElementById('saved-manual-lat');
    const manualLng = document.getElementById('saved-manual-lng');
    const confirmation = document.getElementById('saved-pin-confirmed');
    const status = document.getElementById('address-map-status');
    const element = document.getElementById('address-book-map');
    const locate = document.getElementById('address-use-location');
    let map;
    let marker;

    function hasPin() {
        return lat.value !== '' && lng.value !== '' && Number.isFinite(Number(lat.value)) && Number.isFinite(Number(lng.value));
    }

    function setPin(point, changed = true) {
        lat.value = Number(point.lat).toFixed(6);
        lng.value = Number(point.lng).toFixed(6);
        manualLat.value = lat.value;
        manualLng.value = lng.value;
        if (changed) confirmation.checked = false;
        status.textContent = 'Delivery pin selected. Confirm the entrance before saving.';
        if (map) {
            if (marker) marker.setLatLng(point);
            else {
                marker = L.marker(point, {
                    draggable:true,
                    icon:L.divIcon({className:'delivery-pin', html:'<span aria-hidden="true">●</span>', iconSize:[28,28], iconAnchor:[14,14]})
                }).addTo(map);
                marker.on('dragend', function () { setPin(marker.getLatLng()); });
            }
        }
    }

    if (window.L) {
        const start = hasPin() ? [Number(lat.value), Number(lng.value)] : [Number(element.dataset.startLat), Number(element.dataset.startLng)];
        map = L.map(element, {scrollWheelZoom:false}).setView(start, hasPin() ? 17 : 14);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom:19,
            attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>'
        }).addTo(map);
        map.on('click', function (event) { setPin(event.latlng); });
        if (hasPin()) setPin({lat:Number(lat.value), lng:Number(lng.value)}, false);
    } else {
        status.textContent = 'The map could not load. Enter the delivery coordinates below.';
    }
    if (!hasPin()) status.textContent = 'Select your delivery entrance on the map.';

    locate.addEventListener('click', function () {
        if (!navigator.geolocation) {
            status.textContent = 'Location is unavailable. Choose the pin on the map.';
            return;
        }
        locate.disabled = true;
        status.textContent = 'Finding your location…';
        navigator.geolocation.getCurrentPosition(function (position) {
            setPin({lat:position.coords.latitude, lng:position.coords.longitude});
            if (map) map.setView([Number(lat.value), Number(lng.value)], 17);
            locate.disabled = false;
        }, function () {
            locate.disabled = false;
            status.textContent = 'Location permission was unavailable. Select your delivery entrance on the map.';
        }, {enableHighAccuracy:true, timeout:10000, maximumAge:60000});
    });

    [manualLat, manualLng].forEach(function (input) {
        input.addEventListener('change', function () {
            if (manualLat.value === '' || manualLng.value === '' || !manualLat.checkValidity() || !manualLng.checkValidity()) {
                lat.value = '';
                lng.value = '';
                confirmation.checked = false;
                status.textContent = 'Enter a valid latitude and longitude or choose a pin on the map.';
                return;
            }
            setPin({lat:Number(manualLat.value), lng:Number(manualLng.value)});
            if (map) map.setView([Number(lat.value), Number(lng.value)], 17);
        });
    });

    ['saved-street', 'saved-city', 'saved-province', 'saved-postal'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', function () { confirmation.checked = false; });
    });
    form.addEventListener('submit', function (event) {
        if (!hasPin()) {
            event.preventDefault();
            status.textContent = 'Select the delivery entrance before saving your address.';
            element.scrollIntoView({behavior:'smooth', block:'center'});
        }
    });
});
