<?php
// includes/theme_head.php — put inside <head> before the stylesheets load.
// Applies the saved Day / Night choice before the page paints, so there's no white flash.
// Choice lives in localStorage 'crm-theme': 'light' | 'dark' | 'auto' (default: light).
?>
<script>
(function () {
  var pref = 'light';
  try { pref = localStorage.getItem('crm-theme') || 'light'; } catch (e) {}
  var dark = pref === 'dark' || (pref === 'auto' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
  var r = document.documentElement;
  r.setAttribute('data-theme', dark ? 'dark' : 'light');
  r.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
  r.setAttribute('data-theme-pref', pref);
})();
</script>
