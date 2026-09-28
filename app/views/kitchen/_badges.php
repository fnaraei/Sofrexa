<?php
/** K1 header counters: open, average, late, ready. @var array $ov @var string $station */
use Sofrexa\View\Ui;

$open = $station === 'all' || $station === 'ready' ? $ov['open'] : ($ov['stations'][$station] ?? 0);
$inStation = static fn(array $list): int => count(array_filter($list, static fn(array $t): bool => in_array($station, ['all', 'ready'], true) || $t['station'] === $station));
?>
<?= Ui::badge(t('kds.b_open', ['n' => digits($open)]), 'info', true) ?>
<?= Ui::badge(t('kds.b_avg', ['n' => digits($ov['avg'])]), 'neutral', true) ?>
<?= Ui::badge(t('kds.b_late', ['n' => digits($inStation($ov['late']))]), 'danger', true) ?>
<?= Ui::badge(t('kds.b_ready', ['n' => digits($inStation($ov['ready']))]), 'success', true) ?>
