<?php
namespace App\Services;

interface NotificationChannel { public function send(string $to, string $message, array $context=[]): bool; }
final class NullNotificationChannel implements NotificationChannel { public function send(string $to, string $message, array $context=[]): bool { return true; } }
final class NotificationManager {
    public static function channel(string $name='default'): NotificationChannel { return new NullNotificationChannel(); }
}
