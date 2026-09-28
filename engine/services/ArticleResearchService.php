<?php
/**
 * 🔎 سرویس تحقیق عمیقِ مقاله — ArticleResearchService v1.0
 * ======================================================
 * لایه‌ی اینترنتیِ اختصاصیِ «نویسنده‌ی مقاله» (در کنارِ ArticleGenerator).
 *
 * چرا جدا از WebSearchService::research()؟
 *   research() عمومی است: ۲ کوئری، فقط اسنیپت، بدون خواندن صفحه، بدون
 *   استخراجِ اسکلتِ رقبا و بدون امتیازِ اعتماد. این سرویس دقیقاً همان
 *   کاری را برای مقاله می‌کند که ErrorCodeEngine برای کدهای خطا می‌کند:
 *
 *   ۱) برنامه‌ی کوئریِ چندمرحله‌ای (فارسی + انگلیسی، موضوعی + پرسشی)
 *   ۲) جستجوی عریض با ۱ تا ۲ موتور (searchWide) + زبان/منطقه‌ی درست
 *   ۳) خواندنِ متنِ کاملِ ۲ تا ۷ صفحه با بودجه‌ی زمانیِ مشترک
 *   ۴) استخراجِ «اسکلتِ رقبا» (تیترهایی که SERP واقعاً انتظار دارد)
 *   ۵) استخراجِ پرسش‌های واقعیِ کاربران (PAA / تیترهای سؤالی)
 *   ۶) استخراجِ عدد و آمار **با منبع** (عدد + واحد + دامنه‌ی منبع)
 *   ۷) استخراجِ فکت‌های قابل‌استفاده در نثر مقاله
 *   ۸) استخراجِ موجودیت‌ها و ترکیب‌های پربسامد برای پوششِ معنایی
 *   ۹) امتیازِ اعتمادِ منبع + حذفِ دامنه‌های بی‌فایده (فروشگاه/ویدیو/شبکه)
 *  ۱۰) یادگیری: ذخیره‌ی فکت‌های تأییدشده برای بازمصرف در مقالاتِ بعد
 *
 * هیچ وابستگی به مدل زبانی خارجی ندارد؛ اگر اینترنت در دسترس نباشد،
 * خروجی با ok=false برمی‌گردد و ژنراتور با دانش داخلی ادامه می‌دهد.
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class ArticleResearchService
{
    /** @var string نسخه */
    const VERSION = '1.0';

    /** @var array پروفایل‌های عمقِ تحقیق */
    private const DEPTHS = [
        'fast' => [
            'label'         => 'سریع',
            'queries_fa'    => 4,
            'queries_en'    => 0,
            'engines'       => 1,
            'list_limit'    => 6,
            'pages'         => 2,
            'page_chars'    => 9000,
            'budget'        => 40.0,
            'max_outline'   => 4,
            'max_questions' => 4,
            'max_stats'     => 4,
            'max_facts'     => 8,
            'max_entities'  => 8,
            'max_sources'   => 5,
        ],
        'balanced' => [
            'label'         => 'متعادل',
            'queries_fa'    => 7,
            'queries_en'    => 3,
            'engines'       => 1,
            'list_limit'    => 8,
            'pages'         => 4,
            'page_chars'    => 14000,
            'budget'        => 95.0,
            'max_outline'   => 6,
            'max_questions' => 6,
            'max_stats'     => 8,
            'max_facts'     => 14,
            'max_entities'  => 12,
            'max_sources'   => 7,
        ],
        'deep' => [
            'label'         => 'عمیق',
            'queries_fa'    => 10,
            'queries_en'    => 6,
            'engines'       => 2,
            'list_limit'    => 10,
            'pages'         => 7,
            'page_chars'    => 20000,
            'budget'        => 220.0,
            'max_outline'   => 8,
            'max_questions' => 8,
            'max_stats'     => 12,
            'max_facts'     => 24,
            'max_entities'  => 16,
            'max_sources'   => 9,
        ],
    ];

    /** @var WebSearchService موتور جستجو */
    private $search;

    /** @var array دانش منابعِ مقاله (article-web-sources.json) */
    private $sources;

    /** @var array خطاهای جمع‌آوری‌شده */
    private $errors = [];

    /** @var bool آیا سقفِ نرخ پر شده؟ */
    private $rateLimited = false;

    /** @var string فایلِ دانشِ یادگرفته‌شده از وب */
    private const LEARNED_FILE = '/knowledge/web-facts.json';

    public function __construct()
    {
        $this->search = new WebSearchService();
    }

    /**
     * 🎚️ گزینه‌های عمق برای انتخابگرِ پنل
     *
     * @return array ['fast' => 'سریع (۴۰ ثانیه)', ...]
     */
    public static function depthOptions(): array
    {
        return [
            'fast'     => 'سریع (≈۴۰ ثانیه)',
            'balanced' => 'متعادل (≈۹۰ ثانیه)',
            'deep'     => 'عمیق (≈۲ تا ۳ دقیقه)',
        ];
    }

    /**
     * 🎚️ پروفایلِ یک عمق (با fallback امن)
     */
    public static function depthProfile(string $depth): array
    {
        return self::DEPTHS[$depth] ?? self::DEPTHS['balanced'];
    }

    /**
     * 🔎 تحقیق کامل درباره‌ی موضوع مقاله
     *
     * @param string $subject موضوع/عنوان مقاله
     * @param array  $ctx     ['brand_fa','brand_en','device_fa','device_en','topic_type']
     * @param array  $opts    ['depth','budget','brand_id','device_key','learn'=>bool,'no_cache'=>bool]
     * @return array خروجی ساختاریافته (همیشه یک آرایه است، هرگز استثنا پرتاب نمی‌کند)
     */
    public function research(string $subject, array $ctx = [], array $opts = []): array
    {
        $t0 = microtime(true);
        $depth = (string)($opts['depth'] ?? 'balanced');
        $profile = self::depthProfile($depth);

        /* ⏱️ بودجه‌ی زمانی: کمتر از مقدارِ تنظیماتِ کاربر و کمتر از تایم‌اوتِ PHP */
        $budget = (float)($opts['budget'] ?? $profile['budget']);
        $budget = max(15.0, min($budget, $profile['budget']));
        $deadline = microtime(true) + $budget;
        if (function_exists('set_time_limit')) {
            @set_time_limit((int)max(120, $budget + 90));
        }

        $subject = $this->cleanText($subject);
        $brandFa = trim((string)($ctx['brand_fa'] ?? ''));
        $brandEn = trim((string)($ctx['brand_en'] ?? ''));
        $deviceFa = trim((string)($ctx['device_fa'] ?? ''));
        $deviceEn = trim((string)($ctx['device_en'] ?? ''));

        $out = [
            'ok'          => false,
            'depth'       => $depth,
            'subject'     => $subject,
            'device_fa'   => $deviceFa,
            'brand_fa'    => $brandFa,
            'queries'     => [],
            'engines'     => '',
            'results'     => 0,
            'pages_read'  => 0,
            'outline'     => [],
            'questions'   => [],
            'stats'       => [],
            'facts'       => [],
            'entities'    => [],
            'sources'     => [],
            'source_stats'=> ['total' => 0, 'fa' => 0, 'en' => 0, 'official' => 0, 'manual' => 0, 'forum' => 0, 'avg_trust' => 0],
            'learned'     => 0,
            'rate_limited'=> false,
            'errors'      => [],
            'took_ms'     => 0,
        ];

        if (mb_strlen($subject) < 5) {
            $out['errors'][] = 'موضوعِ تحقیق بسیار کوتاه است.';
            return $out;
        }

        /* ---------- ۰) دانشِ یادگرفته‌شده‌ی قبلی (P2) ---------- */
        $learned = $this->loadLearned((int)($opts['brand_id'] ?? 0), (string)($opts['device_key'] ?? ''), $subject);

        /* ---------- ۱) برنامه‌ی کوئری ---------- */
        $queries = $this->buildQueries($subject, $ctx, $profile);
        $out['queries'] = array_column($queries, 'q');

        /* ---------- ۲) اجرای جستجوها ---------- */
        $results = $this->runQueries($queries, $profile, $deadline);
        $out['engines'] = (string)($results['engines'] ?? '');

        /* ---------- ۳) پالایش + امتیازِ منبع ---------- */
        $clean = $this->filterResults($results['rows'] ?? [], $brandEn !== '' ? $brandEn : $brandFa);
        $out['results'] = count($clean);

        /* ---------- ۴) خواندن صفحاتِ برتر ---------- */
        $urls = [];
        foreach ($clean as $r) {
            $urls[] = (string)($r['url'] ?? '');
        }
        $pages = [];
        if ($profile['pages'] > 0 && !empty($urls)) {
            try {
                $pages = $this->search->fetchPages($urls, $profile['page_chars'], $profile['pages'], $deadline);
            } catch (Throwable $e) {
                $this->errors[] = 'fetchPages: ' . $e->getMessage();
            }
        }
        $out['pages_read'] = count($pages);

        /* ---------- ۵) استخراج ---------- */
        $out['outline']   = $this->extractOutline($clean, $pages, $ctx, $profile['max_outline']);
        $out['questions'] = $this->extractQuestions($clean, $pages, $profile['max_questions']);
        $out['stats']     = $this->extractStats($clean, $pages, $profile['max_stats']);
        $out['facts']     = $this->extractFacts($clean, $pages, $profile['max_facts']);
        $out['entities']  = $this->extractEntities($clean, $pages, $profile['max_entities']);
        $out['sources']   = $this->collectSources($clean, $profile['max_sources']);
        $out['source_stats'] = $this->sourceStats($out['sources']);

        /* ---------- ۶) ادغامِ دانشِ قبلی (وقتی وب کم‌بار است) ---------- */
        if (!empty($learned)) {
            $have = [];
            foreach ($out['facts'] as $f) {
                $have[$this->fingerprint((string)($f['text'] ?? ''))] = true;
            }
            foreach ($learned as $lf) {
                $fp = $this->fingerprint((string)($lf['text'] ?? ''));
                if (!isset($have[$fp])) {
                    $lf['learned'] = true;
                    $out['facts'][] = $lf;
                    $have[$fp] = true;
                }
            }
            $out['facts'] = array_slice($out['facts'], 0, max($profile['max_facts'], 18));
            $out['learned'] = count($learned);
        }

        /* ---------- ۷) یادگیری (ذخیره برای مقالاتِ بعد) ---------- */
        if (!empty($opts['learn']) && !empty($out['facts'])) {
            $out['learned_saved'] = $this->saveLearned(
                (int)($opts['brand_id'] ?? 0),
                (string)($opts['device_key'] ?? ''),
                $subject,
                array_slice($out['stats'], 0, 6)
            );
        }

        $out['ok'] = !empty($out['facts']) || !empty($out['outline']) || !empty($out['questions']) || !empty($out['stats']);
        $out['rate_limited'] = $this->rateLimited;
        $out['errors'] = array_slice(array_merge($this->errors, $results['errors'] ?? []), 0, 8);
        $out['took_ms'] = (int)round((microtime(true) - $t0) * 1000);
        return $out;
    }

    /* ==================================================
     * ۱) برنامه‌ی کوئری
     * ================================================== */

    /**
     * 📋 ساخت فهرست کوئری‌ها (فارسی + انگلیسی)
     *
     * @return array [['q'=>..,'lang'=>'fa'|'en','kind'=>'discovery'|'question'], ...]
     */
    private function buildQueries(string $subject, array $ctx, array $profile): array
    {
        $src = $this->webSources();
        $brandFa = trim((string)($ctx['brand_fa'] ?? ''));
        $brandEn = trim((string)($ctx['brand_en'] ?? ''));
        $deviceFa = trim((string)($ctx['device_fa'] ?? ''));
        $deviceEn = trim((string)($ctx['device_en'] ?? ''));
        $subjectEn = $this->subjectToEnglish($subject, $ctx);
        $core = $this->coreSubject($subject);

        $fill = function (string $tpl, string $sub, string $subEn) use ($brandFa, $brandEn, $deviceFa, $deviceEn): string {
            $out = strtr($tpl, [
                '{subject}'    => $sub,
                '{subject_en}' => $subEn,
                '{device_fa}'  => $deviceFa,
                '{device_en}'  => $deviceEn,
                '{brand_fa}'   => $brandFa,
                '{brand_en}'   => $brandEn,
            ]);
            $out = trim(preg_replace('/\s+/u', ' ', $out) ?? $out);
            return $out;
        };

        $queries = [];
        $seen = [];
        $push = function (string $q, string $lang, string $kind) use (&$queries, &$seen): void {
            $q = trim(preg_replace('/\s+/u', ' ', $q) ?? $q);
            if (mb_strlen($q) < 5) {
                return;
            }
            /* 🚫 خروجیِ بی‌معنا مثل «چرا چرا یخچال…» یا «علت علت …» را حذف کن */
            if (preg_match('/(?:^|\s)(\p{L}{3,})\s+\1(?:\s|$)/u', $q)) {
                return;
            }
            $key = mb_strtolower($q);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $queries[] = ['q' => $q, 'lang' => $lang, 'kind' => $kind];
        };

        /* 🚫 اگر نام برند در خودِ موضوع هست، قالب‌هایی که برند را می‌چسبانند
           بی‌استفاده‌اند («یخچال سامسونگ … سامسونگ») → حذف می‌شوند */
        $brandInSubject = ($brandFa !== '' && mb_strpos($this->softNormalize($core), $this->softNormalize($brandFa)) !== false);
        $skipBrandTpl = function (string $tpl) use ($brandInSubject): bool {
            return $brandInSubject && mb_strpos($tpl, '{brand_fa}') !== false;
        };

        /* 🇮🇷 فارسی — موضوعی
         * اگر خودِ موضوع با یک «عمل» شروع شود (تعمیر، نگهداری، نصب، سرویس …)
         * از گروهِ قالب‌های fa_action استفاده می‌شود؛ وگرنه خروجی‌هایی مثل
         * «علت تعمیر برد ماشین لباسشویی» ساخته می‌شود که بی‌معناست. */
        $isAction = $this->isActionSubject($core, $src);
        $faKey = $isAction ? 'fa_action' : 'fa';
        $faTpl = (array)($src['discovery_queries'][$faKey] ?? []);
        if (empty($faTpl)) {
            $faTpl = (array)($src['discovery_queries']['fa'] ?? []);
        }
        $i = 0;
        foreach ($faTpl as $tpl) {
            if ($i >= $profile['queries_fa']) {
                break;
            }
            if ($skipBrandTpl((string)$tpl)) {
                continue;
            }
            $push($fill((string)$tpl, $core, $subjectEn), 'fa', 'discovery');
            $i++;
        }

        /* 🇮🇷 فارسی — پرسشی (اگر هنوز جا هست) */
        $faQ = (array)($src['question_queries'][$isAction ? 'fa_action' : 'fa'] ?? []);
        if (empty($faQ)) {
            $faQ = (array)($src['question_queries']['fa'] ?? []);
        }
        $qCount = max(2, (int)round($profile['queries_fa'] * 0.4));
        $i = 0;
        foreach ($faQ as $tpl) {
            if ($i >= $qCount) {
                break;
            }
            $push($fill((string)$tpl, $core, $subjectEn), 'fa', 'question');
            $i++;
        }

        /* 🌍 انگلیسی */
        if ($profile['queries_en'] > 0 && $subjectEn !== '') {
            $enTpl = (array)($src['discovery_queries']['en'] ?? []);
            $i = 0;
            foreach ($enTpl as $tpl) {
                if ($i >= $profile['queries_en']) {
                    break;
                }
                $push($fill((string)$tpl, $core, $subjectEn), 'en', 'discovery');
                $i++;
            }
            $enQ = (array)($src['question_queries']['en'] ?? []);
            if ($profile['queries_en'] > 3) {
                $i = 0;
                foreach ($enQ as $tpl) {
                    if ($i >= 2) {
                        break;
                    }
                    $push($fill((string)$tpl, $core, $subjectEn), 'en', 'question');
                    $i++;
                }
            }
        }

        return $queries;
    }

    /**
     * 🌍 تبدیل موضوعِ فارسی به عبارت انگلیسیِ قابل‌جستجو
     * (بدون مترجم: فقط بر اساس واژه‌نامه‌ی intent_glossary + نام لاتین دستگاه/برند)
     */
    private function subjectToEnglish(string $subject, array $ctx): string
    {
        $s = $this->softNormalize($subject);
        $src = $this->webSources();
        $glossary = (array)($src['intent_glossary'] ?? []);

        $parts = [];
        foreach ($glossary as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fa = $this->softNormalize((string)($row['fa'] ?? ''));
            $en = (string)($row['en'] ?? '');
            if ($fa === '' || $en === '') {
                continue;
            }
            if (mb_strpos($s, $fa) !== false) {
                $parts[$en] = true;
            }
        }

        $deviceEn = trim((string)($ctx['device_en'] ?? ''));
        $brandEn = trim((string)($ctx['brand_en'] ?? ''));

        $en = [];
        if ($brandEn !== '' && preg_match('/^[a-z0-9 .\-]+$/i', $brandEn)) {
            $en[] = $brandEn;
        }
        if ($deviceEn !== '' && preg_match('/^[a-z0-9 .\-]+$/i', $deviceEn)) {
            $en[] = $deviceEn;
        }
        $en = array_merge($en, array_keys($parts));

        if (empty($en)) {
            return '';
        }
        /* حذف تکراری‌ها با حفظ ترتیب */
        $out = [];
        foreach ($en as $w) {
            $k = mb_strtolower($w);
            if (!isset($out[$k])) {
                $out[$k] = $w;
            }
        }
        return implode(' ', array_slice(array_values($out), 0, 6));
    }

    /**
     * ✂️ هسته‌ی موضوع (حذف زیرعنوان و علائم)
     */
    private function coreSubject(string $subject): string
    {
        $s = trim(preg_replace('/\s*(?:؛|—|–|\|)\s*.*$/u', '', $subject) ?? $subject);
        $s = trim(preg_replace('/[؟?!.:؛,،]+/u', ' ', $s) ?? $s);
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
        /* «چرا یخچال سامسونگ سرد نمی‌کند؟» → «یخچال سامسونگ سرد نمی‌کند»
         * بدون این کار، قالب‌های کوئری خروجیِ بی‌معنا می‌سازند:
         * «علت چرا یخچال…» / «چرا چرا یخچال…» */
        $stripped = trim(preg_replace('/^(?:چرا|چطور|چگونه|چیست|چه\s+زمانی|کی|کجا|کدام|آیا|مگر)\s+/u', '', $s) ?? $s);
        if (mb_strlen($stripped) >= 8) {
            $s = $stripped;
        }
        return $s !== '' ? $s : trim($subject);
    }

    /**
     * 🧹 نرمال‌سازیِ «نرم» برای تطبیق: نیم‌فاصله → فاصله، یکسان‌سازیِ ی/ک،
     * فشرده‌سازی فاصله‌ها. چرا لازم است؟ چون «سرد نمی‌کند» را یک کاربر با
     * نیم‌فاصله و کاربر دیگری با فاصله‌ی معمولی می‌نویسد و واژه‌نامه‌ی
     * intent_glossary باید هر دو را تشخیص دهد.
     */
    /**
     * 🔧 آیا موضوع با یک واژه‌ی «عمل» شروع می‌شود؟ (تعمیر/نگهداری/نصب/سرویس …)
     */
    private function isActionSubject(string $core, array $src): bool
    {
        $core = $this->softNormalize($core);
        foreach ((array)($src['action_prefixes'] ?? []) as $prefix) {
            $prefix = $this->softNormalize((string)$prefix);
            if ($prefix === '') {
                continue;
            }
            if (mb_strpos($core, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    private function softNormalize(string $text): string
    {
        $text = TextProcessor::normalize($text);
        $text = str_replace("\u{200C}", ' ', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        return mb_strtolower($text);
    }

    /* ==================================================
     * ۲) اجرای جستجوها
     * ================================================== */

    private function runQueries(array $queries, array $profile, float $deadline): array
    {
        $rows = [];
        $errors = [];
        $engines = [];
        $seenUrl = [];

        foreach ($queries as $qi => $q) {
            if (microtime(true) > $deadline) {
                $errors[] = 'بودجه زمانی در کوئری ' . ($qi + 1) . ' تمام شد';
                break;
            }
            $lang = $q['lang'] === 'en' ? 'en' : 'fa';
            $region = $lang === 'fa' ? 'IR' : 'US';
            try {
                $res = $this->search->search($q['q'], $profile['list_limit'], [
                    'lang'     => $lang,
                    'region'   => $region,
                    'engines'  => $profile['engines'],
                    'deadline' => $deadline,
                ]);
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if ($this->isRateLimitError($msg)) {
                    $this->rateLimited = true;
                }
                $errors[] = mb_substr($q['q'], 0, 40) . ': ' . $msg;
                continue;
            }
            $provider = (string)($res['provider'] ?? '');
            if ($provider !== '' && !in_array($provider, $engines, true)) {
                $engines[] = $provider;
            }
            foreach ((array)($res['results'] ?? []) as $r) {
                if (!is_array($r)) {
                    continue;
                }
                $url = (string)($r['url'] ?? '');
                if ($url === '') {
                    continue;
                }
                $key = $this->fingerprint($this->urlKey($url));
                if (isset($seenUrl[$key])) {
                    continue;
                }
                $seenUrl[$key] = true;
                $r['_query'] = $q['q'];
                $r['_lang'] = $lang;
                $r['_kind'] = $q['kind'];
                $r['_rank'] = count($rows);
                $rows[] = $r;
            }
        }

        return ['rows' => $rows, 'errors' => $errors, 'engines' => implode('+', $engines)];
    }

    /* ==================================================
     * ۳) پالایش و امتیازِ منبع
     * ================================================== */

    private function filterResults(array $rows, string $brandKey): array
    {
        $official = $this->officialDomains($brandKey);
        $out = [];
        foreach ($rows as $r) {
            $url = (string)($r['url'] ?? '');
            $host = $this->hostOf($url);
            if ($host === '' || $this->isStopDomain($host)) {
                continue;
            }
            $meta = $this->sourceMeta($url, $host, $official);
            $text = trim((string)($r['title'] ?? '') . ' ' . (string)($r['snippet'] ?? ''));
            if (mb_strlen($text) < 30) {
                continue;
            }
            $r['_host'] = $host;
            $r['_trust'] = $meta['trust'];
            $r['_kind_src'] = $meta['kind'];
            $r['_src_lang'] = $meta['lang'];
            $r['_official'] = $meta['official'];
            $out[] = $r;
        }
        /* اعتمادِ بالاتر + رتبه‌ی بهتر = اولویت بالاتر */
        usort($out, function ($a, $b) {
            $sa = (int)($a['_trust'] ?? 0) * 2 + max(0, 20 - (int)($a['_rank'] ?? 20));
            $sb = (int)($b['_trust'] ?? 0) * 2 + max(0, 20 - (int)($b['_rank'] ?? 20));
            return $sb <=> $sa;
        });
        return $out;
    }

    private function sourceMeta(string $url, string $host, array $official): array
    {
        $src = $this->webSources();
        $trust = (array)($src['trust'] ?? []);
        $isOfficial = false;
        foreach ($official as $dom) {
            if ($dom !== '' && (mb_stripos($host, $dom) !== false)) {
                $isOfficial = true;
                break;
            }
        }
        if ($isOfficial) {
            return ['host' => $host, 'kind' => 'official', 'trust' => (int)($trust['official'] ?? 100), 'lang' => $this->detectLang($url), 'official' => true];
        }
        foreach ((array)($src['verticals'] ?? []) as $v) {
            if (!is_array($v) || empty($v['domain'])) {
                continue;
            }
            if (mb_stripos($host, (string)$v['domain']) !== false) {
                return [
                    'host'     => $host,
                    'kind'     => (string)($v['kind'] ?? 'other'),
                    'trust'    => (int)($v['trust'] ?? 50),
                    'lang'     => (string)($v['lang'] ?? $this->detectLang($url)),
                    'official' => false,
                ];
            }
        }
        foreach ((array)($src['fa_verticals'] ?? []) as $v) {
            if (!is_array($v) || empty($v['domain'])) {
                continue;
            }
            $dom = (string)$v['domain'];
            if ($dom !== '' && mb_substr($host, -mb_strlen($dom)) === $dom) {
                return [
                    'host'     => $host,
                    'kind'     => (string)($v['kind'] ?? 'fa_site'),
                    'trust'    => (int)($v['trust'] ?? 62),
                    'lang'     => 'fa',
                    'official' => false,
                ];
            }
        }
        if (mb_substr($host, -3) === '.ir') {
            return ['host' => $host, 'kind' => 'fa_site', 'trust' => (int)($trust['fa_site'] ?? 64), 'lang' => 'fa', 'official' => false];
        }
        return ['host' => $host, 'kind' => 'other', 'trust' => (int)($trust['other'] ?? 34), 'lang' => $this->detectLang($url), 'official' => false];
    }

    private function officialDomains(string $brandKey): array
    {
        $src = $this->webSources();
        $map = (array)($src['official_domains'] ?? []);
        $key = mb_strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $brandKey) ?? ''));
        if ($key !== '' && isset($map[$key]) && is_array($map[$key])) {
            return array_values(array_filter(array_map('strval', $map[$key])));
        }
        /* تطبیقِ نرم: اگر کلید برند بخشی از نام دامنه است */
        foreach ($map as $k => $doms) {
            if ($key !== '' && (mb_stripos($k, $key) !== false || mb_stripos($key, (string)$k) !== false)) {
                return array_values(array_filter(array_map('strval', (array)$doms)));
            }
        }
        return [];
    }

    private function isStopDomain(string $host): bool
    {
        $src = $this->webSources();
        $host = mb_strtolower($host);
        foreach ((array)($src['stop_domains'] ?? []) as $d) {
            $d = mb_strtolower(trim((string)$d));
            if ($d === '') {
                continue;
            }
            if ($host === $d || mb_substr($host, -mb_strlen($d) - 1) === '.' . $d) {
                return true;
            }
        }
        return false;
    }

    private function detectLang(string $text): string
    {
        return preg_match('/[\x{0600}-\x{06FF}]/u', $text) ? 'fa' : 'en';
    }

    private function isRateLimitError(string $msg): bool
    {
        $m = mb_strtolower($msg);
        foreach (['rate', 'limit', 'سقف', 'تعداد درخواست', '429', 'too many'] as $needle) {
            if (mb_strpos($m, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /* ==================================================
     * ۵) استخراج‌ها
     * ================================================== */

    /**
     * 🧭 استخراجِ اسکلتِ رقبا: تیترهایی که SERP واقعاً انتظار دارد
     * منبع: عنوانِ صفحاتِ رقیب + خطوطِ کوتاهِ شبیه‌تیتر در متن صفحات
     */
    private function extractOutline(array $results, array $pages, array $ctx, int $max): array
    {
        $cands = [];
        $subject = TextProcessor::normalize(mb_strtolower(trim((string)($ctx['subject'] ?? ''))));

        /* الف) عنوانِ نتایج — تیترِ واقعی صفحه‌ی رقیب */
        foreach ($results as $r) {
            $title = $this->cleanText((string)($r['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            /* بریدن دُمِ نام سایت: «علت نشتی لباسشویی - سایت X» */
            $title = trim(preg_replace('/\s+[|\-–—]\s+[^|\-–—]{2,40}$/u', '', $title) ?? $title);
            $words = preg_split('/\s+/u', $title) ?: [];
            $wc = count($words);
            if ($wc < 3 || $wc > 12) {
                continue;
            }
            if (mb_strpos($title, '؟') !== false || preg_match('/^(چرا|چطور|چگونه|آیا|چه)/u', $title)) {
                continue; // پرسش‌ها جای خودشان می‌روند
            }
            $score = (int)($r['_trust'] ?? 40) + max(0, 12 - (int)($r['_rank'] ?? 12));
            if ($subject !== '' && mb_strpos(TextProcessor::normalize(mb_strtolower($title)), $subject) !== false) {
                $score += 25;
            }
            $banned = ['خرید', 'قیمت روز', 'فروش', 'آگهی', 'استخدام', 'دانلود', 'ویدیو', 'فیلم'];
            foreach ($banned as $b) {
                if (mb_strpos($title, $b) !== false) {
                    $score -= 40;
                }
            }
            if ($score < 30) {
                continue;
            }
            $this->addOutlineCandidate($cands, $title, $score, (string)($r['url'] ?? ''), (string)($r['_host'] ?? ''), (string)($r['_src_lang'] ?? 'fa'));
        }

        /* ب) خطوطِ کوتاهِ شبیه‌تیتر در متن صفحات */
        foreach ($pages as $p) {
            $text = (string)($p['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $lines = preg_split('/\R/u', $text) ?: [];
            $trust = 60;
            $host = $this->hostOf((string)($p['url'] ?? ''));
            foreach ($lines as $line) {
                $line = $this->cleanText($line);
                $words = preg_split('/\s+/u', $line) ?: [];
                $wc = count($words);
                if ($wc < 2 || $wc > 9) {
                    continue;
                }
                if (mb_strlen($line) < 12 || mb_strlen($line) > 90) {
                    continue;
                }
                /* تیتر جمله نیست: به نقطه/ویرگول ختم نمی‌شود و فعلی ندارد */
                if (preg_match('/[.؛،,!؟?]$/u', $line) || preg_match('/\b(است|هستند|می‌شود|می‌کند|دارید|کنید|شود)\b/u', $line)) {
                    continue;
                }
                if (preg_match('/^\d+$/u', $line)) {
                    continue;
                }
                $this->addOutlineCandidate($cands, $line, $trust, (string)($p['url'] ?? ''), $host, $this->detectLang($line));
            }
        }

        usort($cands, function ($a, $b) {
            if ((int)$b['score'] === (int)$a['score']) {
                return mb_strlen($a['heading']) <=> mb_strlen($b['heading']);
            }
            return (int)$b['score'] <=> (int)$a['score'];
        });

        $out = [];
        $seen = [];
        foreach ($cands as $c) {
            $fp = $this->fingerprint($c['heading']);
            if (isset($seen[$fp])) {
                continue;
            }
            $seen[$fp] = true;
            $out[] = $c;
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    private function addOutlineCandidate(array &$cands, string $heading, int $score, string $url, string $host, string $lang): void
    {
        $fp = $this->fingerprint($heading);
        if (isset($cands[$fp])) {
            $cands[$fp]['score'] += 30; // توافقِ چند منبع = امتیاز بیشتر
            if (!empty($cands[$fp]['sources']) && count($cands[$fp]['sources']) < 3) {
                $cands[$fp]['sources'][] = $host;
            }
            return;
        }
        $cands[$fp] = [
            'heading' => $heading,
            'score'   => $score,
            'url'     => $url,
            'host'    => $host,
            'lang'    => $lang,
            'sources' => $host !== '' ? [$host] : [],
        ];
    }

    /**
     * ❓ استخراج پرسش‌های واقعی کاربران (PAA / تیترهای سؤالی)
     */
    private function extractQuestions(array $results, array $pages, int $max): array
    {
        $cands = [];
        $collect = function (string $text, string $url, string $host, string $lang, int $base) use (&$cands): void {
            $text = $this->cleanText($text);
            if ($text === '') {
                return;
            }
            $isFaQuestion = mb_strpos($text, '؟') !== false || preg_match('/^(چرا|چطور|چگونه|آیا|کدام|چند|چه|کی|کجا)\b/u', $text);
            $isEnQuestion = mb_strpos($text, '?') !== false || preg_match('/^(why|how|what|when|where|which|is|does|do|can|should)\b/i', $text);
            if (!$isFaQuestion && !$isEnQuestion) {
                return;
            }
            /* فقط تا پایان علامت سؤال */
            if (preg_match('/^([^.!؟?\n]*[؟?])/u', $text, $m)) {
                $q = trim($m[1]);
            } else {
                $q = mb_substr($text, 0, 110);
            }
            $q = $this->cleanText($q);
            if (mb_strlen($q) < 12 || mb_strlen($q) > 130) {
                return;
            }
            $wc = count(preg_split('/\s+/u', $q) ?: []);
            if ($wc < 3 || $wc > 16) {
                return;
            }
            $fp = $this->fingerprint($q);
            if (isset($cands[$fp])) {
                $cands[$fp]['score'] += 25;
                return;
            }
            $cands[$fp] = [
                'q'     => $q,
                'score' => $base,
                'url'   => $url,
                'host'  => $host,
                'lang'  => $lang,
            ];
        };

        foreach ($results as $r) {
            $base = (int)($r['_trust'] ?? 40);
            $collect((string)($r['title'] ?? ''), (string)($r['url'] ?? ''), (string)($r['_host'] ?? ''), (string)($r['_src_lang'] ?? 'fa'), $base + 10);
            $collect((string)($r['snippet'] ?? ''), (string)($r['url'] ?? ''), (string)($r['_host'] ?? ''), (string)($r['_src_lang'] ?? 'fa'), $base);
        }
        foreach ($pages as $p) {
            $host = $this->hostOf((string)($p['url'] ?? ''));
            foreach ($this->sentencesOf((string)($p['text'] ?? ''), 400) as $s) {
                $collect($s, (string)($p['url'] ?? ''), $host, $this->detectLang($s), 50);
            }
        }

        usort($cands, function ($a, $b) {
            return (int)$b['score'] <=> (int)$a['score'];
        });
        return array_slice(array_values($cands), 0, $max);
    }

    /**
     * 📊 استخراج عدد و آمار **با منبع** — برای جعبه‌ی آمارِ مقاله
     */
    private function extractStats(array $results, array $pages, int $max): array
    {
        $src = $this->webSources();
        $units = (array)($src['stat_units'] ?? []);
        $unitRe = [];
        foreach ($units as $u) {
            $u = trim((string)$u);
            if ($u !== '') {
                $unitRe[] = preg_quote($u, '/');
            }
        }
        $unitPattern = empty($unitRe) ? '' : '/(' . implode('|', $unitRe) . ')/u';

        $out = [];
        $seen = [];
        $consider = function (string $sentence, string $url, string $host, int $trust, string $lang) use (&$out, &$seen, $unitPattern, $max): void {
            if (count($out) >= $max) {
                return;
            }
            $sentence = $this->cleanText($sentence);
            if (mb_strlen($sentence) < 20 || mb_strlen($sentence) > 180) {
                return;
            }
            if (!preg_match('/\d/u', $sentence)) {
                return;
            }
            if ($unitPattern !== '' && !preg_match($unitPattern, $sentence)) {
                return;
            }
            /* جملاتِ تبلیغاتی/فروش را حذف کن */
            $bad = ['خرید', 'فروش', 'ارسال رایگان', 'تماس بگیرید', 'سبد خرید', 'موجودی', 'تخفیف'];
            foreach ($bad as $b) {
                if (mb_strpos($sentence, $b) !== false) {
                    return;
                }
            }
            $fp = $this->fingerprint($sentence);
            if (isset($seen[$fp])) {
                return;
            }
            $seen[$fp] = true;
            $number = '';
            $unit = '';
            if (preg_match('/([\d۰-۹][\d۰-۹\.,\/]*)\s*([^\s\d۰-۹]+)?/u', $sentence, $m)) {
                $number = $m[1];
                $unit = isset($m[2]) ? trim($m[2]) : '';
            }
            $out[] = [
                'text'   => $sentence,
                'number' => $number,
                'unit'   => $unit,
                'url'    => $url,
                'host'   => $host,
                'trust'  => $trust,
                'lang'   => $lang,
            ];
        };

        foreach ($results as $r) {
            foreach ($this->sentencesOf((string)($r['snippet'] ?? ''), 300) as $s) {
                $consider($s, (string)($r['url'] ?? ''), (string)($r['_host'] ?? ''), (int)($r['_trust'] ?? 40), (string)($r['_src_lang'] ?? 'fa'));
            }
        }
        foreach ($pages as $p) {
            $host = $this->hostOf((string)($p['url'] ?? ''));
            foreach ($this->sentencesOf((string)($p['text'] ?? ''), 700) as $s) {
                $consider($s, (string)($p['url'] ?? ''), $host, 60, $this->detectLang($s));
            }
        }

        usort($out, function ($a, $b) {
            return (int)$b['trust'] <=> (int)$a['trust'];
        });
        return array_slice($out, 0, $max);
    }

    /**
     * 📝 استخراج فکت‌های قابل‌استفاده در نثر مقاله
     */
    private function extractFacts(array $results, array $pages, int $max): array
    {
        $out = [];
        $seen = [];
        $subjectWords = [];

        $consider = function (string $sentence, string $url, string $host, string $title, int $trust, string $lang, bool $fromPage) use (&$out, &$seen, $max, $subjectWords): void {
            if (count($out) >= $max) {
                return;
            }
            $sentence = $this->cleanText($sentence);
            $len = mb_strlen($sentence);
            if ($len < 40 || $len > 220) {
                return;
            }
            $wc = count(preg_split('/\s+/u', $sentence) ?: []);
            if ($wc < 7 || $wc > 40) {
                return;
            }
            if (mb_strpos($sentence, '؟') !== false || mb_strpos($sentence, '?') !== false) {
                return;
            }
            /* جملاتِ ناوبری/تبلیغاتی و منوها */
            $bad = ['کوکی', 'cookie', 'حریم خصوصی', 'تماس با ما', 'درباره ما', 'ورود', 'عضویت',
                    'خرید', 'سبد خرید', 'ارسال رایگان', 'subscribe', 'sign up', 'all rights reserved', '©'];
            $low = mb_strtolower($sentence);
            foreach ($bad as $b) {
                if (mb_strpos($low, mb_strtolower($b)) !== false) {
                    return;
                }
            }
            $fp = $this->fingerprint(mb_substr($sentence, 0, 60));
            if (isset($seen[$fp])) {
                return;
            }
            $seen[$fp] = true;
            $out[] = [
                'text'   => $sentence,
                'url'    => $url,
                'title'  => $title,
                'host'   => $host,
                'trust'  => $trust + ($fromPage ? 15 : 0),
                'lang'   => $lang,
            ];
        };

        foreach ($results as $r) {
            $snippet = (string)($r['snippet'] ?? '');
            if ($snippet !== '') {
                foreach ($this->sentencesOf($snippet, 400) as $s) {
                    $consider($s, (string)($r['url'] ?? ''), (string)($r['_host'] ?? ''), (string)($r['title'] ?? ''), (int)($r['_trust'] ?? 40), (string)($r['_src_lang'] ?? 'fa'), false);
                }
            }
        }
        foreach ($pages as $p) {
            $host = $this->hostOf((string)($p['url'] ?? ''));
            foreach ($this->sentencesOf((string)($p['text'] ?? ''), 900) as $s) {
                $consider($s, (string)($p['url'] ?? ''), $host, (string)($p['title'] ?? ''), 60, $this->detectLang($s), true);
            }
        }

        usort($out, function ($a, $b) {
            return (int)$b['trust'] <=> (int)$a['trust'];
        });
        return array_slice($out, 0, $max);
    }

    /**
     * 🏷️ استخراج موجودیت‌ها و ترکیب‌های پربسامد (پوششِ معنایی)
     */
    private function extractEntities(array $results, array $pages, int $max): array
    {
        $src = $this->webSources();
        $stop = array_flip((array)($src['entity_stopwords'] ?? []));
        $counts = [];
        $corpus = '';
        foreach ($results as $r) {
            $corpus .= ' ' . (string)($r['title'] ?? '') . ' ' . (string)($r['snippet'] ?? '');
        }
        $corpus = TextProcessor::normalize($corpus);
        foreach ((array)TextProcessor::tokenize($corpus, true) as $w) {
            if (mb_strlen($w) < 4 || isset($stop[$w])) {
                continue;
            }
            $counts[$w] = ($counts[$w] ?? 0) + 1;
        }
        /* بی‌گرمِ دوکلمه‌ایِ پربسامد (ترکیب‌های تخصصی) */
        $bigrams = [];
        $tokens = preg_split('/\s+/u', $corpus) ?: [];
        $tokens = array_values(array_filter($tokens, function ($t) {
            return mb_strlen($t) >= 3;
        }));
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            if (isset($stop[$tokens[$i]]) || isset($stop[$tokens[$i + 1]])) {
                continue;
            }
            $bg = $tokens[$i] . ' ' . $tokens[$i + 1];
            if (mb_strlen($bg) < 8) {
                continue;
            }
            $bigrams[$bg] = ($bigrams[$bg] ?? 0) + 1;
        }
        arsort($bigrams);

        $out = [];
        foreach ($bigrams as $bg => $c) {
            if ($c < 3) {
                break;
            }
            $out[] = ['term' => $bg, 'count' => $c, 'type' => 'bigram'];
            if (count($out) >= (int)($max / 2)) {
                break;
            }
        }
        arsort($counts);
        foreach ($counts as $w => $c) {
            if ($c < 3) {
                break;
            }
            $out[] = ['term' => $w, 'count' => $c, 'type' => 'word'];
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    /**
     * 📚 فهرست منابعِ نهایی (برای بخش «منابع و مطالعه بیشتر»)
     */
    private function collectSources(array $results, int $max): array
    {
        $out = [];
        $seen = [];
        foreach ($results as $r) {
            $host = (string)($r['_host'] ?? '');
            if ($host === '' || isset($seen[$host])) {
                continue;
            }
            $title = $this->cleanText((string)($r['title'] ?? ''));
            $url = (string)($r['url'] ?? '');
            if ($title === '' || $url === '') {
                continue;
            }
            $seen[$host] = true;
            $out[] = [
                'title' => mb_substr($title, 0, 110),
                'url'   => $url,
                'host'  => $host,
                'trust' => (int)($r['_trust'] ?? 40),
                'kind'  => (string)($r['_kind_src'] ?? 'other'),
                'lang'  => (string)($r['_src_lang'] ?? 'fa'),
            ];
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    private function sourceStats(array $sources): array
    {
        $stats = ['total' => count($sources), 'fa' => 0, 'en' => 0, 'official' => 0, 'manual' => 0, 'forum' => 0, 'avg_trust' => 0];
        $sum = 0;
        foreach ($sources as $s) {
            if (($s['lang'] ?? '') === 'fa') {
                $stats['fa']++;
            } else {
                $stats['en']++;
            }
            $kind = (string)($s['kind'] ?? 'other');
            if ($kind === 'official') {
                $stats['official']++;
            } elseif ($kind === 'manual') {
                $stats['manual']++;
            } elseif ($kind === 'forum') {
                $stats['forum']++;
            }
            $sum += (int)($s['trust'] ?? 0);
        }
        $stats['avg_trust'] = $stats['total'] > 0 ? (int)round($sum / $stats['total']) : 0;
        return $stats;
    }

    /* ==================================================
     * ۱۰) یادگیری — ذخیره و بازمصرف فکت‌ها
     * ================================================== */

    private function learnedPath(): string
    {
        return ENGINE_PATH . self::LEARNED_FILE;
    }

    public function loadLearned(int $brandId, string $deviceKey, string $subject): array
    {
        $file = $this->learnedPath();
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }
        $list = (array)($data['facts'] ?? []);
        $subjectWords = array_flip((array)TextProcessor::tokenize(TextProcessor::normalize($subject), true));
        $out = [];
        foreach ($list as $row) {
            if (!is_array($row) || empty($row['text'])) {
                continue;
            }
            if ($deviceKey !== '' && (string)($row['device_key'] ?? '') !== $deviceKey) {
                continue;
            }
            /* ارتباطِ موضوعیِ سبک: حداقل یک واژه‌ی مشترک */
            $rowWords = (array)TextProcessor::tokenize(TextProcessor::normalize((string)$row['text']), true);
            $hit = 0;
            foreach ($rowWords as $w) {
                if (isset($subjectWords[$w])) {
                    $hit++;
                }
            }
            if ($hit === 0) {
                continue;
            }
            $out[] = $row;
            if (count($out) >= 8) {
                break;
            }
        }
        return $out;
    }

    private function saveLearned(int $brandId, string $deviceKey, string $subject, array $stats): int
    {
        if (empty($stats)) {
            return 0;
        }
        $file = $this->learnedPath();
        $data = ['facts' => []];
        if (is_file($file)) {
            $old = json_decode((string)@file_get_contents($file), true);
            if (is_array($old) && isset($old['facts']) && is_array($old['facts'])) {
                $data['facts'] = $old['facts'];
            }
        }
        $existing = [];
        foreach ($data['facts'] as $row) {
            if (is_array($row) && isset($row['text'])) {
                $existing[$this->fingerprint((string)$row['text'])] = true;
            }
        }
        $added = 0;
        foreach ($stats as $s) {
            if (!is_array($s) || empty($s['text'])) {
                continue;
            }
            $fp = $this->fingerprint((string)$s['text']);
            if (isset($existing[$fp])) {
                continue;
            }
            $existing[$fp] = true;
            $data['facts'][] = [
                'text'       => (string)$s['text'],
                'url'        => (string)($s['url'] ?? ''),
                'host'       => (string)($s['host'] ?? ''),
                'device_key' => $deviceKey,
                'brand_id'   => $brandId,
                'subject'    => mb_substr($subject, 0, 120),
                'trust'      => (int)($s['trust'] ?? 50),
                'lang'       => (string)($s['lang'] ?? 'fa'),
                'learned_at' => date('Y-m-d H:i:s'),
            ];
            $added++;
        }
        /* سقفِ ۱۲۰۰ رکورد — قدیمی‌ترین‌ها حذف می‌شوند */
        if (count($data['facts']) > 1200) {
            $data['facts'] = array_slice($data['facts'], -1200);
        }
        if ($added > 0) {
            @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        }
        return $added;
    }

    /* ==================================================
     * ابزارها
     * ================================================== */

    private function webSources(): array
    {
        if ($this->sources === null) {
            $this->sources = TextProcessor::loadKnowledge('article-web-sources');
        }
        return is_array($this->sources) ? $this->sources : [];
    }

    private function sentencesOf(string $text, int $maxSentences = 500): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/(?<=[.!؟?])\s+|\R+/u', $text) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if (mb_strlen($p) >= 20) {
                $out[] = $p;
            }
            if (count($out) >= $maxSentences) {
                break;
            }
        }
        return $out;
    }

    private function cleanText(string $text): string
    {
        $text = trim(strip_tags($text));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = str_replace(["\u{200C}\u{200C}", ' .', ' ,'], ["\u{200C}", '.', ','], $text);
        return trim($text);
    }

    private function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }
        return mb_strtolower(preg_replace('/^www\./', '', $host) ?? $host);
    }

    private function urlKey(string $url): string
    {
        $url = preg_replace('/#.*$/', '', trim($url)) ?? $url;
        $url = preg_replace('/[?&](utm_[^&]*|fbclid|gclid)=[^&]*/', '', $url) ?? $url;
        return mb_strtolower(rtrim((string)$url, '/'));
    }

    private function fingerprint(string $text): string
    {
        $t = TextProcessor::normalize(mb_strtolower($text));
        $t = trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $t) ?? $t);
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
        return md5($t);
    }
}
