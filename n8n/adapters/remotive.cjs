'use strict';

// Self-contained so the workflow builder can embed this in an n8n Code node.
function normalizeRemotive(payload, options = {}) {
  if (!payload || !Array.isArray(payload.jobs)) throw new Error('Unexpected Remotive API response: jobs array missing');
  const now = options.now ?? Date.now();
  const maxItems = Math.min(30, Math.max(1, options.maxItems ?? 30));
  const maxAgeDays = options.maxAgeDays ?? 30;
  const clean = (value) => String(value ?? '')
    .replace(/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/gi, ' ')
    .replace(/<[^>]*>/g, ' ')
    .replace(/&(?:nbsp|amp|lt|gt|quot|apos|#39);/gi, (entity) => ({
      '&nbsp;': ' ', '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&apos;': "'", '&#39;': "'",
    }[entity.toLowerCase()] ?? entity))
    .replace(/\s+/g, ' ').trim();
  const terms = /\b(?:laravel|livewire|filament|website|wordpress|shopify|ecommerce|e-commerce|web development|landing page|n8n|workflow automation|ai automation|zapier|make\.com|ai integration|openai|chatbot|llm|rag|gpt|payment gateway|merchant integration|stripe|paypal|checkout|payment integration)\b/i;
  const seenIds = new Set();
  const seenUrls = new Set();
  const leads = [];
  for (const job of payload.jobs) {
    if (!job || typeof job !== 'object') continue;
    const sourceId = String(job.id ?? '');
    if (!/^[a-zA-Z0-9_-]{1,190}$/.test(sourceId)) continue;
    const title = clean(job.title);
    const description = clean(job.description);
    if (!title || !description || !terms.test(title + ' ' + description)) continue;
    const type = String(job.job_type ?? '').toLowerCase().trim();
    // Do not mistake permanent/full-time employment for client-project leads.
    // Unknown types need an explicit contract/freelance indication in the TITLE.
    const isContract = ['contract', 'freelance'].includes(type)
      || (type === '' && /\b(?:contract|freelance|freelancer)\b/i.test(title));
    if (!isContract) continue;
    let url;
    try { url = new URL(job.url); } catch { continue; }
    if (url.protocol !== 'https:' || url.hostname !== 'remotive.com' || url.username || url.password || url.port) continue;
    url.hash = '';
    if (url.href.length > 2048) continue;
    const rawDate = typeof job.publication_date === 'string' ? job.publication_date : '';
    // Source timestamps without a zone are interpreted as UTC, not server-local time.
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/.test(rawDate)) continue;
    const date = new Date(/(?:Z|[+-]\d{2}:\d{2})$/.test(rawDate) ? rawDate : rawDate + 'Z');
    if (!Number.isFinite(date.getTime()) || date.getTime() > now || date.getTime() < now - maxAgeDays * 86400000) continue;
    if (seenIds.has(sourceId) || seenUrls.has(url.href)) continue;
    seenIds.add(sourceId); seenUrls.add(url.href);
    const context = [
      'Source: Remotive. Original listing: ' + url.href,
      'Type: ' + (type || 'contract/freelance inferred from title; verify manually'),
      'Company: ' + clean(job.company_name).slice(0, 255),
      'Location eligibility: ' + (clean(job.candidate_required_location).slice(0, 400) || 'Not provided; verify'),
      'Salary text (not a project budget): ' + (clean(job.salary).slice(0, 400) || 'Not provided'),
      'This is a job-board opportunity, not a confirmed buyer inquiry. Check application rules.',
    ].join('\n');
    leads.push({
      source: 'remotive', source_id: sourceId, url: url.href, title: title.slice(0, 255),
      description: (context + '\n\n' + description).slice(0, 15000),
      published_at: date.toISOString(),
    });
  }
  return leads.sort((a, b) => b.published_at.localeCompare(a.published_at)).slice(0, maxItems);
}

module.exports = { normalizeRemotive };
