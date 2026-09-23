<?php
/**
 * 🧠 SelfLearner — موتور خودیادگیر و خودارتقادهنده AI سایت‌ساز
 * ================================================================
 * هر بار که یک عمل «بهبود» (سئو، محتوا، UX، کد خطا) نتیجه بهتری می‌گیرد،
 * موتور آن را به‌صورت یک «درس» (Lesson) ثبت می‌کند تا در کارهای بعدی:
 *   ۱) پارامترهای موفق را ترجیح بدهد (مثلاً تراکم هدف کلیدواژه)
 *   ۲) الگوهای اثبات‌شده را خودکار اعمال کند
 *
 * 🔐 امنیت ارتقاها: هر درس در `engine/knowledge/ai-lessons.json` با شناسه و
 * کلید enabled ثبت می‌شود. اگر درسی اشتباه بود و عملکرد را مختل کرد، از
 * پنل «یادگیری AI» همان یک درس غیرفعال می‌شود — بقیه درس‌ها پابرجا می‌مانند.
 * 📜 تمام ارتقاها به‌صورت فقط-افزودنی در `logs/ai-upgrades.log` نیز ثبت
 * می‌شوند (تاریخچه حسابرسی — هرگز پاک نمی‌شود).
 *
 * @package SahandBrandMaker
 * @version 1.0
 */
class SelfLearner
{
    /** @var string مسیر پایگاه درس‌ها */
    private const LESSONS_FILE = ENGINE_PATH . '/knowledge/ai-lessons.json';

    /** @var string مسیر لاگ فقط-افزودنی ارتقاها */
    private const UPGRADES_LOG = ROOT_PATH . '/logs/ai-upgrades.log';

    /** @var array|null کش پایگاه درس‌ها */
    private static ?array $cache = null;

    /* ==================================================
     * 📥 بارگذاری و ذخیره
     * ================================================== */

