'use strict';
const fs=require('node:fs'),path=require('node:path'),{spawnSync}=require('node:child_process');
const {DatabaseSync}=require('node:sqlite');
const root=path.resolve(__dirname,'../..');
const secretFile=path.join(root,'storage/n8n/lead-collector/credential-import.private.json');
const container='leadgenerator-sandbox-n8n-1',temporary='/tmp/leadgen-credential.private.json';
const run=(args)=>{const r=spawnSync('docker',args,{stdio:'inherit',windowsHide:true});if(r.status!==0)throw new Error('Docker operation failed: '+args[0]);};
const db=new DatabaseSync(path.join(root,'storage/n8n/data/.n8n/database.sqlite'),{readOnly:true});
const owners=db.prepare('SELECT id FROM user WHERE roleSlug = ?').all('global:owner');db.close();
if(owners.length!==1)throw new Error('Expected one existing n8n owner; no account was reset.');
try {
 run(['cp',secretFile,container+':'+temporary]);
 run(['exec','-u','root',container,'chown','node:node',temporary]);
 run(['exec','-u','root',container,'chmod','600',temporary]);
 run(['exec',container,'n8n','import:credentials','--input='+temporary,'--userId='+owners[0].id]);
 run(['cp',path.join(root,'n8n/multi-source-leads.json'),container+':/tmp/leadgen-multi-source.json']);
 run(['exec',container,'n8n','import:workflow','--input=/tmp/leadgen-multi-source.json','--userId='+owners[0].id]);
} finally {
 spawnSync('docker',['exec',container,'node','-e','try{require("node:fs").unlinkSync("'+temporary+'")}catch(e){if(e.code!=="ENOENT")throw e}'],{stdio:'inherit',windowsHide:true});
 if(fs.existsSync(secretFile))fs.unlinkSync(secretFile);
}
