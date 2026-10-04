import {ApiClient} from './api-client.js';
const $=s=>document.querySelector(s);
const escape=s=>String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const money=c=>new Intl.NumberFormat('en-US',{style:'currency',currency:'USD'}).format(c/100);
const history=[];let selectedRequest=0,page=1,meta={},product=null,variant=null,loadSequence=0,checkoutKey=null,cartHasItems=false;
function message(target,text,error=false){target.textContent=text;target.className='message '+(error?'error':'success');}
function renderInspector(){
  $('#request-list').innerHTML=history.map((r,i)=>`<button class="request-row" aria-pressed="${i===selectedRequest}" data-request="${i}"><strong>${escape(r.method)}</strong><span class="request-path">${escape(r.url)}</span><span class="status ${r.status>=400?'bad':'good'}">${r.status}</span></button>`).join('');
  const r=history[selectedRequest];if(!r)return;
  $('#request-json').textContent=JSON.stringify({method:r.method,url:r.url,payload:r.payload},null,2);
  $('#response-json').textContent=JSON.stringify(r.response,null,2);
  $('#request-timing').textContent=`HTTP ${r.status} · ${r.duration.toFixed(1)} ms observed round trip`;
}
const api=new ApiClient(r=>{history.unshift(r);if(history.length>20)history.pop();selectedRequest=0;renderInspector();});
async function action(button,fn,target=$('#global-message')){if(button)button.disabled=true;target.textContent='';try{await fn();}catch(e){message(target,e.message,true);}finally{if(button)button.disabled=button.id==='create-order'?!cartHasItems:false;}}
async function loadCatalog(){
  const sequence=++loadSequence;const params=new URLSearchParams({page,per_page:6,sort:$('#sort').value});
  if($('#search').value.trim())params.set('q',$('#search').value.trim());
  if($('#category').value)params.set('category',$('#category').value);
  if($('#stock-filter').checked)params.set('in_stock','true');
  const result=await api.request('GET','/products?'+params);
  if(sequence!==loadSequence)return;
  meta=result.meta;
  $('#catalog-count').textContent=`${meta.total} demo ${meta.total===1?'product':'products'} · prices in USD`;
  $('#page-label').textContent=meta.total_pages?`Page ${meta.page} of ${meta.total_pages}`:'No results';
  $('#previous').disabled=page<=1;$('#next').disabled=page>=meta.total_pages;
  $('#catalog').innerHTML=result.data.length?result.data.map(p=>`<article class="product-card"><img src="${escape(p.image)}" alt="Original illustration of ${escape(p.name)}" width="400" height="300"><div class="product-content"><p class="category">${escape(p.category)}</p><h3>${escape(p.name)}</h3><p class="variant-count">${p.variants.length} variants · ${p.total_stock} units in stock</p><div class="product-bottom"><span class="price">From ${money(p.price_from_cents)}</span><button class="button small" data-product="${p.id}" aria-label="View ${escape(p.name)}">View Product</button></div></div></article>`).join(''):'<p>No products match your filters.</p>';
}
function renderCart(cart){
  cartHasItems=cart.items.length>0;
  $('#cart-count').textContent=cart.quantity;$('#cart-total').textContent=money(cart.total_cents);
  $('#create-order').disabled=!cart.items.length;
  $('#cart-items').innerHTML=cart.items.length?cart.items.map(i=>`<div class="cart-row"><div><p><strong>${escape(i.product_name)}</strong></p><small>${escape(i.variant_label)} · ${money(i.unit_price_cents)} each</small><br><button class="remove" data-remove="${i.id}" aria-label="Remove ${escape(i.product_name)}">Remove</button></div><label>Quantity<input type="number" min="1" max="${Math.min(20,i.available_stock)}" step="1" value="${i.quantity}" data-quantity="${i.id}" aria-label="Quantity for ${escape(i.product_name)}"></label><span class="price">${money(i.subtotal_cents)}</span></div>`).join(''):'<p class="cart-empty">Your cart is empty. Choose a product and a variant to get started.</p>';
}
async function loadCart(){renderCart((await api.request('GET','/cart')).data.cart);}
function renderDetail(){
  $('#detail-title').textContent=product.name;
  $('#product-detail').innerHTML=`<img src="${escape(product.image)}" alt="Original illustration of ${escape(product.name)}"><p>${escape(product.description)}</p><h3>Choose a variant</h3><div class="variant-options">${product.variants.map(v=>`<button class="button small" aria-pressed="${v.id===variant.id}" data-variant="${v.id}">${escape(v.label)}${v.stock===0?' · sold out':''}</button>`).join('')}</div><div class="selection-summary"><strong id="variant-price">${money(variant.price_cents)}</strong><span id="variant-stock">${variant.stock} units available</span></div><p class="category">SKU: ${escape(variant.sku)}</p><div class="detail-actions"><label class="field">Quantity<input id="detail-quantity" type="number" min="1" max="${Math.max(1,Math.min(20,variant.stock))}" value="1"></label><button id="add-to-cart" class="button primary" ${variant.stock===0?'disabled':''}>${variant.stock===0?'Out of Stock':'Add to Cart'}</button></div>`;
}
$('#catalog').addEventListener('click',e=>{const button=e.target.closest('[data-product]');if(!button)return;action(button,async()=>{product=(await api.request('GET','/products/'+button.dataset.product)).data;variant=product.variants.find(v=>v.stock>0)||product.variants[0];$('#detail-message').textContent='';renderDetail();$('#product-dialog').showModal();});});
$('#product-detail').addEventListener('click',e=>{const v=e.target.closest('[data-variant]');if(v){variant=product.variants.find(x=>x.id===Number(v.dataset.variant));renderDetail();return;}const add=e.target.closest('#add-to-cart');if(add)action(add,async()=>{const quantity=Number($('#detail-quantity').value);const result=await api.request('POST','/cart/items',{product_id:product.id,variant_id:variant.id,quantity});renderCart(result.data.cart);checkoutKey=null;message($('#detail-message'),`${product.name} · ${variant.label} added to your cart.`);},$('#detail-message'));});
$('#close-detail').addEventListener('click',()=>$('#product-dialog').close());
$('#product-dialog').addEventListener('click',e=>{if(e.target===$('#product-dialog'))$('#product-dialog').close();});
$('#cart-items').addEventListener('click',e=>{const button=e.target.closest('[data-remove]');if(button)action(button,async()=>{renderCart((await api.request('DELETE','/cart/items/'+button.dataset.remove)).data.cart);checkoutKey=null;});});
$('#cart-items').addEventListener('change',e=>{const input=e.target.closest('[data-quantity]');if(input)action(input,async()=>{try{renderCart((await api.request('PATCH','/cart/items/'+input.dataset.quantity,{quantity:Number(input.value)})).data.cart);checkoutKey=null;}catch(err){await loadCart();throw err;}});});
$('#checkout').addEventListener('submit',e=>{e.preventDefault();action($('#create-order'),async()=>{checkoutKey??=crypto.randomUUID().replaceAll('-','');const result=await api.request('POST','/orders',{delivery_method:$('#delivery').value,demo_acknowledged:$('#acknowledge').checked},{'Idempotency-Key':checkoutKey});const verified=await api.request('GET','/orders/'+result.data.order.id);message($('#order-message'),`${verified.data.order.reference} created · ${money(verified.data.order.total_cents)}. No payment or real delivery. Stock was updated only in your sandbox.`);$('#acknowledge').checked=false;checkoutKey=null;await loadCart();await loadCatalog();});});
$('#reset').addEventListener('click',e=>action(e.currentTarget,async()=>{renderCart((await api.request('POST','/reset',{})).data.cart);page=1;checkoutKey=null;$('#acknowledge').checked=false;$('#order-message').textContent='';$('#search').value='';$('#category').value='';$('#sort').value='name_asc';$('#stock-filter').checked=false;if($('#product-dialog').open)$('#product-dialog').close();await loadCatalog();message($('#global-message'),'Your sandbox has been reset. Stock, cart and demo orders are back to their initial state.');}));
let searchTimer;
$('#search').addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>{page=1;action(null,loadCatalog);},250);});
for(const id of ['category','sort','stock-filter'])$('#'+id).addEventListener('change',()=>{page=1;action(null,loadCatalog);});
$('#filters').addEventListener('submit',e=>{e.preventDefault();page=1;action(null,loadCatalog);});
$('#previous').addEventListener('click',()=>{page--;action(null,loadCatalog);});$('#next').addEventListener('click',()=>{page++;action(null,loadCatalog);});
function view(developer){document.body.classList.toggle('developer-mode',developer);$('#developer-panel').hidden=!developer;$('#customer-tab').setAttribute('aria-selected',String(!developer));$('#developer-tab').setAttribute('aria-selected',String(developer));}
$('#customer-tab').addEventListener('click',()=>view(false));$('#developer-tab').addEventListener('click',()=>view(true));
for(const id of ['customer-tab','developer-tab'])$('#'+id).addEventListener('keydown',e=>{if(['ArrowLeft','ArrowRight'].includes(e.key)){e.preventDefault();const target=id==='customer-tab'?$('#developer-tab'):$('#customer-tab');target.focus();view(target.id==='developer-tab');}});
$('#request-list').addEventListener('click',e=>{const row=e.target.closest('[data-request]');if(row){selectedRequest=Number(row.dataset.request);renderInspector();}});
$('#error-404').addEventListener('click',e=>action(e.currentTarget,()=>api.request('GET','/products/999999')));
$('#error-422').addEventListener('click',e=>action(e.currentTarget,()=>api.request('POST','/cart/items',{product_id:1,variant_id:11,quantity:0})));
action(null,async()=>{await loadCart();await loadCatalog();});
