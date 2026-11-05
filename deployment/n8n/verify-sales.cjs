'use strict';
const fs=require('node:fs'),path=require('node:path'),{DatabaseSync}=require('node:sqlite');
const root=path.resolve(__dirname,'../..'),state=path.join(root,'storage/n8n/lead-collector');
const manifest=JSON.parse(fs.readFileSync(path.join(state,'sales-manifest.json'),'utf8'));
const db=new DatabaseSync(path.join(root,'storage/n8n/data/.n8n/database.sqlite'),{readOnly:true});
const status=Object.fromEntries(manifest.ids.map(id=>{const w=db.prepare('SELECT active,activeVersionId FROM workflow_entity WHERE id=?').get(id);return [id,!!(w?.active&&w?.activeVersionId)];}));db.close();
if(manifest.publish_ids.some(id=>!status[id]))throw Error('A configured sales workflow is not published');
fs.writeFileSync(path.join(state,'sales-connection.json'),JSON.stringify({ai_ready:status.leadgenSalesAi01,worker_ready:status.leadgenSalesWorker01,discovery_ready:!!status.leadgenBusinessWorker01,gmail_credential_name:manifest.gmail_credential_name,gmail_installed:!!status.leadgenSalesGmail01&&!!status.leadgenGmailConnect01,inbox_installed:!!status.leadgenSalesInbox01&&!!status.leadgenGmailRead01,gmail_ready:!!status.leadgenSalesGmail01&&manifest.gmail_authorized===true,inbox_ready:!!status.leadgenSalesInbox01&&manifest.gmail_authorized===true,verified_at:new Date().toISOString()},null,2));
console.log(JSON.stringify(status));
