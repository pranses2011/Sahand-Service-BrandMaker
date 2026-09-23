<?php
/**
 * 🌐 سرویس جستجوی آنلاین وب — WebSearchService v1.0
 * ================================================
 * قدرت جدید موتور سهند: دسترسی به داده‌های زنده اینترنت
 * برای بهبود محتوا، سئو و مقالات — فقط در صورت لزوم.
 *
 * معماری چند-ارائه‌دهنده با زنجیره جایگزین خودکار:
 *   1️⃣ SerpApi        (اختیاری — نیازمند کلید API)
 *   2️⃣ Google CSE     (اختیاری — نیازمند کلید + CX)
 *   3️⃣ Bing API       (اختیاری — نیازمند کلید اشتراک)
 *   4️⃣ DuckDuckGo HTML (رایگان — بدون کلید، پیش‌فرض)
 *   5️⃣ DuckDuckGo Lite (رایگان — جایگزین)
 *   6️⃣ Bing HTML      (رایگان — آخرین جایگزین)
 *
 * امنیت و پایداری:
 *   🛡️ محافظت SSRF (آدرس‌های داخلی/خصوصی مسدود)
 *   ⏱️ محدودیت نرخ (پیش‌فرض ۶۰ جستجو در ساعت)
 *   ⚡ کش TTL-دار (پیش‌فرض ۳۰ دقیقه)
 *   🔄 جایگزینی خودکار ارائه‌دهنده در صورت خطا
 *
 * تنظیمات (جدول settings — کلید websearch_settings):
 *   enabled, timeout, max_results, cache_ttl, rate_per_hour,
 *   providers[], serpapi_key, google_cse_key, google_cse_cx, bing_api_key
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class WebSearchService
{
    /** @var array تنظیمات ادغام‌شده */
    private $cfg;

    /** @var array خطاهای آخرین عملیات (برای دیباگ) */
    private $lastErrors = [];

    /** @var string|null ارائه‌دهنده موفق آخر */
    private $lastProvider = null;

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
            'rate_per_hour' => 60,
            'providers'     => [],       // خالی = زنجیره خودکار
            'serpapi_key'   => '',
            'google_cse_key'=> '',
            'google_cse_cx' => '',
            'bing_api_key'  => '',
        ], $saved);
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
        if (!$ok) {
            return ['ok' => false, 'url' => $url, 'error' => $error ?: ('HTTP ' . $status), 'took_ms' => (int)round((microtime(true) - $t0) * 1000)];
        }

        // حذف بخش‌های غیرمتنی
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $body);
        $html = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $html);
        $html = preg_replace('#<(nav|header|footer|aside|form|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = preg_replace('#<!--.*?-->#s', ' ', $html);

        // عنوان و توضیحات متا
        $title = '';
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            $title = $this->cleanText($m[1]);
        }
        $desc = '';
        if (preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\'](.*?)["\']#is', $html, $m)) {
            $desc = $this->cleanText($m[1]);
        } elseif (preg_match('#<meta[^>]+content=["\'](.*?)["\'][^>]+name=["\']description["\']#is', $html, $m)) {
            $desc = $this->cleanText($m[1]);
        }

        // تبدیل بلوک‌ها به فاصله و حذف تگ‌ها
        $text = preg_replace('#</(p|div|li|h[1-6]|tr|section|article|blockquote)>#i', "\n", $html);
        $text = preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/\n{3,}/", "

", $text);
        $text = trim($text);
        $text = mb_substr($text, 0, max(500, $maxChars));

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

            $took = (int)round((microtime(true) - $t0) * 1000);
            $summary = $this->buildSummary($topic, $keywords, $questions, $facts, $sources, $took);

            return [
                'topic'     => $topic,
                'keywords'  => $keywords,
                'questions' => $questions,
                'facts'     => $facts,
                'pages'     => $pages,
                'sources'   => $sources,
                'summary'   => $summary,
                'provider'  => $main['provider'],
                'fresh'     => true,
                'took_ms'   => $took,
            ];
        });
    }

    /**
     * 📊 وضعیت سرویس — GET /api/ai/websearch-status
     */
    public function status(): array
    {
        $providers = [];
        foreach (['serpapi', 'google_cse', 'bing_api', 'duckduckgo_html', 'duckduckgo_lite', 'bing_html'] as $p) {
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
     * 🔗 زنجیره ارائه‌دهندگان
     * ================================================== */

    /** اجرای زنده جستجو با جایگزینی خودکار ارائه‌دهنده */
    private function searchLive(string $query, int $limit): array
    {
        $this->throttle();
        $errors = [];
        foreach ($this->providerChain() as $provider) {
            try {
                $results = $this->runProvider($provider, $query, $limit);
                if ($results) {
                    $this->lastProvider = $provider;
                    return [
                        'query'    => $query,
                        'results'  => array_slice($results, 0, $limit),
                        'provider' => $provider,
                        'fresh'    => true,
                    ];
                }
                $errors[] = "{$provider}: نتیجه‌ای استخراج نشد";
            } catch (Exception $e) {
                $errors[] = "{$provider}: " . $e->getMessage();
            }
        }
        $this->lastErrors = array_merge($this->lastErrors, $errors);
        throw new RuntimeException('جستجوی وب در همه ارائه‌دهندگان ناموفق بود — ' . implode(' | ', array_slice($errors, 0, 3)));
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
        $chain[] = 'duckduckgo_html';
        $chain[] = 'duckduckgo_lite';
        $chain[] = 'bing_html';
        return $chain;
    }

    /** آیا ارائه‌دهنده کلید معتبری دارد؟ (رایگان‌ها همیشه در دسترس) */
    private function providerAvailable(string $provider): bool
    {
        switch ($provider) {
            case 'serpapi':         return trim((string)$this->cfg['serpapi_key']) !== '';
            case 'google_cse':      return trim((string)$this->cfg['google_cse_key']) !== '' && trim((string)$this->cfg['google_cse_cx']) !== '';
            case 'bing_api':        return trim((string)$this->cfg['bing_api_key']) !== '';
            case 'duckduckgo_html':
            case 'duckduckgo_lite':
            case 'bing_html':       return true;
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
            case 'duckduckgo_html': return $this->searchDuckDuckGoHtml($query, $limit);
            case 'duckduckgo_lite': return $this->searchDuckDuckGoLite($query, $limit);
            case 'bing_html':       return $this->searchBingHtml($query, $limit);
        }
        throw new RuntimeException('ارائه‌دهنده ناشناخته: ' . $provider);
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

    /** GET با cURL — خروجی: [ok, status, body, error] */
    private function httpGet(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => (int)$this->cfg['connect_timeout'],
            CURLOPT_TIMEOUT        => (int)$this->cfg['timeout'],
            CURLOPT_ENCODING       => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => $this->userAgent(),
            CURLOPT_HTTPHEADER     => array_merge([
                'Accept: text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
                'Accept-Language: fa-IR,fa;q=0.9,en;q=0.8',
            ], $headers),
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch) ?: null;
        curl_close($ch);
        return [is_string($body) && $body !== '', $status, is_string($body) ? $body : '', $error];
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
