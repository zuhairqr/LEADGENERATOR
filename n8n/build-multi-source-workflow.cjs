'use strict';
const fs = require('node:fs');
const path = require('node:path');
const {normalizeCollection} = require('./adapters/multi-source.cjs');
const connectionFile = path.join(__dirname,'../storage/n8n/lead-collector/connection.json');
if (!fs.existsSync(connectionFile)) throw new Error('Run the private local collector setup first.');
const connection = JSON.parse(fs.readFileSync(connectionFile,'utf8'));
if (!/^http:\/\/172\.(?:1[6-9]|2\d|3[01])\.\d+\.\d+:8083$/.test(connection.baseUrl)) throw new Error('Expected the private Docker/WSL import bridge URL.');
const credential = {httpHeaderAuth:{id:connection.credentialId,name:'Lead Generator local import'}};
const workflow = {id:connection.workflowId,name:'Lead Generator - Multi-source collection',active:false,
  nodes:[
    {id:'manual',name:'Collect now',type:'n8n-nodes-base.manualTrigger',typeVersion:1,position:[0,260],parameters:{}},
    {id:'schedule',name:'Every 6 hours',type:'n8n-nodes-base.scheduleTrigger',typeVersion:1.2,position:[0,460],parameters:{rule:{interval:[{field:'hours',hoursInterval:6}]}}},
    {id:'notes',name:'Sources and pending Meta connection',type:'n8n-nodes-base.stickyNote',typeVersion:1,position:[0,-340],parameters:{width:1320,height:300,content:'## Multi-source lead collection\nEach branch claims a source from Laravel, fetches one official feed or one SearXNG query, filters, and imports a batch. Laravel enforces source approval, host allowlists, one-minute manual cooldown, 4 requests per rolling 24 hours including tests, validation and deduplication. One branch failure does not stop the others.\nSources: Remotive, Remote OK, Arbeitnow, Facebook public search, Instagram public search, Reddit/LinkedIn/HN public search, Hacker News official Ask API, Jobicy, We Work Remotely, and Himalayas. Search discoveries are unverified until buyer intent is checked. They do not include private posts/groups or inboxes.\nMeta Page/Instagram private messages and comments require your Meta app/account connection and a public HTTPS webhook; they are shown as pending in Laravel. No account access is fabricated. No outreach or AI generation runs here.\nLocal-only: Docker Desktop, the dedicated MariaDB, and deployment/start-local.ps1 must be running. For a changed WSL address rerun deployment/n8n/setup-collector.ps1. Execution payload storage is disabled; per-source counts/errors are kept in Laravel.'}},
  ],connections:{},settings:{executionOrder:'v1',timezone:'Asia/Karachi',executionTimeout:600,
    saveDataErrorExecution:'none',saveDataSuccessExecution:'none',saveManualExecutions:false},pinData:{},tags:[]};
