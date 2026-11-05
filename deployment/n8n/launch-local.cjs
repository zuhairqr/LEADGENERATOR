'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');
const versions = require('./runtime-version.json');
const project = path.resolve(__dirname, '../..');
const root = path.join(project, 'storage', 'n8n');
const nodeDirectory = path.join(root, 'node', `node-${versions.node}-win-x64`);
const node = path.join(nodeDirectory, 'node.exe');
const runtimeLocation = path.join(root, 'runtime-location.json');
const npmRuntime = fs.existsSync(runtimeLocation)
  ? JSON.parse(fs.readFileSync(runtimeLocation, 'utf8').replace(/^\uFEFF/, '')).npmRuntime
  : path.join(root, 'runtime');
const entry = path.join(npmRuntime, 'node_modules', 'n8n', 'bin', 'n8n');
if (!fs.existsSync(node) || !fs.existsSync(entry)) {
  console.error('The pinned local Node/n8n runtime is not installed.');
  process.exit(1);
}
fs.mkdirSync(path.join(root, 'data'), { recursive: true });
// Pass Windows runtime essentials, without inheriting application/provider secrets.
const environment = {};
const essentials = new Set(['systemroot', 'windir', 'comspec', 'temp', 'tmp', 'userprofile', 'appdata', 'localappdata', 'programdata', 'programfiles', 'programfiles(x86)', 'systemdrive', 'pathext', 'number_of_processors', 'processor_architecture']);
for (const [name, value] of Object.entries(process.env)) {
  if (essentials.has(name.toLowerCase())) environment[name] = value;
}
Object.assign(environment, {
  PATH: `${nodeDirectory};${process.env.PATH || ''}`,
  N8N_USER_FOLDER: path.join(root, 'data'),
  DB_TYPE: 'sqlite',
  N8N_LISTEN_ADDRESS: '127.0.0.1',
  N8N_HOST: 'localhost',
  N8N_PORT: '5679',
  N8N_PROTOCOL: 'http',
  N8N_EDITOR_BASE_URL: 'http://localhost:5679/',
  WEBHOOK_URL: 'http://localhost:5679/',
  N8N_SECURE_COOKIE: 'false',
  N8N_DIAGNOSTICS_ENABLED: 'false',
  N8N_PERSONALIZATION_ENABLED: 'false',
  GENERIC_TIMEZONE: 'Asia/Karachi',
  TZ: 'Asia/Karachi',
  EXECUTIONS_DATA_PRUNE: 'true',
  EXECUTIONS_DATA_MAX_AGE: '168',
});
const sandboxConfig = path.join(root, 'sandbox', 'n8n.json');
if (fs.existsSync(sandboxConfig)) {
  const sandbox = JSON.parse(fs.readFileSync(sandboxConfig, 'utf8').replace(/^\uFEFF/, ''));
  if (sandbox.N8N_INSTANCE_AI_SANDBOX_ENABLED !== 'true' ||
      sandbox.N8N_INSTANCE_AI_SANDBOX_PROVIDER !== 'n8n-sandbox' ||
      sandbox.N8N_SANDBOX_SERVICE_URL !== 'http://127.0.0.1:8081' ||
      !/^[a-f0-9]{64}$/.test(sandbox.N8N_SANDBOX_SERVICE_API_KEY || '')) {
    throw new Error('Private local sandbox configuration is invalid.');
  }
  for (const key of ['N8N_INSTANCE_AI_SANDBOX_ENABLED', 'N8N_INSTANCE_AI_SANDBOX_PROVIDER', 'N8N_SANDBOX_SERVICE_URL', 'N8N_SANDBOX_SERVICE_API_KEY']) environment[key] = sandbox[key];
}

const child = spawn(node, [entry, 'start'], { cwd: root, env: environment, stdio: 'inherit', windowsHide: true });
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => child.kill(signal));
child.on('error', error => { console.error(`n8n could not start: ${error.code || 'process error'}`); process.exitCode = 1; });
child.on('exit', code => { process.exitCode = code === null ? 1 : code; });
