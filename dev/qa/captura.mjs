// dev/qa/captura.mjs — Abre una página en Chrome sin ventana con la sesión de QA, ejecuta JS y guarda una captura.
//   W=375 H=812 node dev/qa/captura.mjs "PHPSESSID=…" URL 'return algo;' salida.png
import { spawn } from 'node:child_process'; import fs from 'node:fs';
const [ck, url, js, out] = process.argv.slice(2);
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', ['--headless=new', '--remote-debugging-port=9336', '--window-size=1400,1000', '--user-data-dir=/tmp/cdpsh' + Date.now(), 'about:blank'], { stdio: 'ignore' });
let tabs = null;
for (let i = 0; i < 40 && !tabs; i++) {   // Chrome puede tardar en arrancar
    await new Promise(r => setTimeout(r, 500));
    tabs = await fetch('http://127.0.0.1:9336/json').then(r => r.json()).catch(() => null);
}
const ws = new WebSocket(tabs.find(t => t.type === 'page').webSocketDebuggerUrl); await new Promise(r => ws.onopen = r);
let id = 0; const pend = {}; ws.onmessage = m => { const d = JSON.parse(m.data); if (d.id && pend[d.id]) { pend[d.id](d); delete pend[d.id]; } };
const send = (method, params = {}) => new Promise(r => { const i = ++id; pend[i] = r; ws.send(JSON.stringify({ id: i, method, params })); });
const [n, v] = ck.split('='); await send('Network.setCookie', { name: n, value: v, url: 'http://localhost:8383/' });
const W = +(process.env.W || 1280);
await send('Emulation.setDeviceMetricsOverride', { width: W, height: +(process.env.H || 900), deviceScaleFactor: 1, mobile: W < 768 });
await send('Page.enable'); await send('Page.navigate', { url }); await new Promise(r => setTimeout(r, 3000));
const ev = await send('Runtime.evaluate', { expression: `(async()=>{${js || ''}})()`, awaitPromise: true, returnByValue: true }); await new Promise(r => setTimeout(r, 800));
if (ev.result?.exceptionDetails) console.log('ERROR JS:', ev.result.exceptionDetails.exception?.description || ev.result.exceptionDetails.text);
else if (ev.result?.result?.value !== undefined) console.log(JSON.stringify(ev.result.result.value));
const s = await send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(out, Buffer.from(s.result.data, 'base64'));
chrome.kill(); process.exit(0);
