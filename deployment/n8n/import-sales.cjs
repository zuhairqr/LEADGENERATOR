'use strict';
const fs=require('node:fs'),path=require('node:path'),{spawnSync}=require('node:child_process'),{DatabaseSync}=require('node:sqlite');
const root=path.resolve(__dirname,'../..'),container='leadgenerator-sandbox-n8n-1';
const manifest=JSON.parse(fs.readFileSync(path.join(root,'storage/n8n/lead-collector/sales-manifest.json'),'utf8'));
const db=new DatabaseSync(path.join(root,'storage/n8n/data/.n8n/database.sqlite'),{readOnly:true});
const owners=db.prepare("SELECT id FROM user WHERE roleSlug='global:owner'").all();db.close();if(owners.length!==1)throw Error('Expected one existing owner');
const run=args=>{const r=spawnSync('docker',args,{stdio:'inherit',windowsHide:true});if(r.status!==0)throw Error('n8n deployment failed');};
const available=[...manifest.ids,'leadgenMultiff89be28'];
const selected=process.argv.find(arg=>arg.startsWith('--workflow='))?.slice('--workflow='.length);
if(selected && !available.includes(selected))throw Error('Workflow is not part of the project sales manifest');
for(const id of selected?[selected]:available) {
 const source=path.join(root,'n8n',id==='leadgenMultiff89be28'?'multi-source-leads.json':id+'.json');
 run(['cp',source,container+':/tmp/leadgen-sales-workflow.json']);
 run(['exec',container,'n8n','import:workflow','--input=/tmp/leadgen-sales-workflow.json','--userId='+owners[0].id]);
 if(id==='leadgenMultiff89be28' || manifest.publish_ids.includes(id))run(['exec',container,'n8n','publish:workflow','--id='+id]);
}
console.log('Configured sales and collection versions published; missing-credential workflows remain drafts. Restart the project n8n service to load CLI changes.');
