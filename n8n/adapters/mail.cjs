'use strict';
// Embedded into n8n Code nodes. No network, credentials or AI access.
function buildApprovedMime(b,profile) {
 if(String(profile.emailAddress).toLowerCase()!==String(b.fromEmail).toLowerCase()) throw Error('Sender does not match connected Gmail account');
 if(!/^[0-9a-f-]{36}$/.test(b.messageId)||/[\r\n]/.test(b.recipient+b.subject+b.fromEmail+(b.fromName||''))||!b.recipient||!b.subject||!b.body)throw Error('Invalid approved message');
 const rfc=b.messageId+'@'+b.fromEmail.split('@')[1];
 const from=b.fromName?'=?UTF-8?B?'+Buffer.from(b.fromName).toString('base64')+'?= <'+b.fromEmail+'>':b.fromEmail;
 const headers=['From: '+from,'To: '+b.recipient,'Subject: =?UTF-8?B?'+Buffer.from(b.subject).toString('base64')+'?=','Message-ID: <'+rfc+'>','MIME-Version: 1.0','Content-Type: text/plain; charset=UTF-8','Content-Transfer-Encoding: base64'];
 if(b.parentMessageId && !/[\r\n]/.test(b.parentMessageId))headers.push('In-Reply-To: <'+b.parentMessageId.replace(/[<>]/g,'')+'>','References: <'+b.parentMessageId.replace(/[<>]/g,'')+'>');
 const text=Buffer.from(b.body).toString('base64').match(/.{1,76}/g).join('\r\n');
 return {raw:Buffer.from(headers.join('\r\n')+'\r\n\r\n'+text).toString('base64url'),rfc};
}
function gmailDeliveryReceipt(sent, metadata) {
 if (typeof sent?.id !== 'string' || !sent.id) throw Error('Gmail result missing');
 const headers = metadata?.id === sent.id && Array.isArray(metadata?.payload?.headers) ? metadata.payload.headers : [];
 const header = headers.find(h => h.name?.toLowerCase() === 'message-id')?.value;
 const messageId = typeof header === 'string' ? header.trim().match(/^<([^<>\s@]+@[^<>\s@]+)>$/)?.[1] : null;
 return {ok:true, message_id:messageId || null, thread_id:sent.threadId || null};
}
function parseInboxReceipts(items) {
 const output=[];
 for(const v of items) {
  const headers={};
  if(Array.isArray(v.payload?.headers))for(const h of v.payload.headers)headers[h.name.toLowerCase()]=h.value;
  else for(const [k,val] of Object.entries(v.headers||{}))headers[k.toLowerCase()]=Array.isArray(val)?val.join(' '):String(val);
  const from=headers.from||v.from?.value?.[0]?.address||v.from?.text||v.from||'';
  const sender=String(from).match(/(?:<|^)([^<>\s]+@[^<>\s]+)>?/)?.[1]?.toLowerCase();
  const refs=[headers['in-reply-to']||v.inReplyTo||'',headers.references||v.references||''].flat().join(' ');
  const references=refs.match(/[^<>\s,]+@[^<>\s,]+/g)||[];
  if(v.threadId)references.push(v.threadId);
  if(sender&&references.length)output.push({sender,references:[...new Set(references)].slice(0,30),outcome:'reply'});
 }
 return output;
}
module.exports={buildApprovedMime,gmailDeliveryReceipt,parseInboxReceipts};
