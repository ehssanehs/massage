<?php
declare(strict_types=1);
namespace App\Services;

use App\Services\Payment\Zarinpal;
use App\Support\SiteContent;
use PDO;

/**
 * Gift cards sold on the public site.
 *
 * The row is created before the buyer leaves for the gateway, so the amount and
 * the order are fixed server-side; the gateway callback only ever flips an
 * existing pending row to paid. Codes are only shown after a verified payment.
 */
final class GiftCard {
    public const STATUSES = [
        'pending' => 'در انتظار پرداخت',
        'paid' => 'پرداخت‌شده',
        'redeemed' => 'استفاده‌شده',
        'cancelled' => 'لغو‌شده',
    ];

    public const MIN_AMOUNT = 200000;
    public const MAX_AMOUNT = 200000000;

    private static function pdo(): PDO {
        return SiteContent::pdo();
    }

    /** Which gateway is active: zarinpal when a merchant id exists. */
    public static function driver(): string {
        $requested = strtolower(trim((string)env_value('PAYMENT_DRIVER', '')));
        if ($requested === 'fake') return 'fake'; // test-only, never the default
        if ($requested === 'zarinpal' || ($requested === '' && Zarinpal::configured())) {
            return 'zarinpal';
        }
        return 'none';
    }

    public static function enabled(): bool {
        return self::driver() !== 'none';
    }

    /** Amounts offered as buttons, from the site panel (Rial). */
    public static function presets(): array {
        $raw = SiteContent::block('gift.amounts', '500000,1000000,2000000,5000000');
        $out = [];
        foreach (explode(',', $raw) as $part) {
            $value = (int)preg_replace('/[^0-9]/', '', $part);
            if ($value >= self::MIN_AMOUNT && $value <= self::MAX_AMOUNT) $out[] = $value;
        }
        return $out !== [] ? $out : [500000, 1000000, 2000000, 5000000];
    }

    /** @return array{ok: bool, errors: string[], redirect?: string, code?: string, pending?: bool} */
    public static function create(array $post, string $callbackUrl): array {
        $errors = [];
        $buyer = trim((string)($post['buyer_name'] ?? ''));
        $buyerMobile = preg_replace('/[^0-9]/', '', \App\Support\Jalali::en((string)($post['buyer_mobile'] ?? '')));
        $recipient = trim((string)($post['recipient_name'] ?? ''));
        $recipientMobile = preg_replace('/[^0-9]/', '', \App\Support\Jalali::en((string)($post['recipient_mobile'] ?? '')));
        $message = trim((string)($post['message'] ?? ''));
        $amount = (int)preg_replace('/[^0-9]/', '', (string)($post['amount'] ?? '0'));

        if (mb_strlen($buyer) < 3) $errors[] = 'نام خریدار را کامل وارد کنید.';
        if (!preg_match('/^09\d{9}$/', $buyerMobile)) $errors[] = 'شمارهٔ موبایل خریدار باید ۱۱ رقم و با ۰۹ شروع شود.';
        if ($recipientMobile !== '' && !preg_match('/^09\d{9}$/', $recipientMobile)) $errors[] = 'شمارهٔ موبایل گیرنده معتبر نیست.';
        if ($amount < self::MIN_AMOUNT || $amount > self::MAX_AMOUNT) {
            $errors[] = 'مبلغ کارت هدیه باید بین ' . \App\Support\Jalali::fa(number_format(self::MIN_AMOUNT))
                . ' و ' . \App\Support\Jalali::fa(number_format(self::MAX_AMOUNT)) . ' ریال باشد.';
        }
        if (!self::enabled()) $errors[] = 'پرداخت آنلاین هنوز فعال نشده است.';
        if ($errors !== []) return ['ok' => false, 'errors' => $errors];

        $code = self::newCode();
        $st = self::pdo()->prepare(
            "INSERT INTO gift_cards
               (code, amount, status, buyer_name, buyer_mobile, recipient_name, recipient_mobile, message, driver, created_at)
             VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, NOW())"
        );
        $st->execute([$code, $amount, $buyer, $buyerMobile, $recipient ?: null, $recipientMobile ?: null, $message ?: null, self::driver()]);
        $id = (int)self::pdo()->lastInsertId();

