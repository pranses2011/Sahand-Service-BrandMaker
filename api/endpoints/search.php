<?php
/**
 * 🔍 اندپوینت جستجوی سایت برند (v2.34 — P1 #11)
 * ==============================================
 * جستجوی مقالات (عنوان/خلاصه/محتوا/تگ) + صفحات برند، با:
 *   - نرمال‌سازی فارسی (ي→ی، ك→ک، حذف نیم‌فاصله، ارقام یکسان‌سازی، حذف اعراب)
 *   - رتبه‌بندی وزنی: عنوان ×۵ > خلاصه ×۳ > تگ ×۳ > محتوا ×۱ + جایگاه اولین تکرار
 *   - هایلایت عبارت در خلاصه نتیجه (سمت کلاینت با <mark>)
 *   - صفحه‌بندی + سقف طول کوئری + ضد SQL Injection (prepared + whitelist فیلدها)
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

/**
 * 🔤 نرمال‌سازی متن فارسی برای جستجو — یکسان‌سازی گونه‌های نگارشی
 * (عربی/فارسی، نیم‌فاصله، ارقام فارسی/عربی/لاتین، اعراب، فاصله‌های تکراری)
 */
function api_search_normalize(string $text): string
{
    $text = mb_strtolower(trim($text));
    // حروف عربی → فارسی
    $text = str_replace(['ي', 'ك', 'ة', 'ؤ', 'إ', 'أ', 'ٱ'], ['ی', 'ک', 'ه', 'و', 'ا', 'ا', 'ا'], $text);
    // ارقام عربی/فارسی → لاتین (جستجوی «۲۱» = «21»)
    $text = str_replace(
        ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
        $text
    );
    // حذف اعراب (بدون فاصله — داخل کلمه) + نیم‌فاصله/کاراکترهای جهت‌دهی → فاصله
    $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $text) ?? $text;
    $text = preg_replace('/[\x{200C}\x{200E}\x{200F}\x{FEFF}\x{00AD}]/u', ' ', $text) ?? $text;
    // فاصله‌های تکراری → یکی
    return trim((string)preg_replace('/\s+/u', ' ', $text));
}

/**
 * 🧮 شمارش تکرار یک عبارت در متن نرمال‌شده (برای رتبه‌بندی)
 */
function api_search_count_hits(string $haystack, string $needle): int
{
    if ($needle === '') {
        return 0;
    }
    $cnt = 0;
    $pos = 0;
    while (($pos = mb_strpos($haystack, $needle, $pos)) !== false) {
        $cnt++;
        $pos += mb_strlen($needle);
    }
    return $cnt;
}

/**
 * ✂️ بریدن خلاصه حول اولین تکرار عبارت (زمینه‌محور — نه همیشه از ابتدای متن)
 */
function api_search_excerpt_around(string $text, string $needle, int $radius = 60): string
{
    $text = trim((string)preg_replace('/\s+/u', ' ', strip_tags($text)));
    if ($needle === '' || mb_strlen($text) <= $radius * 2) {
        return mb_substr($text, 0, $radius * 2 + 20);
    }
    $pos = mb_strpos($text, $needle);
    if ($pos === false) {
        return mb_substr($text, 0, $radius * 2 + 20);
    }
    $start = max(0, $pos - $radius);
    $cut = mb_substr($text, $start, $radius * 2 + mb_strlen($needle) + 10);
    return ($start > 0 ? '… ' : '') . $cut . ' …';
}

/**
 * 🔍 اجرای جستجو برای یک برند
 * مسیر API: GET brand/{id}/search?q=...&page=1&per_page=10
 */
