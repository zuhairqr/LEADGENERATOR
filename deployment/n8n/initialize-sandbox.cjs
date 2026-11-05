'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const directory = path.resolve(__dirname, '../../storage/n8n/sandbox');
fs.mkdirSync(directory, { recursive: true });
const destination = path.join(directory, 'service.env');
if (!fs.existsSync(destination)) {
  const secret = () => crypto.randomBytes(32).toString('hex');
  const values = {
    SANDBOX_API_KEYS: secret(),
    SANDBOX_API_RUNNER_REGISTRATION_TOKEN: secret(),
    SANDBOX_API_RUNNER_API_KEY: secret(),
  };
  fs.writeFileSync(destination, Object.entries(values).map(([key, value]) => key+'='+value).join('\n')+'\n', { mode: 0o600, flag: 'wx' });
  console.log('Created private sandbox credentials. Values are not displayed.');
} else console.log('Keeping existing sandbox credentials.');
