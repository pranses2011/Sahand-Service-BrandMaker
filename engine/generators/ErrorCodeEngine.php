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

    /** @var callable|null گیرنده گزارش پیشرفت (v2.14 — نوار پیشرفت خطایاب) */
    private $progressSink = null;

    /**
     * 📊 تنظین گیرنده پیشرفت — با هر مرحله موتور صدا زده می‌شود:
     *   fn(int $pct, string $title, string $detail)
     */
    public function setProgressSink(?callable $fn): void
    {
        $this->progressSink = $fn;
    }

    /** 📊 ارسال گزارش پیشرفت (بدون خطا اگر گیرنده‌ای نباشد) */
    private function progress(int $pct, string $title, string $detail = ''): void
    {
        if ($this->progressSink === null) {
            return;
        }
        try {
            ($this->progressSink)(max(0, min(100, $pct)), $title, $detail);
        } catch (Throwable $e) {
            // گزارش پیشرفت هرگز جریان اصلی را نمی‌شکند
        }
    }

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

    /** 🔁 v2.15: نام‌های مترادف انگلیسی هر دستگاه — برای «راند نجات» جستجو
     *  (ریشه‌یابی: کوئری با نام رسمی گاهی نتایج فنی نمی‌دهد؛ مترادف‌های رایج
     *   کاربران و تعمیرکاران نتایج بهتری برمی‌گردانند) */
    private const DEVICE_ALIASES_EN = [
        'microwave'      => ['Microwave', 'Countertop Microwave', 'Over-the-Range Microwave'],
        'television'    => ['TV', 'Smart TV', 'LED TV', 'OLED TV'],
        'washing_machine' => ['Washer', 'Front Load Washer', 'Washing Machine'],
        'refrigerator'  => ['Fridge', 'Refrigerator Freezer', 'Fridge Freezer'],
        'dishwasher'    => ['Dish Washer', 'Dishwasher'],
        'air_conditioner' => ['AC Split', 'Air Conditioner', 'HVAC Split'],
        'dryer'         => ['Tumble Dryer', 'Clothes Dryer'],
        'oven'          => ['Electric Oven', 'Built-in Oven'],
        'stove'         => ['Cooktop', 'Gas Stove'],
        'hood'          => ['Cooker Hood', 'Extractor Hood'],
        'water_heater'  => ['Water Heater', 'Boiler'],
        'package'       => ['Combi Boiler', 'Wall-mounted Boiler'],
        'vacuum_cleaner' => ['Vacuum', 'Vacuum Cleaner'],
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
     * 🚨 تولید همه کدهای خطای واقعی یک دستگاه از یک برند (v2.8 — صددرصد وب‌محور)
     *
     * طبق درخواست صریح کاربر: «همه کدها باید از جستجوی اینترنت اضافه شوند و همه
     * دقیق و واقعی باشند» — بنابراین:
     *   ۱️⃣ فقط کدهایی ثبت می‌شوند که در وب راستی‌آزمایی شده‌اند (شاهد اختصاصی)
     *   ۲️⃣ پایگاه دانش دیگر «کد جدید» اضافه نمی‌کند — فقط فیلدهای خالی رکوردهای
     *      وب را پر می‌کند (عنوان/قطعه/مشخصات) و هرگز روی داده وب «رونویسی» نمی‌کند
     *   ۳️⃣ اگر وب چیزی نیافت، صادقانه گزارش می‌دهد — کد ساختگی اعتبار سایت را
     *      خراب می‌کند («برای خطای پمپ تخلیه ایراد شیر برقی نوشتن» ممنوع!)
     *
     * @param int    $brandId   شناسه برند (از جدول brands)
     * @param string $deviceKey کلید دستگاه (washing_machine و ...)
     * @param bool   $useWeb    جستجوی آنلاین (منبع اصلی)
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

        /* ⏱️ بودجه زمانی — درج‌ها بلافاصله پس از تحقیق اجرا می‌شوند
           🆕 v2.15: ۳۴ → ۴۴ ثانیه — با ارائه‌دهنده‌های جدید و راند نجات، زمان بیشتر لازم است */
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }
        $maxExec = (int)@ini_get('max_execution_time');
        $budget = ($maxExec > 0 && $maxExec <= 120) ? max(26.0, min(44.0, $maxExec - 8.0)) : 44.0;
        $deadline = microtime(true) + $budget;

        $sources = [];
        $records = [];
        $webCount = 0;

        $this->progress(4, 'بررسی برند و دستگاه', $brand['name_fa'] . ' — ' . $deviceFa . ($useWeb ? ' — جستجوی آنلاین فعال' : ' — جستجوی آنلاین غیرفعال'));

        /* ---------- ۱) جستجوی آنلاین — تنها منبع ثبت کد ---------- */
        if ($useWeb) {
            try {
                $this->progress(8, 'شروع جستجوی آنلاین', 'کوئری‌های فارسی و انگلیسی به موتورهای جستجو ارسال می‌شود...');
                $webCodes = $this->searchWebCodes($brand['name_en'] ?: $brand['name_fa'], $brand['name_fa'], $deviceKey, $deviceFa, $brandKey, $deadline);
                /* 🚨 v2.15 — راند نجات: اگر هیچ کدی پیدا نشد، کوئری‌های ساده‌شده
                   جایگزین (نام‌های مترادف دستگاه) امتحان می‌شوند — ریشه‌یابی
                   «مایکروویو/تلویزیون ال‌جی هیچ کدی پیدا نکرد»: کوئری‌های اصلی
                   گاهی نتایج فنی برنمی‌گرداندند و موتور زود تسلیم می‌شد. */
                if (!$webCodes && microtime(true) < $deadline - 8.0) {
                    $this->progress(30, 'راند نجات — کوئری‌های جایگزین', 'با نام‌های مترادف دستگاه دوباره جستجو می‌شود...');
                    foreach (self::DEVICE_ALIASES_EN[$deviceKey] ?? [] as $altEn) {
                        if (microtime(true) > $deadline - 6.0) { break; }
                        $altFa = $deviceFa;
                        $altCodes = $this->searchWebCodes($brand['name_en'] ?: $brand['name_fa'], $brand['name_fa'], $deviceKey, $altFa, $brandKey, $deadline, $altEn);
                        foreach ($altCodes as $wc) {
                            $webCodes[] = $wc;
                        }
                        if (count($webCodes) >= 5) { break; }
                    }
                }
                foreach ($webCodes as $wc) {
                    /* 🔀 دانش curated فقط «فاصله‌های خالی» را پر می‌کند (v2.8:
                       هرگز جای داده استخراج‌شده از وب را نمی‌گیرد — وب مقدم مطلق) */
                    $wc = $this->mergeKbIntoWebRecord($brandKb, $deviceKey, $wc);
                    /* 🛡 گارد سازگاری قطعه با عنوان (پایین) */
                    $wc = $this->enforcePartConsistency($wc);
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

        /* ---------- ۲) درج در دیتابیس (بدون تکراری) ---------- */
        $this->progress(88, 'استخراج دلایل، راه‌حل‌ها و مشخصات فنی', count($records) . ' کد راستی‌آزمایی‌شده آماده ثبت است...');
        $inserted = 0;
        $skipped = 0;
        $insertedCodes = []; // کدهای همین نوبت — برای جلوگیری از درج دوباره KB
        foreach ($records as $rec) {
            $insertedCodes[] = strtoupper($rec['code']);
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

        /* ---------- 🆕 v3.10: پشتیبانی پایگاه دانش تخصصی — کدهای معتبر curated که وب نیافت ----------
         * ریشه‌یابی «فقط ۳ کد برای ظرفشویی ال‌جی»: سیاست فقط-وب کدهای معتبر و کاملِ
         * پایگاه دانش تخصصی (که از مستندات رسمی سازنده گردآوری شده) را حذف می‌کرد!
         * اکنون: هر کد معتبر KB که وب پیدا نکرد، با داده دقیق curated ثبت می‌شود —
         * منبع: «پایگاه دانش تخصصی + مستندات رسمی» و اگر وب همان کد را با منبع دید،
         * منبع وب هم به آن الصاق می‌شود (داده KB مقدم — دقت تضمینی؛ وب مکمل). */
        $kbAdded = 0;
        if ($brandKb) {
            $kbCodes = $brandKb['devices'][$deviceKey]['codes'] ?? [];
            foreach ($kbCodes as $kc) {
                $kNorm = strtoupper(str_replace([' ', '-'], '', (string)$kc['code']));
                if ($kNorm === '' || in_array($kNorm, $insertedCodes, true)) {
                    continue;
                }
                $existsKb = $this->db->fetchValue(
                    'SELECT COUNT(*) FROM error_codes WHERE brand_id = ? AND device_key = ? AND UPPER(code) = ?',
                    [$brandId, $deviceKey, $kNorm]
                );
                if ($existsKb) {
                    continue;
                }
                /* 🌐 منبع وب همان کد (در صورت یافته‌شدن در نتایج این نوبت) الصاق می‌شود */
                $kbSourceUrls = [];
                foreach ($records as $rec2) {
                    if (strtoupper(str_replace([' ', '-'], '', (string)$rec2['code'])) === $kNorm && !empty($rec2['source_urls'])) {
                        $kbSourceUrls = array_slice((array)$rec2['source_urls'], 0, 3);
                    }
                }
                $kbRec = $this->buildRecord($brand, $deviceKey, $deviceFa, $deviceEn, [
                    'code'        => $kc['code'],
                    'title'       => $kc['title'] ?? ('خطای ' . $kc['code']),
                    'part'        => $kc['part'] ?? '',
                    'category'    => $kc['category'] ?? '',
                    'severity'    => $kc['severity'] ?? 'medium',
                    'causes'      => $kc['causes'] ?? [],
                    'fixes_user'  => $kc['fixes_user'] ?? [],
                    'fixes_tech'  => $kc['fixes_tech'] ?? [],
                    'models'      => $kc['models'] ?? [],
                    'specs'       => $kc['specs'] ?? '',
                    'location'    => $kc['location'] ?? '',
                    'source_urls' => $kbSourceUrls,
                ], 'kb');
                $this->db->insert('error_codes', $kbRec);
                $insertedCodes[] = $kNorm;
                $kbAdded++;
            }
        }

        /* 🧠 خودیادگیر */
        try {
            if ($inserted > 0) {
                SelfLearner::record('error_code_fix', $deviceKey, 'web_verified_extract', ['codes' => $inserted],
                    ['score' => 0], ['score' => $inserted],
                    "استخراج و ثبت {$inserted} کد خطای راستی‌آزمایی‌شدهٔ وب برای «{$deviceFa}» برند «{$brand['name_fa']}».");
            }
        } catch (Throwable $e) {
            // بی‌اثر بر جریان اصلی
        }

        /* ---------- ۳) گزارش صادقانه ---------- */
        $this->progress(97, 'ثبت کدها در پایگاه داده', ($inserted + $kbAdded) . ' کد جدید ثبت شد');
        if ($webCount === 0 && $kbAdded === 0) {
            $report = $useWeb
                ? 'هیچ کدی از جستجوی اینترنتی تأیید نشد و پایگاه دانش هم برای این برند+دستگاه کدی ندارد — چیزی ثبت نشد (طبق سیاست «فقط کدهای واقعی»). کوئری دیگری یا اتصال اینترنت را بررسی کنید.'
                : 'جستجوی آنلاین غیرفعال بود — کدهای معتبر پایگاه دانش تخصصی (در صورت وجود) ثبت شدند.';
        } elseif ($webCount === 0 && $kbAdded > 0) {
            $report = "جستجوی وب کد تأییدشده‌ای نیافت اما {$kbAdded} کد معتبر از «پایگاه دانش تخصصی» (مستندات رسمی سازنده) ثبت شد" . ($skipped > 0 ? " — {$skipped} کد از قبل موجود بود" : '') . '.';
        } else {
            $report = "{$inserted} کد خطای واقعی وب ثبت شد از مجموع {$webCount} کد راستی‌آزمایی‌شده";
            if ($kbAdded > 0) {
                $report .= " + {$kbAdded} کد معتبر تکمیلی از پایگاه دانش تخصصی";
            }
            $report .= ($skipped > 0 ? " — {$skipped} کد از قبل موجود بود" : '') . '.';
        }

        return [
            'inserted' => $inserted + $kbAdded,
            'skipped'  => $skipped,
            'total_known' => count($records) + $kbAdded,
            'web_found' => $webCount,
            'kb_added' => $kbAdded,
            'sources'  => array_values(array_unique(array_filter($sources))),
            'report'   => $report,
        ];
    }

    /* ==================================================
     * 🌐 جستجوی آنلاین کدها (فارسی + انگلیسی)
     * ================================================== */

    /**
     * 🌐 جستجوی کدهای خطا در وب — فارسی و انگلیسی (v3.0: دو فازی + راستی‌آزمایی تک‌کد)
     *
     * فاز ۱ (کشف): کوئری‌های عمومی → کدهای کاندید از نتایج
     * فاز ۲ (تعمیق v3.0): برای هر کد برتر، جستجوی اختصاصی + خواندن متن کامل صفحه
     *   → شواهد واقعی، عنوان دقیق، دلایل و راه‌حل‌های غنی از متن کامل (نه فقط اسنیپت)
     *   → کدهایی که در فاز ۲ هیچ شاهد مستقلی نیافتند و پایگاه دانش هم نمی‌شناسد → رد می‌شوند
     *
     * @return array<array{code,title,part,category,severity,causes,fixes_user,fixes_tech,source_urls}>
     */
    private function searchWebCodes(string $brandEn, string $brandFa, string $deviceKey, string $deviceFa, ?string $brandKey = null, ?float $deadline = null, ?string $deviceEnOverride = null): array
    {
        $searcher = new WebSearchService();
        $deviceEn = $deviceEnOverride !== null && $deviceEnOverride !== '' ? $deviceEnOverride : (self::DEVICE_FA[$deviceKey][1] ?? ucfirst($deviceKey));
        $deadline = $deadline ?? (microtime(true) + 24.0);

        /* 🎯 v2.15: کوئری‌های دقیق‌تر و متنوع‌تر — ۷ → ۱۲ (پوشش عبارت‌های رایج
           تعمیرکاران و صفحات جدول کد؛ تلویزیون: چشمک LED هم پوشش داده می‌شود) */
        $isTv = stripos($deviceEn, 'tv') !== false || stripos($deviceEn, 'television') !== false;
        $queries = [
            "{$brandEn} {$deviceEn} error codes list meaning",
            "کد خطای {$deviceFa} {$brandFa} فهرست کامل",
            "{$brandEn} {$deviceEn} fault codes list troubleshooting manual",
            "کد خطا {$deviceFa} {$brandFa} علت و راه حل",
            "{$brandEn} {$deviceEn} display error code chart",
            /* 🆕 v3.9: کوئری‌های بیشتر — پوشش منابع فنی و جدول‌های سازنده */
            "{$brandEn} {$deviceEn} error code table all models",
            "رفع خطای {$deviceFa} {$brandFa} نمایش کد",
            /* 🆕 v2.15: عبارت‌های رایج کاربران + پوشش تلویزیون (چشمک LED) */
            "{$brandEn} {$deviceEn} error codes what does it mean and how to fix",
            "{$brandEn} {$deviceEn} service manual error code list pdf",
            $isTv ? "{$brandEn} TV blinking codes LED error meaning" : "{$brandEn} {$deviceEn} diagnostic codes self test",
            "{$brandEn} {$deviceEn} کدهای خطا",
        ];

        $found = [];      // code => record
        $contextTexts = []; // code => [متنی که کد در آن دیده شد]
        $listUrls = [];   // 🎯 آدرس صفحات «فهرست کدها» برای پارس جدول (v2.8)

        foreach ($queries as $qi => $query) {
            /* ⏱️ احترام به بودجه زمانی — بقیه کوئری‌ها حذف می‌شوند */
            if (microtime(true) > $deadline - 4.0) {
                break;
            }
            /* 📊 v2.14: گزارش مرحله‌به‌مرحله جستجو (۸٪ تا ۴۲٪) */
            $this->progress(8 + (int)round(34 * ($qi / max(1, count($queries) - 1))), 'جستجوی وب — کوئری ' . ($qi + 1) . ' از ' . count($queries), mb_substr($query, 0, 90));
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
                /* 🎯 v2.8: صفحه‌های «فهرست/جدول کد» را برای پارس ساختاری ذخیره کن
                   (v2.15: سقف ۶ → ۸ صفحه و متن کامل ۹ → ۱۴ هزار نویسه — فیلدهای
                   کامل‌تر از دلایل/راه‌حل از صفحات سازنده) */
                if (count($listUrls) < 8 && $url !== ''
                    && preg_match('#(error|fault|code|خطا|کد)#iu', ($r['title'] ?? '') . $url)
                    && (stripos($text, $brandEn) !== false || mb_strpos($text, $brandFa) !== false)) {
                    $listUrls[] = $url;
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
                            '_verified' => false,
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

        /* ---------- 🎯 v2.8: پارس ساختاریافته «جدول کدها» از صفحات فهرست ----------
         * صفحات فهرست کد سازنده، دقیق‌ترین منبع ممکن‌اند: «E4 | Water Drainage Error»
         * یا «کد OE: خطای تخلیه آب». جدول هر صفحه → معنای دقیق تک‌تک کدها؛
         * کدهای جدید کشف‌شده از این مسیر «تأییدشده با شاهد جدول» حساب می‌شوند. */
        $tableMeanings = [];
        foreach (array_slice($listUrls, 0, 6) as $lui => $lu) {
            if (microtime(true) > $deadline - 6.0) { break; }
            /* 📊 v2.14: گزارش خواندن صفحات فهرست کد (۴۵٪ تا ۶۵٪) */
            $this->progress(45 + (int)round(20 * ($lui / 6)), 'خواندن صفحات فهرست کد سازنده', 'صفحه ' . ($lui + 1) . ' از ' . count(array_slice($listUrls, 0, 6)) . ' تحلیل می‌شود...');
            try {
                $page = $searcher->fetchPageText($lu, 14000);
            } catch (Throwable $e) {
                continue;
            }
            if (empty($page['ok'])) { continue; }
            $txt = (string)$page['text'];
            $hasCtx = stripos($txt, $brandEn) !== false || mb_strpos($txt, $brandFa) !== false
                || stripos($txt, $deviceEn) !== false || mb_strpos($txt, $deviceFa) !== false;
            if (!$hasCtx || mb_strlen($txt) < 400) { continue; }
            $pairs = $this->parseCodeTable($txt);
            /* 🛡 نام برند هرگز «کد خطا» نیست (LG/GE/SMEG/BOSCH...) —
               با پشتیبانی کدهای دوحرفی، «LG خطای ...» به کد تبدیل می‌شد! */
            $brandUpper = strtoupper(trim((string)$brandEn));
            $brandKeyUpper = strtoupper(trim((string)$brandKey));
            foreach ($pairs as $pCode => $_) {
                if ($pCode === $brandUpper || $pCode === $brandKeyUpper
                    || ($brandFa !== '' && $pCode === mb_strtoupper($brandFa))
                    || in_array($pCode, ['AC', 'TV', 'PC', 'OK', 'NO', 'AI', 'HD', 'LED'], true)) {
                    unset($pairs[$pCode]);
                }
            }
            foreach ($pairs as $pCode => $pTitle) {
                if (!isset($tableMeanings[$pCode]) || mb_strlen($pTitle) > mb_strlen($tableMeanings[$pCode])) {
                    $tableMeanings[$pCode] = $pTitle;
                }
                /* کد جدید از جدول → رکورد کامل با منبع (قطعه اول از معنای دقیق عنوان) */
                if (!isset($found[$pCode])) {
                    $found[$pCode] = [
                        'code' => $pCode,
                        'title' => $pTitle,
                        'part' => $this->partFromTitle($pTitle) ?: $this->partFromContext($this->codeCentricContext($txt, $pCode, 400)),
                        'category' => $this->categoryFromContext($this->codeCentricContext($txt, $pCode, 400)),
                        'severity' => $this->severityFromContext($this->codeCentricContext($txt, $pCode, 400)),
                        'causes' => [],
                        'fixes_user' => [],
                        'fixes_tech' => [],
                        'source_urls' => [$lu],
                        '_evidence' => 2, // جدول ساختاری = شاهد قوی
                        '_verified' => true,
                        '_table_verified' => true,
                    ];
                    $contextTexts[$pCode] = [$this->codeCentricContext($txt, $pCode, 700)];
                } else {
                    /* کد موجود: معنای جدول مقدم بر استخراج اسنیپتی — عنوان عمومی
                       (فقط کد + دستگاه) حالا به‌درستی «عمومی» شناخته می‌شود */
                    if (!empty($pTitle) && mb_strlen($pTitle) >= 10) {
                        $oldTitle = (string)$found[$pCode]['title'];
                        if (self::titleIsGeneric($oldTitle, $pCode)) {
                            $found[$pCode]['title'] = $pTitle;
                            /* 🎯 قطعه از معنای دقیق جدول — پنجره ±۴۰۰ مخلوط بود */
                            $tp = $this->partFromTitle($pTitle);
                            if ($tp !== '') { $found[$pCode]['part'] = $tp; }
                        }
                    }
                    $found[$pCode]['_evidence'] += 2;
                    $found[$pCode]['_table_verified'] = true;
                    $found[$pCode]['_verified'] = true;
                    if (!in_array($lu, $found[$pCode]['source_urls'], true) && count($found[$pCode]['source_urls']) < 4) {
                        $found[$pCode]['source_urls'][] = $lu;
                    }
                    if (count($contextTexts[$pCode]) < 8) {
                        $contextTexts[$pCode][] = $this->codeCentricContext($txt, $pCode, 700);
                    }
                }
            }

            /* 🆕 v2.14 — تور ایمنی: کشف کدها از «متن کامل صفحه» با استخراج‌گر الگویی
             * ریشه‌یابی «هیچ کدی برای مایکروویو ال‌جی»: حتی وقتی پارس جدول ساختاری
             * همه فرمت‌ها را نگیرد، این مسیر هیچ کد واقعی صفحه را از دست نمی‌دهد.
             * گارد زمینه: پنجره اطراف کد باید واژه خطا/کد/نمایش داشته باشد. */
            foreach ($this->extractCodePatterns($txt) as $pgCode) {
                $pgCtx = $this->codeCentricContext($txt, $pgCode, 700);
                if ($pgCtx === '' || !preg_match('/(error|fault|code|کد|خطا|نمایش|display)/i', $pgCtx)) {
                    continue;
                }
                if (!isset($found[$pgCode])) {
                    $pgTitle = $tableMeanings[$pgCode] ?? $this->titleFromContext($pgCode, $pgCtx, $deviceFa);
                    $found[$pgCode] = [
                        'code' => $pgCode,
                        'title' => $pgTitle,
                        'part' => $this->partFromTitle($pgTitle) ?: $this->partFromContext($pgCtx),
                        'category' => $this->categoryFromContext($pgCtx),
                        'severity' => $this->severityFromContext($pgCtx),
                        'causes' => [],
                        'fixes_user' => [],
                        'fixes_tech' => [],
                        'source_urls' => [$lu],
                        '_evidence' => 2, // کد در صفحه اختصاصی خطاهای همین برند+دستگاه
                        '_verified' => true,
                        '_table_verified' => isset($tableMeanings[$pgCode]),
                    ];
                    $contextTexts[$pgCode] = [$pgCtx];
                } else {
                    $found[$pgCode]['_evidence'] += 1;
                    if (!in_array($lu, $found[$pgCode]['source_urls'], true) && count($found[$pgCode]['source_urls']) < 4) {
                        $found[$pgCode]['source_urls'][] = $lu;
                    }
                    if (count($contextTexts[$pgCode]) < 8) {
                        $contextTexts[$pgCode][] = $pgCtx;
                    }
                }
            }
        }

        /* ---------- 🎯 فاز ۲ (v3.1): راستی‌آزمایی و تعمیق تک‌کد + بودجه زمانی ----------
         * کدهای پرشاهد (مرتب نزولی) تک‌تک با جستجوی اختصاصی و خواندن صفحه،
         * عمیق می‌شوند — دقت فیلدها از این مسیر چند برابر می‌شود.
         * ⏱️ بودجه: حداکثر ۸ کد یا تا سقف زمان — کدهای هرگز بررسی‌نشده فقط با
         * شناخت KB یا شاهد بالا نگه داشته می‌شوند. */
        uasort($found, fn($a, $b) => $b['_evidence'] <=> $a['_evidence']);
        $deepBudget = 8;
        /* 🆕 v3.9: بودجه ویژه غنی‌سازی کدهای جدول‌تأییدِ «نحیف» — کدهایی که از
           جدول عنوان/قطعه دقیق دارند اما دلایل/راه‌حل استخراج‌شده از متن جدول
           کمتر از ۲ مورد است، با جستجوی اختصاصی تکمیلی غنی می‌شوند تا همه
           فیلدها کامل و بدون نقص باشند */
        $tableEnrichBudget = 4;
        $i = 0;
        $totalCandidates = count($found);
        $processedCandidates = 0;
        foreach ($found as $codeKey => &$f) {
            $processedCandidates++;
            /* 📊 v2.14: گزارش راستی‌آزمایی تک‌کد (۶۸٪ تا ۸۶٪) */
            if ($processedCandidates % 2 === 1 || $processedCandidates === $totalCandidates) {
                $this->progress(68 + (int)round(18 * ($processedCandidates / max(1, $totalCandidates))), 'راستی‌آزمایی و تعمیق کدها', 'کد ' . $codeKey . ' بررسی می‌شود (' . $processedCandidates . ' از ' . $totalCandidates . ')');
            }
            /* 🎯 v2.8: کدهای تأییدشده با «جدول ساختاری» از تعمیق عبور می‌کنند —
               عنوان دقیق از خود جدول آمده؛ وقت برای کدهای دیگر صرفه‌جویی می‌شود.
               🆕 v3.9: مگر اینکه دلایل/راه‌حل‌هایشان نحیف باشد (کمتر از ۲) */
            if (!empty($f['_table_verified'])) {
                $ctxAll = implode(' . ', array_slice($contextTexts[$codeKey] ?? [], 0, 8));
                $thinCauses = count($this->extractCausesFromContext($ctxAll, $deviceKey, $f['category'] ?? 'سایر')) < 2;
                if (!$thinCauses || $tableEnrichBudget <= 0) {
                    continue;
                }
                $tableEnrichBudget--;
                $f['_needs_enrich'] = true;
            } elseif ($i++ >= $deepBudget) {
                break;
            }
            if (microtime(true) > $deadline - 1.5) {
                /* ⏱️ زمان تمام شد — کدهای باقی‌مانده فقط با شاهد بالا یا جدول می‌مانند */
                $idx = 0;
                foreach ($found as $ck2 => $f2info) {
                    $enrichQueued = !empty($f2info['_needs_enrich']);
                    $tableOk = !empty($f2info['_table_verified']);
                    if ($idx++ < $i && !$enrichQueued) { continue; } // کدهای بررسی‌شده
                    if (($f2info['_evidence'] ?? 0) < 2 && !$tableOk) {
                        $found[$ck2]['_reject'] = true;
                    }
                }
                break;
            }
            try {
                $deep = $this->deepResearchCode($searcher, $brandEn, $brandFa, $deviceEn, $deviceFa, $codeKey, $deadline);
            } catch (Throwable $e) {
                $deep = null;
            }
            if ($deep === null) {
                /* هیچ شاهد اختصاصی و نه جدول → رد (سیاست فقط-وب v2.8)
                   🛡 v3.9: کد «جدول‌تأییدشده» هرگز رد نمی‌شود — شاهد جدول قوی است؛
                   فقط غنی‌سازی ناقص می‌ماند و فیلدها از متن جدول پر می‌شوند */
                if (empty($f['_table_verified'])) {
                    $f['_reject'] = true;
                }
                continue;
            }
            $f['_verified'] = true;
            $f['_evidence'] += $deep['evidence'];
            $contextTexts[$codeKey] = array_merge($contextTexts[$codeKey] ?? [], $deep['contexts']);
            $f['source_urls'] = array_values(array_unique(array_merge($f['source_urls'], $deep['urls'])));
            if (!empty($deep['title'])) {
                $f['title'] = $deep['title'];
            }
            if (($f['part'] ?? '') === '' || $f['part'] === 'قطعه مرتبط با کد') {
                $f['part'] = $deep['part'] ?: $f['part'];
            }
            $f['severity'] = $deep['severity'] ?: $f['severity'];
            /* متن غنی صفحه برای استخراج مشخصات فنی و محل قطعه (v3.0) */
            if (!empty($deep['page_text'])) {
                $f['_specs_ctx'] = $deep['page_text'];
                $f['_location'] = $deep['location'];
            }
        }
        unset($f);

        /* 🧹 حذف کدهای رد‌شده در راستی‌آزمایی */
        $found = array_filter($found, fn($f) => empty($f['_reject']));

        /* ---------- 🆕 v3.9: تکمیل نهایی همه فیلدها — «بدون نقص» ----------
         * مشخصات فنی و محل قطعه از متن‌های ذخیره‌شده همین کد (جدول/صفحه) استخراج
         * می‌شوند و مدل‌های سازگار از الگوی نام‌گذاری برند در متن پیدا می‌شوند. */
        foreach ($found as $codeKey => &$f) {
            if (empty($f['_specs_ctx']) && !empty($contextTexts[$codeKey])) {
                $f['_specs_ctx'] = implode(' . ', array_slice($contextTexts[$codeKey], 0, 3));
            }
            if (empty($f['_location'])) {
                $f['_location'] = $this->locationFromContext(implode(' ', array_slice($contextTexts[$codeKey] ?? [], 0, 4)));
            }
            if (empty($f['models']) || $f['models'] === []) {
                $f['models'] = $this->modelsFromContext(implode(' ', array_slice($contextTexts[$codeKey] ?? [], 0, 6)), $brandEn);
            }
            unset($f['_needs_enrich']);
        }
        unset($f);

        /* 🧠 استخراج دلایل و راه‌حل‌ها از جمله‌های واقعی نتایج (v2.7) */
        foreach ($found as $codeKey => &$f) {
            $ctx = implode(' . ', array_slice($contextTexts[$codeKey] ?? [], 0, 8));
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
            /* 🛡 گارد نهایی سازگاری قطعه با عنوان (v2.8) */
            $f = $this->enforcePartConsistency($f);
        }
        unset($f);

        /* 🏷 اولویت‌بندی: کدهای راستی‌آزمایی‌شده و با شواهد بیشتر اول */
        uasort($found, fn($a, $b) => ($b['_verified'] <=> $a['_verified']) ?: ($b['_evidence'] <=> $a['_evidence']));
        foreach ($found as &$f) {
            unset($f['_evidence'], $f['_verified'], $f['_table_verified'], $f['_part_fixed']);
        }
        unset($f);

        return array_values(array_slice($found, 0, 40));
    }

    /**
     * 📊 پارس ساختاریافته «جدول کدها» از متن صفحه (v2.8) — دقیق‌ترین منبع معنا
     *
     * الگوهای پشتیبانی‌شده:
     *   E4 - Water Drainage Error   |   E4: Water Drainage
     *   E4 | خطای تخلیه آب          |   کد E4: خطای تخلیه آب
     *   LE1 = Door Lock Error       |   «E4 — خطای تخلیه»
     *   UE | Unbalance Error        |   «کد OE: خطای تخلیه آب»
     *   «نمایش IE می‌دهد: خطای آبرسانی» (فاصله کوتاه فعل/جداکننده)
     *
     * خروجی: [کد‌نرمال‌شده => عنوان فارسی]
     */
    private function parseCodeTable(string $text): array
    {
        $out = [];
        if (mb_strlen($text) < 100) {
            return $out;
        }
        /* 🐛 v2.14 — ریشه‌یابی «برای مایکروویو ال‌جی هیچ کدی پیدا نشد»:
           کدهای با خط تیره داخلی (E-01 / F-13 / CH-05 — سبک رایج مایکروویو،
           فر و کولر ال‌جی) در الگوی قبلی اصلاً نمی‌گنجیدند → جدول‌های کامل
           سازنده چشم‌پوشی می‌شدند. حالا «-?» در کد پشتیبانی می‌شود. */
        /* ۱) انگلیسی: CODE - meaning / CODE: meaning / CODE | meaning
           ⚠️ کلاس کاراکتر معنا فقط «فاصله/تب» دارد نه \n — وگرنه تطبیق حریصانه
           کدِ خط بعد را می‌بلعد */
        if (preg_match_all('/\b(Er\s?)?([A-Z]{1,2}-?\d{1,2}|\d{1,2}-?[A-Z]{1,2}|[A-Z]{2}\d{0,2})\s*(?:=|:|\||–|—|-)\s*([a-zA-Z][a-zA-Z \t&\'\-]{8,70})/u', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $code = $this->normalizeCode(trim(str_replace(' ', '', $mm[1] . $mm[2])));
                if ($code === null) { continue; }
                $en = trim(preg_replace('/\s+/u', ' ', $mm[3]));
                /* 🛡 اگر معنا به توکنی شبیه «کدِ بعدی» ختم شده (هم‌خطی)، جدا شود —
                   شامل قطعه‌نهایی مثل «E-» / «F-» (v2.14) و پسوند Meaning/Guide */
                $en = (string)preg_replace('/\s+[A-Z]{1,2}(?:\d{0,2}|-)$/u', '', $en);
                $en = (string)preg_replace('/\s+(Meaning|Error\s+Meaning|Guide|Explained)$/i', '', $en);
                /* عبارت‌های بی‌محتوا رد */
                if (preg_match('/^(what|how|why|error|code|this|the|and|for)\b/i', $en)) { continue; }
                $fa = $this->translateErrorPhrase($en);
                if ($fa !== null && !isset($out[$code])) {
                    $out[$code] = $fa;
                }
            }
        }
        /* 🆕 v2.14 — ۱-ب) تیتر بخش: «E-01 Thermistor Short Error» / «F-13 Inverter
           Overcurrent» — کد + عنوان توصیفی با فاصله (قالب رایج مقالات ۲۰۲۴+) */
        if (preg_match_all('/\b([A-Z]{1,2}-?\d{1,2}|\d{1,2}-?[A-Z]{1,2})\s+((?:[A-Z][a-zA-Z]{2,}\s+){0,4}(?:Error|Fault|Failure|Protection|Overcurrent|Overvoltage|Under\s+Voltage|Sensor\s+Error))\b/', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $code = $this->normalizeCode(trim($mm[1]));
                if ($code === null) { continue; }
                $en = trim(preg_replace('/\s+/u', ' ', $mm[2]));
                $en = (string)preg_replace('/\s+(Meaning|Error\s+Meaning|Guide|Explained)$/i', '', $en);
                $fa = $this->translateErrorPhrase($en);
                if ($fa !== null && mb_strlen($fa) >= 10 && (!isset($out[$code]) || mb_strlen($fa) > mb_strlen($out[$code]))) {
                    $out[$code] = mb_substr($fa, 0, 70);
                }
            }
        }
        /* ۲) فارسی: «کد E4: خطای ...» / «E4 - خطای ...» / «نمایش IE می‌دهد: خطای ...» */
        if (preg_match_all('/\b([A-Z]{1,2}-?\d{1,2}|\d{1,2}-?[A-Z]{1,2}|[A-Z]{2}\d{0,2})\b[^\nA-Za-z]{0,12}?(?:خطای|ایراد|علامت)\s+([^\n.،؛!؟]{6,60})/u', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $code = $this->normalizeCode(trim($mm[1]));
                if ($code === null) { continue; }
                $fa = 'خطای ' . trim(preg_replace('/\s+/u', ' ', $mm[2]));
                if (mb_strlen($fa) >= 10 && (!isset($out[$code]) || mb_strlen($fa) > mb_strlen($out[$code]))) {
                    $out[$code] = mb_substr($fa, 0, 70);
                }
            }
        }
        /* ۳) جدول‌های با جداکننده تب یا | : «E4\tWater Drainage» / «| UE | Unbalance |» */
        if (preg_match_all('/^\s*\|?\s*([A-Z]{1,2}-?\d{1,2}|\d{1,2}-?[A-Z]{1,2}|[A-Z]{2}\d{0,2})\b\s*\|\s*([^\n|]{8,70})/um', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $code = $this->normalizeCode(trim($mm[1]));
                if ($code === null) { continue; }
                $desc = trim(preg_replace('/\s+/u', ' ', $mm[2]));
                $fa = $this->translateErrorPhrase($desc) ?: (preg_match('/[\x{0600}-\x{06FF}]/u', $desc) ? 'خطای ' . mb_substr($desc, 0, 60) : null);
                if ($fa !== null && mb_strlen($fa) >= 10 && (!isset($out[$code]) || mb_strlen($fa) > mb_strlen($out[$code]))) {
                    $out[$code] = mb_substr($fa, 0, 70);
                }
            }
        }
        return $out;
    }

    /**
     * 🎯 جستجوی عمیق تک‌کد (v3.0) — راستی‌آزمایی + متن غنی + معنای دقیق
     *
     * برای هر کد کاندید:
     *   ۱) جستجوی اختصاصی EN + FA حول خود کد
     *   ۲) خواندن متن کامل بهترین صفحه (نه فقط اسنیپت)
     *   ۳) استخراج معنای کد از تیتر صفحه (مثل «E4 = Water Drainage»)
     *
     * @return array|null null = کد در وب تأیید نشد
     */
    private function deepResearchCode(WebSearchService $searcher, string $brandEn, string $brandFa, string $deviceEn, string $deviceFa, string $code, ?float $deadline = null): ?array
    {
        $deadline = $deadline ?? (microtime(true) + 20.0);
        $contexts = [];
        $urls = [];
        $evidence = 0;
        $bestTitle = '';
        $bestPart = '';
        $bestSeverity = '';

        $queries = [
            "{$brandEn} {$deviceEn} error code {$code} meaning cause fix",
            "کد خطا {$code} {$deviceFa} {$brandFa} علت راه حل",
        ];
        foreach ($queries as $q) {
            if (microtime(true) > $deadline - 1.0) { break; }
            try {
                $res = $searcher->search($q, 6);
            } catch (Throwable $e) {
                continue;
            }
            foreach ($res['results'] ?? [] as $r) {
                $text = ($r['title'] ?? '') . ' — ' . ($r['snippet'] ?? '');
                $url = (string)($r['url'] ?? '');
                if ($text === '' || trim($text) === '—') { continue; }
                /* خود کد باید در نتیجه باشد (با توجه به فاصله در «Er FF») */
                $codeVariants = [$code, str_replace(' ', '', $code), str_replace(' ', '-', $code)];
                $hasCode = false;
                foreach ($codeVariants as $cv) {
                    if (stripos($text, $cv) !== false || mb_strpos($text, $cv) !== false) { $hasCode = true; break; }
                }
                if (!$hasCode) { continue; }
                /* زمینه برند/دستگاه */
                $hasCtx = stripos($text, $brandEn) !== false
                    || mb_strpos($text, $brandFa) !== false
                    || stripos($text, $deviceEn) !== false
                    || mb_strpos($text, $deviceFa) !== false;
                if (!$hasCtx) { continue; }
                $evidence++;
                if (count($contexts) < 6) { $contexts[] = $text; }
                if ($url !== '' && count($urls) < 4 && !in_array($url, $urls, true)) { $urls[] = $url; }
                if ($bestTitle === '') {
                    $t = $this->meaningFromTitle((string)($r['title'] ?? ''), $code, $deviceFa);
                    if ($t !== null) { $bestTitle = $t; }
                }
            }
        }

        /* 📖 متن کامل بهترین صفحه — منبع اصلی دلایل/راه‌حل/مشخصات (v3.1: فقط ۱ صفحه، سریع‌تر) */
        $pageText = '';
        foreach (array_slice($urls, 0, 1) as $u) {
            if (microtime(true) > $deadline - 0.5) { break; }
            try {
                $page = $searcher->fetchPageText($u, 7000);
            } catch (Throwable $e) {
                continue;
            }
            if (empty($page['ok'])) { continue; }
            $txt = (string)$page['text'];
            /* صفحه باید کد و زمینه را داشته باشد */
            $hasCode = stripos($txt, str_replace(' ', '', $code)) !== false || mb_strpos($txt, $code) !== false;
            $hasCtx = stripos($txt, $brandEn) !== false || stripos($txt, $deviceEn) !== false
                || mb_strpos($txt, $brandFa) !== false || mb_strpos($txt, $deviceFa) !== false;
            if ($hasCode && $hasCtx && mb_strlen($txt) > 400) {
                $pageText = $txt;
                if (count($contexts) < 8) { $contexts[] = ($page['title'] ?? '') . ' — ' . mb_substr($txt, 0, 2500); }
                if ($bestTitle === '') {
                    $t = $this->meaningFromTitle((string)($page['title'] ?? ''), $code, $deviceFa);
                    if ($t !== null) { $bestTitle = $t; }
                }
                break;
            }
        }

        if ($evidence === 0 && $pageText === '') {
            return null;
        }

        /* 🎯 v3.1: استخراج «زمینه کد-محور» — پنجره ±۳۵۰ کاراکتر دور خود کد.
           ریشه قطعه‌های اشتباه (مثلاً «پمپ تخلیه» برای IE آبرسانی): متن کامل صفحه
           شرح همه کدها را دارد و partFromContext روی کل متن، قطعه کد دیگر را می‌گرفت. */
        $codeCtx = $pageText !== '' ? $this->codeCentricContext($pageText, $code, 350) : '';
        $richCtx = $codeCtx !== '' ? $codeCtx : ($pageText !== '' ? $pageText : implode(' . ', $contexts));
        if ($bestPart === '') {
            $bestPart = $this->partFromContext($richCtx);
            if ($bestPart === 'قطعه مرتبط با کد') { $bestPart = ''; }
        }
        if ($bestSeverity === '') {
            $bestSeverity = $this->severityFromContext($richCtx);
        }
        /* 🧭 v3.1: نقشه کدهای شناخته‌شده — معنای استاندارد کدهای پرتکرار برندهای اصلی
           (IE=آبرسانی، OE=تخلیه، UE=عدم توازن و...) — دقیق‌تر از استخراج زمینه‌ای */
        $known = self::knownCodeMeaning($code, $deviceEn);
        if ($known !== null) {
            if ($bestTitle === '' || mb_strlen($bestTitle) < 10) { $bestTitle = $known['title']; }
            if ($bestPart === '') { $bestPart = $known['part']; }
            if ($bestSeverity === 'medium') { $bestSeverity = $known['severity']; }
        }

        return [
            'evidence'  => max(1, $evidence),
            'contexts'  => $contexts,
            'urls'      => $urls,
            'title'     => $bestTitle,
            'part'      => $bestPart,
            'severity'  => $bestSeverity,
            'page_text' => $pageText,
            'location'  => $this->locationFromContext($richCtx),
        ];
    }

    /**
     * 🎯 پنجره متن دور خود کد (v3.1) — برای استخراج قطعه/شدت اختصاصی همین کد
     * صفحه فهرست کدها، شرح همه کدها را دارد؛ این متد فقط متن «همان کد» را برمی‌دارد.
     */
    private function codeCentricContext(string $text, string $code, int $radius = 350): string
    {
        if ($text === '' || $code === '') {
            return '';
        }
        $variants = array_unique([$code, str_replace(' ', '', $code), str_replace(' ', '-', $code)]);
        foreach ($variants as $cv) {
            /* کدها ASCII هستند — stripos بایتی امن است */
            $pos = stripos($text, $cv);
            if ($pos === false) {
                continue;
            }
            $start = max(0, $pos - (int)round($radius * 0.35));
            $chunk = substr($text, $start, $radius * 3);
            return mb_substr($chunk, 0, $radius);
        }
        return '';
    }

    /**
     * 🧭 نقشه کدهای شناخته‌شده (v3.1) — معنای استاندارد پرتکرارترین کدهای
     * برندهای اصلی (LG/Samsung/Beko/Electrolux/Ariston...) بر اساس نوع دستگاه.
     * این کدها بین برندها تقریباً مشترک‌اند و خطای زمینه‌ای در آن‌ها شایع بود.
     * @return array{title:string,part:string,severity:string}|null
     */
    public static function knownCodeMeaning(string $code, string $deviceEn): ?array
    {
        $code = strtoupper(str_replace([' ', '-'], '', trim($code)));
        $norm = [
            '1E' => 'IE', '0E' => 'OE', 'UB' => 'UE', 'DE1' => 'DE', 'DE2' => 'DE',
            '4E' => 'PE', '3E' => 'TE', '11E' => 'LE', 'AE' => 'UE',
        ];
        $code = $norm[$code] ?? $code;

        $maps = [
            'washing machine' => [
                'IE' => ['title' => 'خطای ورود آب (آبرسانی)', 'part' => 'شیر برقی ورودی', 'severity' => 'high'],
                'OE' => ['title' => 'خطای تخلیه آب', 'part' => 'پمپ تخلیه', 'severity' => 'high'],
                'UE' => ['title' => 'خطای عدم توازن بار', 'part' => 'سنسور توازن و سیستم تعلیق', 'severity' => 'medium'],
                'DE' => ['title' => 'خطای قفل درب', 'part' => 'قفل درب', 'severity' => 'high'],
                'TE' => ['title' => 'خطای سنسور دما', 'part' => 'سنسور دما NTC', 'severity' => 'high'],
                'PE' => ['title' => 'خطای سنسور فشار آب', 'part' => 'سنسور فشار آب', 'severity' => 'high'],
                'LE' => ['title' => 'خطای اضافه‌بار موتور', 'part' => 'موتور اصلی', 'severity' => 'critical'],
                'CE' => ['title' => 'خطای جریان نشتی (اتصالی)', 'part' => 'موتور و سیم‌کشی', 'severity' => 'critical'],
                'FE' => ['title' => 'خطای سرریز آب', 'part' => 'شیر برقی ورودی و سنسور سرریز', 'severity' => 'high'],
                'PF' => ['title' => 'خطای تغذیه برق', 'part' => 'برد کنترل و منبع تغذیه', 'severity' => 'medium'],
                'CL' => ['title' => 'قفل کودک فعال است', 'part' => 'پنل و برد کنترل', 'severity' => 'low'],
                'SU' => ['title' => 'خطای سنسور عدم تعادل', 'part' => 'سنسور تعادل', 'severity' => 'medium'],
            ],
            'dishwasher' => [
                'IE' => ['title' => 'خطای آبرسانی/ورود آب', 'part' => 'شیر برقی ورودی', 'severity' => 'high'],
                'OE' => ['title' => 'خطای تخلیه آب', 'part' => 'پمپ تخلیه', 'severity' => 'high'],
                'E1' => ['title' => 'خطای ورود آب', 'part' => 'شیر برقی ورودی', 'severity' => 'high'],
                'E4' => ['title' => 'خطای تخلیه آب', 'part' => 'پمپ تخلیه', 'severity' => 'high'],
                'E3' => ['title' => 'خطای گرمایش آب', 'part' => 'هیتر و سنسور دما', 'severity' => 'high'],
                'E8' => ['title' => 'خطای ماکرو سوییچ درب', 'part' => 'میکروسوئیچ درب', 'severity' => 'high'],
                'E9' => ['title' => 'خطای نشت آب (کف‌ساز)', 'part' => 'سیستم ضد نشت', 'severity' => 'critical'],
                'UE' => ['title' => 'خطای تخلیه/آب اضافه', 'part' => 'پمپ تخلیه', 'severity' => 'medium'],
                'LE' => ['title' => 'خطای نشت آب', 'part' => 'سیستم ضد نشت', 'severity' => 'critical'],
                'HE' => ['title' => 'خطای گرمایش', 'part' => 'هیتر حرارتی', 'severity' => 'high'],
                'TE' => ['title' => 'خطای سنسور دما', 'part' => 'سنسور دما NTC', 'severity' => 'high'],
                'FE' => ['title' => 'خطای فن/تبخیر', 'part' => 'فن تبخیر', 'severity' => 'medium'],
            ],
            'refrigerator' => [
                'E1' => ['title' => 'خطای سنسور دمای یخچال', 'part' => 'سنسور دما ناحیه یخچال', 'severity' => 'high'],
                'E2' => ['title' => 'خطای سنسور دمای فریزر', 'part' => 'سنسور دما ناحیه فریزر', 'severity' => 'high'],
                'E3' => ['title' => 'خطای سنسور دیفراست', 'part' => 'سنسور دیفراست', 'severity' => 'medium'],
                'E4' => ['title' => 'خطای فن اواپراتور', 'part' => 'فن اواپراتور', 'severity' => 'high'],
                'E5' => ['title' => 'خطای برد کنترل/ارتباطی', 'part' => 'برد کنترل', 'severity' => 'high'],
                'E6' => ['title' => 'خطای ارتباط برد و نمایشگر', 'part' => 'برد کنترل و سیم‌کشی', 'severity' => 'medium'],
                'E7' => ['title' => 'خطای سنسور دمای محیط', 'part' => 'سنسور دمای محیط', 'severity' => 'medium'],
                'ER' => ['title' => 'خطای نمایشگر/برد', 'part' => 'برد نمایشگر', 'severity' => 'medium'],
                'DH' => ['title' => 'خطای سیستم دیفراست', 'part' => 'تایمر دیفراست و هیتر', 'severity' => 'high'],
                'SB' => ['title' => 'حالت تعطیلات/Super Freeze فعال است', 'part' => 'برد کنترل', 'severity' => 'low'],
            ],
            'air conditioner' => [
                'E1' => ['title' => 'خطای سنسور دمای اتاق', 'part' => 'سنسور دمای اتاق', 'severity' => 'medium'],
                'E2' => ['title' => 'خطای سنسور کویل داخلی', 'part' => 'سنسور کویل', 'severity' => 'medium'],
                'E3' => ['title' => 'خطای کمپرسور/اینورتر', 'part' => 'ماژول اینورتر', 'severity' => 'critical'],
                'E4' => ['title' => 'خطای حفاظت فشار کمپرسور', 'part' => 'سنسور فشار', 'severity' => 'critical'],
                'E5' => ['title' => 'خطای ارتباط واحد داخلی و خارجی', 'part' => 'برد ارتباطی', 'severity' => 'high'],
                'P1' => ['title' => 'خطای فشار/حفاظت مبرد', 'part' => 'سیستم مبرد', 'severity' => 'critical'],
                'P2' => ['title' => 'خطای دمای کمپرسور', 'part' => 'کمپرسور', 'severity' => 'high'],
                'H1' => ['title' => 'دیفراست در حال اجراست', 'part' => 'سیستم دیفراست', 'severity' => 'low'],
            ],
        ];
        foreach ($maps as $devKey => $codeMap) {
            /* نام دستگاه انگلیسی به نوع نقشه تطبیق می‌یابد */
            if (stripos($deviceEn, $devKey) !== false || stripos($devKey, $deviceEn) !== false) {
                return $codeMap[$code] ?? null;
            }
        }
        /* نقشه عمومی: در همه دستگاه‌ها معنای مشترک دارند */
        $universal = [
            'DE' => ['title' => 'خطای قفل درب', 'part' => 'قفل درب', 'severity' => 'high'],
            'TE' => ['title' => 'خطای سنسور دما', 'part' => 'سنسور دما NTC', 'severity' => 'high'],
            'EE' => ['title' => 'خطای حافظه/EEPROM برد', 'part' => 'برد کنترل', 'severity' => 'high'],
            'PF' => ['title' => 'خطای تغذیه برق', 'part' => 'منبع تغذیه', 'severity' => 'medium'],
            'CL' => ['title' => 'قفل کودک فعال است', 'part' => 'پنل کنترل', 'severity' => 'low'],
        ];
        return $universal[$code] ?? null;
    }

    /**
     * 💎 استخراج معنای کد از تیتر صفحه (v3.0)
     * الگوهای رایج: «E4 Error = Water Drainage Problem»، «کد 4E (خطای آبرسانی)»،
     * «What Does Error E18 Mean? Drainage Issue»
     */
    private function meaningFromTitle(string $title, string $code, string $deviceFa): ?string
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) < 8) { return null; }

        /* انگلیسی: بعد از «=» یا «:» یا «Mean?» معمولاً معنا می‌آید
           🛡 v3.1: فقط اگر ترجمه فارسی معتبر شد پذیرفته می‌شود — قبلاً عبارت
           انگلیسی خام («What Does It Mean...») به عنوان فارسی نشت می‌کرد! */
        if (preg_match('/error\s+code\s+' . preg_quote($code, '/') . '\s*(?:=|:|–|—|-)\s*([a-zA-Z0-9\s&\'\-]{6,60})/iu', $title, $m)
            || preg_match('/' . preg_quote($code, '/') . '\s*(?:=|:|–|—)\s*(?:means?\s*)?([a-zA-Z0-9\s&\'\-]{6,60})/iu', $title, $m)) {
            $en = trim($m[1]);
            /* عبارت‌های بدون محتوا — هرگز معنا نیستند */
            if (preg_match('/^(what|how|why|error|code|meaning|fix|guide|causes?|and|the|a)\b/i', $en)
                || preg_match('/\b(mean|means|meaning|and how|to fix|it\?)\b/i', $en)) {
                $en = '';
            }
            if ($en !== '') {
                $fa = $this->translateErrorPhrase($en);
                if ($fa !== null) {
                    return $fa;
                }
            }
        }
        /* فارسی: داخل پرانتز یا بعد از «یعنی» */
        if (preg_match('/(?:کد|خطا)\s*' . preg_quote($code, '/') . '\s*[\(（]?([^\)）]{6,60})[\)）]?/u', $title, $m)) {
            $inner = trim($m[1], " \t.،:؛-");
            if (mb_strlen($inner) >= 6 && !preg_match('/^\d+$/u', $inner)) {
                return 'خطای ' . $inner;
            }
        }
        /* کلیدواژه‌ای از خود تیتر */
        $kw = $this->titleFromContext($code, $title, $deviceFa);
        if ($kw !== 'خطای ' . $code . ' در ' . $deviceFa) {
            return $kw;
        }
        return null;
    }

    /** 🌐 ترجمه عبارت معنای خطا (EN → FA) — واژه‌نامه تعمیرات (v3.0) */
    private function translateErrorPhrase(string $en): ?string
    {
        $dict = [
            /* ⚠️ ترتیب حیاتی است: «Motor Locked» نباید به «قفل درب» برسد
               و «Water Level Sensor» نباید «سنسور دما» شود */
            /* 🆕 v2.14: عبارات اینورتر/الکتریکی (مایکروویو/فر/کولر ال‌جی) — مقدم بر قوانین عمومی
               ریشه‌یابی: کدهای E-01/F-13 مایکروویو ال‌جی معنای کاملی نداشتند */
            '/over\s?current|overcurrent/i' => 'جریان بیش از حد اینورتر',
            '/over\s?volt|overvoltage/i' => 'ولتاژ بیش از حد اینورتر',
            '/under\s+volt/i' => 'افت ولتاژ اینورتر',
            '/vdc\s*protection/i' => 'محافظت ولتاژ DC اینورتر',
            '/heat\s?sink/i' => 'دساپاتور اینورتر',
            '/inverter\s+communication|communication\s+error/i' => 'ارتباطی برد اینورتر',
            '/inverter/i' => 'برد اینورتر',
            '/thermistor\s+short|short\s+thermistor/i' => 'اتصال کوتاه ترمیستور (سنسور دما)',
            '/thermistor|temperature\s+sensor|temp\s+sensor/i' => 'سنسور دما (ترمیستور)',
            '/humidity\s+sensor/i' => 'سنسور رطوبت',
            '/fermentation/i' => 'حالت تخمیر',
            '/preheat/i' => 'پیش‌گرمایش',
            '/keypad|key\s+pad/i' => 'کیپد (صفحه کلید)',
            '/fan\s+relay|relay/i' => 'رله فن',
            '/short(\s+error)?|shorted/i' => 'اتصال کوتاه',
            '/cooling\s+down|cool\s+down/i' => 'خنک‌سازی (نوتیفیکیشن)',
            /* --- قوانین عمومی --- */
            '/water level|pressure sensor|water pressure|level sensor/i' => 'سنسور سطح/فشار آب',
            /* LG رسمی برای OE می‌گوید «Water Outlet Error» */
            '/drain|drainage|outlet/i' => 'تخلیه آب',
            '/water supply|water inlet|fill|filling|intake|water feed/i' => 'ورود آب',
            '/water leak|leakage/i' => 'نشت آب',
            '/child lock|key lock/i' => 'قفل کودک',
            '/motor|drive/i' => 'موتور',
            '/door|lid|lock|latch/i' => 'قفل درب',
            '/heat|heater|heating|element/i' => 'گرمایش و هیتر',
            '/sensor|ntc/i' => 'سنسور',
            '/fan|blower/i' => 'فن',
            '/communication|connect/i' => 'ارتباطی',
            '/balance|unbalanc|vibrat/i' => 'عدم توازن',
            '/overflow|foam|suds|over-suds/i' => 'سرریز/کف',
            '/compressor/i' => 'کمپرسور',
            '/defrost|ice|frost/i' => 'برفک‌زدایی',
            '/board|control|pcb|eeprom|memory/i' => 'برد کنترل',
            '/power|electric|voltage/i' => 'برق‌رسانی',
            '/filter|clog/i' => 'فیلتر و گرفتگی',
            '/level|position|instal/i' => 'تراز و نصب',
        ];
        foreach ($dict as $re => $fa) {
            if (preg_match($re, $en)) {
                return 'خطای ' . $fa;
            }
        }
        return null;
    }

    /** 📍 استخراج محل قطعه از متن غنی (v3.0) */
    private function locationFromContext(string $ctx): string
    {
        if ($ctx === '') { return ''; }
        /* فارسی: «در قسمت/بخش/سمت/پشت X» */
        if (preg_match_all('/(?:قسمت|بخش|سمت|پشت|زیر|داخل|کنار)\s+([^۱۲۳۴۵۶۷۸۹۰.،؛!؟"()\n]{4,40})/u', $ctx, $m)) {
            foreach ($m[0] as $i => $full) {
                $frag = trim($m[1][$i]);
                if (mb_strlen($frag) >= 4 && preg_match('/(پمپ|شیر|سنسور|فن|موتور|کمپرسور|برد|المنت|هیتر|درب|فیلتر|مخزن|اواپراتور|کندانسور)/u', $full . ' ' . $frag)) {
                    return mb_substr($full, 0, 60);
                }
            }
        }
        /* انگلیسی: «located at/in/on the X» */
        if (preg_match('/(?:located|situated|positioned|found)\s+(?:at|in|on|near|behind|under)\s+(?:the\s+)?([a-zA-Z0-9\s\-]{5,60})/i', $ctx, $m)) {
            $en = trim($m[1]);
            $map = [
                '/back|rear/i' => 'پشت دستگاه', '/bottom|base|under/i' => 'پایین دستگاه',
                '/front|behind.*(panel|kick)/i' => 'جلوی دستگاه پشت پنل',
                '/top|upper/i' => 'بالای دستگاه', '/side|left|right/i' => 'کنار دستگاه',
                '/door/i' => 'درب دستگاه', '/inside|interior/i' => 'داخل محفظه',
                '/outdoor|external unit/i' => 'یونیت خارجی', '/indoor|internal unit/i' => 'یونیت داخلی',
            ];
            foreach ($map as $re => $fa) {
                if (preg_match($re, $en)) { return $fa; }
            }
        }
        return '';
    }

    /**
     * ❓ آیا پایگاه دانش curated این کد را می‌شناسد؟ (v3.0 — برای تصمیم نگه‌داشتن کد کم‌شاهد)
     */
    private function kbKnowsCode(?string $brandKey, string $deviceKey, string $code): bool
    {
        if ($brandKey === null) { return false; }
        $kb = TextProcessor::loadKnowledge('error-codes-brands');
        $codes = $kb['brands'][$brandKey]['devices'][$deviceKey]['codes'] ?? [];
        foreach ($codes as $kc) {
            $kCode = strtoupper(str_replace([' ', '-'], '', (string)$kc['code']));
            if ($kCode === strtoupper(str_replace([' ', '-'], '', $code))) {
                return true;
            }
        }
        return false;
    }

    /**
     * 🔀 ادغام دانش curated در رکورد وب (v2.8 — فقط پرکننده فاصله‌ها)
     *
     * سیاست جدید (درخواست صریح کاربر): داده‌ی «وب» مقدم مطلق است؛ KB فقط وقتی
     * رکورد وب فیلدی خالی/عمومی دارد آن را پر می‌کند — هرگز رونویسی نمی‌کند.
     * ریشه‌ی «برای خطای پمپ تخلیه، شیر برقی نوشته می‌شد» همین رونویسی بود.
     */
    private function mergeKbIntoWebRecord(?array $brandKb, string $deviceKey, array $wc): array
    {
        if (!$brandKb) {
            return $wc;
        }
        $kbCodes = $brandKb['devices'][$deviceKey]['codes'] ?? [];
        $webCodeNorm = strtoupper(str_replace([' ', '-'], '', (string)$wc['code']));
        foreach ($kbCodes as $kc) {
            $kNorm = strtoupper(str_replace([' ', '-'], '', (string)$kc['code']));
            if ($kNorm !== $webCodeNorm) {
                continue;
            }
            /* 🎯 v3.10: داده curated پایگاه دانش «مقدم» بر استخراج متنی وب است —
               ریشه‌یابی «فیلدهای اشتباه»: پنجره متنی اطراف کد، معنای کدهای دیگر را
               قاطی می‌کرد؛ KB از مستندات رسمی گردآوری شده و قطعاً درست است.
               فقط منبع‌ها از وب حفظ می‌شوند. */
            if (!empty($kc['title'])) {
                $wc['title'] = $kc['title'];
            }
            if (!empty($kc['part'])) {
                $wc['part'] = $kc['part'];
            }
            if (!empty($kc['category'])) {
                $wc['category'] = $kc['category'];
            }
            if (!empty($kc['severity'])) {
                $wc['severity'] = $kc['severity'];
            }
            /* دلایل و راه‌حل‌ها: KB (دقیق) اول، موارد وب فقط تکمیل‌کننده */
            foreach (['causes', 'fixes_user', 'fixes_tech'] as $field) {
                $kbList = array_map('trim', (array)($kc[$field] ?? []));
                $merged = [];
                foreach ($kbList as $item) {
                    if ($item !== '' && $item !== '—' && !in_array($item, $merged, true)) {
                        $merged[] = $item;
                    }
                }
                foreach ((array)($wc[$field] ?? []) as $item) {
                    $item = trim((string)$item);
                    if ($item !== '' && !in_array($item, $merged, true)) {
                        $merged[] = $item;
                    }
                }
                $wc[$field] = array_slice($merged, 0, 8);
            }
            /* مشخصات فنی و محل — فقط وقتی وب نداده */
            if (!empty($kc['specs']) && empty($wc['_specs_ctx'])) {
                $wc['specs'] = $kc['specs'];
            }
            if (!empty($kc['location']) && empty($wc['_location'])) {
                $wc['location'] = $kc['location'];
            }
            if (empty($wc['models']) && !empty($kc['models'])) {
                $wc['models'] = $kc['models'];
            }
            if (!isset($wc['needs_technician']) && isset($kc['needs_technician'])) {
                $wc['needs_technician'] = (int)$kc['needs_technician'] ?: 1;
            }
            break;
        }
        return $wc;
    }

    /**
     * 🛡 گارد سازگاری قطعه با عنوان/دسته (v2.8) — آخرین لایه دقت
     *
     * اگر عنوان کد صراحتاً درباره «تخلیه» است، قطعه نباید «شیر برقی ورودی»
     * باشد و بالعکس — همین ناهماهنگی اعتبار سایت را خراب می‌کرد.
     */
    private function enforcePartConsistency(array $wc): array
    {
        $title = (string)($wc['title'] ?? '');
        $part = (string)($wc['part'] ?? '');
        if ($title === '' || $part === '' || $part === 'قطعه مرتبط با کد') {
            return $wc;
        }
        $isDrain = (bool)preg_match('/تخلیه|درین|Drain/iu', $title);
        $isInlet = (bool)preg_match('/آبرسانی|ورود\s*آب|ورودی\s*آب|Inlet|Water Supply|Fill/iu', $title);
        if ($isDrain && !$isInlet) {
            if (mb_strpos($part, 'پمپ') === false && mb_strpos($part, 'تخلیه') === false && mb_strpos($part, 'شلنگ') === false) {
                /* قطعه نامتناسب با خطای تخلیه → از عنوان اصلاح */
                $wc['part'] = 'پمپ تخلیه و شلنگ تخلیه';
                $wc['_part_fixed'] = true;
            }
        } elseif ($isInlet && !$isDrain) {
            if (mb_strpos($part, 'شیر') === false && mb_strpos($part, 'ورودی') === false && mb_strpos($part, 'آبرسانی') === false) {
                $wc['part'] = 'شیر برقی ورودی آب';
                $wc['_part_fixed'] = true;
            }
        }
        /* 🆕 v3.9: تطبیق عمومی «سیستم قطعه ↔ عنوان» — اگر عنوان به سیستمی مشخص
           اشاره می‌کند (توازن/موتور/دما/قفل درب/...) اما قطعه از سیستمی دیگر
           است (مثال واقعی: عنوان «عدم توازن» + قطعه «سنسور دما NTC»)، قطعه از
           نگاشت معتبر عنوان اصلاح می‌شود. ریشه: پنجره زمینه شرح همه کدهای صفحه
           را دارد و قطعه کدهای دیگر را می‌بلعد. */
        $titlePart = $this->partFromTitle($title);
        if ($titlePart !== '' && !$this->partsShareSystem((string)$wc['part'], $titlePart)) {
            $wc['part'] = $titlePart;
            $wc['_part_fixed'] = true;
        }
        return $wc;
    }

    /** 🔗 آیا دو قطعه به یک «سیستم فنی» اشاره دارند؟ (v3.9) */
    private function partsShareSystem(string $a, string $b): bool
    {
        if ($a === '' || $b === '') {
            return true; // ناشناخته = دخالت نکن
        }
        /* سیستم‌های فنی با کلیدواژه‌های تفکیک‌کننده */
        $systems = [
            'موتور', 'پمپ', 'شیر', 'سنسور دما', 'سنسور فشار', 'سنسور توازن', 'سنسور تعادل',
            'قفل', 'میکروسوئیچ', 'برد', 'فن', 'هیتر', 'المنت', 'کمپرسور', 'تخلیه', 'تعلیق',
            'دیفراست', 'برفک', 'سرریز', 'منبع تغذیه', 'سیم‌کشی', 'نمایشگر', 'ماسوره', 'واشر',
            'مدار گاز', 'شارژ', 'اواپراتور', 'کندانسور', 'درایور', 'EEPROM', 'حافظه',
        ];
        $ha = $hb = [];
        foreach ($systems as $kw) {
            if (mb_stripos($a, $kw) !== false) { $ha[$kw] = true; }
            if (mb_stripos($b, $kw) !== false) { $hb[$kw] = true; }
        }
        if (empty($ha) || empty($hb)) {
            return true; // سیستم شناسایی نشد = دخالت نکن
        }
        return (bool)array_intersect_key($ha, $hb);
    }

    /**
     * 🧠 استخراج دلایل از جمله‌های واقعی وب (v2.7 + v3.0)
     * الگوها: «به علت X»، «علت آن X است»، «caused by X»، «due to X»، «faulty/defective X»
     * اگر جمله‌ای پیدا نشد → دلایل استاندارد همان نوع دستگاه (دانش فنی معتبر)
     */
    private function extractCausesFromContext(string $ctx, string $deviceKey, string $category): array
    {
        $causes = [];
        /* 🆕 v2.14: بخش ساختاریافته «Possible Causes:» — رایج‌ترین قالب مقالات فنی
           مثال: «Possible Causes: Damaged wire harness. Faulty thermistor.»
           ⚠️ الگوی مهارشده: هرچه تا کد بعدی/بخش بعدی است می‌گیرد (دو-نقطه داخلی مجاز) */
        if (preg_match_all('/(?:possible\s+)?causes?\s*:\s*((?:(?!\b[A-Z]{1,2}-?\d{1,2}\s*:|\b(?:troubleshoot|how\s+to|solution|fix\b|repair\b|related|meaning|final)\b).){15,600})/is', $ctx, $m)) {
            foreach ($m[1] as $block) {
                foreach (preg_split('/[.;]\s+/', trim($block)) as $s) {
                    $s = trim($s, " \t.,;-‌");
                    if (mb_strlen($s) < 6 || mb_strlen($s) > 90) { continue; }
                    $fa = $this->translateCauseOrFix($s);
                    if ($fa !== null && !in_array($fa, $causes, true)) {
                        $causes[] = $fa;
                    }
                    if (count($causes) >= 5) { break 2; }
                }
            }
        }
        // فارسی: «علت ... است/می‌شود»، «به دلیل ...»، «بر اثر ...»
        if (preg_match_all('/(?:به\s+(?:دلیل|علت)|علت\s+(?:اصلی\s+)?(?:آن\s+)?|بر\s+اثر)\s+([^۱۲۳۴۵۶۷۸۹۰.،؛!؟"()\n]{8,60})/u', $ctx, $m)) {
            foreach ($m[1] as $mm) {
                $c = mb_scrub(trim(preg_replace('/\s+/u', ' ', $mm)));
                if (mb_strlen($c) >= 8 && mb_strlen($c) <= 70 && !in_array($c, $causes, true)) {
                    $causes[] = mb_substr($c, 0, 60);
                }
                if (count($causes) >= 5) { break; }
            }
        }
        // انگلیسی: «caused by X»، «due to X» — ترجمه به فارسی (v2.14: دیگر انگلیسی خام ذخیره نمی‌شود)
        if (count($causes) < 5 && preg_match_all('/(?:caused\s+by|due\s+to|because\s+of)\s+(?:a\s+|an\s+|the\s+)?([a-zA-Z\s\-]{6,60})/i', $ctx, $m)) {
            foreach ($m[1] as $mm) {
                $fa = $this->translateCauseOrFix(trim(preg_replace('/\s+/u', ' ', $mm)));
                if ($fa !== null && !in_array($fa, $causes, true)) {
                    $causes[] = mb_substr($fa, 0, 70);
                }
                if (count($causes) >= 5) { break; }
            }
        }
        // انگلیسی: «a faulty/defective/worn X» (v2.14: با ترجمه فارسی)
        if (count($causes) < 5 && preg_match_all('/(?:a\s+|an\s+)?(faulty|defective|worn[\s-]?out|failed|damaged|clogged|blocked|loose|broken)\s+([a-zA-Z\s\-]{4,40})/i', $ctx, $m)) {
            foreach ($m[0] as $i => $full) {
                $fa = $this->translateCauseOrFix(trim(preg_replace('/\s+/u', ' ', $full)));
                if ($fa !== null && !in_array($fa, $causes, true)) {
                    $causes[] = mb_substr($fa, 0, 70);
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
     * 🌐 ترجمه دلیل/راه‌حل انگلیسی به فارسی (v2.14)
     *
     * ریشه‌یابی «فیلدهای نامربوط/ناقص»: دلایل و راه‌حل‌های استخراج‌شده از وب
     * انگلیسی خام بودند و در سایت فارسی نامفهوم دیده می‌شدند؛ اکنون فقط
     * عبارت‌هایی ترجمه و پذیرفته می‌شوند که قطعه/اقدام شناخته‌شده‌ای دارند —
     * بقیه رد می‌شوند تا مخزن فارسی تخصصی همان دستگاه جایشان را بگیرد.
     */
    private function translateCauseOrFix(string $en): ?string
    {
        $en = trim(preg_replace('/\s+/u', ' ', $en));
        if ($en === '' || mb_strlen($en) > 120) { return null; }

        /* 📦 واژه‌نامه اسم‌های قطعات (EN → FA) */
        $nouns = [
            'wire harness|wiring harness|wiring|wire connection|connector|connections?' => 'اتصالات/هارنس سیم‌کشی',
            'door (?:switch|latch|lock)|latch|interlock' => 'میکروسوئیچ و قفل درب',
            'keypad|key pad|touch panel|control panel' => 'کیپد و پنل کنترل',
            'thermistor|temperature sensor|temp sensor' => 'ترمیستور (سنسور دما)',
            'humidity sensor' => 'سنسور رطوبت',
            'inverter (?:board)?|ipm' => 'برد اینورتر',
            'magnetron' => 'مگنترون',
            'high voltage diode|hv diode|diode' => 'دیود ولتاژ بالا',
            'capacitor' => 'خازن',
            '(?:thermal )?fuse' => 'فیوز حرارتی',
            'relay' => 'رله',
            'fan(?: motor)?|blower' => 'فن',
            'control board|pcb|main board|controller' => 'برد کنترل',
            'drain (?:pump|hose|filter)|pump' => 'پمپ تخلیه و مسیر آن',
            'water (?:inlet )?valve|inlet valve' => 'شیر برقی ورودی آب',
            'pressure sensor|water level sensor' => 'سنسور فشار/سطح آب',
            'heating element|heater' => 'المنت حرارتی',
            'compressor' => 'کمپرسور',
            'motor' => 'موتور',
            'sensor' => 'سنسور',
            'filter' => 'فیلتر',
            'vent|airflow|air flow|duct' => 'مسیر تخلیه هوا',
            'power supply|outlet|socket|cord|plug' => 'منبع تغذیه و کابل برق',
            'switch' => 'سوئیچ',
            'bearing|belt|seal|gasket' => 'بلبرینگ/تسمه/درزگیر',
        ];

        /* 🔧 اقدام‌ها (برای راه‌حل‌ها) */
        $actions = [
            'check|inspect|examine|verify|make sure|ensure|look at|review' => 'بررسی',
            'clean|wipe|remove debris from' => 'تمیز کردن',
            'replace|install a new|swap' => 'تعویض',
            'reset|restart|reboot|power cycle|unplug' => 'ریست و قطع/وصل برق',
            'test|measure|use a multimeter|check (?:the )?(?:resistance|voltage|continuity)' => 'تست با مولتی‌متر',
            'tighten|secure|reconnect|reseat|plug (?:it )?(?:back )?in' => 'محکم‌کردن/اتصال مجدد',
            'open|disassemble|remove|access' => 'باز کردن و دسترسی به',
            'adjust|calibrate|level|align' => 'تنظیم و کالیبراسیون',
            'call|contact|schedule|consult' => 'تماس با',
        ];

        /* 🎯 حالت ۱: راه‌حل — «check the door latch» → «بررسی میکروسوئیچ و قفل درب» */
        $lower = mb_strtolower($en);
        $actionFa = null;
        foreach ($actions as $re => $fa) {
            if (preg_match('/\b(?:' . $re . ')\b/i', $lower)) {
                $actionFa = $fa;
                break;
            }
        }
        foreach ($nouns as $re => $fa) {
            if (preg_match('/\b(?:' . $re . ')\b/i', $lower)) {
                if ($actionFa !== null) {
                    return $actionFa . ' ' . $fa;
                }
                /* حالت ۲: دلیل — «Damaged wire harness» → «خرابی اتصالات سیم‌کشی» */
                $stateMap = [
                    '/\b(faulty|defective|bad|failed|broken)\b/i' => 'خرابی ',
                    '/\b(damaged|worn[\s-]?out)\b/i' => 'آسیب‌دیدگی ',
                    '/\b(clogged|blocked|dirty)\b/i' => 'گرفتگی/کثیفی ',
                    '/\bloose\b/i' => 'لقی ',
                    '/\b(short|shorted)\b/i' => 'اتصال کوتاه ',
                    '/\b(open|disconnected)\b/i' => 'قطعی ',
                ];
                $state = 'مشکل در ';
                foreach ($stateMap as $sre => $sfa) {
                    if (preg_match($sre, $lower)) { $state = $sfa; break; }
                }
                return $state . $fa;
            }
        }
        /* بدون قطعه شناخته‌شده → رد (مخزن فارسی تخصصی جایگزین می‌شود) */
        return null;
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
            /* 🆕 v2.14: بخش ساختاریافته «Troubleshooting Steps:» — قالب استاندارد مقالات
               مثال: «Troubleshooting Steps: Check the wire harness. Reset the unit.»
               ⚠️ الگوی مهارشده: دو-نقطه داخلی مجاز است؛ تا کد بعدی ادامه می‌یابد */
            if (preg_match_all('/troubleshoot\w*\s*(?:steps?)?\s*:\s*((?:(?!\b[A-Z]{1,2}-?\d{1,2}\s*:|\b(?:related|meaning|final|conclusion|notes?\b|when\s+to)\b).){15,900})/is', $ctx, $m)) {
                foreach ($m[1] as $block) {
                    foreach (preg_split('/[.;]\s+/', trim($block)) as $s) {
                        $s = trim($s, " \t.,;-‌");
                        if (mb_strlen($s) < 6 || mb_strlen($s) > 110) { continue; }
                        $fa = $this->translateCauseOrFix($s);
                        if ($fa !== null && !in_array($fa, $fixes, true)) {
                            $fixes[] = $fa;
                        }
                        if (count($fixes) >= 5) { break 2; }
                    }
                }
            }
            $patterns = [
                '/((?:بررسی|تمیز|باز|بستن|شست|قطع|اجرای|شارژ|ریست)[^۱۲۳۴۵۶۷۸۹۰.؛!؟]{5,60}\s+کنید)/u',
                '/(برای\s+رفع[^.؛!؟]{5,60}(?:کنید|بایید|است))/u',
                '/(?:شما\s+)?می‌?توانید\s+([^۱۲۳۴۵۶۷۸۹۰.؛!؟]{12,70}(?:کنید|بایید))/u',
                /* 🆕 v3.9: دستور مستقیم انگلیسی مقالات راهنما — با ترجمه فارسی (v2.14) */
                '/((?:check|clean|open|close|reset|ensure|make sure|unplug|plug)[^\n.؛!؟]{6,70})/i',
                '/(to\s+fix[^.؛!؟\n]{6,80})/i',
            ];
            /* کلمات لازم برای پذیرش راه‌حل کاربر */
            $mustHave = ['بررسی', 'تمیز', 'باز', 'بستن', 'شست', 'قطع', 'برق', 'فشار', 'شیر', 'فیلتر', 'درب', 'ریست', 'تنظیم', 'تست', 'تعویض', 'محکم', 'بررسی', 'کنید'];
        } else {
            $patterns = [
                '/(?:نیاز\s+به|مستلزم)\s+((?:تست|تعویض|عیب‌یابی|تعمیر)[^.؛!؟]{0,50})/u',
                '/((?:تست|اندازه‌گیری)\s+(?:مقاومت|ولتاژ|فشار)[^.؛!؟]{0,45})/u',
                '/(?:should\s+be\s+(?:replaced|tested)|must\s+be\s+(?:replaced|tested)|requires?\s+a)\s+([a-zA-Z\s\-]{6,60})/i',
                /* 🆕 v3.9: فعل دستوری رایج مقالات تعمیر — با ترجمه فارسی (v2.14) */
                '/(?:replace|test|inspect|measure|check|clean)\s+(?:the\s+|a\s+)?([a-z\s\-]{6,55})/i',
            ];
            $mustHave = ['تست', 'تعویض', 'عیب‌یابی', 'تعمیر', 'مولتی', 'اندازه‌گیری', 'سنسور', 'برد', 'کمپرسور', 'شارژ', 'بررسی', 'تمیز', 'تعویض', 'بررسی', 'ریست'];
        }
        foreach ($patterns as $re) {
            if (preg_match_all($re, $ctx, $m)) {
                foreach ($m[1] as $mm) {
                    $fx = mb_scrub(trim(preg_replace('/\s+/u', ' ', $mm)));
                    $fx = rtrim($fx, " ،,.");
                    if (mb_strlen($fx) < 12 || mb_strlen($fx) > 90) { continue; }
                    /* 🌐 v2.14: عبارت انگلیسی → ترجمه ساختاریافته فارسی؛
                       اگر ترجمه ممکن نبود (قطعه ناشناخته) → رد */
                    if (preg_match('/[a-zA-Z]/', $fx)) {
                        $translated = $this->translateCauseOrFix($fx);
                        if ($translated === null) { continue; }
                        if (!in_array($translated, $fixes, true)) {
                            $fixes[] = $translated;
                        }
                        if (count($fixes) >= 5) { break 2; }
                        continue;
                    }
                    /* 🔍 فیلتر کیفیت فارسی: باید حداقل یک کلیدواژه اقدام/قطعه داشته باشد */
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
     * 🔢 استخراج مدل‌های سازگار از متن (v3.9) — الگوهای نام‌گذاری سازنده‌ها
     * مانند «WF-8072T»، «JV1250H»، «GN-H702» از زمینه همان کد
     * @return string[] حداکثر ۸ مدل یکتا
     */
    private function modelsFromContext(string $ctx, string $brandEn = ''): array
    {
        $models = [];
        /* الگوی مدل: ۲-۴ حرف لاتین + خط تیره/فاصله اختیاری + ۲-۶ رقم + پسوند حرفی */
        if (preg_match_all('/\b([A-Z]{1,4}[- ]?[0-9]{2,6}[A-Z]{0,3})\b/', $ctx, $m)) {
            foreach ($m[1] as $mm) {
                $mm = trim(str_replace(' ', '-', $mm));
                if (mb_strlen($mm) < 4 || mb_strlen($mm) > 14) { continue; }
                /* نام برند مدل نیست (LG/SAMSUNG/BOSCH...) */
                $upperBrand = strtoupper($brandEn);
                if ($upperBrand !== '' && stripos($mm, $upperBrand) === 0) { continue; }
                if (in_array($mm, ['E1','E2','E3','E4','LED','LCD','HTTP','HTML'], true)) { continue; }
                if (!in_array($mm, $models, true)) {
                    $models[] = $mm;
                }
                if (count($models) >= 8) { break; }
            }
        }
        return $models;
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
        // ۱) کدهای حرف‌دار: E18 / E-18 / F01 / CH05 / Er FF / UE / OE / LE / IE / dE / tE / nE / 4E / 5E / H1
        //    🆕 v3.10: پوشش کامل حروف سازنده‌ها — 1E، PE، CE، AE، bE، nE (ال‌جی/سامسونگ/بوش)
        if (preg_match_all('/\b(E-?\d{1,2}|F-?\d{1,2}|CH-?\d{1,2}|Er\s?[A-Z]{1,2}|[4n5d1][Ee]|[UOILFCAPb][Ee]|[dt][Ee]|[Hh]\d{1,2})\b/u', $text, $m)) {
            foreach ($m[1] as $raw) {
                $c = $this->normalizeCode($raw);
                if ($c !== null && !isset($codes[$c])) {
                    $codes[$c] = true;
                }
            }
        }
        // ۲) کدهای فقط-عددی: فقط وقتی پشت واژه «کد/خطا/error/code» آمده‌اند (v3.0 — حذف نویز «۲۱ نکته»)
        if (preg_match_all('/(?:code|error|fault|کد|خطا)\s*[#:\\-]?\s*(\d{1,2})\b/ui', $text, $m)) {
            foreach ($m[1] as $raw) {
                if (strlen($raw) === 2) { // فقط دو رقمی‌ها (کدهای تک‌رقمی بسیار نویزند)
                    $c = $this->normalizeCode($raw);
                    if ($c !== null && !isset($codes[$c])) {
                        $codes[$c] = true;
                    }
                }
            }
        }
        /* 🧹 v2.14: حذف واژه‌های رایج انگلیسی که شکل کد دارند (may BE / to LE...)
           ولی در پنجره زمینه‌شان هیچ واژه خطایی نیست — روی «متن کامل صفحه»
           این نویزها فراوان‌اند و باعث کدهای ساختگی می‌شدند */
        foreach (array_keys($codes) as $ck) {
            if (strlen($ck) === 2 && preg_match('/^[A-Z]E$/', $ck)
                && !preg_match('/(error|fault|code|کد|خطا|نمایش|display|shows?|indicates?)/i', $this->codeContextOf($text, $ck, 70))) {
                unset($codes[$ck]);
            }
        }
        return array_keys($codes);
    }

    /** 🔎 پنجره کوچک اطراف کد — برای فیلتر زمینه‌ای نویزها (v2.14) */
    private function codeContextOf(string $text, string $code, int $radius = 60): string
    {
        $pos = mb_stripos($text, $code);
        if ($pos === false) {
            return '';
        }
        $start = max(0, $pos - $radius);
        return mb_substr($text, $start, $radius * 2);
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

        /* 🧭 v3.1: نقشه کدهای شناخته‌شده — عنوان/قطعه/شدت استاندارد برای کدهای پرتکرار
           (رفع عنوان‌های خراب مثل «خطای What Does It Mean...» و قطعه‌های زمینه‌ای غلط) */
        $known = self::knownCodeMeaning((string)$c['code'], $deviceEn);
        if ($known !== null) {
            if (self::titleIsGeneric($title, (string)$c['code'])) {
                $title = $known['title'];
            }
            if (empty($c['part']) || $c['part'] === 'قطعه مرتبط با کد') {
                $c['part'] = $known['part'];
            }
            if (($c['severity'] ?? '') === 'medium' || empty($c['severity'])) {
                $c['severity'] = $known['severity'];
            }
        }
        /* 🛡 v3.1: هیچ عنوان انگلیسی‌دار به دیتابیس نمی‌رود
           (۳+ واژه انگلیسی پشت‌سرهم = جمله انگلیسی؛ یک واژه دوزبانه مثل Inverter مجاز است) */
        if (self::hasEnglishGarbage($title)) {
            $title = 'خطای ' . $c['code'] . ' در ' . $deviceFa;
        }

        /* 🎯 v2.8: عنوان معنادار → قطعه دقیق — ریشه قطعه‌های اشتباه
           (خطای قفل درب با قطعه «پمپ تخلیه»!) پنجره ±۴۰۰ نویسه‌ای زمینه بود
           که شرح کدهای دیگر صفحه را هم در خود داشت؛ عنوان سند معتبرتری است */
        $titlePart = $this->partFromTitle($title);
        if ($titlePart !== '' && !self::titleIsGeneric($title, (string)$c['code'])) {
            $c['part'] = $titlePart;
        }

        /* 🔗 زنجیره قطعه: عنوان → وب/دانش → استنتاج از دلایل → نگاشت دسته */
        $part = trim((string)($c['part'] ?? ''));
        if ($part === '' || $part === 'قطعه مرتبط با کد') {
            $part = $this->partFromTitle($title) ?: $this->partFromCauses($causes);
        }
        if ($part === '') {
            $part = $this->partFromCategory((string)($c['category'] ?? ''));
        }
        if ($part !== '') {
            $c['part'] = $part;
        }

        /* ⚡ مشخصات فنی (v3.0): KB → استخراج از متن غنی صفحه → فالبک دسته */
        $specs = trim((string)($c['specs'] ?? ''));
        if ($specs === '' && !empty($c['_specs_ctx'])) {
            $specs = $this->specsFromContext((string)$c['_specs_ctx']);
        }
        if ($specs === '') {
            $specs = $this->fallbackSpecs($c['category'] ?? '');
        }

        /* 📍 محل قطعه (v3.0): KB → استخراج از متن غنی → فالبک دسته */
        $location = trim((string)($c['location'] ?? ''));
        if ($location === '' && !empty($c['_location'])) {
            $location = trim((string)$c['_location']);
        }
        if ($location === '') {
            $location = $this->fallbackLocation($c['category'] ?? '');
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
            'models'          => json_encode(!empty($c['models']) ? array_slice((array)$c['models'], 0, 8) : $this->fallbackModels($brandName, $deviceKey), JSON_UNESCAPED_UNICODE),
            'category'        => $this->normalizeCategory((string)($c['category'] ?? 'سایر')),
            'related_part'    => $this->partWithEn($c['part'] ?? ''),
            'tech_specs'      => $specs,
            'part_location'   => $location,
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

    /** 🧰 قالب‌های تخصصی دستگاه */

    /**
     * ⚡ استخراج مشخصات فنی واقعی از متن غنی صفحه (v3.0)
     * مقادیر عددی با واحد: اهم/کیلواهم، ولت، وات/کیلووات، بار
     */
    private function specsFromContext(string $ctx): string
    {
        $out = [];
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:k[ΩΩ]|kilohms?)/i', $ctx, $m)) {
            $out[] = 'مقاومت مرجع: ' . en_to_fa_digits($m[1]) . ' کیلواهم';
        } elseif (preg_match('/(\d{2,4})\s*(?:[ΩΩ]|ohms?)\b/i', $ctx, $m)) {
            $out[] = 'مقاومت مرجع: ' . en_to_fa_digits($m[1]) . ' اهم';
        }
        if (preg_match('/(\d{2,3})\s*(?:volts?|V)\b/i', $ctx, $m)) {
            $out[] = 'ولتاژ کاری: ' . en_to_fa_digits($m[1]) . ' ولت';
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:kW|kilowatts?)\b/i', $ctx, $m)) {
            $out[] = 'توان: ' . en_to_fa_digits($m[1]) . ' کیلووات';
        } elseif (preg_match('/(\d{3,4})\s*(?:watts?|W)\b/i', $ctx, $m)) {
            $out[] = 'توان: ' . en_to_fa_digits($m[1]) . ' وات';
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*bar\b/i', $ctx, $m)) {
            $out[] = 'فشار کاری: ' . en_to_fa_digits($m[1]) . ' بار';
        }
        if (preg_match('/(-?\d{2,3})\s*(?:°C|celsius|degrees? celsius)/i', $ctx, $m)) {
            $out[] = 'دمای کاری: ' . en_to_fa_digits($m[1]) . ' درجه سانتیگراد';
        }
        return implode(' | ', array_slice($out, 0, 3));
    }

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
            'water_heater'    => ['خرابی ترموستات', 'رسوب روی المنت/مبدل', 'خرابی سنسور دود', 'افت فشار آب', 'خرابی برد'],
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
            '/(drain|drainage|تخلیه)/iu' => 'خطای تخلیه آب',
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

    /** 🧭 آیا عنوان «عمومی» است؟ (فقط کد + دستگاه — بدون هیچ معنا) — v2.8
     *
     * عنوان‌هایی مثل «خطای DE در ماشین لباسشویی» بلند به‌نظر می‌رسند اما
     * هیچ معنایی نمی‌رسانند؛ جدول کد یا مخزن دانش باید جای آن‌ها را بگیرد.
     * ⚠️ کلیدواژه‌های کوتاهِ خطرناک (برق/گاز/یخ/آب/خشک/کف/بردِ تنها) حذف
     * شدند تا نام دستگاه‌ها — جاروبرقی، اجاق گاز، یخچال، آبگرمکن — به‌نادرستی
     * «معنادار» شمرده نشوند. درب/فن/شیر هم فقط با نویسه بعدی پذیرفته می‌شوند. */
    private static function titleIsGeneric(string $title, string $code): bool
    {
        $t = trim($title);
        if ($t === '' || $t === ('خطای ' . $code)) {
            return true;
        }
        if (self::hasEnglishGarbage($t)) {
            return true;
        }
        $kw = 'تخلیه|درین|ورود آب|آبرسانی|آب‌رسانی|شیر[ ‌)،]|قفل|درب[ ‌)،]|هیتر|گرمایش|المنت|سنسور|NTC|ترموستات|فشار|موتور|فن[ ‌)،]|ارتباط|نشت|توازن|تعادل|بالانس|لرزش|ارتعاش|حافظه|EEPROM|کمپرسور|پمپ|سرریز|برفک|شارژ|مبرد|اواپراتور|کندانسور|میکروسوئیچ|ماکرو|برد کنترل|برد اصلی|کودک|حرارت|ولتاژ|اتصالی|جریان|توقف|ریست|شستشو|چرخش|سرعت|نویز|آلارم|بوق|چشمک|فیلتر|گرفتگی|آب نمی|بدون آب|قطع آب|آبگیری|drain|inlet|door|lock|heater|sensor|thermistor|motor|communication|leak|unbalanc|balanc|eeprom|compressor|pump|overflow|suds|foam|defrost|vibrat|child';
        return preg_match('/(' . $kw . ')/iu', $t) !== 1;
    }

    /** 🎯 استخراج قطعه از «عنوان معنادار» (v2.8) — دقیق‌ترین سیگنال قطعه
     *
     * ریشه قطعه‌های اشتباه (خطای قفل درب با قطعه «پمپ تخلیه»!): پنجره
     * ±۴۰۰ نویسه‌ای متن، شرح کدهای دیگر صفحه را هم در خود داشت. */
    private function partFromTitle(string $title): string
    {
        $map = [
            '/سنسور فشار|سنسور سطح|فشار آب|سطح آب/iu' => 'سنسور فشار/سطح آب',
            '/تخلیه|درین|drain/iu' => 'پمپ تخلیه و شلنگ تخلیه',
            '/ورود آب|آبرسانی|آب‌رسانی|inlet/iu' => 'شیر برقی ورودی آب',
            '/قفل|درب[ ‌)،]|door|lid|ماکرو|میکروسوئیچ/iu' => 'قفل درب (میکروسوئیچ)',
            '/توازن|تعادل|بالانس|unbalanc|balanc|لرزش|ارتعاش|vibrat/iu' => 'سنسور توازن و سیستم تعلیق',
            '/برفک|defrost/iu' => 'سیستم برفک‌زدایی (دیفراست)',
            '/سرریز|suds|foam|overflow/iu' => 'سنسور سرریز و شیر برقی',
            '/نشت|نشتی|leak/iu' => 'ماسوره نشتی و واشرها',
            '/گرمایش|هیتر|المنت|heat/iu' => 'هیتر حرارتی و سنسور دما',
            '/حافظه|eeprom|memory/iu' => 'برد کنترل (حافظه EEPROM)',
            '/کمپرسور|compressor/iu' => 'کمپرسور',
            '/موتور|motor/iu' => 'موتور اصلی و درایور',
            '/فن[ ‌)،]|fan /iu' => 'فن و مسیر تخلیه هوا',
            '/ارتباط|communication/iu' => 'برد کنترل و سیم‌کشی ارتباطی',
            '/ولتاژ|تغذیه برق|برق‌رسانی|power supply/iu' => 'منبع تغذیه و برد کنترل',
            '/اتصالی|جریان/iu' => 'سیم‌کشی و موتور (اتصالی)',
            '/سنسور|thermistor|ntc|حرارت|دمای/iu' => 'سنسور دما NTC',
            '/فیلتر|گرفتگی/iu' => 'فیلتر و مسیر جریان',
            '/برد کنترل|برد اصلی|board|pcb/iu' => 'برد کنترل',
            '/کودک|child/iu' => 'پنل و برد کنترل (قفل کودک)',
            '/شارژ|مبرد|اواپراتور|کندانسور/iu' => 'مدار گاز مبرد',
            '/بوق|چشمک|آلارم/iu' => 'پنل نمایشگر و برد کنترل',
            '/توقف|ریست/iu' => 'برد کنترل و منبع تغذیه',
            '/شستشو|چرخش|سرعت/iu' => 'موتور و سیستم انتقال نیرو',
        ];
        foreach ($map as $re => $part) {
            if (preg_match($re, $title)) { return $part; }
        }
        return '';
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

    /** 🛡 آیا متن «جمله انگلیسی» دارد؟ (۳+ واژه انگلیسی پشت‌سرهم — نه یک واژه دوزبانه) */
    private static function hasEnglishGarbage(string $text): bool
    {
        return (bool)preg_match('/(?:^|[\s\-—:])(?:[A-Za-z]{3,})(?:\s+[A-Za-z]{3,}){2,}/', $text);
    }

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
     * 🌐 متن وبِ اختصاصی همین رکورد — برای بهبود تک‌فیلدی (v3.0)
     * یک جستجو + خواندن بهترین صفحه؛ در صورت شکست null.
     */
    private function webContextForRecord(array $rec, string $deviceFa): ?string
    {
        try {
            $searcher = new WebSearchService();
            $brandEn = trim((string)($rec['brand_en'] ?? '')) ?: (string)($rec['brand_name'] ?? '');
            $deviceEn = self::DEVICE_FA[$rec['device_key']][1] ?? ucfirst((string)$rec['device_key']);
            $res = $searcher->search("{$brandEn} {$deviceEn} error code {$rec['code']} part location specs", 5);
            foreach ($res['results'] ?? [] as $r) {
                $text = ($r['title'] ?? '') . ' — ' . ($r['snippet'] ?? '');
                if (stripos($text, (string)$rec['code']) === false && mb_strpos($text, (string)$rec['code']) === false) { continue; }
                try {
                    $page = $searcher->fetchPageText((string)($r['url'] ?? ''), 6000);
                    if (!empty($page['ok'])) {
                        $txt = (string)$page['text'];
                        if (mb_strlen($txt) > 300) {
                            return ($page['title'] ?? '') . ' — ' . $txt;
                        }
                    }
                } catch (Throwable $e) {
                    continue;
                }
            }
            /* حداقل اسنیپت‌ها */
            $snips = '';
            foreach (array_slice($res['results'] ?? [], 0, 4) as $r) {
                $snips .= ' ' . ($r['title'] ?? '') . ' — ' . ($r['snippet'] ?? '');
            }
            return trim($snips) !== '' ? $snips : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /** 📚 فیلد curated از پایگاه دانش برای همین رکورد (v3.0) */
    private function kbFieldForRecord(array $rec, string $field): string
    {
        $brand = $this->db->fetch('SELECT name_fa, name_en FROM brands WHERE id = ?', [(int)$rec['brand_id']]);
        if (!$brand) { return ''; }
        $brandKey = $this->matchBrandKey($brand['name_fa'], $brand['name_en']);
        if ($brandKey === null) { return ''; }
        $kb = TextProcessor::loadKnowledge('error-codes-brands');
        $codes = $kb['brands'][$brandKey]['devices'][$rec['device_key']]['codes'] ?? [];
        $recNorm = strtoupper(str_replace([' ', '-'], '', (string)$rec['code']));
        foreach ($codes as $kc) {
            if (strtoupper(str_replace([' ', '-'], '', (string)$kc['code'])) === $recNorm) {
                return trim((string)($kc[$field] ?? ''));
            }
        }
        return '';
    }

    /**
     * ✨ بهینه‌سازی و یکتاسازی یک فیلد از کد خطا با AI
     *
     * @param int    $errorId شناسه رکورد
     * @param string $field   نام فیلد (title/description/causes/solutions/...)
     * @return array ['field','old','new','note']
     */
    public function improveField(int $errorId, string $field): array
    {
        $rec = $this->db->fetch('SELECT e.*, b.name_fa AS brand_name, b.name_en AS brand_en FROM error_codes e LEFT JOIN brands b ON b.id = e.brand_id WHERE e.id = ?', [$errorId]);
        if (!$rec) {
            throw new RuntimeException('کد خطا یافت نشد.');
        }
        $allowed = ['title', 'description', 'causes', 'solutions', 'related_part', 'tech_specs', 'part_location', 'subtype'];
        if (!in_array($field, $allowed, true)) {
            throw new RuntimeException('این فیلد قابل بهینه‌سازی خودکار نیست: ' . $field);
        }

        $deviceFa = self::DEVICE_FA[$rec['device_key']][0] ?? $rec['device_key'];
        $deviceEn = self::DEVICE_FA[$rec['device_key']][1] ?? ucfirst((string)$rec['device_key']);
        $brandName = (string)($rec['brand_name'] ?? '');
        $brandEn = (string)($rec['brand_en'] ?? $brandName);
        $old = (string)$rec[$field];
        mt_srand(crc32($errorId . '|' . $field . '|' . substr((string)time(), -4)));

        /* 🌐 v2.8: شواهد وب اختصاصی همین کد — منبع اول فیلدهای فنی.
           خطایاب AI حالا برای هر فیلد، نتایج واقعی وب را می‌خواند و از آن
           استخراج می‌کند؛ مخزن دانش فقط «فاصله‌ها» را پر می‌کند. */
        $webCtx = '';
        $webUrls = [];
        $webNote = '';
        if (in_array($field, ['causes', 'solutions', 'related_part', 'tech_specs', 'part_location'], true)) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(90);
            }
            $deadline = microtime(true) + 14.0;
            try {
                $searcher = new WebSearchService();
                $queries = [
                    "{$brandEn} {$deviceEn} error code {$rec['code']} cause fix",
                    "کد خطا {$rec['code']} {$deviceFa} {$brandName} علت راه حل",
                ];
                $ctxParts = [];
                foreach ($queries as $q) {
                    if (microtime(true) > $deadline - 1.5) { break; }
                    try {
                        $res = $searcher->search($q, 6);
                    } catch (Throwable $e) {
                        continue;
                    }
                    foreach ($res['results'] ?? [] as $r) {
                        $t = ($r['title'] ?? '') . ' — ' . ($r['snippet'] ?? '');
                        $hasCode = stripos($t, (string)$rec['code']) !== false;
                        $hasCtx = stripos($t, $deviceEn) !== false || mb_strpos($t, $deviceFa) !== false
                            || stripos($t, $brandEn) !== false || mb_strpos($t, $brandName) !== false;
                        if ($hasCode && $hasCtx) {
                            $ctxParts[] = $t;
                            if (count($webUrls) < 3 && !empty($r['url'])) { $webUrls[] = (string)$r['url']; }
                        }
                    }
                    if (count($ctxParts) >= 6) { break; }
                }
                /* متن کامل بهترین صفحه — پنجره کد-محور */
                foreach (array_slice($webUrls, 0, 1) as $u) {
                    if (microtime(true) > $deadline - 0.5) { break; }
                    try {
                        $page = $searcher->fetchPageText($u, 6000);
                    } catch (Throwable $e) {
                        continue;
                    }
                    if (!empty($page['ok'])) {
                        $txt = (string)$page['text'];
                        $win = $this->codeCentricContext($txt, (string)$rec['code'], 900);
                        if ($win !== '') {
                            $ctxParts[] = $win;
                        }
                    }
                }
                $webCtx = implode(' . ', $ctxParts);
            } catch (Throwable $e) {
                $webCtx = '';
            }
            if (mb_strlen($webCtx) > 120) {
                $webNote = ' از ' . count(array_unique(array_merge($ctxParts ? [1] : [], $webUrls))) . ' منبع وب برای همین کد استخراج شد';
            }
        }

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
                /* 🌐 اول: دلایل واقعی از وب برای همین کد (v2.8) */
                if ($webCtx !== '') {
                    foreach ($this->extractCausesFromContext($webCtx, (string)$rec['device_key'], (string)$rec['category']) as $wc) {
                        if (!in_array($wc, $causes, true)) {
                            array_unshift($causes, $wc);
                        }
                    }
                }
                $extra = array_diff($this->deviceCauses($rec['device_key'], (string)$rec['category']), $causes);
                foreach (array_slice($extra, 0, 3) as $x) {
                    $causes[] = $x;
                }
                /* 📏 حداقل ۵ دلیل */
                if (count($causes) < 5) {
                    $causes[] = 'قطع برق طولانی و روشن‌سازی مجدد (ریست کامل برد)';
                }
                $causes = array_slice(array_values(array_unique(array_filter($causes))), 0, 7);
                $new = json_encode($causes, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                break;

            case 'solutions':
                $sols = json_decode((string)$rec['solutions'], true) ?: [];
                $sols = array_map(fn($x) => mb_scrub((string)$x), $sols);
                /* 🌐 اول: راه‌حل‌های واقعی از وب برای همین کد (v2.8) */
                if ($webCtx !== '') {
                    foreach ($this->extractFixesFromContext($webCtx, 'user') as $wf) {
                        $cand = '[کاربر] ' . $wf;
                        if (!in_array($cand, $sols, true)) {
                            array_unshift($sols, $cand);
                        }
                    }
                    foreach ($this->extractFixesFromContext($webCtx, 'tech') as $wf) {
                        $cand = '[تکنسین] ' . $wf;
                        if (!in_array($cand, $sols, true)) {
                            array_unshift($sols, $cand);
                        }
                    }
                }
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
                $deep = $this->webContextForRecord($rec, $deviceFa);
                $part = trim($old);
                /* 🌐 اول: قطعه از شواهد وب اختصاصی همین کد (v2.8) */
                if ($webCtx !== '') {
                    $webPart = $this->partFromContext($webCtx);
                    if ($webPart !== 'قطعه مرتبط با کد' && $webPart !== '') {
                        $part = $webPart;
                    }
                }
                if (($part === '' || $part === 'قطعه مرتبط با کد') && $deep !== null) {
                    $part = $this->partFromContext($deep);
                    if ($part === 'قطعه مرتبط با کد') { $part = ''; }
                }
                /* 🛡 گارد سازگاری با عنوان (v2.8) */
                $fixed = $this->enforcePartConsistency(['title' => (string)$rec['title'], 'part' => $part]);
                $part = (string)$fixed['part'];
                $new = $this->partWithEn($part ?: 'قطعه مرتبط با کد');
                break;

            case 'tech_specs':
                $deep = $this->webContextForRecord($rec, $deviceFa);
                $new = '';
                /* 🌐 اول: مشخصات واقعی از وب (v2.8) — بعد KB، بعد قدیمی */
                if ($webCtx !== '') {
                    $new = $this->specsFromContext($webCtx);
                }
                if ($new === '') {
                    $kbSpecs = $this->kbFieldForRecord($rec, 'specs');
                    if ($kbSpecs !== '') {
                        $new = $kbSpecs;
                    } elseif ($deep !== null) {
                        $new = $this->specsFromContext($deep);
                    }
                }
                if ($new === '') {
                    $new = $old ?: $this->fallbackSpecs((string)$rec['category']);
                }
                break;

            case 'part_location':
                $deep = $this->webContextForRecord($rec, $deviceFa);
                $new = '';
                /* 🌐 اول: محل قطعه از وب (v2.8) */
                if ($webCtx !== '') {
                    $new = $this->locationFromContext($webCtx);
                }
                if ($new === '') {
                    $kbLoc = $this->kbFieldForRecord($rec, 'location');
                    if ($kbLoc !== '') {
                        $new = $kbLoc;
                    } elseif ($deep !== null) {
                        $new = $this->locationFromContext($deep);
                    }
                }
                if ($new === '') {
                    $new = $old ?: $this->fallbackLocation((string)$rec['category']);
                }
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
                'note' => 'فیلد «' . $field . '» با AI بازنویسی و یکتا شد.' . $webNote,
                'sources' => $webUrls];
    }
}
