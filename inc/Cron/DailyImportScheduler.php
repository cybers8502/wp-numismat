<?php

namespace Coins\Cron;

class DailyImportScheduler
{
    const HOOK = 'coins_daily_import_and_webp';

    public function boot(): void
    {
        add_action('init', [$this, 'maybeSchedule']);
        add_action(self::HOOK, [$this, 'run']);
        add_action('switch_theme', [$this, 'unschedule']);
    }

    public function maybeSchedule(): void
    {
        if (!is_executable($this->scriptPath())) {
            return;
        }

        if (wp_next_scheduled(self::HOOK)) {
            return;
        }

        wp_schedule_event($this->nextThreeAm(), 'daily', self::HOOK);
    }

    public function unschedule(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public function run(): void
    {
        if (!function_exists('exec')) {
            error_log('[coins-cron] exec() is disabled, cannot run daily import script.');
            return;
        }

        $script = $this->scriptPath();

        if (!is_executable($script)) {
            error_log("[coins-cron] Script not found or not executable: {$script}");
            return;
        }

        // Detached background run — a real HTTP-triggered wp-cron request is capped
        // at max_execution_time (30s on this host), while the import can take minutes.
        exec(escapeshellarg($script) . ' > /dev/null 2>&1 &');
    }

    private function scriptPath(): string
    {
        return rtrim(ABSPATH, '/') . '/cron-coins.sh';
    }

    private function nextThreeAm(): int
    {
        $timezone = wp_timezone();
        $now      = new \DateTime('now', $timezone);
        $next     = new \DateTime('today 03:00', $timezone);

        if ($next <= $now) {
            $next->modify('+1 day');
        }

        return $next->getTimestamp();
    }
}
