import {ApiClient} from '../assets/api-client.js';
const status=document.querySelector('#try-status');
const output=document.querySelector('#try-json');
const api=new ApiClient(r=>{status.textContent=`GET ${r.url} · HTTP ${r.status} · ${r.duration.toFixed(1)} ms observed round trip`;output.textContent=JSON.stringify(r.response,null,2);});
document.querySelector('#try-request').addEventListener('click',async e=>{const button=e.currentTarget;button.disabled=true;try{await api.request('GET',document.querySelector('#try-path').value);}catch(err){if(!err.status)status.textContent=err.message;}finally{button.disabled=false;}});
