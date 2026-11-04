// Runs the synthetic signing chunks that the extractor tests read (made by
// SyntheticWebApp, never OnlyFans' code) in Node, the way the web app runs its
// own: through a minimal webpack runtime. Checks that each chunk signs a request
// exactly as the rules it was built from say, computed here independently. So
// the tests' synthetic code is a working signer, not just text shaped for the
// extractor. Input: the JSON lines of synthetic-web-app-in-node.php.
import { createHash } from 'node:crypto';
import { createInterface } from 'node:readline';
import vm from 'node:vm';

const sha1 = (text) => createHash('sha1').update(text).digest('hex');
const get = (object, path, fallback) => path.split('.').reduce((o, key) => (o == null ? undefined : o[key]), object) ?? fallback;

function expectedSign(rules, time, path) {
  const digest = sha1([rules.static_param, time, path, '0'].join('\n'));
  const checksum = rules.checksum_indexes.reduce((sum, i) => sum + digest.charCodeAt(i), 0) + rules.checksum_constant;
  return [rules.prefix, digest, Math.abs(checksum).toString(16), rules.suffix].join(':');
}

function signWithChunk(chunk, path) {
  const self = {};
  vm.runInNewContext(chunk, { self });
  const modules = self.webpackChunkof_vue[0][1];
  const factory = Object.values(modules)[0];
  // The module's imports: 2 is the SHA-1 function, 3 lodash-style get, 4 the store (a guest).
  const imports = { 1: {}, 2: sha1, 3: get, 4: { A: { auth: { user: {} } } } };
  const require = (id) => imports[id];
  require.n = (module) => () => module;
  const exports = {};
  factory({}, exports, require);
  return exports.A({ url: path });
}

let failures = 0;

for await (const line of createInterface({ input: process.stdin })) {
  if (!line.trim()) continue;
  const { rotation, rules, chunk } = JSON.parse(line);
  const path = '/api2/v2/users/madison420ivy';
  const headers = signWithChunk(chunk, path);
  const expected = expectedSign(rules, String(headers.time), path);
  const ok = headers.sign === expected;
  failures += ok ? 0 : 1;
  console.log(`rotation ${rotation}: ${ok ? 'signs as its rules say' : 'MISMATCH'} (${headers.sign})`);
  if (!ok) console.log(`  expected ${expected}`);
}

process.exit(failures === 0 ? 0 : 1);
