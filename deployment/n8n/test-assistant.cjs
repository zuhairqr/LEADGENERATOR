'use strict';
// Run inside the existing n8n container. No keys are exported or printed.
const { createRequire } = require('node:module');
const path = require('node:path');
const appRoot = '/usr/local/lib/node_modules/n8n';
process.env.APP_ROOT_PATH = appRoot;
const appRequire = createRequire(path.join(appRoot, 'package.json'));
process.env.N8N_LOG_LEVEL = 'error';
process.env.N8N_DIAGNOSTICS_ENABLED = 'false';
process.env.NODE_CONFIG_DIR ||= path.join(appRoot, 'bin/config');
appRequire('reflect-metadata');
appRequire('./dist/config');
const { Container } = appRequire('@n8n/di');
const { GlobalConfig } = appRequire('@n8n/config');
const { BaseCommand } = appRequire('./dist/commands/base-command.js');
const { UserRepository } = appRequire('@n8n/db');
const { InstanceAiSettingsService } = appRequire('./dist/modules/instance-ai/instance-ai-settings.service.js');
const { InstanceAiModelService } = appRequire('./dist/modules/instance-ai/instance-ai-model.service.js');
const { InstanceAiVerificationService } = appRequire('./dist/modules/instance-ai/instance-ai-verification.service.js');
const { OutboundHttp } = appRequire('@n8n/backend-network');
const { createAiProxyFetch } = appRequire('./dist/utils/ai-proxy-fetch.js');
const { sanitizeErrorDetail } = appRequire('@n8n/utils/redaction/sanitize-error-detail');
let command;
function safeError(error) {
  return {
    ok: false,
    error: sanitizeErrorDetail(String(error?.message || error?.name || 'Unknown error'), 400),
    status: typeof error?.statusCode === 'number' ? error.statusCode : typeof error?.status === 'number' ? error.status : undefined,
  };
}
(async () => {
  console.log('Initializing native n8n verification services...');
  command = new BaseCommand();
  await command.init();
  const settings = Container.get(InstanceAiSettingsService);
  const { InstanceCredentialBroker } = appRequire('./dist/credentials/instance-credential-broker.js');
  const { SandboxSettingsService } = appRequire('./dist/services/sandbox-settings.service.js');
  const policies = appRequire('./dist/modules/instance-ai/instance-ai-settings.service.js');
  const broker = Container.get(InstanceCredentialBroker);
  broker.registerUse(policies.INSTANCE_AI_MODEL_CREDENTIAL_POLICY);
  Container.get(SandboxSettingsService).registerCredentialUses();
  broker.registerUse(policies.INSTANCE_AI_SEARCH_CREDENTIAL_POLICY);
  await settings.loadFromDb();
  const assignedModel = await broker.getAssignedCredentialId(policies.INSTANCE_AI_MODEL_CREDENTIAL_POLICY);
  console.log('Saved Assistant model connection assigned: ' + Boolean(assignedModel));
  const owner = await Container.get(UserRepository).findOneBy({ role: { slug: 'global:owner' } });
  if (!owner) throw new Error('Configured local instance owner was not found.');
  Container.get(GlobalConfig).instanceAi.sandboxTimeout = 30000;
  const verification = Container.get(InstanceAiVerificationService);
  console.log('Using the saved Assistant model connection. Test requests starting.');
  const results = await Promise.allSettled([
    (async () => {
      const started = performance.now();
      const config = await Container.get(InstanceAiModelService).resolveAgentModelConfig(owner);
      const agents = await import(appRequire.resolve('@n8n/agents'));
      const ai = await import(appRequire.resolve('ai'));
      const response = await ai.generateText({
        model: agents.createModel(config, createAiProxyFetch(Container.get(OutboundHttp))),
        prompt: 'This is a connection smoke test. Reply with exactly N8N_AI_OK and nothing else.',
        maxOutputTokens: 128,
        maxRetries: 0,
        abortSignal: AbortSignal.timeout(45000),
      });
      const output = response.text.trim();
      return { ok: output === 'N8N_AI_OK', output: sanitizeErrorDetail(output, 160), latencyMs: Math.round(performance.now() - started), finishReason: response.finishReason };
    })(),
    verification.verifySandbox(owner, { provider: 'n8n-sandbox' }),
    verification.verifySearch({}),
  ]);
  const summary = Object.fromEntries(results.map((result, index) => [
    ['model', 'sandbox', 'search'][index],
    result.status === 'fulfilled' ? result.value : safeError(result.reason),
  ]));
  console.log('ASSISTANT_TEST_RESULT ' + JSON.stringify(summary));
  await command.finally(Object.values(summary).every(value => value.ok) ? undefined : new Error('One or more native Assistant checks failed.'));
})().catch(async error => {
  console.log('ASSISTANT_TEST_INIT_ERROR ' + JSON.stringify(safeError(error)));
  if (command?.dbConnection?.connectionState?.connected) await command.dbConnection.close().catch(() => {});
  process.exit(1);
});
