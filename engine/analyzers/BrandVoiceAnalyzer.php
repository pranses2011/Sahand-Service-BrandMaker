<?php
/**
 * 🎙️ تحلیل‌گر صدای برند — BrandVoiceAnalyzer v3.0
 * ================================================
 * پروفایل لحن و سبک نگارش برند را از متن‌های موجود استخراج
 * می‌کند تا محتوای جدید با همان صدا تولید شود.
 *
 * سنجه‌ها:
 *   📏 طول جمله میانگین (رسمی‌ها بلندتر می‌نویسند)
 *   🎩 رسمیت (نسبت نشانگرهای رسمی/غیررسمی از phrases.json)
 *   ✨ تنوع واژگان (Type-Token Ratio روی ریشه‌ها)
 *   🔧 فنی‌بودن (چگالی واژگان تخصصی صنعت)
 *   💬 محاوره‌بودن (واژه‌های گفتاری)
 *   📊 سطح خوانایی (مخاطب عمومی/متخصص)
 *
 * @package SahandBrandMaker\Engine
 * @version 3.0.0
 */
class BrandVoiceAnalyzer
{
    /** @var array|null کش نشانگرها */
    private static $markers = null;

    /**
     * 🎙️ استخراج پروفایل صدای برند از یک یا چند متن
     *
     * @param string|array $texts متن یا آرایه‌ای از متن‌های برند
     * @return array پروفایل صدای برند
     */
    public function profile($texts): array
    {
        if (is_array($texts)) {
            $texts = implode("\n\n", array_map(fn($t) => is_string($t) ? $t : '', $texts));
        }
        $text = trim(strip_tags((string)$texts));
        if ($text === '' || TextProcessor::wordCount($text) < 20) {
            throw new RuntimeException('متن کافی برای تحلیل صدای برند نیست (حداقل ۲۰ کلمه لازم است).');
        }

        $readability = TextProcessor::readability($text);
        $tokens = TextProcessor::tokenize($text);
        $tokenCount = max(1, count($tokens));

        /* ---------- ۱) 🎩 رسمیت ---------- */
        $formal = $this->countMarkers($text, 'formal');
        $informal = $this->countMarkers($text, 'informal');
        $formality = $this->formalityScore($formal, $informal, $readability['avg_sentence_length']);

        /* ---------- ۲) 🔧 فنی‌بودن ---------- */
        $technical = $this->countMarkers($text, 'technical');
        $technicalDensity = $technical / $tokenCount * 1000; // در هزار کلمه
        $technicalScore = (int)round(min(100, $technicalDensity * 12));

        /* ---------- ۳) 💬 محاوره ---------- */
        $conversational = $this->countMarkers($text, 'conversational');
        $convScore = (int)round(min(100, ($conversational / $tokenCount) * 800));

        /* ---------- ۴) 🎭 تیپ صدا ---------- */
        $persona = $this->persona($formality, $technicalScore, $convScore, $readability['score']);

        return [
            'word_count'          => $readability['word_count'],
            'avg_sentence_length' => $readability['avg_sentence_length'],
            'readability_score'   => $readability['score'],
            'lexical_diversity'   => $readability['lexical_diversity'],
            'formality'           => $formality,          // 0-100 (۱۰۰ = کاملاً رسمی)
            'technical'           => $technicalScore,     // 0-100
            'conversational'      => $convScore,          // 0-100
            'persona'             => $persona['label'],
            'persona_traits'      => $persona['traits'],
            'audience_level'      => $readability['score'] >= 70 ? 'عمومی'
                : ($readability['score'] >= 50 ? 'نیمه‌متخصص' : 'متخصص'),
            'markers'             => [
                'formal'          => $formal,
                'informal'        => $informal,
                'technical'       => $technical,
                'conversational'  => $conversational,
            ],
            'recommendations'     => $this->recommendations($persona['key'], $formality, $technicalScore),
        ];
    }

