<?php
declare(strict_types=1);

namespace Sofrexa\Modules\Online;

use Sofrexa\Core\{Audit, Clock, Db, I18n, Mailer, RateLimit, Settings, ValidationError};

/**
 * Online customers (Figma O1, O2, O7, O8, O9): sign-up with e-mail and password, a 6-digit code by e-mail
 * to verify the address (and to set a new password), sign-in with the e-mail as the user name.
 * An account belongs to a customer record, so the till knows the customer; a new account never takes over an
 * existing customer, even one with the same phone (the restaurant can merge them).
 */
final class Accounts
{
    public const CODE_TTL = 600_000;     // a code is valid for 10 minutes
    public const RESEND_AFTER = 60_000;  // another code after 60 seconds
    public const MAX_TRIES = 5;          // wrong codes before a new one is needed

    /** The signed-in account with its customer's name and phone, or null. */
    public static function current(): ?array
    {
        $id = $_SESSION['online'] ?? null;
        if (!is_string($id)) {
            return null;
        }
        $a = self::get($id);
        if (!$a) {
            unset($_SESSION['online']);
        }
        return $a;
    }

    public static function get(string $id): ?array
    {
        return Db::row('SELECT a.*, c.name, c.phone FROM online_accounts a JOIN customers c ON c.id = a.customer_id WHERE a.id = ? AND a.deleted = 0 AND c.deleted = 0', [$id]) ?: null;
    }

    public static function byEmail(string $email): ?array
    {
        $id = Db::value('SELECT id FROM online_accounts WHERE email = ? AND deleted = 0', [self::email($email)]);
        return $id ? self::get((string) $id) : null;
    }

