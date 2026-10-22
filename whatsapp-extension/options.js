// options.js
const DEFAULTS = { crmUrl: '', apiKey: '', autoCapture: true };
const $ = (id) => document.getElementById(id);

function setStatus(text, kind) {
  $('status').textContent = text;
  $('status').className = 'status ' + (kind || '');
}

async function load() {
  const s = { ...DEFAULTS, ...(await chrome.storage.sync.get(Object.keys(DEFAULTS))) };
  $('crmUrl').value = s.crmUrl;
  $('apiKey').value = s.apiKey;
  $('autoCapture').checked = s.autoCapture;
}

$('save').addEventListener('click', async () => {
  let crmUrl = $('crmUrl').value.trim().replace(/\/+$/, '');
  const apiKey = $('apiKey').value.trim();
  if (!/^https?:\/\//.test(crmUrl)) crmUrl = 'https://' + crmUrl;
  let origin;
  try { origin = new URL(crmUrl).origin; } catch (e) { return setStatus('That CRM address doesn’t look right.', 'err'); }
  if (!apiKey) return setStatus('Paste the API key from the CRM.', 'err');

  // Ask for permission to reach a CRM address that isn't built in
  const granted = await chrome.permissions.request({ origins: [origin + '/*'] }).catch(() => false);
  if (!granted && !(await chrome.permissions.contains({ origins: [origin + '/*'] }))) {
    return setStatus('Permission to reach the CRM was not granted.', 'err');
  }

  await chrome.storage.sync.set({ crmUrl: origin, apiKey, autoCapture: $('autoCapture').checked });
  $('crmUrl').value = origin;
  setStatus('Testing connection…');
  const r = await chrome.runtime.sendMessage({ type: 'api', action: 'ping' });
  if (r && r.ok) setStatus(`Connected as “${r.data.label}”. Reload WhatsApp Web to start.`, 'ok');
  else if (r && r.status === 401) setStatus('The CRM rejected this key. Check it was copied fully and is still active.', 'err');
  else setStatus((r && r.data && r.data.error) || 'Could not reach the CRM.', 'err');
});

load();
