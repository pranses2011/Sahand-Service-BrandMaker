<?php
/**
 * 🔬 ErrorCodeResearchTrait — ماشین تحقیق وبِ موتور خطایاب (P2-24)
 * ============================================================
 * این متدها پیش‌تر داخل ErrorCodeEngine (۳۵۸۰ خط) بودند — اکنون در trait
 * جداگانه سازمان یافتند. «trait» در زمان کامپایل عیناً داخل کلاس مصرف‌کننده
 * درج می‌شود؛ رفتار زمان اجرا بی‌تغییر است.
 *
 * شامل: جستجوی چندموتوره + خواندن متن کامل صفحات + ساخت کوئری‌های کشف/
 * کد + تجزیه جدول‌های سازنده + تحقیق عمیق هر کد + متادیتای منابع + کنترل
 * شواهد + ادغام پایگاه دانش + ترجمه عبارات + استخراج دلایل/راه‌حل‌ها.
 *
 * @package SahandBrandMaker
 * @see ErrorCodeEngine
 */
trait ErrorCodeResearchTrait
{
    /* ==================================================
     * 🌐 جستجوی آنلاین کدها — سایت‌های فارسی و غیرفارسی (v2.34 جستجوی عمیق)
     * ================================================== */

    /** 🌐 نمونه مشترک WebSearchService — v3.14 */
    private function sharedSearcher(): WebSearchService
    {
        if ($this->sharedSearcher === null) {
            $this->sharedSearcher = new WebSearchService();
        }
        return $this->sharedSearcher;
    }

