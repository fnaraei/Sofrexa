<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Orders;

use Sofrexa\Core\{App, Db, I18n, Money, Settings};
use Sofrexa\Print\{EscPos, Printer, Spooler};

/**
 * 80 mm tickets from the Figma "12 Fişler" page: P1 receipt, P2 pre-bill, P3 kitchen, P4 bar, P5 courier,
 * P6 end of day (Z). Always Turkish. Thermal code pages have no "₺", so amounts print with "TL".
 */
final class Tickets
{
    // ------------------------------------------------------------ P3 / P4 kitchen and bar
    /** One ticket per station for the lines just sent. */
    public static function kitchen(string $orderId, array $lineIds, int $round): void
    {
        $o = Orders::get($orderId);
        $byStation = [];
        foreach ($o['lines'] as $l) {
            if (in_array($l['id'], $lineIds, true)) {
                $byStation[$l['station']][] = $l;
            }
        }
        foreach ($byStation as $station => $lines) {
            $printer = $station === 'bar' ? 'bar' : 'kitchen';
            $p = self::paper($printer);
            $p->invert()->bold()->size(2, 2)->text(' ' . ($station === 'bar' ? 'BAR' : 'MUTFAK') . ($round > 1 && $station !== 'bar' ? str_repeat(' ', max(1, intdiv($p->cols, 2) - 7 - 12)) . 'EK SİPARİŞ' : ''))->size()->invert(false);
            if (in_array($o['channel'], ['table', 'qr'], true)) {
                $p->size(3, 3)->text('MASA ' . $o['table_no'])->size();
            } else {
                $p->size(2, 2)->text(self::upper(Orders::where($o)))->size();
            }
            $p->pair(self::who($o), date('H:i'));
            if ($station !== 'bar') {
                $p->bold(false)->pair('Sipariş #' . self::no($o) . ($round > 1 ? ' · ' . $round . '. tur' : ''), $station === 'bar' ? 'Bar' : 'Mutfak');
            }
            $p->bold(false)->hr();
            $notes = [];
            foreach ($lines as $l) {
                self::bigLine($p, $l);
                if ($l['note']) {
                    $notes[] = $l['note'] . ' (' . mb_strtolower($l['name']) . ')';
                }
            }
            foreach ($notes as $n) {
                $p->invert()->bold()->size(1, 2)->text(' NOT: ' . self::upper($n))->size()->invert(false);
            }
            $p->bold(false)->hr();
            $p->text($station === 'bar' ? 'Kasa/bar yazıcısından çıkar' : Orders::where($o) . ' · ' . count($lines) . ' ürün');
            $p->feed(2)->cut()->beep(2);
            Spooler::print($printer, $station, $p->bytes(), $orderId);
        }
    }

    /** Cancel ticket for the station of a voided line. */
    public static function void(string $orderId, string $lineId, float $qty, string $reason): void
    {
        $o = Orders::get($orderId);
        $l = Orders::line($lineId);
        $printer = $l['station'] === 'bar' ? 'bar' : 'kitchen';
        $p = self::paper($printer);
        $p->invert()->bold()->size(2, 2)->text(' İPTAL')->size()->invert(false);
        $p->size(2, 2)->text(self::upper(Orders::where($o)))->size()->pair(self::who($o), date('H:i'))->hr();
        $p->bold()->size(2, 2)->text(Orders::qtyText($qty) . '× ' . self::upper($l['name']))->size()->bold(false);
        $p->text('Sebep: ' . $reason)->feed(2)->cut()->beep(3);
        Spooler::print($printer, 'void', $p->bytes(), $orderId);
    }

