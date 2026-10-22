    </main><!-- /page-body -->
  </div><!-- /main-content -->
</div><!-- /app -->

<script src="/assets/vendor/bootstrap-5.3.2/bootstrap.bundle.min.js"></script>
<script src="/assets/vendor/jquery-3.7.1/jquery.min.js"></script>
<script src="/assets/vendor/datatables-1.13.8/jquery.dataTables.min.js"></script>
<script src="/assets/vendor/datatables-1.13.8/dataTables.bootstrap5.min.js"></script>
<script src="/assets/vendor/chartjs-4.4.2/chart.umd.min.js"></script>
<script src="/assets/js/app.js?v=7"></script>
<?php if (($GLOBALS['IDLE_MINUTES'] ?? 0) > 0): ?>
<script>
// Signed out after <?= (int)$GLOBALS['IDLE_MINUTES'] ?> minutes without activity (the server checks too); any key, click or scroll restarts the clock
(function () {
  var ms = <?= (int)$GLOBALS['IDLE_MINUTES'] ?> * 60000, t;
  function reset() { clearTimeout(t); t = setTimeout(function () { location.href = '/logout.php?idle=1'; }, ms); }
  ['keydown', 'mousedown', 'scroll', 'touchstart'].forEach(function (e) { document.addEventListener(e, reset, { passive: true }); });
  reset();
})();
</script>
<?php endif; ?>
<?php if (isset($extraJs)): ?>
<script><?= $extraJs ?></script>
<?php endif; ?>
</body>
</html>
