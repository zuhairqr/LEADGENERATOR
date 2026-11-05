'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { normalizeRemotive } = require('./adapters/remotive.cjs');

const workflow = {
  name: 'Lead Generator - Remotive contracts (REVIEW BEFORE ACTIVATION)',
  active: false,
  nodes: [
    { id: 'manual', name: 'Manual test', type: 'n8n-nodes-base.manualTrigger', typeVersion: 1, position: [0, 120], parameters: {} },
    { id: 'schedule', name: 'Every 6 hours - inactive until published', type: 'n8n-nodes-base.scheduleTrigger', typeVersion: 1.2, position: [0, 320], parameters: { rule: { interval: [{ field: 'hours', hoursInterval: 6 }] } } },
    { id: 'permission', name: 'Permission gate - review first', type: 'n8n-nodes-base.code', typeVersion: 2, position: [260, 220], parameters: {
      jsCode: "// Set true ONLY after checking current Remotive terms for your use.\n// Also set LEAD_REMOTIVE_APPROVED=true on Laravel after review.\nconst sourceApproved = false;\nif (!sourceApproved) throw new Error('Paused: Remotive access and data-use terms have not been reviewed.');\nreturn [{json: {approved: true}}];",
    } },
    { id: 'fetch', name: 'Fetch official software jobs API', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [520, 220], parameters: {
      method: 'GET', url: 'https://remotive.com/api/remote-jobs', sendQuery: true,
      queryParameters: { parameters: [{ name: 'category', value: 'software-dev' }] },
      sendHeaders: true, headerParameters: { parameters: [{ name: 'Accept', value: 'application/json' }] },
      options: { timeout: 30000, response: { response: { responseFormat: 'json' } } },
    }, onError: 'stopWorkflow' },
    { id: 'normalize', name: 'Relevant recent contract leads only', type: 'n8n-nodes-base.code', typeVersion: 2, position: [780, 220], parameters: {
      jsCode: 'const normalizeRemotive = ' + normalizeRemotive.toString() + ';\nreturn normalizeRemotive($input.first().json).map(json => ({json}));',
    } },
    { id: 'import', name: 'Import into Laravel - set URL and credential', type: 'n8n-nodes-base.httpRequest', typeVersion: 4.2, position: [1040, 220], parameters: {
      method: 'POST', url: 'https://leads.your-domain.example/api/leads/import',
      authentication: 'genericCredentialType', genericAuthType: 'httpHeaderAuth',
      sendHeaders: true, headerParameters: { parameters: [{ name: 'Accept', value: 'application/json' }] },
      sendBody: true, specifyBody: 'json', jsonBody: '={{ $json }}',
      options: { timeout: 30000, batching: { batch: { batchSize: 1, batchInterval: 1500 } } },
    }, onError: 'stopWorkflow' },
    { id: 'notes', name: 'Required setup and limitations', type: 'n8n-nodes-base.stickyNote', typeVersion: 1, position: [250, -260], parameters: {
      width: 900, height: 340,
      content: '## Paused connector — no real leads fetched yet\n1. Verify current Remotive API terms, rate limits, attribution and private-dashboard/AI-processing permissions. This is NOT permission to scrape other websites.\n2. Change permission gate to true only after review. Enable matching Laravel source config.\n3. Set Laravel HTTPS import URL and HTTP Header Auth credential: Authorization = Bearer <private token>.\n4. Manual test first; check this workflow against your installed n8n version before publishing.\n5. Candidate cadence: every six hours, one feed request per run, no aggressive retries. Adjust to current provider rules.\n6. Filters: recent 30 days, explicit contract/freelance, relevant skills, max 30 newest per run. Not exhaustive: no pagination/cursor; new busy feeds may need a revised strategy.\n7. Keep Remotive attribution and original links. No auto-contact and no AI calls on import.\n8. Add redacted error alerts before production. Saving execution payloads is disabled for privacy.',
    } },
  ],
  connections: {},
  settings: { executionOrder: 'v1', timezone: 'UTC', executionTimeout: 180, saveDataErrorExecution: 'none', saveDataSuccessExecution: 'none', saveManualExecutions: false },
  pinData: {}, tags: [],
};
for (const [from, to] of [
  ['Manual test', 'Permission gate - review first'],
  ['Every 6 hours - inactive until published', 'Permission gate - review first'],
  ['Permission gate - review first', 'Fetch official software jobs API'],
  ['Fetch official software jobs API', 'Relevant recent contract leads only'],
  ['Relevant recent contract leads only', 'Import into Laravel - set URL and credential'],
]) workflow.connections[from] = { main: [[{ node: to, type: 'main', index: 0 }]] };
const destination = path.join(__dirname, 'remotive-contract-leads.json');
fs.writeFileSync(destination, JSON.stringify(workflow, null, 2) + '\n');
console.log('Generated inactive workflow: ' + destination);
