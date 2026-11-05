'use strict';
// Read-only scheduler metadata. Never reads credential data or execution bodies.
const path=require('node:path');
const {DatabaseSync}=require('node:sqlite');
const db=new DatabaseSync(path.resolve(__dirname,'../../storage/n8n/data/.n8n/database.sqlite'),{readOnly:true});
try {
 const id='leadgenSalesInbox01';
 const w=db.prepare('SELECT active,activeVersionId FROM workflow_entity WHERE id=?').get(id);
 if(!w?.activeVersionId) throw Error('Inbox workflow is not published');
 const v=db.prepare('SELECT nodes FROM workflow_history WHERE workflowId=? AND versionId=?').get(id,w.activeVersionId);
 const nodes=JSON.parse(v.nodes);
 const timer=nodes.find(n=>n.type==='n8n-nodes-base.scheduleTrigger');
 const callback=nodes.find(n=>n.name==='Sync only known client conversations');
 const columns=new Set(db.prepare('PRAGMA table_info(workflow_statistics)').all().map(c=>c.name));
 const eventColumn=['latestEvent','latestDate','updatedAt'].find(c=>columns.has(c));
 const statistics=db.prepare('SELECT name,count'+(eventColumn?','+eventColumn+' AS latest_event':'')+' FROM workflow_statistics WHERE workflowId=?').all(id);
 console.log(JSON.stringify({published:!!w.active,five_minute_schedule:timer?.parameters?.rule?.interval?.some(i=>i.field==='minutes'&&i.minutesInterval===5)===true,correct_private_callback:callback?.parameters?.url==='http://172.23.16.1:8083/api/sales/inbox-sync',authenticated_callback:callback?.parameters?.genericAuthType==='httpHeaderAuth'&&!!callback?.credentials?.httpHeaderAuth?.id,statistics}));
} finally {db.close();}
