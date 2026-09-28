<?php
/**
 * Sync status. Desktop: SE1 card 3 line (SyncStatus + "Web kopyası … · son eşitleme … · kuyrukta …").
 * Phone: SE4 card 2 (SyncStatus and four key/value rows).
 */
use Sofrexa\Core\App;
use Sofrexa\Modules\Backup\Backup;
use Sofrexa\Sync\Status;
use Sofrexa\View\Ui;

$st = Status::get();
$host = parse_url((string) (App::isPc() ? App::config('sync.remote_url') : App::config('base_url')), PHP_URL_HOST) ?: '—';
$ago = static function (?int $ms): string {
    if (!$ms) {
        return t('set.sync.never');
    }
    $s = max(0, intdiv(\Sofrexa\Core\Clock::ms() - $ms, 1000));
    return $s < 60 ? t('set.sync.ago_s', ['n' => digits($s)]) : ($s < 3600 ? t('set.sync.ago_m', ['n' => digits(intdiv($s, 60))]) : t('set.sync.ago_h', ['n' => digits(intdiv($s, 3600))]));
};
$last = Backup::list()[0] ?? null;
$lastLabel = $last ? when_label($last['at']) . ' · ' . Backup::size($last['size']) : t('set.list.no_backup');
?>
<section class="section only-desktop">
  <div class="section__head"><h2 class="section__title"><?= e(t('set.sync.title')) ?></h2></div>
  <div class="row gap-10">
    <?= Ui::sync($st['state']) ?>
    <span class="t-body-s c-secondary grow"><?= e($st['configured'] ? t('set.sync.line', ['host' => $host, 'ago' => $ago($st['last_ok']), 'n' => digits($st['pending'])]) : t('set.sync.none')) ?></span>
  </div>
</section>
<?php if ($mobile ?? true): ?>
<div class="card only-mobile" style="padding:14px;gap:8px">
  <?= Ui::sync($st['state']) ?>
  <div class="kvlist">
    <div class="kv"><span><?= e(t('set.sync.web')) ?></span><span><?= e($st['configured'] ? $host : '—') ?></span></div>
    <div class="kv"><span><?= e(t('set.sync.last')) ?></span><span><?= e($st['configured'] ? $ago($st['last_ok']) : '—') ?></span></div>
    <div class="kv"><span><?= e(t('set.sync.queue')) ?></span><span><?= e(t('set.sync.queue_n', ['n' => digits($st['pending'])])) ?></span></div>
    <div class="kv"><span><?= e(t('set.sync.last_backup')) ?></span><span><?= e($lastLabel) ?></span></div>
  </div>
</div>
<?php endif ?>
