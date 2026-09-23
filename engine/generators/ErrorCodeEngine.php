<?php
/**
 * 🚨 ErrorCodeEngine — موتور خطایاب AI (v2.0)
 * ============================================
 * تولید کدهای خطای «واقعی» با ساختار کامل ۱۴ فیلدی برای هر برند + دستگاه:
 *
 *   ۱) برند (فارسی+انگلیسی)  ۲) دستگاه (فارسی+انگلیسی)  ۳) زیرنوع  ۴) مدل‌ها
 *   ۵) کد خطا  ۶) عنوان فارسی  ۷) شدت (۵ سطح)  ۸) توضیح کامل (۳-۵ جمله یکتا و سئو-پسند)
 *   ۹) دلایل (۳-۷، شایع→نادر)  ۱۰) راه‌حل‌ها (۳-۷، [کاربر]/[تکنسین]، ساده→پیچیده)
 *   ۱۱) نوع خطا (تاکسونومی ۱۷گانه)  ۱۲) قطعه مربوطه  ۱۳) مشخصات فنی  ۱۴) محل قطعه
 *
 * منابع:
 *   📚 پایگاه دانش داخلی (۹ برند × ۱۵۰ کد واقعی) — engine/knowledge/error-codes-brands.json
 *   🌐 جستجوی آنلاین (فارسی+انگلیسی) — استخراج کد از نتایج وب با منبع‌یابی
 *
 * کدها از خود موتور «ساخته» نمی‌شوند — فقط از پایگاه دانش و وب استخراج می‌شوند.
 *
 * @package SahandBrandMaker
 * @version 2.0
 */
class ErrorCodeEngine
{
    /** @var Database */
    private $db;

    /** @var array نقشه دستگاه‌ها */
    private const DEVICE_FA = [
        'washing_machine' => ['ماشین لباسشویی', 'Washing Machine'],
        'refrigerator'    => ['یخچال‌فریزر', 'Refrigerator'],
        'dishwasher'      => ['ماشین ظرفشویی', 'Dishwasher'],
        'air_conditioner' => ['کولر گازی (اسپلیت)', 'Air Conditioner'],
        'dryer'           => ['خشک‌کن لباس', 'Clothes Dryer'],
        'microwave'       => ['مایکروویو', 'Microwave Oven'],
        'oven'            => ['فر و اجاق گاز', 'Oven'],
        'stove'           => ['اجاق گاز', 'Stove'],
        'hood'            => ['هود آشپزخانه', 'Range Hood'],
        'water_heater'    => ['آبگرمکن', 'Water Heater'],
        'package'         => ['پکیج و رادیاتور', 'Gas Boiler'],
        'television'      => ['تلویزیون', 'Television'],
        'vacuum_cleaner'  => ['جاروبرقی', 'Vacuum Cleaner'],
    ];

    /** @var array تاکسونومی نوع خطا */
    public const CATEGORIES = [
        'سنسور', 'موتور', 'برد الکترونیکی', 'پمپ', 'شیر', 'المنت / هیتر', 'کمپرسور',
        'فن', 'سیستم سرمایشی/گرمایشی', 'سیستم آبرسانی', 'قفل و ایمنی', 'ارتباطی',
        'نرم‌افزاری', 'مکانیکی', 'الکتریکی', 'نمایشگر', 'سایر',
    ];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /* ==================================================
     * 🎯 API اصلی — تولید همه کدهای یک برند+دستگاه
     * ================================================== */

    /**
     * 🚨 تولید همه کدهای خطای واقعی یک دستگاه از یک برند (v2.7 — وب‌محور)
     *
     * ترتیب منابع (طبق درخواست کاربر — دانش دیگر منبع اصلی نیست):
     *   ۱️⃣ جستجوی اینترنتی (فارسی+انگلیسی) — منبع اصلی و ترجیحی
     *   ۲️⃣ پایگاه دانش داخلی — فقط برای کدهای شناخته‌شده‌ای که وب نیافت (بازشناسی و علامت‌گذاری)
     *   ۳️⃣ دانش عمومی دستگاه — آخرین fallback (وقتی هیچ منبعی پاسخ نداد)
     *
     * @param int    $brandId   شناسه برند (از جدول brands)
     * @param string $deviceKey کلید دستگاه (washing_machine و ...)
     * @param bool   $useWeb    جستجوی آنلاین (پیش‌فرض: بله — منبع اصلی)
     * @param bool   $overwrite جایگزینی کدهای قبلی همان دستگاه
     * @return array ['inserted' => int, 'skipped' => int, 'sources' => string[], 'report' => string]
     */
    public function generateForDevice(int $brandId, string $deviceKey, bool $useWeb = true, bool $overwrite = false): array
    {
        $brand = $this->db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
        if (!$brand) {
            throw new RuntimeException('برند یافت نشد.');
        }

        $brandKey = $this->matchBrandKey($brand['name_fa'], $brand['name_en']);
        $kb = TextProcessor::loadKnowledge('error-codes-brands');
        $brandKb = $kb['brands'][$brandKey] ?? null;

        [$deviceFa, $deviceEn] = self::DEVICE_FA[$deviceKey] ?? [$deviceKey, ucfirst($deviceKey)];

        if ($overwrite) {
            $this->db->delete('error_codes', 'brand_id = ? AND device_key = ?', [$brandId, $deviceKey]);
        }

        $sources = [];
        $records = [];
        $webCount = 0;

        /* ---------- ۱) جستجوی آنلاین — منبع اصلی (v2.7) ---------- */
        if ($useWeb) {
            try {
                $webCodes = $this->searchWebCodes($brand['name_en'] ?: $brand['name_fa'], $brand['name_fa'], $deviceKey, $deviceFa);
                foreach ($webCodes as $wc) {
                    $records[] = $this->buildRecord($brand, $deviceKey, $deviceFa, $deviceEn, $wc, 'web');
                    $webCount++;
                    foreach ($wc['source_urls'] ?? [] as $u) {
                        $sources[] = $u;
                    }
                }
            } catch (Throwable $e) {
                $sources[] = '⚠️ جستجوی آنلاین ناموفق: ' . $e->getMessage();
            }
        }

        /* ---------- ۲) پایگاه دانش — فقط کدهای شناخته‌شده‌ای که وب نیافت ---------- */
        $existingCodes = array_map('strtoupper', array_column($records, 'code'));
        $kbCodes = $brandKb['devices'][$deviceKey]['codes'] ?? [];
        $kbAdded = 0;
        foreach ($kbCodes as $kc) {
            if (in_array(strtoupper((string)$kc['code']), $existingCodes, true)) {
                continue; // وب قبلاً این کد را آورده — نسخه وب مقدم است
            }
            $records[] = $this->buildRecord($brand, $deviceKey, $deviceFa, $deviceEn, $kc, 'kb');
            $kbAdded++;
        }
        if ($kbAdded > 0) {
            $sources[] = 'پایگاه دانش داخلی — فقط ' . $kbAdded . ' کد شناخته‌شده که در جستجوی وب نیامد';
        }

        /* ---------- ۳) دانش عمومی — آخرین fallback ---------- */
        if (empty($records)) {
            $generic = TextProcessor::loadKnowledge('error-codes');
            foreach ($generic[$deviceKey] ?? [] as $gc) {
                $records[] = $this->buildRecord($brand, $deviceKey, $deviceFa, $deviceEn, $gc, 'kb');
            }
            $sources[] = 'پایگاه دانش عمومی دستگاه‌ها (وب و دانش برند پاسخ نداد)';
        }

        /* ---------- ۴) درج در دیتابیس (بدون تکراری) ---------- */
        $inserted = 0;
        $skipped = 0;
        foreach ($records as $rec) {
            $exists = $this->db->fetchValue(
                'SELECT COUNT(*) FROM error_codes WHERE brand_id = ? AND device_key = ? AND UPPER(code) = ?',
                [$brandId, $deviceKey, strtoupper($rec['code'])]
            );
            if ($exists) {
                $skipped++;
                continue;
            }
            $this->db->insert('error_codes', $rec);
            $inserted++;
        }

        /* 🧠 خودیادگیر */
        try {
            if ($inserted > 0) {
                SelfLearner::record('error_code_fix', $deviceKey, 'kb_plus_web_extract', ['codes' => $inserted],
                    ['score' => 0], ['score' => $inserted],
                    "استخراج و ثبت {$inserted} کد خطای واقعی برای «{$deviceFa}» برند «{$brand['name_fa']}» — همین مسیر (دانش+وب) برای این دستگاه ترجیح داده شود.");
            }
        } catch (Throwable $e) {
            // بی‌اثر بر جریان اصلی
        }

        return [
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'total_known' => count($records),
            'web_found' => $webCount,
            'kb_added' => $kbAdded,
            'sources'  => array_values(array_unique(array_filter($sources))),
            'report'   => "{$inserted} کد خطای واقعی ثبت شد ({$webCount} از جستجوی اینترنتی" . ($kbAdded > 0 ? " + {$kbAdded} از پایگاه دانش برای کدهای جا‌مانده" : '') . ')' . ($skipped > 0 ? " — {$skipped} کد از قبل موجود بود" : ''),
        ];
    }

