  </main>
</div>
</div>
<div class="toast-stack" id="toastStack"></div>
<script src="<?= e(url('assets/js/app.js?v=' . ASSET_VERSION)) ?>"></script>
<?php if (!empty($__inlineScript)): ?>
<script><?= $__inlineScript ?></script>
<?php endif; ?>
<?php if (!empty($__pageScripts)) foreach ($__pageScripts as $s): ?>
<script src="<?= e(url($s . '?v=' . ASSET_VERSION)) ?>"></script>
<?php endforeach; ?>
<?php require __DIR__ . '/onboarding.php'; ?>
<?php require __DIR__ . '/whatsnew.php'; ?>
</body>
</html>
