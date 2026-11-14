document.addEventListener('DOMContentLoaded', function () {
  var token = document.querySelector('meta[name="csrf-token"]').content;
  function money(cents) { return '₱' + (cents / 100).toLocaleString('en-PH', {minimumFractionDigits:2,maximumFractionDigits:2}); }
  var toggle = document.getElementById('chat-toggle'), panel = document.getElementById('chat-panel'), close = document.getElementById('chat-close'), chat = document.getElementById('chat-form');
  function showChat(show, restoreFocus) { panel.hidden=!show; toggle.setAttribute('aria-expanded',String(show)); if(show) (document.getElementById('chat-input') || panel.querySelector('[data-verification-help-link]') || close).focus({preventScroll:true}); else if(restoreFocus) toggle.focus({preventScroll:true}); }
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
  document.addEventListener('reports:updated',fitScrollableRegions);
  if(window.visualViewport){window.visualViewport.addEventListener('resize',fitChatToViewport);window.visualViewport.addEventListener('scroll',fitChatToViewport);}
  document.addEventListener('toggle',function(){window.requestAnimationFrame(fitScrollableRegions);},true);
  var chatPending=false, suggestions=document.querySelectorAll('.chat-suggestion');
  suggestions.forEach(function(suggestion){suggestion.addEventListener('click',function(){if(chatPending)return;var input=document.getElementById('chat-input');input.value=suggestion.dataset.question;input.focus({preventScroll:true});chat.requestSubmit();});});
  if(chat) chat.addEventListener('submit',async function(e) {
    e.preventDefault(); var input=document.getElementById('chat-input'), text=input.value.trim(); if(!text || chatPending)return;
    var log=document.getElementById('chat-messages'), button=chat.querySelector('button');
    function append(message,type){var p=document.createElement('p');p.className=type;p.textContent=message;log.appendChild(p);log.scrollTop=log.scrollHeight;}
    append(text,'user-message'); input.value=''; chatPending=true;button.disabled=true;suggestions.forEach(function(suggestion){suggestion.disabled=true;});
    try {var res=await fetch(chat.dataset.url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({message:text,side:chat.dataset.side})});
      var data=await res.json();append(res.ok?data.reply:(res.status===429?'Please wait a minute before sending another message.':data.message||'Unable to send. Refresh the page and try again.'),'assistant-message');
    }catch(error){append('The assistant could not connect. Please try again.','assistant-message');}finally{chatPending=false;button.disabled=false;suggestions.forEach(function(suggestion){suggestion.disabled=false;});}
  });

  var variantData=document.getElementById('product-variants');
  if(variantData){
    var variants=JSON.parse(variantData.textContent), color=document.getElementById('variant-color'), size=document.getElementById('variant-size');
    var selectedImage=document.getElementById('selected-product-photo'), placeholder=document.getElementById('product-photo-placeholder');
    function options(select, values, previous){select.replaceChildren();values.forEach(function(value){var opt=document.createElement('option');opt.value=value;opt.textContent=value;select.appendChild(opt);});if(values.includes(previous))select.value=previous;}
    function refreshColors(){options(color,[...new Set(variants.map(function(v){return v.color;}))],color.value);refreshSizes();}
    function refreshSizes(){options(size,[...new Set(variants.filter(function(v){return v.color===color.value;}).map(function(v){return v.size;}))],size.value);refreshVariant();}
    function refreshVariant(){
      var v=variants.find(function(v){return v.color===color.value && v.size===size.value;}), add=document.getElementById('add-to-cart');
      document.getElementById('variant-id').value=v?v.id:'';add.disabled=!v||v.stock===0;
      document.getElementById('variant-availability').textContent=v?(v.stock===0?'Currently out of stock':v.stock+' pieces available'):'This combination is unavailable.';
      document.getElementById('variant-sku').textContent=v?'SKU: '+v.sku:'';
      ['retail','wholesale','reseller'].forEach(function(tier){document.getElementById('price-'+tier).textContent=v?money(v[tier]):'—';});
      var price=document.getElementById('selected-price');price.replaceChildren(document.createTextNode(v?money(v.retail):'—'));var note=document.createElement('small');note.textContent=' / piece · SRP';price.appendChild(note);
      var qty=document.getElementById('variant-quantity');qty.max=v?Math.min(10000,Math.max(1,v.stock)):1;if(Number(qty.value)>Number(qty.max))qty.value=qty.max;
      selectedImage.hidden=!(v&&v.photo);placeholder.hidden=!!(v&&v.photo);if(v&&v.photo)selectedImage.src=v.photo;else selectedImage.removeAttribute('src');
      var photoFrame=selectedImage.closest('[data-catalog-photo]'), demo=!!(v&&v.photo&&v.demo_photo), demoNote=document.getElementById('product-demo-photo-note');
      photoFrame.classList.toggle('demo-photo',demo);photoFrame.hidden=!(v&&v.photo);
      if(demo){photoFrame.style.setProperty('--garment-photo','url("'+v.photo+'")');photoFrame.style.setProperty('--garment-color',v.photo_color);}else{photoFrame.style.removeProperty('--garment-photo');photoFrame.style.removeProperty('--garment-color');}
      selectedImage.alt=(v?v.color+' · ':'')+document.querySelector('.detail-copy h1').textContent+(demo?' · demonstration preview':'');if(demoNote)demoNote.hidden=!demo;
    }
    color.addEventListener('change',refreshSizes);size.addEventListener('change',refreshVariant);refreshColors();
  }
  var rows=document.getElementById('size-chart-rows'), addRow=document.getElementById('add-size-row');
  if(rows){var rowIndex=rows.children.length;addRow.addEventListener('click',function(){if(rows.children.length>=30)return;var row=document.createElement('tr');['size','width','length'].forEach(function(field){var cell=document.createElement('td'),input=document.createElement('input');input.name='size_chart['+rowIndex+']['+field+']';input.setAttribute('aria-label','Chart '+field+' '+(rowIndex+1));if(field==='size')input.maxLength=40;else{input.type='number';input.step='.5';input.min=1;input.max=100;}cell.appendChild(input);row.appendChild(cell);});var cell=document.createElement('td'),button=document.createElement('button');button.type='button';button.className='text-button remove-size-row';button.setAttribute('aria-label','Remove size row');button.textContent='×';cell.appendChild(button);row.appendChild(cell);rows.appendChild(row);rowIndex++;});rows.addEventListener('click',function(e){if(e.target.classList.contains('remove-size-row'))e.target.closest('tr').remove();});}
  var filter=document.getElementById('admin-color-filter');if(filter)filter.addEventListener('change',function(){document.querySelectorAll('[data-variant-color]').forEach(function(row){row.hidden=!!filter.value&&row.dataset.variantColor!==filter.value;});});
  document.querySelectorAll('input[type="file"]').forEach(function(input){input.addEventListener('change',function(){var file=input.files[0];if(!file || !['image/jpeg','image/png','image/webp'].includes(file.type))return;var preview=input.closest('.upload-row,.account-photo')?.querySelector('img');if(!preview){preview=document.createElement('img');preview.className='upload-preview';preview.alt='Selected upload preview';input.parentElement.appendChild(preview);}if(input.dataset.previewUrl)URL.revokeObjectURL(input.dataset.previewUrl);input.dataset.previewUrl=URL.createObjectURL(file);preview.src=input.dataset.previewUrl;var frame=preview.closest('[data-catalog-photo]');if(frame){frame.classList.remove('demo-photo');frame.style.removeProperty('--garment-photo');frame.style.removeProperty('--garment-color');}});});
});
