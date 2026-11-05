'use strict';

// Self-contained: embedded verbatim in the n8n Code nodes. No network or AI calls.
function normalizeCollection(source, payload, now = Date.now(), filters = {}) {
  if (typeof URL !== 'function') throw new Error('URL parser is required; import the allowed url builtin in the n8n Code node.');
  const clean = v => String(v ?? '').replace(/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/gi, ' ')
    .replace(/<[^>]*>/g, ' ').replace(/&(?:nbsp|amp|lt|gt|quot|apos|#39);/gi,
      e => ({'&nbsp;':' ','&amp;':'&','&lt;':'<','&gt;':'>','&quot;':'"','&apos;':"'",'&#39;':"'"}[e.toLowerCase()]))
    .replace(/\s+/g, ' ').trim();
  const escape = s => String(s).replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
  const fallback = ['laravel','livewire','filament','wordpress','shopify','website','web developer','web development','ecommerce','e-commerce','landing page','n8n','workflow automation','ai automation','zapier','make.com','ai integration','openai','chatbot','llm','rag','payment gateway','stripe','paypal','checkout','payment integration'];
  const keywords = Array.isArray(filters.keywords) && filters.keywords.length ? filters.keywords : fallback;
  const skill = new RegExp('(?<![a-z0-9])(?:'+keywords.map(escape).join('|')+')(?![a-z0-9])','i');
  const intent = /\b(?:looking for|need(?:ed)?|hiring|seeking|wanted|in search of|anyone (?:know|recommend)|recommend(?:ation)?s?)\b/i;
  const selling = /\b(?:i offer|we offer|my services|our services|hire me|hire us|services available|available for hire)\b/i;
  const request = new RegExp('\\b(?:looking for|need(?:ed)?|hiring|seeking|wanted)\\b.{0,65}(?<![a-z0-9])(?:developers?|designers?|programmers?|agency|freelancers?|'+keywords.map(escape).join('|')+')(?![a-z0-9])','i');
  const hosts = {'remotive':['remotive.com'], 'remoteok':['remoteok.com','www.remoteok.com'],
    'arbeitnow':['arbeitnow.com','www.arbeitnow.com'],
    'jobicy':['jobicy.com','www.jobicy.com'],
    'weworkremotely':['weworkremotely.com','www.weworkremotely.com'],
    'himalayas':['himalayas.app','www.himalayas.app'],
    'facebook-public':['facebook.com','www.facebook.com','m.facebook.com'],
    'instagram-public':['instagram.com','www.instagram.com'],
    'public-web':['reddit.com','www.reddit.com','old.reddit.com','linkedin.com','www.linkedin.com','news.ycombinator.com'],
    'hackernews':['news.ycombinator.com']};
  if (!hosts[source]) throw new Error('Unsupported collection source');
  let rows;
  const sourceApplication = ['jobicy','weworkremotely','himalayas'].includes(source);
  if (['remotive','jobicy','himalayas'].includes(source)) rows = payload?.jobs;
  else if (source === 'remoteok') rows = Array.isArray(payload) ? payload.filter(x => x && x.id && !x.legal) : undefined;
  else if (source === 'arbeitnow') rows = payload?.data;
  else if (source === 'weworkremotely') {
    const channel = payload?.rss?.channel;
    if (channel) rows = [channel.item ?? []].flat();
  }
  else rows = payload?.results;
  if (!Array.isArray(rows)) throw new Error('Unexpected source response shape');
  const search = source.endsWith('-public') || source === 'public-web' || source === 'hackernews';
  const leads = [], seen = new Set();
  for (let row of rows) {
    if (!row || typeof row !== 'object') continue;
    if (source === 'hackernews') {
      const id = Number(row.id), published = Number(row.time) * 1000;
      if (!Number.isSafeInteger(id) || id < 1 || row.type !== 'story' || row.deleted || row.dead
        || !Number.isFinite(published) || published > now || published < now - Math.min(90, Math.max(1, filters.maxAgeDays || 30)) * 86400000) continue;
      row = {title:row.title, content:row.text ?? '', url:'https://news.ycombinator.com/item?id='+id, published_at:new Date(published).toISOString()};
    }
    const restricted = source === 'himalayas' && Array.isArray(row.locationRestrictions) ? row.locationRestrictions
      : source === 'weworkremotely' ? [row.country] : [];
    if (restricted.some(code => (filters.excludedCountries || []).includes(String(code ?? '').trim().toUpperCase()))) continue;
    if (source === 'jobicy') row = {id:row.id,url:row.url,title:row.jobTitle,description:row.jobDescription??row.jobExcerpt,
      company_name:row.companyName,location:row.jobGeo,job_types:row.jobType,date:row.pubDate};
    if (source === 'weworkremotely') row = {id:row.guid?._??row.guid??row.link,url:row.link,title:row.title,description:row.description,
      company_name:String(row.title??'').split(':')[0],location:[row.region,row.country].filter(Boolean).join(' '),
      job_types:[row.type],date:row.pubDate,expires:row.expires_at};
    if (source === 'himalayas') row = {id:row.guid,url:row.applicationLink,title:row.title,description:row.description??row.excerpt,
      company_name:row.companyName,location:restricted.join(' '),job_types:[row.employmentType],
      date:Number(row.pubDate)*1000,expires:Number(row.expiryDate)*1000};
    let title = clean(row.title ?? row.position);
    const text = clean(search ? row.content : row.description);
    const location = clean(row.candidate_required_location ?? row.location ?? '');
    if ((filters.excludedCountries || []).includes('IN') && /\bindia\b/i.test(location) || (filters.excludedCountries || []).includes('PK') && /\bpakistan\b/i.test(location)) continue;
    if (!title || !text || !skill.test(title+' '+text)) continue;
    if ((filters.excludedKeywords || []).some(k=>new RegExp('(?<![a-z0-9])'+escape(k)+'(?![a-z0-9])','i').test(title+' '+text))) continue;
    if ((filters.locationKeywords || []).length && !(filters.locationKeywords || []).some(k=>(title+' '+text+' '+clean(row.candidate_required_location ?? row.location)).toLowerCase().includes(String(k).toLowerCase()))) continue;
    if (search && (!intent.test(title+' '+text) || !request.test(title+' '+text) || selling.test(title+' '+text)
      || /\b(?:looking for (?:a )?(?:job|work)|seeking (?:a )?(?:job|role)|open to work|full[- ]time)\b/i.test(title+' '+text))) continue;
    if (search && !request.test(title) && !/^.{0,45}\b(?:i|we|our company)\b.{0,15}\b(?:need|are looking for|am looking for|are hiring)\b/i.test(text)) continue;
    if (!search) {
      if (!skill.test(title) && !/\b(?:developer|engineer|programmer|architect|automation|integration|web design|AI consultant)\b/i.test(title)) continue;
      const types = [row.job_type, ...(Array.isArray(row.job_types) ? row.job_types : []), ...(Array.isArray(row.tags) ? row.tags : [])].join(' ').toLowerCase();
      const explicitType = /\b(?:contract|contractor|freelance|freelancer)\b/i.test(types+' '+title);
      if (!explicitType || /\b(?:full[-_ ]time|permanent)\b/i.test(String(row.job_type ?? ''))) continue;
      if (sourceApplication && (!/\b(?:contract|contractor|freelance|freelancer)\b/i.test(types) || /\b(?:full[-_ ]time|permanent)\b/i.test(types))) continue;
      if (source === 'arbeitnow' && row.remote !== true) continue;
      if (sourceApplication && !/\b(?:contract|contractor|freelance|freelancer)\b/i.test(title)) title = 'Contract · '+title;
    }
    let url;
    try { url = new URL(row.url); } catch { continue; }
    if (url.protocol !== 'https:' || !hosts[source].includes(url.hostname.toLowerCase()) || url.username || url.password || url.port) continue;
    if (search) {
      const host = url.hostname.toLowerCase(), path = url.pathname;
      if (host.includes('linkedin.com') && !/^\/(?:posts\/|feed\/update\/)/.test(path)) continue;
      if (host.includes('reddit.com') && !/^\/r\/[^/]+\/comments\//.test(path)) continue;
      if ((host.includes('reddit.com') || host === 'news.ycombinator.com') && !request.test(title)) continue;
      if (host.includes('instagram.com') && !/^\/(?:p|reel)\/[^/]+/.test(path)) continue;
      if (host.includes('facebook.com') && !/(?:\/posts\/|\/permalink\/|\/story\.php$|\/permalink\.php$)/.test(path)) continue;
      if (host === 'news.ycombinator.com' && path !== '/item') continue;
    }
    url.hostname = url.hostname.toLowerCase(); url.hash = '';
    if (!sourceApplication) for (const key of [...url.searchParams.keys()]) if (/^(?:utm_|fbclid$|gclid$)/i.test(key)) url.searchParams.delete(key);
    const listingUrl = sourceApplication ? String(row.url).trim() : url.href;
    if (listingUrl.length > 2048 || seen.has(listingUrl)) continue;
    let published = null;
    let expires = null;
    if (source === 'hackernews') published = row.published_at;
    if (!search) {
      let raw = row.publication_date ?? row.date ?? (typeof row.created_at === 'number' ? row.created_at*1000 : null);
      if (typeof raw === 'string' && /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d$/.test(raw)) raw += 'Z';
      const time = raw === null ? NaN : new Date(raw).getTime();
      if (!Number.isFinite(time) || time > now || time < now - Math.min(90,Math.max(1,filters.maxAgeDays || 30))*86400000) continue;
      published = new Date(time).toISOString();
      if (source === 'weworkremotely' || source === 'himalayas') {
        const end = new Date(row.expires).getTime();
        if (!Number.isFinite(end) || end <= now) continue;
        expires = new Date(end).toISOString();
      }
    }
    const sourceId = search ? url.href : String(row.id ?? row.slug ?? '');
    // Search URLs can be long: use a stable non-secret URL hash as the source id.
    const hashes = [2166136261,2246822519,3266489917,668265263];
    for (let i=0;i<url.href.length;i++) for(let j=0;j<hashes.length;j++) hashes[j] = Math.imul(hashes[j] ^ url.href.charCodeAt(i),16777619 + j*2)>>>0;
    const id = search ? 'url-'+hashes.map(h=>h.toString(16).padStart(8,'0')).join('') : sourceId;
    if (!id || id.length > 190) continue;
    const label = {'remoteok':'Remote OK','arbeitnow':'Arbeitnow','remotive':'Remotive','jobicy':'Jobicy','hackernews':'Hacker News',
      'weworkremotely':'We Work Remotely','himalayas':'Himalayas'}[source] ?? source;
    const context = search
      ? 'Public search discovery only. The snippet may be incomplete, stale, or describe a seller rather than a buyer. Verify the original post, date, budget and intent manually. No profile, private group, inbox or contact details were scraped.'
      : 'Contract/freelance job-board opportunity, not a confirmed buyer inquiry. Check eligibility and application rules. Company: '+clean(row.company_name ?? row.company)+'. Salary text is not a project budget: '+clean(row.salary ?? 'Not provided');
    seen.add(listingUrl);
    leads.push({source,source_id:id,url:listingUrl,title:title.slice(0,255),
      description:('Source: '+label+'. Original listing: '+listingUrl+'\n'+context+'\n\n'+text).slice(0,15000),
      ...(published ? {published_at:published} : {}), ...(expires ? {listing_expires_at:expires} : {})});
  }
  if (!search) leads.sort((a,b)=>b.published_at.localeCompare(a.published_at));
  return {fetched:rows.length,leads:leads.slice(0,30),error_code:null};
}
module.exports = {normalizeCollection};
