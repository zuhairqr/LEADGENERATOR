'use strict';
const fs=require('node:fs'),path=require('node:path'),{DatabaseSync}=require('node:sqlite');
const {buildApprovedMime,gmailDeliveryReceipt,parseInboxReceipts}=require('./adapters/mail.cjs');
const {gmailAccountResult}=require('./adapters/gmail-health.cjs');
const root=path.resolve(__dirname,'..'),state=path.join(root,'storage/n8n/lead-collector');
const connection=JSON.parse(fs.readFileSync(path.join(state,'connection.json'),'utf8'));
if(!/^http:\/\/172\.(?:1[6-9]|2\d|3[01])\.\d+\.\d+:8083$/.test(connection.baseUrl)) throw Error('Private import bridge required');
const names=JSON.parse(fs.readFileSync(path.join(state,'sales-settings.json'),'utf8'));
const db=new DatabaseSync(path.join(root,'storage/n8n/data/.n8n/database.sqlite'),{readOnly:true});
const credentials=db.prepare("SELECT DISTINCT c.id,c.name,c.type FROM credentials_entity c JOIN shared_credentials s ON s.credentialsId=c.id JOIN shared_workflow w ON w.projectId=s.projectId WHERE w.workflowId=? AND c.usageScope <> 'instance'").all(connection.workflowId); db.close();
const find=(type,name)=>{const list=credentials.filter(c=>c.type===type && (!name || c.name===name));return list.length===1?list[0]:null;};
const ai=find('openAiApi',names.ai_name);
if(names.ai_name && !ai) throw Error('AI workflow credential name not found or ambiguous; Assistant-only connections cannot be used by workflow nodes.');
const gmail=names.gmail_name ? find('gmailOAuth2',names.gmail_name) : null;
const imap=names.imap_name ? find('imap',names.imap_name) : null;
if(names.gmail_name && !gmail || names.imap_name && !imap) throw Error('Email credential name not found or ambiguous. Save the exact name in the app.');
const header={httpHeaderAuth:{id:connection.credentialId,name:'Lead Generator local import'}};
const settings={executionOrder:'v1',timezone:'Asia/Karachi',executionTimeout:180,saveDataErrorExecution:'none',saveDataSuccessExecution:'none',saveManualExecutions:false};
const workflow=(id,name)=>({id,name,active:false,nodes:[],connections:{},settings:{...settings},pinData:{},tags:[]});
const node=(id,name,type,x,parameters={},extra={})=>({id,name,type,typeVersion:1,position:[x,260],parameters,...extra});
const link=(w,a,b)=>{w.connections[a]={main:[[{node:b,type:'main',index:0}]]};};
const webhook=which=>node('webhook','Private request','n8n-nodes-base.webhook',0,{httpMethod:'POST',path:which,authentication:'headerAuth',responseMode:'responseNode',options:{}},{typeVersion:2,webhookId:which,credentials:header});
const code=(name,x,jsCode)=>node(name,name,'n8n-nodes-base.code',x,{jsCode},{typeVersion:2});
const respond=node('respond','Return safe result','n8n-nodes-base.respondToWebhook',900,{respondWith:'json',responseBody:'={{ $json }}',options:{}},{typeVersion:1.4});
const post=(name,x,url,jsonBody)=>node(name,name,'n8n-nodes-base.httpRequest',x,{method:'POST',url,authentication:'genericCredentialType',genericAuthType:'httpHeaderAuth',sendBody:true,specifyBody:'json',jsonBody,options:{timeout:150000}},{typeVersion:4.2,credentials:header});
const all=[];
const a=workflow('leadgenSalesAi01','Lead Generator - Private AI drafting');
a.nodes=[webhook('leadgen-ai-draft'),node('openai','Saved AI connection','@n8n/n8n-nodes-langchain.openAi',300,{
 resource:'text',operation:'message',modelId:{__rl:true,mode:'id',value:'={{ $json.body.model }}'},
 messages:{values:[{role:'system',content:'={{ $json.body.system }}'},{role:'user',content:'={{ $json.body.prompt }}'}]},
 simplify:false,jsonOutput:true,options:{maxTokens:2200}
},{typeVersion:1.8,...(ai?{credentials:{openAiApi:{id:ai.id,name:ai.name}}}:{}),onError:'continueRegularOutput'}),
code('Validate AI response',600,`const r=$input.first().json; try { const c=r.choices?.[0]; const result=typeof c?.message?.content==='string'?JSON.parse(c.message.content):c?.message?.content; if(c?.finish_reason!=='stop'||!result||typeof result!=='object')throw Error(); return [{json:{ok:true,result}}]; } catch {return [{json:{ok:false,error_code:'AI_PROVIDER_ERROR'}}];}`),respond];
link(a,'Private request','Saved AI connection');link(a,'Saved AI connection','Validate AI response');link(a,'Validate AI response','Return safe result');all.push(a);
const worker=workflow('leadgenSalesWorker01','Lead Generator - Approved outbox and follow-up drafts');
worker.nodes=[node('timer','Every 5 minutes','n8n-nodes-base.scheduleTrigger',0,{rule:{interval:[{field:'minutes',minutesInterval:5}]}},{typeVersion:1.2}),
 node('manual','Process approved queue now','n8n-nodes-base.manualTrigger',0,{}),
 post('Process approved messages and due drafts',400,connection.baseUrl+'/api/sales/tick','={{ {} }}'),
 node('notice','Approval and budget rules','n8n-nodes-base.stickyNote',0,{content:'## Approval is required for every email\nLaravel rechecks the exact approved message, recipient, sender and lead outcome. Edited drafts lose approval. Unknown delivery is never retried automatically. Follow-ups are drafts and need fresh approval. AI calls require verified profile facts, consent, auto-draft enabled and a rolling 24-hour budget. Only one AI draft is attempted per tick. Local services must remain running.',width:800,height:260})];