        $description = 'کارت هدیه ماساژ - ' . $code;
        if (self::driver() === 'fake') {
            $authority = 'FAKE-' . $id;
            self::saveAuthority($id, $authority);
            return ['ok' => true, 'code' => $code, 'redirect' => $callbackUrl . '&Authority=' . urlencode($authority) . '&Status=OK'];
        }

        $payment = Zarinpal::request($amount, $description, $callbackUrl, $buyerMobile);
        if (!$payment['ok']) {
            self::cancel($id);
            return ['ok' => false, 'errors' => [$payment['error'] ?? 'ایجاد پرداخت ناموفق بود.']];
        }
        self::saveAuthority($id, (string)$payment['authority']);
        return ['ok' => true, 'code' => $code, 'redirect' => (string)$payment['redirect']];
    }

    private static function saveAuthority(int $id, string $authority): void {
        self::pdo()->prepare('UPDATE gift_cards SET authority = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$authority, $id]);
    }

    private static function cancel(int $id): void {
        self::pdo()->prepare("UPDATE gift_cards SET status = 'cancelled', updated_at = NOW() WHERE id = ?")
            ->execute([$id]);
    }

    public static function byAuthority(string $authority): ?array {
        $st = self::pdo()->prepare('SELECT * FROM gift_cards WHERE authority = ? LIMIT 1');
        $st->execute([$authority]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function byCode(string $code): ?array {
        $st = self::pdo()->prepare('SELECT * FROM gift_cards WHERE code = ? LIMIT 1');
        $st->execute([strtoupper(trim($code))]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * Verify a gateway callback and mark the card paid exactly once.
     * @return array{ok: bool, card?: array, error?: string}
     */
    public static function complete(string $authority): array {
        $card = self::byAuthority($authority);
        if ($card === null) return ['ok' => false, 'error' => 'سفارش کارت هدیه پیدا نشد.'];
        if ($card['status'] === 'paid') return ['ok' => true, 'card' => $card];

        $amount = (int)round((float)$card['amount']);
        $driver = (string)($card['driver'] ?? '');
        if ($driver === 'fake') {
            $verified = ['ok' => true, 'ref_id' => 'FAKE-' . $card['id']];
        } else {
            $verified = Zarinpal::verify($authority, $amount);
        }
        if (!$verified['ok']) return ['ok' => false, 'error' => $verified['error'] ?? 'پرداخت تأیید نشد.'];

        $st = self::pdo()->prepare(
            "UPDATE gift_cards SET status = 'paid', ref_id = ?, paid_at = NOW(), updated_at = NOW(),
                expires_at = DATE_ADD(CURDATE(), INTERVAL 365 DAY)
             WHERE id = ? AND status = 'pending'"
        );
        $st->execute([(string)($verified['ref_id'] ?? ''), (int)$card['id']]);

        return ['ok' => true, 'card' => self::byAuthority($authority) ?? $card];
    }

    /** GIFT-XXXX-XXXX without look-alike characters. */
    private static function newCode(): string {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = 'GIFT-';
            for ($block = 0; $block < 2; $block++) {
                if ($block > 0) $code .= '-';
                for ($i = 0; $i < 4; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $exists = self::byCode($code) !== null;
        } while ($exists);
        return $code;
    }

    public static function recent(int $limit = 100): array {
        $limit = max(1, min(500, $limit));
        return self::pdo()->query(
            "SELECT * FROM gift_cards ORDER BY id DESC LIMIT " . $limit
        )->fetchAll();
    }

    public static function statusLabel(string $status): string {
        return self::STATUSES[$status] ?? $status;
    }
}
