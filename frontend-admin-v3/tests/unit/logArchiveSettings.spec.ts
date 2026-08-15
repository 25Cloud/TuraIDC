import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const settingsSource = readFileSync(new URL('../../src/pages/settings/index.vue', import.meta.url), 'utf8');
const routeSource = readFileSync(new URL('../../src/router/modules/admin/system.ts', import.meta.url), 'utf8');

assert.match(settingsSource, /\| 'log_archive'/);
assert.match(settingsSource, /group: 'log_archive'/);

for (const key of [
  'pt_archiver_binary',
  'pt_archiver_defaults_file',
  'concurrency',
  'batch_size',
  'sleep_seconds',
  'retention_days',
  'file_retention_days',
]) {
  assert.match(settingsSource, new RegExp(`key: '${key}'`));
}

assert.doesNotMatch(settingsSource, /archive_root|report_root|mount_point|base_path|nas/i);
assert.match(routeSource, /path: 'log-archive'/);
assert.match(routeSource, /name: 'AdminLogArchiveSettings'/);
assert.match(routeSource, /permission: 'settings\.view'/);
assert.match(routeSource, /settingsTab: 'log_archive'/);

console.log('log archive settings tests passed');
