<?php
declare(strict_types=1);
/**
 * Upload rules for the massage catalog image, exercised without a web server.
 *
 * ImageUpload::store() is the only place that decides what may be stored, so
 * these assertions pin the allowed formats, the size ceiling, the "keep the
 * current picture" behaviour and the remove flag.
 */
require __DIR__ . '/../app/bootstrap.php';

use App\Support\ImageUpload;

$source = __DIR__ . '/../public/assets/img/massage/photos/relax.jpg';
if (!is_file($source)) { fwrite(STDERR, "missing fixture photo\n"); exit(1); }

$failures = 0;
function check(bool $ok, string $message): void {
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $message . "\n";
    if (!$ok) $failures++;
}

/** Copy a fixture to a temp file and hand it to the uploader like $_FILES would. */
function attempt(string $path, int $size, int $error = UPLOAD_ERR_OK, bool $remove = false, ?array &$captured = null): array {
    $tmp = tempnam(sys_get_temp_dir(), 'upl-');
    copy($path, $tmp);
    $captured = ['name' => basename($path), 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => $error, 'size' => $size];
    $data = [];
    $errors = ImageUpload::store($captured, $data, $remove);
    if (is_file($tmp)) @unlink($tmp);
    return [$errors, $data];
}

[$errors, $data] = attempt($source, (int)filesize($source));
check($errors === [], 'a valid JPEG is accepted');
$stored = (string)($data['image'] ?? '');
check(str_starts_with($stored, 'uploads/site/'), 'the stored path points at uploads/site/');
check($stored !== '' && is_file(base_path('public/' . $stored)), 'the file really exists on disk');
check($stored !== '' && @getimagesize(base_path('public/' . $stored)) !== false, 'the stored file is a readable image');
check($stored !== '' && str_ends_with($stored, '.jpg'), 'the extension follows the real format');
if ($stored !== '') @unlink(base_path('public/' . $stored));

[$errors] = attempt($source, 5 * 1024 * 1024);
check($errors !== [] && str_contains($errors[0], '۴ مگابایت'), 'a file over 4 MB is rejected');

[$errors] = attempt($source, 1000, UPLOAD_ERR_INI_SIZE);
check($errors !== [] && str_contains($errors[0], 'کد 1'), 'an upload error is reported with its code');

$text = tempnam(sys_get_temp_dir(), 'txt-');
file_put_contents($text, 'not an image');
[$errors] = attempt($text, (int)filesize($text));
check($errors !== [] && str_contains($errors[0], 'JPG'), 'a non-image file is rejected');
@unlink($text);

// Empty input keeps the existing picture: nothing is written to $data.
$data = ['title' => 'x'];
$errors = ImageUpload::store(['error' => UPLOAD_ERR_NO_FILE], $data);
check($errors === [] && !array_key_exists('image', $data), 'an empty file input leaves the existing image alone');

// The remove flag clears it.
$data = ['title' => 'x'];
$errors = ImageUpload::store(['error' => UPLOAD_ERR_NO_FILE], $data, true);
check($errors === [] && array_key_exists('image', $data) && $data['image'] === null, 'the remove flag clears the image');

// A PNG is accepted and keeps its extension (no GD on the target host, so the
// bytes come from a known-good 1x1 PNG instead of an image library).
$png = tempnam(sys_get_temp_dir(), 'png-');
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
[$errors, $data] = attempt($png, (int)filesize($png));
check($errors === [] && str_ends_with((string)($data['image'] ?? ''), '.png'), 'a PNG is accepted with a .png name');
if (!empty($data['image'])) @unlink(base_path('public/' . $data['image']));
@unlink($png);

$html = ImageUpload::fieldHtml('');
check(str_contains($html, 'type="file"') && str_contains($html, 'accept="image/jpeg,image/png,image/webp"'), 'the field renders a file input with the accepted types');
$html = ImageUpload::fieldHtml('uploads/site/x.jpg');
check(str_contains($html, 'name="image_remove"'), 'an existing image offers the remove checkbox');

echo $failures === 0 ? "OK: image upload assertions\n" : "FAILED: $failures\n";
exit($failures === 0 ? 0 : 1);