    // ------------------------------------------------------------ P2 pre-bill
    public static function preBill(string $orderId): void
    {
        $o = Orders::get($orderId);
        $p = self::paper('cashier');
        self::header($p);
        $p->hr()->invert()->bold()->size(1, 2)->align('c')->text(' ÖN HESAP · ÖDENMEDİ ')->size()->invert(false)->bold(false)->align('l');
        $p->pair(self::where($o), '#' . self::no($o));
        $p->pair(date('d.m.Y H:i'), $o['waiter_name'] ? 'Garson: ' . self::first($o['waiter_name']) : '');
        $p->hr();
        self::itemLines($p, $o);
        $p->hr();
        $p->bold()->size(1, 2)->pair('TOPLAM', self::tl((int) $o['total']), 1)->size()->bold(false);
        if ((int) $o['discount'] > 0) {
            $p->pair('İçinde indirim', '-' . self::tl((int) $o['discount']));
        }
        $rates = Rates::latest();
        if ($rates) {
            $fx = [];
            foreach (['GBP' => '£', 'USD' => '$', 'EUR' => '€'] as $c => $s) {
                if (!empty($rates[$c])) {
                    $fx[] = $s . ' ' . number_format((int) $o['total'] / 100 / $rates[$c], 2, ',', '.');
                }
            }
            $p->pair(implode(' · ', $fx), '(bugünkü kur)');
        }
        if ((int) $o['guests'] > 1) {
            $p->pair('Kişi başı (' . $o['guests'] . ')', self::tl((int) round((int) $o['total'] / (int) $o['guests']), true));
        }
        $p->hr()->align('c')->text('Ödeme için garsonumuzu çağırın. Nakit TL, £, $, € veya kart.');
        self::powered($p);
        $p->feed(2)->cut();
        Spooler::print('cashier', 'prebill', $p->bytes(), $orderId);
    }

    // ------------------------------------------------------------ P1 receipt
    public static function receipt(string $orderId): void
    {
        $o = Orders::get($orderId);
        $p = self::paper('cashier');
        self::header($p);
        $p->hr();
        $p->pair(self::where($o), '#' . self::no($o));
        $p->pair(date('d.m.Y H:i', intdiv((int) ($o['closed_at'] ?: \Sofrexa\Core\Clock::ms()), 1000)), $o['waiter_name'] ? 'Garson: ' . self::first($o['waiter_name']) : '');
        $p->hr();
        self::itemLines($p, $o);
        $p->hr();
        if ((int) $o['discount'] > 0) {
            $p->pair('Ara toplam', self::tl((int) $o['subtotal']));
            foreach ($o['discounts'] as $d) {
                if ((int) $d['amount'] !== 0) {
                    $p->pair('İndirim' . ($d['kind'] === 'pct' ? ' %' . I18n::num((float) $d['value'], 0, 'tr') : '') . ($d['reason'] ? ' (' . $d['reason'] . ')' : ''), '-' . self::tl((int) $d['amount']));
                }
            }
        }
        $p->bold()->size(1, 2)->pair('TOPLAM', self::tl((int) $o['total']))->size()->bold(false);
        foreach (Orders::vat($orderId) as $rate => [$gross, $vat]) {
            if ($vat > 0) {
                $p->pair('İçindeki KDV %' . I18n::num((float) $rate, 0, 'tr'), self::tl($vat));
            }
        }
        $p->hr();
        $change = 0;
        foreach ($o['payments'] as $pay) {
            $label = match ($pay['method']) {
                'card' => 'Kart (POS)',
                'account' => 'Cari hesap',
                default => $pay['currency'] === 'TRY' ? 'Nakit' : 'Nakit ' . Money::symbol($pay['currency']) . ' ' . number_format(abs((float) $pay['amount_fx']), 2, ',', '.') . ' (1' . Money::symbol($pay['currency']) . ' = ' . number_format((float) $pay['rate'], 2, ',', '.') . ')',
            };
            $p->pair($label, self::tl((int) $pay['amount'] + (int) $pay['change_given']));
            $change += (int) $pay['change_given'];
        }
        if ($change > 0) {
            $p->bold()->pair('Para üstü (TL)', self::tl($change))->bold(false);
        }
        $p->hr()->align('c');
        if (!empty($o['receipt_note'])) {
            $p->text((string) $o['receipt_note']);
        }
        $site = (string) Settings::get('profile.website', '');
        if ($site !== '') {
            $p->qr(rtrim($site, '/') . '/menu', 5)->text('Menü ve görüşleriniz için okutun');
        }
        $p->bold()->text((string) Settings::get('receipt.footer', ''))->bold(false);
        self::powered($p);
        $p->feed(2)->cut();
        Spooler::print('cashier', 'receipt', $p->bytes(), $orderId);
    }

