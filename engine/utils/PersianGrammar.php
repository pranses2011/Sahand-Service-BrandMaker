<?php
/**
 * 📘 موتور قواعد نگارشی و روان‌نویسی فارسی — PersianGrammar v1.0
 * =================================================================
 * لایه کنترل کیفیت نوشتار موتور AI — قواعد نگارشی فرهنگستان،
 * نیم‌فاصله، علائم سجاوندی، هم‌خوانی فعل و فاعل، املای رایج،
 * روان‌نویسی (شکستن جمله بلند، حذف تکرار) و ارزیابی نگارشی.
 *
 * 🧩 بخش‌ها:
 *   ۱) fix()          — اصلاح کامل HTML (فقط گره‌های متنی؛ code/pre دست‌نخورده)
 *   ۲) analyze()      — گزارش خطاها + امتیاز نگارش ۰-۱۰۰
 *   ۳) fixAgreement() — اصلاح پراطمینان هم‌خوانی فعل و فاعل
 *   ۴) fluency()      — راهکارهای روان‌نویسی (جمله بلند، تکرار واژه)
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class PersianGrammar
{
    /** @var string نیم‌فاصله (ZWNJ) */
    private const ZWNJ = "\u{200C}";

    /* ==================================================
     * ۱) 🧹 اصلاح کامل متن/HTML
     * ================================================== */

    /**
     * 🧹 اصلاح نگارشی کامل — HTML-امن (تگ‌ها و ویژگی‌ها دست نمی‌خورند)
     *
     * @param string $content متن یا HTML
     * @param bool   $fixAgreement هم‌خوانی فعل/فاعل هم اصلاح شود؟ (پیش‌فرض بله)
     * @return array ['content' => string, 'stats' => array]
     */
    public static function fix(string $content, bool $fixAgreement = true): array
    {
        $stats = [
            'zwnj'        => 0,
            'punctuation' => 0,
            'spelling'    => 0,
            'agreement'   => 0,
            'fluency'     => 0,
            'digits'      => 0,
        ];

        // محافظت از بخش‌های فنی
        $placeholders = [];
        $protected = preg_split('/(<pre\b[^>]*>.*?<\/pre>|<code\b[^>]*>.*?<\/code>)/ius', $content, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $parts = [];
        foreach ($protected as $i => $chunk) {
            if ($i % 2 === 1) { // تگ pre/code
                $ph = "\u{2062}GPR" . count($placeholders) . "\u{2062}";
                $placeholders[$ph] = $chunk;
                $parts[] = $ph;
            } else {
                $parts[] = $chunk;
            }
        }
        $content = implode('', $parts);

        // جداکردن تگ‌ها — فقط متن‌ها پردازش می‌شوند
        $tokens = preg_split('/(<[^>]+>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $result = '';
        foreach ($tokens as $i => $token) {
            if ($i % 2 === 1 || $token === '') { // تگ HTML
                $result .= $token;
                continue;
            }
            $before = $token;
            $token = self::normalizePersian($token, $stats);
            $token = self::fixZwnj($token, $stats);
            $token = self::fixSpelling($token, $stats);
            $token = self::fixPunctuation($token, $stats);
            if ($token !== $before) {
                $stats['fluency'] += 0; // شمارش در متدهای خودشان
            }
            $result .= $token;
        }
        $content = $result;

        // هم‌خوانی فعل و فاعل (سراسری روی متن ساده)
        if ($fixAgreement) {
            $agr = self::fixAgreement($content);
            $content = $agr['content'];
            $stats['agreement'] = $agr['fixed'];
        }

        // بازگرداندن بخش‌های محافظت‌شده
        $content = strtr($content, $placeholders);

        $stats['total_fixes'] = $stats['zwnj'] + $stats['punctuation'] + $stats['spelling']
            + $stats['agreement'] + $stats['digits'];
        return ['content' => $content, 'stats' => $stats];
    }

    /**
     * 🧹 نسخه ساده — فقط متن اصلاح‌شده را برمی‌گرداند
     */
    public static function fixText(string $text, bool $fixAgreement = true): string
    {
        $r = self::fix($text, $fixAgreement);
        return $r['content'];
    }

    /* ==================================================
     * ۲) 📊 تحلیل نگارشی (بدون تغییر متن)
     * ================================================== */

    /**
     * 📊 تحلیل کامل نگارش — امتیاز ۰ تا ۱۰۰ + فهرست ایرادها
     *
     * @return array ['score' => int, 'grade' => string, 'issues' => [['type','excerpt','hint']], 'metrics' => [...]]
     */
    public static function analyze(string $content): array
    {
        $text = trim(strip_tags($content));
        $issues = [];

        /* --- شاخص‌های خام --- */
        $charCount = mb_strlen($text);
        $zwnjCount = mb_substr_count($text, self::ZWNJ);
        $words = TextProcessor::tokenize($text);
        $wordCount = count($words);
        $sentences = TextProcessor::sentenceSplit($text);
        $sentenceCount = max(1, count($sentences));

        /* --- ۱) نیم‌فاصله‌های جاافتاده --- */
        $missingZwnj = 0;
        if (preg_match_all('/(?<![\p{L}' . self::ZWNJ . '])(می|نمی|بی) (?=[\p{L}])/u', $text, $m)) {
            $missingZwnj += count($m[0]);
            $issues[] = ['type' => 'zwnj', 'excerpt' => $m[0][0] . '…', 'hint' => 'نیم‌فاصله جاافتاده: «' . $m[0][0] . '» باید با نیم‌فاصله بچسبد.'];
        }
        if (preg_match_all('/[\p{L}] ها(?![\p{L}])/u', $text, $m)) {
            $missingZwnj += count($m[0]);
            $issues[] = ['type' => 'zwnj', 'excerpt' => $m[0][0] . '…', 'hint' => 'پسوند «ها» باید با نیم‌فاصله به واژه قبل بچسبد.'];
        }
        if (preg_match_all('/[\p{L}] تر(?:ین)?(?![\p{L}])/u', $text, $m)) {
            $missingZwnj += count($m[0]);
            $issues[] = ['type' => 'zwnj', 'excerpt' => $m[0][0] . '…', 'hint' => 'پسوند «تر/ترین» با نیم‌فاصله می‌چسبد.'];
        }

        /* --- ۲) علائم سجاوندی --- */
        $punctIssues = 0;
        if (preg_match_all('/\s+[،؛!؟:]/u', $text, $m)) {
            $punctIssues += count($m[0]);
            $issues[] = ['type' => 'punctuation', 'excerpt' => '…' . trim($m[0][0]) . '…', 'hint' => 'قبل از علامت سجاوندی نباید فاصله باشد.'];
        }
        if (preg_match_all('/[،؛](?=[^\s\d\)\]»"])/u', $text, $m)) {
            $punctIssues += count($m[0]);
            $issues[] = ['type' => 'punctuation', 'excerpt' => '…' . $m[0][0] . '…', 'hint' => 'بعد از ویرگول/نقطه‌ویرگول فاصله لازم است.'];
        }
        if (preg_match_all('/[!]{2,}|[؟]{2,}/u', $text, $m)) {
            $punctIssues += count($m[0]);
            $issues[] = ['type' => 'punctuation', 'excerpt' => $m[0][0], 'hint' => 'تکرار علامت در نگارش رسمی مجاز نیست.'];
        }

        /* --- ۳) هم‌خوانی فعل و فاعل --- */
        $agr = self::detectAgreement($text);
        foreach (array_slice($agr, 0, 5) as $a) {
            $issues[] = ['type' => 'agreement', 'excerpt' => mb_substr($a['sentence'], 0, 60) . '…', 'hint' => 'فعل «' . $a['verb'] . '» با فاعل «' . $a['subject'] . '» هم‌خوان نیست؛ شکل درست: «' . $a['correct'] . '».'];
        }

        /* --- ۴) جمله‌های بسیار بلند (روان‌نویسی) --- */
        $longSentences = 0;
        foreach ($sentences as $s) {
            $len = TextProcessor::wordCount($s);
            if ($len > 32) {
                $longSentences++;
                if ($longSentences <= 3) {
                    $issues[] = ['type' => 'fluency', 'excerpt' => mb_substr($s, 0, 50) . '…', 'hint' => 'جمله ' . en_to_fa_digits((string)$len) . ' کلمه‌ای است؛ برای روان‌نویسی به دو جمله بشکنید.'];
                }
            }
        }

        /* --- ۵) تکرار واژه پشت سر هم --- */
        if (preg_match_all('/(?<![\p{L}\p{N}])([\p{L}]{3,})\s+\1(?![\p{L}\p{N}])/u', $text, $m)) {
            $issues[] = ['type' => 'fluency', 'excerpt' => $m[0][0], 'hint' => 'واژه تکراری پشت سر هم — حذف تکرار روان‌نویسی را بالا می‌برد.'];
        }

        /* --- ۶) ارقام لاتین در متن فارسی --- */
        $latinDigits = preg_match_all('/[0-9]+/', $text, $m) ?: 0;
        if ($latinDigits > 0) {
            $issues[] = ['type' => 'digits', 'excerpt' => $m[0][0] ?? '', 'hint' => 'در متن فارسی از ارقام فارسی (۰-۹) استفاده کنید.'];
        }

        /* --- 🧮 امتیاز نهایی --- */
        $per100 = static function (float $v) use ($charCount) {
            return $charCount > 0 ? ($v / $charCount) * 100 : 0;
        };
        $penalty =
            $per100($missingZwnj) * 1.4 +
            $per100($punctIssues) * 1.8 +
            $per100(count($agr)) * 6.0 +
            $per100($longSentences) * 1.2 +
            $per100($latinDigits) * 0.8 +
            ($wordCount > 200 && $zwnjCount === 0 ? 18 : 0); // متن بلند بدون هیچ نیم‌فاصله = مشکوک
        $score = (int)round(max(0, 100 - min(96, $penalty)));

        $grade = $score >= 90 ? 'عالی' : ($score >= 75 ? 'خوب' : ($score >= 55 ? 'متوسط' : 'نیازمند اصلاح'));

        return [
            'score'    => $score,
            'grade'    => $grade,
            'issues'   => array_slice($issues, 0, 25),
            'metrics'  => [
                'word_count'        => $wordCount,
                'sentence_count'    => $sentenceCount,
                'zwnj_count'        => $zwnjCount,
                'missing_zwnj'      => $missingZwnj,
                'punctuation_issues'=> $punctIssues,
                'agreement_issues'  => count($agr),
                'long_sentences'    => $longSentences,
                'latin_digits'      => $latinDigits,
                'avg_sentence_words'=> round($wordCount / $sentenceCount, 1),
            ],
        ];
    }

    /* ==================================================
     * ۳) 🧑‍🤝‍🧑 هم‌خوانی فعل و فاعل
     * ================================================== */

    /** @var array جدول شکل صحیح فعل برای هر ضمیر فاعل */
    private const AGREEMENT_MAP = [
        'ما' => [
            'است' => 'هستیم', 'بود' => 'بودیم', 'می‌شود' => 'می‌شویم', 'می‌کند' => 'می‌کنیم',
            'شده است' => 'شده‌ایم', 'خواهد بود' => 'خواهیم بود', 'می‌دهد' => 'می‌دهیم',
            'می‌رود' => 'می‌رویم', 'دارد' => 'داریم', 'می‌تواند' => 'می‌توانیم',
        ],
        'شما' => [
            'است' => 'هستید', 'بود' => 'بودید', 'می‌شود' => 'می‌شوید', 'می‌کند' => 'می‌کنید',
            'شده است' => 'شده‌اید', 'خواهد بود' => 'خواهید بود', 'می‌دهد' => 'می‌دهید',
            'می‌رود' => 'می‌روید', 'دارد' => 'دارید', 'می‌تواند' => 'می‌توانید',
        ],
        'آنها' => [
            'است' => 'هستند', 'بود' => 'بودند', 'می‌شود' => 'می‌شوند', 'می‌کند' => 'می‌کنند',
            'شده است' => 'شده‌اند', 'خواهد بود' => 'خواهند بود', 'می‌دهد' => 'می‌دهند',
            'می‌رود' => 'می‌روند', 'دارد' => 'دارند', 'می‌تواند' => 'می‌توانند',
        ],
        'آن‌ها' => [], // با الحاق بالا پر می‌شود
        'اینها' => [],
        'این‌ها' => [],
    ];

    /**
     * 🧑‍🤝‍🧑 اصلاح پراطمینان هم‌خوانی — فقط جمله‌هایی که با ضمیر شروع
     * می‌شوند و با فعل مفرد ختم می‌شوند (بالاترین اطمینان).
     *
     * @return array ['content' => string, 'fixed' => int, 'details' => []]
     */
    public static function fixAgreement(string $content): array
    {
        $map = self::agreementMap();
        $fixed = 0;
        $details = [];

        $sentences = preg_split('/(?<=[.！!؟?؛])\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($sentences as $sentence) {
            $trimmed = trim($sentence);
            $first = mb_strtok($trimmed, ' ');
            $firstClean = trim($first ?? '', "،؛.!؟: \n\t");
            if ($firstClean !== '' && isset($map[$firstClean])) {
                // آخرین واژه‌های جمله — فعل فارسی در انتهاست
                $words = preg_split('/\s+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $n = count($words);
                if ($n >= 3) {
                    // بررسی دو واژه آخر برای فعل‌های مرکب («شده است»، «خواهد بود»)
                    $last1 = trim($words[$n - 1], "،؛.!؟:");
                    $last2 = isset($words[$n - 2]) ? trim($words[$n - 2], "،؛.!؟:") . ' ' . $last1 : '';
                    $punct = $last1 !== $words[$n - 1] ? mb_substr($words[$n - 1], -1) : '';
                    foreach ([$last2, $last1] as $verbForm) {
                        if ($verbForm !== '' && isset($map[$firstClean][$verbForm])) {
                            $correct = $map[$firstClean][$verbForm];
                            $replacement = $correct . ($punct !== '' ? $punct : '');
                            $oldTail = $verbForm . ($punct !== '' ? $punct : '');
                            $newSentence = preg_replace('/' . preg_quote($verbForm, '/') . '(?=[\p{P}\s]*$)/u', $replacement, $trimmed, 1);
                            if ($newSentence !== null && $newSentence !== $trimmed) {
                                $details[] = ['subject' => $firstClean, 'verb' => $verbForm, 'correct' => $correct];
                                $sentence = $newSentence;
                                $fixed++;
                            }
                            break;
                        }
                    }
                }
            }
            $out[] = $sentence;
        }
        return ['content' => implode(' ', $out), 'fixed' => $fixed, 'details' => $details];
    }

    /**
     * 🔍 تشخیص موارد مشکوک هم‌خوانی (بدون تغییر) — برای گزارش
     * @return array فهرست ['sentence', 'subject', 'verb', 'correct']
     */
    public static function detectAgreement(string $text): array
    {
        $map = self::agreementMap();
        $found = [];
        $sentences = TextProcessor::sentenceSplit($text);
        foreach ($sentences as $sentence) {
            $words = preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $n = count($words);
            if ($n < 3) {
                continue;
            }
            // ضمیر در ۴ واژه اول؟
            $subject = null;
            $subjectIdx = -1;
            foreach (array_slice($words, 0, 4, true) as $idx => $w) {
                $clean = trim($w, "،؛:!؟.");
                if (isset($map[$clean])) {
                    $subject = $clean;
                    $subjectIdx = $idx;
                    break;
                }
            }
            if ($subject === null) {
                continue;
            }
            // فعل مفرد در پایان؟
            $last1 = trim($words[$n - 1], "،؛.!؟:");
            $last2 = isset($words[$n - 2]) ? trim($words[$n - 2], "،؛.!؟:") . ' ' . $last1 : '';
            foreach ([$last2, $last1] as $verbForm) {
                if ($verbForm !== '' && isset($map[$subject][$verbForm])) {
                    $found[] = [
                        'sentence' => mb_substr($sentence, 0, 90),
                        'subject'  => $subject,
                        'verb'     => $verbForm,
                        'correct'  => $map[$subject][$verbForm],
                    ];
                    break;
                }
            }
        }
        return $found;
    }

    /**
     * 🗺️ نقشه هم‌خوانی کامل (با کلیدهای ZWNJ-دار)
     */
    private static function agreementMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = self::AGREEMENT_MAP;
        foreach (['آن‌ها', 'اینها', 'این‌ها'] as $alias) {
            $map[$alias] = $map['آنها'];
        }
        return $map;
    }

    /* ==================================================
     * ۴) 🌊 روان‌نویسی
     * ================================================== */

    /**
     * 🌊 راهکارهای روان‌نویسی — جمله بلند، تکرار، شروع یکنواخت
     * @return array فهرست پیشنهادها (بدون تغییر متن)
     */
    public static function fluency(string $content): array
    {
        $text = trim(strip_tags($content));
        $sentences = TextProcessor::sentenceSplit($text);
        $suggestions = [];

        // ۱) جمله‌های بلند + نقطه شکست پیشنهادی
        foreach ($sentences as $i => $s) {
            $len = TextProcessor::wordCount($s);
            if ($len > 30) {
                if (mb_strpos($s, ' و ') !== false) {
                    $suggestions[] = ['type' => 'split', 'priority' => 'high', 'text' => 'جمله‌ای ' . en_to_fa_digits((string)$len) . ' کلمه‌ای؛ پیشنهاد شکستن در «و» میانی به دو جمله مستقل.'];
                } elseif (mb_strpos($s, '،') !== false) {
                    $suggestions[] = ['type' => 'split', 'priority' => 'high', 'text' => 'جمله‌ای ' . en_to_fa_digits((string)$len) . ' کلمه‌ای؛ تبدیل یکی از ویرگول‌ها به نقطه، خوانایی را بالا می‌برد.'];
                } else {
                    $suggestions[] = ['type' => 'split', 'priority' => 'medium', 'text' => 'جمله‌ای ' . en_to_fa_digits((string)$len) . ' کلمه‌ای؛ کوتاه‌سازی آن توصیه می‌شود.'];
                }
            }
        }

        // ۲) شروع یکنواخت جملات متوالی
        $streak = 1;
        $maxStreak = 1;
        $streakWord = '';
        for ($i = 1; $i < count($sentences); $i++) {
            $w1 = mb_strtok(trim($sentences[$i - 1]), ' ');
            $w2 = mb_strtok(trim($sentences[$i]), ' ');
            if ($w1 !== false && $w2 !== false && $w1 === $w2 && mb_strlen($w1) > 2) {
                $streak++;
                $streakWord = $w1;
                $maxStreak = max($maxStreak, $streak);
            } else {
                $streak = 1;
            }
        }
        if ($maxStreak >= 3) {
            $suggestions[] = ['type' => 'variety', 'priority' => 'medium', 'text' => en_to_fa_digits((string)$maxStreak) . ' جمله پشت سر هم با واژه «' . $streakWord . '» آغاز شده‌اند؛ تنوع آغازگرها روان‌نویسی را تقویت می‌کند.'];
        }

        // ۳) نسبت جمله به پاراگراف
        $paragraphs = array_filter(array_map('trim', explode("\n", $text)), fn($p) => $p !== '');
        $tooLongParagraphs = count(array_filter($paragraphs, fn($p) => TextProcessor::sentenceSplit($p) && count(TextProcessor::sentenceSplit($p)) > 6));
        if ($tooLongParagraphs > 0) {
            $suggestions[] = ['type' => 'paragraph', 'priority' => 'low', 'text' => en_to_fa_digits((string)$tooLongParagraphs) . ' پاراگراف بیش از ۶ جمله دارد؛ پاراگراف‌های ۳ تا ۵ جمله‌ای استاندارد وب‌نویسی‌اند.'];
        }

        return $suggestions;
    }

    /* ==================================================
     * 🧩 لایه‌های داخلی اصلاح
     * ================================================== */

    /**
     * 🔤 نرمال‌سازی پایه فارسی + ارقام فارسی
     */
    private static function normalizePersian(string $text, array &$stats): string
    {
        $before = $text;
        // ی/ک عربی → فارسی
        $text = str_replace(['ي', 'ك', 'ﻻ'], ['ی', 'ک', 'لا'], $text);
        // حذف اعراب
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text) ?? $text;
        // ارقام لاتین → فارسی (تنها در متن؛ attributes جدا پردازش می‌شوند)
        $fa = $text;
        $text = str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            $text
        );
        if ($text !== $fa) {
            $stats['digits']++;
        }
        // فاصله‌های تکراری و نیم‌فاصله‌های لبه‌واژه
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+\x{200C}|\x{200C}\s+/u', '', $text) ?? $text; // «می‌ رود» → «می‌رود»
        return $text;
    }

    /**
     * ✂️ نیم‌فاصله‌های صحیح — قواعد وابسته‌های چسبان
     */
    private static function fixZwnj(string $text, array &$stats): string
    {
        $before = $text;
        $z = self::ZWNJ;

        // ۱) پیشوند می/نمی/همی — «می رود» → «می‌رود»
        $text = preg_replace('/(?<![\p{L}' . $z . '])((?:ن)?می|همی) +(?=[\p{L}])/u', '$1' . $z, $text) ?? $text;

        // ۲) پیشوند بی — «بی نظیر» → «بی‌نظیر» (نه «بی» مستقل نادر)
        $text = preg_replace('/(?<![\p{L}' . $z . '])بی +(?=[\p{L}]{3,})/u', 'بی' . $z, $text) ?? $text;

        // ۳) پسوند ها — «کتاب ها» → «کتاب‌ها» (واژه حداقل ۲ حرف؛ «ها» مستقل نامعتبر)
        $text = preg_replace('/(?<=[\p{L}]{2,}) +ها(?![\p{L}])/u', $z . 'ها', $text) ?? $text;
        // «Xهای» غلط تعبیری نیست؛ حفظ

        // ۴) پسوند تر/ترین — «بزرگ تر» → «بزرگ‌تر» (با پرهیز از ایست‌واژه‌ها)
        $text = preg_replace('/(?<=[\p{L}]{3,}) +تر(?![\p{L}])/u', $z . 'تر', $text) ?? $text;
        $text = preg_replace('/(?<=[\p{L}]{3,}) +ترین(?![\p{L}])/u', $z . 'ترین', $text) ?? $text;

        // ۵) پسوند «ای» پس از «ه» — «خانه ای» → «خانه‌ای»
        $text = preg_replace('/(?<=ه) +ای(?![\p{L}])/u', $z . 'ای', $text) ?? $text;

        // ۶) «ی» میانجی — «خانه ی ما» → «خانه‌ی ما»
        $text = preg_replace('/(?<=ه) +ی +(?=[\p{L}])/u', $z . 'ی ', $text) ?? $text;

        // ۷) پسوند ساز — «بهینه سازی» → «بهینه‌سازی»
        $text = preg_replace('/(?<=[\p{L}]{3,}) +سازی(?![\p{L}])/u', $z . 'سازی', $text) ?? $text;

        // ۸) پسوند کننده/شونده — «خنک کننده» → «خنک‌کننده»
        $text = preg_replace('/(?<=[\p{L}]{3,}) +کننده(?![\p{L}])/u', $z . 'کننده', $text) ?? $text;
        $text = preg_replace('/(?<=[\p{L}]{3,}) +شونده(?![\p{L}])/u', $z . 'شونده', $text) ?? $text;

        if ($text !== $before) {
            $stats['zwnj']++;
        }
        return $text;
    }

    /**
     * 📖 املای رایج — واژه‌نامه اصلاحات پرتکرار
     * ⚠️ از \b استفاده نمی‌شود چون با حروف فارسی در PCRE کار نمی‌کند؛
     * به‌جای آن مرز یونیکد-امن (?<!\p{L}) و (?!\p{L}) به کار رفته است.
     */
    private static function fixSpelling(string $text, array &$stats): string
    {
        static $map = null;
        if ($map === null) {
            $z = self::ZWNJ;
            $b = '(?<![\p{L}\p{N}])'; // مرز آغاز واژه — یونیکد-امن
            $e = '(?![\p{L}\p{N}])';  // مرز پایان واژه — یونیکد-امن
            $map = [
                // تنوین
                $b . 'حتما' . $e => 'حتماً', $b . 'مثلا' . $e => 'مثلاً', $b . 'دقیقا' . $e => 'دقیقاً',
                $b . 'احتمالا' . $e => 'احتمالاً', $b . 'معمولا' . $e => 'معمولاً', $b . 'قطعا' . $e => 'قطعاً',
                $b . 'واقعا' . $e => 'واقعاً', $b . 'مجددا' . $e => 'مجدداً', $b . 'کاملا' . $e => 'کاملاً',
                $b . 'مطلقا' . $e => 'مطلقاً', $b . 'الزاما' . $e => 'الزاماً', $b . 'اصولا' . $e => 'اصولاً',
                $b . 'اتوماتیک' . $e => 'خودکار', $b . 'اتومات' . $e => 'خودکار',
                // حروف و حروف اضافه چسبان
                $b . 'بدلیل' . $e => 'به دلیل', $b . 'بمنظور' . $e => 'به منظور', $b . 'برایمن' . $e => 'برای من',
                $b . 'بطور' . $e => 'به طور', $b . 'برطبق' . $e => 'بر طبق', $b . 'بگونه' . $e => 'به گونه',
                $b . 'بعنوان' . $e => 'به عنوان', $b . 'بصورت' . $e => 'به صورت', $b . 'بزبان' . $e => 'به زبان',
                // ترکیب‌های یک‌واژه‌ای فرهنگستان
                $b . 'هم چنین' . $e => 'همچنین', $b . 'بنابر این' . $e => 'بنابراین', $b . 'چنان چه' . $e => 'چنانچه',
                $b . 'بر خلاف' . $e => 'برخلاف', $b . 'هر چند' . $e => 'هرچند', $b . 'هر چه' . $e => 'هرچه',
                $b . 'زیرا که' . $e => 'زیرا', $b . 'چون که' . $e => 'چون',
                // ترکیب‌های نیم‌فاصله‌دار پرکاربرد تعمیرات
                $b . 'راه اندازی' . $e => 'راه' . $z . 'اندازی', $b . 'راه انداز' . $e => 'راه' . $z . 'انداز',
                $b . 'جمع بندی' . $e => 'جمع' . $z . 'بندی', $b . 'گران قیمت' . $e => 'گران' . $z . 'قیمت',
                $b . 'بی نقص' . $e => 'بی' . $z . 'نقص', $b . 'نیم فاصله' . $e => 'نیم' . $z . 'فاصله',
                $b . 'چهار راه' . $e => 'چهارراه', $b . 'بی ربط' . $e => 'بی' . $z . 'ربط',
                $b . 'تیک بخیر' . $e => 'تیک تأیید',
                // محاوره → رسمی
                $b . 'باید که' . $e => 'باید', $b . 'می کنه' . $e => 'می‌کند', $b . 'می شه' . $e => 'می‌شود',
                $b . 'نشه' . $e => 'نمی‌شود', $b . 'دیگه' . $e => 'دیگر', $b . 'خیلی زیاد' . $e => 'بسیار',
                $b . 'یه' . $e => 'یک', $b . 'چیز دیگه' . $e => 'مورد دیگر', $b . 'مربوط به به' . $e => 'مربوط به',
                $b . 'در در' . $e => 'در', $b . 'از از' . $e => 'از', $b . 'به به' . $e => 'به',
                $b . 'و و' . $e => 'و', $b . 'که که' . $e => 'که', $b . 'را را' . $e => 'را',
                // ارجاع جغرافیایی
                $b . 'امرار' . $e => 'عبور',
            ];
        }
        $before = $text;
        foreach ($map as $pattern => $replacement) {
            $text = preg_replace('/' . $pattern . '/u', $replacement, $text) ?? $text;
        }
        if ($text !== $before) {
            $stats['spelling']++;
        }
        return $text;
    }

    /**
     * ✍️ علائم سجاوندی — فاصله‌گذاری استاندارد
     */
    private static function fixPunctuation(string $text, array &$stats): string
    {
        $before = $text;
        // حذف فاصله قبل از علائم
        $text = preg_replace('/ +([،؛!؟:])/u', '$1', $text) ?? $text;
        // فاصله بعد از ویرگول/نقطه‌ویرگول (به جز ارقام جداکننده هزارگان — لاتین و فارسی)
        $text = preg_replace('/([،؛])(?=[^\s\d۰-۹\)\]»"])/u', '$1 ', $text) ?? $text;
        // نقطه‌ویرگول لاتین → فارسی
        $text = str_replace(';', '؛', $text);
        // ویرگول لاتین → فارسی (فقط وقتی میان حروف فارسی است)
        $text = preg_replace('/(?<=[\p{Arabic}]) ?, ?(?=[\p{Arabic}])/u', '، ', $text) ?? $text;
        // علامت سؤال لاتین → فارسی (در متن فارسی)
        $text = preg_replace('/(?<=[\p{Arabic}])\?/u', '؟', $text) ?? $text;
        // تکرار علامت → یکی
        $text = preg_replace('/([!]){2,}/u', '$1', $text) ?? $text;
        $text = preg_replace('/([؟]){2,}/u', '$1', $text) ?? $text;
        // سه‌نقطه استاندارد
        $text = preg_replace('/\.{3,}/u', '…', $text) ?? $text;
        // گیومه مستقیم جفتی → گرب‌دستگاه فارسی (فقط زوج ساده)
        $text = preg_replace('/"([^"\n]{1,120})"/u', '«$1»', $text) ?? $text;
        // فاصله بعد از نقطه پایان جمله
        $text = preg_replace('/([.؛!؟…])(?=[\p{L}])/u', '$1 ', $text) ?? $text;

        if ($text !== $before) {
            $stats['punctuation']++;
        }
        return $text;
    }
}
