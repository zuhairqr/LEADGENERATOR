'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const directory = path.resolve(__dirname, '../../storage/n8n/sandbox');
const entries = Object.fromEntries(fs.readFileSync(path.join(directory, 'service.env'), 'utf8').trim().split(/\r?\n/).map(line => {
  const split = line.indexOf('='); return [line.slice(0, split), line.slice(split+1)];
}));
const base = 'http://127.0.0.1:8081';
const key = entries.SANDBOX_API_KEYS;
if (!/^[a-f0-9]{64}$/.test(key || '')) throw new Error('Invalid private sandbox credentials.');
async function request(route, options = {}) {
  const response = await fetch(base+route, {
    ...options, headers: { 'X-Api-Key': key, 'Content-Type': 'application/json', ...options.headers },
    signal: AbortSignal.timeout(300000),
  });
  if (!response.ok) throw new Error('Sandbox request returned HTTP '+response.status);
  return response;
}
(async () => {
  await request('/healthz');
  const denied = await fetch(base+'/sandboxes', { signal: AbortSignal.timeout(10000) });
  if (![401,403].includes(denied.status)) throw new Error('Sandbox API failed authentication check.');
  for (let attempt = 0; ; attempt++) {
    const status = await (await request('/admin/runners')).json();
    if (status.runners.some(runner => runner.healthy)) break;
    if (attempt >= 24) throw new Error('Sandbox runner is not ready.');
    await new Promise(resolve => setTimeout(resolve, 5000));
  }
  const id = crypto.randomUUID();
  try {
    await request('/sandboxes', { method: 'POST', body: JSON.stringify({ id, ephemeral: true, egress: 'none' }) });
    const execution = await request('/sandboxes/'+id+'/executions', { method: 'POST', body: JSON.stringify({ command: "printf 'LEADGEN_SANDBOX_OK'", timeout_ms: 10000 }) });
    const events = (await execution.text()).trim().split('\n').map(line => JSON.parse(line));
    if (!events.some(event => event.type === 'exit' && event.exit_code === 0 && event.success) || events.filter(event => event.type === 'stdout').map(event => event.data).join('').trim() !== 'LEADGEN_SANDBOX_OK') throw new Error('Sandbox execution failed.');
  } finally {
    const cleanup = await fetch(base+'/sandboxes/'+id, { method: 'DELETE', headers: { 'X-Api-Key': key }, signal: AbortSignal.timeout(30000) });
    if (![204,404].includes(cleanup.status)) throw new Error('Sandbox cleanup failed: '+cleanup.status);
  }
  fs.writeFileSync(path.join(directory, 'n8n.json'), JSON.stringify({
    N8N_INSTANCE_AI_SANDBOX_ENABLED: 'true',
    N8N_INSTANCE_AI_SANDBOX_PROVIDER: 'n8n-sandbox',
    N8N_SANDBOX_SERVICE_URL: base,
    N8N_SANDBOX_SERVICE_API_KEY: key,
  }), { mode: 0o600 });
  console.log('PASS: health, authentication, isolated execution, cleanup.');
  console.log('Private n8n sandbox configuration saved. Restart n8n to load it.');
})().catch(error => { console.error(error.message); process.exitCode = 1; });
