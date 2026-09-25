<?php
/**
 * 🌐 سرویس جستجوی آنلاین وب — WebSearchService v1.4
 * ================================================
 * قدرت جدید موتور سهند: دسترسی به داده‌های زنده اینترنت
 * برای بهبود محتوا، سئو و مقالات — فقط در صورت لزوم.
 *
 * معماری چند-ارائه‌دهنده با زنجیره جایگزین خودکار:
 *   1️⃣ SerpApi        (اختیاری — نیازمند کلید API)
 *   2️⃣ Google CSE     (اختیاری — نیازمند کلید + CX)
 *   3️⃣ Bing API       (اختیاری — نیازمند کلید اشتراک)
 *   4️⃣ Wikipedia API  (رایگان — رسمی و پایدار، fa+en)
 *   5️⃣ DuckDuckGo HTML / Lite (رایگان — بدون کلید)
 *   6️⃣ Mojeek / Startpage / Bing / Ecosia / Brave / SearXNG (رایگان)
 *   7️⃣ Google HTML / Yandex (رایگان — v1.3)
 *   8️⃣ 🆕 v1.4: jina+DDG — جستجوی DuckDuckGo از طریق پروکسی متن r.jina.ai
 *      (برو‌رفتنی از هر هاستی — حتی وقتی همه موتورها IP هاست را بلاک کرده‌اند؛
 *       درخواست از سرورهای jina می‌رود نه از هاست ما!)
 *   📰 Google News RSS (اخبار زنده — متد news())
 *   📄 🆕 v1.4: fetchPageText با fallback خواننده jina — صفحات 403/بلاک‌شده
 *      (مثل lg.com و سایت‌های قطعات) از طریق r.jina.ai خوانده می‌شوند
 *
 * ⚡ v1.2: مسابقه موازی (curl_multi) بین ارائه‌دهندگان رایگان —
 *    اولین پاسخ موفق برنده می‌شود؛ تأخیر جستجو تا ۵۰٪ کاهش یافت.
 *    + استخراج تاریخ نتایج (freshness) + رتبه‌بندی اعتماد دامنه
 *
 * 🔧 v1.4: مدیریت هوشمند شکست مسابقه — اگر ۲ کوئری پشت‌سرهم در مسابقه
 *    کامل شکست بخورد، مسابقه دور می‌زند و jina مستقیم امتحان می‌شود
 *    (صرفه‌جویی بودجه زمانی روی هاست‌های بلاک‌شده)
 *
 * امنیت و پایداری:
 *   🛡️ محافظت SSRF (آدرس‌های داخلی/خصوصی مسدود)
 *   ⏱️ محدودیت نرخ (پیش‌فرض ۲۴۰ جستجو در ساعت)
 *   ⚡ کش TTL-دار (پیش‌فرض ۳۰ دقیقه)
 *   🔄 جایگزینی خودکار ارائه‌دهنده در صورت خطا
 *   🔁 تلاش مجدد خودکار در خطای شبکه (v1.1)
 *
 * تنظیمات (جدول settings — کلید websearch_settings):
 *   enabled, timeout, max_results, cache_ttl, rate_per_hour,
 *   providers[], serpapi_key, google_cse_key, google_cse_cx, bing_api_key
 *
 * @package SahandBrandMaker\Engine
 * @version 1.5.0
 */
class WebSearchService
{
    /** @var array تنظیمات ادغام‌شده */
    private $cfg;

    /** @var array خطاهای آخرین عملیات (برای دیباگ) */
    private $lastErrors = [];

    /** @var string|null ارائه‌دهنده موفق آخر */
    private $lastProvider = null;

    /** 🆕 v1.4: زمان آخرین فراخوانی jina (محدودیت نرخ ناشناس ~۲۰/دقیقه) */
    private $jinaLastCall = 0.0;

    /** 🆕 v1.4: jina تا این زمان قفل می‌ماند (پس از خطای پشت‌سرهم 401/429) */
    private $jinaCooldownUntil = 0.0;

    /** 🆕 v1.4: شمارش شکست‌های متوالی مسابقه — بعد از ۲ بار، مستقیم jina */
    private $raceFailureStreak = 0;

    /** ⏱️ TTL پیش‌فرض کش جستجو (ثانیه) */
    const SEARCH_TTL = 1800;

    /** ⏱️ TTL کش تحقیق (ثانیه) */
    const RESEARCH_TTL = 21600;

    /** 🔢 حداکثر نتایج جستجو */
    const MAX_RESULTS = 10;

    public function __construct()
    {
        $saved = Config::get('websearch_settings');
        $saved = is_array($saved) ? $saved : [];
        $this->cfg = array_merge([
            'enabled'       => true,
            'timeout'       => 12,       // ثانیه — کل زمان درخواست
            'connect_timeout' => 5,      // ثانیه — فقط اتصال
            'max_results'   => 8,
            'cache_ttl'     => self::SEARCH_TTL,
            'rate_per_hour' => 240,      /* 🆕 v2.15: ۶۰ → ۲۴۰ — جستجوی عمیق خطایاب هر بار ۲۰+ کوئری می‌سوزاند و سقف ۶۰ آن را خفه می‌کرد! */
            'providers'     => [],       // خالی = زنجیره خودکار
            'serpapi_key'   => '',
            'google_cse_key'=> '',
            'google_cse_cx' => '',
            'bing_api_key'  => '',
        ], $saved);
        /* 🆕 v1.5: کلمپ سقف نرخ — نصب‌های قدیمی مقدار ۶۰ (seed اولیه) ذخیره
           کرده‌اند و آن روی پیش‌فرض ۲۴۰ غالب می‌شود؛ جستجوی عمیق خطایاب هر بار
           ۳۰-۴۰ کوئری می‌سوزاند و سقف ۶۰ بعد از ۱-۲ دستگاه همه جستجوها را
           fail-fast می‌کرد (ریشه‌یابی «هیچ کدی پیدا نشد»). حداقل مجاز = ۲۴۰. */
        if ((int)$this->cfg['rate_per_hour'] < 240) {
            $this->cfg['rate_per_hour'] = 240;
        }
    }

    /* ==================================================
     * 🔍 API عمومی
     * ================================================== */

