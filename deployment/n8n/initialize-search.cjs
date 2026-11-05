'use strict';
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const envFile = path.resolve(__dirname, '../../storage/n8n/sandbox/service.env');
const existing = fs.readFileSync(envFile, 'utf8');
if (!/^SEARXNG_SECRET=\S+$/m.test(existing)) {
  fs.appendFileSync(envFile, (existing.endsWith('\n') ? '' : '\n') + 'SEARXNG_SECRET=' + crypto.randomBytes(32).toString('hex') + '\n');
  console.log('Created private SearXNG signing secret.');
} else console.log('Keeping existing SearXNG signing secret.');
