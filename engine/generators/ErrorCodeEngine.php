<?php
/**
 * 🚨 ErrorCodeEngine — موتور خطایاب AI (v3.15 — جستجوی عمیق اینترنتی v2.34)
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
 *   🌐 جستجوی آنلاینِ عمیق (سایت‌های فارسی + غیرفارسی) — چند موتور همزمان،
 *      صفحات رسمی/دفترچه/قطعات/انجمن، خواندن متن کامل چند صفحه برای هر کد
 *      و ادغام شواهد — engine/knowledge/errorcode-web-sources.json
 *
 * کدها از خود موتور «ساخته» نمی‌شوند — فقط از پایگاه دانش و وب استخراج می‌شوند.
 *
 * @package SahandBrandMaker
 * @version 3.15
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

    /** 🌐 v3.14: سرچر مشترک همه راندهای جستجو — cooldown جینا و streak مسابقه بین راندها حفظ می‌شود (قبلاً هر راند نمونه تازه می‌ساخت و حالت‌ها ریست می‌شد) */
    private $sharedSearcher = null;

    /** 🆕 v2.34: آیا سقف نرخ جستجوی وب پر شد؟ (برای توقفِ زودهنگام و گزارش صادقانه) */
    private $rateLimited = false;

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
     * @param string $depth     🆕 v2.34 عمق جستجو: fast | balanced | deep
     * @return array ['inserted' => int, 'skipped' => int, 'sources' => string[], 'report' => string]
     */
    public function generateForDevice(int $brandId, string $deviceKey, bool $useWeb = true, bool $overwrite = false, string $depth = 'balanced'): array
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
           🆕 v2.34: بودجه با «عمق جستجو» تنظیم می‌شود:
             fast/balanced → ۱۳۰ ثانیه (رفتار قبلی)
             deep          → تا ۴۲۰ ثانیه (چند موتور + صفحات بیشتر + کدهای بیشتر)
           مهلت اجرای PHP هم متناسب با همان بالا می‌رود. */
        $budgetWant = ($depth === 'deep') ? 420.0 : 130.0;
        if (function_exists('set_time_limit')) {
            @set_time_limit($depth === 'deep' ? 900 : 300);
        }
        $maxExec = (int)@ini_get('max_execution_time');
        $budget = ($maxExec > 0) ? max(30.0, min($budgetWant, $maxExec - 10.0)) : $budgetWant;
        $deadline = microtime(true) + $budget;

        $sources = [];
        $records = [];
        $webCount = 0;
        $sourceStats = ['total' => 0, 'fa' => 0, 'en' => 0, 'official' => 0, 'manual' => 0, 'parts' => 0, 'forum' => 0];
        $inserted = 0;
        $skipped = 0;
        $insertedCodes = []; // کدهای همین نوبت — برای جلوگیری از درج دوباره
        $depthLabel = $depth === 'deep' ? 'عمیق' : ($depth === 'fast' ? 'سریع' : 'متعادل');

        /* 💾 درجِ تدریجی (v2.34): هر کد همان لحظه که ساخته شد ثبت می‌شود —
           در جستجوی عمیق (تا چند دقیقه) اگر سرور میانه‌ی کار درخواست را بکشد،
           کدهای تأییدشده‌ی قبلی از دست نمی‌روند. */
        $insertOne = function (array $rec) use (&$inserted, &$skipped, &$insertedCodes, $brandId, $deviceKey): bool {
            $norm = strtoupper(trim((string)$rec['code']));
            if ($norm === '' || in_array($norm, $insertedCodes, true)) {
                $skipped++;
                return false;
            }
            $exists = (int)$this->db->fetchValue(
                'SELECT COUNT(*) FROM error_codes WHERE brand_id = ? AND device_key = ? AND UPPER(code) = ?',
                [$brandId, $deviceKey, $norm]
            );
            $insertedCodes[] = $norm;
            if ($exists > 0) {
                $skipped++;
                return false;
            }
            $this->db->insert('error_codes', $rec);
            $inserted++;
            return true;
        };

        $this->progress(4, 'بررسی برند و دستگاه', $brand['name_fa'] . ' — ' . $deviceFa . ($useWeb ? ' — جستجوی آنلاین ' . $depthLabel . ' فعال' : ' — جستجوی آنلاین غیرفعال'));

        /* ---------- ۱) جستجوی آنلاین — تنها منبع ثبت کد ---------- */
        if ($useWeb) {
            try {
                $this->progress(8, 'شروع جستجوی آنلاین', 'کوئری‌های فارسی و انگلیسی به چند موتور جستجو ارسال می‌شود...');
                $webCodes = $this->searchWebCodes(
                    $brand['name_en'] ?: $brand['name_fa'],
                    $brand['name_fa'],
                    $deviceKey,
                    $deviceFa,
                    $brandKey,
                    $deadline,
                    null,
                    ['depth' => $depth]
                );
                /* 🚨 v2.15 — راند نجات: اگر هیچ کدی پیدا نشد، کوئری‌های ساده‌شده
                   جایگزین (نام‌های مترادف دستگاه) امتحان می‌شوند — ریشه‌یابی
                   «مایکروویو/تلویزیون ال‌جی هیچ کدی پیدا نکرد»: کوئری‌های اصلی
                   گاهی نتایج فنی برنمی‌گرداندند و موتور زود تسلیم می‌شد. */
                /* 🚨 v3.14 — راند نجات «همیشه» اجرا می‌شود + بودجه تازه ۴۵ ثانیه:
                   ریشه‌یابی نهایی «مایکروویو/تلویزیون ال‌جی هیچ کدی پیدا نکرد»:
                   بودجه ۴۴ ثانیه‌ای با موتورهای بلاک‌شده و پل jina کند، پیش از
                   رسیدن به راند نجات تمام می‌شد؛ شرط «deadline-8» عملاً هرگز برقرار
                   نبود و مترادف‌ها هرگز امتحان نمی‌شدند. */
                if (!$webCodes) {
                    $deadline = max($deadline, microtime(true) + ($depth === 'deep' ? 90.0 : 45.0));
                    $this->progress(30, 'راند نجات — کوئری‌های جایگزین', 'با نام‌های مترادف دستگاه دوباره جستجو می‌شود...');
                    foreach (self::DEVICE_ALIASES_EN[$deviceKey] ?? [] as $altEn) {
                        if (microtime(true) > $deadline - 10.0) { break; }
                        $altCodes = $this->searchWebCodes(
                            $brand['name_en'] ?: $brand['name_fa'],
                            $brand['name_fa'],
                            $deviceKey,
                            $deviceFa,
                            $brandKey,
                            $deadline,
                            $altEn,
                            ['depth' => $depth]
                        );
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
                    $rec = $this->buildRecord($brand, $deviceKey, $deviceFa, $deviceEn, $wc, 'web');
                    $records[] = $rec;
                    /* 💾 ذخیره همان لحظه — کدهای قبلی در صورت قطع شدن اجرا می‌مانند */
                    $insertOne($rec);
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
        foreach ($records as $rec) {
            /* 💾 بیشتر رکوردها در حلقه‌ی بالا همان لحظه درج شده‌اند؛ این حلقه
               فقط تور ایمنی برای آن‌هایی است که هنوز ثبت نشده‌اند */
            if (in_array(strtoupper(trim((string)$rec['code'])), $insertedCodes, true)) {
                continue;
            }
            $insertOne($rec);
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
            if ($useWeb && $this->rateLimited) {
                $report = 'سقف نرخ جستجوی وب در این ساعت پر شد و هیچ کدی استخراج نشد — چیزی ثبت نشد. نتایج جستجو ۳۰ دقیقه کش می‌شوند، بنابراین اجرای دوباره (چند دقیقهٔ دیگر یا فردا) ارزان‌تر است و ادامه می‌دهد.';
            } else {
            $report = $useWeb
                ? 'هیچ کدی از جستجوی اینترنتی تأیید نشد و پایگاه دانش هم برای این برند+دستگاه کدی ندارد — چیزی ثبت نشد (طبق سیاست «فقط کدهای واقعی»). سه علت رایج: ① موتورهای رایگان از این هاست بلاک شده‌اند — کلید یک موتور حرفه‌ای (SerpApi/Google CSE/Bing API) را در «تنظیمات ← جستجوی وب» ثبت کنید ② سقف نرخ جستجوی ساعتی پر شده — چند دقیقه صبر کنید یا سقف را در تنظیمات افزایش دهید ③ چند دقیقه بعد دوباره تلاش کنید (پل جستجو ممکن است موقتاً محدود شده باشد).'
                : 'جستجوی آنلاین غیرفعال بود — کدهای معتبر پایگاه دانش تخصصی (در صورت وجود) ثبت شدند.';
            }
        } elseif ($webCount === 0 && $kbAdded > 0) {
            $report = "جستجوی وب کد تأییدشده‌ای نیافت اما {$kbAdded} کد معتبر از «پایگاه دانش تخصصی» (مستندات رسمی سازنده) ثبت شد" . ($skipped > 0 ? " — {$skipped} کد از قبل موجود بود" : '') . '.';
        } else {
            $report = "{$inserted} کد خطای واقعی وب ثبت شد از مجموع {$webCount} کد راستی‌آزمایی‌شده";
            if ($kbAdded > 0) {
                $report .= " + {$kbAdded} کد معتبر تکمیلی از پایگاه دانش تخصصی";
            }
            /* 🌐 آمار منابع اینترنتی (فارسی/خارجی/رسمی) */
            $stats = $this->sourceStats($sources, $brandKey);
            $sourceStats = $stats;
            if ($stats['total'] > 0) {
                $report .= " — منابع: {$stats['total']} سایت ({$stats['fa']} فارسی، {$stats['en']} خارجی"
                    . ($stats['official'] > 0 ? "، {$stats['official']} رسمی برند" : '')
                    . ($stats['manual'] > 0 ? "، {$stats['manual']} دفترچه قطعات" : '')
                    . ($stats['forum'] > 0 ? "، {$stats['forum']} انجمن تعمیرات" : '')
                    . ')';
            }
            if ($this->rateLimited) {
                $report .= ' — ⚠️ سقف نرخ جستجوی وب در این ساعت پر شد و جستجو زودتر از موعد متوقف شد؛ نتایج کش می‌شوند، پس چند دقیقه دیگر دوباره اجرا کنید تا ادامه‌ی کدها (بدون تکرار) اضافه شود.';
            }
            $report .= ($skipped > 0 ? " — {$skipped} کد از قبل موجود بود" : '') . '.';
        }

        return [
            'rate_limited' => $this->rateLimited,
            'inserted' => $inserted + $kbAdded,
            'skipped'  => $skipped,
            'total_known' => count($records) + $kbAdded,
            'web_found' => $webCount,
            'kb_added' => $kbAdded,
            'sources'  => array_values(array_unique(array_filter($sources))),
            'report'   => $report,
            'depth'    => $depth,
            'source_stats' => $sourceStats,
        ];
    }

    /* 🌐 تمام ماشین تحقیق وب در trait — P2-24 (تقسیم فایل ۳۵۸۰ خطی) */
    use ErrorCodeResearchTrait;
}
