'use strict';
async function main() {
  const base = 'http://127.0.0.1:8082';
  for (let attempt = 0; ; attempt++) {
    try {
      const response = await fetch(base + '/healthz', { signal: AbortSignal.timeout(5000) });
      if (response.ok) break;
    } catch {}
    if (attempt >= 24) throw new Error('SearXNG is not healthy yet.');
    await new Promise(resolve => setTimeout(resolve, 3000));
  }
  console.log('PASS: local SearXNG health endpoint.');
  if (process.argv.includes('--live')) {
    const response = await fetch(base+'/search?q=n8n%20documentation&format=json', {signal:AbortSignal.timeout(45000)});
    if (!response.ok) throw new Error('SearXNG JSON search returned HTTP '+response.status);
    const data = await response.json();
    if (!Array.isArray(data.results) || data.results.length === 0 || !data.results.some(result => typeof result.title === 'string' && /^https?:/.test(result.url))) throw new Error('Live SearXNG search returned no usable results.');
    console.log('PASS: live JSON search returned '+data.results.length+' results.');
    if (data.unresponsive_engines?.length) console.log('Unavailable upstream engines: '+data.unresponsive_engines.map(item => item[0]).join(', '));
  }
}
main().catch(error => {console.error(error.message);process.exitCode=1;});
