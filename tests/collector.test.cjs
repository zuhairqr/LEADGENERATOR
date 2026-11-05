const {test}=require('node:test');
const assert=require('node:assert/strict');
const {normalizeCollection}=require('../n8n/adapters/multi-source.cjs');
const now=Date.parse('2026-10-30T00:00:00Z');
const job={id:12,title:'Freelance Laravel developer',description:'Build a website with Stripe checkout.',url:'https://remotive.com/remote-jobs/12',job_type:'contract',publication_date:'2026-10-29T10:00:00'};
test('Remotive admits a real contract shape but excludes permanent, stale and unsafe URLs',()=>{
 const jobs=[job,{...job,id:13,job_type:'full_time'},{...job,id:14,publication_date:'2020-01-01T00:00:00'}, {...job,id:15,url:'https://remotive.com.evil.test/12'}];
 const r=normalizeCollection('remotive',{jobs},now); assert.equal(r.fetched,4);assert.equal(r.leads.length,1);assert.equal(r.leads[0].published_at,'2026-10-29T10:00:00.000Z');
});
test('Remote OK legal notice is ignored; explicit contract tags are required',()=>{
 const row={id:'15',position:'Laravel developer',tags:['contract'],description:job.description,url:'https://remoteOK.com/remote-jobs/15',date:'2026-10-29T00:00:00Z'};
 const r=normalizeCollection('remoteok',[{legal:'retain attribution'},row,{...row,id:'16',tags:['full-time']}],now);assert.equal(r.leads.length,1);assert.match(r.leads[0].description,/Source: Remote OK/);
});
test('Arbeitnow admits remote freelance jobs and excludes on-site jobs',()=>{
 const row={slug:'f-1',title:'Laravel developer',description:job.description,url:'https://www.arbeitnow.com/jobs/f-1',remote:true,job_types:['freelance'],created_at:now/1000-3600};
 assert.equal(normalizeCollection('arbeitnow',{data:[row,{...row,slug:'f-2',remote:false}]},now).leads.length,1);
});
test('Free contract feeds keep official application links and discard expired or non-contract listings',()=>{
 const wwr={guid:{_:'sample-job'},title:'Example: Laravel developer',description:job.description,
   link:'https://weworkremotely.com/remote-jobs/example?utm_source=feed',type:'Contract',
   pubDate:'2026-10-29T10:00:00Z',expires_at:'2026-11-08T10:00:00Z'};
 const rss=normalizeCollection('weworkremotely',{rss:{channel:{item:[wwr,{...wwr,guid:'expired',link:wwr.link+'/expired',expires_at:'2026-10-29T11:00:00Z'}]}}},now);
 assert.equal(rss.leads.length,1);
 assert.equal(rss.leads[0].url,wwr.link);
 assert.equal(rss.leads[0].listing_expires_at,'2026-11-08T10:00:00.000Z');
 const jc={id:1430,url:'https://jobicy.com/jobs/example',jobTitle:'Laravel developer',
   companyName:'Example',jobType:['Contract'],jobDescription:job.description,pubDate:'2026-10-29T10:00:00Z'};
 const json=normalizeCollection('jobicy',{jobs:[jc,{...jc,id:1431,url:jc.url+'/full',jobType:['Full-Time']}]},now);
 assert.equal(json.leads.length,1);
 assert.match(json.leads[0].title,/^Contract/);
 const hi={guid:'https://himalayas.app/companies/example/jobs/laravel',title:'Laravel developer',description:job.description,
   applicationLink:'https://himalayas.app/companies/example/jobs/laravel#apply',employmentType:'Contractor',
   locationRestrictions:[],pubDate:now/1000-3600,expiryDate:now/1000+86400};
 const jobs=normalizeCollection('himalayas',{jobs:[hi,{...hi,locationRestrictions:['PK']}]},now,{excludedCountries:['IN','PK']});
 assert.equal(jobs.leads.length,1);
 assert.equal(jobs.leads[0].url,hi.applicationLink);
});
test('Social search requires buying intent, rejects sellers and off-source hosts, canonicalizes tracking URLs',()=>{
 const row={title:'Looking for a Laravel developer',content:'Need a website and Stripe integration.',url:'https://www.facebook.com/groups/projects/posts/12?utm_source=x#comments'};
 const r=normalizeCollection('facebook-public',{results:[row,{...row,url:'https://www.facebook.com/groups/projects/posts/12'}, {...row,content:'We offer Laravel website services.'},{...row,url:'https://malicious.test/project'}]},now);
 assert.equal(r.leads.length,1);assert.equal(r.leads[0].url,'https://www.facebook.com/groups/projects/posts/12');assert.equal(r.leads[0].published_at,undefined);assert.match(r.leads[0].description,/unverified|Verify/);
});
test('Official Hacker News Ask items require a recent buyer request and keep the original discussion URL',()=>{
 const request={id:123,type:'story',title:'Ask HN: Looking for a WordPress developer to redesign our website',
   text:'We need a freelancer to redesign our WordPress website for our business.',time:Math.floor(now/1000)-3600};
 const seller={...request,id:124,title:'Show HN: Our WordPress website design service',text:'We offer website design.'};
 const old={...request,id:125,time:Math.floor(now/1000)-60*86400};
 const result=normalizeCollection('hackernews',{results:[request,seller,old]},now,{maxAgeDays:30});
 assert.equal(result.fetched,3); assert.equal(result.leads.length,1);
 assert.equal(result.leads[0].url,'https://news.ycombinator.com/item?id=123');
 assert.equal(result.leads[0].source_id.startsWith('url-'),true);
 assert.equal(result.leads[0].published_at,new Date(request.time*1000).toISOString());
});
test('Instagram and public-web preserve source separation and refuse unsupported payload shapes',()=>{
 const row={title:'Looking for Shopify developer',content:'Need a Shopify checkout integration.',url:'https://www.instagram.com/p/example/'};
 assert.equal(normalizeCollection('instagram-public',{results:[row]},now).leads.length,1);
 assert.equal(normalizeCollection('public-web',{results:[row]},now).leads.length,0);
 assert.throws(()=>normalizeCollection('remotive',{unexpected:[]},now));
});
test('Missing URL support must be an explicit failure rather than silently returning zero leads',()=>{
 const vm=require('node:vm');
 assert.throws(()=>vm.runInNewContext('('+normalizeCollection.toString()+')(' + JSON.stringify('remotive')+','+JSON.stringify({jobs:[job]})+','+now+')'),/URL parser is required/);
 const run=vm.runInNewContext('('+normalizeCollection.toString()+')',{URL:require('node:url').URL});
 assert.equal(run('remotive',{jobs:[job]},now).leads.length,1);
});
test('Profiles, general programming discussions and freelance copywriting are not buyer enquiries',()=>{
 const results=[{title:'Looking for a Laravel developer',content:'We need a Laravel developer for a website.',url:'https://www.linkedin.com/in/person/'},
 {title:'Laravel in rust',content:'We need a Laravel developer.',url:'https://www.reddit.com/r/laravel/comments/id/discussion/'}];
 assert.equal(normalizeCollection('public-web',{results},now).leads.length,0);
 assert.equal(normalizeCollection('remotive',{jobs:[{...job,title:'Freelance Copywriter'}]},now).leads.length,0);
 const hiring={title:'[Hiring] Need a Laravel developer for a client website',content:'Project budget available. Need a Laravel developer for Stripe integration.',url:'https://www.reddit.com/r/forhire/comments/id/project/'};
 assert.equal(normalizeCollection('public-web',{results:[hiring]},now).leads.length,1);
});
test('Saved skills, exclusions and locations govern discovery without admitting profiles',()=>{
 const row={title:'Hiring an illustrator',content:'We need an illustrator for a contract project in London.',url:'https://www.reddit.com/r/forhire/comments/id/project/'};
 const filters={keywords:['illustrator'],locationKeywords:['London'],excludedKeywords:[]};
 assert.equal(normalizeCollection('public-web',{results:[row]},now,filters).leads.length,1);
 assert.equal(normalizeCollection('public-web',{results:[row]},now,{...filters,excludedKeywords:['London']}).leads.length,0);
 assert.equal(normalizeCollection('public-web',{results:[row]},now,{...filters,locationKeywords:['Berlin']}).leads.length,0);
 assert.equal(normalizeCollection('public-web',{results:[{...row,url:'https://www.linkedin.com/in/person/'}]},now,filters).leads.length,0);
});
