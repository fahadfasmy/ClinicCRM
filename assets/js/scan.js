// assets/js/scan.js — reads GS1 barcodes from a USB scanner (the scanner types into a field and presses Enter).
// A GS1 DataMatrix or GS1-128 on medical packs carries (01) product code, (17) expiry, (10) batch, (21) serial.
// Plain EAN/UPC barcodes carry only the product code.
(function () {
  const GS = '\u001d';
  const FIXED = { '00': 18, '01': 14, '02': 14, '11': 6, '12': 6, '13': 6, '15': 6, '16': 6, '17': 6, '20': 2 };
  const VARIABLE = ['10', '21', '22', '30', '37', '90', '91', '92', '93', '94', '95', '96', '97', '98', '99', '240', '241', '250', '251', '400', '401', '8003', '8004'];

  function checkGtin(d) {
    if (!/^\d{8}$|^\d{12,14}$/.test(d)) return null;
    d = d.padStart(14, '0');
    let sum = 0;
    for (let i = 0; i < 13; i++) sum += +d[i] * (i % 2 === 0 ? 3 : 1);
    return (10 - (sum % 10)) % 10 === +d[13] ? d : null;
  }

  // YYMMDD → YYYY-MM-DD; day 00 means the last day of that month
  function gs1Date(v) {
    if (!/^\d{6}$/.test(v)) return null;
    const y = 2000 + +v.slice(0, 2), m = +v.slice(2, 4);
    let d = +v.slice(4, 6);
    if (m < 1 || m > 12) return null;
    if (d === 0) d = new Date(y, m, 0).getDate();
    return y + '-' + String(m).padStart(2, '0') + '-' + String(d).padStart(2, '0');
  }

  function fields(raw) {
    const out = {};
    let s = raw.replace(/<GS>|\[GS\]/gi, GS).trim().replace(/^\][A-Za-z]\d/, '');
    if (s.includes('(')) {
      for (const m of s.matchAll(/\((\d{2,4})\)([^(]*)/g)) out[m[1]] = m[2].replace(GS, '').trim();
      return out;
    }
    let i = 0;
    while (i < s.length) {
      if (s[i] === GS) { i++; continue; }
      let ai = null;
      for (const len of [2, 3, 4]) {
        const c = s.substr(i, len);
        if (len === 2 && FIXED[c]) { ai = c; break; }
        if (VARIABLE.includes(c)) { ai = c; break; }
        if (len === 4 && /^3[1-6]\d\d$/.test(c)) { ai = c; break; }
      }
      if (!ai) return out;
      i += ai.length;
      const fixed = FIXED[ai] || (/^3[1-6]\d\d$/.test(ai) ? 6 : 0);
      if (fixed) { out[ai] = s.substr(i, fixed); i += fixed; continue; }
      const end = s.indexOf(GS, i);
      out[ai] = s.slice(i, end === -1 ? s.length : end);
      i = end === -1 ? s.length : end + 1;
    }
    return out;
  }

  /** { gtin, expiry, batch, serial, raw } or null when nothing useful was read. */
  function parse(raw) {
    raw = String(raw || '');
    const plain = raw.trim().replace(/^\][A-Za-z]\d/, '');
    if (/^\d{8}$|^\d{12,13}$/.test(plain)) return checkGtin(plain) ? { gtin: checkGtin(plain), raw } : null;
    const f = fields(raw);
    const r = { gtin: checkGtin(f['01'] || f['02'] || ''), expiry: gs1Date(f['17'] || ''), batch: (f['10'] || '').trim() || null, serial: f['21'] || null, raw };
    return r.gtin || r.batch || r.expiry ? r : null;
  }

  /** Wires a text field: keeps the scanner's group separators and calls onScan(result, raw) on Enter. */
  function attach(input, onScan) {
    if (!input) return;
    input.addEventListener('keydown', (e) => {
      if (e.key === 'GroupSeparator' || e.keyCode === 29 || (e.ctrlKey && (e.key === ']' || e.code === 'BracketRight'))) {
        e.preventDefault();
        const a = input.selectionStart, b = input.selectionEnd;
        input.value = input.value.slice(0, a) + GS + input.value.slice(b);
        input.selectionStart = input.selectionEnd = a + 1;
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (input.value.trim()) onScan(parse(input.value), input.value);
        input.select();
      }
    });
  }

  window.GS1 = { parse, attach, checkGtin };
})();
