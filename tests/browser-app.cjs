// Serve real front-controller HTML to Playwright using only synthetic, in-memory data.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const root = path.join(__dirname, '..');

async function routeFixture(page, hostname = 'search.test') {
    const responses = [];
    await page.route('**/*', async request => {
        const url = new URL(request.request().url());
        if (url.href === 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css' && process.env.BOOTSTRAP_CSS_PATH) {
            return request.fulfill({ contentType: 'text/css', body: fs.readFileSync(process.env.BOOTSTRAP_CSS_PATH) });
        }
        if (url.hostname !== hostname) return request.abort();
        if (url.pathname === '/index.php') {
            const get = Object.fromEntries(url.searchParams);
            const result = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', [path.join(__dirname, 'route-harness.php'), JSON.stringify({ get, search_fixture: true })], { cwd: root, encoding: 'utf8' }));
            assert.equal(result.error, null);
            assert.notEqual(result.status, 500);
            assert.deepEqual(result.writes, []);
            responses.push(result);
            return request.fulfill({ status: result.status, contentType: 'text/html; charset=utf-8', body: result.body });
        }
        if (/^\/assets\/(css|js)\/[a-z]+\.(css|js)$/.test(url.pathname)) {
            return request.fulfill({ contentType: url.pathname.endsWith('.css') ? 'text/css' : 'application/javascript', body: fs.readFileSync(path.join(root, 'public', url.pathname)) });
        }
        return request.fulfill({ status: 404, body: '' });
    });
    return responses;
}
module.exports = { routeFixture };
