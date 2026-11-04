<?php
declare(strict_types=1);
// Unit assertions for the Zarinpal adapter. The transport is stubbed, so this
// never touches the network: it pins the payload sent to the gateway and how
// each response code is interpreted.
require __DIR__ . '/../app/bootstrap.php';

use App\Services\Payment\Zarinpal;

$failures = 0;
function check(bool $condition, string $message): void {
    global $failures;
    if ($condition) { echo "ok   $message\n"; return; }
    $failures++;
    echo "FAIL $message\n";
}

$_ENV['ZARINPAL_MERCHANT_ID'] = 'test-merchant-id';
putenv('ZARINPAL_MERCHANT_ID=test-merchant-id');
$_ENV['ZARINPAL_CURRENCY'] = 'IRR';
$_ENV['ZARINPAL_SANDBOX'] = 'true';

Zarinpal::$transport = static function (string $url, array $payload): array {
    if (str_contains($url, '/request.json')) {
        return ['data' => ['code' => 100, 'authority' => 'A0000000000000000000000000000012345'], 'errors' => []];
    }
    if (($payload['authority'] ?? '') === 'A-BAD') {
        return ['data' => ['code' => 100], 'errors' => []];
    }
    return ['data' => ['code' => 100, 'ref_id' => '20100000000000012345'], 'errors' => []];
};

$request = Zarinpal::request(1500000, 'کارت هدیه ماساژ', 'https://example.test/cb', '09121234567');
check($request['ok'] === true, 'a successful request returns ok');
check(($request['authority'] ?? '') === 'A0000000000000000000000000000012345', 'the authority is returned');
check(str_contains((string)($request['redirect'] ?? ''), 'sandbox.zarinpal.com/pg/StartPay/'), 'sandbox uses the sandbox pay page');
check(str_contains((string)($request['redirect'] ?? ''), 'A0000000000000000000000000000012345'), 'the redirect carries the authority');

$captured = null;
Zarinpal::$transport = static function (string $url, array $payload) use (&$captured): array {
    $captured = ['url' => $url, 'payload' => $payload];
    return ['data' => ['code' => 100, 'authority' => 'AX'], 'errors' => []];
};
Zarinpal::request(1500000, 'کارت هدیه', 'https://example.test/cb', '09121234567', 'buyer@example.test');
check(($captured['payload']['merchant_id'] ?? '') === 'test-merchant-id', 'merchant id is sent');
check(($captured['payload']['amount'] ?? 0) === 1500000, 'the amount is sent as an integer');
check(($captured['payload']['currency'] ?? '') === 'IRR', 'the configured currency is sent');
check(($captured['payload']['callback_url'] ?? '') === 'https://example.test/cb', 'the callback url is sent');
check(($captured['payload']['metadata']['mobile'] ?? '') === '09121234567', 'the buyer mobile is passed as metadata');
check(($captured['payload']['metadata']['email'] ?? '') === 'buyer@example.test', 'the buyer email is passed as metadata');

Zarinpal::$transport = static function (string $url, array $payload): array {
    if (($payload['authority'] ?? '') === 'A-OK') return ['data' => ['code' => 100, 'ref_id' => 'REF-1'], 'errors' => []];
    if (($payload['authority'] ?? '') === 'A-DONE') return ['data' => ['code' => 101, 'ref_id' => 'REF-2'], 'errors' => []];
    return ['data' => [], 'errors' => ['message' => 'rejected']];
};
$ok = Zarinpal::verify('A-OK', 1500000);
check($ok['ok'] === true && ($ok['ref_id'] ?? '') === 'REF-1', 'code 100 verifies');
$again = Zarinpal::verify('A-DONE', 1500000);
check($again['ok'] === true && ($again['ref_id'] ?? '') === 'REF-2', 'code 101 (already verified) counts as success');
$bad = Zarinpal::verify('A-BAD', 1500000);
check($bad['ok'] === false, 'an unknown code fails verification');
check(str_contains((string)($bad['error'] ?? ''), 'پرداخت انجام نشد'), 'a failure returns a Persian message');

Zarinpal::$transport = static function (): array { return ['data' => [], 'errors' => ['message' => 'timeout']]; };
$down = Zarinpal::request(1000, 'x', 'https://example.test/cb');
check($down['ok'] === false && ($down['error'] ?? '') !== '', 'a gateway error returns a message, not an exception');

check(str_contains(Zarinpal::message(-51), 'ناموفق'), 'known error codes are translated');
check(Zarinpal::message(-9999) !== '', 'unknown codes still produce a message');

echo $failures === 0 ? "OK: zarinpal adapter assertions\n" : "FAILED: $failures\n";
exit($failures === 0 ? 0 : 1);