    public static function email(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    /** At least 8 characters and a digit ("En az 8 karakter ve bir rakam"). */
    public static function passwordOk(string $pw): bool
    {
        return mb_strlen($pw) >= 8 && preg_match('/\d/', $pw) === 1;
    }

    /**
     * O7 / O9: a new, unverified account and its customer; the code goes out at once. Signing up again with an
     * address that was never verified replaces the earlier attempt. $in: name, email, phone, password, terms, marketing.
     */
    public static function register(array $in): array
    {
        $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
        $email = self::email((string) ($in['email'] ?? ''));
        $phone = mb_substr(trim((string) ($in['phone'] ?? '')), 0, 40);
        $pw = (string) ($in['password'] ?? '');
        $err = [];
        if ($name === '') {
            $err['name'] = I18n::t('on.err_name');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err['email'] = I18n::t('on.err_email');
        }
        if (strlen(phone_norm($phone)) < 7) {
            $err['phone'] = I18n::t('on.err_phone');
        }
        if (!self::passwordOk($pw)) {
            $err['password'] = I18n::t('on.pw_rule');
        }
        if (empty($in['terms'])) {
            $err['terms'] = I18n::t('on.err_terms');
        }
        $old = $email !== '' ? self::byEmail($email) : null;
        if ($old && $old['verified_at']) {
            $err['email'] = I18n::t('on.err_exists');
        }
        if ($err) {
            throw new ValidationError($err);
        }
        if (!RateLimit::hit('online:signup:' . \Sofrexa\Core\Net::clientIp(), 10, 3_600_000)) {
            throw new \InvalidArgumentException(I18n::t('on.err_limit'));
        }
        $now = Clock::ms();
        $marketing = !empty($in['marketing']);
        $id = Db::tx(static function () use ($old, $name, $email, $phone, $pw, $marketing, $now): string {
            $customer = ['name' => $name, 'phone' => $phone, 'phone_norm' => phone_norm($phone), 'email' => $email];
            $row = ['email' => $email, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'terms_at' => $now, 'marketing' => $marketing ? 1 : 0,
                'marketing_at' => $marketing ? $now : null, 'lang' => I18n::lang()];
            if ($old) {
                Db::save('customers', ['id' => $old['customer_id']] + $customer);
                return Db::save('online_accounts', ['id' => $old['id']] + $row);
            }
            $cid = Db::save('customers', $customer + ['created_at' => $now]);
            Audit::log('customer.create', 'online · ' . $name, 'customer', $cid, [], ['id' => null, 'name' => 'Online']);
            return Db::save('online_accounts', $row + ['customer_id' => $cid, 'created_at' => $now]);
        });
        $a = self::get($id);
        self::sendCode($a, 'verify');
        return self::get($id);
    }

    /** Sends a new 6-digit code (verify | reset). Once a minute at most. */
    public static function sendCode(array $a, string $purpose): void
    {
        $now = Clock::ms();
        if ($a['code_sent_at'] && $now - (int) $a['code_sent_at'] < self::RESEND_AFTER) {
            throw new \InvalidArgumentException(I18n::t('on.err_wait', ['s' => (string) (int) ceil((self::RESEND_AFTER - ($now - (int) $a['code_sent_at'])) / 1000)]));
        }
        $code = sprintf('%06d', random_int(0, 999_999));
        Db::save('online_accounts', ['id' => $a['id'], 'code_hash' => password_hash($code, PASSWORD_DEFAULT), 'code_purpose' => $purpose,
            'code_expires' => $now + self::CODE_TTL, 'code_tries' => 0, 'code_sent_at' => $now]);
        $lang = (string) ($a['lang'] ?: I18n::lang());
        $shop = (string) Settings::get('profile.name');
        Mailer::send((string) $a['email'], I18n::t('on.mail_code_subject', ['shop' => $shop, 'code' => $code], $lang),
            I18n::t($purpose === 'reset' ? 'on.mail_code_reset' : 'on.mail_code_verify', ['name' => first_name((string) $a['name']), 'code' => $code, 'shop' => $shop], $lang));
    }

    /** Seconds until another code can be sent (0 = now). */
    public static function resendIn(array $a): int
    {
        return $a['code_sent_at'] ? max(0, (int) ceil((self::RESEND_AFTER - (Clock::ms() - (int) $a['code_sent_at'])) / 1000)) : 0;
    }

    /** Checks a code; a wrong one counts, five wrong ones or ten minutes end it. */
    private static function checkCode(array $a, string $purpose, string $code): void
    {
        $code = preg_replace('/\D/', '', strtr($code, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'])) ?? '';
        $ok = $a['code_hash'] && $a['code_purpose'] === $purpose && (int) $a['code_expires'] > Clock::ms() && (int) $a['code_tries'] < self::MAX_TRIES
            && strlen($code) === 6 && password_verify($code, (string) $a['code_hash']);
        if (!$ok) {
            Db::save('online_accounts', ['id' => $a['id'], 'code_tries' => (int) $a['code_tries'] + 1]);
            throw new ValidationError(['code' => I18n::t('on.err_code')]);
        }
        Db::save('online_accounts', ['id' => $a['id'], 'code_hash' => null, 'code_purpose' => null, 'code_expires' => null, 'code_tries' => 0]);
    }

    /** O2: the right code opens the account and signs the customer in. */
    public static function verify(string $accountId, string $code): array
    {
        $a = self::get($accountId) ?? throw new \Sofrexa\Core\HttpError(404);
        self::checkCode($a, 'verify', $code);
        Db::save('online_accounts', ['id' => $a['id'], 'verified_at' => $a['verified_at'] ?: Clock::ms()]);
        $a = self::get($accountId);
        self::signIn($a, true);
        return $a;
    }

    /**
     * O1 / O9: e-mail and password. Returns the account; an unverified one is returned as well (the caller sends
     * the customer to O2 with a fresh code). Wrong details give one message for both, so addresses cannot be probed.
     */
    public static function login(string $email, string $pw, bool $remember): array
    {
        $email = self::email($email);
        if (!RateLimit::hit('online:login:' . \Sofrexa\Core\Net::clientIp(), 20, 900_000) || !RateLimit::hit('online:login:' . $email, 8, 900_000)) {
            throw new \InvalidArgumentException(I18n::t('on.err_limit'));
        }
        $a = self::byEmail($email);
        if (!$a || !password_verify($pw, (string) $a['password_hash'])) {
            throw new ValidationError(['password' => I18n::t('on.err_login')]);
        }
        RateLimit::clear('online:login:' . $email);
        if ($a['verified_at']) {
            self::signIn($a, $remember);
        }
        return $a;
    }

    public static function signIn(array $a, bool $remember): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
            if (!$remember) {
                // ends with the browser; the default session cookie lasts two weeks
                $p = session_get_cookie_params();
                setcookie(session_name(), session_id(), ['expires' => 0, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => $p['samesite'] ?: 'Lax']);
            }
        }
        $_SESSION['online'] = $a['id'];
        Db::save('online_accounts', ['id' => $a['id'], 'login_at' => Clock::ms(), 'lang' => I18n::lang()]);
    }

    public static function logout(): void
    {
        unset($_SESSION['online'], $_SESSION['online_cart'], $_SESSION['online_pending']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /** O8 step 1: a reset code for a known address. Unknown addresses look the same to the visitor. */
    public static function startReset(string $email): void
    {
        $email = self::email($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationError(['email' => I18n::t('on.err_email')]);
        }
        if (!RateLimit::hit('online:reset:' . \Sofrexa\Core\Net::clientIp(), 10, 3_600_000)) {
            throw new \InvalidArgumentException(I18n::t('on.err_limit'));
        }
        $a = self::byEmail($email);
        if ($a) {
            self::sendCode($a, 'reset');
        }
    }

    /** O8 step 2: code, new password twice; the address is proven, so an unverified account opens as well. */
    public static function reset(string $email, string $code, string $pw, string $pw2): array
    {
        $a = self::byEmail($email);
        if (!$a) {
            throw new ValidationError(['code' => I18n::t('on.err_code')]);
        }
        if (!self::passwordOk($pw)) {
            throw new ValidationError(['password' => I18n::t('on.pw_rule')]);
        }
        if ($pw !== $pw2) {
            throw new ValidationError(['password2' => I18n::t('on.err_match')]);
        }
        self::checkCode($a, 'reset', $code);
        Db::save('online_accounts', ['id' => $a['id'], 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'verified_at' => $a['verified_at'] ?: Clock::ms()]);
        $a = self::get($a['id']);
        self::signIn($a, true);
        return $a;
    }

    /** Saved addresses of the signed-in customer, the default first. */
    public static function addresses(string $customerId): array
    {
        return Db::rows('SELECT * FROM customer_addresses WHERE customer_id = ? AND deleted = 0 ORDER BY is_default DESC, rowid', [$customerId]);
    }

    /** "Ev" / "Deniz Sk. No 7, Daire 3" / "Karaoğlanoğlu" — a new saved address. */
    public static function addAddress(string $customerId, string $label, string $street, string $district, string $note = ''): string
    {
        $err = [];
        if (trim($street) === '') {
            $err['street'] = I18n::t('on.err_street');
        }
        if (trim($district) === '') {
            $err['district'] = I18n::t('on.err_district');
        }
        if ($err) {
            throw new ValidationError($err);
        }
        if ((int) Db::value('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ? AND deleted = 0', [$customerId]) >= 10) {
            throw new \InvalidArgumentException(I18n::t('on.err_addresses'));
        }
        // kept as one line, as the till writes it: "street, district"; the district is what couriers read first
        return \Sofrexa\Modules\Customers\Customers::addAddress($customerId, trim($district) . ', ' . trim($street), trim($label) ?: I18n::t('on.addr_home'), $note);
    }
}
