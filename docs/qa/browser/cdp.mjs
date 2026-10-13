// Minimal Chrome DevTools Protocol driver (no dependencies; Node 22 global WebSocket).
import { spawn } from 'node:child_process';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export async function launch({ port = 9333, width = 1280, height = 1600, profileDir } = {}) {
    // Refuse to attach to a leftover Chrome from an earlier run (it would replay old console/log entries).
    const stale = await fetch(`http://127.0.0.1:${port}/json/version`).then(() => true, () => false);
    if (stale) throw new Error(`Port ${port} already has a Chrome instance; kill it first`);
    const userDataDir = profileDir || mkdtempSync(join(process.env.QA_TMP || tmpdir(), 'chrome-'));
    const proc = spawn('google-chrome', [
        '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${userDataDir}`,
        '--ignore-certificate-errors', '--no-first-run', '--no-default-browser-check',
        `--window-size=${width},${height}`, 'about:blank',
    ], { stdio: 'ignore' });
    let target;
    for (let i = 0; i < 50 && !target; i++) {
        await sleep(200);
        try {
            const list = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
            target = list.find((t) => t.type === 'page');
        } catch { /* not up yet */ }
    }
    if (!target) throw new Error('Chrome did not start');
    const ws = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise((r, j) => { ws.onopen = r; ws.onerror = j; });

    let id = 0;
    const pending = new Map();
    const listeners = [];
    ws.onmessage = (ev) => {
        const msg = JSON.parse(ev.data);
        if (msg.id && pending.has(msg.id)) {
            const { resolve, reject } = pending.get(msg.id);
            pending.delete(msg.id);
            msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
        } else if (msg.method) {
            listeners.forEach((l) => l(msg));
        }
    };
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const mid = ++id;
        pending.set(mid, { resolve, reject });
        ws.send(JSON.stringify({ id: mid, method, params }));
    });

    const consoleErrors = [];
    listeners.push((m) => {
        if (m.method === 'Runtime.exceptionThrown') {
            consoleErrors.push('exception: ' + (m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text));
        }
        if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') {
            consoleErrors.push('console.error: ' + m.params.args.map((a) => a.value ?? a.description).join(' '));
        }
        if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error') {
            consoleErrors.push('log: ' + m.params.entry.text + ' ' + (m.params.entry.url || ''));
        }
    });
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Log.enable');
    await send('Page.addScriptToEvaluateOnNewDocument', {
        source: `window.__csp = []; document.addEventListener('securitypolicyviolation', (e) => {
            window.__csp.push(e.violatedDirective + ' ' + (e.blockedURI || '') + ' ' + (e.sourceFile || '') + ':' + e.lineNumber);
        });`,
    });

    const evaluate = async (expression) => {
        const r = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
        if (r.exceptionDetails) throw new Error('eval: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
        return r.result.value;
    };
    const waitFor = async (expression, timeout = 20000) => {
        const end = Date.now() + timeout;
        while (Date.now() < end) {
            try { if (await evaluate(expression)) return true; } catch { /* page navigating */ }
            await sleep(250);
        }
        throw new Error('timeout waiting for: ' + expression);
    };
    const goto = async (url, readyExpr = 'document.readyState === "complete"') => {
        await send('Page.navigate', { url });
        await sleep(500);
        await waitFor(readyExpr, 30000);
    };
    const screenshot = async (path) => {
        const { data } = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
        writeFileSync(path, Buffer.from(data, 'base64'));
    };
    const close = async () => { try { await send('Browser.close'); } catch { /* closing */ } proc.kill(); };

    return { send, evaluate, waitFor, goto, screenshot, close, consoleErrors, sleep };
}
