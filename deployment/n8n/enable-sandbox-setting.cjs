'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { DatabaseSync } = require('node:sqlite');
const root = path.resolve(__dirname, '../..');
const db = new DatabaseSync(path.join(root, 'storage/n8n/data/.n8n/database.sqlite'));
db.exec('PRAGMA busy_timeout=5000; BEGIN IMMEDIATE');
try {
  const row = db.prepare('SELECT value FROM settings WHERE key = ?').get('instanceAi.settings');
  if (!row) throw new Error('Expected existing instance AI settings were not found.');
  const settings = JSON.parse(row.value);
  const backup = path.join(root, 'storage/n8n/sandbox/settings-before-sandbox-enable.json');
  if (!fs.existsSync(backup)) fs.writeFileSync(backup, row.value, { flag: 'wx', mode: 0o600 });
  settings.sandboxEnabled = true;
  db.prepare('UPDATE settings SET value = ? WHERE key = ?').run(JSON.stringify(settings), 'instanceAi.settings');
  db.exec('COMMIT');
  console.log('Enabled only the existing instance sandbox setting; other settings retained.');
} catch (error) { db.exec('ROLLBACK'); throw error; }
finally { db.close(); }
