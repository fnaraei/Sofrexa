<?php
/** CodeInput (Figma O2/O8): one numeric input drawn as six cells; paste fills all, the next empty cell is gold. */
?>
<div class="ocode" data-code>
  <input class="ocode__input" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]*" aria-label="<?= e(t('on.code')) ?>" autofocus>
  <?php for ($i = 0; $i < 6; $i++): ?><span class="ocode__cell t-heading-l num<?= $i === 0 ? ' is-active' : '' ?>"></span><?php endfor ?>
</div>
