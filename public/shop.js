document.addEventListener('DOMContentLoaded', function () {
  var token = document.querySelector('meta[name="csrf-token"]').content;
  function money(cents) { return '₱' + (cents / 100).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2}); }
  var toggle = document.getElementById('chat-toggle'), panel = document.getElementById('chat-panel'), close = document.getElementById('chat-close'), chat = document.getElementById('chat-form');
  function showChat(show, restoreFocus) { panel.hidden=!show; toggle.setAttribute('aria-expanded',String(show)); if(show) document.getElementById('chat-input').focus({preventScroll:true}); else if(restoreFocus) toggle.focus({preventScroll:true}); }
  toggle.addEventListener('click',function(){showChat(panel.hidden);}); close.addEventListener('click',function(){showChat(false,true);});
  document.addEventListener('keydown',function(e){if(e.key==='Escape' && !panel.hidden)showChat(false,true);});

  var menu=document.getElementById('workspace-menu'), menuToggle=document.getElementById('workspace-menu-toggle');
  if(menu && menuToggle){
    var menuClose=document.getElementById('workspace-menu-close'), backdrop=document.getElementById('workspace-backdrop'), main=document.querySelector('.admin-main'), mobileMenu=window.matchMedia('(max-width:850px)');
    function showMenu(show,restoreFocus){
      show=show && mobileMenu.matches;
      document.body.classList.toggle('admin-nav-open',show);backdrop.hidden=!show;menuToggle.setAttribute('aria-expanded',String(show));
      menu.inert=mobileMenu.matches && !show;main.inert=show;toggle.inert=show;
      if(mobileMenu.matches){menu.setAttribute('aria-hidden',String(!show));if(show){menu.setAttribute('role','dialog');menu.setAttribute('aria-modal','true');menu.setAttribute('aria-label','Store workspace menu');}else{menu.removeAttribute('role');menu.removeAttribute('aria-modal');menu.removeAttribute('aria-label');}}
      else{menu.removeAttribute('aria-hidden');menu.removeAttribute('role');menu.removeAttribute('aria-modal');menu.removeAttribute('aria-label');}
      if(show){showChat(false);menuClose.focus({preventScroll:true});}else if(restoreFocus && mobileMenu.matches){menuToggle.focus({preventScroll:true});}
    }
    menuToggle.addEventListener('click',function(){showMenu(true);});menuClose.addEventListener('click',function(){showMenu(false,true);});backdrop.addEventListener('click',function(){showMenu(false,true);});
    menu.querySelectorAll('a[href]').forEach(function(link){link.addEventListener('click',function(){if(mobileMenu.matches)showMenu(false);});});
    document.addEventListener('keydown',function(e){
      if(!document.body.classList.contains('admin-nav-open'))return;
      if(e.key==='Escape'){e.preventDefault();showMenu(false,true);return;}
      if(e.key==='Tab'){
        var focusable=[...menu.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]),[tabindex]:not([tabindex="-1"])')].filter(function(el){return el.getClientRects().length>0;}),first=focusable[0],last=focusable[focusable.length-1];
        if(e.shiftKey && (document.activeElement===first || !menu.contains(document.activeElement))){e.preventDefault();last.focus();}else if(!e.shiftKey && (document.activeElement===last || !menu.contains(document.activeElement))){e.preventDefault();first.focus();}
      }
    });
    mobileMenu.addEventListener('change',function(){showMenu(false);});
    document.body.classList.add('admin-nav-ready');showMenu(false);
  }

  function fitChatToViewport(){
    var viewport=window.visualViewport,height=viewport?viewport.height:window.innerHeight,offset=viewport?Math.max(0,window.innerHeight-height-viewport.offsetTop):0;
    document.documentElement.style.setProperty('--chat-viewport-height',Math.round(height)+'px');
    document.documentElement.style.setProperty('--chat-keyboard-offset',Math.round(offset)+'px');
  }
  function fitScrollableRegions(){
    document.querySelectorAll('.table-scroll,.revenue-chart').forEach(function(region){
      var scrollable=region.scrollWidth>region.clientWidth+1;region.dataset.scrollable=String(scrollable);
      if(scrollable){region.tabIndex=0;if(region.classList.contains('table-scroll')){region.setAttribute('role','region');region.setAttribute('aria-label','Table: scroll horizontally to see all columns');}else if(!region.dataset.scrollPositioned){region.scrollLeft=region.scrollWidth-region.clientWidth;region.dataset.scrollPositioned='true';}}
      else{region.removeAttribute('tabindex');if(region.classList.contains('table-scroll')){region.removeAttribute('role');region.removeAttribute('aria-label');}}
    });
  }
  fitChatToViewport();fitScrollableRegions();
  window.addEventListener('resize',function(){fitChatToViewport();fitScrollableRegions();});
  if(window.visualViewport){window.visualViewport.addEventListener('resize',fitChatToViewport);window.visualViewport.addEventListener('scroll',fitChatToViewport);}
  document.addEventListener('toggle',function(){window.requestAnimationFrame(fitScrollableRegions);},true);
  chat.addEventListener('submit',async function(e) {
    e.preventDefault(); var input=document.getElementById('chat-input'), text=input.value.trim(); if(!text)return;
    var log=document.getElementById('chat-messages'), button=chat.querySelector('button');
    function append(message,type){var p=document.createElement('p');p.className=type;p.textContent=message;log.appendChild(p);log.scrollTop=log.scrollHeight;}
    append(text,'user-message'); input.value=''; button.disabled=true;
    try {var res=await fetch(chat.dataset.url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({message:text,side:chat.dataset.side})});
      var data=await res.json();append(res.ok?data.reply:(res.status===429?'Please wait a minute before sending another message.':data.message||'Unable to send. Refresh the page and try again.'),'assistant-message');
    }catch(error){append('The assistant could not connect. Please try again.','assistant-message');}finally{button.disabled=false;}
  });

  var variantData=document.getElementById('product-variants');
  if(variantData){
    var variants=JSON.parse(variantData.textContent), group=document.getElementById('color-group'), color=document.getElementById('variant-color'), size=document.getElementById('variant-size');
    var selectedImage=document.getElementById('selected-product-photo'), placeholder=document.getElementById('product-photo-placeholder');
    function options(select, values, previous){select.replaceChildren();values.forEach(function(value){var opt=document.createElement('option');opt.value=value;opt.textContent=value;select.appendChild(opt);});if(values.includes(previous))select.value=previous;}
    function refreshColors(){options(color,[...new Set(variants.filter(function(v){return v.group===group.value;}).map(function(v){return v.color;}))],color.value);refreshSizes();}
    function refreshSizes(){options(size,[...new Set(variants.filter(function(v){return v.group===group.value && v.color===color.value;}).map(function(v){return v.size;}))],size.value);refreshVariant();}
    function refreshVariant(){
      var v=variants.find(function(v){return v.group===group.value && v.color===color.value && v.size===size.value;}), add=document.getElementById('add-to-cart');
      document.getElementById('variant-id').value=v?v.id:'';add.disabled=!v||v.stock===0;
      document.getElementById('variant-availability').textContent=v?(v.stock===0?'Currently out of stock':v.stock+' pieces available'):'This combination is unavailable.';
      document.getElementById('variant-sku').textContent=v?'SKU: '+v.sku:'';
      ['retail','wholesale','reseller'].forEach(function(tier){document.getElementById('price-'+tier).textContent=v?money(v[tier]):'—';});
      var price=document.getElementById('selected-price');price.replaceChildren(document.createTextNode(v?money(v.retail):'—'));var note=document.createElement('small');note.textContent=' / piece · SRP';price.appendChild(note);
      var qty=document.getElementById('variant-quantity');qty.max=v?Math.min(10000,Math.max(1,v.stock)):1;if(Number(qty.value)>Number(qty.max))qty.value=qty.max;
      selectedImage.hidden=!(v&&v.photo);placeholder.hidden=!!(v&&v.photo);if(v&&v.photo)selectedImage.src=v.photo;else selectedImage.removeAttribute('src');
    }
    group.addEventListener('change',refreshColors);color.addEventListener('change',refreshSizes);size.addEventListener('change',refreshVariant);
    options(group,[...new Set(variants.map(function(v){return v.group;}))],group.value);refreshColors();
  }
  document.querySelectorAll('.color-group-input').forEach(function(select){var input=select.closest('form').querySelector('input[name="color"]');function updateColor(){if(select.value==='WHITE'){input.value='White';input.readOnly=true;}else{input.readOnly=false;if(input.value==='White')input.value='';}}select.addEventListener('change',updateColor);updateColor();});
  var rows=document.getElementById('size-chart-rows'), addRow=document.getElementById('add-size-row');
  if(rows){var rowIndex=rows.children.length;addRow.addEventListener('click',function(){if(rows.children.length>=30)return;var row=document.createElement('tr');['size','width','length'].forEach(function(field){var cell=document.createElement('td'),input=document.createElement('input');input.name='size_chart['+rowIndex+']['+field+']';input.setAttribute('aria-label','Chart '+field+' '+(rowIndex+1));if(field==='size')input.maxLength=40;else{input.type='number';input.step='.5';input.min=1;input.max=100;}cell.appendChild(input);row.appendChild(cell);});var cell=document.createElement('td'),button=document.createElement('button');button.type='button';button.className='text-button remove-size-row';button.setAttribute('aria-label','Remove size row');button.textContent='×';cell.appendChild(button);row.appendChild(cell);rows.appendChild(row);rowIndex++;});rows.addEventListener('click',function(e){if(e.target.classList.contains('remove-size-row'))e.target.closest('tr').remove();});}
  var filter=document.getElementById('admin-color-filter');if(filter)filter.addEventListener('change',function(){document.querySelectorAll('[data-variant-color]').forEach(function(row){row.hidden=!!filter.value&&row.dataset.variantColor!==filter.value;});});
  document.querySelectorAll('input[type="file"]').forEach(function(input){input.addEventListener('change',function(){var file=input.files[0];if(!file || !['image/jpeg','image/png','image/webp'].includes(file.type))return;var preview=input.closest('.upload-row,.account-photo')?.querySelector('img');if(!preview){preview=document.createElement('img');preview.className='upload-preview';preview.alt='Selected upload preview';input.parentElement.appendChild(preview);}if(input.dataset.previewUrl)URL.revokeObjectURL(input.dataset.previewUrl);input.dataset.previewUrl=URL.createObjectURL(file);preview.src=input.dataset.previewUrl;});});
  var method=document.getElementById('delivery-method');
  if(method){
    var fields=document.getElementById('delivery-fields'), quote=document.getElementById('quote-button'), result=document.getElementById('quote-result'), total=document.getElementById('checkout-total'), shipping=document.getElementById('shipping-value');
    var addressFields={street_address:document.getElementById('address-street'),city:document.getElementById('address-city'),province:document.getElementById('address-province'),postal_code:document.getElementById('address-postal'),country:document.getElementById('address-country')}, lat=document.getElementById('delivery-lat'),lng=document.getElementById('delivery-lng');
    var quoteRevision=0, expiryTimer, quoteFresh=false, payButton=document.getElementById('pay-button'), payConfigured=!payButton.disabled;
    var mapElement=document.getElementById('delivery-map'), map, marker, locate=document.getElementById('locate-button'), pinStatus=document.getElementById('pin-status');
    function hasPin(){return lat.value.trim()!=='' && lng.value.trim()!=='' && Number.isFinite(Number(lat.value)) && Number.isFinite(Number(lng.value));}
    function pinMessage(){pinStatus.textContent=hasPin()?'Delivery pin selected. Confirm that it matches your address.':'No delivery pin selected.';}
    function setPin(point){lat.value=Number(point.lat).toFixed(6);lng.value=Number(point.lng).toFixed(6);resetQuote();pinMessage();if(map){if(!marker){marker=L.marker(point,{draggable:true,icon:L.divIcon({className:'delivery-pin',html:'<span aria-hidden="true">●</span>',iconSize:[28,28],iconAnchor:[14,14]})}).addTo(map);marker.on('dragend',function(){setPin(marker.getLatLng());});}else marker.setLatLng(point);}}
    function showMap(){
      if(!mapElement || !window.L){pinStatus.textContent='Map could not load. Use your current location or enter coordinates below.';return;}
      if(!map){var start=hasPin()?[Number(lat.value),Number(lng.value)]:[Number(mapElement.dataset.startLat),Number(mapElement.dataset.startLng)];map=L.map(mapElement,{scrollWheelZoom:false}).setView(start,14);L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>'}).addTo(map);map.on('click',function(e){setPin(e.latlng);});if(hasPin())setPin({lat:Number(lat.value),lng:Number(lng.value)});}
      requestAnimationFrame(function(){map.invalidateSize();});
    }
    locate.addEventListener('click',function(){if(!navigator.geolocation){pinStatus.textContent='Location is unavailable. Tap the map or enter coordinates below.';return;}locate.disabled=true;pinStatus.textContent='Finding your current location…';navigator.geolocation.getCurrentPosition(function(position){setPin({lat:position.coords.latitude,lng:position.coords.longitude});if(map)map.setView([Number(lat.value),Number(lng.value)],17);locate.disabled=false;},function(){locate.disabled=false;pinStatus.textContent='Location was unavailable or permission was declined. Tap the map or enter coordinates below.';},{enableHighAccuracy:true,timeout:10000,maximumAge:60000});});
    [lat,lng].forEach(function(el){el.addEventListener('change',function(){if(hasPin()){setPin({lat:Number(lat.value),lng:Number(lng.value)});if(map)map.panTo([Number(lat.value),Number(lng.value)]);}else pinMessage();});});
    function resetQuote(){quoteRevision++;quoteFresh=false;clearTimeout(expiryTimer);payButton.disabled=!payConfigured||method.value==='lalamove';result.textContent='';shipping.textContent=method.value==='pickup'?'Free pickup':'Get a quote';total.textContent=money(Number(total.dataset.subtotal));}
    function updateDelivery(){fields.hidden=method.value!=='lalamove';lat.required=lng.required=method.value==='lalamove';Object.values(addressFields).forEach(function(el){el.required=method.value==='lalamove';});resetQuote();if(method.value==='lalamove')showMap();}
    method.addEventListener('change',updateDelivery);[...Object.values(addressFields),lat,lng].forEach(function(el){el.addEventListener('input',resetQuote);});updateDelivery();
    quote.addEventListener('click',async function(){
      var body={lat:lat.value,lng:lng.value};Object.entries(addressFields).forEach(function(pair){body[pair[0]]=pair[1].value;});
      if(Object.values(body).some(function(value){return !value.trim();})){result.textContent='Complete your address and choose a delivery pin first.';return;}
      var requestedRevision=quoteRevision;
      quote.disabled=true;result.textContent='Getting your delivery quote…';
      try{var res=await fetch(quote.dataset.url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify(body)});var data=await res.json();if(requestedRevision!==quoteRevision)return;if(!res.ok)throw new Error(Object.values(data.errors||{}).flat().join(' ')||data.message||'Quote is unavailable.');result.textContent='Delivery: '+money(data.amount)+' · Valid until '+new Date(data.expires_at).toLocaleTimeString();shipping.textContent=money(data.amount);total.textContent=money(Number(total.dataset.subtotal)+data.amount);var remaining=new Date(data.expires_at).getTime()-Date.now();if(remaining<=0)throw new Error('This quote has expired. Request another quote.');quoteFresh=true;payButton.disabled=!payConfigured;expiryTimer=setTimeout(function(){resetQuote();result.textContent='The delivery quote expired. Request a fresh quote before payment.';},Math.min(remaining,2147483647));}catch(error){if(requestedRevision===quoteRevision)result.textContent=error.message;}finally{quote.disabled=false;}
    });
    document.getElementById('checkout-form').addEventListener('submit',function(e){if(method.value==='lalamove'&&!quoteFresh){e.preventDefault();result.textContent='Request a fresh delivery quote before payment.';return;}var button=document.getElementById('pay-button');button.disabled=true;button.textContent='Preparing checkout…';});
  }
});
(() => {
  const choice = document.getElementById('payment-option');
  const fields = document.getElementById('partial-payment-fields');
  if (!choice || !fields) return;
  const amount = document.getElementById('payment-amount');
  const summary = document.getElementById('partial-payment-summary');
  const total = document.getElementById('checkout-total');
  const money = n => new Intl.NumberFormat('en-PH', {style:'currency',currency:'PHP'}).format(n);
  const update = () => {
    const partial = choice.value === 'partial';
    fields.hidden = !partial;
    amount.required = partial;
    amount.disabled = !partial;
    if (summary && total) {
      const invoice = Number(total.textContent.replace(/[^0-9.]/g,''));
      const now = Number(amount.value);
      summary.textContent = partial && now > 0 && now < invoice ? 'Pay '+money(now)+' now. Remaining balance: '+money(invoice-now)+'.' : (partial ? 'Enter a first payment below the full invoice total.' : '');
    }
  };
  choice.addEventListener('change', update);
  amount.addEventListener('input', update);
  if (total) new MutationObserver(update).observe(total,{childList:true,characterData:true,subtree:true});
  update();
})();
