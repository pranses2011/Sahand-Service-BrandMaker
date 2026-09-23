<?php
/**
 * 🏷️ ژنراتور عنوان بهینه — TitleGenerator v3.0
 * =============================================
 * تولید و رتبه‌بندی عنوان‌های جذاب (CTR-محور) برای
 * مقالات و صفحات — کاملاً فارسی و قاعده‌محور.
 *
 * ۸ الگوی عنوان: آموزشی، پرسشی، عددی، فصلی، محلی،
 * مقایسه‌ای، راه‌حلی و چک‌لیستی
 *
 * امتیازدهی هر عنوان:
 *   📏 طول (۴۵-۶۰ کاراکتر = بهینه برای SERP)
 *   🔢 وجود عدد (تا ۱۵٪ CTR بیشتر)
 *   ❓ سؤالی بودن (نیاز را برمی‌انگیزد)
 *   ⚡ واژه‌های قدرت (رایگان، فوری، قطعی،...)
 *   🎯 موقعیت کلیدواژه (اول عنوان = بهتر)
 *
 * @package SahandBrandMaker\Engine
 * @version 3.0.0
 */
class TitleGenerator
{
    /** @var array واژه‌های قدرت فارسی */
    private const POWER_WORDS = [
        'رایگان', 'فوری', 'قطعی', 'کامل', 'جامع', 'حرفه‌ای', 'ساده', 'خوشحال',
        'بدون', 'خطرناک', 'مهم', 'ضروری', 'طلایی', 'قطعی‌ترین', 'بهترین',
        'خلبانی', 'نهایی', 'گام‌به‌گام', 'درمان قطعی', 'آسان',
    ];

    /** @var array عدد‌های فارسی جذاب */
    private const CATCHY_NUMBERS = [3, 5, 7, 9, 10, 12, 15, 21];

    /**
     * 🏷️ تولید و رتبه‌بندی عنوان‌ها
     *
     * @param string $topic    موضوع/کلیدواژه کانونی
     * @param array  $context  زمینه [brand_fa, device_fa, city, season, intent]
     * @param int    $count    تعداد عنوان خروجی
     * @return array عنوانهای رتبه‌بندی‌شده
     */
    public function generate(string $topic, array $context = [], int $count = 10): array
    {
        $topic = trim($topic);
        if ($topic === '') {
            throw new RuntimeException('موضوع عنوان خالی است.');
        }

        $device = $context['device_fa'] ?? '';
        $brand = $context['brand_fa'] ?? '';
        $city = $context['city'] ?? '';
        $season = $context['season'] ?? '';

        // 🎯 کلیدواژه اصلی (ترکیب هوشمند)
        $focus = $device !== '' && mb_strpos($topic, $device) === false ? $device . ' ' . $topic : $topic;

        $templates = $this->templates($focus, $device, $brand, $city, $season);

        $scored = [];
        foreach ($templates as $tpl) {
            $title = $tpl['title'];
            $scored[] = [
                'title'            => $title,
                'pattern'          => $tpl['pattern'],
                'pattern_fa'       => $tpl['pattern_fa'],
                'char_count'       => mb_strlen($title),
                'score'            => $this->score($title, $focus),
                'has_number'       => (bool)preg_match('/[0-9۰-۹]/', $title),
                'is_question'      => str_contains($title, '؟') || str_contains($title, '?'),
                'power_words'      => $this->powerWordsIn($title),
                'keyword_position' => $this->keywordPosition($title, $focus),
            ];
        }

        // 🏆 رتبه‌بندی نزولی
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']
            ?: $a['char_count'] <=> $b['char_count']);

        $top = array_slice(array_values($scored), 0, max(1, min(25, $count)));

