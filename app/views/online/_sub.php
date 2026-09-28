<?php
/** PublicSubHeader on phones (Figma O2, O3, O4, O7, O8): back, title (Display/M) and a gold subtitle. @var string $back @var string $title @var string $sub */
use Sofrexa\View\Ui;
?>
<header class="ghead ghead--back only-mobile">
  <?= Ui::ibtn('arrow-left', t('on.back'), ['href' => $back]) ?>
  <div class="ghead__txt"><span class="ghead__name"><?= e($title) ?></span><span class="ghead__sub"><?= e($sub) ?></span></div>
</header>