function api_brand_search(int $brandId, string $q, int $page = 1, int $perPage = 10): void
{
    $db = Database::getInstance();
    $page = max(1, $page);
    $perPage = min(30, max(3, $perPage));

    $qNorm = api_search_normalize($q);
    if (mb_strlen($qNorm) < 2) {
        json_response(['success' => true, 'data' => [], 'meta' => [
            'total' => 0, 'page' => $page, 'per_page' => $perPage, 'pages' => 0,
            'query' => $q, 'hint' => 'عبارت جستجو باید حداقل ۲ حرف باشد',
        ]]);
    }

    $brand = $db->fetch('SELECT name_fa, name_en FROM brands WHERE id = ? AND is_active = 1', [$brandId]);
    if (!$brand) {
        json_response(['success' => false, 'error' => 'برند یافت نشد'], 404);
    }

    /* 🎯 توکن‌های جستجو: عبارت کامل + کلمات جدا (تک‌حرفی‌ها حذف) */
    $tokens = array_values(array_unique(array_filter(
        array_merge([$qNorm], preg_split('/\s+/u', $qNorm) ?: []),
        static fn ($t) => is_string($t) && mb_strlen($t) >= 2
    )));

    /* ─── ۱. مقالات منتشرشده این برند ───
       LIKE برای هر توکن روی فیلدهای متنی؛ نرمال‌سازی داخل SQL با REPLACE
       تا «ي/ك/نیم‌فاصله» نسخه‌های مختلف هم شامل شوند. */
    $sqlNormTitle = "REPLACE(REPLACE(REPLACE(LOWER(title),'ي','ی'),'ك','ک'),'\u{200C}',' ')";
    $sqlNormExcerpt = "REPLACE(REPLACE(REPLACE(LOWER(excerpt),'ي','ی'),'ك','ک'),'\u{200C}',' ')";
    $sqlNormContent = "REPLACE(REPLACE(REPLACE(LOWER(content),'ي','ی'),'ك','ک'),'\u{200C}',' ')";

    $where = [];
    $params = [];
    foreach ($tokens as $t) {
        $like = '%' . $t . '%';
        $where[] = "({$sqlNormTitle} LIKE ? OR {$sqlNormExcerpt} LIKE ? OR {$sqlNormContent} LIKE ?)";
        array_push($params, $like, $like, $like);
    }
    $whereSql = implode(' OR ', $where);

    $articles = $db->fetchAll(
        "SELECT id, title, slug, excerpt, content, tags, published_at
         FROM brand_articles
         WHERE brand_id = ? AND status = 'published' AND ({$whereSql})
         ORDER BY published_at DESC LIMIT 120",
        array_merge([$brandId], $params)
    );

    /* ─── ۲. صفحات برند (متن داخل چیدمان) ─── */
    $pages = $db->fetchAll(
        'SELECT id, page_type, title, content, layout_json FROM brand_pages WHERE brand_id = ? AND is_active = 1',
        [$brandId]
    );
    $pageResults = [];
    foreach ($pages as $pg) {
        // متن قابل جستجوی صفحه = عنوان + متن فیلد content (JSON) + متن چیدمان
        $text = (string)$pg['title'];
        $contentArr = json_decode((string)$pg['content'], true);
        if (is_array($contentArr)) {
            array_walk_recursive($contentArr, static function ($v) use (&$text) {
                if (is_string($v)) { $text .= ' ' . $v; }
            });
        }
        $layoutArr = json_decode((string)$pg['layout_json'], true);
        if (is_array($layoutArr)) {
            array_walk_recursive($layoutArr, static function ($v) use (&$text) {
                if (is_string($v)) { $text .= ' ' . $v; }
            });
        }
        $textNorm = api_search_normalize(strip_tags($text));
        $hits = 0;
        foreach ($tokens as $t) {
            $hits += api_search_count_hits($textNorm, $t);
        }
        if ($hits > 0) {
            $pageResults[] = [
                'type' => 'page',
                'hits' => $hits,
                'score' => $hits * 4, // صفحه‌های اصلی برند وزن بالا
                'title' => $pg['title'] ?: TemplateLibrary::pageTypeLabels()[$pg['page_type']] ?? $pg['page_type'],
                'url' => '/' . ($pg['page_type'] === 'home' ? '' : $pg['page_type']),
                'excerpt' => api_search_excerpt_around($text, $qNorm !== '' ? $qNorm : (string)$tokens[0]),
                'date' => null,
            ];
        }
    }

    /* ─── ۳. رتبه‌بندی مقالات (وزنی) ─── */
    $articleResults = [];
    foreach ($articles as $a) {
        $titleN = api_search_normalize((string)$a['title']);
        $excerptN = api_search_normalize((string)$a['excerpt']);
        $contentN = api_search_normalize(strip_tags((string)$a['content']));
        $tagsN = api_search_normalize(implode(' ', json_decode((string)$a['tags'], true) ?: []));

        $score = 0;
        $firstPos = 999999;
        foreach ($tokens as $t) {
            $hTitle = api_search_count_hits($titleN, $t);
            $hEx = api_search_count_hits($excerptN, $t);
            $hTag = api_search_count_hits($tagsN, $t);
            $hBody = api_search_count_hits($contentN, $t);
            if ($hTitle + $hEx + $hTag + $hBody === 0) {
                continue; // این توکن در هیچ فیلدی نبود (ممکن است فقط در LIKE عربی/فارسی متفاوت بوده باشد)
            }
            $score += $hTitle * 50 + $hEx * 15 + $hTag * 20 + min($hBody, 20) * 3;
            $p = mb_strpos($titleN, $t);
            if ($p !== false) {
                $firstPos = min($firstPos, $p);
            }
        }
        if ($score <= 0) {
            continue;
        }
        $score += max(0, 40 - $firstPos); // هر چه جلوتر در عنوان، بهتر
        $articleResults[] = [
            'type' => 'article',
            'score' => $score,
            'title' => $a['title'],
            'url' => '/blog/' . $a['slug'],
            'url_legacy' => '/blog/article?slug=' . rawurlencode((string)$a['slug']),
            'excerpt' => $a['excerpt'] !== '' && api_search_count_hits($excerptN, $tokens[0]) > 0
                ? api_search_excerpt_around((string)$a['excerpt'], $tokens[0])
                : api_search_excerpt_around((string)$a['content'], $tokens[0]),
            'date' => $a['published_at'],
            'views' => null,
        ];
    }

    /* ─── ۴. ادغام + مرتب‌سازی + صفحه‌بندی ─── */
    $all = array_merge($articleResults, $pageResults);
    usort($all, static fn ($x, $y) => $y['score'] <=> $x['score']);

    $total = count($all);
    $offset = ($page - 1) * $perPage;
    $slice = array_slice($all, $offset, $perPage);

    json_response([
        'success' => true,
        'data'    => $slice,
        'meta'    => [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int)ceil($total / $perPage),
            'query'    => $q,
            'tokens'   => $tokens,
        ],
    ]);
}