        return [
            'topic'      => $topic,
            'focus'      => $focus,
            'generated'  => count($templates),
            'returned'   => count($top),
            'best'       => $top[0]['title'] ?? '',
            'best_score' => $top[0]['score'] ?? 0,
            'titles'     => $top,
            'tips'       => [
                'عنوان ۴۵ تا ۶۰ کاراکتری در نتایج گوگل کامل نمایش داده می‌شود.',
                'وجود عدد در عنوان نرخ کلیک را تا ۱۵٪ افزایش می‌دهد.',
                'کلیدواژه کانونی را در ۳ کلمه اول عنوان بیاورید.',
            ],
        ];
    }

    /* ==================================================
     * 🆕 فاز Q.5 — پیشنهاد بهترین عنوان سئو برای عنوان دلخواه
     * ================================================== */

    /**
     * 🎯 پیشنهاد بهترین عنوان سئو برای عنوان دلخواه کاربر
     *
     * عنوان اصلی کاربر را تحلیل کرده، واریانت‌های سئو-بهینه می‌سازد
     * (عدد، پرسش، واژه قدرت، سال، براکت) و همه را امتیازدهی و رتبه‌بندی می‌کند.
     *
     * @param string $customTitle عنوان دلخواه کاربر
     * @param array  $context    [brand_fa, device_fa, city]
     * @param int    $count      تعداد پیشنهاد خروجی
     * @return array تحلیل + پیشنهادهای رتبه‌بندی‌شده
     */
    public function suggestForCustom(string $customTitle, array $context = [], int $count = 8): array
    {
        $customTitle = trim(preg_replace('/\s+/u', ' ', $customTitle) ?? $customTitle);
        if (mb_strlen($customTitle) < 5) {
            throw new RuntimeException('عنوان دلخواه بسیار کوتاه است (حداقل ۵ نویسه).');
        }
        if (mb_strlen($customTitle) > 120) {
            $customTitle = mb_substr($customTitle, 0, 120);
        }

        $brand = (string)($context['brand_fa'] ?? '');
        $device = (string)($context['device_fa'] ?? '');

        // امتیاز عنوان اصلی کاربر
        $originalScore = $this->score($customTitle, $this->focusOf($customTitle, $device));

        // 🧹 هسته عنوان: حذف حشوهای رایج برای استخراج موضوع
        $core = $customTitle;
        $fillers = ['چگونه', 'چطور', 'راهنمای کامل', 'آموزش کامل', 'همه چیز درباره', 'همه آنچه درباره'];
        foreach ($fillers as $f) {
            $core = preg_replace('/(?<![\p{L}])' . preg_quote($f, '/') . ' (?=[\p{L}])/u', '', $core) ?? $core;
        }
        $core = mb_trim(preg_replace('/\s+/u', ' ', $core) ?? $core, ' ؛،.:-'); // mb_trim: trim بایت-محور خرابکار است
        $core = trim($core) !== '' ? trim($core) : $customTitle;

        $focus = $this->focusOf($core, $device);
        $num = $this->faNum(self::CATCHY_NUMBERS[array_rand(self::CATCHY_NUMBERS)]);
        $num2 = $this->faNum(self::CATCHY_NUMBERS[array_rand(self::CATCHY_NUMBERS)]);
        $year = '۱۴۰۴';

        // 🧵 ساخت واریانت‌های سئو — قصد کاربر حفظ می‌شود، فقط جذابیت و سئو اضافه می‌شود
        $variants = [
            $customTitle, // عنوان اصلی (پایه مقایسه)
            "{$core}؛ راهنمای کامل {$year}",
            "{$num} نکته طلایی درباره {$core}",
            "آیا {$core}؟ همه آنچه باید بدانید",
            "{$core} را در {$num2} مرحله ساده انجام دهید",
            "{$core}؛ علل، تشخیص و راه‌حل قطعی",
            "{$num} اشتباه رایج در {$core} که پرهزینه است",
            "{$core} — راهنمای جامع و کاربردی",
            "چرا {$core} اهمیت دارد و چه باید کرد؟",
            "{$num} راهکار اثبات‌شده برای {$core}",
            "راهنمای صفر تا صد {$core} [{$year}]",
            "{$core}؛ تجربه متخصصان و توصیه‌های عملی",
        ];
        if ($brand !== '' && mb_strpos($core, $brand) === false) {
            $variants[] = "{$core} در {$brand}؛ چک‌لیست و راهنما";
            $variants[] = "راهنمای جامع {$core} با خدمات {$brand}";
        }

        // 🏆 امتیازدهی و رتبه‌بندی همه واریانت‌ها
        $scored = [];
        foreach (array_unique($variants) as $i => $title) {
            $s = $this->score($title, $focus);
            $scored[] = [
                'title'       => $title,
                'is_original' => $i === 0,
                'char_count'  => mb_strlen($title),
                'score'       => $s,
                'gain'        => $s - $originalScore,
                'has_number'  => (bool)preg_match('/[0-9۰-۹]/', $title),
                'is_question' => str_contains($title, '؟') || str_contains($title, '?'),
                'power_words' => $this->powerWordsIn($title),
            ];
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        $top = array_slice($scored, 0, max(3, min(15, $count)));
        $best = $top[0];

        return [
            'original'      => $customTitle,
            'original_score'=> $originalScore,
            'original_analysis' => [
                'char_count'  => mb_strlen($customTitle),
                'char_verdict'=> $this->charVerdict(mb_strlen($customTitle)),
                'has_number'  => (bool)preg_match('/[0-9۰-۹]/', $customTitle),
                'is_question' => str_contains($customTitle, '؟') || str_contains($customTitle, '?'),
                'power_words' => $this->powerWordsIn($customTitle),
                'keyword_position' => $this->keywordPosition($customTitle, $focus),
            ],
            'best'          => $best['title'],
            'best_score'    => $best['score'],
            'best_gain'     => $best['score'] - $originalScore,
            'suggestions'   => $top,
            'tips'          => [
                'عنوان ۴۵ تا ۶۰ کاراکتری در نتایج گوگل کامل نمایش داده می‌شود.',
                'وجود عدد در عنوان نرخ کلیک را تا ۱۵٪ افزایش می‌دهد.',
                'کلیدواژه کانونی را در ۳ کلمه اول عنوان بیاورید.',
                'فرم پرسشی یا براکت‌دار (مثل «راهنمای کامل») توجه جلب می‌کند.',
            ],
        ];
    }

    /**
     * 🔎 استخراج کلیدواژه کانونی از عنوان/موضوع
     */
    private function focusOf(string $title, string $device): string
    {
        // اگر نام دستگاه در عنوان هست، همان محور است
        $clean = trim(preg_replace('/[؟?!؛،.]+/u', '', $title) ?? $title);
        if ($device !== '' && mb_strpos($clean, $device) !== false) {
            return $device;
        }
        // ۴ واژه نخست به عنوان کلیدواژه کانونی
        $words = array_slice(preg_split('/\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 4);
        return implode(' ', $words);
    }

    /**
     * 📏 حکم طول عنوان
     */
    private function charVerdict(int $len): string
    {
        if ($len < 35) {
            return 'کوتاه — می‌توانید واژه‌های کلیدی بیشتری اضافه کنید';
        }
        if ($len >= 45 && $len <= 60) {
            return 'ایده‌آل — در SERP گوگل کامل نمایش داده می‌شود';
        }
        if ($len <= 70) {
            return 'قابل قبول — کمی طولانی است';
        }
        return 'بلند — در نتایج گوگل بریده می‌شود؛ کوتاه‌سازی کنید';
    }

    /* ==================================================
     * 🛠️ متدهای داخلی
     * ================================================== */

    /**
     * 🧵 الگوهای عنوان — ۸ تیپ × چند واریانت
     */
    private function templates(string $focus, string $device, string $brand, string $city, string $season): array
    {
        $num = $this->faNum(self::CATCHY_NUMBERS[array_rand(self::CATCHY_NUMBERS)]);
        $out = [];

        // ۱) 🎓 آموزشی (how-to)
        $out[] = ['title' => "آموزش {$focus}؛ گام‌به‌گام و تصویری", 'pattern' => 'how_to', 'pattern_fa' => 'آموزشی'];
        $out[] = ['title' => "{$focus} را در {$num} مرحله ساده انجام دهید", 'pattern' => 'how_to', 'pattern_fa' => 'آموزشی'];

        // ۲) ❓ پرسشی
        $out[] = ['title' => "چرا {$focus} رخ می‌دهد و چطور حلش کنیم؟", 'pattern' => 'question', 'pattern_fa' => 'پرسشی'];
        $out[] = ['title' => "آیا {$focus} خودتان قابل انجام است؟", 'pattern' => 'question', 'pattern_fa' => 'پرسشی'];
        $out[] = ['title' => "{$focus}؟ همه آنچه باید بدانید", 'pattern' => 'question', 'pattern_fa' => 'پرسشی'];

        // ۳) 🔢 عددی (listicle)
        $out[] = ['title' => "{$num} نکته طلایی درباره {$focus}", 'pattern' => 'numbered', 'pattern_fa' => 'عددی'];
        $out[] = ['title' => "{$num} اشتباه رایج در {$focus} که پرهزینه است", 'pattern' => 'numbered', 'pattern_fa' => 'عددی'];
        $out[] = ['title' => "{$num} راهکار اثبات‌شده برای {$focus}", 'pattern' => 'numbered', 'pattern_fa' => 'عددی'];

        // ۴) 🌸 فصلی
        if ($season !== '') {
            $out[] = ['title' => "نگهداری فصلی {$focus} در {$season}", 'pattern' => 'seasonal', 'pattern_fa' => 'فصلی'];
        }

        // ۵) 📍 محلی
        if ($city !== '') {
            $out[] = ['title' => "{$focus} در {$city}؛ راهنمای کامل ۱۴۰۴", 'pattern' => 'local', 'pattern_fa' => 'محلی'];
        }

        // ۶) ⚖️ مقایسه‌ای
        if ($brand !== '') {
            $out[] = ['title' => "مقایسه خدمات {$focus}؛ چرا {$brand}؟", 'pattern' => 'comparison', 'pattern_fa' => 'مقایسه‌ای'];
        }

        // ۷) 💊 راه‌حلی (problem/solution)
        $out[] = ['title' => "مشکل {$focus}؟ تشخیص و رفع قطعی", 'pattern' => 'solution', 'pattern_fa' => 'راه‌حلی'];
        $out[] = ['title' => "رفع {$focus} بدون نیاز به تعمیرکار", 'pattern' => 'solution', 'pattern_fa' => 'راه‌حلی'];

        // ۸) ✅ چک‌لیستی
        $out[] = ['title' => "چک‌لیست کامل {$focus} [قابل دانلود]", 'pattern' => 'checklist', 'pattern_fa' => 'چک‌لیستی'];
        $out[] = ['title' => "راهنمای جامع {$focus}؛ از صفر تا صد", 'pattern' => 'checklist', 'pattern_fa' => 'چک‌لیستی'];

        // 🎲 واریانت‌های جایگزین (پرهیز از تکرار)
        $alt = [
            "هر آنچه درباره {$focus} باید بدانید",
            "راهنمای صفر تا صد {$focus} برای خانواده‌ها",
            "{$focus}: علل، تشخیص و درمان",
            "تجربه متخصصان درباره {$focus}",
        ];
        foreach ($alt as $a) {
            $out[] = ['title' => $a, 'pattern' => 'general', 'pattern_fa' => 'عمومی'];
        }

        return $out;
    }

    /**
     * 🏆 امتیاز عنوان (۰-۱۰۰)
     */
    private function score(string $title, string $focus): int
    {
        $len = mb_strlen($title);
        $score = 40.0; // پایه

        // 📏 طول بهینه ۴۵-۶۰
        if ($len >= 45 && $len <= 60) {
            $score += 20;
        } elseif ($len > 60) {
            $score -= min(15, ($len - 60) * 0.8);
        } else {
            $score -= min(12, (45 - $len) * 0.5);
        }

        // 🔢 عدد
        if (preg_match('/[0-9۰-۹]/', $title)) {
            $score += 12;
        }

        // ❓ سؤال
        if (str_contains($title, '؟') || str_contains($title, '?')) {
            $score += 8;
        }

        // ⚡ واژه قدرت
        $power = $this->powerWordsIn($title);
        $score += min(12, count($power) * 6);

        // 🎯 موقعیت کلیدواژه (اول = بهتر)
        $pos = $this->keywordPosition($title, $focus);
        if ($pos !== null) {
            $score += $pos <= 12 ? 14 : ($pos <= 25 ? 9 : 5);
        }

        return (int)round(max(0, min(100, $score)));
    }

    /**
     * ⚡ واژه‌های قدرت موجود در عنوان
     */
    private function powerWordsIn(string $title): array
    {
        $found = [];
        foreach (self::POWER_WORDS as $w) {
            if (mb_strpos($title, $w) !== false) {
                $found[] = $w;
            }
        }
        return $found;
    }

    /**
     * 🎯 موقعیت کاراکتری کلیدواژه در عنوان
     */
    private function keywordPosition(string $title, string $focus): ?int
    {
        // اولین واژه کانونی
        $firstWord = explode(' ', trim($focus))[0] ?? '';
        if ($firstWord === '') {
            return null;
        }
        $pos = mb_strpos($title, $firstWord);
        return $pos === false ? null : $pos;
    }

    /**
     * 🔢 تبدیل عدد به فارسی
     */
    private function faNum(int $n): string
    {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            (string)$n
        );
    }
}
