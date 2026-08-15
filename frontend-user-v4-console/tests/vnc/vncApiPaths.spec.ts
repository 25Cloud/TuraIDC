import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';

const sourcePath = new URL('../../public/vnc/vnc.html', import.meta.url);
const source = readFileSync(sourcePath, 'utf8');
const pakoLibPath = new URL('../../public/vnc/vendor/pako/lib/', import.meta.url);
const pakoRuntimeModules = [
  'utils/common.js',
  'zlib/adler32.js',
  'zlib/constants.js',
  'zlib/crc32.js',
  'zlib/deflate.js',
  'zlib/gzheader.js',
  'zlib/inffast.js',
  'zlib/inflate.js',
  'zlib/inftrees.js',
  'zlib/messages.js',
  'zlib/trees.js',
  'zlib/zstream.js',
];

assert.doesNotMatch(source, /\/api\/(?:admin|client)\//);
assert.doesNotMatch(source, /admin_user_id/);
assert.match(source, /\/api\/v2\/client\/services\/\$\{id\}\/vnc/);
assert.match(source, /\/api\/v2\/client\/vnc-tokens\/\$\{encodeURIComponent\(freshToken\)\}/);
assert.match(source, /\/api\/v2\/client\/vnc-tokens\/\$\{encodeURIComponent\(token\)\}/);
assert.match(source, /new URL\(relayPath, `\$\{apiBase\}\/`\)/);
assert.doesNotMatch(source, /apiBase \|\| location\.origin/);
assert.match(source, /relayUrl\.protocol === 'https:'/);
assert.match(source, /relayUrl\.protocol === 'http:'/);

pakoRuntimeModules.forEach((modulePath) => {
  assert.ok(existsSync(new URL(modulePath, pakoLibPath)), `Missing noVNC pako runtime module: ${modulePath}`);
});

console.log('vnc API path tests passed');