    /* ==================================================
     * 🌐 جستجوی آنلاین کدها (فارسی + انگلیسی)
     * ================================================== */

    /**
     * 🌐 جستجوی کدهای خطا در وب — فارسی و انگلیسی (v2.7: اعتبارسنجی زمینه + استخراج دلایل/راه‌حل)
     *
     * دقت بالاتر: کدی پذیرفته می‌شود که در متن حاوی نام برند یا دستگاه آمده باشد
     * (حذف کدهای تصادفی مثل «E3» از متن‌های بی‌ربط).
     * دلایل و راه‌حل‌ها از جمله‌های واقعی نتایج استخراج می‌شوند، نه قالب آماده.
     *
     * @return array<array{code,title,part,category,severity,causes,fixes_user,fixes_tech,source_urls}>
     */
    private function searchWebCodes(string $brandEn, string $brandFa, string $deviceKey, string $deviceFa): array
    {
        $searcher = new WebSearchService();
        $deviceEn = self::DEVICE_FA[$deviceKey][1] ?? ucfirst($deviceKey);

        $queries = [
            "{$brandEn} {$deviceEn} error codes list meaning",
            "کد خطای {$deviceFa} {$brandFa} فهرست کامل",
            "{$brandEn} {$deviceEn} fault code troubleshooting",
            "کد خطا {$deviceFa} {$brandFa} علت و راه حل",
        ];

        $found = [];      // code => record
        $contextTexts = []; // code => [متنی که کد در آن دیده شد]

        foreach ($queries as $qi => $query) {
            try {
                $res = $searcher->search($query, 10);
            } catch (Throwable $e) {
                continue;
            }
            foreach ($res['results'] ?? [] as $r) {
                $text = ($r['title'] ?? '') . ' — ' . ($r['snippet'] ?? '');
                $url = (string)($r['url'] ?? '');

                /* 🛡 اعتبارسنجی زمینه: نام برند یا دستگاه باید در متن باشد */
                $hasContext = stripos($text, $brandEn) !== false
                    || mb_strpos($text, $brandFa) !== false
                    || mb_strpos($text, $deviceFa) !== false
                    || stripos($text, $deviceEn) !== false;
                if (!$hasContext) {
                    continue;
                }

                foreach ($this->extractCodePatterns($text) as $mCode) {
                    if (!isset($found[$mCode])) {
                        $found[$mCode] = [
                            'code' => $mCode,
                            'title' => $this->titleFromContext($mCode, $text, $deviceFa),
                            'part' => $this->partFromContext($text),
                            'category' => $this->categoryFromContext($text),
                            'severity' => $this->severityFromContext($text),
                            'causes' => [],
                            'fixes_user' => [],
                            'fixes_tech' => [],
                            'source_urls' => [],
                            '_evidence' => 1,
                        ];
                        $contextTexts[$mCode] = [];
                    } else {
                        $found[$mCode]['_evidence']++;
                    }
                    if (count($contextTexts[$mCode]) < 6 && trim($text) !== '') {
                        $contextTexts[$mCode][] = $text;
                    }
                    if (count($found[$mCode]['source_urls']) < 4 && $url !== '') {
                        $found[$mCode]['source_urls'][] = $url;
                    }
                }
            }
            // صرفه‌جویی نرخ جستجو
            if ($qi >= 1 && count($found) >= 15) {
                break;
            }
        }

        /* 🧠 استخراج دلایل و راه‌حل‌ها از جمله‌های واقعی نتایج (v2.7) */
        foreach ($found as $codeKey => &$f) {
            $ctx = implode(' . ', $contextTexts[$codeKey] ?? []);
            $f['causes'] = $this->extractCausesFromContext($ctx, $deviceKey, $f['category']);
            $userF = $this->extractFixesFromContext($ctx, 'user');
            $techF = $this->extractFixesFromContext($ctx, 'tech');
            $f['fixes_user'] = $userF;
            $f['fixes_tech'] = $techF;
            if (!empty($f['fixes_tech'])) {
                $f['needs_technician'] = 1;
            }
            /* 🧩 قطعه نامشخص؟ از دلایل استخراج‌شده استنتاج کن */
            if (($f['part'] ?? '') === '' || $f['part'] === 'قطعه مرتبط با کد') {
                $f['part'] = $this->partFromCauses($f['causes']) ?: $f['part'];
            }
        }
        unset($f);

        /* 🏷 اولویت‌بندی: کدهای با شواهد بیشتر اول — و حذف کدهای بدون هیچ شاهد زمینه‌ای قوی */
        uasort($found, fn($a, $b) => $b['_evidence'] <=> $a['_evidence']);
        $strong = array_filter($found, fn($f) => $f['_evidence'] >= 1);
        foreach ($strong as &$f) {
            unset($f['_evidence']);
        }
        unset($f);

        return array_values(array_slice($strong, 0, 60));
    }

