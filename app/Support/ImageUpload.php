<?php
declare(strict_types=1);
namespace App\Support;

/**
 * Image upload for modules that declare `image => true` (the massage catalog).
 *
 * The rules live here rather than inline in the front controller so they can be
 * tested without a running web server, and so every uploader goes through the
 * same checks: real image, allowed format, size ceiling, random filename.
 */
final class ImageUpload {
    public const MAX_BYTES = 4 * 1024 * 1024;
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /**
     * Handle $_FILES['image_file'] for $data.
     *
     * Returns validation errors. When the input is empty the existing image is
     * left untouched; when `image_remove` is set the value becomes null.
     */
    public static function store(array $file, array &$data, bool $remove = false): array {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            if ($remove) $data['image'] = null;
            return [];
        }
        if ($error !== UPLOAD_ERR_OK) {
            return ['آپلود تصویر انجام نشد (کد ' . $error . ').'];
        }
        if ((int)($file['size'] ?? 0) > self::MAX_BYTES) {
            return ['حجم تصویر باید کمتر از ۴ مگابایت باشد.'];
        }
        $info = @getimagesize((string)($file['tmp_name'] ?? ''));
        $extension = self::EXTENSIONS[$info['mime'] ?? ''] ?? '';
        if ($extension === '') {
            return ['فقط تصویر JPG، PNG یا WebP مجاز است.'];
        }

        $directory = public_path('uploads/site');
        if (!is_dir($directory) && !@mkdir($directory, 0755, true)) {
            return ['ساخت پوشهٔ uploads/site ممکن نشد.'];
        }
        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $target = $directory . '/' . $name;

        // In a web request this is a real upload; under the CLI test harness the
        // file already exists on disk, so only there do we fall back to rename().
        $stored = @move_uploaded_file((string)$file['tmp_name'], $target)
            || (PHP_SAPI === 'cli' && @rename((string)$file['tmp_name'], $target));
        if (!$stored) {
            return ['ذخیرهٔ تصویر ممکن نشد؛ دسترسی نوشتن پوشهٔ public/uploads را بررسی کنید.'];
        }

        $data['image'] = 'uploads/site/' . $name;
        return [];
    }

    /** Preview + file input + "remove" checkbox for the edit form. */
    public static function fieldHtml(string $current): string {
        $current = trim($current);
        $html = '<label class="form-label" for="image_file">تصویر اصلی</label><div class="image-field">';
        if ($current !== '') {
            $src = str_starts_with($current, 'uploads/')
                ? Url::upload($current)
                : asset(str_starts_with($current, 'assets/') ? substr($current, 7) : $current);
            $html .= '<img src="' . e($src) . '" alt="" width="220" height="140" style="object-fit:cover;border-radius:8px;display:block;margin-bottom:8px">';
            $html .= '<label class="form-check mb-2"><input type="checkbox" class="form-check-input" name="image_remove" value="1"> '
                . '<span class="form-check-label">حذف تصویر فعلی</span></label>';
        }
        $html .= '<input type="file" class="form-control" id="image_file" name="image_file" accept="image/jpeg,image/png,image/webp">';
        $html .= '<div class="form-text">JPG، PNG یا WebP تا ۴ مگابایت. اگر فایلی انتخاب نکنید، تصویر فعلی می‌ماند.</div></div>';
        return $html;
    }
}
