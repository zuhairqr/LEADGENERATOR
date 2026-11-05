'use strict';
const {test}=require('node:test'),assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
const load=id=>JSON.parse(fs.readFileSync(path.join(__dirname,'../n8n',id+'.json'),'utf8'));
const ids=['leadgenSalesAi01','leadgenSalesWorker01','leadgenBusinessWorker01','leadgenGmailConnect01','leadgenGmailRead01','leadgenSalesGmail01','leadgenSalesInbox01'];
test('Generated workflow code compiles and all connections target existing nodes',()=>{
 for(const id of ids){const w=load(id),names=new Set(w.nodes.map(n=>n.name));assert.equal(names.size,w.nodes.length,id);
  assert.equal(w.settings.saveDataSuccessExecution,'none');assert.equal(w.settings.saveDataErrorExecution,'none');
  for(const n of w.nodes)if(n.type==='n8n-nodes-base.code')assert.doesNotThrow(()=>new Function(n.parameters.jsCode),id+': '+n.name);
  for(const [from,edges] of Object.entries(w.connections)){assert.ok(names.has(from));for(const group of edges.main)for(const edge of group)assert.ok(names.has(edge.node));}
 }
});
test('Gmail transport obtains server-approved content before reaching the sending node',()=>{
 const w=load('leadgenSalesGmail01');let current='Private request',visited=[];
 while(current){visited.push(current);current=w.connections[current]?.main[0]?.[0]?.node;assert.ok(visited.length<12);}
 assert.deepEqual(visited,['Private request','Claim approved server envelope','Verify own Gmail sender','Build exact approved MIME','Send approved email','Read sent Message-ID','Gmail receipt','Return safe result']);
 const claim=w.nodes.find(n=>n.name==='Claim approved server envelope');assert.match(claim.parameters.url,/:8084\/api\/sales\/gmail-envelope$/);assert.equal(claim.parameters.jsonBody,'={{ {message_id:$json.body.messageId} }}');
 assert.match(w.nodes.find(n=>n.name==='Build exact approved MIME').parameters.jsCode,/Claim approved server envelope/);
 const metadata=w.nodes.find(n=>n.name==='Read sent Message-ID');
 assert.equal(metadata.parameters.method,'GET');
 assert.match(metadata.parameters.url,/format=metadata&metadataHeaders=Message-ID/);
 assert.equal(metadata.onError,'continueRegularOutput');
 const receipt=w.nodes.find(n=>n.name==='Gmail receipt');
 const run=(sent,stored)=>new Function('$','$input',receipt.parameters.jsCode)(
  name=>{assert.equal(name,'Send approved email');return {first:()=>({json:sent})};}, {first:()=>({json:stored})})[0].json;
 const sent={id:'gmail-id',threadId:'gmail-thread'};
 const stored={id:'gmail-id',payload:{headers:[{name:'Message-ID',value:'<rewritten@mail.gmail.com>'}]}};
 assert.deepEqual(run(sent,stored),{ok:true,message_id:'rewritten@mail.gmail.com',thread_id:'gmail-thread'});
 assert.deepEqual(run(sent,{error:'Metadata temporarily unavailable'}),{ok:true,message_id:null,thread_id:'gmail-thread'});
 assert.deepEqual(run(sent,{...stored,id:'different-message'}),{ok:true,message_id:null,thread_id:'gmail-thread'});
});
test('Reply reader builds bounded queries and forwards metadata without body or attachments',()=>{
 const w=load('leadgenGmailRead01'),node=n=>w.nodes.find(x=>x.name===n);
 const code=node('Tracked clients only').parameters.jsCode;
 const run=(body,emailAddress='owner@example.test')=>new Function('$','$json',code)(()=>({first:()=>({json:{body}})}),{emailAddress});
 const result=run({fromEmail:'owner@example.test',contacts:['client@example.test'],after:100});
 assert.match(result[0].json.q,/-from:me -in:spam -in:trash after:100 \{from:client@example.test\}/);
 assert.throws(()=>run({fromEmail:'other@example.test',contacts:['client@example.test'],after:100}));
 assert.throws(()=>run({fromEmail:'owner@example.test',contacts:['client@example.test OR from:anyone'],after:100}));
 assert.throws(()=>run({fromEmail:'owner@example.test',contacts:Array(21).fill('client@example.test'),after:100}));
 assert.match(node('List tracked replies').parameters.url,/maxResults=5/);assert.match(node('Read reply metadata').parameters.url,/format=metadata/);
 const reader=new Function('$input','$',node('Return matched metadata').parameters.jsCode);
 const output=reader({all:()=>[{json:{id:'reply-1',threadId:'thread-1',snippet:'Interested in pricing',payload:{body:{data:'PRIVATE FULL BODY'},headers:[{name:'From',value:'Client <client@example.test>'},{name:'Subject',value:'Pricing'},{name:'In-Reply-To',value:'<known@example.test>'}]}}}]},()=>({first:()=>({json:{nextPageToken:'next'}})}));
 assert.equal(output[0].json.messages[0].sender,'client@example.test');assert.deepEqual(output[0].json.messages[0].references,['known@example.test','thread-1']);assert.equal(output[0].json.nextPageToken,'next');assert.ok(!JSON.stringify(output).includes('PRIVATE FULL BODY'));
});
test('Gmail readiness requires both own-account identity and message-read permission',()=>{
 const code=load('leadgenGmailConnect01').nodes.find(n=>n.name==='Safe account result').parameters.jsCode;
 const run=(profile,read)=>new Function('$input','$',code)({first:()=>({json:read})},()=>({first:()=>({json:profile})}))[0].json;
 assert.equal(run({emailAddress:'owner@example.test'},{resultSizeEstimate:0}).read_access,true);
 assert.equal(run({emailAddress:'owner@example.test'},{error:{}}).ok,false);assert.equal(run({error:{}},{resultSizeEstimate:0}).ok,false);
});