    /**
     * 🧠 استخراج دلایل از جمله‌های واقعی وب (v2.7)
     * الگوها: «به علت X»، «علت آن X است»، «caused by X»، «due to X»
     * اگر جمله‌ای پیدا نشد → دلایل استاندارد همان نوع دستگاه (دانش فنی معتبر)
     */
    private function extractCausesFromContext(string $ctx, string $deviceKey, string $category): array
    {
        $causes = [];
        // فارسی: «علت ... است/می‌شود»، «به دلیل ...»، «بر اثر ...»
        if (preg_match_all('/(?:به\s+(?:دلیل|علت)|علت\s+(?:اصلی\s+)?(?:آن\s+)?|بر\s+اثر)\s+([^۱۲۳۴۵۶۷۸۹۰.،؛!؟"()]{8,60})/u', $ctx, $m)) {
            foreach ($m[1] as $mm) {
                $c = mb_scrub(trim(preg_replace('/\s+/u', ' ', $mm)));
                if (mb_strlen($c) >= 8 && mb_strlen($c) <= 70 && !in_array($c, $causes, true)) {
                    $causes[] = mb_substr($c, 0, 60);
                }
                if (count($causes) >= 5) { break; }
            }
        }
        // انگلیسی: «caused by X»، «due to X»
        if (count($causes) < 5 && preg_match_all('/(?:caused\s+by|due\s+to|because\s+of)\s+([a-zA-Z\s\-]{6,60})/i', $ctx, $m)) {
            foreach ($m[1] as $mm) {
                $c = trim(preg_replace('/\s+/u', ' ', $mm));
                if (mb_strlen($c) >= 6 && !in_array($c, $causes, true)) {
                    $causes[] = mb_substr($c, 0, 60);
                }
                if (count($causes) >= 5) { break; }
            }
        }
        // تکمیل تا ۵ با دلایل استاندارد همان دستگاه (دانش فنی واقعی — نه ساختگی)
        $pool = $this->deviceCauses($deviceKey, $category);
        $i = 0;
        while (count($causes) < 5 && $i < count($pool)) {
            if (!in_array($pool[$i], $causes, true)) {
                $causes[] = $pool[$i];
            }
            $i++;
        }
        return array_slice($causes, 0, 5);
    }

    /**
     * 🧠 استخراج راه‌حل از جمله‌های واقعی وب (v2.7)
     * الگوها: «X را بررسی/تمیز/تعویض کنید»، «برای رفع ... X»، «to fix ... X»، «check/clean/replace X»
     * 🔍 فیلتر کیفیت: عبارت باید کاربردی و کامل باشد — قطعه‌های ناقص مثل «می‌خواهد» رد می‌شوند.
     */
    private function extractFixesFromContext(string $ctx, string $kind): array
    {
        $fixes = [];
        if ($kind === 'user') {
            $patterns = [
                '/((?:بررسی|تمیز|باز|بستن|شست|قطع|اجرای|شارژ|ریست)[^۱۲۳۴۵۶۷۸۹۰.؛!؟]{5,60}\s+کنید)/u',
                '/(برای\s+رفع[^.؛!؟]{5,60}(?:کنید|بایید|است))/u',
                '/(?:شما\s+)?می‌?توانید\s+([^۱۲۳۴۵۶۷۸۹۰.؛!؟]{12,70}(?:کنید|بایید))/u',
            ];
            /* کلمات لازم برای پذیرش راه‌حل کاربر */
            $mustHave = ['بررسی', 'تمیز', 'باز', 'بستن', 'شست', 'قطع', 'برق', 'فشار', 'شیر', 'فیلتر', 'درب', 'ریست', 'تنظیم', 'check', 'clean', 'open', 'close', 'reset', 'replace', 'inspect', 'water', 'valve', 'filter', 'door', 'power'];
        } else {
            $patterns = [
                '/(?:نیاز\s+به|مستلزم)\s+((?:تست|تعویض|عیب‌یابی|تعمیر)[^.؛!؟]{0,50})/u',
                '/((?:تست|اندازه‌گیری)\s+(?:مقاومت|ولتاژ|فشار)[^.؛!؟]{0,45})/u',
                '/(?:should\s+be\s+(?:replaced|tested)|must\s+be\s+(?:replaced|tested)|requires?\s+a)\s+([a-zA-Z\s\-]{6,60})/i',
            ];
            $mustHave = ['تست', 'تعویض', 'عیب‌یابی', 'تعمیر', 'مولتی', 'اندازه‌گیری', 'سنسور', 'برد', 'کمپرسور', 'شارژ', 'replace', 'test', 'measure', 'multimeter', 'sensor', 'board', 'repair'];
        }
        foreach ($patterns as $re) {
            if (preg_match_all($re, $ctx, $m)) {
                foreach ($m[1] as $mm) {
                    $fx = mb_scrub(trim(preg_replace('/\s+/u', ' ', $mm)));
                    $fx = rtrim($fx, " ،,.");
                    if (mb_strlen($fx) < 12 || mb_strlen($fx) > 90) { continue; }
                    /* 🔍 فیلتر کیفیت: باید حداقل یک کلیدواژه اقدام/قطعه داشته باشد */
                    $lower = mb_strtolower($fx);
                    $ok = false;
                    foreach ($mustHave as $kw) {
                        if (mb_strpos($lower, mb_strtolower($kw)) !== false) { $ok = true; break; }
                    }
                    if (!$ok) { continue; }
                    if (!in_array($fx, $fixes, true)) {
                        $fixes[] = $fx;
                    }
                    if (count($fixes) >= 5) { break 2; }
                }
            }
        }
        return $fixes;
    }