    private static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        if (!file_exists(self::LESSONS_FILE)) {
            return self::$cache = ['lessons' => [], 'version' => 1, 'updated_at' => null];
        }
        $data = json_decode((string)file_get_contents(self::LESSONS_FILE), true);
        if (!is_array($data)) {
            return self::$cache = ['lessons' => [], 'version' => 1, 'updated_at' => null];
        }
        $data['lessons'] = $data['lessons'] ?? [];
        return self::$cache = $data;
    }

    private static function save(array $data): void
    {
        $data['version'] = 1;
        $data['updated_at'] = date('Y-m-d H:i:s');
        $dir = dirname(self::LESSONS_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(self::LESSONS_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        self::$cache = $data;
    }

    /* ==================================================
     * 🎓 ثبت درس جدید (پس از هر بهبود موفق)
     * ================================================== */

    /**
     * 🎓 ثبت یک درس از بهبود موفق
     *
     * @param string $type    نوع حوزه: seo_fix | content_fix | uiux_fix | error_code_fix
     * @param string $metric  سنجه/بخش مربوطه (مثلاً keyword_density)
     * @param string $action  کلید اقدام موفق (مثلاً add_summary_block)
     * @param array  $params  پارامترهای مؤثر (مثلاً ['target_density' => 1.3])
     * @param array  $before  وضعیت قبل (مثلاً ['score' => 36])
     * @param array  $after   وضعیت بعد  (مثلاً ['score' => 84])
     * @param string $rule    شرح خوانای قانون آموخته‌شده
     * @return string شناسه درس ثبت‌شده (یا شناسه درس تقویت‌شده)
     */
    public static function record(string $type, string $metric, string $action, array $params, array $before, array $after, string $rule = ''): string
    {
        $data = self::load();
        $gain = (int)($after['score'] ?? 0) - (int)($before['score'] ?? 0);

        // 🧲 اگر درس مشابه (نوع+سنجه+اقدام) قبلاً ثبت شده، فقط تقویتش می‌کنیم
        foreach ($data['lessons'] as &$lesson) {
            if (($lesson['type'] ?? '') === $type && ($lesson['metric'] ?? '') === $metric && ($lesson['action'] ?? '') === $action) {
                $lesson['uses'] = (int)($lesson['uses'] ?? 0) + 1;
                $lesson['total_gain'] = (int)($lesson['total_gain'] ?? 0) + max(0, $gain);
                $lesson['last_run'] = date('Y-m-d H:i:s');
                // پارامترها با میانگین‌گیری تعدیلی به‌روز می‌شوند (یادگیری تدریجی)
                foreach ($params as $k => $v) {
                    if (is_numeric($v) && isset($lesson['params'][$k]) && is_numeric($lesson['params'][$k])) {
                        $n = max(2, $lesson['uses']);
                        $lesson['params'][$k] = round(((float)$lesson['params'][$k] * ($n - 1) + (float)$v) / $n, 3);
                    } else {
                        $lesson['params'][$k] = $v;
                    }
                }
                if ($rule !== '') {
                    $lesson['rule'] = $rule;
                }
                unset($lesson);
                self::save($data);
                self::logLine('♻️ reinforce', $type, $metric, $action, $gain, $before, $after);
                return 'reinforced';
            }
        }
        unset($lesson);

        // 🆕 درس جدید
        $id = 'l_' . time() . '_' . substr(md5($type . $metric . $action . microtime()), 0, 6);
        $data['lessons'][] = [
            'id'          => $id,
            'type'        => $type,
            'metric'      => $metric,
            'action'      => $action,
            'params'      => $params,
            'rule'        => $rule !== '' ? $rule : self::autoRule($type, $metric, $action, $params, $before, $after),
            'before'      => $before,
            'after'       => $after,
            'gain'        => $gain,
            'total_gain'  => max(0, $gain),
            'uses'        => 1,
            'enabled'     => true,
            'created_at'  => date('Y-m-d H:i:s'),
            'last_run'    => date('Y-m-d H:i:s'),
        ];
        self::save($data);
        self::logLine('🎓 learn', $type, $metric, $action, $gain, $before, $after);
        return $id;
    }

    /** 📜 ثبت خط در لاگ حسابرسی (فقط-افزودنی) */
    private static function logLine(string $event, string $type, string $metric, string $action, int $gain, array $before, array $after): void
    {
        $dir = dirname(self::UPGRADES_LOG);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = sprintf(
            "[%s] %s | %s:%s | action=%s | score %s → %s (%+d) | %s\n",
            date('Y-m-d H:i:s'),
            $event,
            $type,
            $metric,
            $action,
            (string)($before['score'] ?? '-'),
            (string)($after['score'] ?? '-'),
            $gain,
            'ip=' . ($_SERVER['REMOTE_ADDR'] ?? 'cli')
        );
        @file_put_contents(self::UPGRADES_LOG, $line, FILE_APPEND | LOCK_EX);
    }

    /** ✍️ ساخت شرح خودکار درس */
    private static function autoRule(string $type, string $metric, string $action, array $params, array $before, array $after): string
    {
        $labels = [
            'seo_fix' => 'بهبود سئو',
            'content_fix' => 'بهبود محتوا',
            'uiux_fix' => 'بهبود UX',
            'error_code_fix' => 'بهبود کد خطا',
        ];
        $gain = (int)($after['score'] ?? 0) - (int)($before['score'] ?? 0);
        return sprintf(
            'در %s، اقدام «%s» روی سنجه «%s» امتیاز را از %s به %s رساند (%+d) — این اقدام در بهبودهای بعدی همین سنجه ترجیح داده می‌شود.',
            $labels[$type] ?? $type,
            $action,
            $metric,
            (string)($before['score'] ?? '-'),
            (string)($after['score'] ?? '-'),
            $gain
        );
    }

    /* ==================================================
     * 🧲 مشورت با درس‌های آموخته‌شده (در کارهای بعدی)
     * ================================================== */

    /**
     * 🧲 بهترین پارامتر آموخته‌شده برای یک سنجه — یا پیش‌فرض
     *
     * @param string $type   حوزه (seo_fix و...)
     * @param string $metric سنجه
     * @param string $key    نام پارامتر (مثلاً target_density)
     * @param mixed  $default پیش‌فرض در نبود درس فعال
     * @return mixed
     */
    public static function param(string $type, string $metric, string $key, $default)
    {
        foreach (self::load()['lessons'] as $lesson) {
            if (!empty($lesson['enabled']) && ($lesson['type'] ?? '') === $type && ($lesson['metric'] ?? '') === $metric) {
                if (array_key_exists($key, $lesson['params'] ?? [])) {
                    return $lesson['params'][$key];
                }
            }
        }
        return $default;
    }

    /**
     * ✅ آیا اقدام مشخصی برای این سنجه «آموخته و فعال» است؟
     */
    public static function knows(string $type, string $metric, string $action): bool
    {
        foreach (self::load()['lessons'] as $lesson) {
            if (!empty($lesson['enabled']) && ($lesson['type'] ?? '') === $type
                && ($lesson['metric'] ?? '') === $metric && ($lesson['action'] ?? '') === $action) {
                return true;
            }
        }
        return false;
    }

    /* ==================================================
     * 🔧 مدیریت پنل (فعال/غیرفعال + آمار)
     * ================================================== */

    /** 📋 همه درس‌ها (تازه‌ترین اول) */
    public static function lessons(): array
    {
        $lessons = self::load()['lessons'];
        usort($lessons, fn($a, $b) => ($b['last_run'] ?? '') <=> ($a['last_run'] ?? ''));
        return $lessons;
    }

    /** 🔘 فعال/غیرفعال کردن یک درس — غیرفعال = دیگر اعمال نمی‌شود ولی حفظ می‌شود */
    public static function toggle(string $id, bool $enabled): bool
    {
        $data = self::load();
        foreach ($data['lessons'] as &$lesson) {
            if (($lesson['id'] ?? '') === $id) {
                $lesson['enabled'] = $enabled;
                unset($lesson);
                self::save($data);
                self::logLine($enabled ? '🟢 enable' : '🔴 disable', '-', '-', '-', 0, [], []);
                return true;
            }
        }
        unset($lesson);
        return false;
    }

    /** 🗑️ حذف کامل یک درس (فقط از پایگاه؛ لاگ حسابرسی می‌ماند) */
    public static function remove(string $id): bool
    {
        $data = self::load();
        $before = count($data['lessons']);
        $data['lessons'] = array_values(array_filter($data['lessons'], fn($l) => ($l['id'] ?? '') !== $id));
        if (count($data['lessons']) === $before) {
            return false;
        }
        self::save($data);
        return true;
    }

    /** 📊 آمار کلی */
    public static function stats(): array
    {
        $lessons = self::load()['lessons'];
        $active = array_filter($lessons, fn($l) => !empty($l['enabled']));
        return [
            'total'       => count($lessons),
            'active'      => count($active),
            'disabled'    => count($lessons) - count($active),
            'total_gain'  => array_sum(array_map(fn($l) => (int)($l['total_gain'] ?? 0), $lessons)),
            'total_uses'  => array_sum(array_map(fn($l) => (int)($l['uses'] ?? 0), $lessons)),
            'by_type'     => [
                'seo_fix'         => count(array_filter($lessons, fn($l) => ($l['type'] ?? '') === 'seo_fix')),
                'content_fix'     => count(array_filter($lessons, fn($l) => ($l['type'] ?? '') === 'content_fix')),
                'uiux_fix'        => count(array_filter($lessons, fn($l) => ($l['type'] ?? '') === 'uiux_fix')),
                'error_code_fix'  => count(array_filter($lessons, fn($l) => ($l['type'] ?? '') === 'error_code_fix')),
            ],
        ];
    }

    /** 📜 آخرین خطوط لاگ ارتقاها (برای نمایش در پنل) */
    public static function recentLog(int $lines = 40): array
    {
        if (!file_exists(self::UPGRADES_LOG)) {
            return [];
        }
        $all = array_values(array_filter(explode("\n", (string)file_get_contents(self::UPGRADES_LOG)), 'trim'));
        return array_slice(array_reverse($all), 0, $lines);
    }
}
