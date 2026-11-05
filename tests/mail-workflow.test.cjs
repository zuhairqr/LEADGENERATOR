const {test}=require('node:test'),assert=require('node:assert/strict');
const {buildApprovedMime,parseInboxReceipts}=require('../n8n/adapters/mail.cjs');
const {gmailAccountResult}=require('../n8n/adapters/gmail-health.cjs');
const message={messageId:'11111111-1111-4111-8111-111111111111',recipient:'client@example.test',fromEmail:'owner@example.test',fromName:'Owner Name',subject:'Your Laravel project',body:'A personalized plain-text draft.\nReply to opt out.',parentMessageId:'parent@example.test'};

test('Gmail diagnostics distinguish API, consent, scope and quota failures without returning raw errors',()=>{
 for(const [error,code] of [
  ['Gmail API has not been used in project 123 before or it is disabled.','GMAIL_API_DISABLED'],
  ['invalid_grant: revoked','GMAIL_REAUTHORIZE_REQUIRED'],
  ['Request had insufficient authentication scopes.','GMAIL_READ_SCOPE_REQUIRED'],
  ['RESOURCE_EXHAUSTED quota','GMAIL_RATE_LIMITED'],
  ['Unrecognized provider failure','GMAIL_AUTHORIZATION_OR_READ_SCOPE_REQUIRED']
 ]) {
  assert.deepEqual(gmailAccountResult({error:{message:error,request:{authorization:'PRIVATE_TEST_SENTINEL'}}},{}),{ok:false,error_code:code});
 }
 assert.deepEqual(gmailAccountResult({emailAddress:'owner@example.test'},{resultSizeEstimate:0}),{ok:true,emailAddress:'owner@example.test',read_access:true});
 assert.equal(gmailAccountResult({emailAddress:'owner@example.test'},{}).ok,false);
});
test('Gmail MIME preserves approved content, sender and thread headers',()=>{
 const result=buildApprovedMime(message,{emailAddress:'owner@example.test'}),mime=Buffer.from(result.raw,'base64url').toString();
 assert.match(mime,/To: client@example.test/);assert.match(mime,/In-Reply-To: <parent@example.test>/);
 assert.ok(mime.includes(Buffer.from(message.fromName).toString('base64')));assert.equal(result.rfc,message.messageId+'@example.test');
 assert.equal(Buffer.from(mime.split('\r\n\r\n')[1].replace(/\r\n/g,''),'base64').toString(),message.body);
});
test('Gmail sender mismatch and header injection fail closed',()=>{
 assert.throws(()=>buildApprovedMime(message,{emailAddress:'someone@example.test'}));
 assert.throws(()=>buildApprovedMime({...message,subject:'Hi\r\nBcc: other@example.test'},{emailAddress:message.fromEmail}));
});
test('Gmail and IMAP replies yield sender/references only, without private body data',()=>{
 const receipts=parseInboxReceipts([{payload:{headers:[{name:'From',value:'Client <client@example.test>'},{name:'In-Reply-To',value:'<parent@example.test>'}]},threadId:'thread-1',snippet:'Private body not forwarded'},
 {from:{value:[{address:'second@example.test'}]},references:['one@example.test','two@example.test'],text:'Private text not forwarded'}, {from:'no-reference@example.test'}]);
 assert.deepEqual(receipts,[{sender:'client@example.test',references:['parent@example.test','thread-1'],outcome:'reply'},{sender:'second@example.test',references:['one@example.test','two@example.test'],outcome:'reply'}]);
 assert.ok(!JSON.stringify(receipts).includes('Private'));
});
