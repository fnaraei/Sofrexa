<?php
/**
 * "Personele yaz" — Figma IP4 (134:1987): the staff a cooked cancelled dish can be charged to, people on shift first,
 * as radio cards with their initials; the amount is deducted from their pay. @var array $v @var int $amount @var array $staff
 */
use Sofrexa\Core\{Clock, I18n};
use Sofrexa\View\Ui;

$on = \Sofrexa\Modules\Staff\Staff::onShift();
$tr = I18n::lang() === 'tr';
$groups = ['voids.on_shift' => array_filter($staff, static fn(array $u): bool => $u['on']), 'voids.others' => array_filter($staff, static fn(array $u): bool => !$u['on'])];
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/u', trim($name)) ?: [''];
    return mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : ''), 'UTF-8');
};
?>
<div class="scrim" data-dyn hidden>
  <div class="sheet" role="dialog" aria-modal="true">
    <?= Ui::sheetHead(t('voids.staff_t', ['name' => $v['name']])) ?>
    <form class="sheet__body voidsheet" method="post" action="/cashier/voids/<?= e($v['id']) ?>/staff" data-ajax data-toast="off" data-void-form>
      <?= csrf_field() ?>
      <p class="t-body-s c-muted"><?= e(t('voids.staff_help')) ?></p>
      <?= Ui::field('q', ['type' => 'search', 'icon' => 'search', 'placeholder' => t('voids.search_staff'), 'attrs' => ['data-void-search' => true]]) ?>
      <?php foreach ($groups as $title => $people): if (!$people) { continue; } ?>
        <div class="overline"><?= e(t($title)) ?></div>
        <div class="optlist">
          <?php foreach ($people as $u):
              $first = first_name($u['name']);
              $since = isset($on[$u['id']]) ? digits(Clock::fmt((int) $on[$u['id']], 'H:i')) : null;
              $subText = $since !== null ? t('voids.since', ['role' => $u['role_label'], 'time' => $tr ? I18n::trFromTime(Clock::fmt((int) $on[$u['id']], 'H:i')) : $since]) : $u['role_label']; ?>
            <label class="optrow" data-void-row="<?= e(mb_strtolower($u['name'] . ' ' . $u['role_label'], 'UTF-8')) ?>">
              <input type="radio" name="user_id" value="<?= e($u['id']) ?>" data-void-pick data-label="<?= e(t('voids.staff_btn', ['to' => $tr ? I18n::trDative($first) : $first, 'name' => $first, 'amount' => money($amount)])) ?>">
              <span class="optrow__radio"></span>
              <span class="avatar avatar--s"><?= e($initials($u['name'])) ?></span>
              <span class="grow col gap-2"><span class="t-label-l ellipsis"><?= e($u['name']) ?></span><span class="t-body-s c-muted ellipsis"><?= e($subText) ?></span></span>
            </label>
          <?php endforeach ?>
        </div>
      <?php endforeach ?>
      <div class="voidsheet__foot">
        <?= Ui::btn(t('voids.pick'), ['type' => 'submit', 'size' => 'l', 'block' => true, 'icon' => 'check', 'attrs' => ['data-void-submit' => true, 'data-empty' => t('voids.pick'), 'disabled' => true]]) ?>
      </div>
    </form>
  </div>
</div>