    // ------------------------------------------------------------ P5 courier slip
    public static function courier(string $orderId): void
    {
        $o = Orders::get($orderId);
        $d = $o['delivery'];
        $p = self::paper('courier');
        $courier = !empty($d['courier_id']) ? (string) Db::value('SELECT name FROM users WHERE id = ?', [$d['courier_id']]) : '';
        $p->invert()->bold()->size(1, 2)->pair(' TESLİMAT #' . self::no($o), $courier !== '' ? 'Kurye: ' . self::first($courier) . ' ' : '')->size()->invert(false);
        $p->bold()->size(1, 2)->text((string) ($o['customer_name'] ?? $o['label'] ?? ''))->text((string) ($d['phone'] ?? $o['customer_phone'] ?? ''))->size()->text((string) ($d['address'] ?? ''))->bold(false);
        if (!empty($d['note'])) {
            $p->text('Not: ' . $d['note']);
        }
        $p->hr();
        self::itemLines($p, $o);
        $p->hr()->bold()->size(1, 2)->pair('TAHSİL EDİLECEK', self::tl(max(0, (int) $o['total'] - (int) $o['paid'])))->size()->bold(false);
        $hint = $d['pay_hint'] ?? 'cash';
        $cashGiven = (int) ($d['cash_given'] ?? 0);
        $line = $hint === 'card' ? 'KAPIDA KART (POS)' : 'KAPIDA NAKİT' . ($cashGiven > (int) $o['total'] ? ' · ' . self::tl($cashGiven) . '’den ' . self::tl($cashGiven - (int) $o['total']) . ' üstü' : '');
        $p->invert()->bold()->text(' ' . $line . ' ')->invert(false)->bold(false);
        $p->text('Çıkış ' . date('H:i') . (!empty($d['eta']) ? ' · tahmini varış ' . $d['eta'] : ''));
        $fee = (int) Settings::get('online.delivery_fee', 0);
        $p->pair('Teslimat ücreti', $fee ? self::tl($fee) : 'Ücretsiz');
        $p->feed(2)->cut();
        Spooler::print('courier', 'courier', $p->bytes(), $orderId);
    }

    // ------------------------------------------------------------ P6 end of day (and the interim X report)
    public static function xReport(string $shiftId): void
    {
        self::zReport($shiftId, true);
    }

