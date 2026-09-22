<?php
/**
 * 🏗️ اندپوینت AJAX فرآیند ساخت سایت برند
 * =========================================
 * هر مرحله به صورت مجزا اجرا و نتیجه JSON برمی‌گردد.
 * مراحل: تحلیل لوگو → تطبیق دانش → محتوای صفحات → مقالات →
 *         FAQ → کدهای خطا → سئو → نهایی‌سازی
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$auth = new Auth();
$auth->requireLogin();

// 📥 ورودی
$input = Router::jsonInput();
$step = (int)($input['step'] ?? 0);
$brandId = (int)($input['brand_id'] ?? 0);

// 🛡️ CSRF برای AJAX
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfHeader) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfHeader)) {
    json_response(['success' => false, 'error' => 'توکن CSRF نامعتبر است.'], 419);
}

$db = Database::getInstance();
$brand = $db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
if (!$brand) {
    json_response(['success' => false, 'error' => 'برند یافت نشد.'], 404);
}

// 📊 شروع ساخت
if ($step === 0) {
    $db->update('brands', ['status' => 'building'], 'id = ?', [$brandId]);
    Logger::activity((int)$_SESSION['user_id'], 'شروع ساخت سایت', $brand['name_fa']);
    json_response(['success' => true, 'message' => 'فرآیند ساخت آغاز شد']);
}

$totalSteps = 8;
$progressBase = ($step - 1) / $totalSteps * 100;

try {
    switch ($step) {
        /* ---------- ۱️⃣ تحلیل لوگو و پالت رنگ ---------- */
        case 1:
            if (empty($brand['logo'])) {
                json_response(['success' => true, 'message' => 'لوگویی برای تحلیل وجود ندارد — پالت پیش‌فرض اعمال شد', 'details' => ['رنگ اصلی' => '#1e40af']]);
            }
            $analyzer = new ColorAnalyzer();
            $analysis = $analyzer->analyze(ROOT_PATH . '/' . $brand['logo']);
            if (!$analysis) {
                json_response(['success' => false, 'error' => 'تحلیل لوگو ناموفق بود — فرمت فایل را بررسی کنید']);
            }
            // 💾 ذخیره پالت
            $db->delete('color_palettes', 'brand_id = ?', [$brandId]);
            $db->insert('color_palettes', [
                'brand_id'        => $brandId,
                'light_palette'   => json_encode($analysis['light'], JSON_UNESCAPED_UNICODE),
                'dark_palette'    => json_encode($analysis['dark'], JSON_UNESCAPED_UNICODE),
                'dominant_colors' => json_encode($analysis['dominant'], JSON_UNESCAPED_UNICODE),
            ]);
            json_response([
                'success' => true,
                'message' => 'پالت رنگ استخراج شد',
                'details' => ['رنگ اصلی' => $analysis['classified']['primary'], 'ثانویه' => $analysis['classified']['secondary'], 'تأکیدی' => $analysis['classified']['accent']],
                'swatches' => $analysis['dominant'],
            ]);

        /* ---------- ۲️⃣ تطبیق برند + ثبت دستگاه‌ها ---------- */
        case 2:
            $brandGen = new BrandInfoGenerator();
            $knowledge = $brandGen->matchBrand($brand['name_fa'], $brand['name_en']);
            $devicesCount = 0;
            if ($knowledge && !empty($knowledge['devices'])) {
                $devicesKnowledge = TextProcessor::loadKnowledge('devices');
                foreach ($knowledge['devices'] as $deviceKey) {
                    if (!isset($devicesKnowledge[$deviceKey])) {
                        continue;
                    }
                    $exists = $db->fetchValue('SELECT COUNT(*) FROM brand_devices WHERE brand_id = ? AND device_key = ?', [$brandId, $deviceKey]);
                    if (!$exists) {
                        $db->insert('brand_devices', [
                            'brand_id'   => $brandId,
                            'device_key' => $deviceKey,
                            'name_fa'    => $devicesKnowledge[$deviceKey]['name_fa'],
                            'icon'       => 'home-appliance/' . ($devicesKnowledge[$deviceKey]['icon'] ?? $deviceKey),
                            'is_active'  => 1,
                        ]);
                        $devicesCount++;
                    }
                }
            }
            json_response([
                'success' => true,
                'message' => $knowledge ? 'برند با پایگاه دانش تطبیق یافت' : 'برند در پایگاه دانش یافت نشد — محتوا با قالب‌های عمومی تولید می‌شود',
                'details' => ['کشور' => $knowledge['country_fa'] ?? '—', 'دستگاه‌های ثبت‌شده' => (string)$devicesCount],
            ]);

        /* ---------- ۳️⃣ تولید محتوای صفحات ---------- */
        case 3:
            $ai = new SahandAI();
            $report = $ai->generateBrandInfo(['brand_id' => $brandId]);

            // 📄 ایجاد تمام صفحات استاندارد (فعال/غیرفعال قابل مدیریت در ویرایش برند)
            $standardPages = [
                'home', 'services', 'service-area', 'warranty', 'blog', 'about-agency',
                'about-brand', 'contact', 'request', 'other-brands', 'error-codes',
                'faq', 'sitemap-page', 'terms', 'privacy',
            ];
            foreach ($standardPages as $pageType) {
                $exists = $db->fetchValue('SELECT COUNT(*) FROM brand_pages WHERE brand_id = ? AND page_type = ?', [$brandId, $pageType]);
                if (!$exists) {
                    $db->insert('brand_pages', [
                        'brand_id'  => $brandId,
                        'page_type' => $pageType,
                        'content'   => json_encode([], JSON_UNESCAPED_UNICODE),
                        'is_active' => 1,
                    ]);
                }
            }
            json_response([
                'success' => true,
                'message' => 'محتوای تمام صفحات تولید شد',
                'details' => [
                    'معرفی برند' => ($report['intro']['word_count'] ?? 0) . ' کلمه',
                    'تاریخچه' => ($report['history']['word_count'] ?? 0) . ' کلمه',
                    'توضیح دستگاه‌ها' => count($report['devices']) . ' دستگاه',
                    'صفحات فعال' => count($standardPages) . ' صفحه',
                ],
            ]);

        /* ---------- ۴️⃣ تولید مقالات اولیه ---------- */
        case 4:
            $ai = new SahandAI();
            $brand = $db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
            $articles = [];
            $topicTypes = ['troubleshooting', 'user_guide', 'maintenance', 'error_codes', 'comparison'];
            foreach ($topicTypes as $i => $topicType) {
                $article = $ai->generateArticle(['brand_id' => $brandId, 'topic_type' => $topicType]);
                $articleId = $ai->saveArticle($brandId, $article, $topicType);
                // اولین مقاله منتشر می‌شود، بقیه زمان‌بندی هفتگی
                if ($i === 0) {
                    $db->update('brand_articles', ['status' => 'published', 'published_at' => date('Y-m-d H:i:s')], 'id = ?', [$articleId]);
                } else {
                    $publishAt = date('Y-m-d H:i:s', strtotime('+' . ($i * 3) . ' days'));
                    $db->update('brand_articles', ['status' => 'scheduled', 'published_at' => $publishAt], 'id = ?', [$articleId]);
                    $db->insert('scheduled_posts', [
                        'brand_id' => $brandId, 'article_id' => $articleId,
                        'task_type' => 'publish_article', 'run_at' => $publishAt,
                    ]);
                }
                $articles[] = $article['title'] . ' (' . $article['word_count'] . ' کلمه)';
            }
            json_response([
                'success' => true,
                'message' => '۵ مقاله یکتا تولید شد',
                'details' => $articles,
            ]);

        /* ---------- ۵️⃣ تولید سوالات متداول ---------- */
        case 5:
            $ai = new SahandAI();
            $faqResult = $ai->generateFaq(['brand_id' => $brandId, 'count' => 12]);
            json_response([
                'success' => true,
                'message' => 'سوالات متداول تولید شد',
                'details' => ['تعداد' => (string)$faqResult['generated']],
            ]);

        /* ---------- ۶️⃣ تولید کدهای خطا ---------- */
        case 6:
            $brand = $db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
            if (!$brand['error_codes_enabled']) {
                json_response(['success' => true, 'message' => 'صفحه کدهای خطا برای این برند غیرفعال است — رد شد', 'details' => []]);
            }
            $count = (new ErrorCodeGenerator())->generateForBrand($brandId);
            json_response([
                'success' => true,
                'message' => 'کدهای خطای رایج دستگاه‌ها ثبت شد',
                'details' => ['تعداد کد' => (string)$count],
            ]);

        /* ---------- ۷️⃣ بهینه‌سازی سئو ---------- */
        case 7:
            $seoGen = new SeoGenerator();
            $analyzer = new SeoAnalyzer();
            $pages = $db->fetchAll('SELECT id, page_type, content FROM brand_pages WHERE brand_id = ?', [$brandId]);
            $brand = $db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
            $vars = ['brand_fa' => $brand['name_fa'], 'brand_en' => $brand['name_en'], 'agency' => Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس', 'brand_domain' => $brand['domain']];
            $scores = [];
            foreach ($pages as $page) {
                $contentData = json_decode($page['content'] ?? '{}', true) ?: [];
                $text = implode("\n", $contentData);
                $seo = $seoGen->generateForPage($page['page_type'], $text, $vars);
                // امتیاز سئو
                $analysis = $analyzer->analyze([
                    'title' => $seo['title'],
                    'meta_description' => $seo['description'],
                    'content' => '<p>' . $text . '</p>',
                    'slug' => $page['page_type'],
                ], $brand['name_fa']);
                $scores[$page['page_type']] = $analysis['score'];
                $db->update('brand_pages', [
                    'title'           => $seo['title'],
                    'seo_title'       => $seo['title'],
                    'seo_description' => $seo['description'],
                    'seo_keywords'    => $seo['keywords'],
                    'og_title'        => $seo['og']['og:title'] ?? null,
                    'og_description'  => $seo['og']['og:description'] ?? null,
                    'seo_score'       => $analysis['score'],
                ], 'id = ?', [$page['id']]);
            }
            // سئوی برند
            $db->update('brands', [
                'seo_title' => $seoGen->title('خدمات تعمیر ' . $brand['name_fa'], $vars),
                'seo_description' => $seoGen->metaDescription('تعمیرات تخصصی ' . $brand['name_fa'] . ' با قطعات اصلی و ضمانت کتبی توسط نمایندگی رسمی', $vars),
            ], 'id = ?', [$brandId]);
            json_response([
                'success' => true,
                'message' => 'سئوی تمام صفحات تولید و بهینه شد',
                'details' => $scores,
            ]);

        /* ---------- ۸️⃣ نهایی‌سازی ---------- */
        case 8:
            $db->update('brands', ['status' => 'published', 'updated_at' => date('Y-m-d H:i:s')], 'id = ?', [$brandId]);
            (new Cache())->flush('brand_' . $brandId);
            Logger::activity((int)$_SESSION['user_id'], 'تکمیل ساخت سایت', $brand['name_fa'] . ' — ' . $brand['domain']);
            json_response([
                'success' => true,
                'message' => '🎉 سایت برند آماده شد!',
                'details' => ['دامنه' => $brand['domain']],
                'finished' => true,
            ]);

        default:
            json_response(['success' => false, 'error' => 'مرحله نامعتبر'], 400);
    }
} catch (Exception $e) {
    Logger::error('خطای مرحله ' . $step . ' ساخت برند ' . $brandId, ['message' => $e->getMessage()]);
    // بازگشت به وضعیت پیش‌نویس برای امکان تلاش مجدد
    $db->update('brands', ['status' => 'draft'], 'id = ?', [$brandId]);
    json_response(['success' => false, 'error' => 'خطا در مرحله ' . $step . ': ' . $e->getMessage()], 500);
}