const connect=(a,b,index=0)=>{workflow.connections[a]??={main:[]};workflow.connections[a].main[index]??=[];workflow.connections[a].main[index].push({node:b,type:'main',index:0});};
const sources=['remotive','remoteok','arbeitnow','facebook-public','instagram-public','public-web','hackernews','jobicy','weworkremotely','himalayas'];
for(const [i,source] of sources.entries()) {
  const y=i*300, claim='Claim '+source, gate='Allowed '+source, fetch='Fetch '+source, normalize='Filter '+source, store='Save '+source;
  const rss = source === 'weworkremotely';
  const hn = source === 'hackernews';
  const parse = 'Parse '+source+' RSS';
  workflow.nodes.push(
    {id:'claim-'+source,name:claim,type:'n8n-nodes-base.httpRequest',typeVersion:4.2,position:[300,y],credentials:credential,onError:'continueRegularOutput',parameters:{method:'POST',url:connection.baseUrl+'/api/collection/'+source+'/claim',authentication:'genericCredentialType',genericAuthType:'httpHeaderAuth',sendHeaders:true,headerParameters:{parameters:[{name:'Accept',value:'application/json'}]},options:{timeout:30000}}},
    {id:'allowed-'+source,name:gate,type:'n8n-nodes-base.if',typeVersion:2.2,position:[560,y],parameters:{conditions:{options:{caseSensitive:true,leftValue:'',typeValidation:'strict',version:2},conditions:[{id:'source-allowed',leftValue:'={{ $json.allowed === true }}',rightValue:true,operator:{type:'boolean',operation:'true',singleValue:true}}],combinator:'and'},options:{}}},
    {id:'fetch-'+source,name:fetch,type:'n8n-nodes-base.httpRequest',typeVersion:4.2,position:[800,y],onError:'continueRegularOutput',parameters:{method:'GET',url:'={{ $json.fetch_url }}',sendHeaders:true,headerParameters:{parameters:[{name:'Accept',value:rss?'application/rss+xml, application/xml':'application/json'},{name:'User-Agent',value:'LeadGenerator-Local/1.0 (private opportunity inbox; source attribution retained)'}]},options:{timeout:45000,response:{response:(rss||hn)?{responseFormat:'text',outputPropertyName:'data'}:{responseFormat:'json'}}}}},
    {id:'filter-'+source,name:normalize,type:'n8n-nodes-base.code',typeVersion:2,position:[1040,y],onError:'continueRegularOutput',parameters:{jsCode:'const { URL } = require("url");\nconst normalizeCollection = '+normalizeCollection.toString()+';\nconst claim = $("'+claim+'").first().json;\nlet result;\ntry { const input = $input.all().map(x => x.json); const payload = '+(hn?'{results:input};':source==='remoteok'?'input;':'input[0];')+'\n if (input.some(x => x.error)) result = {leads:[],fetched:0,error_code:"FETCH_FAILED"}; else result = normalizeCollection(claim.source, payload, Date.now(), claim.filters || {}); } catch { result = {leads:[],fetched:0,error_code:"INVALID_SOURCE_RESPONSE"}; }\nreturn [{json:{run_id:claim.run_id,...result}}];'}},
    {id:'save-'+source,name:store,type:'n8n-nodes-base.httpRequest',typeVersion:4.2,position:[1280,y],credentials:credential,onError:'continueRegularOutput',parameters:{method:'POST',url:'='+connection.baseUrl+'/api/collection/runs/{{ $("'+claim+'").first().json.run_id }}/complete',authentication:'genericCredentialType',genericAuthType:'httpHeaderAuth',sendHeaders:true,headerParameters:{parameters:[{name:'Accept',value:'application/json'}]},sendBody:true,specifyBody:'json',jsonBody:'={{ {leads:$json.leads || [],fetched:$json.fetched || 0,error_code:$json.error_code || ($json.error ? "NORMALIZATION_FAILED" : null)} }}',options:{timeout:60000}}}
  );
  if (hn) {
    const expand='Expand hackernews IDs', detail='Fetch hackernews items';
    workflow.nodes.push(
      {id:'expand-hackernews',name:expand,type:'n8n-nodes-base.code',typeVersion:2,position:[920,y-90],onError:'continueRegularOutput',parameters:{jsCode:'const raw = $input.first().json.data;\nconst ids = JSON.parse(raw);\nif (!Array.isArray(ids)) throw new Error("INVALID_HN_IDS");\nreturn ids.slice(0,25).filter(id => Number.isSafeInteger(id) && id > 0).map(id => ({json:{id}}));'}},
      {id:'items-hackernews',name:detail,type:'n8n-nodes-base.httpRequest',typeVersion:4.2,position:[1040,y-90],onError:'continueRegularOutput',parameters:{method:'GET',url:'={{ "https://hacker-news.firebaseio.com/v0/item/" + $json.id + ".json" }}',sendHeaders:true,headerParameters:{parameters:[{name:'Accept',value:'application/json'}]},options:{timeout:15000,response:{response:{responseFormat:'json'}}}}}
    );
    connect(fetch,expand); connect(expand,detail); connect(detail,normalize);
  } else if (rss) {
    workflow.nodes.push({id:'parse-'+source,name:parse,type:'n8n-nodes-base.xml',typeVersion:1,position:[920,y-90],onError:'continueRegularOutput',parameters:{mode:'xmlToJson',dataPropertyName:'data',options:{explicitArray:false,mergeAttrs:false,trim:true}}});
    connect(fetch,parse);
    connect(parse,normalize);
  } else {
    connect(fetch,normalize);
  }
  connect('Collect now',claim); connect('Every 6 hours',claim); connect(claim,gate); connect(gate,fetch);connect(normalize,store);
}
const output=path.join(__dirname,'multi-source-leads.json');fs.writeFileSync(output,JSON.stringify(workflow,null,2)+'\n');
console.log('Built multi-source workflow with '+sources.length+' independent source branches (inactive until verified).');
