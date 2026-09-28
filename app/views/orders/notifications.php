<?php
/**
 * Notifications — Figma W6 (20:675): open alerts as cards with their action, handled ones as a quiet list.
 * @var array $rows @var int $unread
 */
use Sofrexa\Core\Clock;
use Sofrexa\View\Ui;

$appSub = $unread ? t('notif.sub', ['n' => digits($unread)]) : t('notif.sub_none');
$sub = $appSub;
$appActions = [Ui::ibtn('check', t('notif.read_all'), ['class' => 'appbar__act', 'attrs' => ['data-post' => '/my/notifications/read', 'data-reload' => true]])];
$headActions = Ui::btn(t('notif.read_all'), ['style' => 'secondary', 'icon' => 'check', 'attrs' => ['data-post' => '/my/notifications/read', 'data-reload' => true]]);
$kinds = [
    'ready' => ['success', 'bell'],
    'qr' => ['attention', 'qr'],
    'bill' => ['warning', 'receipt'],
    'call' => ['info', 'hand'],
    'served' => ['success', 'check-circle'],
    'online' => ['attention', 'globe'],
    'void' => ['danger', 'x-circle'],
    'printer' => ['danger', 'printer'],
];
$ago = static function (int $at): string {
    $m = intdiv(Clock::ms() - $at, 60_000);
    return $m < 1 ? t('dur.now') : dur($at);
};
$open = array_filter($rows, static fn(array $n): bool => !$n['done_at']);
$done = array_filter($rows, static fn(array $n): bool => (bool) $n['done_at']);
?>
<div class="notifs">
  <?php if (!$rows): ?><div class="empty"><?= icon('bell', 24) ?><div><?= e(t('notif.empty')) ?></div></div><?php endif ?>
  <?php foreach ($open as $n):
      $b = json_arr($n['body']);
      [$tone, $ic] = $kinds[$n['kind']] ?? ['info', 'bell'];
      // the table the bill is at now: a bill moved since takes its alerts with it
      $where = (string) ($n['place']['where'] ?? $b['where'] ?? $n['title'] ?? '');
      $head = t('notif.k.' . $n['kind'], ['where' => $where, 'what' => (string) ($b['what'] ?? '')]);
      $subText = match ($n['kind']) {
          'bill' => !empty($b['qr']) ? t('notif.bill_qr') : t('notif.bill_by', ['name' => first_name((string) ($b['by'] ?? ''))]),
          'call' => t('notif.call_qr'),
          'void' => t('notif.void_ask', ['name' => first_name((string) ($b['by'] ?? ''))]),
          'printer' => t('notif.printer_sub', ['n' => digits((int) ($b['n'] ?? 0))]),
          default => (string) ($b['text'] ?? $b['what'] ?? ''),
      };
      $base = '/my/notifications/' . $n['id'];
      $action = match ($n['kind']) {
          // the plates listed on this card, and no others: one added after the page was drawn keeps its alert
          'ready' => Ui::btn(t('notif.got'), ['size' => 's', 'attrs' => ['data-post' => $base . '/done', 'data-body' => json_encode(['lines' => array_values((array) ($b['lines'] ?? []))])]]),
          'qr' => $n['ref_id'] ? Ui::btn(t('notif.review'), ['size' => 's', 'attrs' => ['data-load-sheet' => '/qr/orders/' . $n['ref_id']]]) : Ui::btn(t('notif.review'), ['size' => 's', 'href' => '/tables']),
          'bill' => Ui::btn(t('notif.prebill'), ['style' => 'secondary', 'size' => 's', 'attrs' => ['data-post' => $base . '/prebill']]),
          'call' => Ui::btn(t('notif.going'), ['style' => 'secondary', 'size' => 's', 'attrs' => ['data-post' => $base . '/done']]),
          'online' => $n['ref_id'] ? Ui::btn(t('notif.review'), ['size' => 's', 'attrs' => ['data-load-sheet' => '/delivery/' . $n['ref_id'] . '/sheet']]) : Ui::btn(t('notif.review'), ['size' => 's', 'href' => '/delivery']),
          // W6b: the till asked the kitchen — made → waste (it can still go to another bill); "not made" gets a row of its own
          'void' => Ui::btn(t('notif.void_waste'), ['size' => 's', 'attrs' => ['data-post' => $base . '/waste']]),
          'printer' => Ui::btn(t('notif.retry'), ['style' => 'secondary', 'size' => 's', 'attrs' => ['data-post' => $base . '/retry']]),
          default => Ui::btn(t('notif.open'), ['size' => 's', 'attrs' => ['data-post' => $base . '/done']]),
      };
      $later = Ui::btn(t('notif.later'), ['style' => 'ghost', 'size' => 's', 'attrs' => ['data-post' => $base . '/later']]); ?>
    <article class="ncard<?= $n['read_at'] ? '' : ' is-new' ?>">
      <div class="ncard__row">
        <span class="ncard__ic ncard__ic--<?= $tone ?>"><?= icon($ic, 22) ?></span>
        <div class="grow col gap-2"><span class="t-label-l"><?= e($head) ?></span><?php if ($subText !== ''): ?><span class="t-body-s c-muted<?= $n['kind'] === 'printer' ? '' : ' ellipsis' ?>"><?= e($subText) ?></span><?php endif ?></div>
        <span class="t-label-s c-muted nowrap"><?= e($ago((int) $n['at'])) ?></span>
      </div>
      <?php if ($n['kind'] === 'void'): ?>
        <div class="ncard__acts ncard__acts--stack"><?= Ui::btn(t('notif.void_back'), ['style' => 'secondary', 'size' => 's', 'block' => true, 'attrs' => ['data-post' => $base . '/returned']]) ?>
          <div class="row"><?= $later . $action ?></div></div>
      <?php else: ?>
        <div class="ncard__acts"><?= $later . $action ?></div>
      <?php endif ?>
    </article>
  <?php endforeach ?>
  <?php foreach ($done as $n):
      $b = json_arr($n['body']); ?>
    <div class="nearlier"><?= icon('check-circle', 20) ?><span class="t-body-s c-muted"><?= e(t('notif.k.' . $n['kind'], ['where' => (string) ($n['place']['where'] ?? $b['where'] ?? $n['title'] ?? ''), 'what' => (string) ($b['what'] ?? '')]) . ' · ' . digits(date('H:i', intdiv((int) $n['done_at'], 1000)))) ?></span></div>
  <?php endforeach ?>
</div>
