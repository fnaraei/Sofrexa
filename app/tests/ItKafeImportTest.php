<?php
/** ItKafe import: the pure mapping helpers (no SQL Server needed). */
declare(strict_types=1);

use Sofrexa\Core\Uuid;
use Sofrexa\Migrate\ItKafeImport as I;

return [
    'itkafe: deterministic time-ordered ids' => function (): void {
        $a = I::uuid(1_732_470_000_000, 'itk:order:167848');
        same($a, I::uuid(1_732_470_000_000, 'itk:order:167848'), 'same key, same id');
        check(Uuid::isValid($a) && $a[14] === '7' && in_array($a[19], ['8', '9', 'a', 'b'], true), 'UUID v7 layout');
        check($a !== I::uuid(1_732_470_000_000, 'itk:order:167849'), 'another key, another id');
        check(I::uuid(1_732_470_000_000, 'itk:x') < I::uuid(1_732_470_000_001, 'itk:x'), 'ids sort by time');
    },

    'itkafe: day numbers are yyyymmdd' => function (): void {
        same('2019-04-30', I::day(20190430));
        same('2025-11-24', I::day(20251124));
        same(null, I::day(0));
    },

    'itkafe: card key is a phone or a card code' => function (): void {
        same([null, '2000000012345'], I::cardOrPhone('2000000012345'), 'EAN card');
        same(['05331234567', null], I::cardOrPhone(' 05331234567 '), 'Turkish mobile');
        same(['5331234567', null], I::cardOrPhone('5331234567'), 'without the 0');
        same(['+447700900123', null], I::cardOrPhone('+447700900123'), 'foreign');
        same([null, '123'], I::cardOrPhone('123'), 'other code');
        same([null, null], I::cardOrPhone(''), 'empty');
    },

    'itkafe: roles, ledger kinds and methods' => function (): void {
        same('manager', I::role(3, true, false));
        same('cashier', I::role(2, false, true));
        same('waiter', I::role(4, false, false), 'barman serves');
        same('chef', I::role(5, false, false));
        same('charge', I::ledgerKind(1, -90000));
        same('bonus', I::ledgerKind(1, 1235));
        same('payment', I::ledgerKind(0, 50000));
        same('adjust', I::ledgerKind(0, -1000));
        same('card', I::ledgerMethod('Card'));
        same('transfer', I::ledgerMethod('Starling'));
        same('cash', I::ledgerMethod('paid cash'));
    },

    'itkafe: payroll rows keep accruals out of what was paid' => function (): void {
        $r = I::payrollRow(1, 45000, 'Robot');
        same(['accrual', 45000, 0], [$r['kind'], $r['base'], $r['total']], 'shift accrual');
        $r = I::payrollRow(2, -100000, 'avans');
        same(['advance', 100000, null], [$r['kind'], $r['total'], $r['method']], 'advance');
        $r = I::payrollRow(2, -20000, 'Pinalty');
        same(['accrual', 20000, 0], [$r['kind'], $r['deduction'], $r['total']], 'penalty');
        $r = I::payrollRow(3, -500000, 'Salary June');
        same(['payment', 500000, 'cash'], [$r['kind'], $r['total'], $r['method']], 'paid at the cash desk');
        $r = I::payrollRow(2, 3000, 'correction');
        same(['accrual', 3000, 0], [$r['kind'], $r['bonus'], $r['total']], 'correction');
    },
];
