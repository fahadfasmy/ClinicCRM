// popup.js
const $ = (id) => document.getElementById(id);

(async () => {
  const s = await chrome.runtime.sendMessage({ type: 'settings' });
  $('autoCapture').checked = !!s.autoCapture;

  const { stats = {} } = await chrome.storage.local.get('stats');
  $('today').textContent = stats.day === new Date().toISOString().slice(0, 10) ? stats.count : 0;

  if (!s.apiKey) {
    $('conn').textContent = 'Not connected. Open Settings.';
    $('conn').className = 'err';
  } else {
    const r = await chrome.runtime.sendMessage({ type: 'api', action: 'ping' });
    $('conn').textContent = r && r.ok ? `Connected · ${r.data.label}` : 'Can’t reach the CRM';
    $('conn').className = r && r.ok ? 'ok' : 'err';
  }

  $('autoCapture').addEventListener('change', (e) => chrome.storage.sync.set({ autoCapture: e.target.checked }));
  $('settings').addEventListener('click', () => chrome.runtime.openOptionsPage());
  $('openCrm').addEventListener('click', () => chrome.tabs.create({ url: s.crmUrl + '/pages/leads.php?source=whatsapp' }));

  $('diag').addEventListener('click', async () => {
    const out = $('diagOut');
    out.hidden = false;
    const [tab] = await chrome.tabs.query({ url: 'https://web.whatsapp.com/*' });
    if (!tab) { out.textContent = 'WhatsApp Web is not open in this browser.'; return; }
    try {
      const d = await chrome.tabs.sendMessage(tab.id, { type: 'diagnose' });
      out.textContent = [
        `Chat list found:        ${d.chatListFound ? 'yes' : 'NO'}`,
        `Chats visible:          ${d.rows}`,
        `Unsaved-number chats:   ${d.unknownNumberRows}`,
        `Unread chats:           ${d.unreadRows}`,
        `Would capture now:      ${d.wouldCapture.join(', ') || '—'}`,
        `Open chat:              ${d.openChat ? (d.openChat.title || '?') : '—'}`,
        `Open chat number:       ${d.openChat && d.openChat.phone ? d.openChat.phone : '—'}`,
      ].join('\n');
    } catch (e) {
      out.textContent = 'Could not talk to the WhatsApp tab. Reload web.whatsapp.com and try again.';
    }
  });
})();
