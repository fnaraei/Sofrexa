<?php
/**
 * Promotion editor on phones — Figma PR4 (120:1281): full screen like M4, bottom bar with the live preview and "Kaydet";
 * "Sil" in the AppBar. Desktop normally uses the PR2 dialog; opened directly, this page shows the same single column.
 * @var array $p @var array $categories @var array $items @var ?string $state
 */
use Sofrexa\Modules\Menu\Promotions;
use Sofrexa\View\Ui;

$appSub = $p['id'] ? tn($p['names']) . ' · ' . mb_strtolower(t('promo.st_' . ($state === 'ended' ? 'off' : $state)), 'UTF-8') : t('promo.title');
$sub = $p['id'] ? Promotions::summary($p) : t('promo.sub');
$bodyClass = 'page-promo-edit';
if ($p['id']) {
    $appActions = [Ui::ibtn('trash', t('promo.delete'), ['class' => 'appbar__act', 'attrs' => ['data-post' => '/menu/promotions/' . $p['id'] . '/delete', 'data-confirm' => t('promo.delete_confirm')]])];
    $headActions = Ui::btn(t('promo.delete'), ['style' => 'danger', 'icon' => 'trash', 'attrs' => ['data-post' => '/menu/promotions/' . $p['id'] . '/delete', 'data-confirm' => t('promo.delete_confirm')]]);
}
$headActions = ($headActions ?? '') . Ui::btn(t('ui.save'), ['type' => 'submit', 'icon' => 'check', 'attrs' => ['form' => 'promo-form', 'data-promo-save' => true]]);
$bottom = '<div class="promo__preview promo__preview--bar" data-promo-preview><div class="grow col gap-2"><span class="t-label-m" data-preview-title>…</span><span class="t-body-s c-muted" data-preview-sub></span></div></div>'
    . Ui::btn(t('ui.save'), ['type' => 'submit', 'size' => 'l', 'icon' => 'check', 'attrs' => ['form' => 'promo-form', 'data-promo-save' => true]]);
?>
<form id="promo-form" class="promo__page" method="post" action="/menu/promotions/save" data-ajax data-promo-form>
  <?= \Sofrexa\Core\View::partial('menu/_promo_form', ['p' => $p, 'categories' => $categories, 'items' => $items, 'mode' => 'phone', 'uid' => 'pm']) ?>
</form>
