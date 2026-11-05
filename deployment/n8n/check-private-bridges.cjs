'use strict';
// Run inside project n8n. No credential or message content is used or printed.
const checks=[
 ['callbacks_health','http://172.23.16.1:8083/up','GET',200],
 ['envelope_health','http://172.23.16.1:8084/up','GET',200],
 ['callbacks_private_dashboard','http://172.23.16.1:8083/','GET',404],
 ['envelope_private_dashboard','http://172.23.16.1:8084/','GET',404],
 ['callbacks_require_auth','http://172.23.16.1:8083/api/sales/inbox-sync','POST',401],
 ['envelope_requires_auth','http://172.23.16.1:8084/api/sales/gmail-envelope','POST',401],
 ['envelope_rejects_other_routes','http://172.23.16.1:8084/api/sales/inbox-sync','POST',404],
];
(async()=>{
 const results=await Promise.all(checks.map(async([name,url,method,expected])=>{
  try {
   const r=await fetch(url,{method,headers:{Accept:'application/json',...(method==='POST'?{'Content-Type':'application/json'}:{})},...(method==='POST'?{body:'{}'}:{}),redirect:'manual',signal:AbortSignal.timeout(15000)});
   await r.arrayBuffer();
   return {name,http:r.status,pass:r.status===expected};
  } catch { return {name,pass:false,error_code:'PRIVATE_LISTENER_UNREACHABLE'}; }
 }));
 console.log(JSON.stringify({checks:results,pass:results.every(r=>r.pass),emails_sent:0}));
 if(results.some(r=>!r.pass))process.exitCode=1;
})();