    /**
     * 🔎 جستجوی وب — POST /api/ai/web-search
     *
     * @param string $query  عبارت جستجو (فارسی یا انگلیسی)
     * @param int    $limit  حداکثر نتایج (۱ تا ۱۰)
     * @return array [query, results[], provider, cached, took_ms]
     * @throws RuntimeException در صورت غیرفعال بودن یا شکست همه ارائه‌دهندگان
     */
    public function search(string $query, int $limit = 0): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            throw new RuntimeException('عبارت جستجو خیلی کوتاه است (حداقل ۲ کاراکتر).');
        }
        $this->guard();

        $limit = max(1, min(self::MAX_RESULTS, $limit > 0 ? $limit : (int)$this->cfg['max_results']));
        $t0 = microtime(true);

        $result = EngineCache::remember('websearch', ['q' => $query, 'l' => $limit], (int)$this->cfg['cache_ttl'], function () use ($query, $limit) {
            $this->rateHit();
            return $this->searchLive($query, $limit);
        });

        $result['cached'] = empty($result['fresh']);
        unset($result['fresh']);
        $result['took_ms'] = (int)round((microtime(true) - $t0) * 1000);
        return $result;
    }

    /**
     * 📰 جستجوی اخبار زنده — Google News RSS (فارسی/ایران)
     * برای روند‌های فصلی، قیمت‌های روز و رویداد‌های صنعت.
     *
     * @param string $query عبارت جستجو
     * @param int    $limit حداکثر خبر (۱ تا ۱۵)
     * @return array [query, results[], provider, cached, took_ms]
     */
    public function news(string $query, int $limit = 8): array
    {
        $query = trim($query);
        if (mb_strlen($query) < 2) {
            throw new RuntimeException('عبارت جستجوی خبر خیلی کوتاه است.');
        }
        $this->guard();
        $limit = max(1, min(15, $limit));
        $t0 = microtime(true);

        $result = EngineCache::remember('webnews', ['q' => $query, 'l' => $limit], self::SEARCH_TTL, function () use ($query, $limit) {
            $this->rateHit();
            $url = 'https://news.google.com/rss/search?' . http_build_query([
                'q'    => $query,
                'hl'   => 'fa',
                'gl'   => 'IR',
                'ceid' => 'IR:fa',
            ]);
            [$ok, $status, $body, $error] = $this->httpGet($url);
            if (!$ok) {
                throw new RuntimeException('دریافت اخبار ناموفق بود: ' . ($error ?: "HTTP {$status}"));
            }
            $results = $this->parseGoogleNewsRss($body, $limit);
            if (!$results) {
                throw new RuntimeException('خبری برای این عبارت یافت نشد.');
            }
            return [
                'query'    => $query,
                'results'  => $results,
                'provider' => 'google_news_rss',
                'fresh'    => true,
            ];
        });

        $result['cached'] = empty($result['fresh']);
        unset($result['fresh']);
        $result['took_ms'] = (int)round((microtime(true) - $t0) * 1000);
        return $result;
    }

    /** 🧩 تجزیه RSS اخبار گوگل (قابل تست آفلاین) */
    public function parseGoogleNewsRss(string $xml, int $limit): array
    {
        $out = [];
        if (preg_match_all('#<item>(.*?)</item>#is', $xml, $items)) {
            foreach (array_slice($items[1], 0, $limit) as $i => $item) {
                $title = $desc = $link = $date = $source = '';
                if (preg_match('#<title>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?</title>#is', $item, $m)) {
                    $title = $this->cleanText($m[1]);
                }
                if (preg_match('#<link>(.*?)</link>#is', $item, $m)) {
                    $link = trim($m[1]);
                }
                if (preg_match('#<description>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?</description>#is', $item, $m)) {
                    $desc = $this->cleanText($m[1]);
                }
                if (preg_match('#<pubDate>(.*?)</pubDate>#is', $item, $m)) {
                    $ts = strtotime(trim($m[1]));
                    $date = $ts ? date('Y-m-d H:i', $ts) : trim($m[1]);
                }
                if (preg_match('#<source[^>]*>(.*?)</source>#is', $item, $m)) {
                    $source = $this->cleanText($m[1]);
                }
                if ($title === '' || $link === '') { continue; }
                $out[] = [
                    'title'    => $title,
                    'url'      => $link,
                    'snippet'  => mb_substr($desc, 0, 220),
                    'source'   => $source ?: $this->hostOf($link),
                    'date'     => $date,
                    'rank'     => $i + 1,
                ];
            }
        }
        return $out;
    }

    /**
     * 📖 خواندن متن یک صفحه وب — استخراج محتوای مفید
     *
     * @param string $url       آدرس صفحه (http/https)
     * @param int    $maxChars  حداکثر کاراکتر متن (پیش‌فرض ۸۰۰۰)
     * @return array [ok, url, title, description, text, chars, took_ms]
     */
    public function fetchPageText(string $url, int $maxChars = 8000): array
    {
        $this->guard();
        $url = $this->sanitizeUrl($url);
        $t0 = microtime(true);

        [$ok, $status, $body, $error] = $this->httpGet($url);

        /* 🆕 v1.4 — fallback خواننده jina (دو محرک):
         *  ① سایت بلاک‌کننده: 403/401/429 یا صفحه چالش (Access Denied / Cloudflare)
         *  ② صفحه JS-محور: HTTP 200 ولی متن استخراجی تقریباً خالی (< ۳۰۰ نویسه) —
         *    jina صفحه را با رندر JS کامل می‌خواند (تست زنده: techbullish LG TV
         *    مستقیم = خالی، از طریق jina = ۱۰هزار نویسه با همه کدها) */
        $blocked = !$ok
            || $status === 401 || $status === 403 || $status === 429
            || stripos($body, 'Access Denied') !== false
            || stripos($body, 'Just a moment') !== false
            || stripos($body, 'captcha') !== false
            || stripos($body, 'unusual traffic') !== false;
        $directText = '';
        if ($ok && !$blocked) {
            $directText = $this->htmlBodyToText($body, $maxChars);
        }
        if (($blocked || mb_strlen($directText) < 300) && microtime(true) >= $this->jinaCooldownUntil) {
            try {
                [$jok, $jmd] = $this->jinaFetch($url);
                if ($jok) {
                    [$title, $text] = $this->markdownToText($jmd, $maxChars);
                    if (mb_strlen($text) > max(120, mb_strlen($directText))) {
                        return [
                            'ok'          => true,
                            'url'         => $url,
                            'title'       => $title ?: mb_substr($directText, 0, 0),
                            'description' => mb_substr($text, 0, 200),
                            'text'        => $text,
                            'chars'       => mb_strlen($text),
                            'took_ms'     => (int)round((microtime(true) - $t0) * 1000),
                            'via'         => 'jina',
                        ];
                    }
                }
            } catch (Throwable $e) {
                // jina هم نشد — ادامه با مسیر عادی
            }
        }
        if (!$ok) {
            return ['ok' => false, 'url' => $url, 'error' => $error ?: ('HTTP ' . $status), 'took_ms' => (int)round((microtime(true) - $t0) * 1000)];
        }

        // 🆕 v1.4: متن مستقیم از قبل استخراج شده (htmlBodyToText) — فقط عنوان/توضیح متا از HTML
        $title = '';
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $body, $m)) {
            $title = $this->cleanText($m[1]);
        }
        $desc = '';
        if (preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']#is', $body, $m)) {
            $desc = $this->cleanText($m[1]);
        } elseif (preg_match('#<meta[^>]+content=["\'](.*?)["\'][^>]+name=["\']description["\']#is', $body, $m)) {
            $desc = $this->cleanText($m[1]);
        }
        $text = $directText;

        return [
            'ok'          => mb_strlen($text) > 80,
            'url'         => $url,
            'title'       => $title,
            'description' => $desc,
            'text'        => $text,
            'chars'       => mb_strlen($text),
            'took_ms'     => (int)round((microtime(true) - $t0) * 1000),
        ];
    }

    /**
     * 🧹 تبدیل بدنه HTML به متن ساده (v1.4 — از دل fetchPageText استخراج شد
     * تا هم برای مسیر مستقیم و هم مقایسه با متن jina استفاده شود)
     */
    private function htmlBodyToText(string $body, int $maxChars): string
    {
        // حذف بخش‌های غیرمتنی
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $body);
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $html);
        $html = preg_replace('#<(nav|header|footer|aside|form|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = preg_replace('#<!--.*?-->#s', ' ', $html);

        // تبدیل بلوک‌ها به فاصله و حذف تگ‌ها
        $text = preg_replace('#</(p|div|li|h[1-6]|tr|section|article|blockquote)>#i', "\n", $html);
        $text = preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        $text = trim($text);
        return mb_substr($text, 0, max(500, $maxChars));
    }

    /**
     * 🧪 تحقیق ساختاریافته درباره یک موضوع — POST /api/ai/research
     * ترکیب چند جستجو + استخراج هوشمند: کلیدواژه ترند، سؤالات واقعی
     * کاربران، داده‌های عددی تازه و منابع.
     *
     * @param string $topic موضوع (مثلاً «تعمیر ماشین لباسشویی پاکشما»)
     * @param array  $opts  [fetch_pages => bool, limit => int]
     * @return array [topic, keywords[], questions[], facts[], sources[], summary, provider, took_ms]
     */
    public function research(string $topic, array $opts = []): array
    {
        $topic = trim($topic);
        if (mb_strlen($topic) < 3) {
            throw new RuntimeException('موضوع تحقیق خیلی کوتاه است.');
        }
        $this->guard();

        $fetchPages = !empty($opts['fetch_pages']);
        $limit = (int)($opts['limit'] ?? 8);

        return EngineCache::remember('webresearch', ['t' => $topic, 'fp' => $fetchPages, 'l' => $limit], self::RESEARCH_TTL, function () use ($topic, $fetchPages, $limit) {
            $t0 = microtime(true);

            // ۱️⃣ جستجوی اصلی
            $main = $this->searchLive($topic, $limit);
            $results = $main['results'];

            // ۲️⃣ جستجوی مکمل (قصد تجاری/قیمت) — خطای آن مهم نیست
            $commercial = [];
            try {
                $commercial = $this->searchLive($topic . ' قیمت', 4)['results'];
            } catch (Exception $e) {
                $this->lastErrors[] = 'commercial probe: ' . $e->getMessage();
            }

            $all = array_merge($results, $commercial);

            // ۳️⃣ استخراج کلیدواژه‌های ترند از عنوان‌ها و خلاصه‌ها
            $corpus = '';
            foreach ($all as $r) {
                $corpus .= ' ' . ($r['title'] ?? '') . ' ' . ($r['snippet'] ?? '');
            }
            $keywords = $this->extractTrendKeywords($topic, $corpus);

            // ۴️⃣ استخراج سؤالات واقعی کاربران
            $questions = $this->extractQuestions($all);

            // ۵️⃣ استخراج داده‌های عددی (قیمت/درصد/سال/مدت)
            $facts = $this->extractFacts($all);

            // ۶️⃣ خواندن متن ۲ صفحه برتر (اختیاری)
            $pages = [];
            if ($fetchPages && count($results) >= 1) {
                foreach (array_slice($results, 0, 2) as $r) {
                    try {
                        $page = $this->fetchPageText($r['url'], 4000);
                        if (!empty($page['ok'])) {
                            $pages[] = ['title' => $page['title'] ?: $r['title'], 'url' => $r['url'], 'text' => $page['text']];
                        }
                    } catch (Exception $e) {
                        $this->lastErrors[] = 'fetch page: ' . $e->getMessage();
                    }
                }
            }

            $sources = array_map(static function ($r) {
                return ['title' => $r['title'], 'url' => $r['url'], 'source' => $r['source'] ?? ''];
            }, array_slice($results, 0, $limit));

            // ۷️⃣ فرصت‌های کلیدواژه long-tail (موضوع × ترند) — v1.1
            $opportunities = $this->buildOpportunities($topic, $keywords, $questions);

            $took = (int)round((microtime(true) - $t0) * 1000);
            $summary = $this->buildSummary($topic, $keywords, $questions, $facts, $sources, $took);

            return [
                'topic'        => $topic,
                'keywords'     => $keywords,
                'questions'    => $questions,
                'facts'        => $facts,
                'opportunities'=> $opportunities,
                'pages'        => $pages,
                'sources'      => $sources,
                'summary'      => $summary,
                'provider'     => $main['provider'],
                'fresh'        => true,
                'took_ms'      => $took,
            ];
        });
    }

    /**
     * 📊 وضعیت سرویس — GET /api/ai/websearch-status
     */
    public function status(): array
    {
        $providers = [];
        foreach (['serpapi', 'google_cse', 'bing_api', 'duckduckgo_html', 'duckduckgo_lite', 'mojeek', 'bing_html'] as $p) {
            $providers[$p] = $this->providerAvailable($p);
        }
        $bucket = $this->rateBucket();
        return [
            'enabled'        => (bool)$this->cfg['enabled'],
            'curl_available' => extension_loaded('curl'),
            'providers'      => $providers,
            'active_chain'   => $this->providerChain(),
            'last_provider'  => $this->lastProvider,
            'rate'           => [
                'used_last_hour' => $bucket['count'],
                'limit_per_hour' => (int)$this->cfg['rate_per_hour'],
            ],
            'cache'          => EngineCache::stats(),
            'config'         => [
                'timeout'     => (int)$this->cfg['timeout'],
                'max_results' => (int)$this->cfg['max_results'],
                'cache_ttl'   => (int)$this->cfg['cache_ttl'],
            ],
            'last_errors'    => array_slice($this->lastErrors, 0, 5),
        ];
    }

    /* ==================================================
     * 🌉 پل jina — پروکسی متن رایگان r.jina.ai (v1.4)
     * ================================================== */

    /**
     * 🌉 فراخوانی خواننده jina با مدیریت هوشمند نرخ و retry
     *
     * r.jina.ai هر URL را از سرورهای خودش می‌خواند (IP تمیز) و Markdown
     * تمیز برمی‌گرداند — برو‌رفتنی برای صفحات 403/Cloudflare از هر هاستی.
     * ⏱️ محدودیت ناشناس ~۲۰ درخواست/دقیقه → فاصله اجباری ۳٫۵ ثانیه
     * بین فراخوانی‌ها + retry با تأخیر فزاینده روی 401/429 + قفل ۱۲۰ثانیه‌ای
     * پس از شکست‌های پشت‌سرهم (تا وقت موتور تلف نشود).
     *
     * @param string $targetUrl آدرس کامل http/https مقصد
     * @return array [ok(bool), body(string)] — body = Markdown خام jina
     */
    private function jinaFetch(string $targetUrl): array
    {
        if (microtime(true) < $this->jinaCooldownUntil) {
            return [false, ''];
        }
        $url = 'https://r.jina.ai/' . $targetUrl;
        $delays = [0.4, 2.5, 7.0]; // 🆕 v1.5: فاصله‌های کوتاه‌تر — بودجه خطایاب محدود است و انتظار ۹+ ثانیه‌ای wasteful بود
        $body = '';
        foreach ($delays as $i => $delay) {
            if ($i > 0) {
                usleep((int)($delay * 1000000));
            }
            // ⏱️ فاصله اجباری بین فراخوانی‌های jina (نرخ ناشناس)
            $wait = 3.5 - (microtime(true) - $this->jinaLastCall);
            if ($wait > 0) {
                usleep((int)($wait * 1000000));
            }
            $this->jinaLastCall = microtime(true);
            [$ok, $status, $b, $err] = $this->httpGet($url, [], 25, true); // حالت حداقلی — بدون UA مرورگر (تست زنده: UA کروم 403، curl 200)
            $body = is_string($b) ? $b : '';
            // خطای شناخته‌شده نرخ/اعتبار jina (بدنه JSON خطا با کد 401/429 می‌آید)
            $rateLimited = !$ok
                || $status === 401 || $status === 429
                || strpos($body, 'AuthenticationRequiredError') !== false
                || strpos($body, 'RateLimitError') !== false;
            if (!$rateLimited && $ok && $status === 200 && mb_strlen($body) > 100) {
                return [true, $body];
            }
            if ($status === 404) {
                return [false, ''];
            }
        }
        // شکست پشت‌سرهم → قفل ۱۲۰ ثانیه jina (فراخوانی بعدی سریع fail می‌شود)
        $this->jinaCooldownUntil = microtime(true) + 120.0;
        return [false, $body];
    }

    /**
     * 🔎 جستجوی DuckDuckGo از طریق پروکسی jina (v1.4)
     *
     * چرا؟ html.duckduckgo.com مستقیم از خیلی هاست‌ها 202/timeout می‌دهد؛
     * اما از طریق r.jina.ai درخواست از سرورهای jina می‌رود و نتیجه Markdown
     * با لینک‌های uddg بازگشتی برمی‌گردد — قابل پارس کامل.
     */
    private function searchJinaDdg(string $query, int $limit): array
    {
        $target = 'https://html.duckduckgo.com/html/?q=' . rawurlencode($query);
        [$ok, $md] = $this->jinaFetch($target);
        if (!$ok) {
            throw new RuntimeException('پروکسی jina در دسترس نیست');
        }
        return $this->parseJinaDdgMarkdown($md, $limit);
    }

    /**
     * 📊 پارس Markdown خروجی «jina روی صفحه نتایج DDG»
     *
     * قالب خروجی:
     *   ## [عنوان نتیجه](https://duckduckgo.com/l/?uddg=<URL-کدشده>&rut=...)
     *   [![Image...](favicon)](...)  ← حذف
     *   [دامنه/مسیر](همان لینک)      ← حذف
     *   [متن اسنیپت](همان لینک)      ← استخراج متن
     */
    public function parseJinaDdgMarkdown(string $md, int $limit): array
    {
        $out = [];
        if (!preg_match_all('/##\s*\[([^\]]+)\]\(https:\/\/duckduckgo\.com\/l\/\?uddg=([^&"\')]+)[^)]*\)/u', $md, $m, PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        $count = min(count($m[0]), max(1, $limit));
        for ($i = 0; $i < $count; $i++) {
            $title = trim((string)$m[1][$i][0]);
            $url = rawurldecode((string)$m[2][$i][0]);
            // اعتبار URL
            if (!preg_match('#^https?://#i', $url) || stripos($url, 'duckduckgo.com') !== false) {
                continue;
            }
            // اسنیپت = بخش بعد از این تیتر تا تیتر بعدی — متنِ داخل آخرین لینک‌های [..](..)
            $start = (int)$m[0][$i][1] + strlen((string)$m[0][$i][0]);
            $end = ($i + 1 < count($m[0])) ? (int)$m[0][$i + 1][1] : strlen($md);
            $section = substr($md, $start, $end - $start);
            // متن بلوک‌های لینک [متن](url) — متن اسنیپت DDG داخل این بلوک‌هاست
            $snippet = '';
            if (preg_match_all('/\[([^\]!][^\]]{10,})\]\(https:\/\/duckduckgo\.com\/l\/[^)]*\)/u', $section, $sm)) {
                foreach ($sm[1] as $cand) {
                    $cand = trim($cand);
                    // مسیر دامنه‌مانند نیست (مثل example.com/path) و تصویر نیست
                    if (mb_strlen($cand) > mb_strlen($snippet) && !preg_match('/^[\w.-]+\.[a-z]{2,}(\/\S*)?$/iu', $cand)) {
                        $snippet = $cand;
                    }
                }
            }
            $snippet = trim(preg_replace('/\s+/u', ' ', $snippet));
            if ($title === '') {
                continue;
            }
            $out[] = [
                'title'    => $this->cleanText($title),
                'url'      => $url,
                'snippet'  => $snippet,
                'provider' => 'jina_ddg',
            ];
        }
        return $out;
    }

    /**
     * 📄 تبدیل Markdown خواننده jina به متن ساده (v1.4)
     *
     * خروجی jina:
     *   Title: <عنوان صفحه>
     *   URL Source: <آدرس>
     *   Markdown Content:
     *   <بدنه markdown>
     *
     * تبدیل‌ها: حذف تصاویر، لینک به متن، حذف نشانه‌های قالب‌بندی markdown، حفظ
     * خطوط جدول (| کد | معنا | برای parseCodeTable خطایاب حیاتی است)
     *
     * @return array [title, text]
     */
    public function markdownToText(string $md, int $maxChars = 8000): array
    {
        $title = '';
        if (preg_match('/^Title:\s*(.+)$/mu', $md, $tm)) {
            $title = trim($tm[1]);
        }
        $body = $md;
        $pos = strpos($md, 'Markdown Content:');
        if ($pos !== false) {
            $body = substr($md, $pos + strlen('Markdown Content:'));
        }
        // حذف سرصفحه‌های jina اگر در بدنه ماند
        $body = preg_replace('/^(Title|URL Source):.*$/mu', ' ', $body);
        // تصاویر ![alt](url) → حذف کامل
        $body = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', ' ', $body);
        // لینک [متن](url) → متن
        $body = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $body);
        // خط‌های جداکننده جدول markdown (---|---) → حذف
        $body = preg_replace('/^\s*\|?[\s:|-]+\|?\s*$/mu', ' ', $body);
        // نشانه‌های قالب‌بندی
        $body = str_replace(['**', '__'], '', $body);
        $body = preg_replace('/(?<!\w)[*_]{1,3}(?!\s)/u', '', $body);
        $body = preg_replace('/^#{1,6}\s*/mu', '', $body);
        $body = preg_replace('/^\s*[-•]\s+/mu', '', $body);
        // بلاک کد
        $body = preg_replace('/```.*?```/us', ' ', $body);
        // فاصله‌ها
        $body = preg_replace('/[ \t]+/', ' ', $body);
        $body = preg_replace('/\n{3,}/', "\n\n", $body);
        $body = trim($body);
        $body = mb_substr($body, 0, max(500, $maxChars));
        return [$this->cleanText($title), $body];
    }

    /* ==================================================
     * 🔗 زنجیره ارائه‌دهندگان
     * ================================================== */

    /** اجرای زنده جستجو با جایگزینی خودکار ارائه‌دهنده */
    private function searchLive(string $query, int $limit): array
    {
        $this->throttle();
        $errors = [];
        $chain = $this->providerChain();

        // ⚡ v1.2: مسابقه موازی ارائه‌دهندگان رایگان با curl_multi —
        // فقط اولین ارائه‌دهنده رایگان به صورت ترتیبی اجرا نمی‌شود؛
        // بلکه گروه رایگان‌ها همزمان شلیک می‌شوند و اولین پاسخ برنده است.
        $freeGroup = array_values(array_filter($chain, function ($p) {
            return in_array($p, ['wikipedia', 'duckduckgo_html', 'duckduckgo_lite', 'mojeek', 'startpage', 'bing_html', 'google_html', 'yandex_html', 'ecosia_html', 'brave_html', 'searx_json'], true);
        }));
        $keyedGroup = array_values(array_diff($chain, $freeGroup));

        // ۱) ارائه‌دهندگان کلیددار به صورت ترتیبی (معمولاً یک عدد فعال است)
        foreach ($keyedGroup as $provider) {
            try {
                $results = $this->runProvider($provider, $query, $limit);
                if ($results) {
                    $this->lastProvider = $provider;
                    return $this->finalizeResults($query, $results, $limit, $provider);
                }
                $errors[] = "{$provider}: نتیجه‌ای استخراج نشد";
            } catch (Exception $e) {
                $errors[] = "{$provider}: " . $e->getMessage();
            }
        }

        // ۲) ⚡ مسابقه موازی رایگان‌ها
        //    🔧 v1.4: اگر ۲ بار پشت‌سرهم کامل شکست خورده باشد، دیگر وقت
        //    برای مسابقه تلف نمی‌شود — مستقیم سراغ jina می‌رویم (هاست بلاک‌شده)
        $raceTried = false;
        if (!empty($freeGroup) && $this->raceFailureStreak < 2) {
            $raceTried = true;
            try {
                $raced = $this->raceProviders($freeGroup, $query, $limit);
                if (!empty($raced['results'])) {
                    $this->raceFailureStreak = 0;
                    $this->lastProvider = $raced['provider'];
                    return $this->finalizeResults($query, $raced['results'], $limit, $raced['provider']);
                }
                $errors[] = $raced['error'] ?? 'race: بدون نتیجه';
                $this->raceFailureStreak++;
            } catch (Exception $e) {
                $errors[] = 'race: ' . $e->getMessage();
                $this->raceFailureStreak++;
            }
        }

        // ۳) 🆕 v1.4: jina+DDG — برو‌رفتنی از هر هاستی (پروکسی r.jina.ai)
        //    درخواست جستجو از سرورهای jina می‌رود؛ حتی اگر همه موتورها IP
        //    هاست را بلاک کرده باشند نتیجه می‌آید. retry داخلی + throttle.
        if (microtime(true) >= $this->jinaCooldownUntil) {
            try {
                $results = $this->searchJinaDdg($query, $limit);
                if ($results) {
                    $this->lastProvider = 'jina_ddg';
                    return $this->finalizeResults($query, $results, $limit, 'jina_ddg');
                }
                $errors[] = 'jina_ddg: نتیجه‌ای استخراج نشد';
            } catch (Exception $e) {
                $errors[] = 'jina_ddg: ' . $e->getMessage();
            }
        }

        // ۴) آخرین تلاش: اگر مسابقه را به‌خاطر streak رد کردیم، حالا یک‌بار امتحان شود
        if (!$raceTried && !empty($freeGroup)) {
            try {
                $raced = $this->raceProviders($freeGroup, $query, $limit);
                if (!empty($raced['results'])) {
                    $this->lastProvider = $raced['provider'];
                    return $this->finalizeResults($query, $raced['results'], $limit, $raced['provider']);
                }
                $errors[] = $raced['error'] ?? 'race(2): بدون نتیجه';
            } catch (Exception $e) {
                $errors[] = 'race(2): ' . $e->getMessage();
            }
        }

        $this->lastErrors = array_merge($this->lastErrors, $errors);
        throw new RuntimeException('جستجوی وب در همه ارائه‌دهندگان ناموفق بود — ' . implode(' | ', array_slice($errors, 0, 3)));
    }

    /**
     * ⚡ v1.2: مسابقه موازی ارائه‌دهندگان با curl_multi
     * همه ارائه‌دهندگان همزمان درخواست می‌فرستند؛ اولین پاسخ موفقِ غیرخالی برنده است
     * و بقیه درخواست‌ها لغو می‌شوند (صرفه‌جویی در زمان تا ۵۰٪).
     */
    private function raceProviders(array $providers, string $query, int $limit): array
    {
        if (!function_exists('curl_multi_init')) {
            // fallback: ترتیبی (سرورهای بدون curl_multi)
            foreach ($providers as $provider) {
                try {
                    $results = $this->runProvider($provider, $query, $limit);
                    if ($results) { return ['provider' => $provider, 'results' => $results]; }
                } catch (Exception $e) { /* ادامه */ }
            }
            return ['provider' => null, 'results' => [], 'error' => 'بدون curl_multi و بدون نتیجه'];
        }

        $mh = curl_multi_init();
        $handles = [];
        $meta = [];
        foreach ($providers as $idx => $provider) {
            [$ch, $ctx] = $this->buildProviderRequest($provider, $query, $limit);
            if ($ch === null) { continue; }
            curl_multi_add_handle($mh, $ch);
            $handles[$idx] = $ch;
            $meta[$idx] = ['provider' => $provider] + $ctx;
        }
        if (empty($handles)) {
            curl_multi_close($mh);
            return ['provider' => null, 'results' => [], 'error' => 'هیچ درخواستی ساخته نشد'];
        }

        // اجرای موازی
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) { curl_multi_select($mh, 0.05); }
        } while ($active && $status === CURLM_OK);

        // جمع‌آوری اولین پاسخ موفق (به ترتیب زنجیره = اولویت)
        $winner = null;
        $raceErrors = [];
        foreach ($handles as $idx => $ch) {
            $body = (string)curl_multi_getcontent($ch);
            $provider = $meta[$idx]['provider'];
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if ($winner !== null) { continue; }
            try {
                $results = $this->parseProviderResponse($provider, $body, $limit, $meta[$idx]);
                if ($results) {
                    $winner = ['provider' => $provider, 'results' => $results];
                } else {
                    $raceErrors[] = "{$provider}: خالی";
                }
            } catch (Exception $e) {
                $raceErrors[] = "{$provider}: " . $e->getMessage();
            }
        }
        curl_multi_close($mh);

        if ($winner === null) {
            return ['provider' => null, 'results' => [], 'error' => implode(' | ', array_slice($raceErrors, 0, 3))];
        }
        return $winner;
    }

    /**
     * 🏗️ ساخت درخواست cURL خام یک ارائه‌دهنده (برای مسابقه موازی)
     * خروجی: [curl_handle|null, context]
     */
    private function buildProviderRequest(string $provider, string $query, int $limit): array
    {
        $ch = curl_init();
        $ctx = ['method' => 'GET', 'url' => ''];
        $common = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => (int)$this->cfg['connect_timeout'],
            CURLOPT_TIMEOUT        => (int)$this->cfg['timeout'],
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => $this->userAgent(),
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                'Accept-Language: fa-IR,fa;q=0.9,en;q=0.8',
            ],
        ];
        switch ($provider) {
            case 'wikipedia':
                $ctx['url'] = 'https://fa.wikipedia.org/w/api.php?' . http_build_query([
                    'action' => 'query', 'list' => 'search', 'srsearch' => $query,
                    'srlimit' => min(50, max(1, $limit)), 'format' => 'json', 'utf8' => 1,
                ]);
                curl_setopt_array($ch, $common + [CURLOPT_URL => $ctx['url']]);
                return [$ch, $ctx];
            case 'duckduckgo_html':
                $ctx['method'] = 'POST';
                $ctx['url'] = 'https://html.duckduckgo.com/html/';
                curl_setopt_array($ch, $common + [
                    CURLOPT_URL           => $ctx['url'],
                    CURLOPT_POST          => true,
                    CURLOPT_POSTFIELDS    => http_build_query(['q' => $query, 'kl' => 'wt-wt']),
                ]);
                return [$ch, $ctx];
            case 'duckduckgo_lite':
                $ctx['method'] = 'POST';
                $ctx['url'] = 'https://lite.duckduckgo.com/lite/';
                curl_setopt_array($ch, $common + [
                    CURLOPT_URL           => $ctx['url'],
                    CURLOPT_POST          => true,
                    CURLOPT_POSTFIELDS    => http_build_query(['q' => $query]),
                ]);
                return [$ch, $ctx];
            case 'mojeek':
                $ctx['url'] = 'https://www.mojeek.com/search?' . http_build_query(['q' => $query, 't' => (string)$limit]);
                curl_setopt_array($ch, $common + [CURLOPT_URL => $ctx['url']]);
                return [$ch, $ctx];
            case 'startpage':
                $ctx['method'] = 'POST';
                $ctx['url'] = 'https://www.startpage.com/sp/search';
                curl_setopt_array($ch, $common + [
                    CURLOPT_URL           => $ctx['url'],
                    CURLOPT_POST          => true,
                    CURLOPT_POSTFIELDS    => http_build_query(['query' => $query]),
                ]);
                return [$ch, $ctx];
            case 'bing_html':
                $ctx['url'] = 'https://www.bing.com/search?' . http_build_query([
                    'q' => $query, 'count' => (string)$limit, 'setlang' => 'fa', 'cc' => 'IR',
                ]);
                curl_setopt_array($ch, $common + [CURLOPT_URL => $ctx['url']]);
                return [$ch, $ctx];
            /* ════════ 🆕 v2.15: ارائه‌دهنده‌های جدید — پوشش از هاست‌های محدود/ایرانی ════════ */
            case 'google_html':
                $ctx['url'] = 'https://www.google.com/search?' . http_build_query([
                    'q' => $query, 'num' => (string)max(10, $limit), 'hl' => 'en', 'gbv' => '1',
                ]);
                curl_setopt_array($ch, $common + [
                    CURLOPT_URL => $ctx['url'],
                    CURLOPT_HTTPHEADER => [
                        'Accept: text/html,application/xhtml+xml',
                        'Accept-Language: en-US,en;q=0.9,fa;q=0.8',
                    ],
                ]);
                return [$ch, $ctx];
            case 'yandex_html':
                $ctx['url'] = 'https://yandex.com/search/?' . http_build_query(['text' => $query]);
                curl_setopt_array($ch, $common + [CURLOPT_URL => $ctx['url']]);
                return [$ch, $ctx];
            case 'ecosia_html':
                $ctx['url'] = 'https://www.ecosia.org/search?' . http_build_query(['q' => $query]);
                curl_setopt_array($ch, $common + [CURLOPT_URL => $ctx['url']]);
                return [$ch, $ctx];
            case 'brave_html':
                $ctx['url'] = 'https://search.brave.com/search?' . http_build_query(['q' => $query, 'source' => 'web']);
                curl_setopt_array($ch, $common + [CURLOPT_URL => $ctx['url']]);
                return [$ch, $ctx];
            case 'searx_json':
                /* نمونه‌های عمومی SearXNG با خروجی JSON — یکی تصادفی (پراکندگی بار) */
                $instances = [
                    'https://searx.be/search',
                    'https://search.inetol.net/search',
                    'https://priv.au/search',
                    'https://opnxng.com/search',
                    'https://paulgo.io/search',
                ];
                $ctx['url'] = $instances[array_rand($instances)] . '?' . http_build_query([
                    'q' => $query, 'format' => 'json', 'language' => 'auto', 'safesearch' => '0',
                ]);
                curl_setopt_array($ch, $common + [
                    CURLOPT_URL => $ctx['url'],
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                ]);
                return [$ch, $ctx];
        }
        curl_close($ch);
        return [null, $ctx];
    }

    /**
     * 🧩 تجزیه پاسخ خام یک ارائه‌دهنده (برای مسابقه موازی)
     */
    private function parseProviderResponse(string $provider, string $body, int $limit, array $ctx): array
    {
        switch ($provider) {
            case 'wikipedia':         return $this->parseWikipediaJson($body, $limit);
            case 'duckduckgo_html':   return $this->parseDdgHtml($body, $limit);
            case 'duckduckgo_lite':   return $this->parseDdgLite($body, $limit);
            case 'mojeek':            return $this->parseMojeekHtml($body, $limit);
            case 'bing_html':         return $this->parseBingHtml($body, $limit);
            case 'startpage':         return $this->parseStartpageHtml($body, $limit);
            /* ════════ 🆕 v2.15: پارسرهای ارائه‌دهنده‌های جدید ════════ */
            case 'google_html':       return $this->parseGoogleHtml($body, $limit);
            case 'yandex_html':       return $this->parseYandexHtml($body, $limit);
            case 'ecosia_html':       return $this->parseEcosiaHtml($body, $limit);
            case 'brave_html':        return $this->parseBraveHtml($body, $limit);
            case 'searx_json':        return $this->parseSearxJson($body, $limit);
        }
        throw new RuntimeException('ارائه‌دهنده ناشناخته: ' . $provider);
    }

    /**
     * 📊 v1.2: نهایی‌سازی نتایج — استخراج تاریخ (تازگی) + رتبه‌بندی اعتماد دامنه
     */
    private function finalizeResults(string $query, array $results, int $limit, string $provider): array
    {
        foreach ($results as &$r) {
            $r['freshness'] = $this->extractDateHint((string)($r['snippet'] ?? '') . ' ' . (string)($r['title'] ?? ''));
            $r['trust'] = $this->domainTrust((string)($r['url'] ?? ''));
        }
        unset($r);
        return [
            'query'    => $query,
            'results'  => array_slice($results, 0, $limit),
            'provider' => $provider,
            'fresh'    => true,
        ];
    }

    /** 📅 v1.2: استخراج اشاره تاریخ از متن (شمسی/میلادی/نسبی) */
    private function extractDateHint(string $text): ?string
    {
        if ($text === '') { return null; }
        // الگوهای نسبی انگلیسی
        if (preg_match('#\b(\d{1,2})\s*(hour|day|week|month|year)s?\s*ago#i', $text, $m)) {
            $map = ['hour' => 'ساعت', 'day' => 'روز', 'week' => 'هفته', 'month' => 'ماه', 'year' => 'سال'];
            return $m[1] . ' ' . $map[strtolower($m[2])] . ' پیش';
        }
        // تاریخ میلادی 2024/2025/2026
        if (preg_match('#\b(20[12][0-9])/([01]?[0-9])/([0-3]?[0-9])\b#', $text, $m)) {
            return $m[0];
        }
        // سال شمسی ۱۴۰۳/۱۴۰۴
        if (preg_match('#\b۱۴۰[۰-۹]/[۰-۹]+/[۰-۹]+#u', $text, $m)) {
            return $m[0];
        }
        return null;
    }

    /** 🏛️ v1.2: اعتماد دامنه — دامنه‌های معتبر امتیاز بالاتر (برای رتبه‌بندی تحقیق) */
    private function domainTrust(string $url): int
    {
        $host = $this->hostOf($url);
        if ($host === '') { return 0; }
        $high = ['wikipedia.org', 'github.com', 'microsoft.com', 'samsung.com', 'lg.com', 'bosch-home.com',
                 'sony.com', 'panasonic.com', 'electrolux.com', 'whirlpool.com', 'iran.ir', 'psql.ir'];
        foreach ($high as $h) {
            if ($host === $h || substr($host, -strlen('.' . $h)) === '.' . $h) { return 90; }
        }
        // دامنه‌های دولتی/آموزشی/سازمانی هر کشور
        if (preg_match('/\.(gov|edu|org|ac)\.[a-z]{2}$/i', $host) || preg_match('/\.(gov|edu)$/i', $host)) { return 75; }
        // پرتال‌های معروف فنی فارسی
        $mid = ['digikala.com', 'zoomit.ir', 'gsmarena.com', 'howstuffworks.com', 'wikihow.com',
                'zoomit.com', 'khabaronline.ir', 'isna.ir', 'mehrnews.com'];
        foreach ($mid as $h) {
            if ($host === $h || substr($host, -strlen('.' . $h)) === '.' . $h) { return 60; }
        }
        return 30;
    }

    /** ترتیب زنجیره ارائه‌دهندگان (کلیددارها اول، رایگان‌ها بعد) */
    private function providerChain(): array
    {
        if (!empty($this->cfg['providers']) && is_array($this->cfg['providers'])) {
            return array_values(array_filter($this->cfg['providers'], [$this, 'providerAvailable']));
        }
        $chain = [];
        if ($this->providerAvailable('serpapi'))    { $chain[] = 'serpapi'; }
        if ($this->providerAvailable('google_cse')) { $chain[] = 'google_cse'; }
        if ($this->providerAvailable('bing_api'))   { $chain[] = 'bing_api'; }
        /* 🆕 v2.15: گوگل و یاندکس اول رایگان‌ها — بهترین پوشش از هاست‌های ایرانی
           (تست زنده: DDG/Mojeek از بسیاری هاست‌ها بلاک/ناتمام هستند) */
        $chain[] = 'google_html';
        $chain[] = 'yandex_html';
        $chain[] = 'wikipedia';
        $chain[] = 'duckduckgo_html';
        $chain[] = 'duckduckgo_lite';
        $chain[] = 'mojeek';
        $chain[] = 'startpage';
        $chain[] = 'bing_html';
        $chain[] = 'ecosia_html';
        $chain[] = 'brave_html';
        $chain[] = 'searx_json';
        return $chain;
    }

    /** آیا ارائه‌دهنده کلید معتبری دارد؟ (رایگان‌ها همیشه در دسترس) */
    private function providerAvailable(string $provider): bool
    {
        switch ($provider) {
            case 'serpapi':         return trim((string)$this->cfg['serpapi_key']) !== '';
            case 'google_cse':      return trim((string)$this->cfg['google_cse_key']) !== '' && trim((string)$this->cfg['google_cse_cx']) !== '';
            case 'bing_api':        return trim((string)$this->cfg['bing_api_key']) !== '';
            case 'wikipedia':
            case 'startpage':
            case 'duckduckgo_html':
            case 'duckduckgo_lite':
            case 'mojeek':
            case 'bing_html':
            case 'google_html':      /* 🆕 v2.15 */
            case 'yandex_html':      /* 🆕 v2.15 */
            case 'ecosia_html':      /* 🆕 v2.15 */
            case 'brave_html':       /* 🆕 v2.15 */
            case 'searx_json':       /* 🆕 v2.15 */
                return true;
        }
        return false;
    }

    /** اجرای یک ارائه‌دهنده مشخص */
    private function runProvider(string $provider, string $query, int $limit): array
    {
        switch ($provider) {
            case 'serpapi':         return $this->searchSerpApi($query, $limit);
            case 'google_cse':      return $this->searchGoogleCse($query, $limit);
            case 'bing_api':        return $this->searchBingApi($query, $limit);
            case 'wikipedia':       return $this->searchWikipedia($query, $limit);
            case 'duckduckgo_html': return $this->searchDuckDuckGoHtml($query, $limit);
            case 'duckduckgo_lite': return $this->searchDuckDuckGoLite($query, $limit);
            case 'mojeek':          return $this->searchMojeek($query, $limit);
            case 'startpage':       return $this->searchStartpage($query, $limit);
            case 'bing_html':       return $this->searchBingHtml($query, $limit);
            /* 🆕 v2.15: اجرای ترتیبی ارائه‌دهنده‌های جدید (fallback بدون curl_multi) */
            case 'google_html':
            case 'yandex_html':
            case 'ecosia_html':
            case 'brave_html':
            case 'searx_json':      return $this->searchGenericProvider($provider, $query, $limit);
        }
        throw new RuntimeException('ارائه‌دهنده ناشناخته: ' . $provider);
    }

    /**
     * 🆕 v2.15: اجرای ترتیبی یک ارائه‌دهنده جدید از روی سازنده درخواست مشترک
     * (URL را از buildProviderRequest می‌سازد، با httpGet می‌گیرد و با پارسر مربوط تجزیه می‌کند)
     */
    private function searchGenericProvider(string $provider, string $query, int $limit): array
    {
        /* ساخت URL با همان منطق مسابقه — از یک handle موقت فقط برای ساخت آپشن‌ها */
        $urls = [
            'google_html' => 'https://www.google.com/search?' . http_build_query(['q' => $query, 'num' => (string)max(10, $limit), 'hl' => 'en', 'gbv' => '1']),
            'yandex_html' => 'https://yandex.com/search/?' . http_build_query(['text' => $query]),
            'ecosia_html' => 'https://www.ecosia.org/search?' . http_build_query(['q' => $query]),
            'brave_html'  => 'https://search.brave.com/search?' . http_build_query(['q' => $query, 'source' => 'web']),
        ];
        if ($provider === 'searx_json') {
            $instances = ['https://searx.be/search', 'https://search.inetol.net/search', 'https://priv.au/search', 'https://opnxng.com/search', 'https://paulgo.io/search'];
            $urls['searx_json'] = $instances[array_rand($instances)] . '?' . http_build_query(['q' => $query, 'format' => 'json', 'language' => 'auto', 'safesearch' => '0']);
        }
        $url = $urls[$provider] ?? '';
        if ($url === '') {
            throw new RuntimeException('URL ارائه‌دهنده ساخته نشد.');
        }
        [$ok, $status, $body, $error] = $this->httpGet($url, $provider === 'google_html' ? ['Accept-Language: en-US,en;q=0.9'] : []);
        if (!$ok || $status !== 200) {
            throw new RuntimeException('پاسخ ' . $status . ($error ? ' (' . $error . ')' : ''));
        }
        return $this->parseProviderResponse($provider, $body, $limit, ['method' => 'GET', 'url' => $url]);
    }

    /* ==================================================
     * 🏭 ارائه‌دهندگان — API های کلیددار
     * ================================================== */

    private function searchSerpApi(string $query, int $limit): array
    {
        $url = 'https://serpapi.com/search.json?' . http_build_query([
            'q'        => $query,
            'hl'       => 'fa',
            'gl'       => 'ir',
            'num'      => $limit,
            'api_key'  => $this->cfg['serpapi_key'],
        ]);
        [$ok, $status, $body, $error] = $this->httpGet($url);
        if (!$ok) { throw new RuntimeException($error ?: "HTTP {$status}"); }
        $data = json_decode($body, true);
        $out = [];
        foreach (($data['organic_results'] ?? []) as $r) {
            $out[] = [
                'title'   => $this->cleanText((string)($r['title'] ?? '')),
                'url'     => (string)($r['link'] ?? ''),
                'snippet' => $this->cleanText((string)($r['snippet'] ?? '')),
                'source'  => $this->hostOf((string)($r['link'] ?? '')),
                'rank'    => (int)($r['position'] ?? 0),
            ];
        }
        return $out;
    }

    private function searchGoogleCse(string $query, int $limit): array
    {
        $url = 'https://www.googleapis.com/customsearch/v1?' . http_build_query([
            'q'       => $query,
            'num'     => min(10, $limit),
            'hl'      => 'fa',
            'key'     => $this->cfg['google_cse_key'],
            'cx'      => $this->cfg['google_cse_cx'],
        ]);
        [$ok, $status, $body, $error] = $this->httpGet($url);
        if (!$ok) { throw new RuntimeException($error ?: "HTTP {$status}"); }
        $data = json_decode($body, true);
        $out = [];
        foreach (($data['items'] ?? []) as $i => $r) {
            $out[] = [
                'title'   => $this->cleanText((string)($r['title'] ?? '')),
                'url'     => (string)($r['link'] ?? ''),
                'snippet' => $this->cleanText((string)($r['snippet'] ?? '')),
                'source'  => $this->hostOf((string)($r['link'] ?? '')),
                'rank'    => $i + 1,
            ];
        }
        return $out;
    }

    private function searchBingApi(string $query, int $limit): array
    {
        $url = 'https://api.bing.microsoft.com/v7.0/search?' . http_build_query([
            'q'      => $query,
            'count'  => $limit,
            'mkt'    => 'fa-IR',
            'setLang'=> 'fa',
        ]);
        [$ok, $status, $body, $error] = $this->httpGet($url, [
            'Ocp-Apim-Subscription-Key: ' . $this->cfg['bing_api_key'],
        ]);
        if (!$ok) { throw new RuntimeException($error ?: "HTTP {$status}"); }
        $data = json_decode($body, true);
        $out = [];
        foreach (($data['webPages']['value'] ?? []) as $i => $r) {
            $out[] = [
                'title'   => $this->cleanText((string)($r['name'] ?? '')),
                'url'     => (string)($r['url'] ?? ''),
                'snippet' => $this->cleanText((string)($r['snippet'] ?? '')),
                'source'  => $this->hostOf((string)($r['url'] ?? '')),
                'rank'    => $i + 1,
            ];
        }
        return $out;
    }

    /* ==================================================
     * 🏭 ارائه‌دهندگان — رایگان (بدون کلید)
     * ================================================== */

    private function searchDuckDuckGoHtml(string $query, int $limit): array
    {
        // POST به دلیل پایداری بیشتر در برابر فیلتر ربات‌ها
        [$ok, $status, $body, $error] = $this->httpPost('https://html.duckduckgo.com/html/', [
            'q'  => $query,
            'kl' => 'wt-wt',
        ]);
        if (!$ok) { throw new RuntimeException($error ?: "HTTP {$status}"); }
        return $this->parseDdgHtml($body, $limit);
    }

    /** 🧩 تجزیه پاسخ HTML نتایج DuckDuckGo */
    public function parseDdgHtml(string $body, int $limit): array
    {
        $out = [];
        if (preg_match_all('#<a[^>]+class="[^"]*result__a[^"]*"[^>]+href="([^"]+)"[^>]*>(.*?)</a>#is', $body, $links, PREG_SET_ORDER)) {
            preg_match_all('#<a[^>]+class="[^"]*result__snippet[^"]*"[^>]*>(.*?)</a>#is', $body, $snips);
            foreach ($links as $i => $m) {
                if ($i >= $limit) { break; }
                $url = $this->decodeDdgRedirect(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
                if ($url === '') { continue; }
                $out[] = [
                    'title'   => $this->cleanText($m[2]),
                    'url'     => $url,
                    'snippet' => isset($snips[1][$i]) ? $this->cleanText($snips[1][$i]) : '',
                    'source'  => $this->hostOf($url),
                    'rank'    => $i + 1,
                ];
            }
        }
        return $out;
    }

    private function searchDuckDuckGoLite(string $query, int $limit): array
    {
        $url = 'https://lite.duckduckgo.com/lite/?' . http_build_query(['q' => $query]);
        [$ok, $status, $body, $error] = $this->httpGet($url);
        if (!$ok) { throw new RuntimeException($error ?: "HTTP {$status}"); }
        return $this->parseDdgLite($body, $limit);
    }

    /** 🧩 تجزیه پاسخ Lite نتایج DuckDuckGo (قابل تست آفلاین) */
    public function parseDdgLite(string $body, int $limit): array
    {
        $out = [];
        // لینک نتیجه: <a ... result-link ...> — ترتیب href/class هر چه باشد
        if (preg_match_all('#<a\b([^>]*result-link[^>]*)>(.*?)</a>#is', $body, $links, PREG_SET_ORDER)) {
            preg_match_all('#<td[^>]*result-snippet[^>]*>(.*?)</td>#is', $body, $snips);
            foreach ($links as $i => $m) {
                if (count($out) >= $limit) { break; }
                if (!preg_match('#href=[\'"]([^\'"]+)[\'"]#', $m[1], $h)) { continue; }
                $real = $this->decodeDdgRedirect(html_entity_decode($h[1], ENT_QUOTES, 'UTF-8'));
                if ($real === '') { continue; } // تبلیغ یا لینک داخلی — ولی ایندکس snippet حفظ شود
                $out[] = [
                    'title'   => $this->cleanText($m[2]),
                    'url'     => $real,
                    'snippet' => isset($snips[1][$i]) ? $this->cleanText($snips[1][$i]) : '',
                    'source'  => $this->hostOf($real),
                    'rank'    => count($out) + 1,
                ];
            }
        }
        return $out;
    }

    private function searchBingHtml(string $query, int $limit): array
    {
        $url = 'https://www.bing.com/search?' . http_build_query([
            'q'       => $query,
            'setLang' => 'fa',
            'mkt'     => 'fa-IR',
            'count'   => $limit,
        ]);
        [$ok, $status, $body, $error] = $this->httpGet($url);
        if (!$ok) { throw new RuntimeException($error ?: "HTTP {$status}"); }
        return $this->parseBingHtml($body, $limit);
    }

    /** 📚 Wikipedia — ارائه‌دهنده رسمی و پایدار (رایگان، بدون کلید) — v1.2
     *  ابتدا ویکی فارسی؛ اگر نتیجه کم بود، ویکی انگلیسی هم دقیق می‌شود. */
    private function searchWikipedia(string $query, int $limit): array
    {
        $out = $this->wikipediaLang('fa', $query, $limit);
        if (count($out) < max(2, (int)floor($limit / 2))) {
            // 🌐 تکمیل از ویکی انگلیسی
            $en = $this->wikipediaLang('en', $query, $limit - count($out));
            $out = array_merge($out, $en);
        }
        return $out;
    }

    /** 📚 جستجوی ویکی در یک زبان مشخص */
    private function wikipediaLang(string $lang, string $query, int $limit): array
    {
        if ($limit < 1) { return []; }
        $url = "https://{$lang}.wikipedia.org/w/api.php?" . http_build_query([
            'action'   => 'query',
            'list'     => 'search',
            'srsearch' => $query,
            'srlimit'  => min(50, $limit),
            'format'   => 'json',
            'utf8'     => 1,
        ]);
        [$ok, $status, $body, $error] = $this->httpGet($url);
        if (!$ok) { throw new RuntimeException($error ?: "HTTP {$status}"); }
        return $this->parseWikipediaJson($body, $limit, $lang);
    }

    /** 🧩 تجزیه پاسخ JSON ویکی‌پدیا */
    public function parseWikipediaJson(string $body, int $limit, string $lang = 'fa'): array
    {
        $data = json_decode($body, true);
        $hits = $data['query']['search'] ?? [];
        if (!is_array($hits) || empty($hits)) { return []; }
        $out = [];
        foreach (array_slice($hits, 0, $limit) as $i => $hit) {
            $title = $this->cleanText((string)($hit['title'] ?? ''));
            if ($title === '') { continue; }
            // snippet ویکی شامل HTML brackmark است — پاکسازی
            $snippet = preg_replace('#<[^>]+>#', ' ', (string)($hit['snippet'] ?? ''));
            $timestamp = (string)($hit['timestamp'] ?? '');
            $out[] = [
                'title'     => $title,
                'url'       => "https://{$lang}.wikipedia.org/wiki/" . rawurlencode(str_replace(' ', '_', $title)),
                'snippet'   => $this->cleanText((string)$snippet),
                'source'    => "{$lang}.wikipedia.org",
                'rank'      => $i + 1,
                'timestamp' => $timestamp,
            ];
        }
        return $out;
    }

    /** 🔍 Startpage — جایگزین رایگان با نتایج گوگل (v1.2) */
    private function searchStartpage(string $query, int $limit): array
    {
        [$ok, $status, $body, $error] = $this->httpPost('https://www.startpage.com/sp/search', [
            'query' => $query,
            'cat'   => 'web',
            'language' => 'farsi',
        ]);
        if (!$ok) { throw new RuntimeException($error ?: "HTTP {$status}"); }
        return $this->parseStartpageHtml($body, $limit);
    }

    /** 🧩 تجزیه نتایج HTML استارت‌پیج */
    public function parseStartpageHtml(string $body, int $limit): array
    {
        $out = [];
        // لینک‌های نتیجه استارت‌پیج دارای class="w-gl__result-title" یا در ساختار JSON داخلی
        if (preg_match_all('#<a[^>]+class="[^"]*result-title[^"]*"[^>]+href="([^"]+)"[^>]*>(.*?)</a>#is', $body, $links, PREG_SET_ORDER)) {
            preg_match_all('#<p[^>]+class="[^"]*description[^"]*"[^>]*>(.*?)</p>#is', $body, $snips);
            foreach ($links as $i => $m) {
                if ($i >= $limit) { break; }
                $url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                if (!preg_match('#^https?://#i', $url)) { continue; }
                $out[] = [
                    'title'   => $this->cleanText($m[2]),
                    'url'     => $url,
                    'snippet' => isset($snips[1][$i]) ? $this->cleanText($snips[1][$i]) : '',
                    'source'  => $this->hostOf($url),
                    'rank'    => $i + 1,
                ];
            }
        }
        return $out;
    }

    /* ════════════════════════════════════════════════════════════════
     * 🆕 v2.15: پارسرهای ارائه‌دهنده‌های جدید — پوشش از هاست‌های محدود
     * (تست زنده نشان داد DDG/Mojeek از بسیاری هاست‌ها 202/timeout می‌دهند)
     * ════════════════════════════════════════════════════════════════ */

    /** 🔵 Google — HTML کلاسیک (gbv=1) — بهترین پوشش؛ صفحه ریدایرکت/کنسنت رد می‌شود */
    public function parseGoogleHtml(string $body, int $limit): array
    {
        $out = [];
        /* رد صفحات کنسنت/کپچا/خالی */
        if (mb_strlen($body) < 2500 || stripos($body, 'google.com/search?') === false && stripos($body, '<body') === false) {
            if (mb_strlen($body) < 2500) { return $out; }
        }
        /* لینک‌های ارگانیک: /url?q=... یا href مستقیم داخل h3 */
        if (preg_match_all('#<a[^>]+href="(?:/url\?q=|)(https?://[^"&]+)"[^>]*>.{0,400}?<h3[^>]*>(.*?)</h3>#is', $body, $m, PREG_SET_ORDER)) {
            foreach ($m as $i => $mm) {
                if (count($out) >= $limit) { break; }
                $url = html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8');
                $host = strtolower((string)parse_url($url, PHP_URL_HOST));
                if (preg_match('#google\.|gstatic|blogger\.com|youtube\.com#i', $host)) { continue; }
                /* اسنیپت: نزدیک‌ترین متن بعد از این نتیجه */
                $snippet = '';
                if (preg_match('#<h3[^>]*>' . preg_quote($this->cleanText($mm[2]), '#') . '</h3>(.{0,900}?)</div>#is', $body, $sm)) {
                    $snippet = $this->cleanText($sm[1]);
                }
                $out[] = [
                    'title'   => $this->cleanText($mm[2]),
                    'url'     => $url,
                    'snippet' => mb_substr($snippet, 0, 300),
                    'source'  => $this->hostOf($url),
                    'rank'    => count($out) + 1,
                ];
            }
        }
        return $out;
    }

    /** 🟡 Yandex — HTML SERP (از ایران هم معمولاً در دسترس) */
    public function parseYandexHtml(string $body, int $limit): array
    {
        $out = [];
        if (stripos($body, 'captcha') !== false && stripos($body, 'SmartCaptcha') !== false) { return $out; }
        /* لینک‌های ارگانیک داخل تگ <a class="...Link..." href="http..."> */
        if (preg_match_all('#<a[^>]+href="(https?://[^"]+)"[^>]*>(.*?)</a>#is', $body, $m, PREG_SET_ORDER)) {
            $seen = [];
            foreach ($m as $mm) {
                if (count($out) >= $limit) { break; }
                $url = html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8');
                $host = strtolower((string)parse_url($url, PHP_URL_HOST));
                if ($host === '' || preg_match('#yandex|microsoft|w3\.org#i', $host)) { continue; }
                if (isset($seen[$url])) { continue; }
                $title = $this->cleanText($mm[2]);
                if (mb_strlen($title) < 8) { continue; }
                $seen[$url] = 1;
                $out[] = [
                    'title'   => mb_substr($title, 0, 160),
                    'url'     => $url,
                    'snippet' => '',
                    'source'  => $this->hostOf($url),
                    'rank'    => count($out) + 1,
                ];
            }
        }
        /* اسنیپت‌ها: تگ‌های <span class="organic__url"> یا متن جاری — از تکرار خالی جلوگیری */
        return $out;
    }

    /** 🌱 Ecosia — HTML سمت سرور */
    public function parseEcosiaHtml(string $body, int $limit): array
    {
        $out = [];
        if (preg_match_all('#<a[^>]+class="[^"]*result__link[^"]*"[^>]+href="([^"]+)"[^>]*>(.*?)</a>#is', $body, $m, PREG_SET_ORDER)) {
            preg_match_all('#<p[^>]+class="[^"]*result__snippet[^"]*"[^>]*>(.*?)</p>#is', $body, $snips);
            foreach ($m as $i => $mm) {
                if ($i >= $limit) { break; }
                $url = html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8');
                if (!preg_match('#^https?://#i', $url)) { continue; }
                $out[] = [
                    'title'   => $this->cleanText($mm[2]),
                    'url'     => $url,
                    'snippet' => isset($snips[1][$i]) ? $this->cleanText($snips[1][$i]) : '',
                    'source'  => $this->hostOf($url),
                    'rank'    => $i + 1,
                ];
            }
        }
        /* ساختار جدیدتر Ecosia: data-test-id="result-link" */
        if (!$out && preg_match_all('#<a[^>]+data-test-id="result-link"[^>]+href="([^"]+)"[^>]*>(.*?)</a>#is', $body, $m, PREG_SET_ORDER)) {
            foreach ($m as $i => $mm) {
                if ($i >= $limit) { break; }
                $url = html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8');
                if (!preg_match('#^https?://#i', $url)) { continue; }
                $out[] = [
                    'title'   => $this->cleanText(strip_tags($mm[2])),
                    'url'     => $url,
                    'snippet' => '',
                    'source'  => $this->hostOf($url),
                    'rank'    => $i + 1,
                ];
            }
        }
        return $out;
    }

    /** 🦁 Brave — HTML سمت سرور */
    public function parseBraveHtml(string $body, int $limit): array
    {
        $out = [];
        if (preg_match_all('#<a[^>]+href="(https?://[^"]+)"[^>]*class="[^"]*heading-serpresult[^"]*"[^>]*>(.*?)</a>#is', $body, $m, PREG_SET_ORDER)) {
            preg_match_all('#<div[^>]+class="[^"]*snippet-description[^"]*"[^>]*>(.*?)</div>#is', $body, $snips);
            foreach ($m as $i => $mm) {
                if ($i >= $limit) { break; }
                $url = html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8');
                if (preg_match('#brave\.com#i', (string)parse_url($url, PHP_URL_HOST))) { continue; }
                $out[] = [
                    'title'   => $this->cleanText($mm[2]),
                    'url'     => $url,
                    'snippet' => isset($snips[1][$i]) ? $this->cleanText($snips[1][$i]) : '',
                    'source'  => $this->hostOf($url),
                    'rank'    => $i + 1,
                ];
            }
        }
        /* ساختار جایگزین: snippet داخل <div class="snippet ..."> با لینک标题 */
        if (!$out) {
            if (preg_match_all('#<div[^>]+class="snippet[^"]*"[^>]*>.*?<a[^>]+href="(https?://[^"]+)"[^>]*>(.*?)</a>.*?<div[^>]+class="snippet-description[^"]*"[^>]*>(.*?)</div>#is', $body, $m, PREG_SET_ORDER)) {
                foreach ($m as $i => $mm) {
                    if ($i >= $limit) { break; }
                    $url = html_entity_decode($mm[1], ENT_QUOTES, 'UTF-8');
                    if (preg_match('#brave\.com#i', (string)parse_url($url, PHP_URL_HOST))) { continue; }
                    $out[] = [
                        'title'   => $this->cleanText($mm[2]),
                        'url'     => $url,
                        'snippet' => $this->cleanText($mm[3]),
                        'source'  => $this->hostOf($url),
                        'rank'    => $i + 1,
                    ];
                }
            }
        }
        return $out;
    }

    /** 🔍 SearXNG — JSON API نمونه‌های عمومی */
    public function parseSearxJson(string $body, int $limit): array
    {
        $out = [];
        $data = json_decode(trim($body), true);
        if (!is_array($data) || empty($data['results']) || !is_array($data['results'])) {
            return $out;
        }
        foreach ($data['results'] as $i => $r) {
            if (count($out) >= $limit) { break; }
            $url = (string)($r['url'] ?? '');
            if (!preg_match('#^https?://#i', $url)) { continue; }
            $out[] = [
                'title'   => $this->cleanText((string)($r['title'] ?? '')),
                'url'     => $url,
                'snippet' => $this->cleanText((string)($r['content'] ?? '')),
                'source'  => $this->hostOf($url),
                'rank'    => count($out) + 1,
            ];
        }
        return $out;
    }

    /** 🟠 Mojeek — موتور مستقل با ایندکس خودش (رایگان، بدون کلید) */
    private function searchMojeek(string $query, int $limit): array
    {
        $url = 'https://www.mojeek.com/search?' . http_build_query([
            'q'      => $query,
            'fmt'    => 'html',
            't'      => $limit,
        ]);
        [$ok, $status, $body, $error] = $this->httpGet($url);
        if (!$ok) { throw new RuntimeException($error ?: "HTTP {$status}"); }
        return $this->parseMojeekHtml($body, $limit);
    }

    /** 🧩 تجزیه HTML نتایج Mojeek (قابل تست آفلاین) */
    public function parseMojeekHtml(string $body, int $limit): array
    {
        $out = [];
        // ساختار Mojeek: <ul class="results-standard"><li><h2><a href=...>title</a></h2><p class="s">snippet</p>
        if (preg_match_all('#<li>\s*<h2[^>]*>\s*<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>\s*</h2>(.*?)</li>#is', $body, $items, PREG_SET_ORDER)) {
            foreach ($items as $i => $m) {
                if (count($out) >= $limit) { break; }
                $url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
                if (!preg_match('#^https?://#i', $url)) { continue; }
                $snippet = '';
                if (preg_match('#<p[^>]*class="[^"]*s[^"]*"[^>]*>(.*?)</p>#is', $m[3], $s)) {
                    $snippet = $this->cleanText($s[1]);
                }
                $out[] = [
                    'title'   => $this->cleanText($m[2]),
                    'url'     => $url,
                    'snippet' => $snippet,
                    'source'  => $this->hostOf($url),
                    'rank'    => count($out) + 1,
                ];
            }
        }
        return $out;
    }

    /** 🧩 تجزیه پاسخ HTML نتایج Bing (قابل تست آفلاین) */
    public function parseBingHtml(string $body, int $limit): array
    {
        $out = [];
        if (preg_match_all('#<li class="b_algo".*?</li>#is', $body, $items)) {
            foreach ($items[0] as $i => $block) {
                if ($i >= $limit) { break; }
                if (!preg_match('#<h2[^>]*><a[^>]+href="([^"]+)"[^>]*>(.*?)</a></h2>#is', $block, $m)) { continue; }
                $snippet = '';
                if (preg_match('#<p[^>]*>(.*?)</p>#is', $block, $s)) {
                    $snippet = $this->cleanText($s[1]);
                }
                $out[] = [
                    'title'   => $this->cleanText($m[2]),
                    'url'     => html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'),
                    'snippet' => $snippet,
                    'source'  => $this->hostOf($m[1]),
                    'rank'    => $i + 1,
                ];
            }
        }
        return $out;
    }

    /* ==================================================
     * 🧠 استخراج هوشمند از نتایج
     * ================================================== */

    /** استخراج کلیدواژه‌های ترند (فراوانی وزنی) */
    private function extractTrendKeywords(string $topic, string $corpus): array
    {
        $topicWords = array_flip(TextProcessor::tokenize($topic, true));
        $scores = [];
        $words = TextProcessor::tokenize($corpus, true);
        foreach ($words as $w) {
            if (mb_strlen($w) < 3 || isset($topicWords[$w])) { continue; }
            $scores[$w] = ($scores[$w] ?? 0) + 1;
        }
        arsort($scores);

        $out = [];
        foreach (array_slice($scores, 0, 12, true) as $word => $count) {
            if ($count < 2) { break; } // فقط واژه‌های تکرارشونده
            $out[] = ['keyword' => $word, 'frequency' => $count];
        }
        return array_slice($out, 0, 8);
    }

    /** استخراج سؤالات واقعی کاربران از نتایج */
    private function extractQuestions(array $results): array
    {
        $questions = [];
        foreach ($results as $r) {
            foreach ([$r['title'] ?? '', $r['snippet'] ?? ''] as $text) {
                $text = trim(strip_tags((string)$text));
                if ($text === '') { continue; }
                if (preg_match('/^(چرا|چطور|چگونه|آیا|کدام|چند|چه)/u', $text) || mb_strpos($text, '؟') !== false) {
                    if (preg_match('/^([^.!؟\n]*؟)/u', $text, $m)) {
                        $q = trim($m[1]);
                    } else {
                        $q = mb_substr($text, 0, 90);
                    }
                    $q = $this->cleanText($q);
                    if (mb_strlen($q) >= 10 && mb_strlen($q) <= 120) {
                        $questions[$q] = true;
                    }
                }
            }
        }
        return array_values(array_slice(array_keys($questions), 0, 8));
    }

    /** استخراج جملات حاوی داده عددی (قیمت/درصد/مدت/سال) */
    private function extractFacts(array $results): array
    {
        $facts = [];
        foreach ($results as $r) {
            $text = trim(strip_tags((string)($r['snippet'] ?? '')));
            if ($text === '') { continue; }
            foreach (preg_split('/[.!؟\n]/u', $text) as $sentence) {
                $sentence = $this->cleanText($sentence);
                if (mb_strlen($sentence) < 15 || mb_strlen($sentence) > 160) { continue; }
                if (preg_match('/\d/u', $sentence) && preg_match('/(تومان|ریال|درصد|٪|سال|ساعت|ماه|هفته|روز|برابر|میلیون|هزار)/u', $sentence)) {
                    $facts[$sentence] = true;
                }
            }
        }
        return array_values(array_slice(array_keys($facts), 0, 6));
    }

    /** ساخت خلاصه فارسی تحقیق */
    private function buildSummary(string $topic, array $keywords, array $questions, array $facts, array $sources, int $took): string
    {
        $parts = ['تحقیق آنلاین درباره «' . $topic . '» با ' . count($sources) . ' منبع در ' . $took . ' میلی‌ثانیه انجام شد.'];
        if ($keywords) {
            $parts[] = 'کلیدواژه‌های پرتکرار وب: ' . implode('، ', array_column($keywords, 'keyword')) . '.';
        }
        if ($questions) {
            $parts[] = count($questions) . ' سؤال واقعی کاربران شناسایی شد که برای بخش پرسش‌های متداول و ساختار محتوا قابل استفاده است.';
        }
        if ($facts) {
            $parts[] = count($facts) . ' داده عددی تازه استخراج شد.';
        }
        return implode(' ', $parts);
    }

    /**
     * 🎯 ساخت فرصت‌های کلیدواژه long-tail — ترکیب موضوع با ترندها
     * برای عنوان‌ها، H2ها و برنامه تولید محتوا
     */
    private function buildOpportunities(string $topic, array $keywords, array $questions): array
    {
        $out = [];
        $templates = [
            '{topic} در تهران',
            'هزینه {topic}',
            '{topic} + قیمت قطعات',
            'آموزش {topic} گام‌به‌گام',
            '{topic} در منزل',
            'علت و رفع {topic}',
        ];
        foreach ($templates as $tpl) {
            $out[] = str_replace('{topic}', $topic, $tpl);
        }
        // ترکیب با ۳ ترند برتر وب
        foreach (array_slice($keywords, 0, 3) as $kw) {
            $k = is_array($kw) ? (string)($kw['keyword'] ?? '') : (string)$kw;
            if ($k !== '' && mb_strpos($topic, $k) === false) {
                $out[] = $topic . ' ' . $k;
            }
        }
        // از سؤالات واقعی: حذف واژه پرسشی → عبارت کلیدواژه‌ای
        foreach (array_slice($questions, 0, 2) as $q) {
            $phrase = trim(preg_replace('/^(چرا|چطور|چگونه|آیا|کدام|چند)\s+/u', '', (string)$q));
            $phrase = trim(preg_replace('/\s*(است|هست|می‌شود|می شود)[؟?.]*/u', '', $phrase));
            if (mb_strlen($phrase) >= 8) {
                $out[] = $phrase;
            }
        }
        return array_values(array_slice(array_unique($out), 0, 10));
    }

    /* ==================================================
     * 🌐 HTTP + امنیت + نرخ + ابزار
     * ================================================== */

    /** بررسی فعال بودن و افزونه curl */
    private function guard(): void
    {
        if (!$this->cfg['enabled']) {
            throw new RuntimeException('جستجوی آنلاین وب غیرفعال است — از تنظیمات (websearch_settings) فعال کنید.');
        }
        if (!extension_loaded('curl')) {
            throw new RuntimeException('افزونه cURL سرور در دسترس نیست — جستجوی وب ممکن نیست.');
        }
    }

    /** اعمال محدودیت نرخ ساعتی */
    private function throttle(): void
    {
        $bucket = $this->rateBucket();
        if ($bucket['count'] >= (int)$this->cfg['rate_per_hour']) {
            throw new RuntimeException('سقف جستجوی وب در این ساعت پر شده است — بعداً تلاش کنید.');
        }
    }

    /** سطل نرخ: فایل شمارنده با پاکسازی خودکار قدیمی‌ها */
    private function rateBucket(): array
    {
        $file = CACHE_PATH . '/websearch-rate.json';
        $now = time();
        $data = ['hits' => []];
        if (is_file($file)) {
            $decoded = json_decode((string)@file_get_contents($file), true);
            if (is_array($decoded) && isset($decoded['hits'])) { $data = $decoded; }
        }
        $data['hits'] = array_values(array_filter($data['hits'], static function ($t) use ($now) {
            return is_numeric($t) && ($now - (int)$t) < 3600;
        }));
        return ['file' => $file, 'count' => count($data['hits']), 'data' => $data];
    }

    /** ثبت یک جستجو در سطل نرخ */
    private function rateHit(): void
    {
        $bucket = $this->rateBucket();
        $bucket['data']['hits'][] = time();
        @file_put_contents($bucket['file'], json_encode($bucket['data']), LOCK_EX);
    }

    /**
     * 🛡️ پاکسازی و اعتبارسنجی URL (محافظت SSRF)
     * فقط http/https + میزبان عمومی
     */
    private function sanitizeUrl(string $url): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
        $parts = parse_url($url);
        if (!in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            throw new RuntimeException('آدرس نامعتبر است — فقط http/https مجاز است.');
        }
        $host = strtolower($parts['host']);
        $badHosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0', 'metadata.google.internal'];
        if (in_array($host, $badHosts, true)
            || preg_match('/\.local$/i', $host)
            || preg_match('/^10\.|^192\.168\.|^172\.(1[6-9]|2\d|3[01])\.|^169\.254\./', $host)) {
            throw new RuntimeException('دسترسی به میزبان داخلی مجاز نیست.');
        }
        return $url;
    }

    /** GET با cURL — خروجی: [ok, status, body, error] — 🔁 با یک تلاش مجدد در خطای شبکه
     *
     * ⚠️🚨 باگ تاریخی v2.2→v2.16 (رفع v2.17): این کلوژر «static function» بود اما
     * داخلش از $this->cfg و $this->userAgent() استفاده می‌کرد → در PHP 8 با
     * «Using $this when not in object context» همیشه می‌ترکید → fetchPageText و
     * searchGenericProvider (هر ۵ ارائه‌دهنده جدید) کاملاً مرده بودند — ریشه واقعی
     * «خطایاب هیچ کدی پیدا نکرد» و «فیلدهای ناقص از اسنیپت‌های ضعیف». کلوژر معمولی
     * شد تا $this را از زمینه شیء بگیرد.
     */
    private function httpGet(string $url, array $headers = [], ?int $timeout = null, bool $minimal = false): array
    {
        $attempt = function () use ($url, $headers, $timeout, $minimal) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => (int)$this->cfg['connect_timeout'],
                CURLOPT_TIMEOUT        => $timeout ?? (int)$this->cfg['timeout'],
                CURLOPT_ENCODING       => '',
                CURLOPT_SSL_VERIFYPEER => true,
            ];
            if ($minimal) {
                /* 🆕 v1.4: حالت حداقلی — برای r.jina.ai که UA مرورگرگونه را با
                   چالش Cloudflare پاسخ می‌دهد اما کلاینت‌های ساده (UA پیش‌فرض
                   curl) را آزاد می‌گذارد. تست زنده: UA کروم = 403، curl = 200 */
                if (!empty($headers)) {
                    $opts[CURLOPT_HTTPHEADER] = $headers;
                }
            } else {
                $opts[CURLOPT_USERAGENT] = $this->userAgent();
                $opts[CURLOPT_HTTPHEADER] = array_merge([
                    'Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                    'Accept-Language: fa-IR,fa;q=0.9,en;q=0.8',
                ], $headers);
            }
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch) ?: null;
            curl_close($ch);
            return [is_string($body) && $body !== '', $status, is_string($body) ? $body : '', $error];
        };

        $res = $attempt();
        // 🔁 تلاش مجدد: خطای شبکه بدون پاسخ HTTP یا خطای ۵xx گذرا
        if (!$res[0] && ($res[3] || $res[1] >= 500)) {
            usleep(400000); // ۰٫۴ ثانیه
            $res = $attempt();
        }
        return $res;
    }

    /** POST فرم با cURL — خروجی: [ok, status, body, error] */
    private function httpPost(string $url, array $form): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($form),
            CURLOPT_CONNECTTIMEOUT => (int)$this->cfg['connect_timeout'],
            CURLOPT_TIMEOUT        => (int)$this->cfg['timeout'],
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => $this->userAgent(),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept-Language: fa-IR,fa;q=0.9,en;q=0.8',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch) ?: null;
        curl_close($ch);
        return [is_string($body) && $body !== '', $status, is_string($body) ? $body : '', $error];
    }

    /** چرخش User-Agent برای پایداری */
    private function userAgent(): string
    {
        static $agents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:126.0) Gecko/20100101 Firefox/126.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
        ];
        return $agents[mt_rand(0, count($agents) - 1)];
    }

    /** دیکود لینک ریدایرکت DuckDuckGo (uddg=) — تبلیغات داخلی رد می‌شوند */
    private function decodeDdgRedirect(string $href): string
    {
        $href = trim($href);
        if ($href === '') { return ''; }
        if (preg_match('#uddg=([^&]+)#', $href, $m)) {
            $url = urldecode($m[1]);
            // 🚫 رد تبلیغات (y.js) و صفحات خود DuckDuckGo
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            if ($host === 'duckduckgo.com' || strpos($host, '.duckduckgo.com') !== false) {
                return '';
            }
            return $url;
        }
        if (preg_match('#^https?://#i', $href) && strpos($href, 'duckduckgo.com') === false) {
            return $href;
        }
        return '';
    }

    /** پاکسازی متن HTML → متن ساده */
    private function cleanText(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim((string)$text);
    }

    /** استخراج میزبان از URL */
    private function hostOf(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        return preg_replace('#^www\.#', '', strtolower((string)$host));
    }
}