link(worker,'Every 5 minutes','Process approved messages and due drafts');link(worker,'Process approved queue now','Process approved messages and due drafts');all.push(worker);
const discovery=workflow('leadgenBusinessWorker01','Lead Generator - Business search and website audits');
discovery.nodes=[node('timer','Every 5 minutes — bounded discovery','n8n-nodes-base.scheduleTrigger',0,{rule:{interval:[{field:'minutes',minutesInterval:5}]}},{typeVersion:1.2}),node('manual','Discover and audit now','n8n-nodes-base.manualTrigger',0,{}),post('Public business discovery and audit',400,connection.baseUrl+'/api/collection/business-tick','={{ {} }}')];link(discovery,'Every 5 minutes — bounded discovery','Public business discovery and audit');link(discovery,'Discover and audit now','Public business discovery and audit');all.push(discovery);
if(gmail) {
 const g=workflow('leadgenSalesGmail01','Lead Generator - Approved Gmail transport');
 const google=(name,x,method,url,body,timeout=30000)=>node(name,name,'n8n-nodes-base.httpRequest',x,{method,url,authentication:'predefinedCredentialType',nodeCredentialType:'gmailOAuth2',...(body?{sendBody:true,specifyBody:'json',jsonBody:body}:{}),options:{timeout}},{typeVersion:4.2,credentials:{gmailOAuth2:{id:gmail.id,name:gmail.name}}});
 const profile=workflow('leadgenGmailConnect01','Lead Generator - Gmail account verification');
 profile.nodes=[webhook('leadgen-gmail-profile'),{...google('Read own account',250,'GET','https://gmail.googleapis.com/gmail/v1/users/me/profile'),onError:'continueRegularOutput'},
  {...google('Check reply-read permission',450,'GET','https://gmail.googleapis.com/gmail/v1/users/me/messages?maxResults=1&q=rfc822msgid%3Aleadgenerator-permission-probe%40invalid.invalid&fields=resultSizeEstimate'),onError:'continueRegularOutput'},
  code('Safe account result',650,`const gmailAccountResult=${gmailAccountResult.toString()};return [{json:gmailAccountResult($('Read own account').first().json,$input.first().json)}];`),respond];
 link(profile,'Private request','Read own account');link(profile,'Read own account','Check reply-read permission');link(profile,'Check reply-read permission','Safe account result');link(profile,'Safe account result','Return safe result');all.push(profile);
 const read=workflow('leadgenGmailRead01','Lead Generator - Tracked client reply metadata');
 read.nodes=[webhook('leadgen-gmail-read'),google('Verify reply mailbox',150,'GET','https://gmail.googleapis.com/gmail/v1/users/me/profile'),
  code('Tracked clients only',300,`const b=$('Private request').first().json.body;if(String($json.emailAddress).toLowerCase()!==String(b.fromEmail).toLowerCase())throw Error('Mailbox mismatch');if(!Array.isArray(b.contacts)||!b.contacts.length||b.contacts.length>20||b.contacts.some(e=>typeof e!=='string'||! /^[a-zA-Z0-9.!#$%&'*+/=?^_\x60|~-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$/.test(e)))throw Error('Tracked recipients required');const after=Number(b.after);if(!Number.isFinite(after)||after<0)throw Error('Invalid checkpoint');return [{json:{q:'-from:me -in:spam -in:trash after:'+Math.floor(after)+' {'+b.contacts.map(e=>'from:'+e).join(' ')+'}',pageToken:typeof b.pageToken==='string'?b.pageToken:''}}];`),
  google('List tracked replies',450,'GET',`={{ 'https://gmail.googleapis.com/gmail/v1/users/me/messages?maxResults=5&q='+encodeURIComponent($json.q)+($json.pageToken?'&pageToken='+encodeURIComponent($json.pageToken):'') }}`),
  code('Expand message IDs',600,`const rows=$json.messages||[];return rows.length?rows.map(m=>({json:m})):[{json:{empty:true}}];`),
  node('has-message','Has replies','n8n-nodes-base.if',750,{conditions:{options:{caseSensitive:true,typeValidation:'strict',version:2},conditions:[{id:'has',leftValue:'={{ !$json.empty }}',rightValue:true,operator:{type:'boolean',operation:'true',singleValue:true}}],combinator:'and'},options:{}},{typeVersion:2.2}),
  google('Read reply metadata',900,'GET',`={{ 'https://gmail.googleapis.com/gmail/v1/users/me/messages/'+encodeURIComponent($json.id)+'?format=metadata&metadataHeaders=From&metadataHeaders=Subject&metadataHeaders=In-Reply-To&metadataHeaders=References&metadataHeaders=Auto-Submitted&metadataHeaders=X-Failed-Recipients' }}`),
  code('Return matched metadata',1050,`const messages=[];for(const item of $input.all()){const v=item.json,h={};for(const x of v.payload?.headers||[])h[x.name.toLowerCase()]=x.value;const sender=(h.from||'').match(/(?:<|^)([^<>\\s]+@[^<>\\s]+)>?/)?.[1]?.toLowerCase();const references=((h['in-reply-to']||'')+' '+(h.references||'')).match(/[^<>\\s]+@[^<>\\s]+/g)||[];if(v.threadId)references.push(v.threadId);if(!sender||!references.length)continue;messages.push({sender,references:[...new Set(references)].slice(0,30),provider_message_id:v.id,subject:h.subject||'',snippet:v.snippet||'',outcome:h['x-failed-recipients']?'bounce':'reply',failed_recipient:h['x-failed-recipients']||null});}return [{json:{ok:true,messages,nextPageToken:$('List tracked replies').first().json.nextPageToken||null}}];`),
  code('No matching replies',900,`return [{json:{ok:true,messages:[],nextPageToken:null}}];`),{...respond,position:[1250,260]}];
 for(const [x,y] of [['Private request','Verify reply mailbox'],['Verify reply mailbox','Tracked clients only'],['Tracked clients only','List tracked replies'],['List tracked replies','Expand message IDs'],['Expand message IDs','Has replies'],['Read reply metadata','Return matched metadata'],['Return matched metadata','Return safe result'],['No matching replies','Return safe result']])link(read,x,y);
 read.connections['Has replies']={main:[[{node:'Read reply metadata',type:'main',index:0}],[{node:'No matching replies',type:'main',index:0}]]};all.push(read);
 g.nodes=[webhook('leadgen-gmail-send'),post('Claim approved server envelope',100,connection.baseUrl.replace(/:8083$/,':8084')+'/api/sales/gmail-envelope','={{ {message_id:$json.body.messageId} }}'),google('Verify own Gmail sender',200,'GET','https://gmail.googleapis.com/gmail/v1/users/me/profile'),
 code('Build exact approved MIME',400,'const buildApprovedMime='+buildApprovedMime.toString()+'; return [{json:buildApprovedMime($("Claim approved server envelope").first().json,$json)}];'),
 google('Send approved email',600,'POST','https://gmail.googleapis.com/gmail/v1/users/me/messages/send','={{ {raw:$json.raw} }}'),
 {...google('Read sent Message-ID',800,'GET',`={{ 'https://gmail.googleapis.com/gmail/v1/users/me/messages/'+encodeURIComponent($json.id)+'?format=metadata&metadataHeaders=Message-ID&fields=id,payload(headers)' }}`,undefined,8000),onError:'continueRegularOutput'},
 code('Gmail receipt',1000,`const gmailDeliveryReceipt=${gmailDeliveryReceipt.toString()}; return [{json:gmailDeliveryReceipt($('Send approved email').first().json,$input.first().json)}];`),{...respond,position:[1200,260]}];
 for(const [x,y] of [['Private request','Claim approved server envelope'],['Claim approved server envelope','Verify own Gmail sender'],['Verify own Gmail sender','Build exact approved MIME'],['Build exact approved MIME','Send approved email'],['Send approved email','Read sent Message-ID'],['Read sent Message-ID','Gmail receipt'],['Gmail receipt','Return safe result']])link(g,x,y);all.push(g);
}
const parseReceipt='const parseInboxReceipts='+parseInboxReceipts.toString()+';return parseInboxReceipts($input.all().map(x=>x.json)).map(json=>({json}));';
if(gmail || imap) {
 const inbox=workflow('leadgenSalesInbox01','Lead Generator - Match own inbox replies');
 const trigger=gmail?node('inbox','Every 5 minutes — tracked clients only','n8n-nodes-base.scheduleTrigger',0,{rule:{interval:[{field:'minutes',minutesInterval:5}]}},{typeVersion:1.2}):
 node('inbox','Own IMAP inbox','n8n-nodes-base.emailReadImap',0,{mailbox:'INBOX',postProcessAction:'nothing',format:'simple',downloadAttachments:false,options:{customEmailConfig:'["ALL"]',trackLastMessageId:true}},{typeVersion:2.1,credentials:{imap:{id:imap.id,name:imap.name}}});
 if(gmail){inbox.nodes=[trigger,post('Sync only known client conversations',400,connection.baseUrl+'/api/sales/inbox-sync','={{ {} }}')];link(inbox,trigger.name,'Sync only known client conversations');}
 else {inbox.nodes=[trigger,code('Match reply headers only',300,parseReceipt),post('Record matched replies',600,connection.baseUrl+'/api/sales/receipt','={{ $json }}')];link(inbox,trigger.name,'Match reply headers only');link(inbox,'Match reply headers only','Record matched replies');}all.push(inbox);
}
for(const w of all)fs.writeFileSync(path.join(root,'n8n',w.id+'.json'),JSON.stringify(w,null,2)+'\n');
fs.writeFileSync(path.join(state,'sales-manifest.json'),JSON.stringify({ids:all.map(w=>w.id),publish_ids:all.filter(w=>w.id!=='leadgenSalesAi01'||ai).map(w=>w.id),ai_configured:!!ai,gmail_configured:!!gmail,gmail_credential_name:gmail?.name??null,gmail_authorized:names.gmail_verified===true,inbox_configured:!!(gmail||imap)},null,2));
console.log('Built '+all.length+' sales workflows; AI '+(ai?'workflow credential selected':'pending workflow connection (Assistant-only is preserved)')+', Gmail '+(gmail?'credential selected':'pending user connection')+', inbox '+(gmail||imap?'credential selected':'pending user connection')+'.');