    /**
     * 🌐 جستجوی کدهای خطا در وب — سایت‌های فارسی و غیرفارسی (v2.34 «جستجوی عمیق»)
     *
     * 🔀 ادغام چند موتور: هر کوئری همزمان از ۱ تا ۲ موتورِ مختلف پرسیده می‌شود
     *    و نتایج همه آن‌ها ادغام و حذف‌تکراری می‌گردد (دیگر فقط «اولین موتوری
     *    که جواب داد» ملاک نیست) — پوشش سایت‌هایی که فقط یکی از موتورها می‌شناسد.
     * 🌍 کوئری‌ها از فایل دانش منابع می‌آیند: الگوهای انگلیسی + الگوهای فارسی
     *    + کوئری‌های عمودیِ سایت‌های دفترچه/قطعات/انجمن (manualslib، partselect،
     *    appliancepartspros، fixya، reddit و...) با ارسالِ hl/gl و kl/mkt درست.
     * 🏛️ هر منبع شناسنامه می‌گیرد: رسمی برند / دفترچه / قطعات / انجمن / سایت
     *    فارسی + امتیاز اعتماد — پایه‌ی «ادغام شواهد».
     * 📄 صفحات «فهرست کد» با متن کامل (تا ۱۶هزار نویسه) خوانده و جدول کدها پارس
     *    می‌شود (دقیق‌ترین منبعِ معنا) + تور ایمنی الگویی روی کل متن.
     * 🧪 هر کد با کوئری‌های اختصاصیِ خودش (فارسی + انگلیسی) و خواندن ۱ تا ۴
     *    صفحه تعمیق می‌شود؛ مشخصات/مدل/محل قطعه فقط از پنجره‌ی «دورِ خود کد».
     * 🧮 ادغام شواهد: کد با ≥۲ دامنه‌ی مستقل، یا یک منبع رسمی/دفترچه، یا تأییدِ
     *    پایگاه دانش پذیرفته می‌شود؛ تک‌منبعِ ضعیف رد می‌گردد (فقط کدهای واقعی).
     *
     * @return array<array{code,title,part,category,severity,causes,fixes_user,fixes_tech,source_urls}>
     */
    private function searchWebCodes(string $brandEn, string $brandFa, string $deviceKey, string $deviceFa, ?string $brandKey = null, ?float $deadline = null, ?string $deviceEnOverride = null, array $opts = []): array
    {
        $searcher = $this->sharedSearcher();
        $deviceEn = $deviceEnOverride !== null && $deviceEnOverride !== '' ? $deviceEnOverride : (self::DEVICE_FA[$deviceKey][1] ?? ucfirst($deviceKey));
        $deadline = $deadline ?? (microtime(true) + 24.0);

        /* 🎚 پروفایل عمق — تعداد کوئری، تعداد موتور، صفحات و سقف کدها */
        $depthKey = (string)($opts['depth'] ?? 'balanced');
        $P = self::depthProfile($depthKey);

        /* 🧭 کوئری‌های کشف: فارسی و انگلیسی یکی‌درمیان (+ عمودی‌ها در انتها) */
        $queries = $this->buildDiscoveryQueries($brandEn, $brandFa, $deviceEn, $deviceFa, (int)$P['queries']);

        $found = [];         // code => record
        $contextTexts = [];  // code => [متنی که کد در آن دیده شد]
        $listUrls = [];      // 🎯 url => sourceMeta — صفحات «فهرست کدها» برای پارس جدول
        /* ⏱️ تسلیمِ زودهنگام فقط در حالت‌های سبک — در حالت عمیق تا سقف می‌گردد */
        $earlyStop = ((int)$P['max_codes'] >= 40) ? 30 : 15;

        /* ---------- فاز ۱: کشف کدها از نتایج جستجو (فارسی + انگلیسی) ---------- */
        foreach ($queries as $qi => $qItem) {
            if (microtime(true) > $deadline - 4.0) {
                break;
            }
            $query = (string)$qItem['q'];
            $lang  = (string)$qItem['lang'];
            /* 📊 گزارش مرحله‌به‌مرحله جستجو (۸٪ تا ۳۸٪) */
            $this->progress(8 + (int)round(30 * ($qi / max(1, count($queries) - 1))), 'جستجوی وب — کوئری ' . ($qi + 1) . ' از ' . count($queries), mb_substr($query, 0, 90));
            try {
                $res = $searcher->search($query, 10, [
                    'lang'     => $lang,
                    'engines'  => (int)$P['engines'],
                    'deadline' => $deadline,
                ]);
            } catch (Throwable $e) {
                /* 🛑 سقف نرخ پر شد — ادامه‌ی کوئری‌ها فقط خطای تکراری است */
                if ($this->isRateLimitError($e)) {
                    $this->rateLimited = true;
                    break;
                }
                continue;
            }
            foreach ($res['results'] ?? [] as $r) {
                $text = ($r['title'] ?? '') . ' — ' . ($r['snippet'] ?? '');
                $url = (string)($r['url'] ?? '');
                /* 🚫 فروشگاه‌ها، شبکه‌های اجتماعی، ویدیو و آگهی‌ها بی‌فایده‌اند */
                if ($url !== '' && $this->isStopDomain($url)) {
                    continue;
                }

                /* 🛡 اعتبارسنجی زمینه: نام برند یا دستگاه باید در متن باشد */
                $hasContext = stripos($text, $brandEn) !== false
                    || mb_strpos($text, $brandFa) !== false
                    || mb_strpos($text, $deviceFa) !== false
                    || stripos($text, $deviceEn) !== false;
                if (!$hasContext) {
                    continue;
                }
                /* 🏛️ شناسنامه منبع (رسمی / دفترچه / قطعات / انجمن / فارسی) */
                $meta = $this->sourceMeta($url, $brandKey);
                $langSeen = $this->detectLang($text);
                if ($meta['kind'] === 'other' && $langSeen === 'fa') {
                    $trusts = (array)($this->webSources()['trust'] ?? []);
                    $meta['kind'] = 'fa_site';
                    $meta['trust'] = (int)($trusts['fa_site'] ?? 62);
                }
                if (count($listUrls) < (int)$P['list_pages'] && $url !== ''
                    && preg_match('#(error|fault|code|خطا|کد)#iu', ($r['title'] ?? '') . $url)
                    && (stripos($text, $brandEn) !== false || mb_strpos($text, $brandFa) !== false)) {
                    $listUrls[$url] = $meta;
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
                            '_evidence' => 0,
                            '_verified' => false,
                        ];
                        $contextTexts[$mCode] = [];
                    }
                    $this->noteSource($found[$mCode], $url, $meta, 'snippet', $lang !== '' ? $lang : $langSeen);
                    if (count($contextTexts[$mCode]) < 8 && trim($text) !== '') {
                        $contextTexts[$mCode][] = $text;
                    }
                }
            }
            /* صرفه‌جویی نرخ جستجو (در حالت عمیق دیرتر تسلیم می‌شود) */
            if ($qi >= 1 && count($found) >= $earlyStop && (int)$P['engines'] <= 1) {
                break;
            }
        }

        /* ---------- فاز ۱٫۵: پارس ساختاریافته «جدول کدها» از صفحات فهرست ----------
         * صفحات فهرست کد سازنده، دقیق‌ترین منبع ممکن‌اند: «E4 | Water Drainage Error»
         * یا «کد OE: خطای تخلیه آب». جدول هر صفحه → معنای دقیق تک‌تک کدها. */
        $tableMeanings = [];
        $listPageKeys = array_slice(array_keys($listUrls), 0, (int)$P['list_pages']);
        $listTotal = max(1, count($listPageKeys));
        foreach ($listPageKeys as $lui => $lu) {
            if (microtime(true) > $deadline - 6.0) {
                break;
            }
            $this->progress(40 + (int)round(18 * (($lui + 1) / $listTotal)), 'خواندن صفحات فهرست کد', 'صفحه ' . ($lui + 1) . ' از ' . $listTotal . ' تحلیل می‌شود...');
            try {
                $page = $searcher->fetchPageText($lu, (int)$P['page_chars'], $deadline);
            } catch (Throwable $e) {
                continue;
            }
            if (empty($page['ok'])) {
                continue;
            }
            $txt = (string)$page['text'];
            $meta = $listUrls[$lu];
            $pageLang = $this->detectLang($txt);
            $hasCtx = stripos($txt, $brandEn) !== false || mb_strpos($txt, $brandFa) !== false
                || stripos($txt, $deviceEn) !== false || mb_strpos($txt, $deviceFa) !== false;
            if (!$hasCtx || mb_strlen($txt) < 400) {
                continue;
            }
            $pairs = $this->parseCodeTable($txt);
            /* 🛡 نام برند هرگز «کد خطا» نیست (LG/GE/SMEG/BOSCH...) */
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
                if (!isset($found[$pCode])) {
                    $ctx = $this->codeCentricContext($txt, $pCode, 700);
                    $found[$pCode] = [
                        'code' => $pCode,
                        'title' => $pTitle,
                        'part' => $this->partFromTitle($pTitle) ?: $this->partFromContext($this->codeCentricContext($txt, $pCode, 400)),
                        'category' => $this->categoryFromContext($this->codeCentricContext($txt, $pCode, 400)),
                        'severity' => $this->severityFromContext($this->codeCentricContext($txt, $pCode, 400)),
                        'causes' => [],
                        'fixes_user' => [],
                        'fixes_tech' => [],
                        'source_urls' => [],
                        '_evidence' => 0,
                        '_verified' => false,
                        '_table_verified' => true,
                    ];
                    $contextTexts[$pCode] = [];
                    $this->noteSource($found[$pCode], $lu, $meta, 'list_page', $pageLang);
                    /* 🆕 v2.34: معنای انگلیسیِ جدول برای تطبیقِ زیرسیستم */
                    $enMeaning = $this->englishMeaningFor((string)$pCode, [$ctx]);
                    if ($enMeaning !== '') {
                        $found[$pCode]['_title_en'] = $enMeaning;
                    }
                    if ($ctx !== '') {
                        $contextTexts[$pCode][] = $ctx;
                    }
                } else {
                    if (!empty($pTitle) && mb_strlen($pTitle) >= 10) {
                        $oldTitle = (string)$found[$pCode]['title'];
                        if (self::titleIsGeneric($oldTitle, $pCode)) {
                            $found[$pCode]['title'] = $pTitle;
                            $tp = $this->partFromTitle($pTitle);
                            if ($tp !== '') {
                                $found[$pCode]['part'] = $tp;
                            }
                        }
                    }
                    $found[$pCode]['_table_verified'] = true;
                    $found[$pCode]['_verified'] = true;
                    $this->noteSource($found[$pCode], $lu, $meta, 'list_page', $pageLang);
                    $ctx = $this->codeCentricContext($txt, $pCode, 700);
                    if ($ctx !== '') {
                        $enMeaning = $this->englishMeaningFor((string)$pCode, [$ctx]);
                        if ($enMeaning !== '' && empty($found[$pCode]['_title_en'])) {
                            $found[$pCode]['_title_en'] = $enMeaning;
                        }
                        if (count($contextTexts[$pCode]) < 10) {
                            $contextTexts[$pCode][] = $ctx;
                        }
                    }
                }
            }

            /* 🆕 تور ایمنی: کشف کدها از «متن کامل صفحه» با استخراج‌گر الگویی
             * (وقتی پارس جدول همه فرمت‌ها را نگیرد، این مسیر هیچ کد واقعی را
             * از دست نمی‌دهد). گارد زمینه: پنجره اطراف کد باید واژه خطا/کد/نمایش
             * داشته باشد. */
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
                        'source_urls' => [],
                        '_evidence' => 0,
                        '_verified' => false,
                        '_table_verified' => isset($tableMeanings[$pgCode]),
                    ];
                    $contextTexts[$pgCode] = [];
                    $this->noteSource($found[$pgCode], $lu, $meta, 'list_page', $pageLang);
                    $enMeaning = $this->englishMeaningFor((string)$pgCode, [$pgCtx]);
                    if ($enMeaning !== '') {
                        $found[$pgCode]['_title_en'] = $enMeaning;
                    }
                    $contextTexts[$pgCode][] = $pgCtx;
                } else {
                    $this->noteSource($found[$pgCode], $lu, $meta, 'list_page', $pageLang);
                    if (count($contextTexts[$pgCode]) < 10 && $pgCtx !== '') {
                        $contextTexts[$pgCode][] = $pgCtx;
                    }
                    $enMeaning = $this->englishMeaningFor((string)$pgCode, [$pgCtx]);
                    if ($enMeaning !== '' && empty($found[$pgCode]['_title_en'])) {
                        $found[$pgCode]['_title_en'] = $enMeaning;
                    }
                }
            }
        }

        /* ---------- فاز ۲: راستی‌آزمایی و تعمیق تک‌کد + بودجه زمانی ---------- */
        if (!empty($found)) {
            /* کدهای پرشاهد (مرتب نزولی) تک‌تک عمیق می‌شوند */
            uasort($found, function ($a, $b) {
                return ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0)
                    ?: ($b['_evidence'] ?? 0) <=> ($a['_evidence'] ?? 0);
            });
        }
        $deepBudget = (int)$P['deep_codes'];
        /* 🆕 کدهای جدول‌تأییدِ «نحیف» — دلایل/راه‌حل کمتر از ۲ مورد */
        $tableEnrichBudget = (int)$P['enrich_budget'];
        $i = 0;
        $totalCandidates = count($found);
        $processedCandidates = 0;
        foreach ($found as $codeKey => &$f) {
            $processedCandidates++;
            if ($processedCandidates % 2 === 1 || $processedCandidates === $totalCandidates) {
                $this->progress(62 + (int)round(24 * ($processedCandidates / max(1, $totalCandidates))), 'تعمیق و راستی‌آزمایی کدها', 'کد ' . $codeKey . ' بررسی می‌شود (' . $processedCandidates . ' از ' . $totalCandidates . ')');
            }
            if (!empty($f['_table_verified'])) {
                $ctxAll = implode(' . ', array_slice($contextTexts[$codeKey] ?? [], 0, 8));
                $thinCauses = count($this->extractCausesFromContext($ctxAll, $deviceKey, $f['category'] ?? 'سایر')) < 2;
                if (!$thinCauses || $tableEnrichBudget <= 0) {
                    continue;
                }
                $tableEnrichBudget--;
            } elseif ($i++ >= $deepBudget) {
                break;
            }
            if (microtime(true) > $deadline - 1.5) {
                /* ⏱️ زمان تمام شد — کدهای باقی‌مانده فقط با شاهد بالا می‌مانند */
                foreach ($found as $ck2 => $f2info) {
                    if ($this->webEvidenceOk($f2info, $this->kbKnowsCode($brandKey, $deviceKey, (string)$ck2), true)) {
                        continue;
                    }
                    $found[$ck2]['_reject'] = true;
                }
                break;
            }
            try {
                $deep = $this->deepResearchCode($searcher, $brandEn, $brandFa, $deviceEn, $deviceFa, (string)$codeKey, $deadline, [
                    'depth' => $depthKey,
                    'brand_key' => $brandKey,
                ]);
            } catch (Throwable $e) {
                $deep = null;
            }
            if ($deep === null || ((int)($deep['evidence'] ?? 0) === 0 && (string)($deep['page_text'] ?? '') === '')) {
                /* تفکیک «شکست زیرساخت» از «شاهد منفی»: وقتی همه موتورها پاسخ
                   نمی‌دهند، نبودِ شاهد معنایش «کد جعلی است» نیست. */
                $verdict = (string)($deep['verdict'] ?? 'negative');
                $kbKnows = $this->kbKnowsCode($brandKey, $deviceKey, (string)$codeKey);
                if (!$this->webEvidenceOk($f, $kbKnows, $verdict === 'infra')) {
                    $f['_reject'] = true;
                }
                continue;
            }
            $f['_verified'] = true;
            $contextTexts[$codeKey] = array_merge($contextTexts[$codeKey] ?? [], $deep['contexts']);
            foreach (($deep['metas'] ?? []) as $dUrl => $dMeta) {
                $this->noteSource($f, (string)$dUrl, (array)$dMeta, 'code_page', (string)($dMeta['lang'] ?? ''));
            }
            if (!empty($deep['title'])) {
                $f['title'] = $deep['title'];
            }
            if (!empty($deep['title_en']) && empty($f['_title_en'])) {
                $f['_title_en'] = (string)$deep['title_en'];
            }
            if (($f['part'] ?? '') === '' || $f['part'] === 'قطعه مرتبط با کد') {
                $f['part'] = ($deep['part'] ?: $f['part']);
            }
            $f['severity'] = ($deep['severity'] ?: $f['severity']);
            /* 🎯 داده‌های واقعی استخراج‌شده از «پنجره‌ی خود کد» (v2.34) */
            if (!empty($deep['page_text'])) {
                $f['_specs_ctx'] = $deep['page_text'];
            }
            if (!empty($deep['specs_web'])) {
                $f['_specs_web'] = (string)$deep['specs_web'];
            }
            if (!empty($deep['models_web'])) {
                $f['_models_web'] = array_slice((array)$deep['models_web'], 0, 8);
            }
            if (!empty($deep['location'])) {
                $f['_location'] = (string)$deep['location'];
            }
        }
        unset($f);

        /* 🧹 حذف کدهای رد‌شده در راستی‌آزمایی (ادغام شواهد) */
        $found = array_filter($found, function ($f) {
            return empty($f['_reject']);
        });

        /* ---------- تکمیل نهایی همه فیلدها — «بدون نقص» ---------- */
        foreach ($found as $codeKey => &$f) {
            if (empty($f['_specs_ctx']) && !empty($contextTexts[$codeKey])) {
                $f['_specs_ctx'] = implode(' . ', array_slice($contextTexts[$codeKey], 0, 3));
            }
            if (empty($f['_location'])) {
                $f['_location'] = $this->locationFromContext(implode(' ', array_slice($contextTexts[$codeKey] ?? [], 0, 4)));
            }
            if (empty($f['models']) || $f['models'] === []) {
                $f['models'] = $this->modelsFromContext(implode(' ', array_slice($contextTexts[$codeKey] ?? [], 0, 6)), $brandEn, array_keys($found));
            }
            unset($f['_needs_enrich']);
        }
        unset($f);

        /* 🧠 استخراج دلایل و راه‌حل‌ها از جمله‌های واقعی نتایج */
        foreach ($found as $codeKey => &$f) {
            $ctx = implode(' . ', array_slice($contextTexts[$codeKey] ?? [], 0, 10));
            $f['causes'] = $this->extractCausesFromContext($ctx, $deviceKey, $f['category']);
            $userF = $this->extractFixesFromContext($ctx, 'user');
            $techF = $this->extractFixesFromContext($ctx, 'tech');
            $f['fixes_user'] = $userF;
            $f['fixes_tech'] = $techF;
            if (!empty($f['fixes_tech'])) {
                $f['needs_technician'] = 1;
            }
            if (($f['part'] ?? '') === '' || $f['part'] === 'قطعه مرتبط با کد') {
                $f['part'] = ($this->partFromCauses($f['causes']) ?: $f['part']);
            }
            $f = $this->enforcePartConsistency($f);
        }
        unset($f);

        /* 🏷 اولویت‌بندی: امتیازِ شواهد، سپس تعداد شواهد، سپس راستی‌آزمایی */
        uasort($found, function ($a, $b) {
            return ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0)
                ?: (($b['_verified'] ?? false) <=> ($a['_verified'] ?? false))
                ?: (($b['_evidence'] ?? 0) <=> ($a['_evidence'] ?? 0));
        });
        foreach ($found as &$f) {
            unset($f['_evidence'], $f['_verified'], $f['_table_verified'], $f['_part_fixed'],
                  $f['_sources'], $f['_domains'], $f['_langs'], $f['_official'], $f['_score']);
        }
        unset($f);

        return array_values(array_slice($found, 0, (int)$P['max_codes']));
    }

    /* ==================================================
     * 🌐 v2.34 — ابزارهای جستجوی عمیق اینترنتی
     * ================================================== */

    /** 🎚 پروفایل‌های عمق جستجو */
    private const SEARCH_DEPTHS = [
        'fast' => [
            'queries' => 8, 'engines' => 1, 'list_pages' => 4, 'pages_per_code' => 1,
            'code_queries' => 2, 'deep_codes' => 6, 'enrich_budget' => 3,
            'max_codes' => 20, 'page_chars' => 8000,
        ],
        'balanced' => [
            'queries' => 16, 'engines' => 1, 'list_pages' => 8, 'pages_per_code' => 1,
            'code_queries' => 4, 'deep_codes' => 14, 'enrich_budget' => 8,
            'max_codes' => 40, 'page_chars' => 11000,
        ],
        'deep' => [
            'queries' => 28, 'engines' => 2, 'list_pages' => 12, 'pages_per_code' => 4,
            'code_queries' => 8, 'deep_codes' => 24, 'enrich_budget' => 14,
            'max_codes' => 60, 'page_chars' => 16000,
        ],
    ];

    /** 🎚 دریافت پروفایل عمق (ناشناس → متعادل) */
    public static function depthProfile(string $depth): array
    {
        $d = strtolower(trim($depth));
        return self::SEARCH_DEPTHS[$d] ?? self::SEARCH_DEPTHS['balanced'];
    }

    /** 🛑 آیا این خطا از نوع «سقف نرخ جستجوی وب» است؟ */
    private function isRateLimitError(Throwable $e): bool
    {
        $msg = $e->getMessage();
        return $msg !== '' && (mb_strpos($msg, 'سقف جستجوی وب') !== false);
    }

    /** 📚 دانش منابع وب (فارسی/خارجی) — کش استاتیک */
    private static $webSourcesCfg = null;

    private function webSources(): array
    {
        if (self::$webSourcesCfg === null) {
            $path = ENGINE_PATH . '/knowledge/errorcode-web-sources.json';
            $raw  = @file_get_contents($path);
            $data = $raw === false ? null : json_decode($raw, true);
            self::$webSourcesCfg = is_array($data) ? $data : [];
        }
        return self::$webSourcesCfg;
    }

    /** 🌍 تشخیص زبان یک متن: 'fa' | 'en' | 'other' */
    private function detectLang(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return 'other';
        }
        $persian = preg_match_all('/[\x{0600}-\x{06FF}]/u', $text);
        $latin   = preg_match_all('/[A-Za-z]/', $text);
        if ($persian > 0 && $persian >= (int)($latin * 0.25)) {
            return 'fa';
        }
        if ($latin > 0) {
            return 'en';
        }
        return 'other';
    }

    /** 🌐 میزبانِ نرمال‌شده یک URL */
    private function urlHost(string $url): string
    {
        $host = (string)(parse_url(trim($url), PHP_URL_HOST) ?? '');
        $host = preg_replace('#^www\.#', '', strtolower($host));
        return is_string($host) ? $host : '';
    }

    /** 🚫 آیا این دامنه در فهرستِ بی‌فایده‌هاست؟ (فروشگاه/شبکه اجتماعی/ویدیو) */
    private function isStopDomain(string $url): bool
    {
        $cfg = $this->webSources();
        $host = $this->urlHost($url);
        if ($host === '') {
            return false;
        }
        foreach ((array)($cfg['stop_domains'] ?? []) as $bad) {
            $bad = strtolower(trim((string)$bad));
            if ($bad === '') {
                continue;
            }
            if ($host === $bad || substr($host, -strlen('.' . $bad)) === '.' . $bad) {
                return true;
            }
        }
        return false;
    }

    /**
     * 🏛️ شناسنامه‌ی یک منبع: دامنه، نوع و امتیاز اعتماد
     * نوع‌ها: official (سایت خود برند) > manual (دفترچه‌ها) > parts (فروشگاه قطعات)
     *        > forum (انجمن‌های تعمیرات) > fa_site (منبع فارسی) > other
     */
    private function sourceMeta(string $url, ?string $brandKey = null): array
    {
        $cfg = $this->webSources();
        $host = $this->urlHost($url);
        $trusts = (array)($cfg['trust'] ?? []);
        $t = function (string $k, int $def = 34) use ($trusts) {
            return (int)($trusts[$k] ?? $def);
        };

        /* ۱) دامنه‌ی رسمیِ خودِ برند */
        $official = [];
        if ($brandKey !== null && $brandKey !== '') {
            $official = (array)($cfg['official_domains'][$brandKey] ?? []);
        }
        foreach ($official as $dom) {
            $dom = strtolower(trim((string)$dom));
            if ($dom !== '' && ($host === $dom || substr($host, -strlen('.' . $dom)) === '.' . $dom)) {
                return ['domain' => $host, 'kind' => 'official', 'trust' => $t('official', 100)];
            }
        }
        /* ۲) سایت‌های عمودیِ شناخته‌شده */
        foreach ((array)($cfg['verticals'] ?? []) as $v) {
            $dom = strtolower(trim((string)($v['domain'] ?? '')));
            if ($dom !== '' && ($host === $dom || substr($host, -strlen('.' . $dom)) === '.' . $dom)) {
                return [
                    'domain' => $host,
                    'kind'   => (string)($v['kind'] ?? 'other'),
                    'trust'  => (int)($v['trust'] ?? $t('other')),
                ];
            }
        }
        /* ۳) دامنه‌ی ایرانی → منبع فارسی */
        if (substr($host, -3) === '.ir') {
            return ['domain' => $host, 'kind' => 'fa_site', 'trust' => $t('fa_site', 62)];
        }
        return ['domain' => $host, 'kind' => 'other', 'trust' => $t('other')];
    }

    /**
     * 🧮 ثبت یک شاهد برای کد + به‌روزرسانی امتیاز، زبان‌ها و دامنه‌ها
     * (ادغام شواهد: تعداد دامنه‌های مستقل از تعداد تکرار مهم‌تر است)
     */
    private function noteSource(array &$f, string $url, array $meta, string $where, string $lang): void
    {
        if (!isset($f['_sources']) || !is_array($f['_sources'])) {
            $f['_sources'] = [];
        }
        if (!isset($f['_domains']) || !is_array($f['_domains'])) {
            $f['_domains'] = [];
        }
        if (!isset($f['_langs']) || !is_array($f['_langs'])) {
            $f['_langs'] = [];
        }
        $url = trim($url);
        if ($url !== '' && !isset($f['_sources'][$url])) {
            $f['_sources'][$url] = [
                'url'    => $url,
                'domain' => (string)($meta['domain'] ?? ''),
                'kind'   => (string)($meta['kind'] ?? 'other'),
                'trust'  => (int)($meta['trust'] ?? 34),
                'where'  => $where,
                'lang'   => $lang,
            ];
            if (!isset($f['source_urls']) || !is_array($f['source_urls'])) {
                $f['source_urls'] = [];
            }
            if (count($f['source_urls']) < 6 && !in_array($url, $f['source_urls'], true)) {
                $f['source_urls'][] = $url;
            }
        }
        $dom = (string)($meta['domain'] ?? '');
        if ($dom !== '') {
            $f['_domains'][$dom] = ($f['_domains'][$dom] ?? 0) + 1;
        }
        if ($lang !== '') {
            $f['_langs'][$lang] = true;
        }
        if (($meta['kind'] ?? '') === 'official' || ($meta['kind'] ?? '') === 'manual') {
            $f['_strong'] = true;
        }
        $f['_evidence'] = ($f['_evidence'] ?? 0) + 1;
        $f['_score'] = ($f['_score'] ?? 0)
            + max(1, (int)round(((int)($meta['trust'] ?? 34)) / 12))
            + ($where === 'code_page' ? 3 : ($where === 'list_page' ? 2 : 0));
    }

    /**
     * ✅ آیا شواهدِ وب برای پذیرش این کد کافی است؟ (سیاستِ «فقط کدهای واقعی»)
     * کد با ≥۲ دامنه‌ی مستقل، یا یک منبعِ رسمی/دفترچه، یا تأیید پایگاه دانش
     * پذیرفته می‌شود؛ تک‌منبعِ ضعیف رد می‌گردد — مگر اینکه زیرساخت جستجو
     * اصلاً پاسخ نداده باشد ($infra).
     */
    private function webEvidenceOk(array $f, bool $kbKnows, bool $infra = false): bool
    {
        if (!empty($f['_table_verified'])) {
            return true;
        }
        if (!empty($f['_strong']) || !empty($f['_official'])) {
            return true;
        }
        if (count((array)($f['_domains'] ?? [])) >= 2) {
            return true;
        }
        if ($kbKnows) {
            return true;
        }
        if ($infra) {
            return true;
        }
        return ((int)($f['_score'] ?? 0)) >= 8;
    }

    /**
     * 📊 آمار منابع اینترنتیِ یک نوبت جستجو — برای گزارشِ صادقانه به مدیر
     * (چند سایت فارسی، چند خارجی، چند رسمی/دفترچه/انجمن)
     */
    private function sourceStats(array $urls, ?string $brandKey = null): array
    {
        $stats = ['total' => 0, 'fa' => 0, 'en' => 0, 'official' => 0, 'manual' => 0, 'parts' => 0, 'forum' => 0];
        $domains = [];
        foreach ($urls as $u) {
            $u = trim((string)$u);
            if ($u === '' || !preg_match('#^https?://#i', $u)) {
                continue;
            }
            $host = $this->urlHost($u);
            if ($host === '' || isset($domains[$host])) {
                continue;
            }
            $domains[$host] = true;
            $meta = $this->sourceMeta($u, $brandKey);
            $stats['total']++;
            $kind = (string)($meta['kind'] ?? 'other');
            if (isset($stats[$kind])) {
                $stats[$kind]++;
            }
            if ($kind === 'fa_site' || substr($host, -3) === '.ir') {
                $stats['fa']++;
            } else {
                $stats['en']++;
            }
        }
        return $stats;
    }

    /**
     * 🧭 ساخت کوئری‌های کشف (فارسی و انگلیسی، یکی‌درمیان) از دانش منابع
     * @return array<array{q:string,lang:string}>
     */
    private function buildDiscoveryQueries(string $brandEn, string $brandFa, string $deviceEn, string $deviceFa, int $target): array
    {
        $cfg = $this->webSources();
        $rep = [
            '{brand}'    => $brandEn,
            '{brand_fa}' => $brandFa,
            '{device}'   => $deviceEn,
            '{device_fa}'=> $deviceFa,
        ];
        $en = (array)($cfg['discovery_queries']['en'] ?? []);
        $fa = (array)($cfg['discovery_queries']['fa'] ?? []);
        $fill = static function ($tpl) use ($rep) {
            return trim(preg_replace('/\s+/u', ' ', strtr((string)$tpl, $rep)));
        };
        $out = [];
        $n = max(count($en), count($fa));
        for ($i = 0; $i < $n && count($out) < $target; $i++) {
            if (isset($en[$i])) {
                $q = $fill($en[$i]);
                if ($q !== '') {
                    $out[] = ['q' => $q, 'lang' => 'en'];
                }
            }
            if (count($out) >= $target) {
                break;
            }
            if (isset($fa[$i])) {
                $q = $fill($fa[$i]);
                if ($q !== '') {
                    $out[] = ['q' => $q, 'lang' => 'fa'];
                }
            }
        }
        /* 🎯 تکمیل با کوئری‌های عمودیِ سایت‌های دفترچه/قطعات/انجمن */
        if (count($out) < $target) {
            $verts = (array)($cfg['verticals'] ?? []);
            usort($verts, static function ($a, $b) {
                return (int)($b['trust'] ?? 0) <=> (int)($a['trust'] ?? 0);
            });
            foreach ($verts as $v) {
                if (count($out) >= $target) {
                    break;
                }
                $booster = trim((string)($v['booster_en'] ?? ''));
                if ($booster === '') {
                    continue;
                }
                $out[] = ['q' => $fill('{brand} {device} error code ' . $booster), 'lang' => 'en'];
            }
        }
        return array_slice($out, 0, max(1, $target));
    }

    /**
     * 🎯 ساخت کوئری‌های اختصاصیِ یک کد (فارسی و انگلیسی، یکی‌درمیان)
     * @return array<array{q:string,lang:string}>
     */
    private function buildCodeQueries(string $brandEn, string $brandFa, string $deviceEn, string $deviceFa, string $code, int $target): array
    {
        $cfg = $this->webSources();
        $rep = [
            '{brand}'    => $brandEn,
            '{brand_fa}' => $brandFa,
            '{device}'   => $deviceEn,
            '{device_fa}'=> $deviceFa,
            '{code}'     => $code,
        ];
        $en = (array)($cfg['code_queries']['en'] ?? []);
        $fa = (array)($cfg['code_queries']['fa'] ?? []);
        $fill = static function ($tpl) use ($rep) {
            return trim(preg_replace('/\s+/u', ' ', strtr((string)$tpl, $rep)));
        };
        $out = [];
        $n = max(count($en), count($fa));
        for ($i = 0; $i < $n && count($out) < $target; $i++) {
            if (isset($en[$i])) {
                $q = $fill($en[$i]);
                if ($q !== '') {
                    $out[] = ['q' => $q, 'lang' => 'en'];
                }
            }
            if (count($out) >= $target) {
                break;
            }
            if (isset($fa[$i])) {
                $q = $fill($fa[$i]);
                if ($q !== '') {
                    $out[] = ['q' => $q, 'lang' => 'fa'];
                }
            }
        }
        return array_slice($out, 0, max(1, $target));
    }

    /**
     * 🔤 استخراج «معنای انگلیسی» یک کد از متن‌های وب (مثل "OE = Drain Error")
     * هدف: تطبیقِ زیرسیستمِ علّی با عنوانِ واقعیِ خارجی، نه فقط عنوان فارسی.
     */
    private function englishMeaningFor(string $code, array $texts): string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }
        $variants = array_values(array_unique(array_filter(self::codeVariantSet($code), 'strlen')));
        foreach ($texts as $t) {
            if ($t === '') {
                continue;
            }
            foreach ($variants as $cv) {
                $pattern = '/\b' . preg_quote($cv, '/') . '\s*(?:=|:|\||\x{2013}|\x{2014}|-|,)\s*([A-Za-z][A-Za-z \t&\'\-\/]{4,60})/u';
                if (preg_match($pattern, $t, $m)) {
                    $en = trim(preg_replace('/\s+/u', ' ', (string)$m[1]));
                    $en = (string)preg_replace('/\s+(Meaning|Error\s+Meaning|Guide|Explained)$/i', '', $en);
                    if (mb_strlen($en) >= 4 && !preg_match('/^(what|how|why|this|the|and|for)\b/i', $en)) {
                        return mb_substr($en, 0, 70);
                    }
                }
                /* الگوی دوم: «CODE Water Drainage Error» (بدون جداکننده)
                   ⚠️ حداقل یک واژه‌ی توصیفی پیش از Error/Fault لازم است —
                   وگرنه «OE Error» به معنای بی‌محتوای «Error» ترجمه می‌شد */
                $pattern2 = '/\b' . preg_quote($cv, '/') . '\s+((?:[A-Z][a-zA-Z]{2,}\s+){1,3}(?:Error|Fault|Failure|Protection|Sensor|Problem))\b/u';
                if (preg_match($pattern2, $t, $m2)) {
                    $en = trim(preg_replace('/\s+/u', ' ', (string)$m2[1]));
                    if (mb_strlen($en) >= 8 && !preg_match('/^(Error|Fault|Failure|Problem|Sensor)$/i', $en)) {
                        return mb_substr($en, 0, 70);
                    }
                }
            }
        }
        /* 🆕 مسیر سوم: عبارت انگلیسیِ خطا در همان پنجره (مثل «Door Lock Error»
           در متنی که کد با فاصله/فعل فارسی از آن جدا شده) */
        foreach ($texts as $t) {
            if ($t === '') {
                continue;
            }
            $codeHere = false;
            foreach ($variants as $cv) {
                if (mb_stripos($t, $cv) !== false) {
                    $codeHere = true;
                    break;
                }
            }
            if (!$codeHere) {
                continue;
            }
            if (preg_match('/((?:[A-Z][a-zA-Z]{2,}\s+){1,3}(?:Error|Fault|Failure|Protection|Problem))\b/u', $t, $m3)) {
                $en = trim(preg_replace('/\s+/u', ' ', (string)$m3[1]));
                if (mb_strlen($en) >= 8 && !preg_match('/^(Error|Fault|Failure|Problem)$/i', $en)) {
                    return mb_substr($en, 0, 70);
                }
            }
        }
        return '';
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
        if (preg_match_all('/\b(Er\s?)?([A-Z]{1,2}-?\d{1,2}|\d{1,2}-?[A-Z]{1,2}|[A-Z]{2}\d{0,2}|\d{3})\s*(?:=|:|\||\x{2013}|\x{2014}|-)\s*([a-zA-Z][a-zA-Z \t&\'\-]{8,70})/u', $text, $m, PREG_SET_ORDER)) {
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
                if ($fa === null) {
                    /* 🆕 v3.14: واژه بیرون دیکشنری دیگر «کل ردیف جدول» را حذف
                       نمی‌کند — استخراج کلیدواژه‌ای از خود معنا (شبکه/بک‌لایت/
                       مگنترون/...) با واژه‌نامه titleFromContext */
                    $fa = $this->titleFromContext($code, $en, '');
                }
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
        if (preg_match_all('/\b([A-Z]{1,2}-?\d{1,2}|\d{1,2}-?[A-Z]{1,2}|[A-Z]{2}\d{0,2}|\d{3})\b[^\nA-Za-z]{0,12}?(?:خطای|ایراد|علامت)\s+([^\n.،؛!؟]{6,60})/u', $text, $m, PREG_SET_ORDER)) {
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
        if (preg_match_all('/^\s*\|?\s*([A-Z]{1,2}-?\d{1,2}|\d{1,2}-?[A-Z]{1,2}|[A-Z]{2}\d{0,2}|\d{3})\b\s*\|\s*([^\n|]{8,70})/um', $text, $m, PREG_SET_ORDER)) {
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
    /**
     * 🎯 جستجوی عمیق تک‌کد (v2.34) — راستی‌آزمایی + متن غنی + معنای دقیق
     *
     * برای هر کد کاندید:
     *   ۱) چند کوئری اختصاصی EN + FA حول خود کد (تعدادش با پروفایل عمق)
     *   ۲) خواندن متن کامل ۱ تا ۴ صفحه‌ی برتر (نه فقط اسنیپت) با بودجه زمانی
     *   ۳) استخراج معنای کد از تیتر/جدول صفحه (مثل «OE = Drain Error»)
     *   ۴) استخراج مشخصات/مدل/محل قطعه فقط از «پنجره‌ی دورِ خود کد» تا داده‌ی
     *      کدهای همسایه وارد فیلدهای این کد نشود
     *
     * @return array|null null = کد در وب تأیید نشد
     */
    private function deepResearchCode(WebSearchService $searcher, string $brandEn, string $brandFa, string $deviceEn, string $deviceFa, string $code, ?float $deadline = null, array $opts = []): ?array
    {
        $deadline = $deadline ?? (microtime(true) + 20.0);
        $P = self::depthProfile((string)($opts['depth'] ?? 'balanced'));
        $brandKey = isset($opts['brand_key']) ? (string)$opts['brand_key'] : null;

        $contexts = [];
        $urls = [];
        $metas = [];
        $evidence = 0;
        $searchesReturned = 0;
        $bestTitle = '';
        $bestPart = '';
        $bestSeverity = '';
        $bestEn = '';

        /* 🧭 کوئری‌های اختصاصیِ این کد — فارسی و انگلیسی */
        $queries = $this->buildCodeQueries($brandEn, $brandFa, $deviceEn, $deviceFa, $code, (int)$P['code_queries']);
        foreach ($queries as $qItem) {
            if (microtime(true) > $deadline - 1.0) {
                break;
            }
            try {
                $res = $searcher->search((string)$qItem['q'], 6, [
                    'lang'     => (string)$qItem['lang'],
                    'engines'  => 1,
                    'deadline' => $deadline,
                ]);
            } catch (Throwable $e) {
                if ($this->isRateLimitError($e)) {
                    $this->rateLimited = true;
                    break;
                }
                continue;
            }
            if (!empty($res['results'])) {
                $searchesReturned++;
            }
            foreach ($res['results'] ?? [] as $r) {
                $text = ($r['title'] ?? '') . ' — ' . ($r['snippet'] ?? '');
                $url = (string)($r['url'] ?? '');
                if ($text === '' || trim($text) === '—') {
                    continue;
                }
                if ($url !== '' && ($this->isStopDomain($url) || isset($urls[$url]))) {
                    continue;
                }
                /* خود کد باید در نتیجه باشد (با توجه به فاصله در «Er FF») */
                $hasCode = false;
                foreach (self::codeVariantSet($code) as $cv) {
                    if (stripos($text, $cv) !== false || mb_strpos($text, $cv) !== false) {
                        $hasCode = true;
                        break;
                    }
                }
                if (!$hasCode) {
                    continue;
                }
                /* زمینه برند/دستگاه */
                $hasCtx = stripos($text, $brandEn) !== false
                    || mb_strpos($text, $brandFa) !== false
                    || stripos($text, $deviceEn) !== false
                    || mb_strpos($text, $deviceFa) !== false;
                if (!$hasCtx) {
                    continue;
                }
                $evidence++;
                if (count($contexts) < 8) {
                    $contexts[] = $text;
                }
                if ($url !== '') {
                    $urls[$url] = true;
                    $meta = $this->sourceMeta($url, $brandKey);
                    $meta['lang'] = $this->detectLang($text);
                    $metas[$url] = $meta;
                }
                if ($bestTitle === '') {
                    $t = $this->meaningFromTitle((string)($r['title'] ?? ''), $code, $deviceFa);
                    if ($t !== null) {
                        $bestTitle = $t;
                    }
                }
                if ($bestEn === '') {
                    $en = $this->englishMeaningFor($code, [$text]);
                    if ($en !== '') {
                        $bestEn = $en;
                    }
                }
            }
        }

        /* 📖 خواندن متن کامل چند صفحه‌ی برتر — منبع اصلی دلایل/راه‌حل/مشخصات */
        $windows = [];
        $primaryCtx = '';
        $pagesOk = 0;
        $urlList = array_keys($urls);
        $pages = $searcher->fetchPages($urlList, (int)$P['page_chars'], (int)$P['pages_per_code'], $deadline);
        foreach ($pages as $page) {
            $txt = (string)$page['text'];
            $u = (string)$page['url'];
            /* صفحه باید کد و زمینه را داشته باشد */
            $hasCode = false;
            foreach (self::codeVariantSet($code) as $cv) {
                if (stripos($txt, $cv) !== false || mb_strpos($txt, $cv) !== false) {
                    $hasCode = true;
                    break;
                }
            }
            $hasCtx = stripos($txt, $brandEn) !== false || stripos($txt, $deviceEn) !== false
                || mb_strpos($txt, $brandFa) !== false || mb_strpos($txt, $deviceFa) !== false;
            if (!$hasCode || !$hasCtx || mb_strlen($txt) < 400) {
                continue;
            }
            $pagesOk++;
            /* 🆕 پنجره کد-محور — متنِ «همان کد»، نه ابتدای صفحه که شرح کدهای دیگر است */
            $win = $this->codeCentricContext($txt, $code, 1200);
            if ($win !== '') {
                $windows[] = $win;
                if (count($contexts) < 10) {
                    $contexts[] = (($page['title'] ?? '') !== '' ? $page['title'] . ' — ' : '') . $win;
                }
            }
            if ($bestTitle === '') {
                $t = $this->meaningFromTitle((string)($page['title'] ?? ''), $code, $deviceFa);
                if ($t !== null) {
                    $bestTitle = $t;
                }
            }
            if ($bestEn === '') {
                $en = $this->englishMeaningFor($code, [(string)($page['title'] ?? ''), $win]);
                if ($en !== '') {
                    $bestEn = $en;
                }
            }
            /* 🎯 معنای کد از جدولِ همان صفحه (دقیق‌ترین منبع) */
            $pairs = $this->parseCodeTable($txt);
            if (isset($pairs[$code]) && mb_strlen((string)$pairs[$code]) >= 8) {
                if ($bestTitle === '' || self::titleIsGeneric($bestTitle, $code)) {
                    $bestTitle = (string)$pairs[$code];
                }
            }
            if (!isset($metas[$u])) {
                $meta = $this->sourceMeta($u, $brandKey);
                $meta['lang'] = $this->detectLang($txt);
                $metas[$u] = $meta;
            }
        }

        if ($evidence === 0 && empty($windows)) {
            /* «negative» = نتایج آمد ولی این کد تأیید نشد؛
               «infra» = زیرساخت جستجو اصلاً پاسخ نداد (بلاک/نرخ پر) */
            return ['evidence' => 0, 'contexts' => [], 'urls' => array_keys($urls), 'metas' => $metas,
                    'title' => '', 'part' => '', 'severity' => '', 'page_text' => '', 'location' => '',
                    'specs_web' => '', 'models_web' => [], 'title_en' => '',
                    'verdict' => $searchesReturned > 0 ? 'negative' : 'infra'];
        }

        /* 🏆 بهترین پنجره: پنجره‌ای که بیشترین نشانه‌های خطا/تعمیر را دارد */
        $primaryCtx = $this->bestCodeWindow($windows, $code);
        $richCtx = $primaryCtx !== '' ? $primaryCtx : implode(' . ', $contexts);
        if ($bestPart === '') {
            $bestPart = $this->partFromContext($richCtx);
            if ($bestPart === 'قطعه مرتبط با کد') {
                $bestPart = '';
            }
        }
        if ($bestSeverity === '') {
            $bestSeverity = $this->severityFromContext($richCtx);
        }
        /* 🧭 نقشه کدهای شناخته‌شده — معنای استاندارد کدهای پرتکرار برندهای اصلی */
        $known = self::knownCodeMeaning($code, $deviceEn);
        if ($known !== null) {
            if ($bestTitle === '' || mb_strlen($bestTitle) < 10) {
                $bestTitle = $known['title'];
            }
            if ($bestPart === '') {
                $bestPart = $known['part'];
            }
            if ($bestSeverity === 'medium') {
                $bestSeverity = $known['severity'];
            }
        }

        /* ⚙️ داده‌های واقعی فقط از پنجره‌ی خود کد (جلوگیری از نشتِ کدهای دیگر) */
        $specsWeb = $primaryCtx !== '' ? $this->specsFromContext($primaryCtx) : '';
        $modelsWeb = ($primaryCtx !== '' && $pagesOk > 0)
            ? $this->modelsFromContext($primaryCtx, $brandEn, [])
            : [];
        $locationWeb = $primaryCtx !== '' ? $this->locationFromContext($primaryCtx) : '';

        return [
            'evidence'   => max(1, $evidence),
            'contexts'   => $contexts,
            'urls'       => array_keys($urls),
            'metas'      => $metas,
            'title'      => $bestTitle,
            'part'       => $bestPart,
            'severity'   => $bestSeverity,
            'page_text'  => $richCtx,
            'location'   => $locationWeb,
            'specs_web'  => $specsWeb,
            'models_web' => $modelsWeb,
            'title_en'   => $bestEn,
            'pages'      => $pagesOk,
        ];
    }

    /**
     * 🏆 انتخاب بهترین «پنجره‌ی کد-محور» از میان صفحات خوانده‌شده
     * معیار: تکرارِ خود کد + واژگان خطا/تعمیر + طول متن — پنجره‌ای که توضیحِ
     * واقعیِ همین کد باشد (نه تیتر صفحه یا شرح کدهای دیگر) برنده می‌شود.
     */
    private function bestCodeWindow(array $windows, string $code): string
    {
        $best = '';
        $bestScore = -1;
        $variants = array_values(array_unique(array_filter(self::codeVariantSet($code), 'strlen')));
        foreach ($windows as $w) {
            if (trim($w) === '') {
                continue;
            }
            $score = 0;
            foreach ($variants as $cv) {
                $score += 6 * preg_match_all('/\b' . preg_quote($cv, '/') . '\b/u', $w);
            }
            $score += 2 * preg_match_all('/(error|fault|code|cause|fix|repair|replace|test|sensor|کد خطا|خطا|ارور|علت|رفع|تعمیر)/iu', $w);
            $score += (int)(mb_strlen($w) / 200);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $w;
            }
        }
        return $best;
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
            /* 🆕 v3.14: تلویزیون (webOS/پنل/شبکه) و مایکروویو — مقدم بر قوانین عمومی
               ریشه‌یابی: جدول‌های کامل سازنده LG برای این دستگاه‌ها به‌خاطر نبودن
               این واژه‌ها «کامل» دور ریخته می‌شدند */
            '/back\s?light|backlight/i' => 'بک‌لایت LED',
            '/t-?con/i' => 'برد T-Con',
            '/main\s?board|mainboard/i' => 'مین‌برد',
            '/power\s?(board|supply)/i' => 'پاور بورد (منبع تغذیه)',
            '/wi-?fi|wireless/i' => 'ماژول وای‌فای',
            '/network|internet|router|lan|ethernet|dns/i' => 'اتصال شبکه و اینترنت',
            '/unable to load|app(lication)?s?\s+(load|fail|crash)|content/i' => 'بارگذاری اپلیکیشن و محتوا',
            '/webos|firmware|software|update|upgrade/i' => 'نرم‌افزار (فیرویر)',
            '/signal|antenna|tuner|channel|broadcast/i' => 'سیگنال آنتن و تیونر',
            '/hdmi|arc|port/i' => 'پورت HDMI',
            '/display\s?panel|oled\s?panel|panel/i' => 'پنل نمایشگر',
            '/remote\s?control|remote/i' => 'ریموت کنترل',
            '/internal\s?(storage|memory)/i' => 'حافظه داخلی',
            '/region|geograph|country/i' => 'محدودیت جغرافیایی محتوا',
            '/magnetron/i' => 'مگنترون',
            '/high\s?voltage|hv\s?diode/i' => 'دیود ولتاژ بالا (HV)',
            '/capacitor/i' => 'خازن',
            '/thermal\s?(fuse|cut\s?off)/i' => 'فیوز حرارتی',
            '/door\s?(switch|latch)|interlock/i' => 'قفل درب (میکروسوئیچ)',
            '/membrane|touch\s?pad/i' => 'کیپد (صفحه‌کلید)',
            '/turntable|rotat/i' => 'موتور گردان (ترن‌تیبل)',
            '/waveguide|stirrer/i' => 'راهنمای موج و پخش‌کننده',
            '/steam\s?sensor/i' => 'سنسور بخار',
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
            /* مشخصات فنی و محل — فقط وقتی وب چیزِ واقعی استخراج نکرده
               (🆕 v2.34: _specs_web/_location_web = داده‌ی استخراج‌شده از
               پنجره‌ی خود کد؛ اگر آن‌ها خالی باشند، KB مقدم است) */
            if (!empty($kc['specs']) && empty($wc['_specs_web'])) {
                $wc['specs'] = $kc['specs'];
            }
            if (!empty($kc['location']) && empty($wc['_location_web']) && empty($wc['_location'])) {
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
    /**
     * 🆕 v3.13: برش بخش ساختاریافته «نشانگر شروع → نشانگر پایان/سقف»
     * جایگزین regex مهارشده‌ای که روی متن‌های بلند با «preg_match_all():
     * regular expression is too large» می‌ترکید و بخش‌های Possible Causes /
     * Troubleshooting Steps را کاملاً از دست می‌داد (ریشه «فیلدهای ناقص»).
     */
    private function sliceStructuredSections(string $ctx, string $startRe, array $endRegexes, int $maxLen = 700, int $maxSections = 3): array
    {
        $out = [];
        if (!preg_match_all($startRe, $ctx, $mm, PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        foreach (array_slice($mm[0], 0, $maxSections) as $mk) {
            $start = $mk[1] + strlen($mk[0]);
            $rest = substr($ctx, $start);
            if ($rest === false || $rest === '') { continue; }
            $len = min($maxLen, strlen($rest));
            foreach ($endRegexes as $ere) {
                if (preg_match($ere, $rest, $em, PREG_OFFSET_CAPTURE)) {
                    $len = min($len, (int)$em[0][1]);
                }
            }
            if ($len > 15) {
                $out[] = substr($rest, 0, $len);
            }
        }
        return $out;
    }

    private function extractCausesFromContext(string $ctx, string $deviceKey, string $category): array
    {
        $causes = [];
        /* 🆕 v2.14: بخش ساختاریافته «Possible Causes:» — رایج‌ترین قالب مقالات فنی
           مثال: «Possible Causes: Damaged wire harness. Faulty thermistor.»
           ⚠️ الگوی مهارشده: هرچه تا کد بعدی/بخش بعدی است می‌گیرد (دو-نقطه داخلی مجاز) */
        foreach ($this->sliceStructuredSections(
            $ctx,
            '/(?:possible\s+)?causes?\s*:/is',
            ['/\b(?:troubleshoot\w*|how\s+to|solution|fix\b|repair\b|related|meaning|final|diagnos\w*|symptom\w*|next\s+step)\b/i', '/\b[A-Z]{1,2}-?\d{1,2}\s*(?::|\b)/'],
            700
        ) as $block) {
            {
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
            foreach ($this->sliceStructuredSections(
                $ctx,
                '/troubleshoot\w*\s*(?:steps?)?\s*:/is',
                ['/\b(?:related|meaning|final|conclusion|notes?\b|when\s+to|next\s+step|possible\s+causes)\b/i', '/\b[A-Z]{1,2}-?\d{1,2}\s*(?::|\b)/'],
                900
            ) as $block) {
                {
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
    private function modelsFromContext(string $ctx, string $brandEn = '', array $knownCodes = []): array
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
                /* 🆕 v3.14: خودِ کدهای خطا مدل نیستند! — ریشه‌یابی «مدل‌های
                   نامربوط»: متن فهرست کدها پر از «E-01 / F-13 / CH05» است و
                   الگوی مدل همه را «مدل سازگار» می‌گرفت */
                $normTok = strtoupper(str_replace([' ', '-'], '', $mm));
                foreach ($knownCodes as $kc) {
                    if (strtoupper(str_replace([' ', '-'], '', (string)$kc)) === $normTok) { continue 2; }
                }
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
            /* 🆕 v3.14: تلویزیون و مایکروویو */
            '/مگنترون/' => 'مگنترون',
            '/بک‌لایت/' => 'بک‌لایت LED',
            '/T-?Con|تی‌کان/' => 'برد T-Con',
            '/پاور|منبع تغذیه/' => 'پاور بورد',
            '/وای‌فای|شبکه|اینترنت/' => 'ماژول وای‌فای',
            '/کیپد|کیبورد/' => 'کیپد (صفحه‌کلید)',
            '/فیوز حرارتی/' => 'فیوز حرارتی',
            '/دیود/' => 'دیود ولتاژ بالا',
            '/آنتن|سیگنال|تیونر/' => 'تیونر و برد سیگنال',
            '/نرم‌افزار|فیرویر/' => 'نرم‌افزار webOS',
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
        if (preg_match_all('/\b(E-?\d{1,2}|F-?\d{1,2}|CH-?\d{1,2}|Er\s?[A-Z]{1,2}|[4n5d1][Ee]|[UOILFCAPBSDHTMN][Ee]|[dt][Ee]|[Hh]\d{1,2})\b/u', $text, $m)) {
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
        /* ۳) 🆕 v3.13: کدهای عددی سه‌رقمی تلویزیون/webOS — «LG TV Error Code 137»،
         *    «error codes like 137, 324, 202, 105, and 109» (سبک رایج خطاهای
         *    اپلیکیشن تلویزیون‌های هوشمند). گارد: واژه error/code/fault باید
         *    دقیقاً قبل عدد باشد — سال‌ها (2024) و شماره مدل‌ها رد می‌شوند.
         *    فهرست‌های با ویرگول کامل گرفته می‌شوند (یک تطبیق = همه کدها). */
        if (preg_match_all('/\b(?:errors?|faults?|codes?)\s+(?:codes?\s+|of\s+)?(?:like\s+|numbers?\s+|no\.?\s*|[:#\\-]\s*)?(\d{3}(?:\s*[,،]\s*(?:and\s+)?\d{3})*)/ui', $text, $m)) {
            foreach ($m[1] as $list) {
                preg_match_all('/\b(\d{3})\b/', $list, $nums);
                foreach ($nums[1] as $raw) {
                    $n = (int)$raw;
                    if ($n < 100) { continue; } // سه‌رقمی واقعی
                    $c = $this->normalizeCode($raw);
                    if ($c !== null && !isset($codes[$c])) {
                        $codes[$c] = true;
                    }
                }
            }
        }
        /* ۴) 🆕 v3.13: تیترهای ساختاری «LG TV Error Code 137» — بعد از پاک‌سازی
         *    markdown خط مستقل می‌شود؛ الگو: (برند/دستگاه) + error + code + عدد */
        if (preg_match_all('/^\s*(?:[A-Z][a-zA-Z]{0,15}(?:\s+[A-Z][a-zA-Z]{0,15}){0,3}\s+)?(?:error|fault)\s+(?:code\s+)?(\d{3})\b/im', $text, $m)) {
            foreach ($m[1] as $raw) {
                $n = (int)$raw;
                if ($n < 100) { continue; }
                $c = $this->normalizeCode($raw);
                if ($c !== null && !isset($codes[$c])) {
                    $codes[$c] = true;
                }
            }
        }
        /* ۵) 🆕 v3.14: کدهای چشمک LED تلویزیون — «blinks 3 times» / «3 blinks»
         *    (روش تشخیص رسمی خطاهای سخت‌افزاری تلویزیون‌های LG/سامسونگ —
         *    کد به فارسی «چشمک N بار» ثبت می‌شود تا روی سایت خوانا باشد) */
        if (preg_match_all('/\b(?:(\d{1,2})\s+(?:blinks?|flashes?)\b|(?:blinks?|flashes?)\s+(\d{1,2})\s+times?\b)/i', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $mm) {
                $n = (int)($mm[1] !== '' ? $mm[1] : $mm[2]);
                if ($n >= 2 && $n <= 15) {
                    $blinkKey = 'چشمک ' . en_to_fa_digits((string)$n) . ' بار';
                    if (!isset($codes[$blinkKey])) {
                        $codes[$blinkKey] = true;
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

    /** 🔤 مجموعه شکل‌های نوشتاری یک کد — v3.14
     * ریشه‌یابی «رد اشتباه کد واقعی»: صفحه‌ای که «E01» می‌نویسد کدِ «E-01» را
     * تأیید نمی‌کرد (تطبیق فقط فاصله را جابه‌جا می‌کرد نه خط تیره) → کد واقعی رد می‌شد. */
    private static function codeVariantSet(string $code): array
    {
        $base = strtoupper(preg_replace('/\s+/', '', $code));
        if ($base === '') {
            return [$code];
        }
        $variants = [$code, $base, str_replace(' ', '', $code), str_replace(' ', '-', $code)];
        if (preg_match('/^([A-Z]{1,2})-?(\d{1,3})$/', $base, $m)) {
            $variants[] = $m[1] . '-' . $m[2];
            $variants[] = $m[1] . ' ' . $m[2];
            $variants[] = $m[1] . $m[2];
            $variants[] = strtolower($m[1]) . $m[2];
        }
        return array_values(array_unique(array_filter($variants)));
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

        /* 📥 داده‌های پایه از منبع (پایگاه دانش یا وب) — بدون هیچ پُرکننده‌ای */
        $causes = array_values(array_slice(array_filter(array_map('trim', (array)($c['causes'] ?? []))), 0, 7));
        $fixesUser = array_values(array_filter(array_map('trim', (array)($c['fixes_user'] ?? $c['fix_user'] ?? []))));
        $fixesTech = array_values(array_filter(array_map('trim', (array)($c['fixes_tech'] ?? $c['fix_tech'] ?? []))));
        /* 🧬 زیرسیستم در ادامه و پس از پالایشِ عنوان/قطعه تشخیص داده می‌شود
           (ترتیب مهم است: تشخیص روی عنوانِ خامِ وب، زیرسیستمِ غلط می‌داد) */

        /* راه‌حل‌ها پس از تشخیص زیرسیستم ادغام می‌شوند (پایین‌تر) */
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

        /* ==================================================
         * 🧬 v2.33 — تشخیص زیرسیستم (اینجا، پس از پالایش عنوان و قطعه)
         * ==================================================
         * همه‌ی فیلدهای فنی از این نقطه به بعد فقط از همین زیرسیستم تأمین
         * می‌شوند تا انسجام علّی به‌صورت ساختاری تضمین شود.
         * 🚨 ریشه‌ی مشکل قبلی: deviceCauses() برای **همه** کدهای یک دستگاه یک
         * لیست ثابت می‌داد (بدون توجه به کد) و برای خطای تخلیه می‌نوشت
         * «خرابی شیر برقی ورودی آب». اکنون استخرِ پُرکننده = زیرسیستمِ همین کد. */
        $sub = ErrorCodeSubsystem::resolve($deviceKey, [
            'code'      => (string)($c['code'] ?? ''),
            'title'     => $title,
            'part'      => $part,
            'category'  => (string)($c['category'] ?? ''),
            'subsystem' => (string)($c['subsystem'] ?? ''),
        ]);
        /* 🌐 v2.34: اگر عنوان فارسی قطعیت کافی نداد، «معنای انگلیسی» استخراج‌شده
           از سایت‌های خارجی هم امتحان می‌شود (مثل «Drain Error» → زیرسیستم drain).
           ریشه: بسیاری از معناهای وب انگلیسی‌اند و فقط با عنوان فارسیِ عمومی
           («خطای E4») زیرسیستم به‌درستی تشخیص داده نمی‌شد. */
        $enTitle = trim((string)($c['_title_en'] ?? ''));
        if ($enTitle !== '' && (string)($sub['confidence'] ?? 'none') !== 'high') {
            $subEn = ErrorCodeSubsystem::resolve($deviceKey, [
                'code'     => (string)($c['code'] ?? ''),
                'title'    => $enTitle,
                'part'     => '',
                'category' => (string)($c['category'] ?? ''),
            ]);
            $rank = ['none' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];
            $rNow = $rank[(string)($sub['confidence'] ?? 'none')] ?? 0;
            $rEn  = $rank[(string)($subEn['confidence'] ?? 'none')] ?? 0;
            if ($rEn > $rNow) {
                $sub = $subEn;
            }
        }
        $subKey  = $sub['key'];
        $subData = $sub['data'];

        /* تأمین علت‌ها و راه‌حل‌ها از زیرسیستم (اگر منبع چیزی نداده باشد) */
        if (empty($causes)) {
            $causes = ErrorCodeSubsystem::causes($subData);
        }
        if (empty($fixesUser) && empty($fixesTech)) {
            $subSolutions = ErrorCodeSubsystem::solutions($subData);
            $fixesUser = array_values(array_map(
                fn($s) => str_replace('[کاربر] ', '', $s),
                array_filter($subSolutions, fn($s) => strpos($s, '[کاربر] ') === 0)
            ));
            $fixesTech = array_values(array_map(
                fn($s) => str_replace('[تکنسین] ', '', $s),
                array_filter($subSolutions, fn($s) => strpos($s, '[تکنسین] ') === 0)
            ));
        }
        /* ادغامِ نهاییِ راه‌حل‌ها با برچسب (ساده→پیچیده) — پس از مشخص شدن زیرسیستم */
        $solutions = [];
        foreach ($fixesUser as $f) {
            $solutions[] = '[کاربر] ' . $f;
        }
        foreach ($fixesTech as $f) {
            $solutions[] = '[تکنسین] ' . $f;
        }
        $solutions = array_slice($solutions, 0, 8);

        /* 📏 تکمیل تا حد نصاب — فقط از «همین زیرسیستم» */
        $solutionPool = ErrorCodeSubsystem::solutions($subData);
        $si = 0;
        while (count($solutions) < 5 && $si < count($solutionPool)) {
            if (!in_array($solutionPool[$si], $solutions, true)) {
                $solutions[] = $solutionPool[$si];
            }
            $si++;
        }

        $causePool = ErrorCodeSubsystem::causes($subData);
        $ci = 0;
        while (count($causes) < 5 && $ci < count($causePool)) {
            if (!in_array($causePool[$ci], $causes, true)) {
                $causes[] = $causePool[$ci];
            }
            $ci++;
        }
        /* ⚠️ دیگر هیچ علتِ «سراسری» (مثل ریست برد) کورکورانه افزوده نمی‌شود:
           ریست فقط وقتی معنا دارد که زیرسیستم واقعاً الکترونیکی باشد. */
        if (count($causes) < 5 && $this->isElectronicSubsystem($subKey)) {
            $extraCause = 'قطع برق طولانی و روشن‌سازی مجدد (ریست کامل برد)';
            if (!in_array($extraCause, $causes, true)) {
                $causes[] = $extraCause;
            }
        }
        $causes = array_slice($causes, 0, 7);

        /* ⚡ مشخصات فنی: KB → 🆕 استخراج واقعی از پنجره‌ی خود کد (وب) → متن غنی
           صفحه → زیرسیستم → فالبک دسته */
        $specs = trim((string)($c['specs'] ?? ''));
        if ($specs === '' && !empty($c['_specs_web'])) {
            /* داده‌ی واقعیِ استخراج‌شده از متنِ همان کد در صفحه‌ی خارجی/فارسی */
            $specs = trim((string)$c['_specs_web']);
        }
        if ($specs === '' && !empty($c['_specs_ctx'])) {
            $specs = $this->specsFromContext((string)$c['_specs_ctx']);
        }
        if ($specs === '') {
            /* ✅ v2.33: مشخصاتِ واقعیِ زیرسیستم (مثل «مقاومت کویل پمپ: ۱۵ تا ۲۰ Ω»)
               به‌جای متنِ عمومیِ دسته (مثل «مقاومت کویل: ۱۵-۲۰ اهم · ۲۲۰V AC») */
            $specs = ErrorCodeSubsystem::specs($subData);
        }
        if ($specs === '') {
            $specs = $this->fallbackSpecs($c['category'] ?? '');
        }

        /* 📍 محل قطعه: KB → 🆕 استخراج از پنجره‌ی خود کد (وب) → متن غنی → زیرسیستم */
        $location = trim((string)($c['location'] ?? ''));
        if ($location === '' && !empty($c['_location_web'])) {
            $location = trim((string)$c['_location_web']);
        }
        if ($location === '' && !empty($c['_location'])) {
            $location = trim((string)$c['_location']);
        }
        if ($location === '') {
            $location = ErrorCodeSubsystem::location($subData);
        }
        if ($location === '') {
            $location = $this->fallbackLocation($c['category'] ?? '');
        }

        /* 🎚 شدت: داده → زیرسیستم → متن وب (دیگر پیش‌فرضِ کورکورانه medium نیست) */
        if (empty($c['severity']) || ($c['severity'] ?? '') === 'medium') {
            $subSev = ErrorCodeSubsystem::severity($subData);
            if ($subSev !== 'medium') {
                $severity = $subSev;
            }
        }

        /* 🏷 دسته‌بندی: داده → زیرسیستم (دیگر «سایر» کورکورانه نیست) */
        $category = trim((string)($c['category'] ?? ''));
        if ($category === '' || $category === 'سایر') {
            $subCat = ErrorCodeSubsystem::category($subData);
            if ($subCat !== '') {
                $category = $subCat;
            }
        }

        /* 🔧 قطعه: داده → زیرسیستم (با تطبیق روی عنوان) */
        $part = trim((string)($c['part'] ?? ''));
        if ($part === '' || $part === 'قطعه مرتبط با کد') {
            $subPart = ErrorCodeSubsystem::part($subData, $title);
            if ($subPart !== '') {
                $part = $subPart;
            }
        }

        /* توضیح کامل ۳-۵ جمله‌ای — مبتنی بر واقعیت‌های همین کد (v2.33) */
        $description = $this->composeDescription($brandName, $deviceFa, [
            'code' => (string)($c['code'] ?? ''),
            'part' => $part,
        ], $causes, $severity, $seed, $subData);

        /* 📋 مدل‌های واقعی: داده → 🆕 مدل‌های یافت‌شده در متن وب → پایگاه دانش برند */
        $models = !empty($c['models']) ? array_slice((array)$c['models'], 0, 8) : [];
        if (empty($models) && !empty($c['_models_web'])) {
            $models = array_values(array_slice((array)$c['_models_web'], 0, 8));
        }
        if (empty($models)) {
            $models = $this->brandDeviceModels((int)$brand['id'], $brandName, $deviceKey);
        }

        /* 🚩 نیاز به بازبینی دستی: زیرسیستم با قطعیت پایین تشخیص داده شده
           يا اصلاً تشخیص داده نشده (بدون پرچم، رکوردِ مشکوک بی‌سروصدا منتشر می‌شود) */
        $needsReview = in_array((string)($sub['confidence'] ?? 'none'), ['low', 'none'], true) ? 1 : 0;

        $rec = [
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
            'models'          => json_encode($models, JSON_UNESCAPED_UNICODE),
            'category'        => $this->normalizeCategoryList($category),
            'related_part'    => $this->partWithEn($part),
            'tech_specs'      => $specs,
            'part_location'   => $location,
            'source'          => $source,
            'source_urls'     => json_encode(array_slice((array)($c['source_urls'] ?? []), 0, 6), JSON_UNESCAPED_UNICODE),
            'is_active'       => 1,
        ];

        /* 🆕 v2.33 — زیرسیستم، پرچم بازبینی و هش یکتایی محتوا */
        if ($subKey !== null) {
            $rec['subsystem'] = $subKey;
        }
        $rec['needs_review'] = $needsReview;
        if (function_exists('error_code_content_hash')) {
            $rec['content_hash'] = error_code_content_hash([
                'title'       => $rec['title'],
                'description' => $rec['description'],
                'causes'      => $causes,
                'solutions'   => $solutions,
            ]);
        }
        return $rec;
    }

    /**
     * ⚡ آیا زیرسیستم ماهیت الکترونیکی/نرم‌افزاری دارد؟
     * (فقط در این صورت افزودنِ «ریست با قطع برق» به فهرست علت‌ها منطقی است)
     */
    private function isElectronicSubsystem(?string $subKey): bool
    {
        if ($subKey === null) {
            return false;
        }
        $electronic = [
            'control_board', 'fridge_board', 'dw_board', 'ac_board', 'ac_comm',
            'mw_board', 'oven_board', 'tv_main', 'tv_power', 'tv_tcon',
            'tv_backlight', 'tv_network', 'child_lock', 'hood_light',
        ];
        return in_array($subKey, $electronic, true);
    }

    /**
     * 📋 مدل‌های واقعی یک برند+دستگاه از پایگاه دانش تخصصی (v2.33)
     *
     * 🚨 ریشه‌ی مشکل قبلی: fallbackModels() رشته‌ی جایگزین
     * «مدل‌های هم‌خانواده — با جستجوی آنلاین تکمیل شود» برمی‌گرداند؛
     * یعنی فیلد مدل‌ها عملاً هیچ‌وقت داده‌ی واقعی نداشت.
     * پایگاه دانش برای هر برند+دستگاه ۳ تا ۷ مدل واقعی دارد — همان‌ها استفاده می‌شوند.
     *
     * @return array فهرست مدل‌ها (آرایه‌ی خالی اگر موجود نباشد)
     */
    private function brandDeviceModels(int $brandId, string $brandFa, string $deviceKey): array
    {
        static $cache = [];
        $ck = $brandFa . '|' . $deviceKey;
        if (isset($cache[$ck])) {
            return $cache[$ck];
        }
        $out = [];
        try {
            $kb  = TextProcessor::loadKnowledge('error-codes-brands');
            $key = $this->matchBrandKey($brandFa, '');
            if ($key !== null) {
                $dev = $kb['brands'][$key]['devices'][$deviceKey] ?? null;
                if (is_array($dev)) {
                    $out = array_values(array_filter(array_map('trim', (array)($dev['models'] ?? []))));
                }
            }
        } catch (Throwable $e) {
            $out = [];
        }
        $out = array_slice($out, 0, 8);
        $cache[$ck] = $out;
        return $out;
    }

    /**
     * ✍️ توضیح کامل ۳-۵ جمله — مبتنی بر واقعیتِ همین کد (v2.33)
     * ==========================================================
     *
     * 🚨 ریشه‌ی مشکل قبلی: این تابع یک چرخ‌دنده‌ی ۳×۲×۵×۳ = ۹۰ حالته بود؛
     * یعنی برای ۱۲ کدِ یک دستگاه، ۱۲ متن با ساختارِ جمله‌بندیِ یکسان تولید
     * می‌شد و فقط نام قطعه و کد عوض می‌گشت. گوگل چنین محتوایی را
     * «templated / thin» می‌بیند.
     *
     * ✅ رویکرد جدید: متن از **واقعیت‌های استخراج‌شده‌ی همان کد** ساخته می‌شود
     * (سازوکار خرابیِ زیرسیستم، پیامد، قطعه، شایع‌ترین علت، سطح شدت،
     * خوداصلاحی). ساختارِ جمله‌ها با بذر تغییر می‌کند تا دو کدِ هم‌خانواده
     * هم متنِ یکسان نداشته باشند.
     *
     * پوشش ۵ مؤلفه‌ی الزامی:
     *   ۱) ماهیت خطا و چرا رخ می‌دهد      ۲) چه اتفاقی در سیستم داخلی افتاده
     *   ۳) تأثیر بر عملکرد کلی            ۴) قابل استفاده بودن یا نبودن
     *   ۵) خودکار رفع می‌شود یا نه
     */
    private function composeDescription(string $brand, string $device, array $c, array $causes, string $severity, int $seed, array $subData = []): string
    {
        $code = (string)($c['code'] ?? '');
        $part = trim((string)($c['part'] ?? ''));
        $part = ($part !== '' && $part !== 'قطعه مرتبط با کد') ? $part : 'بخش مرتبط';
        /* نام فارسی قطعه (بخش قبل از | در «نام | English») */
        $partFa = trim(explode('|', $part)[0]) ?: $part;

        $mechanism = trim((string)($subData['mechanism_fa'] ?? ''));
        $impact    = trim((string)($subData['impact_fa'] ?? ''));
        $selfHeal  = (bool)($subData['self_healing'] ?? false);
        $mainCause = (string)($causes[0] ?? '');
        $secondCause = (string)($causes[1] ?? '');

        /* ---------- جمله‌ی ۱: ماهیت خطا ---------- */
        $natureVariants = [
            "کد {$code} در {$device} {$brand} به معنای بروز اختلال در {$partFa} است.",
            "نمایش کد {$code} روی {$device} {$brand} یعنی دستگاه در {$partFa} خطا تشخیص داده است.",
            "{$device} {$brand} با کد {$code} اعلام می‌کند که {$partFa} خارج از محدوده‌ی عادی کار می‌کند.",
        ];
        $nature = $natureVariants[$seed % 3];

        /* ---------- جمله‌ی ۲: اتفاق داخلی (سازوکار واقعی زیرسیستم) ---------- */
        if ($mechanism !== '') {
            $internal = $mechanism;
        } elseif ($mainCause !== '') {
            $internal = "در عمل، {$mainCause} باعث شده برد کنترل ادامه کار را ایمن تشخیص ندهد و سیکل را متوقف کند.";
        } else {
            $internal = "دستگاه برای محافظت از قطعات دیگر، ادامه کار را متوقف کرده است.";
        }

        /* ---------- جمله‌ی ۳: پیامد ---------- */
        if ($impact !== '') {
            $effect = $impact;
        } else {
            $effect = "در نتیجه، {$device} نمی‌تواند برنامه‌ی انتخابی را کامل اجرا کند.";
        }

        /* ---------- جمله‌ی ۴: قابل استفاده بودن + شدت ---------- */
        $usability = [
            'critical' => "با توجه به سطح «بحرانی»، ادامه استفاده مطلقاً توصیه نمی‌شود و دستگاه باید تا رفع عیب خاموش بماند.",
            'high'     => "شدت این خطا «زیاد» است؛ دستگاه در این وضعیت قابل استفاده نیست و باید پیش از ادامه کار عیب برطرف شود.",
            'medium'   => "شدت این خطا «متوسط» است؛ دستگاه ممکن است با محدودیت کار کند، اما رفع به‌موقع از آسیب‌های بعدی جلوگیری می‌کند.",
            'low'      => "شدت این خطا «کم» است و اثر فوری بر عملکرد ندارد، با این حال بهتر است در اولین فرصت بررسی شود.",
            'informational' => "این مورد در واقع خطا نیست بلکه یک پیام «اطلاعاتی» است و دستگاه بدون محدودیت به کار ادامه می‌دهد.",
        ];
        $usage = $usability[$severity] ?? $usability['medium'];

        /* ---------- جمله‌ی ۵: خوداصلاحی + سرنخ بعدی ---------- */
        $selfHealVariants = [
            true  => [
                "در برخی موارد با رفع علت و یک‌بار قطع و وصل برق، کد به‌طور خودکار از نمایشگر پاک می‌شود.",
                "این خطا معمولاً پس از برطرف شدن شرایطِ ایجادکننده، خودبه‌خود رفع می‌شود و نیازی به تعویض قطعه نیست.",
            ],
            false => [
                "این خطا به‌طور خودکار رفع نمی‌شود و تا زمان تعمیر یا تعویض قطعه‌ی معیوب، با هر بار راه‌اندازی دوباره ظاهر می‌شود.",
                "ریست کردن دستگاه فقط کد را موقتاً پاک می‌کند؛ تا وقتی علت اصلی برطرف نشود، خطا بازمی‌گردد.",
            ],
        ];
        $healPool = $selfHealVariants[$selfHeal ? 1 : 0];
        $heal     = $healPool[($seed >> 2) % 2];

        /* ---------- سرنخِ علتِ شایع (ادغام در متن، نه تکرارِ فهرست) ---------- */
        $clue = '';
        if ($mainCause !== '') {
            $clueVariants = [
                "شایع‌ترین علت در {$device} {$brand} برای این کد، {$mainCause} است" .
                    ($secondCause !== '' ? " و در مرتبه‌ی بعد {$secondCause}." : '.'),
                "تجربه‌ی تعمیرات نشان می‌دهد {$mainCause} بیشترین سهم را در بروز این کد دارد.",
            ];
            $clue = $clueVariants[($seed >> 3) % 2];
        }

        /* ---------- چینش نهایی با تنوعِ ساختاری ---------- */
        $orders = [
            function () use ($nature, $internal, $clue, $effect, $usage, $heal) {
                return trim($nature . ' ' . $internal . ($clue !== '' ? ' ' . $clue : '') . ' ' . $effect . ' ' . $usage . ' ' . $heal);
            },
            function () use ($nature, $clue, $internal, $effect, $usage, $heal) {
                return trim($nature . ($clue !== '' ? ' ' . $clue : '') . ' ' . $internal . ' ' . $effect . ' ' . $heal . ' ' . $usage);
            },
            function () use ($internal, $nature, $effect, $clue, $usage, $heal) {
                return trim($internal . ' ' . $nature . ' ' . $effect . ($clue !== '' ? ' ' . $clue : '') . ' ' . $usage . ' ' . $heal);
            },
            function () use ($nature, $effect, $internal, $usage, $heal, $clue) {
                return trim($nature . ' ' . $effect . ' ' . $internal . ' ' . $usage . ($clue !== '' ? ' ' . $clue : '') . ' ' . $heal);
            },
        ];
        $text = $orders[($seed >> 4) % 4]();

        /* ایمنی: اگر به هر دلیلی متن خیلی کوتاه شد، با قطعه و کد تکمیل شود */
        if (mb_strlen($text) < 120) {
            $text = trim($nature . ' ' . $internal . ' ' . $effect . ' ' . $usage . ' ' . $heal);
        }
        return $text;
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
            'television'      => ['خرابی بک‌لایت LED', 'اشکال برد T-Con', 'خرابی پاور بورد', 'خرابی مین‌برد', 'نوسان برق ورودی', 'قطعی یا بی‌ثباتی اتصال شبکه و وای‌فای', 'نیاز به بروزرسانی نرم‌افزار webOS', 'ضعف سیگنال آنتن یا خرابی تیونر', 'محدودیت جغرافیایی سرویس محتوا'],
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
            'television'     => ['قطع برق تلویزیون از پریز و اتصال مجدد بعد از ۵ دقیقه (ریست کامل)', 'بررسی سلامت اتصال کابل HDMI و پورت‌ها', 'بررسی اتصال WiFi و ریست مودم/روتر', 'بروزرسانی نرم‌افزار از منوی تنظیمات', 'بررسی اتصال آنتن و جستجوی مجدد کانال‌ها'],
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
            'television'     => ['تست ولتاژهای ریل پاور بورد (۵V/۱۲V/۲۴V)', 'تست LEDهای بک‌لایت و برد T-Con با تجهیزات', 'بروزرسانی/ریفلاش فیرویر webOS به روش USB', 'تست و تعویض ماژول وای‌فای', 'تعمیر مین‌برد (قطعات مربوطه)'],
        ];
        return $map[$deviceKey] ?? ['تست قطعه مرتبط با مولتی‌متر و تعویض', 'عیب‌یابی برد کنترل و تعمیر تخصصی', 'بررسی سیم‌کشی و سوکت‌های داخلی'];
    }

    /* ==================================================
     * 🔎 استنتاج زمینه از متن وب
     * ================================================== */

    private function titleFromContext(string $code, string $text, string $deviceFa): string
    {
        $patterns = [
            /* 🆕 v3.14: تلویزیون و مایکروویو — پوشش جدول‌های سازنده این دستگاه‌ها */
            '/(backlight|بک‌لایت)/iu' => 'خطای بک‌لایت LED',
            '/(t-?con|تی‌کان)/iu' => 'خطای برد T-Con',
            '/(network|wi-?fi|internet|router|شبکه|اینترنت|اتصال به)/iu' => 'خطای اتصال شبکه و اینترنت',
            '/\b(app|apps|webos|اپلیکیشن)\b/iu' => 'خطای اپلیکیشن و نرم‌افزار',
            '/(signal|antenna|tuner|آنتن|سیگنال)/iu' => 'خطای سیگنال آنتن',
            '/(magnetron|مگنترون)/iu' => 'خطای مگنترون',
            '/(thermal\s?(fuse|cut)|فیوز حرارتی)/iu' => 'خطای فیوز حرارتی',
            '/(keypad|key\s?pad|touch\s?pad|کیپد|صفحه‌کلید)/iu' => 'خطای کیپد و صفحه‌کلید',
            '/(high\s?voltage|hv\s?diode|دیود)/iu' => 'خطای دیود ولتاژ بالا',
            '/(main\s?board|مین‌برد)/iu' => 'خطای مین‌برد',
            '/(power\s?(board|supply)|پاور\s?بورد)/iu' => 'خطای پاور بورد',
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
        return 'خطای ' . $code . ($deviceFa !== '' ? ' در ' . $deviceFa : '');
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
            /* 🆕 v3.14: قطعات تلویزیون و مایکروویو — پیش از قوانین عمومی */
            '/بک‌لایت|backlight/iu' => 'بک‌لایت LED و درایور',
            '/T-?Con|تی‌کان/iu' => 'برد T-Con',
            '/مین‌برد|main\s?board/iu' => 'مین‌برد (Main Board)',
            '/پاور\s?بورد|power\s?(board|supply)/iu' => 'پاور بورد (Power Board)',
            '/وای‌فای|wi-?fi|ماژول شبکه|network module/iu' => 'ماژول وای‌فای/شبکه',
            '/اپلیکیشن|نرم‌افزار|webos|firmware/iu' => 'نرم‌افزار webOS و حافظه',
            '/آنتن|تیونر|signal|tuner/iu' => 'تیونر و برد سیگنال',
            '/مگنترون|magnetron/iu' => 'مگنترون',
            '/فیوز حرارتی|thermal/iu' => 'فیوز حرارتی',
            '/دیود ولتاژ|hv\s?diode/iu' => 'دیود ولتاژ بالا (HV)',
            '/خازن|capacitor/iu' => 'خازن ولتاژ بالا',
            '/کیپد|کیبورد|keypad|touch\s?pad/iu' => 'کیپد (صفحه‌کلید)',
            '/گردان|ترن‌تیبل|turntable/iu' => 'موتور گردان (ترن‌تیبل)',
            '/پنل نمایشگر|display\s?panel/iu' => 'پنل نمایشگر',
            '/پورت|hdmi|port/iu' => 'پورت HDMI',
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
            /* 🆕 v3.14: تلویزیون و مایکروویو — پیش از قوانین عمومی */
            '/بک‌لایت|backlight/iu' => 'بک‌لایت LED و درایور',
            '/T-?Con|تی‌کان/iu' => 'برد T-Con',
            '/مین‌برد|main\s?board/iu' => 'مین‌برد (Main Board)',
            '/پاور\s?بورد|power\s?(board|supply)/iu' => 'پاور بورد (Power Board)',
            '/وای‌فای|wi-?fi/iu' => 'ماژول وای‌فای',
            '/مگنترون|magnetron/iu' => 'مگنترون',
            '/دیود ولتاژ|hv\s?diode/iu' => 'دیود ولتاژ بالا (HV)',
            '/خازن|capacitor/iu' => 'خازن ولتاژ بالا',
            '/فیوز حرارتی|thermal\s?fuse/iu' => 'فیوز حرارتی',
            '/کیپد|keypad|touch\s?pad/iu' => 'کیپد (صفحه‌کلید)',
            '/آنتن|tuner|antenna/iu' => 'تیونر و برد سیگنال',
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
        /* 🆕 v3.14: کلیدواژه‌های بیشتر — تلویزیون/مایکروویو و سطوح low */
        if (preg_match('/critical|fatal|بحرانی|خطرناک|فوری|fire|آتش|دود|smoke|shock|برق‌گرفتگی|burn/iu', $text)) { return 'critical'; }
        if (preg_match('/warning|هشدار|مهم|serious|severe|سنگین|failure|توقف کامل|does not (?:turn|start|work)|کار نمی‌کند/iu', $text)) { return 'high'; }
        if (preg_match('/minor|جزئی|کم‌اهمیت|informational|اطلاعاتی|notice|tip|نکته/iu', $text)) { return 'low'; }
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
            /* 🆕 v3.14: قطعات تلویزیون و مایکروویو */
            'بک‌لایت LED و درایور' => 'بک‌لایت LED | LED Backlight',
            'برد T-Con' => 'برد T-Con | Timing Control Board',
            'مین‌برد (Main Board)' => 'مین‌برد | Main Board',
            'پاور بورد (Power Board)' => 'پاور بورد | Power Supply Board',
            'ماژول وای‌فای/شبکه' => 'ماژول شبکه | WiFi Module',
            'نرم‌افزار webOS و حافظه' => 'نرم‌افزار | webOS Firmware',
            'تیونر و برد سیگنال' => 'تیونر | Tuner Board',
            'مگنترون' => 'مگنترون | Magnetron',
            'دیود ولتاژ بالا (HV)' => 'دیود HV | High-Voltage Diode',
            'خازن ولتاژ بالا' => 'خازن HV | HV Capacitor',
            'فیوز حرارتی' => 'فیوز حرارتی | Thermal Fuse',
            'کیپد (صفحه‌کلید)' => 'کیپد | Keypad',
            'موتور گردان (ترن‌تیبل)' => 'موتور گردان | Turntable Motor',
            'پنل نمایشگر' => 'پنل | Display Panel',
            'پورت HDMI' => 'پورت HDMI | HDMI Port',
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

    /**
     * 🏷 نرمال‌سازی دسته‌بندیِ چندمقداری (v2.33)
     *
     * زیرسیستم‌ها ممکن است چند دسته داشته باشند (مثل «پمپ، سیستم آبرسانی»).
     * normalizeCategory فقط یک مورد برمی‌گرداند و باعث از دست رفتن بقیه می‌شد؛
     * این تابع هر بخش را جداگانه نرمال می‌کند و موارد یکتا را با «،» برمی‌گرداند.
     */
    private function normalizeCategoryList(string $cat): string
    {
        $cat = trim($cat);
        if ($cat === '') {
            return 'سایر';
        }
        $parts = preg_split('/[،,]+/u', $cat, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($parts) <= 1) {
            return $this->normalizeCategory($cat);
        }
        $out = [];
        foreach ($parts as $p) {
            $n = $this->normalizeCategory(trim($p));
            if ($n !== '' && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }
        if (empty($out)) {
            return 'سایر';
        }
        /* «سایر» فقط وقتی نگه داشته شود که تنها مورد باشد */
        if (count($out) > 1) {
            $out = array_values(array_filter($out, fn($x) => $x !== 'سایر'));
        }
        return implode('، ', $out) ?: 'سایر';
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
