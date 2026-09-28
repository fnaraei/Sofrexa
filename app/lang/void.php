<?php
/** Cancelled dishes (the till's kitchen question, waste, giving a cooked dish to another bill or a staff member), printer alerts, sync. */
return [
    // notifications
    'notif.k.void' => ['{where} · {what} iptal edildi', '{where} · {what} cancelled', '{where} · {what} لغو شد', '{where} · {what} отменено'],
    'notif.void_ask' => ['Mutfağa sorun: hazırlandı mı? · {name}', 'Ask the kitchen: was it made? · {name}', 'از آشپزخانه بپرسید: آماده شده بود؟ · {name}', 'Спросите кухню: уже приготовлено? · {name}'],
    'notif.void_back' => ['Hazırlanmadı · stoka dön', 'Not made · back to stock', 'آماده نشده · برگشت به انبار', 'Не готовили · на склад'],
    'notif.void_waste' => ['Hazırlandı · zayi', 'Made · waste', 'آماده شده · دورریز', 'Готово · списать'],
    'notif.k.printer' => ['{where} yazıcısı yazdırmıyor', 'The {where} printer is not printing', 'چاپگر {where} چاپ نمی‌کند', 'Принтер «{where}» не печатает'],
    'notif.printer_sub' => ['{n} fiş bekliyor · yazıcı açılınca kendiliğinden basılır', '{n} tickets waiting · they print by themselves once it is back', '{n} فیش منتظر است · با روشن شدن چاپگر خودکار چاپ می‌شود', 'Ждут чеков: {n} · напечатаются сами, когда принтер заработает'],
    'notif.printer_retry' => ['Şimdi tekrar deneniyor', 'Trying again now', 'همین حالا دوباره امتحان می‌شود', 'Пробуем снова'],
    'notif.retry' => ['Şimdi dene', 'Try now', 'همین حالا امتحان کن', 'Повторить'],
    // the till's answer and what happens to the dish
    'void.returned' => ['Malzemeler stoka döndü', 'Ingredients back in stock', 'مواد به انبار برگشت', 'Продукты вернулись на склад'],
    'void.wasted' => ['Zayi olarak kaydedildi', 'Booked as waste', 'به‌عنوان دورریز ثبت شد', 'Списано'],
    'void.err_done' => ['Bu iptal için karar zaten verildi', 'This cancellation is already settled', 'برای این لغو قبلاً تصمیم گرفته شده', 'По этой отмене уже решено'],
    // sync
    'sync.err_incomplete' => ['Sipariş henüz tam gelmedi, birkaç saniye sonra deneyin', 'The order is still arriving, try again in a few seconds', 'سفارش هنوز کامل نرسیده؛ چند ثانیه بعد امتحان کنید', 'Заказ ещё загружается, повторите через несколько секунд'],
];