    /**
     * 🔍 سنجش انطباق یک متن جدید با پروفایل صدای برند
     *
     * @param string $content متن جدید
     * @param array  $profile پروفایل (خروجی متد profile)
     */
    public function compare(string $content, array $profile): array
    {
        $new = $this->profile($content);

        $diffs = [
            'avg_sentence_length' => abs($new['avg_sentence_length'] - $profile['avg_sentence_length']),
            'formality'           => abs($new['formality'] - $profile['formality']),
            'technical'           => abs($new['technical'] - $profile['technical']),
            'conversational'      => abs($new['conversational'] - $profile['conversational']),
            'readability_score'   => abs($new['readability_score'] - $profile['readability_score']),
        ];

        // انطباق کلی: فاصله وزنی معکوس (۰=ناسازگار، ۱۰۰=کاملاً هم‌صدا)
        $penalty = ($diffs['formality'] * 1.5 + $diffs['technical'] * 1.0
                + $diffs['conversational'] * 0.8 + min(30, $diffs['avg_sentence_length']) * 2.0
                + $diffs['readability_score'] * 0.7) / 6.0;
        $consistency = (int)round(max(0, 100 - $penalty));

        $advice = [];
        if ($diffs['formality'] > 20) {
            $advice[] = $new['formality'] > $profile['formality']
                ? 'لحن متن جدید رسمی‌تر از صدای برند است — از جملات کوتاه‌تر و عبارات طبیعی‌تر استفاده کنید.'
                : 'لحن متن جدید خودمانی‌تر از صدای برند است — از واژه‌های محاوره‌ای کم کنید.';
        }
        if ($diffs['avg_sentence_length'] > 5) {
            $advice[] = 'طول جمله‌ها با روال برند متفاوت است (میانگین برند: ' . $profile['avg_sentence_length'] . ' کلمه).';
        }
        if ($diffs['technical'] > 25) {
            $advice[] = $new['technical'] > $profile['technical']
                ? 'متن جدید بیش از حد فنی است — اصطلاحات تخصصی را ساده‌سازی کنید.'
                : 'متن جدید فنی‌تر شدن دارد — اصطلاحات دقیق صنعت را بیشتر به کار ببرید.';
        }

        return [
            'consistency'       => $consistency,
            'verdict'           => $consistency >= 80 ? 'کاملاً هم‌صدا ✅'
                : ($consistency >= 60 ? 'قابل قبول با تفاوت‌های جزئی' : 'ناهماهنگ با صدای برند ⚠️'),
            'new_content'       => $new,
            'differences'       => $diffs,
            'advice'            => $advice,
        ];
    }

    /* ==================================================
     * 🛠️ متدهای داخلی
     * ================================================== */

    /**
     * 🔤 شمارش نشانگرهای هر دسته در متن
     */
    private function countMarkers(string $text, string $category): int
    {
        $markers = $this->markers();
        $count = 0;
        $lower = mb_strtolower($text);
        foreach ($markers[$category] ?? [] as $marker) {
            $marker = trim($marker);
            if ($marker === '') {
                continue;
            }
            $pos = 0;
            while (($pos = mb_strpos($lower, $marker, $pos)) !== false) {
                $count++;
                $pos += mb_strlen($marker);
            }
        }
        return $count;
    }

    /**
     * 📚 نشانگرهای لحن — ترکیب phrases.json + قواعد داخلی
     */
    private function markers(): array
    {
        if (self::$markers !== null) {
            return self::$markers;
        }

        $phrases = TextProcessor::loadPhrases();

        self::$markers = [
            'formal' => array_merge(
                ['با احترام', 'جناب آقای', 'سرکار خانم', 'محترم', 'می‌باشد', 'می‌گردد', 'خواهد شد',
                 'لطفاً', 'ارائه می‌شود', 'در راستای', 'به منظور', 'حاضر', 'امور', 'جهت اطلاع',
                 'محترمانه', 'کاربران گرامی', 'همکاران', 'شرکت', 'مجموعه', 'خدمات‌رسانی', 'می‌توانید'],
                (array)($phrases['tone_markers']['formal'] ?? [])
            ),
            'informal' => array_merge(
                ['فردا صبح زود', 'راستش', 'خب', 'الان', 'بذار', 'می‌خوای', 'نمیشه', 'باشه', 'اوکیه'],
                (array)($phrases['tone_markers']['informal'] ?? [])
            ),
            'technical' => array_merge(
                ['کمپرسور', 'کاندنسر', 'اواپراتور', 'ترموستات', 'برد الکترونیکی', 'سنسور', 'فن', 'موتر',
                 'الکتروموتور', 'شیر برقی', 'پمپ تخلیه', 'گاز مبرد', 'R600a', 'R134a', 'اینورتر', 'دیفراست',
                 'تایمر', 'رله', 'خازن', 'المنت حرارتی', 'ولتاژ', 'آمپر', 'اهم', 'کیلووات', 'دریفت', 'کوپلینگ'],
                (array)($phrases['tone_markers']['technical'] ?? [])
            ),
            'conversational' => array_merge(
                ['شما می‌توانید', 'آیا می‌دانستید', 'در نظر بگیرید', 'تصور کنید', 'بگذارید', 'همانطور که می‌دانید',
                 'به راحتی', 'به سادگی', 'قدم به قدم', 'با هم', 'بررسی کنیم', 'نگاهی بیندازیم'],
                (array)($phrases['tone_markers']['conversational'] ?? [])
            ),
        ];

        return self::$markers;
    }