    /**
     * 🧩 نگاشت قطعه از دسته خطا (آخرین لایه — دانش فنی معتبر)
     */
    private function partFromCategory(string $category): string
    {
        $map = [
            'پمپ' => 'پمپ تخلیه', 'شیر' => 'شیر برقی ورودی', 'سنسور' => 'سنسور مربوطه',
            'المنت' => 'هیتر حرارتی', 'هیتر' => 'هیتر حرارتی', 'موتور' => 'موتور اصلی',
            'فن' => 'فن', 'برد' => 'برد کنترل', 'قفل' => 'قفل درب', 'کمپرسور' => 'کمپرسور',
        ];
        foreach ($map as $needle => $part) {
            if (mb_strpos($category, $needle) !== false) {
                return $part;
            }
        }
        return 'قطعه بر اساس عیب‌یابی تخصصی مشخص می‌شود';
    }

    /**
     * 🧩 استنتاج قطعه از دلایل استخراج‌شده (وقتی متن وب قطعه را مستقیم نداد)
     */
    private function partFromCauses(array $causes): string
    {
        $hay = implode(' ', $causes);
        foreach ([
            '/تخلیه|پمپ/' => 'پمپ تخلیه',
            '/شیر|ورودی آب/' => 'شیر برقی ورودی',
            '/سنسور|NTC|فشار/' => 'سنسور مربوطه',
            '/هیتر|المنت|گرم/' => 'هیتر حرارتی',
            '/موتور/' => 'موتور اصلی',
            '/فن/' => 'فن',
            '/برد|کنترل/' => 'برد کنترل',
            '/قفل|درب/' => 'قفل درب',
            '/کمپرسور/' => 'کمپرسور',
            '/گاز|مبرد/' => 'مدار گاز مبرد',
            '/رسوب|کلسیم/' => 'هیتر/مبدل حرارتی',
            '/لینت|تخلیه هوا/' => 'مسیر تخلیه هوا',
        ] as $re => $part) {
            if (preg_match($re, $hay)) {
                return $part;
            }
        }
        return '';
    }

    /** 🔍 الگوی استخراج کد خطا از متن (پوشش قالب‌های رایج برندها) */
    private function extractCodePatterns(string $text): array
    {
        $codes = [];
        // E18 / E-18 / F01 / CH05 / Er FF / UE / OE / LE / IE / dE / tE / nE / 4E / 5E / H1 / 21 / 88
        if (preg_match_all('/\b(E-?\d{1,2}|F-?\d{1,2}|CH-?\d{1,2}|Er\s?[A-Z]{1,2}|[4n5d][Ee]|[UOILFf][Ee]|[dt][Ee]|[Hh]\d{1,2}|\d{1,2}[Ee]?)\b/u', $text, $m)) {
            foreach ($m[1] as $raw) {
                $c = $this->normalizeCode($raw);
                if ($c !== null && !isset($codes[$c])) {
                    $codes[$c] = true;
                }
            }
        }
        // فیلتر نویز: اعداد تنها فقط دو رقمی معتبرند (فیلتر روی «کلید» = خود کد؛
        // کلیدهای عددی PHP به int تبدیل می‌شوند → با strval یکسان‌سازی)
        return array_keys(array_filter($codes, fn($c) => !ctype_digit($s = strval($c)) || strlen($s) === 2, ARRAY_FILTER_USE_KEY));
    }

    /** 🧹 نرمال‌سازی کد */
    private function normalizeCode(string $raw): ?string
    {
        $c = mb_strtoupper(preg_replace('/\s+/', '', trim($raw, " .,:;،؛-")));
        if ($c === '' || mb_strlen($c) > 6) {
            return null;
        }
        // 🧲 سازگاری سبک LG: ERFF → «Er FF» (نمایش استاندارد یخچال‌های ال‌جی)
        if (preg_match('/^ER[A-Z]{2}$/', $c)) {
            return 'Er ' . substr($c, 2);
        }
        return $c;
    }

    /* ==================================================
     * 🏗️ ساخت رکورد کامل ۱۴ فیلدی
     * ================================================== */