    public static function zReport(string $shiftId, bool $interim = false): void
    {
        $s = Db::row('SELECT s.*, u.name FROM shifts s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ?', [$shiftId]);
        $sum = Shifts::summary($shiftId);
        $counted = json_arr($s['counted']);
        $p = self::paper('cashier');
        self::header($p);
        $p->hr()->invert()->bold()->size(1, 2)->align('c')->text($interim ? ' ARA RAPOR · X ' : ' GÜN SONU · Z #' . str_pad((string) $s['z_no'], 4, '0', STR_PAD_LEFT) . ' ')->size()->invert(false)->bold(false)->align('l');
        $p->pair(date('d.m.Y', intdiv((int) $s['opened_at'], 1000)), date('H:i', intdiv((int) $s['opened_at'], 1000)) . '-' . date('H:i', intdiv((int) ($s['closed_at'] ?: \Sofrexa\Core\Clock::ms()), 1000)));
        $p->pair('Kapatan', self::first((string) (\Sofrexa\Core\Auth::user()['name'] ?? $s['name'])));
        $p->hr();
        $paidIn = "SELECT order_id FROM payments WHERE shift_id = ? AND order_id IS NOT NULL";
        $guests = (int) Db::value("SELECT COALESCE(SUM(guests), 0) FROM orders WHERE status = 'paid' AND id IN ($paidIn)", [$shiftId]);
        $gross = (int) $sum['orders']['total'] + (int) $sum['orders']['discount'];
        $p->pair('Hesap sayısı', (string) $sum['orders']['n'])->pair('Misafir', (string) $guests)->pair('Brüt satış', self::tl($gross))->pair('İndirim', '-' . self::tl((int) $sum['orders']['discount']));
        $p->bold()->pair('CİRO (KDV dahil)', self::tl((int) $sum['orders']['total']))->bold(false);
        foreach (Db::rows("SELECT l.vat_rate, SUM(ROUND(l.qty * (l.unit_price + l.mods_price) * (1.0 - CAST(o.discount AS REAL) / MAX(o.subtotal, 1)))) AS gross
            FROM order_items l JOIN orders o ON o.id = l.order_id WHERE o.status = 'paid' AND o.id IN ($paidIn) AND l.status <> 'void' AND l.deleted = 0 GROUP BY l.vat_rate", [$shiftId]) as $v) {
            if ((float) $v['vat_rate'] > 0) {
                $p->pair('KDV %' . I18n::num((float) $v['vat_rate'], 0, 'tr'), self::tl(Money::vatOf((int) $v['gross'], (float) $v['vat_rate'])));
            }
        }
        $p->pair('İptal (' . $sum['voids']['n'] . ' ürün)', self::tl((int) $sum['voids']['amount']));
        $p->hr();
        $cashTry = (int) Db::value("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE shift_id = ? AND method = 'cash' AND currency = 'TRY'", [$shiftId]);
        $p->pair('Nakit TL', self::tl($cashTry));
        foreach (Db::rows("SELECT currency, SUM(amount_fx) AS fx, SUM(amount + change_given) AS try, MAX(rate) AS rate FROM payments WHERE shift_id = ? AND method = 'cash' AND currency <> 'TRY' GROUP BY currency", [$shiftId]) as $f) {
            $p->pair('Nakit ' . Money::symbol($f['currency']) . ' ' . I18n::num((float) $f['fx'], 0, 'tr') . ' (' . number_format((float) $f['rate'], 2, ',', '.') . ')', self::tl((int) $f['try']));
        }
        $p->pair('Kart (POS)', self::tl((int) ($sum['methods']['card'] ?? 0)))->pair('Cari hesap', self::tl((int) ($sum['methods']['account'] ?? 0)));
        $p->hr();
        $p->pair('Açılış kasası', self::tl((int) $s['opening_cash']));
        if (!empty($sum['moves']['in'])) {
            $p->pair('Kasaya giriş', self::tl((int) $sum['moves']['in']));
        }
        if (!empty($sum['moves']['out'])) {
            $p->pair('Kasadan çıkış', self::tl((int) $sum['moves']['out']));
        }
        $expected = (int) $sum['cash']['TRY'];
        $count = (int) ($counted['TRY'] ?? 0);
        $p->pair('Kasa beklenen TL', self::tl($expected));
        if (!$interim) {
            $p->pair('Kasa sayılan TL', self::tl($count))->bold()->pair('Fark', ($count - $expected < 0 ? '-' : '+') . self::tl(abs($count - $expected)))->bold(false);
        }
        foreach ($interim ? [] : $sum['cash'] as $cur => $exp) {
            if ($cur !== 'TRY' && (abs((float) $exp) > 0.001 || isset($counted[$cur]))) {
                $p->pair('Kasa ' . Money::symbol($cur) . ' beklenen / sayılan', number_format((float) $exp, 2, ',', '.') . ' / ' . number_format((float) ($counted[$cur] ?? 0), 2, ',', '.'));
            }
        }
        $note = json_arr($s['note'])['close'] ?? '';
        if ($note !== '') {
            $p->text('Açıklama: ' . $note);
        }
        $p->feed(2)->cut();
        Spooler::print('cashier', $interim ? 'x' : 'z', $p->bytes(), $shiftId);
    }

    public static function drawer(): void
    {
        Spooler::print('cashier', 'drawer', (new EscPos())->drawer()->bytes());
    }

    // ------------------------------------------------------------ building blocks

    private static function paper(string $printer): EscPos
    {
        return new EscPos((int) Printer::config($printer)['width']);
    }

    /** Restaurant block: logo (when enabled) or the name in large letters, tagline, address, phone · website. */
    private static function header(EscPos $p): void
    {
        $p->align('c');
        $logo = (string) Settings::get('profile.logo', '');
        $file = $logo !== '' ? App::storage('uploads') . '/' . $logo : '';
        if (Settings::get('receipt.logo', true) && $file !== '' && is_file($file) && !str_ends_with($file, '.svg')) {
            $p->image($file, $p->cols === 32 ? 256 : 320);
        } else {
            $name = mb_strtoupper((string) (Settings::get('profile.short_name') ?: Settings::get('profile.name')), 'UTF-8');
            $p->bold()->size(2, 2)->text(implode(' ', mb_str_split($name)))->size()->bold(false);
            $tag = (string) Settings::get('profile.tagline', '');
            if ($tag !== '') {
                $p->bold()->text(mb_strtoupper($tag, 'UTF-8'))->bold(false);
            }
        }
        foreach ([(string) Settings::get('profile.address', ''), implode(' · ', array_filter([(string) Settings::get('profile.phone', ''), preg_replace('#^https?://#', '', rtrim((string) Settings::get('profile.website', ''), '/'))]))] as $line) {
            if ($line !== '') {
                $p->text($line);
            }
        }
        $head = (string) Settings::get('receipt.header', '');
        if ($head !== '') {
            $p->text($head);
        }
        $p->align('l');
    }

    /** "2×  Adana Kebap        1.740" with options under the name. */
    private static function itemLines(EscPos $p, array $o): void
    {
        $w = $p->cols;
        foreach ($o['lines'] as $l) {
            if ($l['status'] === 'void') {
                continue;
            }
            $qty = Orders::qtyText((float) $l['qty']) . '× ';
            $total = I18n::num(round((float) $l['qty'] * ((int) $l['unit_price'] + (int) $l['mods_price']) / 100, 2), fmod((float) $l['qty'] * ((int) $l['unit_price'] + (int) $l['mods_price']), 100) ? 2 : 0, 'tr');
            $p->cols([$qty . $l['name'], $total], [$w - 10, -10]);
            $mods = array_column(json_arr($l['mods']), 'name');
            if ($l['note']) {
                $mods[] = $l['note'];
            }
            if ($mods) {
                $p->text(str_repeat(' ', mb_strlen($qty) + 2) . implode(' · ', $mods));
            }
        }
    }

    /** Kitchen/bar line: large quantity and name, options one per line with "— ". */
    private static function bigLine(EscPos $p, array $l): void
    {
        $p->bold()->size(2, 2)->text(Orders::qtyText((float) $l['qty']) . '× ' . self::upper($l['name']))->size();
        foreach (json_arr($l['mods']) as $m) {
            $p->size(1, 2)->text('   — ' . $m['name'])->size();
        }
        $p->bold(false);
    }

    private static function powered(EscPos $p): void
    {
        if (Settings::get('receipt.powered_by', true)) {
            $p->align('c')->text('powered by SOFREXA')->align('l');
        }
    }

    private static function who(array $o): string
    {
        $area = $o['area_names'] ? (json_arr($o['area_names'])['tr'] ?? $o['area_name']) : ($o['area_name'] ?? null);
        $parts = array_filter([$area, $o['channel'] === 'qr' ? 'QR' . ($o['approved_by'] ? ' (' . self::first((string) Db::value('SELECT name FROM users WHERE id = ?', [$o['approved_by']])) . ' onayladı)' : '') : null,
            (int) $o['guests'] > 0 ? $o['guests'] . ' kişi' : null, $o['channel'] !== 'qr' && $o['waiter_name'] ? self::first($o['waiter_name']) : null, $o['label'] ?: null]);
        return implode(' · ', $parts);
    }

    private static function where(array $o): string
    {
        if (in_array($o['channel'], ['table', 'qr'], true)) {
            $area = $o['area_names'] ? (json_arr($o['area_names'])['tr'] ?? $o['area_name']) : $o['area_name'];
            return 'Masa ' . $o['table_no'] . ($area ? ' · ' . $area : '') . ((int) $o['guests'] > 0 ? ' · ' . $o['guests'] . ' kişi' : '');
        }
        return Orders::where($o);
    }

    private static function no(array $o): string
    {
        return str_pad((string) $o['no'], 4, '0', STR_PAD_LEFT);
    }

    private static function tl(int $kurus, bool $decimals = false): string
    {
        return I18n::num($kurus / 100, $decimals || $kurus % 100 ? 2 : 0, 'tr') . ' TL';
    }

    private static function first(string $name): string
    {
        return explode(' ', trim($name))[0];
    }

    /** Turkish upper case (i → İ, ı → I). */
    public static function upper(string $s): string
    {
        return mb_strtoupper(strtr($s, ['i' => 'İ', 'ı' => 'I']), 'UTF-8');
    }
}
