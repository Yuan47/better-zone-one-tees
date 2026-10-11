document.addEventListener('DOMContentLoaded', function () {
  var token = document.querySelector('meta[name="csrf-token"]').content;
  function money(cents) { return '₱' + (cents / 100).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2}); }
  var toggle = document.getElementById('chat-toggle'), panel = document.getElementById('chat-panel'), close = document.getElementById('chat-close'), chat = document.getElementById('chat-form');
  function showChat(show) { panel.hidden=!show; toggle.setAttribute('aria-expanded',String(show)); if(show) document.getElementById('chat-input').focus(); }
  toggle.addEventListener('click',function(){showChat(panel.hidden);}); close.addEventListener('click',function(){showChat(false);});
  document.addEventListener('keydown',function(e){if(e.key==='Escape')showChat(false);});
  chat.addEventListener('submit',async function(e) {
    e.preventDefault(); var input=document.getElementById('chat-input'), text=input.value.trim(); if(!text)return;
    var log=document.getElementById('chat-messages'), button=chat.querySelector('button');
    function append(message,type){var p=document.createElement('p');p.className=type;p.textContent=message;log.appendChild(p);log.scrollTop=log.scrollHeight;}
    append(text,'user-message'); input.value=''; button.disabled=true;
    try {var res=await fetch(chat.dataset.url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({message:text,side:chat.dataset.side})});
      var data=await res.json();append(res.ok?data.reply:(res.status===429?'Please wait a minute before sending another message.':data.message||'Unable to send. Refresh the page and try again.'),'assistant-message');
    }catch(error){append('The assistant could not connect. Please try again.','assistant-message');}finally{button.disabled=false;input.focus();}
  });

  var variantData=document.getElementById('product-variants');
  if(variantData){
    var variants=JSON.parse(variantData.textContent), group=document.getElementById('color-group'), color=document.getElementById('variant-color'), size=document.getElementById('variant-size');
    var selectedImage=document.getElementById('selected-product-photo'), placeholder=document.getElementById('product-photo-placeholder');
    function options(select, values, previous){select.replaceChildren();values.forEach(function(value){var opt=document.createElement('option');opt.value=value;opt.textContent=value;select.appendChild(opt);});if(values.includes(previous))select.value=previous;}
    function refreshColors(){options(color,[...new Set(variants.filter(function(v){return v.group===group.value;}).map(function(v){return v.color;}))],color.value);refreshSizes();}
    function compareSizes(a,b){var adult=['XS','S','M','L','XL','2XL','3XL','4XL','5XL'], ai=adult.indexOf(a),bi=adult.indexOf(b);if(ai!==-1 && bi!==-1)return ai-bi;return a.localeCompare(b,'en',{numeric:true});}
    function refreshSizes(){options(size,[...new Set(variants.filter(function(v){return v.group===group.value && v.color===color.value;}).map(function(v){return v.size;}))].sort(compareSizes),size.value);refreshVariant();}
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
    var quoteRevision=0;
    function resetQuote(){quoteRevision++;result.textContent='';shipping.textContent=method.value==='pickup'?'Free pickup':'Get a quote';total.textContent=money(Number(total.dataset.subtotal));}
    function updateDelivery(){fields.hidden=method.value!=='lalamove';lat.required=lng.required=method.value==='lalamove';Object.values(addressFields).forEach(function(el){el.required=method.value==='lalamove';});resetQuote();}
    method.addEventListener('change',updateDelivery);[...Object.values(addressFields),lat,lng].forEach(function(el){el.addEventListener('input',resetQuote);});updateDelivery();
    quote.addEventListener('click',async function(){
      var body={lat:lat.value,lng:lng.value};Object.entries(addressFields).forEach(function(pair){body[pair[0]]=pair[1].value;});
      if(Object.values(body).some(function(value){return !value.trim();})){result.textContent='Enter all address fields and coordinates first.';return;}
      var requestedRevision=quoteRevision;
      quote.disabled=true;result.textContent='Getting your delivery quote…';
      try{var res=await fetch(quote.dataset.url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify(body)});var data=await res.json();if(requestedRevision!==quoteRevision)return;if(!res.ok)throw new Error(Object.values(data.errors||{}).flat().join(' ')||data.message||'Quote is unavailable.');result.textContent='Delivery: '+money(data.amount)+' · Valid until '+new Date(data.expires_at).toLocaleTimeString();shipping.textContent=money(data.amount);total.textContent=money(Number(total.dataset.subtotal)+data.amount);}catch(error){if(requestedRevision===quoteRevision)result.textContent=error.message;}finally{quote.disabled=false;}
    });
    document.getElementById('checkout-form').addEventListener('submit',function(){var button=document.getElementById('pay-button');button.disabled=true;button.textContent='Preparing checkout…';});
  }
});
