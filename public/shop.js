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
  var method=document.getElementById('delivery-method');
  if(method){
    var fields=document.getElementById('delivery-fields'), quote=document.getElementById('quote-button'), result=document.getElementById('quote-result'), total=document.getElementById('checkout-total'), shipping=document.getElementById('shipping-value');
    var address=document.getElementById('delivery-address'), lat=document.getElementById('delivery-lat'), lng=document.getElementById('delivery-lng');
    function resetQuote(){result.textContent='';shipping.textContent=method.value==='pickup'?'Free pickup':'Get a quote';total.textContent=money(Number(total.dataset.subtotal));}
    function updateDelivery(){fields.hidden=method.value!=='lalamove';lat.required=lng.required=method.value==='lalamove';resetQuote();}
    method.addEventListener('change',updateDelivery);[address,lat,lng].forEach(function(el){el.addEventListener('input',resetQuote);});updateDelivery();
    quote.addEventListener('click',async function(){
      if(!address.value||!lat.value||!lng.value){result.textContent='Enter your full address and coordinates first.';return;}
      quote.disabled=true;result.textContent='Getting your delivery quote…';
      try{var res=await fetch(quote.dataset.url,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':token},body:JSON.stringify({address:address.value,lat:lat.value,lng:lng.value})});var data=await res.json();
        if(!res.ok)throw new Error(data.message||'Quote is unavailable.');
        result.textContent='Delivery: '+money(data.amount)+' · Valid until '+new Date(data.expires_at).toLocaleTimeString();shipping.textContent=money(data.amount);total.textContent=money(Number(total.dataset.subtotal)+data.amount);
      }catch(error){result.textContent=error.message;}finally{quote.disabled=false;}
    });
    document.getElementById('checkout-form').addEventListener('submit',function(){var button=document.getElementById('pay-button');button.disabled=true;button.textContent='Preparing checkout…';});
  }
});