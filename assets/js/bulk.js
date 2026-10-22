// assets/js/bulk.js — select several table rows and apply one action to all of them.
// Markup: <form data-bulk> containing a .bulk-bar (hidden until something is selected, with a .bulk-count),
// a header .bulk-all checkbox, and a .bulk-check checkbox per row (name="ids[]").
// Buttons in the bar submit with name="bulk"; data-confirm asks first ({n} = number selected).
document.querySelectorAll('form[data-bulk]').forEach((form) => {
  const checks = () => [...form.querySelectorAll('.bulk-check')];
  const all = form.querySelector('.bulk-all');
  const bar = form.querySelector('.bulk-bar');
  const count = form.querySelector('.bulk-count');
  let last = null;

  const sync = () => {
    const boxes = checks(), n = boxes.filter((c) => c.checked).length;
    bar.hidden = n === 0;
    count.textContent = n + ' selected';
    if (all) { all.checked = n > 0 && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; }
    boxes.forEach((c) => c.closest('tr').classList.toggle('row-selected', c.checked));
  };

  form.addEventListener('click', (e) => {
    const box = e.target.closest('.bulk-check');
    if (!box) return;
    // Shift-click selects every row between the last click and this one
    if (e.shiftKey && last && last !== box) {
      const boxes = checks(), a = boxes.indexOf(last), b = boxes.indexOf(box);
      boxes.slice(Math.min(a, b), Math.max(a, b) + 1).forEach((c) => { c.checked = box.checked; });
    }
    last = box;
    sync();
  });
  all?.addEventListener('change', () => { checks().forEach((c) => { c.checked = all.checked; }); sync(); });
  form.querySelector('.bulk-clear')?.addEventListener('click', () => { checks().forEach((c) => { c.checked = false; }); sync(); });
  form.addEventListener('submit', (e) => {
    const b = e.submitter;
    const n = checks().filter((c) => c.checked).length;
    if (b?.dataset.confirm && !confirm(b.dataset.confirm.replace('{n}', n))) e.preventDefault();
  });
  sync();
});
