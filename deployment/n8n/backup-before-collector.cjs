'use strict';
const fs=require('node:fs');const path=require('node:path');const {DatabaseSync,backup}=require('node:sqlite');
const root=path.resolve(__dirname,'../..');
(async()=>{
 const source=path.join(root,'storage/n8n/data/.n8n');
 const destination=path.join(root,'storage/n8n/data-backups','before-collector-'+new Date().toISOString().replace(/[^0-9]/g,''));
 fs.mkdirSync(destination,{recursive:true,mode:0o700});
 const db=new DatabaseSync(path.join(source,'database.sqlite'),{readOnly:true});
 await backup(db,path.join(destination,'database.sqlite'));db.close();
 fs.copyFileSync(path.join(source,'config'),path.join(destination,'config'));
 const verify=new DatabaseSync(path.join(destination,'database.sqlite'),{readOnly:true});
 if(verify.prepare('PRAGMA quick_check').get().quick_check!=='ok')throw new Error('Backup verification failed');verify.close();
 console.log('Verified private n8n database/config backup: '+path.basename(destination));
})().catch(e=>{console.error(e.message);process.exitCode=1});
