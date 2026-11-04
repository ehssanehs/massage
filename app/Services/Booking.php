<?php
declare(strict_types=1);
namespace App\Services;

use App\Support\Jalali;
use PDO;

/**
 * Online booking against a section's real schedule.
 *
 * Availability is not invented: it comes from each active therapist's
 * working_schedule ({"sat":"09-17", ...}), the chosen service's duration and the
 * appointments already taken in that section. A slot is offered only when a
 * therapist who can perform the service is working and free for the whole
 * duration.
 *
 * The browser only ever sends a section key, a service id, a date and a slot
 * time. Everything else is re-read and re-validated here, so a tampered form
 * cannot book outside working hours or onto a taken slot.
 */
final class Booking {
    public const SECTIONS = ['men' => 'مرکز آقایان', 'women' => 'مرکز بانوان'];

    private const SCHEDULE_DAYS = [
        6 => 'sat', 0 => 'sun', 1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri',
    ];

    private const MONTHS = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
    ];

    private const WEEKDAYS = [
        6 => 'شنبه', 0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه',
    ];

    /** Slot granularity, in minutes. */
    public const STEP = 30;

    /** @var array<string, PDO> */
    private static array $connections = [];

    /** The section this request books into; null means a single-section install. */
    public static function section(?string $requested = null): ?string {
        if (!\App\Support\Tenant::isMulti()) return null;
        return isset(self::SECTIONS[(string)$requested]) ? (string)$requested : null;
    }

    public static function sectionLabel(?string $section): string {
        return $section === null ? 'مرکز ماساژ' : self::SECTIONS[$section];
    }

    public static function pdo(?string $section): PDO {
        $key = (string)$section;
        if (isset(self::$connections[$key])) return self::$connections[$key];

        if ($section === null) {
            $name = (string)env_value('DB_DATABASE', '');
            if ($name === '') {
                foreach (['MEN', 'WOMEN'] as $suffix) {
                    $candidate = (string)env_value('DB_DATABASE_' . $suffix, '');
                    if ($candidate !== '') { $name = $candidate; break; }
                }
            }
        } else {
            $name = (string)env_value('DB_DATABASE_' . strtoupper($section), '');
        }
        if ($name === '') throw new \RuntimeException('پایگاه‌دادهٔ این مرکز تنظیم نشده است.');

        $dsn = 'mysql:host=' . env_value('DB_HOST', '127.0.0.1') . ';port=' . env_value('DB_PORT', '3306')
            . ';dbname=' . $name . ';charset=utf8mb4';
        return self::$connections[$key] = new PDO($dsn, env_value('DB_USERNAME', 'root'), env_value('DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /** Treatments that can be booked online in this section. */
    public static function services(?string $section): array {
        $st = self::pdo($section)->prepare(
            "SELECT id, name, default_price, duration_minutes FROM services
             WHERE status = 'active' AND deleted_at IS NULL ORDER BY name"
        );
        $st->execute();
        return $st->fetchAll();
    }

    public static function service(?string $section, int $id): ?array {
        foreach (self::services($section) as $service) {
            if ((int)$service['id'] === $id) return $service;
        }
        return null;
    }

    /** Persian label such as «شنبه ۱۵ آبان». */
    public static function dayLabel(string $date): string {
        // toJalali returns Persian digits; convert before reading the parts.
        $jalali = Jalali::en(Jalali::toJalali($date));
        [$y, $m, $d] = array_map('intval', explode('/', $jalali));
        $weekday = self::WEEKDAYS[(int)date('w', strtotime($date))] ?? '';
        return $weekday . ' ' . Jalali::fa($d) . ' ' . (self::MONTHS[$m] ?? '');
    }

    /**
     * The next $count days that at least one active therapist works.
     * Closed days are skipped rather than shown as bookable.
     */
    public static function days(?string $section, int $count = 10): array {
        $schedules = self::schedules($section);
        $days = [];
        $cursor = new \DateTimeImmutable('today');
        for ($i = 0; $i < 60 && count($days) < $count; $i++) {
            $date = $cursor->modify('+' . $i . ' day')->format('Y-m-d');
            $key = self::SCHEDULE_DAYS[(int)date('w', strtotime($date))] ?? '';
            foreach ($schedules as $schedule) {
                if ($key !== '' && isset($schedule[$key])) { $days[$date] = self::dayLabel($date); break; }
            }
        }
        return $days;
    }

    /** working_schedule JSON of every active therapist, decoded, keyed by id. */
    private static function schedules(?string $section): array {
        $st = self::pdo($section)->prepare(
            "SELECT id, working_schedule FROM therapists WHERE status = 'active' AND deleted_at IS NULL"
        );
        $st->execute();
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $schedule = json_decode((string)$row['working_schedule'], true);
            if (is_array($schedule) && $schedule !== []) $out[(int)$row['id']] = $schedule;
        }
        return $out;
    }

    /** Therapists able to perform $serviceId on $date, with their window. */
    private static function candidates(?string $section, int $serviceId, string $date): array {
        $key = self::SCHEDULE_DAYS[(int)date('w', strtotime($date))] ?? '';
        $st = self::pdo($section)->prepare(
            "SELECT id, service_ids, working_schedule FROM therapists
             WHERE status = 'active' AND deleted_at IS NULL"
        );
        $st->execute();
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $schedule = json_decode((string)$row['working_schedule'], true);
            if (!is_array($schedule) || $key === '' || !isset($schedule[$key])) continue;
            $skills = array_map('intval', (array)(json_decode((string)$row['service_ids'], true) ?: []));
            if ($skills !== [] && !in_array($serviceId, $skills, true)) continue;
            $window = self::window((string)$schedule[$key]);
            if ($window === null) continue;
            $out[(int)$row['id']] = $window;
        }
        return $out;
    }

    /** "09-17" → [32400, 61200] in seconds, or null when malformed. */
    private static function window(string $value): ?array {
        if (!preg_match('/^(\d{1,2})(?::(\d{2}))?\s*-\s*(\d{1,2})(?::(\d{2}))?$/', trim($value), $m)) return null;
        $start = (int)$m[1] * 3600 + (int)($m[2] ?? 0) * 60;
        $end = (int)$m[3] * 3600 + (int)($m[4] ?? 0) * 60;
        return $end > $start ? [$start, $end] : null;
    }

    /** Booked intervals per therapist for a date, ignoring cancelled ones. */
    private static function taken(?string $section, string $date): array {
        $st = self::pdo($section)->prepare(
            "SELECT therapist_id, start_time, end_time FROM appointments
             WHERE appointment_date = ? AND deleted_at IS NULL
               AND status NOT IN ('cancelled', 'no_show')"
        );
        $st->execute([$date]);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[(int)$row['therapist_id']][] = [
                self::toSeconds((string)$row['start_time']),
                self::toSeconds((string)$row['end_time']),
            ];
        }
        return $out;
    }

    private static function toSeconds(string $time): int {
        [$h, $m] = array_pad(explode(':', $time), 2, '0');
        return (int)$h * 3600 + (int)$m * 60;
    }

    private static function toTime(int $seconds): string {
        return sprintf('%02d:%02d:00', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    /**
     * Free start times for a service on a date: [['time' => '10:00:00',
     * 'label' => '۱۰:۰۰', 'therapist_id' => 3], ...] in chronological order.
     */
    public static function slots(?string $section, int $serviceId, string $date): array {
        $service = self::service($section, $serviceId);
        if ($service === null) return [];
        $duration = max(15, (int)$service['duration_minutes']) * 60;
        $candidates = self::candidates($section, $serviceId, $date);
        if ($candidates === []) return [];
        return self::computeSlots($candidates, self::taken($section, $date), $duration);
    }

    /** @param array<int, array{0:int,1:int}> $candidates therapist id => [open, close] */
    private static function computeSlots(array $candidates, array $taken, int $duration): array {
        $byTime = [];
        foreach ($candidates as $therapistId => $window) {
            [$open, $close] = $window;
            for ($start = $open; $start + $duration <= $close; $start += self::STEP * 60) {
                $end = $start + $duration;
                $conflict = false;
                foreach ($taken[$therapistId] ?? [] as [$busyStart, $busyEnd]) {
                    if ($start < $busyEnd && $busyStart < $end) { $conflict = true; break; }
                }
                if ($conflict) continue;
                $time = self::toTime($start);
                if (!isset($byTime[$time])) {
                    $byTime[$time] = ['time' => $time, 'label' => Jalali::fa(substr($time, 0, 5)), 'therapist_id' => $therapistId];
                }
            }
        }
        ksort($byTime);
        return array_values($byTime);
    }

    /**
     * Days that actually have a free time for this service.
     *
     * The booking form used to list every day a therapist works, even when that
     * therapist cannot perform the chosen massage, so a guest could pick a day
     * and then find nothing. This walks the calendar with the service's own
     * eligibility rules and returns only days with at least one slot.
     *
     * @return array<string, array{label: string, slots: int}>
     */
    public static function availableDays(?string $section, int $serviceId, int $limit = 12): array {
        $service = self::service($section, $serviceId);
        if ($service === null) return [];
        $duration = max(15, (int)$service['duration_minutes']) * 60;
        $skills = self::skillSchedules($section, $serviceId);
        if ($skills === []) return [];

        $from = date('Y-m-d');
        $to = date('Y-m-d', strtotime('+60 days'));
        $taken = self::takenBetween($section, $from, $to);

        $out = [];
        for ($i = 0; $i < 60 && count($out) < $limit; $i++) {
            $date = date('Y-m-d', strtotime('+' . $i . ' day'));
            $dayKey = self::SCHEDULE_DAYS[(int)date('w', strtotime($date))] ?? '';
            if ($dayKey === '') continue;
            $candidates = [];
            foreach ($skills as $therapistId => $schedule) {
                if (!isset($schedule[$dayKey])) continue;
                $window = self::window((string)$schedule[$dayKey]);
                if ($window !== null) $candidates[$therapistId] = $window;
            }
            if ($candidates === []) continue;
            $slots = self::computeSlots($candidates, $taken[$date] ?? [], $duration);
            if ($slots !== []) $out[$date] = ['label' => self::dayLabel($date), 'slots' => count($slots)];
        }
        return $out;
    }

    /** Active therapists who can perform $serviceId, with their full weekly schedule. */
    private static function skillSchedules(?string $section, int $serviceId): array {
        $st = self::pdo($section)->prepare(
            "SELECT id, service_ids, working_schedule FROM therapists WHERE status = 'active' AND deleted_at IS NULL"
        );
        $st->execute();
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $skills = array_map('intval', (array)(json_decode((string)$row['service_ids'], true) ?: []));
            if ($skills !== [] && !in_array($serviceId, $skills, true)) continue;
            $schedule = json_decode((string)$row['working_schedule'], true);
            if (is_array($schedule) && $schedule !== []) $out[(int)$row['id']] = $schedule;
        }
        return $out;
    }

    /** Booked intervals for a date range, grouped by date and therapist. */
    private static function takenBetween(?string $section, string $from, string $to): array {
        $st = self::pdo($section)->prepare(
            "SELECT appointment_date, therapist_id, start_time, end_time FROM appointments
             WHERE appointment_date BETWEEN ? AND ? AND deleted_at IS NULL
               AND status NOT IN ('cancelled', 'no_show')"
        );
        $st->execute([$from, $to]);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[(string)$row['appointment_date']][(int)$row['therapist_id']][] = [
                self::toSeconds((string)$row['start_time']),
                self::toSeconds((string)$row['end_time']),
            ];
        }
        return $out;
    }

    /**
     * Cancel a pending online booking. Returns [ok, error].
     * Only a booking that has not been confirmed yet may be cancelled by the guest.
     */
    public static function cancel(?string $section, string $ref): array {
        $row = self::findByRef($section, $ref);
        if ($row === null) return ['ok' => false, 'error' => 'نوبتی با این کد پیدا نشد.'];
        if ((string)$row['status'] !== 'pending') {
            return ['ok' => false, 'error' => 'این نوبت دیگر در وضعیت «در انتظار تأیید» نیست؛ برای لغو با مرکز تماس بگیرید.'];
        }
        $st = self::pdo($section)->prepare(
            "UPDATE appointments SET status = 'cancelled', updated_at = NOW()
             WHERE booking_ref = ? AND source = 'online' AND status = 'pending' AND deleted_at IS NULL"
        );
        $st->execute([self::normalizeRef($ref)]);
        if ($st->rowCount() === 0) {
            return ['ok' => false, 'error' => 'لغو نوبت انجام نشد؛ دوباره تلاش کنید.'];
        }
        return ['ok' => true];
    }

    /**
     * Create the appointment (and the customer when the mobile is new).
     * Returns ['ok' => bool, 'errors' => string[], 'ref' => ?string, 'appointment' => ?array].
     */
    public static function create(?string $section, array $post): array {
        $errors = [];
        $name = trim((string)($post['name'] ?? ''));
        $mobile = Jalali::en(trim((string)($post['mobile'] ?? '')));
        $mobile = preg_replace('/[^0-9]/', '', $mobile);
        $serviceId = (int)($post['service_id'] ?? 0);
        $date = trim((string)($post['date'] ?? ''));
        $time = trim((string)($post['time'] ?? ''));
        $note = trim((string)($post['note'] ?? ''));

        if (mb_strlen($name) < 3) $errors[] = 'نام و نام خانوادگی را وارد کنید.';
        if (!preg_match('/^09\d{9}$/', $mobile)) $errors[] = 'شمارهٔ موبایل باید ۱۱ رقم و با ۰۹ شروع شود.';

        $service = $serviceId > 0 ? self::service($section, $serviceId) : null;
        if ($service === null) $errors[] = 'خدمت انتخاب‌شده معتبر نیست.';

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < date('Y-m-d')) {
            $errors[] = 'تاریخ انتخاب‌شده معتبر نیست.';
        }
        if (!isset(self::days($section, 10)[$date])) $errors[] = 'این روز برای رزرو باز نیست.';

        $slot = null;
        if ($errors === []) {
            foreach (self::slots($section, $serviceId, $date) as $candidate) {
                if ($candidate['time'] === $time) { $slot = $candidate; break; }
            }
            if ($slot === null) $errors[] = 'این ساعت دیگر خالی نیست؛ زمان دیگری انتخاب کنید.';
        }

        if ($errors !== []) return ['ok' => false, 'errors' => $errors];

        $pdo = self::pdo($section);
        $pdo->beginTransaction();
        try {
            // Re-check inside the transaction: two guests may submit the same slot.
            $end = self::toTime(self::toSeconds($time) + (int)$service['duration_minutes'] * 60);
            $clash = $pdo->prepare(
                "SELECT COUNT(*) FROM appointments WHERE therapist_id = ? AND appointment_date = ?
                   AND deleted_at IS NULL AND status NOT IN ('cancelled','no_show')
                   AND start_time < ? AND ? < end_time"
            );
            $clash->execute([(int)$slot['therapist_id'], $date, $end, $time]);
            if ((int)$clash->fetchColumn() > 0) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => ['این ساعت همین حالا رزرو شد؛ زمان دیگری انتخاب کنید.']];
            }

            $find = $pdo->prepare('SELECT id FROM customers WHERE mobile = ? AND deleted_at IS NULL ORDER BY id LIMIT 1');
            $find->execute([$mobile]);
            $customerId = (int)($find->fetchColumn() ?: 0);
            if ($customerId === 0) {
                [$first, $last] = self::splitName($name);
                $insert = $pdo->prepare(
                    'INSERT INTO customers (customer_code, first_name, last_name, mobile, registration_date, status, created_at)
                     VALUES (?, ?, ?, ?, ?, "new", NOW())'
                );
                $insert->execute([self::customerCode(), $first, $last, $mobile, date('Y-m-d')]);
                $customerId = (int)$pdo->lastInsertId();
            }

            $ref = strtoupper(bin2hex(random_bytes(3)));
            $notes = 'رزرو آنلاین';
            if ($note !== '') $notes .= ' | ' . mb_substr($note, 0, 300);
            $book = $pdo->prepare(
                "INSERT INTO appointments
                   (customer_id, therapist_id, service_id, appointment_date, start_time, end_time, status, source, booking_ref, notes, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending', 'online', ?, ?, NOW())"
            );
            $book->execute([$customerId, (int)$slot['therapist_id'], $serviceId, $date, $time, $end, $ref, $notes]);
            $appointmentId = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'errors' => ['ثبت نوبت انجام نشد. لطفاً دوباره تلاش کنید.']];
        }

        return [
            'ok' => true,
            'errors' => [],
            'ref' => $ref,
            'appointment' => [
                'id' => $appointmentId,
                'ref' => $ref,
                'date' => $date,
                'time' => substr($time, 0, 5),
                'service' => $service['name'],
                'customer' => $name,
            ],
        ];
    }

    /** A reference is six hex characters; accept any case, spaces or dashes. */
    public static function normalizeRef(string $ref): string {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $ref) ?? '');
    }

    /** Look up an online booking by its reference, for the guest status page. */
    public static function findByRef(?string $section, string $ref): ?array {
        $st = self::pdo($section)->prepare(
            "SELECT a.booking_ref, a.appointment_date, a.start_time, a.status, s.name service
             FROM appointments a JOIN services s ON s.id = a.service_id
             WHERE a.booking_ref = ? AND a.source = 'online' AND a.deleted_at IS NULL LIMIT 1"
        );
        $st->execute([self::normalizeRef($ref)]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * Look a reference up in every section (the guest only knows the code, not
     * which centre they booked). Returns the row plus the section it was found in.
     */
    public static function findAnywhere(string $ref): ?array {
        $sections = \App\Support\Tenant::isMulti() ? array_keys(self::SECTIONS) : [null];
        foreach ($sections as $section) {
            try {
                $row = self::findByRef($section, $ref);
            } catch (\Throwable $e) {
                continue;
            }
            if ($row !== null) {
                $row['section'] = $section;
                return $row;
            }
        }
        return null;
    }

    private static function splitName(string $name): array {
        $parts = preg_split('/\s+/', $name, 2) ?: [$name];
        return [$parts[0], $parts[1] ?? ''];
    }

    private static function customerCode(): string {
        return 'WEB-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }
}
