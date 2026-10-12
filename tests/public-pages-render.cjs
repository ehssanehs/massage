// Structural sanity check of the public pages rendered by the real front controller:
// every non-void tag that opens must close. Uses the same CLI harness as the route tests.
const {execFileSync} = require('node:child_process');
const path = require('node:path');
const VOID = new Set(['meta','link','br','img','input','hr','source','area','base','col','embed','param','track','wbr']);
const TENANTS = {men: 'test_men', women: 'test_women'};
let failures = 0;
for (const route of ['home', 'public.massage', 'public.education', 'login']) {
  const out = execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'route-harness.php'), JSON.stringify({get: {r: route}, session: {}, tenants: TENANTS})], {cwd: path.join(__dirname, '..'), encoding: 'utf8'});
  const {body, status, error} = JSON.parse(out);
  const opened = {}, closed = {};
  for (const m of body.matchAll(/<([a-zA-Z][\w-]*)(?:\s[^>]*?)?\/?>/g)) if (!VOID.has(m[1].toLowerCase())) opened[m[1].toLowerCase()] = (opened[m[1].toLowerCase()] || 0) + 1;
  for (const m of body.matchAll(/<\/([a-zA-Z][\w-]*)>/g)) closed[m[1].toLowerCase()] = (closed[m[1].toLowerCase()] || 0) + 1;
  const bad = Object.keys(opened).filter(t => (closed[t] || 0) !== opened[t]).map(t => `${t} ${opened[t]}/${closed[t] || 0}`);
  const title = (body.match(/<title>([^<]*)<\/title>/) || [])[1];
  const ok = status === 200 && !error && bad.length === 0;
  if (!ok) failures++;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${route.padEnd(18)} status=${status} bytes=${body.length} title=${title} ${bad.length ? 'unbalanced: ' + bad.join(', ') : ''}${error ? ' error=' + JSON.stringify(error) : ''}`);
}
process.exit(failures ? 1 : 0);
