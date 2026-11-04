<?php
declare(strict_types=1);
namespace App\Services\Payment;

/**
 * Zarinpal gateway (REST v4).
 *
 * Nothing here runs until a merchant id is configured: GiftCard asks
 * Gateway::configured() first and otherwise leaves the order "در انتظار پرداخت"
 * instead of pretending a payment happened.
 *
 * Amounts are sent in the currency named by ZARINPAL_CURRENCY (IRR by default,
 * IRT for toman). A transport hook exists only so the request/verify payloads
 * can be asserted without the network.
 */
final class Zarinpal {
    /** @var null|\Closure(string, array): array */
    public static ?\Closure $transport = null;

    public static function merchantId(): string {
        return trim((string)env_value('ZARINPAL_MERCHANT_ID', ''));
    }

    public static function configured(): bool {
        return self::merchantId() !== '';
    }

    private static function base(): string {
        $sandbox = filter_var(env_value('ZARINPAL_SANDBOX', false), FILTER_VALIDATE_BOOLEAN);
        return $sandbox ? 'https://sandbox.zarinpal.com' : 'https://payment.zarinpal.com';
    }

    /**
     * Create a payment and return the page the buyer must visit.
     * @return array{ok: bool, authority?: string, redirect?: string, error?: string}
     */
    public static function request(int $amount, string $description, string $callbackUrl, string $mobile = '', string $email = ''): array {
        if (!self::configured()) return ['ok' => false, 'error' => 'درگاه پرداخت هنوز تنظیم نشده است.'];
        if ($amount <= 0) return ['ok' => false, 'error' => 'مبلغ پرداخت معتبر نیست.'];

        $payload = [
            'merchant_id' => self::merchantId(),
            'amount' => $amount,
            'currency' => strtoupper((string)env_value('ZARINPAL_CURRENCY', 'IRR')),
            'callback_url' => $callbackUrl,
            'description' => mb_substr($description, 0, 250),
        ];
        $metadata = [];
        if ($mobile !== '') $metadata['mobile'] = $mobile;
        if ($email !== '') $metadata['email'] = $email;
        if ($metadata !== []) $payload['metadata'] = $metadata;

        $response = self::post('/pg/v4/payment/request.json', $payload);
        $code = (int)($response['data']['code'] ?? 0);
        $authority = (string)($response['data']['authority'] ?? '');
        if ($code !== 100 || $authority === '') {
            return ['ok' => false, 'error' => self::message($code, $response['errors'] ?? [])];
        }
        return ['ok' => true, 'authority' => $authority, 'redirect' => self::base() . '/pg/StartPay/' . $authority];
    }

    /**
     * Verify a returned authority. Code 101 means it was already verified, which
     * counts as success so a refreshed callback never loses a paid order.
     * @return array{ok: bool, ref_id?: string, error?: string, code?: int}
     */
    public static function verify(string $authority, int $amount): array {
        if (!self::configured()) return ['ok' => false, 'error' => 'درگاه پرداخت تنظیم نشده است.'];
        $response = self::post('/pg/v4/payment/verify.json', [
            'merchant_id' => self::merchantId(),
            'amount' => $amount,
            'authority' => $authority,
        ]);
        $code = (int)($response['data']['code'] ?? 0);
        if ($code === 100 || $code === 101) {
            return ['ok' => true, 'ref_id' => (string)($response['data']['ref_id'] ?? ''), 'code' => $code];
        }
        return ['ok' => false, 'code' => $code, 'error' => self::message($code, $response['errors'] ?? [])];
    }

    /** @return array{data: array, errors: array} */
    private static function post(string $path, array $payload): array {
        $url = self::base() . $path;
        if (self::$transport !== null) {
            return (self::$transport)($url, $payload) + ['data' => [], 'errors' => []];
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $failed = curl_errno($handle);
        curl_close($handle);

        if ($body === false || $failed !== 0) {
            return ['data' => [], 'errors' => ['message' => 'ارتباط با درگاه پرداخت برقرار نشد.']];
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            return ['data' => [], 'errors' => ['message' => 'پاسخ درگاه قابل خواندن نبود (کد ' . $status . ').']];
        }
        $decoded['data'] = $decoded['data'] ?? [];
        $decoded['errors'] = $decoded['errors'] ?? [];
        return $decoded;
    }

    /** Zarinpal returns numeric codes; translate the ones a buyer can hit. */
    public static function message(int $code, array $errors = []): string {
        $map = [
            -9 => 'اطلاعات ارسالی به درگاه درست نیست. با پشتیبانی تماس بگیرید.',
            -10 => 'مرچنت‌آیدی یا آی‌پی درگاه پذیرفته نشد.',
            -11 => 'درخواست پرداخت رد شد؛ مرچنت را بررسی کنید.',
            -12 => 'تعداد تلاش‌ها زیاد بود؛ چند دقیقه بعد دوباره امتحان کنید.',
            -15 => 'پذیرندهٔ این پرداخت مسدود است.',
            -16 => 'سطح تأیید پذیرنده کافی نیست.',
            -17 => 'مبلغ پرداخت بیش از حد مجاز است.',
            -21 => 'هیچ پرداخت‌کننده‌ای برای این تراکنش وجود ندارد.',
            -22 => 'پرداخت ناموفق بود.',
            -30 => 'پذیرنده اجازهٔ این تراکنش را ندارد.',
            -31 => 'حساب پذیرنده مسدود است.',
            -32 => 'مبلغ تراکنش با مبلغ درخواست همخوانی ندارد.',
            -33 => 'مبلغ پرداخت‌شده با مبلغ سفارش یکی نیست.',
            -34 => 'سقف تقسیم تراکنش را رد کرده‌اید.',
            -40 => 'پارامتر نامعتبر است.',
            -50 => 'مبلغ پرداخت‌شده با مبلغ درخواست یکی نیست.',
            -51 => 'پرداخت ناموفق بود.',
            -52 => 'خطای غیرمنتظره در درگاه رخ داد.',
            -53 => 'این تراکنش متعلق به این پذیرنده نیست.',
            -54 => 'شناسهٔ پرداخت (authority) نامعتبر است.',
        ];
        if (isset($map[$code])) return $map[$code];
        // Unknown codes (including transport failures) get a Persian message a
        // customer can act on; the gateway's own text is not shown to them.
        return 'پرداخت انجام نشد. اگر مبلغی از حساب شما کسر شده باشد، طی ۷۲ ساعت برمی‌گردد.';
    }
}
