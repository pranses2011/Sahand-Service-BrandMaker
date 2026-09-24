<?php
/**
 * ✏️ ویرایش برند — تمام تنظیمات یک سایت برند
 * ============================================
 * تب‌ها: اطلاعات | دستگاه‌ها | صفحات و محتوا | پالت رنگ | سئو | API
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();
$fm = new FileManager();
$brandId = (int)get_param('id');
$brand = $db->fetch('SELECT * FROM brands WHERE id = ?', [$brandId]);
if (!$brand) {
    flash('danger', 'برند یافت نشد.');
    redirect('brands.php');
}

/* ==================================================
 * 💾 پردازش فرم‌ها
 * ================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::enforceCsrf();
    $action = post('action');

    /* ---------- بروزرسانی اطلاعات پایه ---------- */
    if ($action === 'update_info') {
        $update = [
            'name_fa'            => post('name_fa'),
            'name_en'            => post('name_en'),
            'domain'             => post('domain'),
            'error_codes_enabled'=> !empty($_POST['error_codes_enabled']) ? 1 : 0,
            'is_active'          => !empty($_POST['is_active']) ? 1 : 0,
            'updated_at'         => date('Y-m-d H:i:s'),
        ];
        if (!empty($_FILES['logo']['name'])) {
            $upload = $fm->uploadImage($_FILES['logo'], 'logos');
            if ($upload['success']) {
                $update['logo'] = $upload['path'];
            } else {
                flash('danger', 'خطای آپلود لوگو: ' . $upload['error']);
            }
        }
        /* 🔖 v2.12: فاوآیکون اختصاصی برند — اگر فایل جدید آپلود شود */
        if (!empty($_FILES['favicon']['name'])) {
            $favUpload = $fm->uploadImage($_FILES['favicon'], 'logos');
            if ($favUpload['success']) {
                $update['favicon'] = $favUpload['path'];
            } else {
                flash('danger', 'خطای آپلود فاوآیکون: ' . $favUpload['error']);
            }
        }
        $db->update('brands', $update, 'id = ?', [$brandId]);
        Logger::activity((int)$_SESSION['user_id'], 'ویرایش برند', $brand['name_fa']);
        flash('success', '✅ اطلاعات برند بروزرسانی شد.');
        redirect('brand-edit.php?id=' . $brandId);
    }

    /* ---------- 🔖 v2.12: بازگردانی فاوآیکون به لوگوی برند ---------- */
    if ($action === 'reset_favicon') {
        $db->update('brands', ['favicon' => null], 'id = ?', [$brandId]);
        (new Cache())->delete('brand_' . $brandId . '_pages');
        flash('success', '♻️ فاوآیکون حذف شد — از این پس لوگوی برند به‌عنوان فاوآیکون استفاده می‌شود.');
        redirect('brand-edit.php?id=' . $brandId . '&tab=info');
    }

    /* ---------- مدیریت دستگاه‌ها ---------- */
    if ($action === 'update_devices') {
        $deviceIds = (array)($_POST['device_active'] ?? []);
        $featured = (array)($_POST['device_featured'] ?? []);
        foreach ((array)($_POST['device_id'] ?? []) as $i => $deviceId) {
            $deviceId = (int)$deviceId;
            $db->update('brand_devices', [
                'is_active'   => in_array($deviceId, array_map('intval', $deviceIds), true) ? 1 : 0,
                'is_featured' => in_array($deviceId, array_map('intval', $featured), true) ? 1 : 0,
                'sort_order'  => (int)($_POST['device_order'][$i] ?? 0),
                'description' => Validator::sanitizeHtml((string)($_POST['device_desc'][$i] ?? '')),
            ], 'id = ? AND brand_id = ?', [$deviceId, $brandId]);
        }
        flash('success', '✅ لیست دستگاه‌ها بروزرسانی شد.');
        redirect('brand-edit.php?id=' . $brandId . '&tab=devices');
    }

    /* ---------- 🧹 حذف دستگاه‌های تکراری برند (v2.9) ---------- */
    if ($action === 'dedupe_devices') {
        $rows = $db->fetchAll('SELECT * FROM brand_devices WHERE brand_id = ? ORDER BY id', [$brandId]);
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['device_key']][] = $r;
        }
        $dupGroups = array_filter($byKey, fn($g) => count($g) > 1);
        if (!$dupGroups) {
            flash('info', 'هیچ دستگاه تکراری برای این برند یافت نشد.');
            redirect('brand-edit.php?id=' . $brandId . '&tab=devices');
        }

        $devicesRemoved = 0;
        $codesMerged = 0;
        $codesRemoved = 0;
        foreach ($dupGroups as $deviceKey => $group) {
            /* بهترین ردیف: توضیحدار > برجسته > فعال > قدیمی‌ترین */
            usort($group, fn($a, $b) =>
                (($b['description'] ? 1 : 0) <=> ($a['description'] ? 1 : 0))
                ?: ((int)$b['is_featured'] <=> (int)$a['is_featured'])
                ?: ((int)$b['is_active'] <=> (int)$a['is_active'])
                ?: ((int)$a['id'] <=> (int)$b['id'])
            );
            $keep = array_shift($group);
            foreach ($group as $dup) {
                /* ⭐ برجستگی/فعالیت را به ردیف نگه‌داشته‌شده منتقل کن */
                if ((int)$dup['is_featured'] && !(int)$keep['is_featured']) {
                    $db->update('brand_devices', ['is_featured' => 1], 'id = ?', [$keep['id']]);
                }
                if ((int)$dup['is_active'] && !(int)$keep['is_active']) {
                    $db->update('brand_devices', ['is_active' => 1], 'id = ?', [$keep['id']]);
                }
                if (trim((string)$keep['description']) === '' && trim((string)$dup['description']) !== '') {
                    $db->update('brand_devices', ['description' => $dup['description']], 'id = ?', [$keep['id']]);
                }
                $db->delete('brand_devices', 'id = ?', [$dup['id']]);
                $devicesRemoved++;
            }

            /* 🔀 ادغام کدهای خطای تکراری همین دستگاه — کدهای تکراری: فیلدهای خالی
               از رکوردهای دیگر پر می‌شود و رکورد اضافه حذف؛ کدهای غیرتکراری دست‌نخورده */
            $codes = $db->fetchAll(
                'SELECT * FROM error_codes WHERE brand_id = ? AND device_key = ? ORDER BY id',
                [$brandId, $deviceKey]
            );
            $byCode = [];
            foreach ($codes as $cRow) {
                $byCode[strtoupper(trim((string)$cRow['code']))][] = $cRow;
            }
            foreach ($byCode as $codeGroup) {
                if (count($codeGroup) < 2) {
                    continue;
                }
                usort($codeGroup, fn($a, $b) =>
                    (mb_strlen((string)$b['description']) <=> mb_strlen((string)$a['description']))
                        ?: ((int)$a['id'] <=> (int)$b['id'])
                );
                $keepCode = array_shift($codeGroup);
                $fill = [];
                foreach ($codeGroup as $dupCode) {
                    foreach (['title', 'description', 'severity', 'subtype', 'category', 'related_part', 'tech_specs', 'part_location', 'source', 'source_urls'] as $field) {
                        if (trim((string)($keepCode[$field] ?? '')) === '' && trim((string)($dupCode[$field] ?? '')) !== '') {
                            $keepCode[$field] = $dupCode[$field];
                            $fill[$field] = $dupCode[$field];
                        }
                    }
                    /* آرایه‌های JSON: دلایل/راه‌حل — ادغام یکتا */
                    foreach (['causes', 'solutions', 'models'] as $jField) {
                        $a = json_decode((string)($keepCode[$jField] ?? '[]'), true) ?: [];
                        $b = json_decode((string)($dupCode[$jField] ?? '[]'), true) ?: [];
                        $keepCode[$jField] = json_encode(array_values(array_unique(array_merge($a, $b))), JSON_UNESCAPED_UNICODE);
                    }
                    $db->delete('error_codes', 'id = ?', [$dupCode['id']]);
                    $codesRemoved++;
                }
                if ($fill || $codesRemoved) {
                    $db->update('error_codes', [
                        'title'         => $keepCode['title'],
                        'description'   => $keepCode['description'],
                        'severity'      => $keepCode['severity'],
                        'subtype'       => $keepCode['subtype'],
                        'category'      => $keepCode['category'],
                        'related_part'  => $keepCode['related_part'],
                        'tech_specs'    => $keepCode['tech_specs'],
                        'part_location' => $keepCode['part_location'],
                        'causes'        => $keepCode['causes'],
                        'solutions'     => $keepCode['solutions'],
                        'models'        => $keepCode['models'],
                    ], 'id = ?', [$keepCode['id']]);
                    $codesMerged++;
                }
            }
        }
        (new Cache())->delete('brand_' . $brandId . '_pages');
        Logger::activity((int)$_SESSION['user_id'], 'حذف تکراری‌های برند', $brand['name_fa'] . " — {$devicesRemoved} دستگاه");
        flash('success',
            '🧹 پاک‌سازی انجام شد: <b>' . en_to_fa_digits((string)$devicesRemoved) . '</b> دستگاه تکراری حذف، ' .
            '<b>' . en_to_fa_digits((string)$codesMerged) . '</b> کد خطای تکراری ادغام و ' .
            '<b>' . en_to_fa_digits((string)$codesRemoved) . '</b> رکورد اضافه حذف شد.'
        );
        redirect('brand-edit.php?id=' . $brandId . '&tab=devices');
    }

    /* ---------- ویرایش محتوای صفحه ---------- */
    if ($action === 'update_page') {
        $pageId = (int)post('page_id');
        $page = $db->fetch('SELECT id FROM brand_pages WHERE id = ? AND brand_id = ?', [$pageId, $brandId]);
        if ($page) {
            $contentData = json_decode((string)post('page_content_json'), true) ?: [];
            $contentData[post('content_field') ?: 'content'] = Validator::sanitizeHtml((string)($_POST['content_value'] ?? ''));
            $db->update('brand_pages', [
                'content'   => json_encode($contentData, JSON_UNESCAPED_UNICODE),
                'is_active' => !empty($_POST['page_active']) ? 1 : 0,
            ], 'id = ?', [$pageId]);
            (new Cache())->delete('brand_' . $brandId . '_pages');
            flash('success', '✅ محتوای صفحه ذخیره شد.');
        }
        redirect('brand-edit.php?id=' . $brandId . '&tab=pages');
    }

    /* ---------- ذخیره پالت رنگ (ویرایش دستی) ---------- */
    if ($action === 'update_palette') {
        $light = [];
        $dark = [];
        foreach ((array)($_POST['light_vars'] ?? []) as $i => $var) {
            $light[clean_input($var)] = clean_input(($_POST['light_vals'] ?? [])[$i] ?? '');
        }
        foreach ((array)($_POST['dark_vars'] ?? []) as $i => $var) {
            $dark[clean_input($var)] = clean_input(($_POST['dark_vals'] ?? [])[$i] ?? '');
        }
        $exists = $db->fetchValue('SELECT COUNT(*) FROM color_palettes WHERE brand_id = ?', [$brandId]);
        if ($exists) {
            $db->update('color_palettes', [
                'light_palette' => json_encode($light, JSON_UNESCAPED_UNICODE),
                'dark_palette'  => json_encode($dark, JSON_UNESCAPED_UNICODE),
                'is_manual_edited' => 1,
            ], 'brand_id = ?', [$brandId]);
        } else {
            $db->insert('color_palettes', [
                'brand_id' => $brandId,
                'light_palette' => json_encode($light, JSON_UNESCAPED_UNICODE),
                'dark_palette' => json_encode($dark, JSON_UNESCAPED_UNICODE),
                'is_manual_edited' => 1,
            ]);
        }
        flash('success', '✅ پالت رنگ ذخیره شد.');
        redirect('brand-edit.php?id=' . $brandId . '&tab=palette');
    }

    /* ---------- بازتولید پالت از لوگو ---------- */
    if ($action === 'regenerate_palette') {
        if (!empty($brand['logo'])) {
            $analysis = (new ColorAnalyzer())->analyze(ROOT_PATH . '/' . $brand['logo']);
            if ($analysis) {
                $exists = $db->fetchValue('SELECT COUNT(*) FROM color_palettes WHERE brand_id = ?', [$brandId]);
                if ($exists) {
                    $db->update('color_palettes', [
                        'light_palette' => json_encode($analysis['light'], JSON_UNESCAPED_UNICODE),
                        'dark_palette' => json_encode($analysis['dark'], JSON_UNESCAPED_UNICODE),
                        'dominant_colors' => json_encode($analysis['dominant'], JSON_UNESCAPED_UNICODE),
                        'is_manual_edited' => 0,
                    ], 'brand_id = ?', [$brandId]);
                } else {
                    $db->insert('color_palettes', [
                        'brand_id' => $brandId,
                        'light_palette' => json_encode($analysis['light'], JSON_UNESCAPED_UNICODE),
                        'dark_palette' => json_encode($analysis['dark'], JSON_UNESCAPED_UNICODE),
                        'dominant_colors' => json_encode($analysis['dominant'], JSON_UNESCAPED_UNICODE),
                    ]);
                }
                flash('success', '✅ پالت از لوگو بازتولید شد.');
            } else {
                flash('danger', 'تحلیل لوگو ناموفق بود.');
            }
        } else {
            flash('warning', 'لوگویی برای این برند آپلود نشده است.');
        }
        redirect('brand-edit.php?id=' . $brandId . '&tab=palette');
    }

    /* ---------- بروزرسانی سئوی صفحه ---------- */
    if ($action === 'update_seo') {
        $pageId = (int)post('page_id');
        $analyzer = new SeoAnalyzer();
        $content = (string)post('seo_content');
        $analysis = $analyzer->analyze([
            'title' => post('seo_title'),
            'meta_description' => post('seo_description'),
            'content' => $content,
            'slug' => post('seo_slug'),
            'seo_robots' => post('seo_robots'),
            'og_image' => post('og_image'),
        ], post('focus_keyword'));
        $db->update('brand_pages', [
            'seo_title'       => post('seo_title'),
            'seo_description' => post('seo_description'),
            'seo_keywords'    => post('seo_keywords'),
            'seo_robots'      => post('seo_robots'),
            'og_title'        => post('seo_title'),
            'og_description'  => post('seo_description'),
            'og_image'        => post('og_image') ?: null,
            'seo_score'       => $analysis['score'],
        ], 'id = ? AND brand_id = ?', [$pageId, $brandId]);
        flash('success', '✅ سئوی صفحه ذخیره شد — امتیاز جدید: ' . $analysis['score'] . '/100');
        redirect('brand-edit.php?id=' . $brandId . '&tab=seo&page=' . $pageId);
    }

    /* 🚀 بهبود خودکار سئوی یک صفحه تا ۱۰۰ (v2.6 — AJAX) */
    if ($action === 'improve_page_seo') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $pageId = (int)post('page_id');
            $page = $db->fetch('SELECT * FROM brand_pages WHERE id = ? AND brand_id = ?', [$pageId, $brandId]);
            if (!$page) {
                json_response(['success' => false, 'error' => 'صفحه یافت نشد.'], 404);
            }
            /* 🧭 v2.7: کلیدواژه خالی = استخراج خودکار از نوع و عنوان «همان صفحه»
               (قبلاً پیش‌فرض «تعمیر + برند» بود و همه صفحات به تبلیغ نمایندگی تبدیل می‌شدند) */
            $focus = trim((string)post('focus_keyword'));
            $improver = new PageSeoImprover();
            $result = $improver->improve($page, $focus, $brand);
            $p = $result['page'];
            $db->update('brand_pages', [
                'seo_title'       => $p['seo_title'],
                'seo_description' => $p['seo_description'],
                'seo_keywords'    => $p['seo_keywords'],
                'seo_robots'      => $p['robots'],
                'slug'            => $p['slug'],
                'og_title'        => $p['og_title'],
                'og_description'  => $p['og_description'],
                'content'         => $p['content_json'],
                'seo_score'       => $p['seo_score'],
                'updated_at'      => date('Y-m-d H:i:s'),
            ], 'id = ?', [$pageId]);
            (new Cache())->delete('brand_pages_' . $brandId);
            unset($result['page']);
            json_response(['success' => true, 'data' => $result]);
        } catch (Throwable $e) {
            json_response(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /* 🚀🚀 بهبود خودکار همه صفحات برند تا ۱۰۰ (v2.6 — AJAX) */
    if ($action === 'improve_all_pages') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            @set_time_limit(300);
            $pages = $db->fetchAll('SELECT * FROM brand_pages WHERE brand_id = ? AND is_active = 1', [$brandId]);
            $improver = new PageSeoImprover();
            $report = [];
            $improved = 0;
            foreach ($pages as $page) {
                try {
                    /* 🧭 v2.7: کلیدواژه هر صفحه از نوع و عنوان خودش — نه یکسان برای همه */
                    $result = $improver->improve($page, '', $brand);
                    $p = $result['page'];
                    $db->update('brand_pages', [
                        'seo_title'       => $p['seo_title'],
                        'seo_description' => $p['seo_description'],
                        'seo_keywords'    => $p['seo_keywords'],
                        'seo_robots'      => $p['robots'],
                        'slug'            => $p['slug'],
                        'og_title'        => $p['og_title'],
                        'og_description'  => $p['og_description'],
                        'content'         => $p['content_json'],
                        'seo_score'       => $p['seo_score'],
                        'updated_at'      => date('Y-m-d H:i:s'),
                    ], 'id = ?', [$page['id']]);
                    if ($result['score_after'] > $result['score_before']) { $improved++; }
                    $report[] = [
                        'page_type' => $page['page_type'],
                        'before'    => $result['score_before'],
                        'after'     => $result['score_after'],
                    ];
                } catch (Throwable $pe) {
                    $report[] = ['page_type' => $page['page_type'], 'error' => $pe->getMessage()];
                }
            }
            (new Cache())->delete('brand_pages_' . $brandId);
            json_response(['success' => true, 'data' => [
                'pages'    => count($pages),
                'improved' => $improved,
                'report'   => $report,
            ]]);
        } catch (Throwable $e) {
            json_response(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }
}

$pageTitle = 'ویرایش برند';
$activeMenu = 'brands';
require __DIR__ . '/includes/header.php';

/* ==================================================
 * 📥 بارگذاری داده‌ها
 * ================================================== */
$devices = $db->fetchAll('SELECT * FROM brand_devices WHERE brand_id = ? ORDER BY sort_order, id', [$brandId]);
$pages = $db->fetchAll('SELECT * FROM brand_pages WHERE brand_id = ? ORDER BY id', [$brandId]);
$palette = $db->fetch('SELECT * FROM color_palettes WHERE brand_id = ?', [$brandId]);
$lightPalette = $palette ? (json_decode($palette['light_palette'], true) ?: []) : [];
$darkPalette = $palette ? (json_decode($palette['dark_palette'], true) ?: []) : [];
$dominant = $palette ? (json_decode($palette['dominant_colors'], true) ?: []) : [];
$currentTab = get_param('tab', 'info');

/* 🧹 v2.9: شمارش دستگاه‌های تکراری برای دکمه پاک‌سازی */
$dupDeviceCount = 0;
{
    $seen = [];
    foreach ($devices as $d) {
        if (isset($seen[$d['device_key']])) {
            $dupDeviceCount++;
        }
        $seen[$d['device_key']] = true;
    }
}

/* 🏷️ v2.9: برچسب‌های فارسی گروه‌بندی متغیرهای پالت رنگ */
function paletteVarLabel(string $var): array
{
    $map = [
        'primary' => ['🎨 رنگ اصلی', 'اصلی'],
        'secondary' => ['🎨 رنگ دوم', 'اصلی'],
        'accent' => ['✨ رنگ تاکیدی', 'اصلی'],
        'background' => ['🌊 پس‌زمینه', 'پس‌زمینه'],
        'surface' => ['▤ سطح/کارت', 'پس‌زمینه'],
        'card' => ['▤ سطح کارت', 'پس‌زمینه'],
        'text' => ['✍️ متن اصلی', 'متن'],
        'text_light' => ['💬 متن کم‌رنگ', 'متن'],
        'text_muted' => ['💬 متن کم‌رنگ', 'متن'],
        'border' => ['┇ حاشیه', 'پس‌زمینه'],
        'header_bg' => ['🖼 پس‌زمینه هدر', 'اجزا'],
        'footer_bg' => ['🖼 پس‌زمینه پایین‌صفحه', 'اجزا'],
        'btn_primary_bg' => ['🔘 دکمه اصلی', 'اجزا'],
        'btn_primary_text' => ['🔘 متن دکمه اصلی', 'اجزا'],
        'hero_bg' => ['🦸 پس‌زمینه هیرو', 'اجزا'],
        'price' => ['💰 رنگ قیمت', 'اجزا'],
    ];
    foreach ($map as $needle => $meta) {
        if (stripos($var, $needle) !== false) {
            return $meta;
        }
    }
    return ['🔧 ' . $var, 'سایر'];
}

$pageNames = [
    'home' => '🏠 صفحه اصلی', 'services' => '🔧 خدمات', 'service-area' => '📍 محدوده خدمات',
    'warranty' => '🛡️ ضمانت', 'blog' => '📰 مقالات', 'about-agency' => '🏢 درباره نمایندگی',
    'about-brand' => 'ℹ️ درباره برند', 'contact' => '📞 تماس با ما', 'request' => '📝 ثبت درخواست',
    'other-brands' => '🏷️ سایر برندها', 'error-codes' => '🚨 کدهای خطا', 'faq' => '❓ سوالات متداول',
    'sitemap-page' => '🗺️ نقشه سایت', 'terms' => '📜 قوانین', 'privacy' => '🔒 حریم خصوصی',
];

// صفحه انتخابی سئو
$seoPageId = (int)get_param('page', ($pages[0]['id'] ?? 0));
$seoPage = null;
foreach ($pages as $p) {
    if ((int)$p['id'] === $seoPageId) {
        $seoPage = $p;
        break;
    }
}
?>

<!-- 🐛 v2.12: ساختار فرم‌ها اصلاح شد — قبلاً همه تب‌ها داخل یک فرم غول‌پیکر بودند و
     فرم پاک‌سازی داخل آن تودرتو می‌شد؛ مرورگر تگ فرم تودرتو را حذف می‌کند و
     «آخرین input با نام action» همیشه برنده می‌شد → کلیک روی «حذف دستگاه‌های تکراری"
     عملاً هندلر update_devices را اجرا می‌کرد و پاک‌سازی هرگز اجرا نمی‌شد.
     حالا: هر تب فرم مستقل خودش را دارد. -->
<div class="tabs">
    <button type="button" class="tab-btn <?= $currentTab === 'info' ? 'active' : '' ?>" onclick="switchTab(this,'tab-info')">📇 اطلاعات</button>
    <button type="button" class="tab-btn <?= $currentTab === 'devices' ? 'active' : '' ?>" onclick="switchTab(this,'tab-devices')">🔧 دستگاه‌ها (<?= en_to_fa_digits((string)count($devices)) ?>)</button>
    <button type="button" class="tab-btn <?= $currentTab === 'pages' ? 'active' : '' ?>" onclick="switchTab(this,'tab-pages')">📄 صفحات</button>
    <button type="button" class="tab-btn <?= $currentTab === 'palette' ? 'active' : '' ?>" onclick="switchTab(this,'tab-palette')">🎨 پالت رنگ</button>
    <button type="button" class="tab-btn <?= $currentTab === 'seo' ? 'active' : '' ?>" onclick="switchTab(this,'tab-seo')">🔍 سئو</button>
    <button type="button" class="tab-btn <?= $currentTab === 'api' ? 'active' : '' ?>" onclick="switchTab(this,'tab-api')">🔌 API</button>
    <button type="button" class="tab-btn <?= $currentTab === 'deploy' ? 'active' : '' ?>" onclick="switchTab(this,'tab-deploy')">🚀 استقرار</button>
</div>

<form method="post" enctype="multipart/form-data" id="info-form">
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="update_info">

    <!-- 📇 تب اطلاعات -->
    <div id="tab-info" class="tab-pane <?= $currentTab === 'info' ? 'active' : '' ?>">
        <div class="card">
            <div class="card-header"><h3>📇 اطلاعات برند</h3></div>
            <div class="card-body">
                <div class="form-row">
                    <div class="form-group">
                        <label>نام فارسی</label>
                        <input type="text" name="name_fa" class="form-control" required value="<?= e($brand['name_fa']) ?>">
                    </div>
                    <div class="form-group">
                        <label>نام انگلیسی</label>
                        <input type="text" name="name_en" class="form-control" style="direction:ltr;text-align:left" required value="<?= e($brand['name_en']) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>🌐 آدرس دامنه</label>
                    <input type="text" name="domain" class="form-control" style="direction:ltr;text-align:left" value="<?= e($brand['domain']) ?>">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>🖼️ لوگو</label>
                        <?php if ($brand['logo']): ?><img src="<?= asset_url($brand['logo']) ?>" alt="لوگو" style="max-height:70px;margin-bottom:8px;display:block"><?php endif; ?>
                        <input type="file" name="logo" class="form-control" accept="image/*">
                    </div>
                    <div class="form-group" style="display:flex;flex-direction:column;justify-content:center;gap:12px">
                        <label class="form-check"><input type="checkbox" name="is_active" value="1" <?= $brand['is_active'] ? 'checked' : '' ?>> برند فعال باشد</label>
                        <label class="form-check"><input type="checkbox" name="error_codes_enabled" value="1" <?= $brand['error_codes_enabled'] ? 'checked' : '' ?>> صفحه کدهای خطا فعال باشد</label>
                    </div>
                </div>
                <!-- 🔖 v2.12: فاوآیکون اختصاصی برند (پیش‌فرض = لوگو) -->
                <div class="form-group">
                    <label>🔖 فاوآیکون سایت برند</label>
                    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:8px">
                        <?php $favSrc = $brand['favicon'] ?: $brand['logo']; ?>
                        <?php if ($favSrc): ?>
                            <img src="<?= asset_url($favSrc) ?>" alt="فاوآیکون" style="width:42px;height:42px;border-radius:9px;object-fit:contain;border:1px solid var(--border);padding:3px;background:#fff">
                            <small style="color:var(--text-light)"><?= $brand['favicon'] ? 'فاوآیکون اختصاصی' : 'در حال استفاده از لوگوی برند (پیش‌فرض)' ?></small>
                        <?php else: ?>
                            <small style="color:var(--text-light)">فاوآیکونی ثبت نشده — پس از آپلود لوگو، همان لوگو استفاده می‌شود</small>
                        <?php endif; ?>
                        <?php if ($brand['favicon']): ?>
                            <button type="submit" form="favicon-reset-form" class="btn btn-outline btn-sm" title="فاوآیکون حذف و به لوگوی برند برمی‌گردد">♻️ بازگشت به لوگو</button>
                        <?php endif; ?>
                    </div>
                    <input type="file" name="favicon" class="form-control" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml,image/x-icon">
                    <div class="hint">اگر خالی بماند، لوگوی برند به‌عنوان فاوآیکون سایت استفاده می‌شود (پیش‌فرض). فایل مربعی ۵۱۲×۵۱۲ توصیه می‌شود.</div>
                </div>
                <button type="submit" class="btn btn-primary">💾 ذخیره اطلاعات</button>
            </div>
        </div>
    </div>
</form>

<!-- 🧹 فرم مستقل بازگردانی فاوآیکون به لوگو (خارج از فرم اطلاعات) -->
<form method="post" id="favicon-reset-form" <?= $brand['favicon'] ? '' : 'style="display:none"' ?>>
    <?= Auth::csrfField() ?>
    <input type="hidden" name="action" value="reset_favicon">
</form>

    <!-- 🔧 تب دستگاه‌ها -->
    <div id="tab-devices" class="tab-pane <?= $currentTab === 'devices' ? 'active' : '' ?>">
        <div class="card">
            <div class="card-header">
                <h3>🔧 دستگاه‌های <?= e($brand['name_fa']) ?></h3>
                <div class="tools" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <?php if ($dupDeviceCount > 0): ?>
                        <form method="post" id="dedupe-form"
                              onsubmit="return confirm('🧹 <?= e($brand['name_fa']) ?>: <?= $dupDeviceCount ?> دستگاه تکراری شناسایی شد.\n\nبرای هر دستگاه تکراری، بهترین رکورد (با توضیح/برجستگی) نگه داشته می‌شود و بقیه حذف.\nکدهای خطای تکراری ادغام و کدهای غیرتکراری دست‌نخورده می‌مانند.\n\nادامه می‌دهید؟')">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="dedupe_devices">
                            <button type="submit" class="btn btn-warning btn-sm">🧹 حذف دستگاه‌های تکراری (<?= en_to_fa_digits((string)$dupDeviceCount) ?>)</button>
                        </form>
                    <?php else: ?>
                        <span class="badge badge-success">✅ بدون دستگاه تکراری</span>
                    <?php endif; ?>
                    <span class="badge badge-info">انتخاب موارد نمایش در سایت</span>
                </div>
            </div>
            <form method="post" id="devices-form">
            <?= Auth::csrfField() ?>
            <div class="card-body">
                <input type="hidden" name="action" value="update_devices">
                <?php if (empty($devices)): ?>
                    <div class="empty-state"><div class="icon">🔧</div><p>دستگاهی ثبت نشده — فرآیند ساخت را اجرا کنید یا دستگاه‌ها از پایگاه دانش ثبت شوند.</p></div>
                <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>نمایش</th><th>برجسته</th><th>دستگاه</th><th>ترتیب</th><th>وضعیت توضیح AI</th></tr></thead>
                        <tbody>
                        <?php foreach ($devices as $i => $device): ?>
                            <?php $dupMark = (function () use ($devices, $i, $device) {
                                foreach (array_slice($devices, 0, $i) as $prev) {
                                    if ($prev['device_key'] === $device['device_key']) { return ' <span class="badge badge-warning" title="این دستگاه تکراری است">🔁 تکراری</span>'; }
                                }
                                return '';
                            })(); ?>
                            <tr>
                                <td><input type="hidden" name="device_id[]" value="<?= (int)$device['id'] ?>"><input type="checkbox" name="device_active[]" value="<?= (int)$device['id'] ?>" <?= $device['is_active'] ? 'checked' : '' ?>></td>
                                <td><input type="checkbox" name="device_featured[]" value="<?= (int)$device['id'] ?>" <?= $device['is_featured'] ? 'checked' : '' ?>></td>
                                <td style="font-weight:700"><?= e($device['name_fa']) ?> <small style="color:var(--text-light);direction:ltr"><?= e($device['device_key']) ?></small><?= $dupMark ?></td>
                                <td><input type="number" name="device_order[]" class="form-control" style="width:70px" value="<?= (int)($device['sort_order'] ?? $i) ?>"></td>
                                <td><?= $device['description'] ? '<span class="badge badge-success">✅ تولید شد</span>' : '<span class="badge badge-secondary">—</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary" <?= empty($devices) ? 'disabled' : '' ?>>💾 ذخیره دستگاه‌ها</button>
            </div>
            </form>
        </div>
    </div>

    <!-- 📄 تب صفحات و محتوا -->
    <div id="tab-pages" class="tab-pane <?= $currentTab === 'pages' ? 'active' : '' ?>">
        <?php if (empty($pages)): ?>
            <div class="card"><div class="empty-state"><div class="icon">📄</div><p>محتوایی تولید نشده — ابتدا <a href="brand-build.php?id=<?= (int)$brandId ?>">فرآیند ساخت</a> را اجرا کنید.</p></div></div>
        <?php else: ?>
            <?php foreach ($pages as $page): ?>
                <?php $contentData = json_decode($page['content'] ?? '{}', true) ?: []; ?>
                <?php $mainField = isset($contentData['content']) ? 'content' : (isset($contentData['intro']) ? 'intro' : (array_key_first($contentData) ?: 'content')); ?>
                <div class="card">
                    <div class="card-header">
                        <h3><?= $pageNames[$page['page_type']] ?? e($page['page_type']) ?></h3>
                        <div class="tools" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <?php if ((int)$page['seo_score'] > 0): ?><span class="badge badge-info">سئو: <?= en_to_fa_digits((string)$page['seo_score']) ?>/۱۰۰</span><?php endif; ?>
                            <!-- 👁 v2.9: پیش‌نمایش صفحه — رندر همان‌طور که در سایت دیده می‌شود -->
                            <button type="button" class="btn btn-outline btn-sm" onclick="previewBrandPage(<?= (int)$page['id'] ?>, '<?= e($page['slug'] ?: $page['page_type']) ?>')">👁 پیش‌نمایش</button>
                            <label class="switch"><input type="checkbox" form="page-form-<?= (int)$page['id'] ?>" name="page_active" <?= $page['is_active'] ? 'checked' : '' ?>><span class="slider"></span></label>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="post" id="page-form-<?= (int)$page['id'] ?>">
                            <?= Auth::csrfField() ?>
                            <input type="hidden" name="action" value="update_page">
                            <input type="hidden" name="page_id" value="<?= (int)$page['id'] ?>">
                            <input type="hidden" name="page_content_json" value="<?= e($page['content'] ?? '{}') ?>">
                            <input type="hidden" name="content_field" value="<?= e((string)$mainField) ?>">
                            <textarea name="content_value" class="form-control" rows="7" style="line-height:2.1"><?= e((string)($contentData[$mainField] ?? '')) ?></textarea>
                            <div class="hint" style="margin:8px 0 12px">ویرایش دستی محتوای تولیدشده توسط AI — تغییرات بلافاصله در سایت برند اعمال می‌شود (کش ۱۰ دقیقه).</div>
                            <button type="submit" class="btn btn-primary btn-sm">💾 ذخیره محتوا</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- 🎨 تب پالت رنگ -->
    <div id="tab-palette" class="tab-pane <?= $currentTab === 'palette' ? 'active' : '' ?>">
        <div class="card">
            <div class="card-header">
                <h3>🎨 پالت رنگ <?= e($brand['name_fa']) ?></h3>
                <div class="tools">
                    <button type="submit" form="palette-regen-form" class="btn btn-outline btn-sm">🔄 بازتولید از لوگو</button>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($lightPalette)): ?>
                    <div class="empty-state"><div class="icon">🎨</div><p>پالتی ثبت نشده — فرآیند ساخت یا بازتولید از لوگو را اجرا کنید.</p></div>
                <?php else: ?>
                    <?php
                    /* 🧩 v2.9: گروه‌بندی متغیرهای هر تم */
                    $groupPalette = static function (array $palette): array {
                        $groups = ['اصلی' => [], 'متن' => [], 'پس‌زمینه' => [], 'اجزا' => [], 'سایر' => []];
                        foreach ($palette as $var => $value) {
                            [$label, $group] = paletteVarLabel((string)$var);
                            $groups[$group][$var] = ['label' => $label, 'value' => (string)$value];
                        }
                        return array_filter($groups);
                    };
                    $lightGroups = $groupPalette($lightPalette);
                    $darkGroups = $groupPalette($darkPalette);
                    $isHex = static fn($v) => (bool)preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string)$v);
                    ?>

                    <?php if (!empty($dominant)): ?>
                        <!-- 🌈 پالت غالب لوگو — بزرگ و قالب‌بندی‌شده -->
                        <div style="margin-bottom:26px;padding:18px;border-radius:16px;background:linear-gradient(135deg,#f8fafc,#eef2ff);border:1px solid var(--border)">
                            <div style="font-weight:800;font-size:14.5px;margin-bottom:14px">🌈 پالت غالب لوگو <small style="font-weight:400;color:var(--text-light)">(استخراج هوشمند از پیکسل‌های لوگو)</small></div>
                            <div style="display:flex;gap:14px;flex-wrap:wrap">
                                <?php foreach ($dominant as $di => $color): ?>
                                    <?php $c = trim((string)$color); if ($c === '') { continue; } ?>
                                    <div class="dom-swatch" data-color="<?= e($c) ?>" style="cursor:pointer;text-align:center;transition:transform .15s" onclick="copyText('<?= e($c) ?>', this)" title="کلیک = کپی رنگ">
                                        <div style="width:88px;height:88px;border-radius:18px;background:<?= e($c) ?>;border:1px solid rgba(0,0,0,.12);box-shadow:0 6px 18px rgba(0,0,0,.1);margin:0 auto"></div>
                                        <code style="font-size:11.5px;direction:ltr;display:block;margin-top:8px;font-weight:700"><?= e($c) ?></code>
                                        <small style="color:var(--text-light);font-size:10.5px">رنگ <?= en_to_fa_digits((string)($di + 1)) ?> — کلیک برای کپی</small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="post">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="action" value="update_palette">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:start" class="palette-grid">
                            <?php foreach ([['🌞 تم روشن', $lightPalette, $lightGroups, 'light'], ['🌙 تم تاریک', $darkPalette, $darkGroups, 'dark']] as [$themeTitle, $themeVars, $themeGroups, $prefix]): ?>
                                <div style="border:1px solid var(--border);border-radius:16px;overflow:hidden">
                                    <div style="padding:13px 16px;font-weight:800;font-size:13.5px;display:flex;justify-content:space-between;align-items:center;background:var(--bg)">
                                        <?= $themeTitle ?>
                                        <label style="font-weight:400;font-size:11.5px;color:var(--text-light);display:flex;gap:5px;align-items:center;cursor:pointer">
                                            <input type="checkbox" class="theme-live-toggle" data-theme="<?= $prefix ?>" checked> پیش‌نمایش زنده
                                        </label>
                                    </div>
                                    <div style="padding:14px 16px;max-height:430px;overflow:auto">
                                        <?php foreach ($themeGroups as $groupName => $vars): ?>
                                            <div style="margin-bottom:14px">
                                                <div style="font-size:11.5px;font-weight:700;color:var(--text-light);margin-bottom:8px;border-inline-start:3px solid var(--primary);padding-inline-start:8px"><?= e($groupName) ?></div>
                                                <?php foreach ($vars as $var => $meta): ?>
                                                    <div style="display:flex;gap:9px;align-items:center;margin-bottom:7px">
                                                        <input type="hidden" name="<?= $prefix ?>_vars[]" value="<?= e($var) ?>">
                                                        <?php if ($isHex($meta['value'])): ?>
                                                            <button type="button" class="cp-open" data-target="<?= $prefix ?>-val-<?= md5($var) ?>" data-var="<?= e($var) ?>" data-theme="<?= $prefix ?>"
                                                                    style="width:46px;height:34px;border-radius:9px;cursor:pointer;border:1px solid rgba(0,0,0,.15);flex:0 0 46px;background:<?= e($meta['value']) ?>"
                                                                    title="کلیک: انتخابگر رنگ حرفه‌ای">▼</button>
                                                            <input type="hidden" name="<?= $prefix ?>_vals[]" id="<?= $prefix ?>-val-<?= md5($var) ?>" value="<?= e($meta['value']) ?>">
                                                            <div style="flex:1;min-width:0">
                                                                <div style="font-size:12px;font-weight:600"><?= e($meta['label']) ?></div>
                                                                <div style="font-size:10px;color:var(--text-light);direction:ltr;text-align:left;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($var) ?></div>
                                                            </div>
                                                            <code class="cp-hex" style="font-size:11px;direction:ltr;font-weight:700"><?= e($meta['value']) ?></code>
                                                        <?php else: ?>
                                                            <input type="text" name="<?= $prefix ?>_vals[]" class="form-control" style="direction:ltr;text-align:left;font-size:11px;padding:5px 9px;flex:1" value="<?= e($meta['value']) ?>">
                                                            <small style="font-size:10.5px;color:var(--text-light);flex:0 0 auto;max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($var) ?>"><?= e($meta['label']) ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- 🖥 پیش‌نمایش زنده تم — مینی‌سایت با رنگ‌های انتخابی -->
                        <div style="margin-top:18px;border:1px solid var(--border);border-radius:16px;overflow:hidden">
                            <div style="padding:12px 16px;font-weight:800;font-size:13.5px;background:var(--bg);display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
                                <span>🖥 پیش‌نمایش زنده سایت با رنگ‌های انتخابی</span>
                                <span style="display:flex;gap:8px">
                                    <button type="button" class="btn btn-outline btn-sm active" id="pv-btn-light" onclick="switchPreviewTheme('light')">🌞 روشن</button>
                                    <button type="button" class="btn btn-outline btn-sm" id="pv-btn-dark" onclick="switchPreviewTheme('dark')">🌙 تاریک</button>
                                </span>
                            </div>
                            <div id="palette-live-preview" style="padding:0"></div>
                        </div>

                        <div style="margin-top:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                            <button type="submit" class="btn btn-primary">💾 ذخیره پالت</button>
                            <?= $palette['is_manual_edited'] ? '<span class="badge badge-warning">ویرایش دستی شده</span>' : '' ?>
                            <span class="hint" style="margin:0">روی هر رنگ کلیک کنید تا «انتخابگر رنگ حرفه‌ای» باز شود</span>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <!-- فرم جداگانه بازتولید -->
        <form method="post" id="palette-regen-form"><?= Auth::csrfField() ?><input type="hidden" name="action" value="regenerate_palette"></form>
    </div>

    <!-- 🎨 مودال انتخابگر رنگ حرفه‌ای (v2.9) -->
    <div class="modal-backdrop" id="cp-backdrop">
        <div class="modal" style="max-width:420px">
            <div class="modal-header" style="justify-content:space-between;gap:10px">
                <span>🎨 انتخابگر رنگ — <code id="cp-var-name" style="font-size:11px"></code></span>
                <button type="button" class="btn btn-outline btn-sm" onclick="closeColorPicker()">✕</button>
            </div>
            <div class="modal-body" style="padding:16px">
                <!-- ناحیه اشباع/روشنایی -->
                <div id="cp-sv" style="position:relative;height:200px;border-radius:12px;cursor:crosshair;border:1px solid var(--border);overflow:hidden">
                    <div style="position:absolute;inset:0;background:linear-gradient(to top,#000,transparent)"></div>
                    <div style="position:absolute;inset:0;background:linear-gradient(to right,#fff,transparent)"></div>
                    <div id="cp-sv-dot" style="position:absolute;width:16px;height:16px;border-radius:50%;border:2.5px solid #fff;box-shadow:0 0 4px rgba(0,0,0,.55);transform:translate(-50%,-50%);pointer-events:none"></div>
                </div>
                <!-- نوار رنگ -->
                <div id="cp-hue" style="position:relative;height:16px;border-radius:9px;margin-top:14px;cursor:pointer;background:linear-gradient(to right,red,#ff0,#0f0,#0ff,#00f,#f0f,red)">
                    <div id="cp-hue-dot" style="position:absolute;top:50%;width:20px;height:20px;border-radius:50%;border:3px solid #fff;box-shadow:0 0 5px rgba(0,0,0,.6);transform:translate(-50%,-50%);pointer-events:none"></div>
                </div>
                <!-- مقدارها -->
                <div style="display:flex;gap:9px;margin-top:14px;align-items:center;flex-wrap:wrap">
                    <div style="flex:1;min-width:130px">
                        <label style="font-size:11px;color:var(--text-light)">HEX</label>
                        <input type="text" id="cp-hex" class="form-control" style="direction:ltr;text-align:left;font-family:monospace;font-weight:700" maxlength="7">
                    </div>
                    <div style="flex:1;min-width:130px">
                        <label style="font-size:11px;color:var(--text-light)">RGB</label>
                        <input type="text" id="cp-rgb" class="form-control" style="direction:ltr;text-align:left;font-family:monospace;font-size:12px" readonly>
                    </div>
                    <div id="cp-preview" style="width:58px;height:44px;border-radius:10px;border:1px solid var(--border);flex:0 0 58px"></div>
                </div>
                <!-- قطرهچشم + پیش‌فرض -->
                <div style="display:flex;gap:8px;margin-top:13px;flex-wrap:wrap;align-items:center">
                    <?php if ($dominant): ?>
                        <span style="font-size:11.5px;color:var(--text-light);font-weight:700">از لوگو:</span>
                        <?php foreach (array_slice($dominant, 0, 6) as $dc): $dc = trim((string)$dc); if ($dc === '') continue; ?>
                            <button type="button" class="cp-preset" data-color="<?= e($dc) ?>" style="width:30px;height:30px;border-radius:8px;border:1.5px solid rgba(0,0,0,.15);cursor:pointer;background:<?= e($dc) ?>" title="<?= e($dc) ?>"></button>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline btn-sm" id="cp-eyedropper" style="margin-inline-start:auto">💧 قطره‌چشم</button>
                </div>
                <div style="display:flex;gap:8px;margin-top:16px">
                    <button type="button" class="btn btn-primary" style="flex:1" onclick="applyColorPicker()">✔ اعمال رنگ</button>
                </div>
                <div class="hint" id="cp-eyedropper-hint" style="display:none;margin-top:8px">🎯 روی هر نقطهٔ صفحه کلیک کنید (Esc = لغو)</div>
            </div>
        </div>
    </div>

    <!-- 👁 مودال پیش‌نمایش صفحه برند (v2.9) -->
    <div class="modal-backdrop" id="page-preview-backdrop">
        <div class="modal" style="max-width:96vw;width:1100px">
            <div class="modal-header" style="justify-content:space-between;gap:10px;flex-wrap:wrap">
                <span>👁 پیش‌نمایش صفحه — <b id="pp-title"></b></span>
                <span style="display:flex;gap:8px;align-items:center">
                    <span class="device-tabs" style="margin:0">
                        <button type="button" class="device-tab active" onclick="setPpWidth(this,0)" title="دسکتاپ">🖥️</button>
                        <button type="button" class="device-tab" onclick="setPpWidth(this,420)" title="موبایل">📲</button>
                    </span>
                    <button type="button" class="btn btn-outline btn-sm" onclick="closeBrandPagePreview()">✕ بستن</button>
                </span>
            </div>
            <div class="modal-body" style="padding:0;background:#e2e8f0;max-height:82vh;overflow:hidden">
                <iframe id="page-preview-frame" class="page-preview-frame" src="about:blank" style="width:100%;height:76vh;border:0;background:#fff;display:block;margin:0 auto;transition:max-width .25s" title="پیش‌نمایش صفحه"></iframe>
            </div>
        </div>
    </div>

    <!-- 🔍 تب سئو -->
    <div id="tab-seo" class="tab-pane <?= $currentTab === 'seo' ? 'active' : '' ?>">
        <div class="card">
            <div class="card-header">
                <h3>🔍 سئوی صفحات <?= e($brand['name_fa']) ?></h3>
                <div class="tools">
                    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap">
                        <input type="hidden" name="id" value="<?= (int)$brandId ?>">
                        <input type="hidden" name="tab" value="seo">
                        <select name="page" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($pages as $p): ?>
                                <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $seoPageId ? 'selected' : '' ?>><?= $pageNames[$p['page_type']] ?? $p['page_type'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <?php if (!$seoPage): ?>
                    <div class="empty-state"><div class="icon">🔍</div><p>صفحه‌ای برای ویرایش سئو وجود ندارد.</p></div>
                <?php else: ?>
                    <?php
                    $contentData = json_decode($seoPage['content'] ?? '{}', true) ?: [];
                    $seoContent = implode("\n", $contentData);
                    $lastAnalysis = (new SeoAnalyzer())->analyze([
                        'title' => $seoPage['seo_title'],
                        'meta_description' => $seoPage['seo_description'],
                        'content' => '<p>' . $seoContent . '</p>',
                        'slug' => $seoPage['page_type'],
                        'og_image' => $seoPage['og_image'],
                    ], $brand['name_fa']);
                    ?>
                    <!-- ⚡ امتیاز فعلی -->
                    <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;background:var(--bg);padding:16px;border-radius:12px">
                        <div style="width:74px;height:74px;border-radius:50%;background:conic-gradient(var(--primary) <?= (int)$seoPage['seo_score'] ?>%, var(--border) 0);display:flex;align-items:center;justify-content:center">
                            <div style="width:58px;height:58px;border-radius:50%;background:var(--card);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px"><?= en_to_fa_digits((string)$seoPage['seo_score']) ?></div>
                        </div>
                        <div>
                            <div style="font-weight:700">امتیاز سئوی صفحه</div>
                            <div style="font-size:12px;color:var(--text-light)"><?= e($lastAnalysis['grade']) ?> — <?= en_to_fa_digits((string)$lastAnalysis['word_count']) ?> کلمه محتوا</div>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:9px;margin:14px 0">
                        <button type="button" id="btn-improve-page-seo" class="btn btn-success" data-page="<?= (int)$seoPage['id'] ?>">
                            🚀 بهبود خودکار این صفحه تا امتیاز ۱۰۰
                        </button>
                        <button type="button" id="btn-improve-all-pages" class="btn btn-primary">
                            🚀🚀 بهبود همه بخش‌ها و صفحات برند با AI
                        </button>
                        <div id="page-seo-report" style="display:none"></div>
                    </div>

                    <form method="post">
                        <?= Auth::csrfField() ?>
                        <input type="hidden" name="action" value="update_seo">
                        <input type="hidden" name="page_id" value="<?= (int)$seoPage['id'] ?>">
                        <input type="hidden" name="seo_content" value="<?= e($seoContent) ?>">
                        <div class="form-row">
                            <div class="form-group">
                                <label>عنوان سئو (حداکثر ۶۰)</label>
                                <input type="text" name="seo_title" class="form-control" maxlength="70" value="<?= e($seoPage['seo_title'] ?? '') ?>" oninput="document.getElementById('title-count').textContent=this.value.length">
                                <div class="hint">طول فعلی: <span id="title-count"><?= mb_strlen((string)$seoPage['seo_title']) ?></span> — <span style="color:var(--success)">بهینه: ۳۰ تا ۶۰</span></div>
                            </div>
                            <div class="form-group">
                                <label>کلیدواژه کانونی</label>
                                <input type="text" name="focus_keyword" class="form-control" value="<?= e('تعمیر ' . $brand['name_fa']) ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label>متا توضیحات (حداکثر ۱۶۰)</label>
                            <textarea name="seo_description" class="form-control" rows="2" maxlength="170" oninput="document.getElementById('meta-count').textContent=this.value.length"><?= e($seoPage['seo_description'] ?? '') ?></textarea>
                            <div class="hint">طول فعلی: <span id="meta-count"><?= mb_strlen((string)$seoPage['seo_description']) ?></span> — <span style="color:var(--success)">بهینه: ۱۲۰ تا ۱۶۰</span></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>کلمات کلیدی (با کاما)</label>
                                <input type="text" name="seo_keywords" class="form-control" value="<?= e($seoPage['seo_keywords'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Robots</label>
                                <select name="seo_robots" class="form-control">
                                    <?php foreach (['index,follow' => 'ایندکس شود (پیش‌فرض)', 'noindex,follow' => 'ایندکس نشود', 'noindex,nofollow' => 'کاملاً مخفی'] as $val => $label): ?>
                                        <option value="<?= $val ?>" <?= ($seoPage['seo_robots'] ?? 'index,follow') === $val ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>🖼️ تصویر Open Graph</label>
                            <input type="url" name="og_image" class="form-control" style="direction:ltr;text-align:left" value="<?= e($seoPage['og_image'] ?? '') ?>" placeholder="https://... (1200x630)">
                        </div>
                        <button type="submit" class="btn btn-primary">💾 ذخیره سئو + امتیازدهی مجدد</button>
                    </form>

                    <!-- 💡 پیشنهادهای بهبود -->
                    <?php if (!empty($lastAnalysis['suggestions'])): ?>
                        <div style="margin-top:22px">
                            <h4 style="font-size:13.5px;margin-bottom:10px">💡 پیشنهادهای بهبود سئو:</h4>
                            <?php foreach (array_slice($lastAnalysis['suggestions'], 0, 5) as $sug): ?>
                                <div class="alert alert-warning" style="padding:9px 14px;font-size:12.5px;margin-bottom:8px">
                                    <b><?= e($sug['title']) ?></b> — <?= e($sug['advice']) ?> <span class="badge badge-info">+<?= $sug['gain'] ?> امتیاز</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-success" style="margin-top:20px">🎉 عالی! هیچ مورد بهبودی برای این صفحه باقی نمانده است.</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 🔌 تب API -->
    <div id="tab-api" class="tab-pane <?= $currentTab === 'api' ? 'active' : '' ?>">
        <div class="card">
            <div class="card-header"><h3>🔌 کلید API سایت برند</h3></div>
            <div class="card-body">
                <p style="font-size:13px;margin-bottom:14px">این کلید در <code>config.php</code> هسته سایت برند قرار می‌گیرد و برای دریافت داده‌ها از سایت ساز استفاده می‌شود.</p>
                <div style="display:flex;gap:10px;align-items:center">
                    <input type="text" class="form-control" style="direction:ltr;text-align:left;font-family:monospace" readonly value="<?= e($brand['api_key']) ?>" id="api-key-input">
                    <button type="button" class="btn btn-outline" onclick="copyText(document.getElementById('api-key-input').value, this)">📋 کپی</button>
                </div>
                <div class="hint" style="margin-top:10px">🔒 این کلید محرمانه است — در اختیار افراد غیرمجاز قرار ندهید.</div>
            </div>
        </div>
    </div>

    <!-- 🚀 تب استقرار و سرور -->
    <div id="tab-deploy" class="tab-pane <?= $currentTab === 'deploy' ? 'active' : '' ?>">
        <?php
        /* 📊 وضعیت استقرار این برند */
        $deployBrand = $db->fetch('SELECT * FROM brands WHERE id = ?', [(int)$brandId]);
        $isDeployed = !empty($deployBrand['is_deployed']);
        $deploySettings = PathResolver::getSettings();
        $deployLogger = new DeploymentLogger();
        $deployHistory = $deployLogger->getBrandHistory((int)$brandId, 10);
        $subPreview = (new SubdomainManager())->previewSubdomain($deployBrand);
        $sslMap = ['active' => ['🔒 فعال', 'badge-success'], 'pending' => ['⏳ در حال صدور', 'badge-warning'], 'none' => ['—', 'badge-secondary']];
        $healthMap = ['online' => ['🟢 آنلاین', 'badge-success'], 'offline' => ['🔴 آفلاین', 'badge-danger'], 'error' => ['⚠️ خطا', 'badge-warning']];
        ?>
        <div class="card" style="margin-bottom:14px">
            <div class="card-header"><h3>🚀 استقرار و سرور</h3></div>
            <div class="card-body">
                <?php if (empty($deploySettings['deploy_enabled'])): ?>
                    <div class="alert alert-warning" style="margin-bottom:14px">⚠️ استقرار خودکار غیرفعال است — <a href="cpanel-settings.php">فعال‌سازی از تنظیمات cPanel</a></div>
                <?php endif; ?>

                <div class="table-wrap">
                    <table class="table" style="max-width:640px">
                        <tbody>
                        <tr><td style="width:200px">وضعیت استقرار</td><td><?= $isDeployed ? '<span class="badge badge-success">🟢 مستقر</span>' : '<span class="badge badge-secondary">⚪ مستقر نشده</span>' ?></td></tr>
                        <tr><td>دامنه</td><td dir="ltr"><?= $deployBrand['full_domain'] ? e($deployBrand['full_domain']) : ($subPreview . '.' . ($deploySettings['root_domain'] ?? '—')) ?></td></tr>
                        <tr><td>مسیر سرور</td><td dir="ltr" style="font-size:12px"><?= e($deployBrand['server_path'] ?: PathResolver::resolveDocumentRoot($deployBrand)) ?></td></tr>
                        <tr><td>SSL</td><td><span class="badge <?= ($sslMap[$deployBrand['ssl_status'] ?? 'none'] ?? $sslMap['none'])[1] ?>"><?= ($sslMap[$deployBrand['ssl_status'] ?? 'none'] ?? $sslMap['none'])[0] ?></span><?= $deployBrand['ssl_expiry'] ? ' — انقضا: ' . jdate((string)$deployBrand['ssl_expiry']) : '' ?></td></tr>
                        <tr><td>آخرین استقرار</td><td><?= $deployBrand['deployed_at'] ? jdate((string)$deployBrand['deployed_at'], true) : '—' ?></td></tr>
                        <tr><td>وضعیت سلامت</td><td><?php if ($deployBrand['health_status']): ?><span class="badge <?= ($healthMap[$deployBrand['health_status']] ?? $healthMap['error'])[1] ?>"><?= ($healthMap[$deployBrand['health_status']] ?? $healthMap['error'])[0] ?></span> <?= $deployBrand['last_health_check'] ? '(' . jdate((string)$deployBrand['last_health_check'], true) . ')' : '' ?><?php else: ?>—<?php endif; ?></td></tr>
                        </tbody>
                    </table>
                </div>

                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">
                    <?php if ($isDeployed): ?>
                        <a href="deploy.php?brand_id=<?= (int)$brandId ?>" class="btn btn-primary">🔄 بروزرسانی خودکار</a>
                        <a href="health-dashboard.php?brand_id=<?= (int)$brandId ?>" class="btn btn-outline">📊 وضعیت سلامت</a>
                        <a href="backups.php?brand_id=<?= (int)$brandId ?>" class="btn btn-outline">💾 بکاپ‌ها</a>
                        <a href="<?= e($deployBrand['full_domain'] ? 'https://' . $deployBrand['full_domain'] : '#') ?>" target="_blank" rel="noopener" class="btn btn-outline">🌐 مشاهده سایت</a>
                    <?php else: ?>
                        <a href="deploy.php?brand_id=<?= (int)$brandId ?>" class="btn btn-primary">🚀 استقرار خودکار</a>
                        <?php if ($deployBrand['status'] !== 'draft'): ?>
                        <a href="subdomain-editor.php?brand_id=<?= (int)$brandId ?>" class="btn btn-outline">✏️ ویرایش نام زیردامنه</a>
                        <a href="export.php?brand=<?= (int)$brandId ?>" class="btn btn-outline">📦 دانلود ZIP</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- 📋 تاریخچه عملیات این برند -->
        <div class="card">
            <div class="card-header"><h3>📋 تاریخچه عملیات این برند</h3></div>
            <div class="table-wrap">
                <?php if (empty($deployHistory)): ?>
                    <div class="empty-state" style="padding:16px"><p>هنوز عملیاتی برای این برند ثبت نشده است.</p></div>
                <?php else: ?>
                <table class="table">
                    <thead><tr><th>عملیات</th><th>وضعیت</th><th>شروع</th><th>مدت</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($deployHistory as $op): [$label, $badge] = DeploymentLogger::statusBadge((string)$op['status']); ?>
                    <tr>
                        <td><?= DeploymentLogger::actionLabel((string)$op['action']) ?></td>
                        <td><span class="badge <?= $badge ?>"><?= $label ?></span></td>
                        <td style="font-size:12px"><?= jdate((string)$op['started_at'], true) ?></td>
                        <td><?= $op['duration_seconds'] ? en_to_fa_digits((string)(int)$op['duration_seconds']) . ' ثانیه' : '—' ?></td>
                        <td><a class="btn btn-outline btn-sm" href="deployment-logs.php">👁️ جزئیات</a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

<script>
/* 🚀 بهبود خودکار سئو تا ۱۰۰ (v2.6) */
(function () {
    'use strict';
    var fa = function (n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); };

    function post(url, body) {
        var csrf = document.querySelector('input[name="csrf_token"]');
        var data = new URLSearchParams();
        Object.keys(body).forEach(function (k) { data.append(k, body[k]); });
        if (csrf) { data.append('csrf_token', csrf.value); }
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf ? csrf.value : '', 'X-Requested-With': 'XMLHttpRequest' },
            body: data.toString(),
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }

    var single = document.getElementById('btn-improve-page-seo');
    var report = document.getElementById('page-seo-report');
    if (single && report) {
        single.addEventListener('click', function () {
            post('brand-edit.php?id=<?= (int)$brandId ?>', { action: 'improve_page_seo', page_id: single.getAttribute('data-page') })
                .then(function (res) {
                    if (!res.success) { sahandToast({ message: res.error || 'بهبود ناموفق بود', type: 'danger' }); return; }
                    var d = res.data;
                    report.style.display = 'block';
                    report.innerHTML = '<div class="alert alert-' + (d.score_after >= 85 ? 'success' : 'warning') + '">' +
                        '🚀 امتیاز: ' + fa(d.score_before) + ' ← <b>' + fa(d.score_after) + '</b> (' + (d.score_after - d.score_before > 0 ? '+' + fa(d.score_after - d.score_before) : 'بدون تغییر') + ') در ' + fa(d.rounds) + ' دور' +
                        (d.applied.length ? '<br>✅ ' + d.applied.join('؛ ') : '') + '<br>🔄 برای دیدن نتیجه، صفحه را رفرش کنید.</div>';
                    sahandToast({ message: 'سئوی صفحه به ' + fa(d.score_after) + '/۱۰۰ رسید', type: d.score_after >= 85 ? 'success' : 'info' });
                })
                .catch(function (err) { sahandToast({ message: 'خطای ارتباط: ' + err.message, type: 'danger' }); });
        });
    }

    var all = document.getElementById('btn-improve-all-pages');
    if (all) {
        all.addEventListener('click', function () {
            all.disabled = true;
            all.innerHTML = '⏳ در حال بهبود همه صفحات... (چند لحظه)';
            post('brand-edit.php?id=<?= (int)$brandId ?>', { action: 'improve_all_pages' })
                .then(function (res) {
                    all.disabled = false;
                    all.innerHTML = '🚀🚀 بهبود همه بخش‌ها و صفحات برند با AI';
                    if (!res.success) { sahandToast({ message: res.error || 'بهبود ناموفق بود', type: 'danger' }); return; }
                    var d = res.data;
                    var rows = (d.report || []).map(function (r) {
                        return r.error
                            ? '❌ ' + r.page_type + ': ' + r.error
                            : '📄 ' + r.page_type + ': ' + fa(r.before) + ' → <b>' + fa(r.after) + '</b>';
                    }).join('<br>');
                    if (report) {
                        report.style.display = 'block';
                        report.innerHTML = '<div class="alert alert-success">🚀🚀 ' + fa(d.improved) + ' صفحه از ' + fa(d.pages) + ' صفحه بهبود یافت:<br>' + rows + '<br>🔄 برای دیدن نتیجه، صفحه را رفرش کنید.</div>';
                    }
                    sahandToast({ message: fa(d.improved) + ' از ' + fa(d.pages) + ' صفحه بهبود یافت', type: 'success' });
                })
                .catch(function (err) {
                    all.disabled = false;
                    all.innerHTML = '🚀🚀 بهبود همه بخش‌ها و صفحات برند با AI';
                    sahandToast({ message: 'خطای ارتباط: ' + err.message, type: 'danger' });
                });
        });
    }
})();
</script>