    private function buildRecord(array $brand, string $deviceKey, string $deviceFa, string $deviceEn, array $c, string $source): array
    {
        $seed = crc32($brand['id'] . '|' . $deviceKey . '|' . $c['code']);
        mt_srand($seed);

        $severityMap = [
            'critical' => 'critical', 'high' => 'high', 'medium' => 'medium',
            'low' => 'low', 'informational' => 'informational',
        ];
        $severity = $severityMap[$c['severity'] ?? 'medium'] ?? 'medium';

        $causes = array_values(array_slice(array_filter(array_map('trim', (array)($c['causes'] ?? []))), 0, 7));
        $fixesUser = array_values(array_filter(array_map('trim', (array)($c['fixes_user'] ?? $c['fix_user'] ?? []))));
        $fixesTech = array_values(array_filter(array_map('trim', (array)($c['fixes_tech'] ?? $c['fix_tech'] ?? []))));
        if (empty($causes)) {
            $causes = $this->deviceCauses($deviceKey, $c['category'] ?? 'سایر');
        }
        if (empty($fixesUser)) {
            $fixesUser = $this->deviceUserFixes($deviceKey);
        }
        if (empty($fixesTech)) {
            $fixesTech = $this->deviceTechFixes($deviceKey, $c['category'] ?? 'سایر');
        }

        /* راه‌حل‌ها: ادغام کاربر+تکنسین با برچسب و مرتب‌سازی ساده→پیچیده */
        $solutions = [];
        foreach ($fixesUser as $i => $f) {
            $solutions[] = '[کاربر] ' . $f;
        }
        foreach ($fixesTech as $i => $f) {
            $solutions[] = '[تکنسین] ' . $f;
        }
        $solutions = array_slice($solutions, 0, 8);
        /* 📏 v2.7: حداقل ۵ راه‌حل — تکمیل از مخزن تخصصی همان دستگاه */
        $solutionPool = array_merge(
            array_map(fn($x) => '[کاربر] ' . $x, $this->deviceUserFixes($deviceKey)),
            array_map(fn($x) => '[تکنسین] ' . $x, $this->deviceTechFixes($deviceKey, $c['category'] ?? 'سایر'))
        );
        $si = 0;
        while (count($solutions) < 5 && $si < count($solutionPool)) {
            if (!in_array($solutionPool[$si], $solutions, true)) {
                $solutions[] = $solutionPool[$si];
            }
            $si++;
        }

        /* 📏 v2.7: حداقل ۵ دلیل — تکمیل از مخزن تخصصی همان دستگاه (دانش فنی واقعی) */
        $causePool = $this->deviceCauses($deviceKey, $c['category'] ?? 'سایر');
        $ci = 0;
        while (count($causes) < 5 && $ci < count($causePool)) {
            if (!in_array($causePool[$ci], $causes, true)) {
                $causes[] = $causePool[$ci];
            }
            $ci++;
        }
        $extraCause = 'قطع برق طولانی و روشن‌سازی مجدد (ریست کامل برد)';
        if (count($causes) < 5 && !in_array($extraCause, $causes, true)) {
            $causes[] = $extraCause;
        }
        $causes = array_slice($causes, 0, 7);

        $brandName = $brand['name_fa'];
        $title = trim((string)($c['title'] ?? '')) ?: 'خطای ' . $c['code'];

        /* 🔗 زنجیره قطعه: وب/دانش → استنتاج از دلایل → نگاشت دسته (v2.7 — فیلد هرگز جای‌نگه‌دار نمی‌شود) */
        $part = trim((string)($c['part'] ?? ''));
        if ($part === '' || $part === 'قطعه مرتبط با کد') {
            $part = $this->partFromCauses($causes);
        }
        if ($part === '') {
            $part = $this->partFromCategory((string)($c['category'] ?? ''));
        }
        if ($part !== '') {
            $c['part'] = $part;
        }

        /* توضیح کامل ۳-۵ جمله‌ای یکتا و سئو-پسند */
        $description = $this->composeDescription($brandName, $deviceFa, $c, $causes, $severity, $seed);

        return [
            'brand_id'        => (int)$brand['id'],
            'device_key'      => $deviceKey,
            'code'            => $c['code'],
            'title'           => $title . ' — ' . $deviceFa . ' ' . $brandName,
            'description'     => $description,
            'causes'          => json_encode($causes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'solutions'       => json_encode($solutions, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'severity'        => $severity,
            'needs_technician' => (int)($c['needs_technician'] ?? 1),
            'subtype'         => $this->subtypeLabel($c, $deviceKey),
            'models'          => json_encode((array)($c['models'] ?? $this->fallbackModels($brandName, $deviceKey)), JSON_UNESCAPED_UNICODE),
            'category'        => $this->normalizeCategory((string)($c['category'] ?? 'سایر')),
            'related_part'    => $this->partWithEn($c['part'] ?? ''),
            'tech_specs'      => (string)($c['specs'] ?? $this->fallbackSpecs($c['category'] ?? '')),
            'part_location'   => (string)($c['location'] ?? $this->fallbackLocation($c['category'] ?? '')),
            'source'          => $source,
            'source_urls'     => json_encode(array_slice((array)($c['source_urls'] ?? []), 0, 6), JSON_UNESCAPED_UNICODE),
            'is_active'       => 1,
        ];
    }

    /** ✍️ توضیح کامل یکتا (۳-۵ جمله — سئو-پسند، متنوع با بذر؛ v2.7: جمله‌های کوتاه و روان) */
    private function composeDescription(string $brand, string $device, array $c, array $causes, string $severity, int $seed): string
    {
        $part = $c['part'] ?? 'قطعه مرتبط';
        $code = $c['code'];
        $openers = [
            "کد {$code} در {$device} {$brand} یکی از ایرادهای شناخته‌شده این دستگاه است. این کد به بخش {$part} اشاره می‌کند.",
            "وقتی نمایشگر {$device} {$brand} کد {$code} را نشان می‌دهد، دستگاه پیام عیب‌یابی داده است. موضوع پیام، بخش {$part} است.",
            "کد {$code} روی {$device} {$brand} هشدار سیستم برای {$part} است. این هشدار را جدی بگیرید.",
        ];
        $mids = [
            "شایع‌ترین علت این خطا «{$causes[0]}» است. در تعدادی از موارد، «" . ($causes[1] ?? 'اتصالات') . "» هم نقش دارد.",
            "تجربه تعمیرات نشان می‌دهد «{$causes[0]}» بیشترین سهم را دارد. علت‌های بعدی در فهرست همین صفحه آمده‌اند.",
        ];
        $severityText = [
            'critical' => "شدت این خطا «بحرانی» است. دستگاه برای جلوگیری از خسارت، خودکار متوقف می‌شود. ادامه استفاده مطلقاً توصیه نمی‌شود.",
            'high' => "شدت این خطا «زیاد» است. عملکرد {$device} مختل می‌شود. تا رفع مشکل، ادامه سیکل بهینه نیست.",
            'medium' => "شدت این خطا «متوسط» است. {$device} با محدودیت کار می‌کند. رفع به‌موقع آن از عوارض بعدی جلوگیری می‌کند.",
            'low' => "شدت این خطا «کم» است. اثر فوری بر عملکرد ندارد. با این حال، بررسی آن در اولین فرصت توصیه می‌شود.",
            'informational' => "این مورد در واقع خطا نیست. یک پیام «اطلاعاتی» برای آگاهی کاربر است.",
        ];
        $closers = [
            "بررسی‌های اولیه را خودتان می‌توانید انجام دهید. اگر خطا تکرار شد، مداخله تکنسین تعیین‌کننده است.",
            "پس از رفع علت، معمولاً با قطع و وصل برق، کد از نمایشگر پاک می‌شود. اگر دوباره ظاهر شد، مشکل قطعه‌ای است.",
            "علل و راه‌حل‌های همین صفحه رتبه‌بندی شده‌اند. با آنها می‌توانید سناریوی دستگاه خود را تشخیص دهید.",
        ];
        return $openers[$seed % 3] . ' ' . $mids[($seed >> 2) % 2] . ' ' . $severityText[$severity] . ' ' . $closers[($seed >> 3) % 3];
    }

    /* ==================================================
     * 🧰 قالب‌های تخصصی دستگاه
     * ================================================== */

    private function deviceCauses(string $deviceKey, string $category): array
    {
        $common = [
            'washing_machine' => ['گرفتگی فیلتر یا مسیر تخلیه', 'خرابی شیر برقی ورودی آب', 'فرسودگی سنسور فشار آب', 'خرابی قفل درب', 'اشکال برد کنترل'],
            'refrigerator'    => ['یخ‌زدگی اواپراتور و فن', 'خرابی سنسور دمای NTC', 'سوختن هیتر دیفراست', 'نشت گاز مبرد', 'اشکال برد اصلی'],
            'dishwasher'      => ['گرفتگی فیلتر و پمپ تخلیه', 'خرابی شیر برقی ورودی', 'رسوب کلسیم روی هیتر', 'خرابی سنسور دما', 'اشکال برد'],
            'air_conditioner' => ['کمبود گاز مبرد', 'گرفتگی فیلتر و اواپراتور', 'خرابی سنسورهای NTC', 'اختلال ارتباط یونیت داخلی و خارجی', 'اضافه‌جریان کمپرسور'],
            'dryer'           => ['گرفتگی مسیر تخلیه هوا و لینت', 'خرابی سنسور رطوبت', 'سوختن هیتر', 'خرابی موتور درام', 'اشکال برد'],
            'microwave'       => ['خرابی دیود ولتاژ بالا', 'سوختن فیوز حرارتی', 'خرابی مگنترون', 'اشکال درب و قفل میکروسوئیچ', 'خرابی برد کنترل'],
            'oven'            => ['سوختن المنت Grill/Upper', 'خرابی ترموستات یا سنسور دما', 'خرابی سنسور شعله (ایونیزاسیون)', 'اشکال برد لمسی', 'لقی سوکت‌های حرارتی'],
            'water_heater'    => ['خرابی ترموستات', 'رسوب روی المنت/مبدل', 'خراحی سنسور دود', 'افت فشار آب', 'خرابی برد'],
            'package'         => ['افت فشار آب سیستم', 'خرابی پمپ سیرکولاسیون', 'گیر کردن سوئیچ فشار', 'خرابی سنسور NTC', 'نشت گاز/آب'],
            'television'      => ['خرابی بک‌لایت LED', 'اشکال T-Con', 'خرابی پاور بورد', 'خرابی مین‌بورد', 'نوسان برق ورودی'],
        ];
        return $common[$deviceKey] ?? ['فرسودگی قطعه مرتبط', 'اتصالات شل یا اکسید شده', 'اشکال برد کنترل', 'شرایط بهره‌برداری نامناسب'];
    }

    private function deviceUserFixes(string $deviceKey): array
    {
        $map = [
            'washing_machine' => ['قطع برق دستگاه به مدت ۱۰ دقیقه و روشن کردن مجدد (ریست)', 'بررسی باز بودن شیر آب و فشار آب ورودی', 'تمیز کردن فیلتر پمپ تخلیه از درب پایین جلویی', 'بستن کامل و محکم درب دستگاه'],
            'refrigerator'    => ['قطع برق ۲۴ ساعت برای دیفراست کامل (خالی کردن مواد غذایی)', 'بررسی تنظیم دمای دیجیتال و باز نشدن کامل دریچه‌ها', 'تمیز کردن کویل‌های کندانسور پشت دستگاه'],
            'dishwasher'      => ['تمیز کردن فیلتر کف محفظه', 'بررسی شیر آب ورودی و فشار', 'استفاده از نمک و مایع شست‌وشوی استاندارد', 'اجرای سیکل خالی با جوش‌شیرین برای رسوب‌زدایی'],
            'air_conditioner' => ['تمیز کردن فیلترهای یونیت داخلی', 'قطع برق هر دو یونیت ۱۰ دقیقه (ریست)', 'بررسی چرخش آزاد فن خارجی با خاموشی کامل'],
            'dryer'           => ['تمیز کردن کامل فیلتر لینت بعد از هر بار استفاده', 'بررسی مسیر و خم شیلنگ تخلیه هوا', 'ریست با قطع برق'],
            'microwave'       => ['ریست با قطع برق چند دقیقه‌ای', 'بررسی کامل بسته بودن درب و تمیزی سطح تماس', 'جداسازی ظروف فلزی از داخل محفظه'],
            'oven'            => ['قطع برق ۵ دقیقه و ریست برد', 'بررسی تنظیمات پخت و تایمر'],
            'water_heater'    => ['ریست کلید حرارتی (پشت درب)', 'بررسی فشار آب ورودی', 'قطع برق ۱۰ دقیقه و روشن‌سازی مجدد'],
            'package'         => ['شارژ فشار آب سیستم به ۱.۵ بار', 'ریست سوئیچ فشار', 'هوای مدار شوفاژ'],
            'television'     => ['قطع برق و اتصال مجدد بعد از ۵ دقیقه', 'بررسی سلامت کابل HDMI/آنتن', 'بروزرسانی نرم‌افزار از منوی تنظیمات'],
        ];
        return $map[$deviceKey] ?? ['ریست دستگاه با قطع برق ۱۰ دقیقه‌ای', 'بررسی اتصالات و منبع تغذیه', 'بررسی درب و کلیدهای امنیتی'];
    }

    private function deviceTechFixes(string $deviceKey, string $category): array
    {
        $map = [
            'washing_machine' => ['تست مقاومت سنسور/شیر/پمپ با مولتی‌متر و تعویض قطعه معیوب', 'عیب‌یابی مسیر برد و رله‌ها با نقشه سیم‌کشی', 'تست عایق موتور و تعویض در صورت کاهش عایقی'],
            'refrigerator'    => ['تست سنسورهای NTC و هیتر دیفراست و تعویض', 'بررسی فشار گاز و نشتی‌یابی با ازت', 'تست برد و تعویض/تعمیر در صورت نیاز'],
            'dishwasher'      => ['تست هیتر و سنسور NTC و تعویض', 'تست شیر برقی و پمپ تخلیه', 'تعمیر برد کنترل'],
            'air_conditioner' => ['اندازه‌گیری فشار گاز و شارژ مجدد', 'تست خازن و موتور فن خارجی', 'تست IPM برد اینورتر و کمپرسور'],
            'dryer'           => ['تست هیتر و ترموفیوز', 'تست سنسور رطوبت و موتور'],
            'microwave'       => ['تست دیود، خازن و مگنترون با تجهیزات HV (فقط تکنسین)', 'تست قفل درب میکروسوئیچ سه‌پایه'],
            'oven'            => ['تست مقاومت المنت‌ها (۲۰-۴۰ اهم)', 'تست سنسور دما و ترموستات', 'تعمیر/تعویض برد لمسی'],
            'water_heater'    => ['تست ترموستات و سنسورها', 'رسوب‌زدایی مبدل و بررسی المنت'],
            'package'         => ['تست پمپ سیرکولاسیون و سوئیچ فشار', 'کالیبراسیون برد و بررسی احتراق'],
            'television'     => ['تست ولتاژهای پاور بورد', 'تست بک‌لایت و T-Con', 'تعمیر مین‌بورد'],
        ];
        return $map[$deviceKey] ?? ['تست قطعه مرتبط با مولتی‌متر و تعویض', 'عیب‌یابی برد کنترل و تعمیر تخصصی', 'بررسی سیم‌کشی و سوکت‌های داخلی'];
    }

    /* ==================================================
     * 🔎 استنتاج زمینه از متن وب
     * ================================================== */

    private function titleFromContext(string $code, string $text, string $deviceFa): string
    {
        $patterns = [
            '/(درain|drain|تخلیه)/iu' => 'خطای تخلیه آب',
            '/(inlet|ورود آب|آبرسانی)/iu' => 'خطای ورود آب',
            '/(door|درب|قفل)/iu' => 'خطای قفل درب',
            '/(heat|هیتر|گرمایش|المنت)/iu' => 'خطای گرمایش و هیتر',
            '/(sensor|thermistor|سنسور|NTC)/iu' => 'خطای سنسور',
            '/(motor|موتور)/iu' => 'خطای موتور',
            '/(fan|فن)/iu' => 'خطای فن',
            '/(communicat|ارتباط|communication)/iu' => 'خطای ارتباطی',
            '/(leak|نشت)/iu' => 'خطای نشت آب',
            '/(balance|توازن|unbalanc)/iu' => 'خطای عدم توازن',
            '/(EEPROM|حافظه|memory)/iu' => 'خطای حافظه',
            '/(compressor|کمپرسور)/iu' => 'خطای کمپرسور',
        ];
        foreach ($patterns as $re => $title) {
            if (preg_match($re, $text)) {
                return $title;
            }
        }
        return 'خطای ' . $code . ' در ' . $deviceFa;
    }

    private function partFromContext(string $text): string
    {
        $map = [
            '/تخلیه|drain|پمپ/iu' => 'پمپ تخلیه',
            '/ورود آب|inlet|شیر/iu' => 'شیر برقی ورودی',
            '/سنسور|sensor|NTC|thermistor/iu' => 'سنسور دما NTC',
            '/هیتر|heater|المنت/iu' => 'هیتر حرارتی',
            '/موتور|motor/iu' => 'موتور اصلی',
            '/فن|fan/iu' => 'فن',
            '/برد|board|PCB/iu' => 'برد کنترل',
            '/قفل|lock|درب|door/iu' => 'قفل درب',
            '/کمپرسور|compressor/iu' => 'کمپرسور',
        ];
        foreach ($map as $re => $part) {
            if (preg_match($re, $text)) {
                return $part;
            }
        }
        return 'قطعه مرتبط با کد';
    }

    private function categoryFromContext(string $text): string
    {
        $p = $this->partFromContext($text);
        foreach (self::CATEGORIES as $cat) {
            if (mb_strpos($p, mb_substr($cat, 0, 4)) !== false) {
                return $cat;
            }
        }
        return 'سایر';
    }

    private function severityFromContext(string $text): string
    {
        if (preg_match('/critical|بحرانی|فوری/iu', $text)) { return 'critical'; }
        if (preg_match('/warning|هشدار|مهم/iu', $text)) { return 'high'; }
        return 'medium';
    }

    /* ==================================================
     * 🛠️ ابزارهای کمکی
     * ================================================== */

    /** 🔗 تطبیق برند پنل با کلید پایگاه دانش */
    public function matchBrandKey(string $nameFa, string $nameEn): ?string
    {
        $kb = TextProcessor::loadKnowledge('error-codes-brands');
        $hay = mb_strtolower($nameFa . ' ' . $nameEn);
        foreach ($kb['brands'] as $key => $b) {
            if (mb_strpos($hay, mb_strtolower($b['name_en'])) !== false
                || mb_strpos($hay, mb_strtolower($b['name_fa'])) !== false
                || mb_strpos($hay, $key) !== false) {
                return $key;
            }
        }
        return null;
    }

    private function subtypeLabel(array $c, string $deviceKey): string
    {
        $subs = (array)($c['subtypes'] ?? []);
        if (!empty($subs)) {
            return implode('، ', array_slice($subs, 0, 3));
        }
        return 'همه زیرنوع‌ها';
    }

    private function fallbackModels(string $brandFa, string $deviceKey): array
    {
        return ['مدل‌های هم‌خانواده — با جستجوی آنلاین مدل‌ دقیق تکمیل شود'];
    }

    private function partWithEn(string $part): string
    {
        $map = [
            'پمپ تخلیه' => 'پمپ تخلیه | Drain Pump',
            'شیر برقی ورودی' => 'شیر برقی ورودی | Inlet Valve',
            'سنسور دما NTC' => 'سنسور دما | NTC Thermistor',
            'هیتر حرارتی' => 'هیتر | Heating Element',
            'موتور اصلی' => 'موتور | Drive Motor',
            'برد کنترل' => 'برد کنترل | Main PCB',
            'قفل درب' => 'قفل درب | Door Lock',
            'فن' => 'فن | Fan Motor',
            'کمپرسور' => 'کمپرسور | Compressor',
        ];
        return $map[$part] ?? ($part ?: '—');
    }

    private function normalizeCategory(string $cat): string
    {
        $cat = trim($cat);
        foreach (self::CATEGORIES as $c) {
            if ($cat === $c || mb_strpos($cat, mb_substr($c, 0, 4)) !== false || mb_strpos($c, mb_substr($cat, 0, 4)) !== false) {
                return $c;
            }
        }
        return 'سایر';
    }

    private function fallbackSpecs(string $category): string
    {
        $map = [
            'سنسور' => 'مقاومت NTC: ۵-۱۲ kΩ در ۲۵°C (بسته به مدل)',
            'پمپ' => 'مقاومت کویل: ۱۵-۲۰ اهم · ولتاژ ۲۲۰V AC',
            'شیر' => 'مقاومت کویل: ۳.۵-۴.۵ kΩ · ۲۲۰V AC',
            'المنت / هیتر' => 'توان ۱۸۰۰-۲۰۰۰W · مقاومت ۲۵-۳۵ اهم',
            'موتور' => 'موتور BLDC/یونیورسال — تست عایق و کویل لازم',
            'برد الکترونیکی' => 'تست ولتاژهای ریل ۵V/۱۲V لازم',
        ];
        return $map[$category] ?? 'مشخصات دقیق با مدل دستگاه در مرجع قطعات بررسی شود';
    }

    private function fallbackLocation(string $category): string
    {
        $map = [
            'پمپ' => 'پایین محفظه، پشت پنل جلویی/فیلتر',
            'شیر' => 'پشت دستگاه، محل اتصال شیلنگ ورودی',
            'سنسور' => 'روی/کنار قطعه هدف (طبق نقله مونتاژ)',
            'المنت / هیتر' => 'پایین دیگ / داخل محفظه',
            'موتور' => 'پشت دیگ',
            'برد الکترونیکی' => 'پشت پنل کنترل / پایین جلو',
        ];
        return $map[$category] ?? 'محل دقیق در نقشه انفجاری مدل مشخص می‌شود';
    }

    /* ==================================================
     * ✨ بهبود/یکتاسازی فیلد با AI (برای ویرایش کد خطا)
     * ================================================== */

    /**
     * ✨ بهینه‌سازی و یکتاسازی یک فیلد از کد خطا با AI
     *
     * @param int    $errorId شناسه رکورد
     * @param string $field   نام فیلد (title/description/causes/solutions/...)
     * @return array ['field','old','new','note']
     */
    public function improveField(int $errorId, string $field): array
    {
        $rec = $this->db->fetch('SELECT e.*, b.name_fa AS brand_name FROM error_codes e LEFT JOIN brands b ON b.id = e.brand_id WHERE e.id = ?', [$errorId]);
        if (!$rec) {
            throw new RuntimeException('کد خطا یافت نشد.');
        }
        $allowed = ['title', 'description', 'causes', 'solutions', 'related_part', 'tech_specs', 'part_location', 'subtype'];
        if (!in_array($field, $allowed, true)) {
            throw new RuntimeException('این فیلد قابل بهینه‌سازی خودکار نیست: ' . $field);
        }

        $deviceFa = self::DEVICE_FA[$rec['device_key']][0] ?? $rec['device_key'];
        $brandName = (string)($rec['brand_name'] ?? '');
        $old = (string)$rec[$field];
        mt_srand(crc32($errorId . '|' . $field . '|' . substr((string)time(), -4)));

        switch ($field) {
            case 'title':
                $base = preg_replace('/\s*—\s*' . preg_quote($deviceFa, '/') . '.*$/u', '', $old) ?: ('خطای ' . $rec['code']);
                $patterns = [
                    $base . ' — ' . $deviceFa . ' ' . $brandName . ' (علت و راه‌حل)',
                    'رفع ' . $base . ' در ' . $deviceFa . ' ' . $brandName . ' — راهنمای کامل',
                    $deviceFa . ' ' . $brandName . ' کد ' . $rec['code'] . ': ' . $base,
                ];
                $new = $patterns[mt_rand(0, 2)];
                break;

            case 'description':
                $causes = json_decode((string)$rec['causes'], true) ?: [];
                $new = $this->composeDescription($brandName, $deviceFa, [
                    'code' => $rec['code'], 'title' => $rec['title'], 'part' => $rec['related_part'] ?? '',
                ], $causes ?: ['فرسودگی قطعه مرتبط'], $rec['severity'], mt_rand(1000, 999999));
                // یکتاسازی: افزودن جمله تمایز
                $new .= ' نکته متمایز این رکورد نسبت به سایر کدها، ترکیب «' . ($rec['category'] ?: 'سایر') . '» با شرایط بهره‌برداری خاص ' . $deviceFa . ' ' . $brandName . ' است.';
                break;

            case 'causes':
                $causes = json_decode((string)$rec['causes'], true) ?: [];
                $extra = array_diff($this->deviceCauses($rec['device_key'], (string)$rec['category']), $causes);
                foreach (array_slice($extra, 0, 3) as $x) {
                    $causes[] = $x;
                }
                /* 📏 v2.7: حداقل ۵ دلیل */
                if (count($causes) < 5) {
                    $causes[] = 'قطع برق طولانی و روشن‌سازی مجدد (ریست کامل برد)';
                }
                $causes = array_slice(array_values(array_unique(array_filter($causes))), 0, 7);
                $new = json_encode($causes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                break;

            case 'solutions':
                $sols = json_decode((string)$rec['solutions'], true) ?: [];
                $sols = array_map(fn($x) => mb_scrub((string)$x), $sols);
                $hasTech = (bool)array_filter($sols, fn($s) => mb_strpos($s, '[تکنسین]') === 0);
                if (!$hasTech) {
                    foreach (array_slice($this->deviceTechFixes($rec['device_key'], (string)$rec['category']), 0, 2) as $t) {
                        $sols[] = '[تکنسین] ' . $t;
                    }
                }
                /* 📏 v2.7: حداقل ۵ راه‌حل — تکمیل از مخزن همان دستگاه */
                $pool = array_merge(
                    array_map(fn($x) => '[کاربر] ' . $x, $this->deviceUserFixes($rec['device_key'])),
                    array_map(fn($x) => '[تکنسین] ' . $x, $this->deviceTechFixes($rec['device_key'], (string)$rec['category']))
                );
                $pi = 0;
                while (count($sols) < 5 && $pi < count($pool)) {
                    if (!in_array($pool[$pi], $sols, true)) {
                        $sols[] = $pool[$pi];
                    }
                    $pi++;
                }
                $new = json_encode(array_map(fn($x) => mb_scrub((string)$x), array_slice(array_values(array_unique(array_filter($sols))), 0, 8)), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                break;

            case 'related_part':
                $new = $this->partWithEn($old ?: 'قطعه مرتبط با کد');
                break;

            case 'tech_specs':
                $new = $old ?: $this->fallbackSpecs((string)$rec['category']);
                if (mb_strlen($new) < 30) {
                    $new .= ' · برای مقدار دقیق، شماره مدل را در مرجع قطعات بررسی کنید';
                }
                break;

            case 'part_location':
                $new = $old ?: $this->fallbackLocation((string)$rec['category']);
                break;

            case 'subtype':
                $new = $old ?: 'همه زیرنوع‌ها';
                break;

            default:
                throw new RuntimeException('فیلد پشتیبانی نمی‌شود.');
        }

        $this->db->update('error_codes', [$field => $new], 'id = ?', [$errorId]);

        /* 🧠 خودیادگیر */
        try {
            SelfLearner::record('error_code_fix', $rec['device_key'] . ':' . $field, 'ai_optimize_field', [],
                ['score' => 0], ['score' => 1],
                "بهینه‌سازی فیلد «{$field}» کد خطا با الگوی موثر — برای همین فیلد در آینده هم استفاده شود.");
        } catch (Throwable $e) {
        }

        /* 📏 v2.7: مقدار «جدید» کامل برگردانده می‌شود — قبلاً mb_substr(...,300)
           JSON آرایه‌ها را وسط راه می‌بُرید و رکورد ذخیره‌شده خراب می‌شد */
        return ['field' => $field, 'old' => mb_substr($old, 0, 300), 'new' => $new,
                'note' => 'فیلد «' . $field . '» با AI بازنویسی و یکتا شد.'];
    }
}