    /**
     * 🎩 امتیاز رسمیت (۰=خیلی خودمانی، ۱۰۰=کاملاً رسمی)
     */
    private function formalityScore(int $formal, int $informal, float $avgSentenceLength): int
    {
        $signal = $formal - $informal * 1.2;
        // جمله‌های بلندتر نشانه رسمیت (تا حدی)
        $lengthSignal = ($avgSentenceLength - 12) * 2.5;
        $raw = 50 + $signal * 8 + max(-25, min(25, $lengthSignal));
        return (int)round(max(0, min(100, $raw)));
    }

    /**
     * 🎭 تیپ صدای برند
     */
    private function persona(int $formality, int $technical, int $conversational, int $readability): array
    {
        $candidates = [
            'expert_mentor' => [
                'label' => 'متخصص راهنما 🎓',
                'traits' => ['فنی و دقیق', 'آموزشی', 'مستند'],
                'score' => $technical * 1.2 + ($readability >= 45 && $readability <= 75 ? 20 : 0) + $formality * 0.3,
            ],
            'trusted_advisor' => [
                'label' => 'مشاور قابل‌اعتماد 🤝',
                'traits' => ['رسمی و محترم', 'خدمات‌محور', 'خیاط‌شده برای مشتری'],
                'score' => $formality * 1.2 + $conversational * 0.5 + ($technical >= 20 && $technical <= 60 ? 15 : 0),
            ],
            'friendly_helper' => [
                'label' => 'دوست صمیمی 💬',
                'traits' => ['صمیمی', 'ساده و روان', 'نزدیک به مخاطب'],
                'score' => $conversational * 1.3 + (100 - $formality) * 0.8 + ($readability >= 65 ? 20 : 0),
            ],
            'technical_authority' => [
                'label' => 'مرجع تخصصی 📡',
                'traits' => ['بسیار فنی', 'دقیق', 'برای متخصصان'],
                'score' => $technical * 1.5 + (100 - $readability) * 0.4,
            ],
        ];

        uasort($candidates, fn($a, $b) => $b['score'] <=> $a['score']);
        $key = array_key_first($candidates);
        return ['key' => $key] + $candidates[$key];
    }

    /**
     * 💡 توصیه‌های تولید محتوا بر اساس تیپ صدا
     */
    private function recommendations(string $persona, int $formality, int $technical): array
    {
        $map = [
            'expert_mentor' => [
                'در هر بخش یک نکته فنی قابل‌اندازه‌گیری (ولتاژ، دما، مدت) بیاورید.',
                'از جدول مقایسه و لیست عیب‌یابی مرحله‌ای استفاده کنید.',
                'اصطلاح فنی را بار اول با توضیح ساده معرفی کنید.',
            ],
            'trusted_advisor' => [
                'جمله‌ها را با «شما» و مخاطب مستقیم بنویسید.',
                'در پایان هر بخش، گام بعدی روشن برای مشتری تعریف کنید.',
                'از اعداد و تضمین‌های شفاف (زمان، هزینه پایه) بهره ببرید.',
            ],
            'friendly_helper' => [
                'سؤال‌های مستقیم از مخاطب بپرسید («آیا تا به حال...؟»).',
                'مثال‌های روزمره و تشبیه‌های ساده به کار ببرید.',
                'از واژه‌های تخصصی سنگین پرهیز کنید یا ساده توضیح دهید.',
            ],
            'technical_authority' => [
                'به استانداردها و کدهای صنعتی ارجاع دهید (R600a، IEC و...).',
                'داده‌های عددی و واحدهای دقیق ارائه کنید.',
                'مخاطب را متخصص فرض کنید و مقدمات را طولانی نکنید.',
            ],
        ];
        $recs = $map[$persona] ?? $map['trusted_advisor'];
        if ($formality >= 75) {
            $recs[] = 'لحن رسمی برند را حفظ کنید — از شوخی و اغراق پرهیز شود.';
        } elseif ($formality <= 35) {
            $recs[] = 'سبک خودمانی برند را حفظ کنید اما در بخش‌های ایمنی، جدی و دقیق بنویسید.';
        }
        return $recs;
    }
}
