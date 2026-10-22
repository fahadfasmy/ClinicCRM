// background.js — the only place that talks to the CRM (keeps the API key out of the WhatsApp page).

const DEFAULTS = { crmUrl: '', apiKey: '', autoCapture: true };

async function settings() {
  return { ...DEFAULTS, ...(await chrome.storage.sync.get(Object.keys(DEFAULTS))) };
}

async function callCrm({ action, params = {}, body = null }) {
  const s = await settings();
  if (!s.crmUrl || !s.apiKey) return { ok: false, status: 0, data: { error: 'not_configured' } };
  const url = new URL('/pages/api_whatsapp.php', s.crmUrl);
  url.searchParams.set('action', action);
  for (const [k, v] of Object.entries(params)) url.searchParams.set(k, v);
  try {
    const res = await fetch(url, {
      method: body ? 'POST' : 'GET',
      headers: { 'X-CRM-Key': s.apiKey, ...(body ? { 'Content-Type': 'application/json' } : {}) },
      body: body ? JSON.stringify(body) : undefined,
      credentials: 'omit',
      cache: 'no-store',
    });
    let data = {};
    try { data = await res.json(); } catch (e) { data = { error: 'Unexpected response from CRM (HTTP ' + res.status + ')' }; }
    return { ok: res.ok, status: res.status, data };
  } catch (e) {
    return { ok: false, status: 0, data: { error: 'Cannot reach the CRM: ' + e.message } };
  }
}

// Daily capture counter for the popup
async function bumpCounter() {
  const today = new Date().toISOString().slice(0, 10);
  const { stats = {} } = await chrome.storage.local.get('stats');
  if (stats.day !== today) { stats.day = today; stats.count = 0; }
  stats.count++;
  stats.last = Date.now();
  await chrome.storage.local.set({ stats });
}

chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  (async () => {
    if (msg.type === 'settings') return sendResponse(await settings());
    if (msg.type === 'openOptions') { chrome.runtime.openOptionsPage(); return sendResponse({ ok: true }); }
    if (msg.type === 'api') {
      const r = await callCrm(msg);
      if (msg.action === 'capture' && r.data && r.data.created) await bumpCounter();
      return sendResponse(r);
    }
    sendResponse({ ok: false });
  })();
  return true; // async response
});