<script>
/* ==================================================
 * 🎨 انتخابگر رنگ حرفه‌ای + پیش‌نمایش زنده پالت (v2.9)
 * ================================================== */
(function () {
    'use strict';

    /* ---------- مبدل‌های رنگ ---------- */
    function hexToRgb(hex) {
        hex = hex.replace('#', '');
        if (hex.length === 3) { hex = hex.split('').map(function (c) { return c + c; }).join(''); }
        var n = parseInt(hex, 16);
        return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    }
    function rgbToHex(r, g, b) {
        return '#' + [r, g, b].map(function (v) {
            v = Math.max(0, Math.min(255, Math.round(v)));
            return v.toString(16).padStart(2, '0');
        }).join('').toUpperCase();
    }
    function rgbToHsv(r, g, b) {
        r /= 255; g /= 255; b /= 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b), d = max - min;
        var h = 0;
        if (d) {
            if (max === r) { h = ((g - b) / d + (g < b ? 6 : 0)); }
            else if (max === g) { h = (b - r) / d + 2; }
            else { h = (r - g) / d + 4; }
            h *= 60;
        }
        return [h, max ? d / max : 0, max];
    }
    function hsvToRgb(h, s, v) {
        var c = v * s, x = c * (1 - Math.abs((h / 60) % 2 - 1)), m = v - c;
        var r, g, b;
        if (h < 60) { r = c; g = x; b = 0; }
        else if (h < 120) { r = x; g = c; b = 0; }
        else if (h < 180) { r = 0; g = c; b = x; }
        else if (h < 240) { r = 0; g = x; b = c; }
        else if (h < 300) { r = x; g = 0; b = c; }
        else { r = c; g = 0; b = x; }
        return [(r + m) * 255, (g + m) * 255, (b + m) * 255];
    }

    /* ---------- وضعیت انتخابگر ---------- */
    var cp = { hue: 220, sat: 0.6, val: 0.85, hex: '#2563EB', target: null, theme: 'light', varName: '' };
    var svEl = document.getElementById('cp-sv');
    var hueEl = document.getElementById('cp-hue');
    if (!svEl) { return; }

    function setCpFromHex(hex) {
        var rgb = hexToRgb(hex);
        var hsv = rgbToHsv(rgb[0], rgb[1], rgb[2]);
        cp.hue = hsv[0]; cp.sat = hsv[1]; cp.val = hsv[2];
        cp.hex = hex.toUpperCase();
        syncCpUi();
    }
    function syncCpUi() {
        /* پس‌زمینه ناحیه SV = رنگ خالص hue */
        var pure = rgbToHex.apply(null, hsvToRgb(cp.hue, 1, 1));
        svEl.style.background = 'linear-gradient(to top, #000, transparent), linear-gradient(to right, #fff, ' + pure + ')';
        svEl.style.backgroundImage = 'linear-gradient(to top, #000, rgba(0,0,0,0)), linear-gradient(to right, #fff, ' + pure + ')';
        svEl.style.backgroundColor = pure;
        var dot = document.getElementById('cp-sv-dot');
        dot.style.left = (cp.sat * 100) + '%';
        dot.style.top = ((1 - cp.val) * 100) + '%';
        dot.style.background = cp.hex;
        var hd = document.getElementById('cp-hue-dot');
        hd.style.left = (cp.hue / 360 * 100) + '%';
        document.getElementById('cp-hex').value = cp.hex;
        var rgb = hexToRgb(cp.hex);
        document.getElementById('cp-rgb').value = rgb.join(', ');
        document.getElementById('cp-preview').style.background = cp.hex;
    }
    function updateHexFromHsv() {
        var rgb = hsvToRgb(cp.hue, cp.sat, cp.val);
        cp.hex = rgbToHex(rgb[0], rgb[1], rgb[2]);
        syncCpUi();
    }

    /* ---------- تعامل SV ---------- */
    function svPick(e) {
        var rect = svEl.getBoundingClientRect();
        cp.sat = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
        cp.val = Math.max(0, Math.min(1, 1 - (e.clientY - rect.top) / rect.height));
        updateHexFromHsv();
    }
    var svDragging = false;
    svEl.addEventListener('mousedown', function (e) { svDragging = true; svPick(e); });
    document.addEventListener('mousemove', function (e) { if (svDragging) { svPick(e); } });
    document.addEventListener('mouseup', function () { svDragging = false; });

    /* ---------- تعامل Hue ---------- */
    function huePick(e) {
        var rect = hueEl.getBoundingClientRect();
        cp.hue = Math.max(0, Math.min(360, (e.clientX - rect.left) / rect.width * 360));
        updateHexFromHsv();
    }
    var hueDragging = false;
    hueEl.addEventListener('mousedown', function (e) { hueDragging = true; huePick(e); });
    document.addEventListener('mousemove', function (e) { if (hueDragging) { huePick(e); } });
    document.addEventListener('mouseup', function () { hueDragging = false; });

    /* ---------- HEX دستی ---------- */
    document.getElementById('cp-hex').addEventListener('input', function () {
        var v = this.value.trim();
        if (/^#?[0-9a-fA-F]{6}$/.test(v)) { setCpFromHex(v[0] === '#' ? v : '#' + v); }
    });

    /* ---------- باز/بستن ---------- */
    window.closeColorPicker = function () {
        document.getElementById('cp-backdrop').classList.remove('show');
        cp.target = null;
    };
    window.applyColorPicker = function () {
        if (cp.target) {
            cp.target.value = cp.hex;
            /* به‌روزرسانی بصری سواچ و کد hex در لیست */
            var row = cp.target.closest('div[style*="display:flex"]');
            if (row) {
                var btn = row.querySelector('.cp-open');
                if (btn) { btn.style.background = cp.hex; }
                var code = row.querySelector('.cp-hex');
                if (code) { code.textContent = cp.hex; }
            }
            renderPalettePreview();
            if (window.sahandToast) { sahandToast({ message: 'رنگ «' + cp.varName + '» = ' + cp.hex, type: 'success' }); }
        }
        window.closeColorPicker();
    };

    /* دکمه‌های سواچ رنگ → باز کردن انتخابگر */
    document.querySelectorAll('.cp-open').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = this.getAttribute('data-target');
            cp.target = document.getElementById(targetId);
            if (!cp.target) { return; }
            cp.theme = this.getAttribute('data-theme') || 'light';
            cp.varName = this.getAttribute('data-var') || '';
            document.getElementById('cp-var-name').textContent = cp.varName;
            setCpFromHex(cp.target.value || '#2563EB');
            document.getElementById('cp-backdrop').classList.add('show');
        });
    });

    /* پیش‌فرض‌ها از لوگو */
    document.querySelectorAll('.cp-preset').forEach(function (b) {
        b.addEventListener('click', function () { setCpFromHex(this.getAttribute('data-color')); });
    });

    /* قطره‌چشم مرورگر */
    var edBtn = document.getElementById('cp-eyedropper');
    if (edBtn && window.EyeDropper) {
        edBtn.addEventListener('click', function () {
            new EyeDropper().open().then(function (res) {
                setCpFromHex(res.sRGBHex);
            }).catch(function () {});
        });
    } else if (edBtn) {
        edBtn.style.display = 'none';
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            window.closeColorPicker();
            window.closeBrandPagePreview && window.closeBrandPagePreview();
        }
    });

    /* ==================================================
     * 🖥 پیش‌نمایش زنده پالت — مینی‌سایت با رنگ‌های فعلی
     * ================================================== */
    function collectTheme(theme) {
        /* متغیرها از ردیف‌های همان تم خوانده می‌شوند */
        var vars = {};
        var prefix = theme === 'light' ? 'light' : 'dark';
        document.querySelectorAll('input[name="' + prefix + '_vars[]"]').forEach(function (vIn, idx) {
            var valIn = document.querySelectorAll('input[name="' + prefix + '_vals[]"]')[idx];
            if (valIn && /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(valIn.value)) {
                vars[vIn.value] = valIn.value;
            }
        });
        return vars;
    }
    function findVar(vars, needles, fallback) {
        for (var i = 0; i < needles.length; i++) {
            for (var k in vars) {
                if (k.toLowerCase().indexOf(needles[i]) !== -1) { return vars[k]; }
            }
        }
        return fallback;
    }
    var previewTheme = 'light';
    window.switchPreviewTheme = function (t) {
        previewTheme = t;
        document.getElementById('pv-btn-light').classList.toggle('active', t === 'light');
        document.getElementById('pv-btn-dark').classList.toggle('active', t === 'dark');
        renderPalettePreview();
    };
    window.renderPalettePreview = function () {
        var box = document.getElementById('palette-live-preview');
        if (!box) { return; }
        var v = collectTheme(previewTheme);
        var primary = findVar(v, ['primary', 'accent', 'brand'], '#2563eb');
        var accent = findVar(v, ['accent', 'secondary'], '#f59e0b');
        var bg = findVar(v, ['background', 'bg', 'body'], '#f8fafc');
        var surface = findVar(v, ['surface', 'card'], '#ffffff');
        var text = findVar(v, ['text', 'color'], '#1e293b');
        var muted = findVar(v, ['text_light', 'muted', 'text_secondary'], '#64748b');
        var border = findVar(v, ['border', 'line'], '#e2e8f0');
        var btnText = findVar(v, ['btn_primary_text', 'on_primary'], '#ffffff');
        box.innerHTML =
        '<div style="background:' + bg + ';color:' + text + ';padding:18px;font-family:inherit;transition:background .2s">' +
          '<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:' + surface + ';border-radius:12px;border:1px solid ' + border + ';margin-bottom:14px">' +
            '<span style="font-weight:800">🏗️ ' + <?= json_encode($brand['name_fa'], JSON_UNESCAPED_UNICODE) ?> + '</span>' +
            '<span style="background:' + primary + ';color:' + btnText + ';padding:6px 14px;border-radius:9px;font-size:12px;font-weight:700">ثبت درخواست</span>' +
          '</div>' +
          '<div style="background:linear-gradient(135deg,' + primary + ',' + accent + ');border-radius:14px;padding:26px 18px;text-align:center;color:#fff;margin-bottom:14px">' +
            '<div style="font-size:16px;font-weight:800;margin-bottom:6px">تعمیرات تخصصی و سریع</div>' +
            '<div style="font-size:12px;opacity:.9;margin-bottom:12px">نمایندگی رسمی با قطعات اصلی</div>' +
            '<span style="background:#fff;color:' + primary + ';padding:7px 16px;border-radius:9px;font-size:12px;font-weight:800">📞 تماس فوری</span>' +
          '</div>' +
          '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">' +
            '<div style="background:' + surface + ';border:1px solid ' + border + ';border-radius:11px;padding:13px;text-align:center"><div style="font-size:22px">🔧</div><div style="font-size:11.5px;font-weight:700;margin-top:5px">سرویس</div><div style="font-size:10px;color:' + muted + '">تخصصی</div></div>' +
            '<div style="background:' + surface + ';border:1px solid ' + border + ';border-radius:11px;padding:13px;text-align:center"><div style="font-size:22px">⚡</div><div style="font-size:11.5px;font-weight:700;margin-top:5px">سریع</div><div style="font-size:10px;color:' + muted + '">همان روز</div></div>' +
            '<div style="background:' + surface + ';border:1px solid ' + border + ';border-radius:11px;padding:13px;text-align:center"><div style="font-size:22px">🛡️</div><div style="font-size:11.5px;font-weight:700;margin-top:5px">ضمانت</div><div style="font-size:10px;color:' + muted + '">۶ ماه</div></div>' +
          '</div>' +
          '<div style="margin-top:12px;padding:11px 13px;background:' + surface + ';border:1px solid ' + border + ';border-radius:11px;font-size:11.5px;color:' + muted + ';line-height:1.9">این پیش‌نمایش، رنگ‌های «' + (previewTheme === 'light' ? 'تم روشن' : 'تم تاریک') + '» را همان‌طور که در سایت برند دیده می‌شود نشان می‌دهد. با تغییر هر رنگ در لیست بالا، این بخش بلافاصله به‌روز می‌شود.</div>' +
        '</div>';
    };
    /* تغییر هر مقدار → پیش‌نمایش زنده (مقدارهای hex از طریق انتخابگر، بقیه دستی) */
    document.querySelectorAll('input[name$="_vals[]"]').forEach(function (inp) {
        inp.addEventListener('input', renderPalettePreview);
        inp.addEventListener('change', renderPalettePreview);
    });
    renderPalettePreview();

    /* ==================================================
     * 👁 پیش‌نمایش صفحه‌های برند (v2.9)
     * ================================================== */
    window.previewBrandPage = function (pageId, slug) {
        document.getElementById('pp-title').textContent = slug || ('صفحه #' + pageId);
        var frame = document.getElementById('page-preview-frame');
        frame.src = 'brand-page-preview.php?id=' + pageId;
        document.getElementById('page-preview-backdrop').classList.add('show');
    };
    window.closeBrandPagePreview = function () {
        document.getElementById('page-preview-backdrop').classList.remove('show');
        document.getElementById('page-preview-frame').src = 'about:blank';
    };
    window.setPpWidth = function (btn, w) {
        document.querySelectorAll('#page-preview-backdrop .device-tab').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        document.getElementById('page-preview-frame').style.maxWidth = w > 0 ? w + 'px' : '100%';
    };
})();
</script>
<style>
/* 📱 ریسپانسیو تب پالت */
@media (max-width: 900px) {
    .palette-grid { grid-template-columns: 1fr !important; }
}
.dom-swatch:hover { transform: translateY(-3px); }
</style>
<?php require __DIR__ . '/includes/footer.php'; ?>
