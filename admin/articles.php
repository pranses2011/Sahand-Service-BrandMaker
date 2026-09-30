<?php
/**
 * 📰 مدیریت مقالات برندها
 * ========================
 * لیست + فیلتر برند/وضعیت + ویرایشگر + تولید AI + زمان‌بندی
 *
 * @package SahandBrandMaker
 */

define('SAHAND_INIT', true);
require_once dirname(__DIR__) . '/config.php';

/* 🛂 v2.34 — ACL سطح‌برند: گارد دسترسی (GET brand / POST brand_id)
   brand_manager فقط به برندهای تخصیص‌یافته در users.php دسترسی دارد */
$_aclBrand = (int)($_GET['brand'] ?? 0);
if ($_aclBrand < 1) { $_aclBrand = (int)($_POST['brand_id'] ?? ($_POST['brand'] ?? 0)); }
if ($_aclBrand > 0) {
    (new Auth())->requireBrandAccess($_aclBrand);
}


$db = Database::getInstance();

/* 🗑️ حذف مقاله */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'delete') {
    Auth::enforceCsrf();
    $db->delete('brand_articles', 'id = ?', [(int)post('article_id')]);
    flash('success', '🗑️ مقاله حذف شد.');
    redirect('articles.php' . (get_param('brand') !== '' ? '?brand=' . get_param('brand') : ''));
}

/* 💾 تغییر وضعیت سریع */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'status') {
    Auth::enforceCsrf();
    $id = (int)post('article_id');
    $status = post('status');
    if (in_array($status, ['draft', 'published'], true)) {
        $db->update('brand_articles', [
            'status' => $status,
            'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
        ], 'id = ?', [$id]);
        /* 📡 v2.34 — IndexNow: انتشار فوری مقاله به موتورهای جستجو اطلاع داده شود */
        if ($status === 'published') {
            try {
                $row = $db->fetch('SELECT brand_id, slug FROM brand_articles WHERE id = ?', [$id]);
                if ($row) {
                    IndexNow::pingArticle((int)$row['brand_id'], (string)$row['slug']);
                }
            } catch (Throwable $inE) { /* fire-and-forget */ }
            /* 🪝 v2.37 — P3: رویداد وب‌هوک article.published */
            WebhookDispatcher::articlePublished($db, $id);
        }
        flash('success', '✅ وضعیت مقاله تغییر کرد.');
    }
    redirect('articles.php' . (get_param('brand') !== '' ? '?brand=' . get_param('brand') : ''));
}

/* 💾 ذخیره ویرایش مقاله */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'save') {
    Auth::enforceCsrf();
    $id = (int)post('article_id');
    /* 🕘 v2.34 — تاریخچه: قبل از ذخیره، نسخه فعلی ثبت می‌شود */
    try {
        $rev = new Revision();
        $old = $db->fetch('SELECT brand_id, title FROM brand_articles WHERE id = ?', [$id]);
        $rev->save('article', $id, $old ? (int)$old['brand_id'] : null, $old['title'] ?? '', $rev->snapshotArticle($id));
    } catch (Throwable $revE) { /* تاریخچه نباید جریان اصلی را بشکند */ }
    /* ⏰ v2.34 — زمان‌بندی انتشار: draft | publish | schedule */
    $update = [
        'title'           => post('title'),
        'content'         => Validator::sanitizeHtml((string)($_POST['content'] ?? '')),
        'excerpt'         => post('excerpt'),
        'seo_title'       => post('seo_title') ?: null,
        'seo_description' => post('seo_description') ?: null,
        'og_image'        => trim((string)post('og_image')) ?: null, /* 🆕 v2.6: OG قابل ویرایش/تعویض */
        'tags'            => json_encode(array_filter(array_map('trim', explode('،', post('tags')))), JSON_UNESCAPED_UNICODE),
        'updated_at'      => date('Y-m-d H:i:s'),
    ];
    $publishMode = (string)post('publish_mode', 'keep');
    if ($publishMode === 'draft') {
        $update['status'] = 'draft';
    } elseif ($publishMode === 'publish') {
        $update['status'] = 'published';
        $update['published_at'] = date('Y-m-d H:i:s');
    } elseif ($publishMode === 'schedule') {
        /* فرمت datetime-local: YYYY-MM-DDTHH:MM — تایم‌زون تهران سرور */
        $raw = trim((string)post('schedule_at'));
        $ts = strtotime(str_replace('T', ' ', $raw));
        if ($raw === '' || $ts === false) {
            flash('danger', 'زمان‌بندی نامعتبر است — تاریخ و ساعت را کامل وارد کنید.');
            redirect('articles.php?edit=' . $id);
        }
        if ($ts <= time()) {
            flash('warning', '⏰ زمان انتخابی گذشته است — مقاله همین حالا منتشر شد.');
            $update['status'] = 'published';
            $update['published_at'] = date('Y-m-d H:i:s');
        } else {
            $update['status'] = 'scheduled';
            $update['published_at'] = date('Y-m-d H:i:s', $ts);
        }
    }
    $db->update('brand_articles', $update, 'id = ?', [$id]);
    (new Cache())->delete('brand_articles_all');
    if (($update['status'] ?? '') === 'published') {
        flash('success', '✅ مقاله ذخیره و منتشر شد.');
        /* 📡 v2.34 — IndexNow: انتشار از فرم ویرایش هم اطلاع‌رسانی می‌شود */
        try {
            $slugRow = $db->fetch('SELECT brand_id, slug FROM brand_articles WHERE id = ?', [$id]);
            if ($slugRow) {
                IndexNow::pingArticle((int)$slugRow['brand_id'], (string)$slugRow['slug']);
            }
        } catch (Throwable $inE) { /* fire-and-forget */ }
        /* 🪝 v2.37 — P3: رویداد وب‌هوک article.published */
        WebhookDispatcher::articlePublished($db, $id);
    } elseif (($update['status'] ?? '') === 'scheduled') {
        flash('success', '⏰ مقاله زمان‌بندی شد — انتشار خودکار در ' . fa_num(jdate('Y/m/d H:i', strtotime((string)$update['published_at']))));
    } else {
        flash('success', '✅ مقاله ذخیره شد.');
    }
    redirect('articles.php?edit=' . $id);
}

/* 🎨 تولید OG با AI (v2.6 — AJAX) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'gen_og') {
    Auth::enforceCsrf();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id = (int)post('article_id');
        $article = $db->fetch('SELECT a.*, b.name_fa AS brand_name, b.logo AS brand_logo, b.extra_settings FROM brand_articles a JOIN brands b ON b.id = a.brand_id WHERE a.id = ?', [$id]);
        if (!$article) {
            json_response(['success' => false, 'error' => 'مقاله یافت نشد.'], 404);
        }
        $brand = ['name_fa' => $article['brand_name'] ?? '', 'extra_settings' => $article['extra_settings'] ?? '', 'logo' => $article['brand_logo'] ?? ''];
        $gen = new AiImageGenerator();
        /* بذر متفاوت با هر بار کلیک → هر تولید یک ترکیب جدید */
        $og = $gen->generateOgForPage('article', $article['title'], $brand, 'article-' . $id . '-' . substr((string)time(), -5));
        $db->update('brand_articles', ['og_image' => $og['path']], 'id = ?', [$id]);
        json_response(['success' => true, 'data' => [
            'path' => $og['path'],
            'url'  => asset_url($og['path']) . '?t=' . time(),
            'format' => $og['format'] ?? 'svg',
        ]]);
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

/* 🖼️ تولید مجدد ۲ تصویر یکتای AI مقاله (v2.15 — AJAX با نوار پیشرفت زنده + جایگزینی)
 * الگوی یکسان با خطایاب: کلید یکتا از فرانت → پیشرفت موتور در فایل کش → polling هر ۸۰۰ms.
 * سشن زود بسته می‌شود تا قفل سشن، درخواست‌های polling را مسدود نکند. */
if (get_param('regen_images') === '1' && ($regenId = (int)get_param('edit')) > 0) {
    $article = $db->fetch('SELECT a.*, b.name_fa AS brand_name, b.logo AS brand_logo, b.extra_settings FROM brand_articles a JOIN brands b ON b.id = a.brand_id WHERE a.id = ?', [$regenId]);
    if ($article) {
        try {
            $brand = ['name_fa' => $article['brand_name'] ?? '', 'extra_settings' => $article['extra_settings'] ?? '', 'logo' => $article['brand_logo'] ?? '', 'id' => $article['brand_id'] ?? 0];
            $gen = new AiImageGenerator();
            /* 🆕 v2.22: نوع مقاله از خود عنوان استنتاج می‌شود — قبلاً همیشه
               «troubleshooting» ثابت بود و صحنه پرامپت با موضوع مقاله نمی‌خواند */
            $aiImages = $gen->generateForArticle(
                $regenId,
                $article['title'],
                (string)($article['device_key'] ?? ''),
                AiPhotoService::inferTopicType((string)$article['title']),
                $brand,
                '',
                strip_tags((string)($article['content'] ?? ''))
            );
            /* 🧹 v2.14: تصاویر تولیدی قبلی حذف → تصاویر تازه «جایگزین» می‌شوند
               (قبلاً هر بار تولید، تصاویر جدید به انتهای محتوا «افزوده» می‌شدند) */
            $injector = new ArticleImageService();
            $stripped = $injector->stripGeneratedFigures((string)$article['content']);
            $rich = $injector->injectIntoContent($stripped, $aiImages['images']);
            /* 🖼️ تصویر شاخص هم عکس واقعی AI جدید می‌شود (در صورت موفقیت سرویس) */
            $upd = ['content' => $rich, 'og_image' => $aiImages['og']['path']];
            if (!empty($aiImages['featured'])) {
                $upd['featured_image'] = $aiImages['featured']['path'];
            }
            $db->update('brand_articles', $upd, 'id = ?', [$regenId]);
            (new Cache())->delete('brand_articles_all');
            $srcLabel = ($aiImages['photo_source'] ?? '') === 'ai_photo' ? 'عکس واقعی مرتبط با موضوع' : 'عکس‌های بسته دستگاه';
            flash('success', '🎨 ۲ تصویر ' . $srcLabel . ' + تصویر OG جدید تولید شد — تصاویر قبلی مقاله جایگزین شدند و واترمارک‌ها (لوگوی برند + نمایندگی بزرگ‌تر) مهر خوردند.');
        } catch (Throwable $e) {
            flash('danger', 'خطای تولید تصویر AI: ' . $e->getMessage());
        }
    }
    redirect('articles.php?edit=' . $regenId);
}

/* 🖼️ v2.15 — همان جریان به‌صورت AJAX + نوار پیشرفت زنده (مسیر اصلی دکمه) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'regen_images') {
    Auth::enforceCsrf();
    header('Content-Type: application/json; charset=utf-8');
    $regenId = (int)post('article_id');
    $progressKey = preg_replace('/[^a-z0-9]/i', '', (string)post('progress_key', '')) ?: (uniqid('ig') . random_int(100, 999));
    $progressFile = CACHE_PATH . '/imggen-' . $progressKey . '.json';
    if (!is_dir(CACHE_PATH)) { @mkdir(CACHE_PATH, 0755, true); }
    @file_put_contents($progressFile, json_encode(['pct' => 1, 'title' => 'آماده‌سازی...', 'detail' => '', 'ts' => time()], JSON_UNESCAPED_UNICODE));
    session_write_close(); /* 🔓 قفل سشن باز — polling آزاد است */

    try {
        $article = $db->fetch('SELECT a.*, b.name_fa AS brand_name, b.logo AS brand_logo, b.extra_settings FROM brand_articles a JOIN brands b ON b.id = a.brand_id WHERE a.id = ?', [$regenId]);
        if (!$article) {
            @unlink($progressFile);
            json_response(['success' => false, 'error' => 'مقاله یافت نشد.'], 404);
        }
        $brand = ['name_fa' => $article['brand_name'] ?? '', 'extra_settings' => $article['extra_settings'] ?? '', 'logo' => $article['brand_logo'] ?? '', 'id' => $article['brand_id'] ?? 0];

        $gen = new AiImageGenerator();
        $gen->setProgressSink(static function (int $pct, string $title, string $detail) use ($progressFile) : void {
            @file_put_contents($progressFile, json_encode(['pct' => max(1, min(96, $pct)), 'title' => $title, 'detail' => $detail, 'ts' => time()], JSON_UNESCAPED_UNICODE));
        });

        /* ⏱ زمان بیشتری برای جبران سرویس‌های تصویر */
        if (function_exists('set_time_limit')) { @set_time_limit(300); }

        /* 🆕 v2.22: نوع مقاله از عنوان استنتاج می‌شود (قبلاً ثابت بود) */
        $aiImages = $gen->generateForArticle(
            $regenId,
            $article['title'],
            (string)($article['device_key'] ?? ''),
            AiPhotoService::inferTopicType((string)$article['title']),
            $brand,
            '',
            strip_tags((string)($article['content'] ?? ''))
        );

        $injector = new ArticleImageService();
        $stripped = $injector->stripGeneratedFigures((string)$article['content']);
        $rich = $injector->injectIntoContent($stripped, $aiImages['images']);
        $upd = ['content' => $rich, 'og_image' => $aiImages['og']['path'], 'updated_at' => date('Y-m-d H:i:s')];
        if (!empty($aiImages['featured'])) {
            $upd['featured_image'] = $aiImages['featured']['path'];
        }
        $db->update('brand_articles', $upd, 'id = ?', [$regenId]);
        (new Cache())->delete('brand_articles_all');

        @unlink($progressFile);
        $isAi = ($aiImages['photo_source'] ?? '') === 'ai_photo';
        $svcLabel = $isAi && !empty($aiImages['service']) && class_exists('AiPhotoService')
            ? (AiPhotoService::SERVICES[$aiImages['service']][0] ?? '') : '';
        json_response([
            'success'    => true,
            'photo_source' => $aiImages['photo_source'] ?? 'package',
            'service'    => $aiImages['service'] ?? '',
            'service_label' => $svcLabel,
            'notices'    => (array)($aiImages['notices'] ?? []), /* 🆕 v3.0: هشدار شفاف «سرویس انتخابی کلید ندارد» */
            'og'         => ['path' => $aiImages['og']['path'], 'url' => asset_url($aiImages['og']['path']) . '?t=' . time()],
            'featured'   => isset($aiImages['featured']) ? ['path' => $aiImages['featured']['path'], 'url' => asset_url($aiImages['featured']['path']) . '?t=' . time()] : null,
            'images'     => array_map(static function ($im) {
                return ['path' => $im['path'], 'url' => asset_url($im['path']) . '?t=' . time()];
            }, $aiImages['images']),
        ]);
    } catch (Throwable $e) {
        @unlink($progressFile);
        json_response(['success' => false, 'error' => 'خطای تولید تصویر: ' . $e->getMessage()], 200);
    }
}

/* 📊 v2.15 — روند پیشرفت تولید تصاویر مقاله (AJAX polling — الگوی خطایاب) */
if (($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'imggen_progress')
    || ($_SERVER['REQUEST_METHOD'] === 'GET' && get_param('action') === 'imggen_progress')) {
    header('Content-Type: application/json; charset=utf-8');
    session_write_close(); // بدون قفل سشن — فایل مستقل خوانده می‌شود
    $key = preg_replace('/[^a-z0-9]/i', '', (string)post('progress_key', get_param('progress_key', '')));
    $file = CACHE_PATH . '/imggen-' . $key . '.json';
    if ($key === '' || !is_file($file)) {
        json_response(['success' => false, 'done' => true]);
    }
    /* 🧹 فایل‌های رهاشده قدیمی (بیش از ۶ دقیقه) پاک شوند — نه فایل فعال */
    foreach (glob(CACHE_PATH . '/imggen-*.json') ?: [] as $old) {
        if (is_file($old) && (time() - (int)filemtime($old)) > 360 && $old !== $file) {
            @unlink($old);
        }
    }
    $data = json_decode((string)@file_get_contents($file), true);
    json_response(['success' => true, 'done' => false, 'progress' => is_array($data) ? $data : ['pct' => 0, 'title' => '', 'detail' => '']]);
}

/* 🤖 تولید مقاله با AI */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'generate') {
    Auth::enforceCsrf();
    try {
        /* 🎚️ v2.35 — عمقِ تحقیق وب (سریع/متعادل/عمیق) + زمانِ کافی برای حالت عمیق */
        $depth = (string)post('research_depth');
        if (!in_array($depth, ['fast', 'balanced', 'deep'], true)) {
            $depth = 'balanced';
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit($depth === 'deep' ? 600 : 300);
        }
        $ai = new SahandAI();
        $article = $ai->generateArticle([
            'brand_id'     => (int)post('brand_id'),
            'topic_type'   => post('topic_type') ?: 'troubleshooting',
            'device_key'   => post('device_key') ?: null,
            'custom_title' => trim((string)post('custom_title')) ?: null, // 🆕 فاز Q.5
            'research'     => post('research') === '1',                  // 🆕 فاز Q.7: جستجوی آنلاین
            'with_images'  => post('with_images') === '1',               // 🆕 فاز Q.8: تصاویر خودکار
            'depth'        => $depth,                                    // 🆕 v2.35: عمق تحقیق وب
        ]);
        $id = $ai->saveArticle((int)post('brand_id'), $article, post('topic_type') ?: 'troubleshooting');
        $msg = '🤖 مقاله تولید شد: «' . $article['title'] . '» (' . $article['word_count'] . ' کلمه)';
        /* 📊 v2.35 — گزارشِ تحقیق وب در پیامِ موفقیت */
        if (!empty($article['research']['used'])) {
            $r = $article['research'];
            $msg .= ' — 🔎 تحقیق وب (' . e((string)($r['depth'] ?? $depth)) . '): '
                . (int)($r['facts'] ?? 0) . ' فکت، '
                . (int)($r['stats'] ?? 0) . ' آمار، '
                . (int)($r['questions'] ?? 0) . ' پرسش، '
                . (int)($r['outline'] ?? 0) . ' سرفصل از رقبا، '
                . (int)($r['sources'] ?? 0) . ' منبع';
            if (!empty($r['source_stats']['total'])) {
                $ss = $r['source_stats'];
                $msg .= ' (' . (int)$ss['fa'] . ' فارسی/' . (int)$ss['en'] . ' خارجی)';
            }
        }
        flash('success', $msg);
        redirect('articles.php?edit=' . $id);
    } catch (Throwable $e) {
        flash('danger', 'خطای تولید: ' . $e->getMessage());
        redirect('articles.php?generate=1');
    }
}

/* ============================================================
 * 🪜 v2.38 — تولید «غیرهمزمانِ گام‌محور» مقاله + نوار پیشرفت زنده
 * ============================================================
 * ریشه‌یابیِ «تولید مقاله در مرحله‌ی پایانی با خطای داخلی سرور قطع
 * می‌شود»: هاست اشتراکی (LiteSpeed) هر درخواست HTTP را پس از ~۱۰۰ تا
 * ۳۰۰ ثانیه می‌کُشد — مهم نیست PHP چقدر مهلت داشته باشد. پاسخِ قطری:
 * «صف کارِ سمت-کلاینت» — هر درخواست فقط «یک گام» کوتاه انجام می‌دهد،
 * وضعیت در فایل کش ذخیره می‌شود و مرورگر بلافاصله گام بعدی را می‌خواهد.
 *
 * گام‌ها (هر کدام یک درخواست مجزای کوتاه):
 *   ① research — تحقیق وب (سقف امن ~۷۰ ثانیه — حتی برای عمق عمیق)
 *   ② compose  — نگارش ۲ واریانت + انتخاب بهترین (کاملاً محلی و سریع)
 *   ③ save     — ثبت مقاله در دیتابیس (تقریباً آنی)
 *   ④ images   — تصاویر AI (مستقل — شکستش مقاله‌ی ذخیره‌شده را تهدید نمی‌کند)
 *
 * مزیت حیاتی: حتی اگر گام تصویر کشته شود، مقاله از قبل ثبت شده است؛
 * کاربر به‌جای «خطای سرور و از دست رفتن همه‌چیز»، مقاله + دکمه‌ی
 * «تولید مجدد تصاویر» را دریافت می‌کند.
 * ============================================================ */
require_once __DIR__ . '/includes/async-task.php';
async_task_gc();

/* 🚀 شروع وظیفه — اعتبارسنجی ورودی + ساخت کلید یکتا */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'generate_start') {
    Auth::enforceCsrf();
    header('Content-Type: application/json; charset=utf-8');
    $brandId = (int)post('brand_id');
    if ($db->fetch('SELECT id FROM brands WHERE id = ?', [$brandId]) === false) {
        json_response(['success' => false, 'error' => 'برند یافت نشد.']);
    }
    $depth = (string)post('research_depth');
    if (!in_array($depth, ['fast', 'balanced', 'deep'], true)) {
        $depth = 'balanced';
    }
    $key = async_task_create('articlegen', [
        'brand_id'     => $brandId,
        'topic_type'   => (string)(post('topic_type') ?: 'troubleshooting'),
        'device_key'   => (string)(post('device_key') ?: ''),
        'custom_title' => trim((string)post('custom_title')) ?: '',
        'research'     => post('research') === '1',
        'with_images'  => post('with_images') === '1',
        'depth'        => $depth,
    ]);
    json_response(['success' => true, 'task_key' => $key]);
}

/* ⚙️ اجرای «یک گام» از صف — فرانت پس از هر پاسخ، گام بعدی را می‌خواهد */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'generate_step') {
    Auth::enforceCsrf();
    header('Content-Type: application/json; charset=utf-8');
    $key = preg_replace('/[^a-z0-9]/i', '', (string)post('task_key', ''));
    $task = $key !== '' ? async_task_load('articlegen', $key) : null;
    if ($task === null) {
        json_response(['success' => false, 'error' => 'وظیفه یافت نشد (منقضی یا پاک شده است) — دوباره فرم را ارسال کنید.', 'restart' => true]);
    }

    /* 🛂 ACL: brand_manager فقط به برندهای خودش */
    try {
        (new Auth())->requireBrandAccess((int)($task['payload']['brand_id'] ?? 0));
    } catch (Throwable $aclE) {
        json_response(['success' => false, 'error' => 'دسترسی به این برند مجاز نیست.']);
    }

    /* 🔒 قفل گام — دو گامِ هم‌زمان روی یک وظیفه اجرا نشوند (دوبار کلیک/تلاش مجدد) */
    $lockPath = CACHE_PATH . '/tasks/articlegen-' . $key . '.lock';
    $lockH = @fopen($lockPath, 'c');
    if (!$lockH || !@flock($lockH, LOCK_EX | LOCK_NB)) {
        @fclose($lockH);
        json_response(['success' => true, 'busy' => true]);
    }

    session_write_close(); /* 🔓 قفل سشن باز — polling آزاد است */
    if (function_exists('set_time_limit')) { @set_time_limit(160); }
    @ignore_user_abort(true);
    @ini_set('display_errors', '0');

    $p = $task['payload'];
    $phase = (string)($task['phase'] ?: 'research');
    $skipImages = post('skip_images') === '1';
    $notices = (array)($task['state']['notices'] ?? []);

    try {
        $brand = $db->fetch('SELECT * FROM brands WHERE id = ?', [(int)$p['brand_id']]);
        if (!$brand) {
            throw new RuntimeException('برند یافت نشد.');
        }

        /* ---------- ① تحقیق وب — گامِ شبکه‌ایِ اول (سقف امن ~۷۰ ثانیه) ---------- */
        if ($phase === 'research') {
            if (!empty($p['research'])) {
                $attempts = (int)($task['state']['research_attempts'] ?? 0) + 1;
                $task['state']['research_attempts'] = $attempts;
                if ($attempts > 2) {
                    /* 🛟 دو بار کشته شد — ادامه بدون تحقیق آنلاین (مقاله محلی هم کامل و یکتاست) */
                    $notices[] = '⚠️ تحقیق وب دو بار ناتمام ماند (محدودیت زمان هاست) — مقاله با دانش داخلی و بدون جستجوی آنلاین ادامه یافت.';
                } else {
                    async_task_save('articlegen', $key, $task);
                    async_task_progress_write('articlegen', $key, 6, '🔎 تحقیق وب — تلاش ' . $attempts . ' از ۲',
                        'کوئری‌های فارسی و انگلیسی به موتورهای جستجو ارسال می‌شود (عمق: ' . $p['depth'] . ')...');
                    $gen0 = new ArticleGenerator();
                    $research = $gen0->researchForArticle($brand, $p['topic_type'], $p['device_key'] ?: null, $p['custom_title'] ?: null, $p['depth'], 70.0);
                    $task['state']['research'] = $research;
                    if (!empty($research['results'])) {
                        $notices[] = '🔎 تحقیق وب: ' . (int)$research['results'] . ' نتیجه از موتورها، '
                            . (int)($research['pages_read'] ?? 0) . ' صفحه کامل خوانده شد ('
                            . (int)(($research['facts'] ?? []) ? count($research['facts']) : 0) . ' فکت).';
                    }
                }
            }
            $task['phase'] = 'compose';
            $task['state']['notices'] = $notices;
            async_task_save('articlegen', $key, $task);
            async_task_progress_write('articlegen', $key, 46, '✍️ نوبت نگارش مقاله', 'تحقیق وب تمام شد — واریانت‌ها در گام بعد نوشته می‌شوند...');
            json_response(['success' => true, 'done' => false, 'phase' => 'compose']);
        }

        /* ---------- ② نگارش واریانت‌ها (کاملاً محلی — بدون شبکه) ---------- */
        if ($phase === 'compose') {
            async_task_progress_write('articlegen', $key, 50, '✍️ نگارش مقاله (۲ واریانت)',
                'دو پیش‌نویس مستقل نوشته و بهترین امتیاز کیفیت انتخاب می‌شود — این گام محلی و سریع است...');
            $gen = new ArticleGenerator();
            $hasResearch = !empty($task['state']['research']);
            $article = $gen->generate(
                $brand,
                $p['topic_type'],
                $p['device_key'] ?: null,
                2,
                $p['custom_title'] ?: null,
                [
                    /* تحقیقِ از پیش انجام‌شده پاس داده می‌شود؛ اگر تحقیق نتیجه نداد،
                       research=false تا گامِ نگارشِ «محلی» ناگهان شبکه‌ای نشود */
                    'research'         => $hasResearch,
                    'research_context' => $hasResearch ? $task['state']['research'] : null,
                    'with_images'      => false, /* تصاویر در گامِ مستقلِ بعدی */
                    'depth'            => $p['depth'],
                ]
            );
            $task['state']['article'] = $article;
            $task['phase'] = 'save';
            $task['state']['notices'] = $notices;
            async_task_save('articlegen', $key, $task);
            async_task_progress_write('articlegen', $key, 78, '💾 نوبت ثبت مقاله',
                '«' . ($article['title'] ?? '') . '» با ' . ($article['word_count'] ?? 0) . ' کلمه آماده ثبت است...');
            json_response(['success' => true, 'done' => false, 'phase' => 'save']);
        }

        /* ---------- ③ ثبت مقاله (تقریباً آنی — بدون تصویر AI) ---------- */
        if ($phase === 'save') {
            async_task_progress_write('articlegen', $key, 82, '💾 ثبت مقاله در پایگاه داده', 'درج رکورد + سئو + منابع تحقیق...');
            $article = $task['state']['article'] ?? null;
            if (!is_array($article) || empty($article['title'])) {
                throw new RuntimeException('محتوای مقاله در وضعیت وظیفه یافت نشد — وظیفه را از نو اجرا کنید.');
            }
            /* 🖼️ تصاویر AI عمداً به گام بعد موکول می‌شوند — ثبتِ مقاله سبک و تضمینی بماند */
            $article['ai_images_wanted'] = false;
            $id = (new SahandAI())->saveArticle((int)$p['brand_id'], $article, $p['topic_type']);
            if ($id < 1) {
                throw new RuntimeException('ثبت مقاله در پایگاه داده ناموفق بود.');
            }
            $task['article_id'] = $id;
            $task['phase'] = (!empty($p['with_images']) && !$skipImages) ? 'images' : 'done';
            $task['state']['notices'] = $notices;
            async_task_save('articlegen', $key, $task);
            (new Cache())->delete('brand_articles_all');
            if ($task['phase'] === 'done') {
                async_task_progress_write('articlegen', $key, 100, '✅ مقاله آماده شد', 'بدون تصاویر AI (طبق درخواست)');
            } else {
                async_task_progress_write('articlegen', $key, 85, '🖼️ نوبت تصاویر AI', 'مقاله ثبت شد — تولید ۳ تصویر واقعی شروع می‌شود...');
            }
            json_response(['success' => true, 'done' => false, 'phase' => $task['phase'], 'article_id' => $id]);
        }

        /* ---------- ④ تصاویر AI (شبکه‌ای — مقاله از قبل امن است) ---------- */
        if ($phase === 'images') {
            if ($skipImages) {
                /* 🛟 سه بار تلاش ناموفق — مقاله بدون تصویر AI تحویل می‌شود (دکمه‌ی
                   «تولید مجدد تصاویر» در ویرایشگر همیشه در دسترس است) */
                $notices[] = '🖼️ گام تصاویر AI پس از چند تلاش ناتمام ماند (محدودیت زمان هاست) — مقاله بدون تصویر AI ثبت شد؛ از دکمه‌ی «تولید مجدد تصاویر» در ویرایشگر استفاده کنید.';
                $task['phase'] = 'done';
                $task['state']['notices'] = $notices;
                async_task_save('articlegen', $key, $task);
                async_task_progress_write('articlegen', $key, 100, '✅ مقاله آماده شد (بدون تصویر AI)', 'گام تصویر طبق درخواست رد شد');
                json_response(['success' => true, 'done' => false, 'phase' => 'done', 'article_id' => (int)($task['article_id'] ?? 0)]);
            }
            $id = (int)($task['article_id'] ?? 0);
            $row = $db->fetch('SELECT a.*, b.name_fa AS brand_name, b.logo AS brand_logo, b.extra_settings FROM brand_articles a JOIN brands b ON b.id = a.brand_id WHERE a.id = ?', [$id]);
            if (!$row) {
                throw new RuntimeException('مقاله ثبت‌شده یافت نشد (id=' . $id . ').');
            }
            $brandArr = [
                'name_fa'        => $row['brand_name'] ?? '',
                'extra_settings' => $row['extra_settings'] ?? '',
                'logo'           => $row['logo'] ?? '',
                'id'             => (int)$p['brand_id'],
            ];
            $gen = new AiImageGenerator();
            /* 📊 پیشرفت واقعیِ سرویس تصویر → نگاشت به بازه‌ی ۸۵ تا ۹۷ */
            $gen->setProgressSink(static function (int $ipct, string $ititle, string $idetail) use ($key): void {
                async_task_progress_write('articlegen', $key, 85 + (int)round($ipct * 0.12), '🖼️ ' . $ititle, $idetail);
            });
            $aiImages = $gen->generateForArticle(
                $id,
                (string)$row['title'],
                (string)($task['state']['article']['device_key'] ?? ''),
                (string)$p['topic_type'],
                $brandArr,
                '',
                strip_tags((string)$row['content'])
            );
            /* 🧹 جایگزینی تصاویر تولیدیِ قبلی (در صورت تلاش مجددِ گام) + درج درون‌متن */
            $injector = new ArticleImageService();
            $stripped = $injector->stripGeneratedFigures((string)$row['content']);
            $rich = $injector->injectIntoContent($stripped, $aiImages['images']);
            $upd = ['content' => $rich, 'og_image' => $aiImages['og']['path'], 'updated_at' => date('Y-m-d H:i:s')];
            if (!empty($aiImages['featured'])) {
                $upd['featured_image'] = $aiImages['featured']['path'];
            }
            $db->update('brand_articles', $upd, 'id = ?', [$id]);
            (new Cache())->delete('brand_articles_all');
            $task['state']['images_done'] = true;
            $task['state']['photo_source'] = (string)($aiImages['photo_source'] ?? 'package');
            foreach ((array)($aiImages['notices'] ?? []) as $nItem) {
                $notices[] = '⚠️ ' . (is_string($nItem) ? $nItem : json_encode($nItem, JSON_UNESCAPED_UNICODE));
            }
            $task['phase'] = 'done';
            $task['state']['notices'] = $notices;
            async_task_save('articlegen', $key, $task);
            async_task_progress_write('articlegen', $key, 97, '✅ تصاویر آماده شد', 'درج در محتوا + ثبت تصویر OG و شاخص...');
            json_response(['success' => true, 'done' => false, 'phase' => 'done', 'article_id' => $id]);
        }

        /* ---------- 🏁 پایان — گزارش نهایی ---------- */
        $art = is_array($task['state']['article'] ?? null) ? $task['state']['article'] : [];
        $r = is_array($art['research'] ?? null) ? $art['research'] : [];
        $summary = '«' . ($art['title'] ?? 'مقاله') . '» با ' . ($art['word_count'] ?? 0) . ' کلمه تولید و ثبت شد';
        if (!empty($r['used'])) {
            $summary .= ' — 🔎 تحقیق وب: ' . (int)($r['facts'] ?? 0) . ' فکت، '
                . (int)($r['stats'] ?? 0) . ' آمار، ' . (int)($r['questions'] ?? 0) . ' پرسش از '
                . (int)($r['sources'] ?? 0) . ' منبع';
        }
        if (!empty($task['state']['images_done'])) {
            $summary .= ($task['state']['photo_source'] === 'ai_photo') ? ' — 🖼️ ۳ تصویر واقعی AI' : ' — 🖼️ تصاویر بسته دستگاه';
        }
        async_task_progress_write('articlegen', $key, 100, '✅ تولید مقاله کامل شد', $summary);
        json_response([
            'success'    => true,
            'done'       => true,
            'article_id' => (int)($task['article_id'] ?? 0),
            'summary'    => $summary,
            'notices'    => $notices,
            'redirect'   => 'articles.php?edit=' . (int)($task['article_id'] ?? 0),
        ]);
    } catch (Throwable $e) {
        $task['error'] = $e->getMessage();
        $task['state']['notices'] = $notices;
        async_task_save('articlegen', $key, $task);
        async_task_progress_write('articlegen', $key, 100, '❌ خطا در گام «' . $phase . '»', $e->getMessage());
        json_response(['success' => false, 'error' => 'خطا در گام «' . $phase . '»: ' . $e->getMessage(), 'phase' => $phase]);
    } finally {
        if (isset($lockH) && is_resource($lockH)) {
            @flock($lockH, LOCK_UN);
            @fclose($lockH);
        }
        @unlink($lockPath);
    }
}

/* 📊 روند پیشرفت تولید مقاله (polling سبک — بدون قفل سشن) */
if (($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'generate_progress')
    || ($_SERVER['REQUEST_METHOD'] === 'GET' && get_param('action') === 'generate_progress')) {
    header('Content-Type: application/json; charset=utf-8');
    session_write_close();
    $key = preg_replace('/[^a-z0-9]/i', '', (string)post('task_key', get_param('task_key', '')));
    $task = $key !== '' ? async_task_load('articlegen', $key) : null;
    if ($task === null) {
        json_response(['success' => false, 'done' => true]);
    }
    $prog = async_task_progress_read('articlegen', $key);
    json_response([
        'success'    => true,
        'done'       => ($task['phase'] ?? '') === 'done',
        'error'      => $task['error'] ?? null,
        'article_id' => $task['article_id'] ?? null,
        'phase'      => $task['phase'] ?? 'research',
        'progress'   => $prog ?: ['pct' => 1, 'title' => '', 'detail' => ''],
        'stale_sec'  => $prog ? max(0, time() - (int)($prog['ts'] ?? 0)) : 999,
    ]);
}

/* 🎯 پیشنهاد بهترین عنوان سئو برای عنوان دلخواه (فاز Q.5 — AJAX) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'suggest_titles') {
    Auth::enforceCsrf();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $customTitle = trim((string)post('custom_title'));
        if (mb_strlen($customTitle) < 5) {
            json_response(['success' => false, 'error' => 'عنوان دلخواه بسیار کوتاه است (حداقل ۵ نویسه).']);
        }
        $context = [];
        $brandId = (int)post('brand_id');
        if ($brandId > 0) {
            $brand = $db->fetch('SELECT name_fa FROM brands WHERE id = ?', [$brandId]);
            if ($brand) {
                $context['brand_fa'] = $brand['name_fa'];
            }
        }
        $deviceKey = trim((string)post('device_key'));
        if ($deviceKey !== '') {
            $device = $db->fetch('SELECT name_fa FROM brand_devices WHERE device_key = ? LIMIT 1', [$deviceKey]);
            if ($device) {
                $context['device_fa'] = $device['name_fa'];
            }
        }
        $titleGen = new TitleGenerator();
        json_response(['success' => true, 'data' => $titleGen->suggestForCustom($customTitle, $context, 8)]);
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

/* 🎯 بهبود خودکار سئو (فاز Q.6 — AJAX) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'improve_seo') {
    Auth::enforceCsrf();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $articleId = (int)post('article_id');
        $article = $db->fetch('SELECT * FROM brand_articles WHERE id = ?', [$articleId]);
        if (!$article) {
            json_response(['success' => false, 'error' => 'مقاله یافت نشد.'], 404);
        }
        $improver = new SeoImprover();
        $result = $improver->improve($article);
        $db->update('brand_articles', [
            'content'         => $result['content'],
            'seo_title'       => $result['seo_title'],
            'seo_description' => $result['seo_description'],
            'seo_keywords'    => implode(', ', $result['seo_keywords']),
            'seo_score'       => $result['seo_score'],
            'updated_at'      => date('Y-m-d H:i:s'),
        ], 'id = ?', [$articleId]);
        (new Cache())->delete('brand_articles_all');
        unset($result['content']);
        json_response(['success' => true, 'data' => $result]);
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

/* 🎯 بهبود «فقط یک سنجه» سئو (v2.6 — دکمه کنار هر سنجه) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'improve_seo_metric') {
    Auth::enforceCsrf();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $articleId = (int)post('article_id');
        $metric = (string)post('metric');
        $article = $db->fetch('SELECT * FROM brand_articles WHERE id = ?', [$articleId]);
        if (!$article) {
            json_response(['success' => false, 'error' => 'مقاله یافت نشد.'], 404);
        }
        $improver = new SeoImprover();
        $result = $improver->improveMetric($article, $metric);
        if (empty($result['applied'])) {
            json_response(['success' => false, 'error' => 'برای این سنجه مورد قابل اصلاحی یافت نشد — سنجه پاس شده یا نیازمند بازنویسی دستی است.'], 422);
        }
        $db->update('brand_articles', [
            'content'         => $result['content'],
            'seo_title'       => $result['seo_title'],
            'seo_description' => $result['seo_description'],
            'seo_keywords'    => implode(', ', $result['seo_keywords']),
            'seo_score'       => $result['seo_score'],
            'updated_at'      => date('Y-m-d H:i:s'),
        ], 'id = ?', [$articleId]);
        (new Cache())->delete('brand_articles_all');
        unset($result['content']);
        json_response(['success' => true, 'data' => $result]);
    } catch (Throwable $e) {
        json_response(['success' => false, 'error' => $e->getMessage()], 400);
    }
}

/* 👁 پیش‌نمایش مقاله — نمایش کامل شبیه سایت عمومی (v2.7) */
if ((int)get_param('preview') > 0) {
    $previewArticle = $db->fetch(
        'SELECT a.*, b.name_fa AS brand_name, b.logo AS brand_logo, b.extra_settings
         FROM brand_articles a JOIN brands b ON b.id = a.brand_id WHERE a.id = ?',
        [(int)get_param('preview')]
    );
    if ($previewArticle) {
        $extra = json_decode((string)($previewArticle['extra_settings'] ?? ''), true) ?: [];
        $pColor = $extra['palette']['primary'] ?? '#0e7490';
        $pAccent = $extra['palette']['accent'] ?? '#f59e0b';
        $wc = TextProcessor::wordCount(strip_tags((string)$previewArticle['content']));
        $readMin = max(1, (int)ceil($wc / 220));
        $tags = json_decode((string)($previewArticle['tags'] ?? '[]'), true) ?: [];
        ?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($previewArticle['title']) ?> — پیش‌نمایش</title>
<?= preview_font_html() /* 🔤 v2.14: فونت انتخابی سیستم — مثل سایت نهایی */ ?>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--font-body, Vazirmatn, Vazir, Tahoma, sans-serif); background: #f1f5f9; color: #1e293b; line-height: 1.95; font-size: 16px; }
/* 🔤 تیترها و عناصر تاکیدی با فونت تیتر انتخابی (مثل سایت واقعی) */
.pv-hero h1, .pv-content h2, .pv-content h3, .pv-content h4, .pv-tags span, .badge-preview, .pv-topbar b { font-family: var(--font-heading, Vazirmatn, Tahoma, sans-serif); }
.pv-topbar { position: sticky; top: 0; z-index: 50; background: #0f172a; color: #fff; padding: 10px 18px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 13px; }
.pv-topbar .badge-preview { background: #f59e0b; color: #78350f; font-weight: 800; border-radius: 8px; padding: 3px 10px; font-size: 12px; }
.pv-topbar a { color: #93c5fd; text-decoration: none; }
.pv-topbar .sep { color: #475569; }
.pv-hero { background: linear-gradient(135deg, <?= e($pColor) ?>, <?= e($pAccent) ?>); color: #fff; padding: 52px 20px 46px; text-align: center; }
.pv-hero h1 { font-size: clamp(21px, 4vw, 32px); max-width: 780px; margin: 0 auto 14px; line-height: 1.6; }
.pv-meta { display: flex; justify-content: center; gap: 16px; flex-wrap: wrap; font-size: 13px; opacity: .92; }
.pv-meta span { background: rgba(255,255,255,.16); border-radius: 20px; padding: 4px 14px; }
.pv-container { max-width: 780px; margin: -28px auto 48px; padding: 0 18px; }
.pv-card { background: #fff; border-radius: 16px; box-shadow: 0 12px 40px rgba(2,8,23,.10); padding: 34px 32px; }
.pv-featured { width: 100%; height: auto; object-fit: contain; border-radius: 12px; margin-bottom: 22px; display: block; }
.pv-og { border-radius: 12px; margin-bottom: 22px; width: 100%; display: block; border: 1px solid #e2e8f0; }
.pv-label { font-size: 12px; color: #64748b; margin: -14px 0 22px; text-align: center; }
.pv-content h2 { font-size: 21px; margin: 30px 0 12px; color: #0f172a; border-inline-start: 4px solid <?= e($pColor) ?>; padding-inline-start: 12px; }
.pv-content h3 { font-size: 17.5px; margin: 24px 0 10px; }
.pv-content p { margin-bottom: 16px; text-align: justify; }
.pv-content ul, .pv-content ol { margin: 0 24px 16px 0; }
.pv-content li { margin-bottom: 8px; }
.pv-content img { max-width: 100%; height: auto; object-fit: contain; border-radius: 10px; }
.pv-content a { color: <?= e($pColor) ?>; }
.pv-content blockquote { border-inline-start: 4px solid <?= e($pAccent) ?>; background: #f8fafc; padding: 14px 18px; border-radius: 10px; margin: 0 0 16px; }
.pv-content table { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 14.5px; }
.pv-content th, .pv-content td { border: 1px solid #e2e8f0; padding: 9px 12px; text-align: right; }
.pv-content th { background: #f1f5f9; }
.pv-content code { background: #f1f5f9; border-radius: 6px; padding: 2px 7px; font-size: 13.5px; direction: ltr; display: inline-block; }
.pv-tags { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 26px; padding-top: 20px; border-top: 1px dashed #e2e8f0; }
.pv-tags span { background: #f1f5f9; color: #475569; border-radius: 18px; padding: 4px 14px; font-size: 12.5px; }
.pv-excerpt { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 18px; font-size: 14.5px; color: #475569; margin-bottom: 22px; }
@media (max-width: 600px) { .pv-card { padding: 22px 16px; } .pv-content p { text-align: right; } }
</style>
</head>
<body>
<div class="pv-topbar">
    <span class="badge-preview">👁 پیش‌نمایش</span>
    <span>مقاله «<?= e(mb_substr($previewArticle['title'], 0, 40)) ?>»</span>
    <span class="sep">|</span>
    <a href="articles.php?edit=<?= (int)$previewArticle['id'] ?>">✏️ بازگشت به ویرایش</a>
    <a href="articles.php">📋 فهرست مقالات</a>
    <span class="sep">|</span>
    <span>وضعیت: <?= e($previewArticle['status'] === 'published' ? 'منتشرشده' : 'پیش‌نویس') ?></span>
</div>
<div class="pv-hero">
    <h1><?= e($previewArticle['title']) ?></h1>
    <div class="pv-meta">
        <span>🏷 <?= e($previewArticle['brand_name']) ?></span>
        <span>📅 <?= e(jdate((string)($previewArticle['created_at'] ?? 'now'))) ?></span>
        <span>⏱ ~<?= e(en_to_fa_digits((string)$readMin)) ?> دقیقه مطالعه</span>
        <span>👁 <?= e(en_to_fa_digits((string)($previewArticle['views'] ?? 0))) ?> بازدید</span>
    </div>
</div>
<div class="pv-container">
    <div class="pv-card">
        <?php if (!empty($previewArticle['featured_image'])): ?>
            <?php $pvFeAbs = ROOT_PATH . '/' . ltrim((string)$previewArticle['featured_image'], '/'); $pvFeV = is_file($pvFeAbs) ? '?v=' . filemtime($pvFeAbs) : ''; ?>
            <img class="pv-featured" src="<?= e(asset_url((string)$previewArticle['featured_image'])) . $pvFeV ?>" alt="تصویر شاخص">
        <?php endif; ?>
        <?php if (!empty($previewArticle['excerpt'])): ?>
            <div class="pv-excerpt">💡 <?= e($previewArticle['excerpt']) ?></div>
        <?php endif; ?>
        <div class="pv-content"><?= $previewArticle['content'] /* sanitize شده هنگام ذخیره */ ?></div>
        <?php if (!empty($previewArticle['og_image'])): ?>
            <div class="pv-label">🖼 تصویر OG (شبکه‌های اجتماعی)</div>
            <?php $pvOgAbs = ROOT_PATH . '/' . ltrim((string)$previewArticle['og_image'], '/'); $pvOgV = is_file($pvOgAbs) ? '?v=' . filemtime($pvOgAbs) : ''; ?>
            <img class="pv-og" src="<?= e(asset_url((string)$previewArticle['og_image'])) . $pvOgV ?>" alt="OG">
        <?php endif; ?>
        <?php if ($tags): ?>
            <div class="pv-tags"><?php foreach ($tags as $t): ?><span>#<?= e((string)$t) ?></span><?php endforeach; ?></div>
        <?php endif; ?>
    </div>
</div>
</body>
</html><?php
        exit;
    }
    flash('danger', 'مقاله موردنظر برای پیش‌نمایش یافت نشد.');
    redirect('articles.php');
}

$pageTitle = 'مدیریت مقالات';
$activeMenu = 'articles';
require __DIR__ . '/includes/header.php';

$brands = $db->fetchAll('SELECT id, name_fa FROM brands ORDER BY name_fa');
/* 🛂 v2.34 — ACL: brand_manager فقط برندهای تخصیص‌یافته را می‌بیند */
$_aclIds = (new Auth())->accessibleBrandIds();
if ($_aclIds !== null) {
    $brands = array_values(array_filter($brands, function ($_b) use ($_aclIds) {
        return in_array((int)$_b['id'], $_aclIds, true);
    }));
}
$editArticle = null;
$showGenerate = (int)get_param('generate') === 1 || (empty($brands) === false && get_param('generate') !== '');

/* ✏️ ویرایش مقاله */
if (($editId = (int)get_param('edit')) > 0) {
    $editArticle = $db->fetch(
        'SELECT a.*, b.name_fa AS brand_name FROM brand_articles a JOIN brands b ON b.id = a.brand_id WHERE a.id = ?',
        [$editId]
    );
}

/* 🔎 فیلترها */
$brandFilter = (int)get_param('brand');
$statusFilter = get_param('status');
$where = '1=1';
$params = [];
if ($brandFilter > 0) {
    $where .= ' AND a.brand_id = ?';
    $params[] = $brandFilter;
}
if ($statusFilter !== '') {
    $where .= ' AND a.status = ?';
    $params[] = $statusFilter;
}
$page = max(1, (int)get_param('p'));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$total = $db->count('brand_articles a', $where, $params);
$articles = $db->fetchAll(
    "SELECT a.*, b.name_fa AS brand_name, b.logo AS brand_logo
     FROM brand_articles a JOIN brands b ON b.id = a.brand_id
     WHERE {$where} ORDER BY a.id DESC LIMIT {$perPage} OFFSET {$offset}",
    $params
);
$statusMap = ['draft' => ['پیش‌نویس', 'badge-secondary'], 'scheduled' => ['زمان‌بندی', 'badge-warning'], 'published' => ['منتشرشده', 'badge-success']];
$categories = $db->fetchAll('SELECT id, name_fa FROM article_categories');
?>

<?php if ($editArticle): ?>
<!-- ✏️ فرم ویرایش مقاله -->
<div class="card">
    <div class="card-header">
        <h3>✏️ ویرایش مقاله — <?= e($editArticle['brand_name']) ?></h3>
        <div class="tools">
            <?php if ($editArticle['generated_by_ai']): ?><span class="badge badge-info">🤖 تولید AI</span><?php endif; ?>
            <a href="articles.php?preview=<?= (int)$editArticle['id'] ?>" target="_blank" class="btn btn-primary btn-sm">👁 پیش‌نمایش مقاله</a>
            <a href="articles.php" class="btn btn-outline btn-sm">بازگشت</a>
        </div>
    </div>
    <div class="card-body">
        <form method="post">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="article_id" value="<?= (int)$editArticle['id'] ?>">
            <div class="form-group">
                <label>عنوان مقاله</label>
                <input type="text" name="title" class="form-control" required value="<?= e($editArticle['title']) ?>">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>خلاصه (Excerpt)</label>
                    <textarea name="excerpt" class="form-control" rows="2"><?= e($editArticle['excerpt'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                    <label>تگ‌ها (با «،» جدا شوند)</label>
                    <input type="text" name="tags" class="form-control" value="<?= e(implode('، ', json_decode($editArticle['tags'] ?? '[]', true) ?: [])) ?>">
                </div>
            </div>
            <div class="form-group">
                <label>محتوا <small style="font-weight:400;color:#64748b">— ویرایشگر حرفه‌ای (S13): قالب‌بندی · تصویر · جدول · لینک · نمای کد</small></label>
                <?php /* ✍️ v2.44 (S13) — ویرایشگر غنی جایگزین textarea خام */ ?>
                <textarea name="content" class="rich-editor" rows="18"><?= e($editArticle['content']) ?></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>عنوان سئو</label>
                    <input type="text" name="seo_title" class="form-control" value="<?= e($editArticle['seo_title'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>متا توضیحات</label>
                    <input type="text" name="seo_description" class="form-control" value="<?= e($editArticle['seo_description'] ?? '') ?>">
                </div>
            </div>

            <?php $ogImage = (string)($editArticle['og_image'] ?? ''); ?>
            <div class="form-group">
                <label>📌 تصویر OG مقاله (شبکه‌های اجتماعی — ۱۲۰۰×۶۳۰)</label>
                <?php if ($ogImage !== ''): ?>
                    <?php
                    /* 🐛 v2.14: cache-buster بر اساس زمان فایل — بدون این، بعد از «تولید مجدد
                       تصاویر مقاله» مرورگر تصویر قدیمی کش‌شده (با کادر سفید قدیمی پشت لوگو)
                       را نشان می‌داد در حالی که فایل جدید سالم روی دیسک بود */
                    $ogAbs = ROOT_PATH . '/' . ltrim($ogImage, '/');
                    $ogV = is_file($ogAbs) ? '?v=' . filemtime($ogAbs) : '?v=' . time();
                    ?>
                    <img id="og-preview" src="<?= e(asset_url($ogImage)) . $ogV ?>" alt="پیش‌نمایش OG" style="max-width:340px;border-radius:11px;border:1px solid var(--border);display:block;margin-bottom:9px">
                <?php else: ?>
                    <img id="og-preview" src="" alt="" style="display:none;max-width:340px;border-radius:11px;border:1px solid var(--border);margin-bottom:9px">
                <?php endif; ?>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <input type="text" name="og_image" class="form-control" style="direction:ltr;text-align:left;flex:1;min-width:230px" value="<?= e($ogImage) ?>" placeholder="مسیر یا URL تصویر OG (خالی = بدون OG)">
                    <button type="button" id="btn-gen-og" class="btn btn-success btn-sm" title="تصویر OG یکتای مرتبط با همین مقاله با AI ساخته می‌شود">🎨 تولید OG با AI</button>
                    <button type="button" id="btn-regen-images" class="btn btn-outline btn-sm" title="۳ تصویر واقعی AI مرتبط با موضوع مقاله در همان لحظه ساخته، واترمارک‌دار و جایگزین تصاویر قبلی می‌شود">🖼️ تولید مجدد تصاویر مقاله</button>
                </div>
                <div class="hint">🎨 «تولید OG با AI» تصویری یکتا و مرتبط با موضوع همین مقاله می‌سازد (فقط واترمارک نمایندگی)؛ «تولید مجدد تصاویر مقاله» ۳ عکس واقعی لحظه‌ای مرتبط با موضوع می‌سازد (واترمارک برند + نمایندگی) و جایگزین قبلی‌ها می‌کند — با نوار پیشرفت زنده. سرویس تولید از «تنظیمات ← تولید تصویر مقاله» انتخاب می‌شود.</div>
            </div>

            <?php
            /* 📊 پنل آمار کامل سئو (فاز Q.6) */
            $seoStats = (new SeoImprover())->analyze($editArticle);
            $statusMeta = [
                'pass' => ['✅', 'badge-success'],
                'warn' => ['⚠️', 'badge-warning'],
                'fail' => ['❌', 'badge-danger'],
            ];
            ?>
            <div class="card" style="margin:18px 0;border-color:var(--primary)">
                <div class="card-header">
                    <h3>📊 آمار کامل سئو</h3>
                    <div class="tools" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                        <span class="badge <?= $seoStats['score'] >= 70 ? 'badge-success' : ($seoStats['score'] >= 55 ? 'badge-warning' : 'badge-danger') ?>" style="font-size:14px;padding:6px 14px">امتیاز: <?= en_to_fa_digits((string)$seoStats['score']) ?>/۱۰۰ — <?= e($seoStats['grade']) ?></span>
                        <button type="button" id="btn-improve-seo" class="btn btn-success" <?= $seoStats['fixable_count'] === 0 ? 'disabled title="مورد قابل اصلاح خودکار نیست"' : '' ?>>🎯 بهبود سئو (<?= en_to_fa_digits((string)$seoStats['fixable_count']) ?> مورد)</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="hint" style="margin-bottom:10px">🎯 کلیدواژه کانونی: <b><?= e($seoStats['focus_keyword'] ?: '—') ?></b> · <?= e($seoStats['summary']) ?></div>
                    <div id="seo-improve-report" style="display:none;margin-bottom:14px"></div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>سنجه</th><th>وضعیت</th><th>جزئیات</th><th>اصلاح خودکار</th><th>بهبود تک‌سنجه</th></tr></thead>
                            <tbody>
                            <?php foreach ($seoStats['checks'] as $check): ?>
                                <?php [$icon, $badge] = $statusMeta[$check['status']] ?? ['•', 'badge-secondary']; ?>
                                <tr>
                                    <td style="font-weight:600;white-space:nowrap"><?= e($check['label']) ?></td>
                                    <td><span class="badge <?= $badge ?>"><?= $icon ?></span></td>
                                    <td style="font-size:12px"><?= e($check['detail']) ?></td>
                                    <td><?= $check['fixable'] ? '<span class="badge badge-info">قابل اصلاح</span>' : '<span style="color:var(--text-light)">—</span>' ?></td>
                                    <td>
                                        <?php if ($check['fixable'] && $check['status'] !== 'pass'): ?>
                                            <button type="button" class="btn btn-success btn-sm btn-metric-improve" data-metric="<?= e($check['id']) ?>" data-label="<?= e($check['label']) ?>" title="فقط همین سنجه را بهبود بده">🎯 بهبود</button>
                                        <?php elseif ($check['status'] === 'pass'): ?>
                                            <span class="badge badge-success">✔ پاس شده</span>
                                        <?php else: ?>
                                            <span style="color:var(--text-light);font-size:11px">نیازمند بازنویسی</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="hint">💡 دکمه «بهبود سئو» (سربرگ) همه موارد قابل اصلاح را یکجا اعمال می‌کند؛ دکمه «🎯 بهبود» هر ردیف فقط همان سنجه را اصلاح می‌کند. حجم محتوا، ساختار هدینگ و خوانایی نیازمند بازنویسی مقاله هستند.</div>
                </div>
            </div>

            <!-- ⏰ v2.34 — زمان‌بندی انتشار -->
            <div class="form-row" style="background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:14px 16px">
                <div class="form-group" style="margin:0">
                    <label>⏰ وضعیت انتشار</label>
                    <select name="publish_mode" id="publish-mode" onchange="toggleScheduleBox()" style="max-width:230px">
                        <option value="keep">— بدون تغییر —</option>
                        <option value="draft">📝 پیش‌نویس</option>
                        <option value="publish">🚀 انتشار فوری</option>
                        <option value="schedule">⏰ زمان‌بندی انتشار</option>
                    </select>
                    <?php if (($editArticle['status'] ?? '') === 'scheduled'): ?>
                        <div class="hint" style="margin-top:6px;color:#b45309">
                            ⏰ این مقاله زمان‌بندی شده است: <?= e(fa_num(jdate('Y/m/d H:i', strtotime((string)$editArticle['published_at'])))) ?>
                            (تولید خودکار در سرِ وقت توسط cron)
                        </div>
                    <?php endif; ?>
                </div>
                <div class="form-group" id="schedule-box" style="margin:0;display:none">
                    <label>📅 تاریخ و ساعت انتشار (تایم‌زون تهران)</label>
                    <input type="datetime-local" name="schedule_at" id="schedule-at" class="form-control" style="max-width:230px;direction:ltr"
                           min="<?= e(date('Y-m-d\TH:i', time())) ?>">
                    <div class="hint" style="margin-top:6px">مقاله در این زمان به‌صورت خودکار منتشر می‌شود (نیازمند cron فعال — cron/cron.php هر ۵ دقیقه).</div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg">💾 ذخیره مقاله</button>
        </form>
    </div>
</div>

<script>
/* ⏰ نمایش/پنهان جعبه زمان‌بندی بر اساس انتخاب وضعیت */
function toggleScheduleBox() {
    var mode = document.getElementById('publish-mode');
    var box = document.getElementById('schedule-box');
    if (!mode || !box) { return; }
    box.style.display = mode.value === 'schedule' ? '' : 'none';
    if (mode.value === 'schedule') {
        var inp = document.getElementById('schedule-at');
        if (inp && !inp.value) {
            /* پیش‌فرض: فردا ساعت ۹ صبح */
            var d = new Date(Date.now() + 86400000);
            d.setHours(9, 0, 0, 0);
            var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
            inp.value = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        }
    }
}
</script>

<!-- 🖼️ v2.15 — مودال پیشرفت زنده تولید تصاویر مقاله (الگوی خطایاب: گرادیانت + درصد فارسی + لاگ مرحله‌به‌مرحله) -->
<div class="modal-overlay" id="imggen-modal" style="display:none">
    <div class="modal-box" style="max-width:560px">
        <div class="modal-header">
            <h3>🖼️ تولید تصاویر واقعی مقاله با AI</h3>
        </div>
        <div class="modal-body" style="padding:22px">
            <div class="imggen-bar">
                <div class="imggen-bar-fill" id="imggen-bar">
                    <span id="imggen-percent">۰٪</span>
                </div>
            </div>
            <div class="imggen-title" id="imggen-title">آماده‌سازی...</div>
            <div class="imggen-detail" id="imggen-detail">در حال اتصال به سرویس تولید تصویر...</div>
            <div class="imggen-log" id="imggen-log"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="imggen-close" style="display:none" onclick="document.getElementById('imggen-modal').style.display='none';window.location.reload()">بستن و بروزرسانی</button>
        </div>
    </div>
</div>
<style>
/* 🖼️ v2.15 — مودال پیشرفت تولید تصاویر (خوداتکا — مستقل از صفحات دیگر) */
#imggen-modal.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.58);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;z-index:1000;padding:16px}
#imggen-modal .modal-box{background:var(--card-bg,#fff);border-radius:15px;max-width:560px;width:100%;max-height:92vh;overflow:auto;box-shadow:0 22px 60px rgba(0,0,0,.28)}
#imggen-modal .modal-header{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
#imggen-modal .modal-header h3{margin:0;font-size:16px}
#imggen-modal .modal-footer{padding:14px 22px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end}
.imggen-bar{height:24px;border-radius:14px;background:var(--bg-secondary,#e2e8f0);overflow:hidden;box-shadow:inset 0 2px 5px rgba(0,0,0,.08)}
.imggen-bar-fill{height:100%;width:0%;border-radius:14px;background:linear-gradient(90deg,#7c3aed,#2563eb 55%,#0ea5e9);transition:width .6s ease;position:relative;display:flex;align-items:center;justify-content:center;min-width:44px}
.imggen-bar-fill::after{content:'';position:absolute;inset:0;border-radius:14px;background:linear-gradient(110deg,transparent 30%,rgba(255,255,255,.5) 50%,transparent 70%);background-size:220% 100%;animation:imggenShine 1.6s linear infinite}
@keyframes imggenShine{from{background-position:200% 0}to{background-position:-60% 0}}
.imggen-bar-fill span{font-size:12.5px;font-weight:800;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.35);position:relative;z-index:1}
.imggen-title{font-weight:800;font-size:15px;margin:14px 0 4px}
.imggen-detail{font-size:12.5px;color:var(--text-light);margin-bottom:12px;min-height:19px}
.imggen-log{max-height:200px;overflow:auto;font-size:12px;line-height:2;border:1px dashed var(--border);border-radius:10px;padding:8px 12px;background:rgba(0,0,0,.02)}
.imggen-log .lg{display:flex;gap:8px;align-items:flex-start;border-bottom:1px dashed rgba(0,0,0,.06);padding:2px 0}
.imggen-log .lg:last-child{border-bottom:none}
.imggen-log .lg .ic{min-width:18px;text-align:center}
</style>

<?php else: ?>

<?php if (!empty($showGenerate) && !empty($brands)): ?>
<!-- 🤖 فرم تولید با AI -->
<div class="card" style="border-color:var(--primary)">
    <div class="card-header"><h3>🤖 تولید مقاله با هوش مصنوعی داخلی</h3></div>
    <div class="card-body">
        <form method="post">
            <?= Auth::csrfField() ?>
            <input type="hidden" name="action" value="generate">
            <div class="form-row-3">
                <div class="form-group">
                    <label>برند</label>
                    <select name="brand_id" id="gen-brand" class="form-control" required>
                        <?php foreach ($brands as $brand): ?>
                            <option value="<?= (int)$brand['id'] ?>"><?= e($brand['name_fa']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>نوع مقاله (۲۵ نوع)</label>
                    <select name="topic_type" class="form-control">
                        <optgroup label="🔧 فنی و عیب‌یابی">
                            <option value="troubleshooting">رفع ایراد و مشکلات رایج</option>
                            <option value="error_codes">کدهای خطا و ریست</option>
                            <option value="symptom_focus">عیب‌یابی علامت‌محور (روشن نمی‌شود، صدا، نشتی...)</option>
                            <option value="case_study">مطالعه موردی تعمیر واقعی</option>
                            <option value="diy_vs_pro">تعمیر شخصی یا تخصصی؟</option>
                        </optgroup>
                        <optgroup label="📘 آموزشی و راهنما">
                            <option value="user_guide">راهنمای استفاده</option>
                            <option value="installation_guide">راهنمای نصب و راه‌اندازی</option>
                            <option value="common_mistakes">اشتباهات رایج کاربران</option>
                            <option value="checklist">چک‌لیست بازدید و نگهداری</option>
                            <option value="glossary">واژه‌نامه تخصصی</option>
                            <option value="tech_explainer">فناوری‌های به‌کاررفته (اینورتر و...)</option>
                        </optgroup>
                        <optgroup label="🛡️ نگهداری و ایمنی">
                            <option value="maintenance">نگهداری و سرویس دوره‌ای</option>
                            <option value="seasonal_care">مراقبت فصلی</option>
                            <option value="safety_guide">نکات ایمنی و احتیاط</option>
                            <option value="expert_tips">نکات حرفه‌ای تکنسین‌ها</option>
                            <option value="environment">محیط زیست و بازیافت</option>
                        </optgroup>
                        <optgroup label="💰 خرید و هزینه">
                            <option value="buying_guide">راهنمای خرید</option>
                            <option value="comparison">مقایسه مدل‌ها</option>
                            <option value="cost_guide">راهنمای هزینه تعمیر</option>
                            <option value="warranty_guide">گارانتی و خدمات پس از فروش</option>
                            <option value="energy_saving">صرفه‌جویی انرژی و قبض</option>
                        </optgroup>
                        <optgroup label="📚 اعتمادسازی و محتوا">
                            <option value="myths_facts">باورهای غلط در برابر واقعیت</option>
                            <option value="history_evolution">تاریخچه و تکامل</option>
                            <option value="statistics">آمار و ارقام صنعت</option>
                            <option value="service_process">فرآیند تعمیر در نمایندگی</option>
                        </optgroup>
                    </select>
                </div>
                <div class="form-group">
                    <label>دستگاه (خالی = تصادفی)</label>
                    <select name="device_key" class="form-control" id="gen-device">
                        <option value="">— تصادفی —</option>
                        <?php foreach ($db->fetchAll('SELECT DISTINCT device_key, name_fa FROM brand_devices ORDER BY name_fa') as $device): ?>
                            <option value="<?= e($device['device_key']) ?>"><?= e($device['name_fa']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>عنوان دلخواه (اختیاری — فاز Q.5)</label>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <input type="text" name="custom_title" id="gen-custom-title" class="form-control" style="flex:1;min-width:220px" placeholder="مثلاً: چرا یخچال سامسونگ من سرد نمی‌کند؟" maxlength="120">
                    <button type="button" id="btn-suggest-titles" class="btn btn-outline" style="white-space:nowrap">🎯 پیشنهاد بهترین عنوان سئو</button>
                </div>
                <div class="hint" style="margin-top:6px">اگر خالی بگذارید، عنوان از قالب‌های نوع مقاله انتخاب می‌شود. با وارد کردن عنوان دلخواه، مقاله حول همان عنوان نوشته می‌شود.</div>
                <div id="title-suggestions" style="display:none;margin-top:12px" class="seo-stats"></div>
            </div>
            <div class="form-row" style="gap:16px;align-items:flex-start;flex-wrap:wrap">
                <label style="display:flex;gap:8px;align-items:center;font-weight:600;cursor:pointer;margin:0">
                    <input type="checkbox" name="research" value="1" checked style="width:18px;height:18px">
                    <span>🔎 استفاده از جستجوی اینترنت هنگام نوشتن</span>
                </label>
                <label style="display:flex;gap:8px;align-items:center;font-weight:600;margin:0">
                    <span>🎚️ عمق جستجو</span>
                    <select name="research_depth" class="form-control" style="width:auto;min-width:170px">
                        <option value="balanced" selected>متعادل (≈۹۰ ثانیه)</option>
                        <option value="fast">سریع (≈۴۰ ثانیه)</option>
                        <option value="deep">عمیق (≈۲ تا ۳ دقیقه)</option>
                    </select>
                </label>
                <label style="display:flex;gap:8px;align-items:center;font-weight:600;cursor:pointer;margin:0">
                    <input type="checkbox" name="with_images" value="1" checked style="width:18px;height:18px">
                    <span>🖼️ تصاویر خودکار مقاله (۳ تصویر)</span>
                </label>
            </div>
            <div class="hint" style="margin-top:6px">🔎 جستجوی آنلاین (روشن پیش‌فرض): کوئری‌های فارسی+انگلیسی، خواندن متن کامل صفحات، استخراجِ سرفصل‌های واقعیِ رقبا، آمارِ منبع‌دار و پرسش‌های کاربران به مقاله اضافه می‌شود. 🎚️ «عمیق» دو موتور جستجو و تا ۷ صفحه را می‌خواند (زمان بیشتر، محتوای غنی‌تر). 🖼️ تصاویر با alt و کپشن استاندارد در متن درج می‌شوند.</div>
            <button type="submit" id="btn-generate-article" class="btn btn-success btn-lg">🚀 تولید مقاله یکتا</button>
            <div class="hint" style="margin-top:8px">موتور AI محتوای ۸۰۰-۱۵۰۰ کلمه‌ای یکتا با لینک داخلی، سئو و اصلاح خودکار نگارش فارسی تولید می‌کند. تولید به‌صورت «گام‌به‌گام» انجام می‌شود و پیشرفت هر مرحله زنده نمایش داده می‌شود — دیگر هیچ تایم‌اوت سروری رخ نمی‌دهد.</div>
        </form>
    </div>
</div>

<!-- 🪜 v2.38 — مودال پیشرفت زنده‌ی تولید مقاله (الگوی imggen: گرادیانت + درصد فارسی + لاگ گام‌ها) -->
<div class="modal-overlay" id="articlegen-modal" style="display:none">
    <div class="modal-box" style="max-width:600px">
        <div class="modal-header">
            <h3>🤖 تولید مقاله با هوش مصنوعی — زنده</h3>
        </div>
        <div class="modal-body" style="padding:22px">
            <div class="imggen-bar">
                <div class="imggen-bar-fill" id="agen-bar">
                    <span id="agen-percent">۱٪</span>
                </div>
            </div>
            <div class="imggen-title" id="agen-title">آماده‌سازی...</div>
            <div class="imggen-detail" id="agen-detail">در حال آغاز صف گام‌به‌گام...</div>
            <div class="agen-steps" id="agen-steps">
                <span class="st" data-ph="research">🔎 تحقیق وب</span>
                <span class="st" data-ph="compose">✍️ نگارش مقاله</span>
                <span class="st" data-ph="save">💾 ثبت</span>
                <span class="st" data-ph="images">🖼️ تصاویر AI</span>
            </div>
            <div class="imggen-log" id="agen-log"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" id="agen-retry" style="display:none">↻ تلاش مجدد این گام</button>
            <button type="button" class="btn btn-success" id="agen-open" style="display:none">✏️ باز کردن ویرایشگر مقاله</button>
            <button type="button" class="btn btn-outline" id="agen-close" style="display:none">بستن</button>
        </div>
    </div>
</div>
<style>
/* 🪜 v2.38 — مودال تولید گام‌به‌گام مقاله (خوداتکا — هم‌خانواده‌ی imggen) */
#articlegen-modal.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.58);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;z-index:1000;padding:16px}
#articlegen-modal .modal-box{background:var(--card-bg,#fff);border-radius:15px;max-width:600px;width:100%;max-height:92vh;overflow:auto;box-shadow:0 22px 60px rgba(0,0,0,.28)}
#articlegen-modal .modal-header{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
#articlegen-modal .modal-header h3{margin:0;font-size:16px}
#articlegen-modal .modal-footer{padding:14px 22px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
.agen-steps{display:flex;gap:6px;flex-wrap:wrap;margin:12px 0}
.agen-steps .st{font-size:11.5px;font-weight:700;padding:5px 12px;border-radius:20px;background:var(--bg-secondary,#e2e8f0);color:var(--text-light,#64748b);border:1px solid transparent;transition:all .35s ease}
.agen-steps .st.active{background:linear-gradient(90deg,#7c3aed,#2563eb);color:#fff;box-shadow:0 3px 10px rgba(124,58,237,.35)}
.agen-steps .st.done{background:rgba(16,185,129,.14);color:#059669;border-color:rgba(16,185,129,.4)}
</style>
<?php endif; ?>

<!-- 🔎 فیلتر و لیست -->
<div class="card">
    <div class="card-header">
        <h3>📰 مقالات (<?= en_to_fa_digits((string)$total) ?>)</h3>
        <div class="tools">
            <form method="get" style="display:flex;gap:8px;flex-wrap:wrap">
                <select name="brand" class="form-control" style="max-width:170px;min-width:140px">
                    <option value="">همه برندها</option>
                    <?php foreach ($brands as $brand): ?>
                        <option value="<?= (int)$brand['id'] ?>" <?= $brandFilter === (int)$brand['id'] ? 'selected' : '' ?>><?= e($brand['name_fa']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" class="form-control" style="max-width:150px;min-width:120px">
                    <option value="">همه وضعیت‌ها</option>
                    <?php foreach ($statusMap as $key => [$label]): ?>
                        <option value="<?= $key ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline">فیلتر</button>
                <a href="articles.php?generate=1" class="btn btn-success">🤖 تولید جدید</a>
            </form>
        </div>
    </div>
    <div class="table-wrap">
        <?php if (empty($articles)): ?>
            <div class="empty-state"><div class="icon">📰</div><p>مقاله‌ای یافت نشد.<br><small>با دکمه «تولید جدید» اولین مقاله را با AI بسازید.</small></p></div>
        <?php else: ?>
        <table class="table">
            <thead><tr><th>عنوان</th><th>برند</th><th>وضعیت</th><th>کلمات</th><th>سئو</th><th>انتشار</th><th>عملیات</th></tr></thead>
            <tbody>
            <?php foreach ($articles as $article): ?>
                <tr>
                    <td style="max-width:320px">
                        <div style="font-weight:700;font-size:12.5px"><?= e(excerpt($article['title'], 60)) ?></div>
                        <small style="color:var(--text-light);direction:ltr;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($article['slug']) ?></small>
                    </td>
                    <td><div class="brand-cell"><span class="name" style="font-size:12px"><?= e($article['brand_name']) ?></span></div></td>
                    <td>
                        <?php if ($article['status'] === 'scheduled'): ?>
                            <span class="badge badge-warning" title="<?= e($article['published_at'] ?? '') ?>">⏰ <?= jdate($article['published_at'] ?? '') ?></span>
                        <?php else: ?>
                            <span class="badge <?= $statusMap[$article['status']][1] ?>"><?= $statusMap[$article['status']][0] ?></span>
                        <?php endif; ?>
                        <?= $article['generated_by_ai'] ? '<span class="badge badge-info">AI</span>' : '' ?>
                    </td>
                    <td><?= en_to_fa_digits((string)TextProcessor::wordCount(strip_tags((string)$article['content']))) ?></td>
                    <td><span class="badge <?= ($article['seo_score'] ?? 0) >= 60 ? 'badge-success' : 'badge-warning' ?>"><?= en_to_fa_digits((string)($article['seo_score'] ?? 0)) ?></span></td>
                    <td style="font-size:11px;color:var(--text-light)"><?= $article['published_at'] ? jdate($article['published_at']) : '—' ?></td>
                    <td>
                        <div class="actions">
                            <a href="articles.php?preview=<?= (int)$article['id'] ?>" target="_blank" class="btn btn-outline btn-sm" title="پیش‌نمایش مقاله">👁</a>
                            <a href="articles.php?edit=<?= (int)$article['id'] ?>" class="btn btn-outline btn-sm">✏️</a>
                            <?php if ($article['status'] !== 'published'): ?>
                                <form method="post" style="display:inline"><?= Auth::csrfField() ?><input type="hidden" name="action" value="status"><input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>"><input type="hidden" name="status" value="published"><button class="btn btn-success btn-sm" title="انتشار">▶️</button></form>
                            <?php else: ?>
                                <form method="post" style="display:inline"><?= Auth::csrfField() ?><input type="hidden" name="action" value="status"><input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>"><input type="hidden" name="status" value="draft"><button class="btn btn-outline btn-sm" title="پیش‌نویس">⏸️</button></form>
                            <?php endif; ?>
                            <form method="post" style="display:inline" data-confirm="این مقاله حذف شود؟"><?= Auth::csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="article_id" value="<?= (int)$article['id'] ?>"><button class="btn btn-danger btn-sm">🗑️</button></form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php if ($total > $perPage): ?>
        <div class="pagination">
            <?php for ($p = 1; $p <= ceil($total / $perPage); $p++): ?>
                <?php if ($p === $page): ?>
                    <span class="current"><?= en_to_fa_digits((string)$p) ?></span>
                <?php else: ?>
                    <a href="?p=<?= $p ?>&brand=<?= $brandFilter ?>&status=<?= e($statusFilter) ?>"><?= en_to_fa_digits((string)$p) ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- 🎯 فاز Q.5 + Q.6: پیشنهاد عنوان سئو + بهبود خودکار سئو -->
<script>
(function () {
    'use strict';

    /* ---------- 🎯 بهبود خودکار سئو (فاز Q.6) ---------- */
    var improveBtn = document.getElementById('btn-improve-seo');
    var reportBox = document.getElementById('seo-improve-report');
    if (improveBtn && reportBox) {
        improveBtn.addEventListener('click', function () {
            var run = function () {
                var csrf = document.querySelector('input[name="csrf_token"]');
                var articleId = document.querySelector('input[name="article_id"]');
                improveBtn.disabled = true;
                improveBtn.textContent = '⏳ در حال اعمال اصلاحات سئو...';
                reportBox.style.display = 'block';
                reportBox.innerHTML = '<div style="padding:12px;color:var(--text-light)">در حال تحلیل و اصلاح مقاله... (نگارش، کلیدواژه، متا، لینک‌ها، FAQ و TOC)</div>';

                var body = new URLSearchParams();
                body.append('action', 'improve_seo');
                body.append('article_id', articleId ? articleId.value : '0');
                if (csrf) { body.append('csrf_token', csrf.value); }

                fetch('articles.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf ? csrf.value : '', 'X-Requested-With': 'XMLHttpRequest' },
                    body: body.toString(),
                    credentials: 'same-origin'
                }).then(function (r) { return r.json(); }).then(function (res) {
                    improveBtn.disabled = false;
                    improveBtn.textContent = '🎯 بهبود سئو (اجرای مجدد)';
                    if (!res.success) {
                        reportBox.innerHTML = '<div style="padding:12px;color:#e74c3c">خطا: ' + (res.error || 'نامشخص') + '</div>';
                        if (window.sahandToast) { sahandToast({ message: res.error || 'خطا در بهبود سئو', type: 'danger' }); }
                        return;
                    }
                    var d = res.data;
                    var fa = function (n) { return String(n).replace(/[0-9]/g, function (x) { return '۰۱۲۳۴۵۶۷۸۹'[+x]; }); };
                    var gainColor = d.gain > 0 ? '#27ae60' : '#e67e22';
                    var html = '<div style="border:1px solid ' + gainColor + ';border-radius:10px;padding:14px;background:#fafefe">';
                    html += '<div style="font-weight:700;margin-bottom:8px">🎯 نتیجه بهبود سئو: ' + fa(d.before.score) + ' ← <span style="color:' + gainColor + ';font-size:16px">' + fa(d.after.score) + '</span> (' + (d.gain > 0 ? '+' + fa(d.gain) : 'بدون تغییر') + ' امتیاز) — ' + d.after.grade + '</div>';
                    if (d.applied && d.applied.length) {
                        html += '<div style="font-weight:600;margin:8px 0 4px">✅ اصلاحات اعمال‌شده:</div><ul style="margin:0;padding-right:20px;font-size:12.5px">';
                        d.applied.forEach(function (a) { html += '<li style="margin-bottom:3px">' + a + '</li>'; });
                        html += '</ul>';
                    } else {
                        html += '<div style="color:var(--text-light);font-size:12.5px">این مقاله از قبل بهینه است — مورد قابل اصلاح جدیدی یافت نشد.</div>';
                    }
                    html += '<div style="margin-top:10px;font-size:12px;color:var(--text-light)">🔄 برای دیدن محتوای بهبودیافته، صفحه را رفرش کنید.</div></div>';
                    reportBox.innerHTML = html;
                    if (window.sahandToast) { sahandToast({ message: d.gain > 0 ? 'سئو ' + fa(d.before.score) + ' → ' + fa(d.after.score) + ' (' + (d.gain > 0 ? '+' + fa(d.gain) : '') + ')' : 'امتیاز تغییری نکرد', type: d.gain > 0 ? 'success' : 'info' }); }
                }).catch(function (err) {
                    improveBtn.disabled = false;
                    improveBtn.textContent = '🎯 بهبود سئو';
                    reportBox.innerHTML = '<div style="padding:12px;color:#e74c3c">خطای ارتباط با سرور: ' + err.message + '</div>';
                });
            };
            if (window.sahandConfirm) {
                sahandConfirm({ title: 'بهبود خودکار سئو', message: 'همه اصلاحات سئو به‌صورت خودکار روی این مقاله اعمال و ذخیره شود؟', type: 'question', confirmText: 'بله، بهبود بده', confirmIcon: '🎯' }).then(function (ok) { if (ok) { run(); } });
            } else { run(); }
        });
    }

    /* ---------- 🎨 تولید OG با AI (v2.6) ---------- */
    var genOgBtn = document.getElementById('btn-gen-og');
    if (genOgBtn) {
        genOgBtn.addEventListener('click', function () {
            var run = function () {
                var csrf = document.querySelector('input[name="csrf_token"]');
                var articleId = document.querySelector('input[name="article_id"]');
                genOgBtn.disabled = true;
                genOgBtn.textContent = '⏳ در حال ساخت تصویر OG...';
                var body = new URLSearchParams();
                body.append('action', 'gen_og');
                body.append('article_id', articleId ? articleId.value : '0');
                if (csrf) { body.append('csrf_token', csrf.value); }
                fetch('articles.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf ? csrf.value : '', 'X-Requested-With': 'XMLHttpRequest' },
                    body: body.toString(),
                    credentials: 'same-origin'
                }).then(function (r) { return r.json(); }).then(function (res) {
                    genOgBtn.disabled = false;
                    genOgBtn.textContent = '🎨 تولید OG با AI';
                    if (!res.success) {
                        if (window.sahandToast) { sahandToast({ message: res.error || 'تولید OG ناموفق بود', type: 'danger' }); }
                        return;
                    }
                    var preview = document.getElementById('og-preview');
                    if (preview) {
                        preview.src = res.data.url;
                        preview.style.display = 'block';
                    }
                    var input = document.querySelector('input[name="og_image"]');
                    if (input) { input.value = res.data.path; }
                    if (window.sahandToast) { sahandToast({ message: 'تصویر OG یکتا ساخته شد — برای ثبت، مقاله را ذخیره کنید', type: 'success', duration: 5000 }); }
                }).catch(function (err) {
                    genOgBtn.disabled = false;
                    genOgBtn.textContent = '🎨 تولید OG با AI';
                    if (window.sahandToast) { sahandToast({ message: 'خطای ارتباط با سرور: ' + err.message, type: 'danger' }); }
                });
            };
            if (window.sahandConfirm) {
                sahandConfirm({ title: 'تولید تصویر OG با AI', message: 'تصویر OG یکتای مرتبط با موضوع همین مقاله ساخته و جایگزین فعلی شود؟ (هر بار کلیک = ترکیب بصری جدید)', type: 'question', confirmText: 'بله، بساز', confirmIcon: '🎨' }).then(function (ok) { if (ok) { run(); } });
            } else { run(); }
        });
    }

    /* ---------- 🖼️ تولید مجدد تصاویر مقاله (v2.15 — AJAX + نوار پیشرفت زنده) ---------- */
    var regenBtn = document.getElementById('btn-regen-images');
    if (regenBtn) {
        regenBtn.addEventListener('click', function () {
            var run = function () {
                var csrf = document.querySelector('input[name="csrf_token"]');
                var articleId = document.querySelector('input[name="article_id"]');
                var aid = articleId ? articleId.value : '0';
                var progressKey = 'ig' + Date.now() + Math.random().toString(36).slice(2, 10);

                /* 🎬 مودال پیشرفت */
                var modal = document.getElementById('imggen-modal');
                var bar = document.getElementById('imggen-bar');
                var pctEl = document.getElementById('imggen-percent');
                var titleEl = document.getElementById('imggen-title');
                var detailEl = document.getElementById('imggen-detail');
                var logEl = document.getElementById('imggen-log');
                var closeBtn = document.getElementById('imggen-close');
                logEl.innerHTML = '';
                closeBtn.style.display = 'none';
                bar.style.width = '2%';
                pctEl.textContent = '۲٪';
                titleEl.textContent = 'آماده‌سازی...';
                detailEl.textContent = 'در حال اتصال به سرویس تولید تصویر...';
                modal.style.display = 'flex';
                regenBtn.disabled = true;
                regenBtn.textContent = '⏳ در حال تولید...';

                var faDig = function (n) { return String(n).replace(/[0-9]/g, function (x) { return '۰۱۲۳۴۵۶۷۸۹'[+x]; }); };
                var lastLog = '';
                function addLog(icon, text) {
                    if (!text || text === lastLog) { return; }
                    lastLog = text;
                    var div = document.createElement('div');
                    div.className = 'lg';
                    div.innerHTML = '<span class="ic">' + icon + '</span><span>' + text + '</span>';
                    logEl.insertBefore(div, logEl.firstChild);
                }

                /* 🔄 polling روند پیشرفت از فایل کش (۸۰۰ms — الگوی خطایاب) */
                var pollTimer = setInterval(function () {
                    var pb = new URLSearchParams();
                    pb.append('action', 'imggen_progress');
                    pb.append('progress_key', progressKey);
                    fetch('articles.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                        body: pb.toString(),
                        credentials: 'same-origin'
                    }).then(function (r) { return r.json(); }).then(function (res) {
                        if (res.success && res.progress) {
                            var p = res.progress;
                            if (typeof p.pct === 'number' && p.pct > 0) {
                                bar.style.width = Math.max(2, Math.min(96, p.pct)) + '%';
                                pctEl.textContent = faDig(Math.max(2, Math.min(96, p.pct))) + '٪';
                            }
                            if (p.title) { titleEl.textContent = p.title; }
                            if (p.detail) { detailEl.textContent = p.detail; addLog('🔄', (p.title || '') + (p.detail ? ' — ' + p.detail : '')); }
                        }
                    }).catch(function () { /* polling بی‌صدا رد می‌شود */ });
                }, 800);

                /* 🚀 اجرای تولید (درخواست اصلی) */
                var body = new URLSearchParams();
                body.append('action', 'regen_images');
                body.append('article_id', aid);
                body.append('progress_key', progressKey);
                if (csrf) { body.append('csrf_token', csrf.value); }

                fetch('articles.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf ? csrf.value : '', 'X-Requested-With': 'XMLHttpRequest' },
                    body: body.toString(),
                    credentials: 'same-origin'
                }).then(function (r) { return r.json(); }).then(function (res) {
                    clearInterval(pollTimer);
                    regenBtn.disabled = false;
                    regenBtn.textContent = '🖼️ تولید مجدد تصاویر مقاله';
                    if (!res.success) {
                        bar.style.width = '100%';
                        bar.style.background = 'linear-gradient(90deg,#dc2626,#ef4444)';
                        titleEl.textContent = '❌ تولید ناموفق بود';
                        detailEl.textContent = res.error || 'خطای نامشخص';
                        addLog('❌', res.error || 'خطای نامشخص');
                        closeBtn.style.display = '';
                        return;
                    }
                    bar.style.width = '100%';
                    pctEl.textContent = '۱۰۰٪';
                    var isAi = res.photo_source === 'ai_photo';
                    var svc = res.service_label ? ('با سرویس «' + res.service_label + '»') : '';
                    titleEl.textContent = isAi ? '✅ تصاویر واقعی AI تولید و جایگزین شد' : '⚠️ تصاویر بسته دستگاه جایگزین شد';
                    var count = (res.images || []).length + (res.featured ? 1 : 0);
                    detailEl.textContent = isAi
                        ? faDig(count) + ' تصویر واقعی مرتبط با موضوع ' + svc + ' ساخته شد — واترمارک برند + نمایندگی مهر خورد و تصویر OG تازه شد.'
                        : 'سرویس‌های تولید تصویر پاسخ ندادند — بسته عکس‌های دستگاه (آماده) با واترمارک جایگزین شد. از «تنظیمات ← تولید تصویر مقاله» سرویس را تست/تغییر دهید.';
                    addLog(isAi ? '✅' : '⚠️', detailEl.textContent);
                    if (res.og && res.og.url) {
                        var pv = document.getElementById('og-preview');
                        if (pv) { pv.src = res.og.url; pv.style.display = 'block'; }
                        var ogInput = document.querySelector('input[name="og_image"]');
                        if (ogInput) { ogInput.value = res.og.path; }
                    }
                    addLog('📌', 'تصویر OG جدید ساخته شد (فقط واترمارک نمایندگی)');
                    closeBtn.style.display = '';
                }).catch(function (err) {
                    clearInterval(pollTimer);
                    regenBtn.disabled = false;
                    regenBtn.textContent = '🖼️ تولید مجدد تصاویر مقاله';
                    bar.style.width = '100%';
                    bar.style.background = 'linear-gradient(90deg,#dc2626,#ef4444)';
                    titleEl.textContent = '❌ خطای ارتباط با سرور';
                    detailEl.textContent = err.message || 'ارتباط قطع شد';
                    closeBtn.style.display = '';
                });
            };
            if (window.sahandConfirm) {
                sahandConfirm({
                    title: 'تولید مجدد تصاویر مقاله',
                    message: '۳ تصویر واقعی AI (۱ شاخص + ۲ درون‌متن) مرتبط با موضوع همین مقاله، در همان لحظه با سرویس انتخابی تنظیمات ساخته و واترمارک‌دار جایگزین تصاویر قبلی شوند؟',
                    type: 'question', confirmText: 'بله، بساز', confirmIcon: '🖼️'
                }).then(function (ok) { if (ok) { run(); } });
            } else { run(); }
        });
    }

    /* ---------- 🎯 بهبود تک‌سنجه (v2.6 — دکمه کنار هر سنجه) ---------- */
    var metricButtons = document.querySelectorAll('.btn-metric-improve');
    Array.prototype.forEach.call(metricButtons, function (mbtn) {
        mbtn.addEventListener('click', function () {
            var metric = mbtn.getAttribute('data-metric');
            var label = mbtn.getAttribute('data-label') || metric;
            var run = function () {
                var csrf = document.querySelector('input[name="csrf_token"]');
                var articleId = document.querySelector('input[name="article_id"]');
                mbtn.disabled = true;
                mbtn.innerHTML = '⏳ در حال بهبود...';
                var body = new URLSearchParams();
                body.append('action', 'improve_seo_metric');
                body.append('article_id', articleId ? articleId.value : '0');
                body.append('metric', metric);
                if (csrf) { body.append('csrf_token', csrf.value); }

                fetch('articles.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf ? csrf.value : '', 'X-Requested-With': 'XMLHttpRequest' },
                    body: body.toString(),
                    credentials: 'same-origin'
                }).then(function (r) { return r.json(); }).then(function (res) {
                    if (!res.success) {
                        mbtn.disabled = false;
                        mbtn.innerHTML = '🎯 بهبود';
                        if (window.sahandToast) { sahandToast({ message: res.error || 'بهبود ممکن نشد', type: 'warning' }); }
                        return;
                    }
                    var d = res.data;
                    var fa = function (n) { return String(n).replace(/[0-9]/g, function (x) { return '۰۱۲۳۴۵۶۷۸۹'[+x]; }); };
                    mbtn.innerHTML = '✅ ' + fa(d.after.score) + '/۱۰۰';
                    if (window.sahandToast) { sahandToast({ message: '«' + label + '» بهبود یافت — امتیاز کل: ' + fa(d.before.score) + ' → ' + fa(d.after.score), type: 'success' }); }
                    setTimeout(function () { window.location.reload(); }, 1400);
                }).catch(function (err) {
                    mbtn.disabled = false;
                    mbtn.innerHTML = '🎯 بهبود';
                    if (window.sahandToast) { sahandToast({ message: 'خطای ارتباط با سرور: ' + err.message, type: 'danger' }); }
                });
            };
            if (window.sahandConfirm) {
                sahandConfirm({ title: 'بهبود تک‌سنجه', message: 'فقط سنجه «' + label + '» بهبود داده و ذخیره شود؟ (سایر بخش‌های مقاله دست‌نخورده می‌مانند)', type: 'question', confirmText: 'بله، بهبود بده', confirmIcon: '🎯' }).then(function (ok) { if (ok) { run(); } });
            } else { run(); }
        });
    });

    /* ---------- 🎯 پیشنهاد بهترین عنوان سئو (فاز Q.5) ---------- */
    var btn = document.getElementById('btn-suggest-titles');
    var input = document.getElementById('gen-custom-title');
    var box = document.getElementById('title-suggestions');
    if (!btn || !input || !box) { return; }

    function faNum(n) { return String(n).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; }); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function scoreBadge(s) {
        var cls = s >= 75 ? 'badge-success' : (s >= 55 ? 'badge-warning' : 'badge-secondary');
        return '<span class="badge ' + cls + '">' + faNum(s) + '/۱۰۰</span>';
    }

    btn.addEventListener('click', function () {
        var title = input.value.trim();
        if (title.length < 5) {
            if (window.sahandWarning) { sahandWarning('لطفاً عنوان دلخواه را وارد کنید (حداقل ۵ نویسه).', 'عنوان کوتاه است'); } else { alert('لطفاً عنوان دلخواه را وارد کنید (حداقل ۵ نویسه).'); }
            input.focus();
            return;
        }
        var csrf = document.querySelector('input[name="csrf_token"]');
        var brandSel = document.getElementById('gen-brand');
        var deviceSel = document.getElementById('gen-device');
        btn.disabled = true;
        btn.textContent = '⏳ در حال تحلیل عنوان...';
        box.style.display = 'block';
        box.innerHTML = '<div style="padding:12px;color:var(--text-light)">در حال تحلیل عنوان و ساخت پیشنهادهای سئو...</div>';

        var body = new URLSearchParams();
        body.append('action', 'suggest_titles');
        body.append('custom_title', title);
        body.append('brand_id', brandSel ? brandSel.value : '0');
        body.append('device_key', deviceSel ? deviceSel.value : '');
        if (csrf) { body.append('csrf_token', csrf.value); }

        fetch('articles.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf ? csrf.value : '', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (res) {
            btn.disabled = false;
            btn.textContent = '🎯 پیشنهاد بهترین عنوان سئو';
            if (!res.success) {
                box.innerHTML = '<div style="padding:12px;color:#e74c3c">خطا: ' + esc(res.error || 'نامشخص') + '</div>';
                return;
            }
            var d = res.data;
            var html = '<div style="border:1px solid var(--border);border-radius:10px;padding:14px;background:rgba(0,0,0,0.02)">';
            html += '<div style="font-weight:700;margin-bottom:6px">📊 تحلیل عنوان شما: ' + scoreBadge(d.original_score) + '</div>';
            html += '<div style="font-size:12px;color:var(--text-light);margin-bottom:4px">' + faNum(d.original_analysis.char_count) + ' کاراکتر — ' + esc(d.original_analysis.char_verdict) + '</div>';
            if (d.best_gain > 0) {
                html += '<div style="font-size:12.5px;margin:8px 0;color:#27ae60">🏆 بهترین پیشنهاد (+' + faNum(d.best_gain) + ' امتیاز): <b>' + esc(d.best) + '</b></div>';
            } else {
                html += '<div style="font-size:12.5px;margin:8px 0;color:#27ae60">✅ عنوان شما از نظر سئو وضعیت خوبی دارد؛ این گزینه‌ها نیز قابل بررسی‌اند:</div>';
            }
            html += '<div style="max-height:320px;overflow:auto;margin-top:8px">';
            (d.suggestions || []).forEach(function (s, i) {
                html += '<div class="title-suggest-item" data-title="' + esc(s.title).replace(/"/g, '&quot;') + '" style="display:flex;gap:10px;align-items:flex-start;padding:9px 10px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px;cursor:pointer;background:#fff">'
                    + '<span style="min-width:26px;text-align:center;color:var(--text-light)">' + faNum(i + 1) + '.</span>'
                    + '<div style="flex:1"><div style="font-size:13px;font-weight:600">' + esc(s.title) + (s.is_original ? ' <span class="badge badge-info">عنوان شما</span>' : '') + '</div>'
                    + '<div style="font-size:11px;color:var(--text-light);margin-top:3px">' + faNum(s.char_count) + ' کاراکتر' + (s.has_number ? ' · 🔢 عدد' : '') + (s.is_question ? ' · ❓ پرسشی' : '') + (s.power_words && s.power_words.length ? ' · ⚡ ' + esc(s.power_words.join('، ')) : '') + (s.gain > 0 ? ' · <b style="color:#27ae60">+' + faNum(s.gain) + '</b>' : '') + '</div></div>'
                    + '<span>' + scoreBadge(s.score) + '</span></div>';
            });
            html += '</div><div class="hint" style="margin-top:8px">💡 روی هر پیشنهاد کلیک کنید تا جایگزین عنوان دلخواه شما شود — سپس «تولید مقاله» را بزنید.</div></div>';
            box.innerHTML = html;
            Array.prototype.forEach.call(box.querySelectorAll('.title-suggest-item'), function (item) {
                item.addEventListener('click', function () {
                    input.value = item.getAttribute('data-title');
                    input.focus();
                    box.querySelectorAll('.title-suggest-item').forEach(function (el) { el.style.outline = 'none'; });
                    item.style.outline = '2px solid var(--primary)';
                });
            });
        }).catch(function (err) {
            btn.disabled = false;
            btn.textContent = '🎯 پیشنهاد بهترین عنوان سئو';
            box.innerHTML = '<div style="padding:12px;color:#e74c3c">خطای ارتباط با سرور: ' + esc(err.message) + '</div>';
        });
    });
})();
</script>

<script>
/* ============================================================
 * 🪜 v2.38 — تولید گام‌به‌گام مقاله (صف وظایف غیرهمزمان + پیشرفت زنده)
 * فرانت: ① generate_start → کلید وظیفه ② حلقه‌ی generate_step (هر بار یک گام)
 * ③ polling هر ۸۰۰ms برای درصد/متن زنده ④ تشخیص گامِ کشته‌شده (توقف
 * پیشرفت + قطع fetch) → تلاش مجدد خودکار → در گام تصویر: تحویل بدون تصویر.
 * بدون fetch → فرم به‌صورت همزمانِ قدیم ارسال می‌شود (fallback).
 * ============================================================ */
(function () {
    var btnGen = document.getElementById('btn-generate-article');
    var form = btnGen ? (btnGen.form || btnGen.closest('form')) : null;
    if (!form || !window.fetch || !window.URLSearchParams) { return; }

    var modal    = document.getElementById('articlegen-modal');
    if (!modal) { return; }
    var bar      = document.getElementById('agen-bar');
    var pctEl    = document.getElementById('agen-percent');
    var titleEl  = document.getElementById('agen-title');
    var detailEl = document.getElementById('agen-detail');
    var logEl    = document.getElementById('agen-log');
    var stepEls  = modal.querySelectorAll('.agen-steps .st');
    var btnRetry = document.getElementById('agen-retry');
    var btnOpen  = document.getElementById('agen-open');
    var btnClose = document.getElementById('agen-close');

    var csrfEl = document.querySelector('input[name="csrf_token"]');
    var csrfVal = csrfEl ? csrfEl.value : '';
    var faDig = function (n) { return String(Math.round(n)).replace(/[0-9]/g, function (x) { return '۰۱۲۳۴۵۶۷۸۹'[+x]; }); };

    var ORDER = ['research', 'compose', 'save', 'images'];
    var CAPS  = { research: 44, compose: 77, save: 83, images: 96 };
    var LABELS = {
        research: '🔎 تحقیق وب — کوئری‌ها به موتورهای جستجو',
        compose:  '✍️ نگارش ۲ واریانت و انتخاب بهترین',
        save:     '💾 ثبت مقاله در پایگاه داده',
        images:   '🖼️ تولید ۳ تصویر واقعی AI'
    };

    var taskKey = '', shownPct = 1, phase = 'research', stallCount = 0;
    var pollTimer = null, creepTimer = null, aborter = null, finished = false;
    var lastLog = '', lastTitle = '', lastDetail = '';

    function setPct(p) {
        p = Math.max(1, Math.min(99.5, p));
        if (p > shownPct) { shownPct = p; bar.style.width = p + '%'; pctEl.textContent = faDig(p) + '٪'; }
    }
    function setTitle(t) { if (t && t !== lastTitle) { lastTitle = t; titleEl.textContent = t; } }
    function setDetail(d) { if (d && d !== lastDetail) { lastDetail = d; detailEl.textContent = d; } }
    function addLog(icon, text) {
        if (!text || text === lastLog) { return; }
        lastLog = text;
        var div = document.createElement('div');
        div.className = 'lg';
        div.innerHTML = '<span class="ic">' + icon + '</span><span>' + text + '</span>';
        logEl.insertBefore(div, logEl.firstChild);
    }
    function setPhase(ph) {
        phase = ph;
        var idx = ORDER.indexOf(ph);
        Array.prototype.forEach.call(stepEls, function (el) {
            var i = ORDER.indexOf(el.getAttribute('data-ph'));
            el.className = 'st' + (i >= 0 && i < idx ? ' done' : (i === idx ? ' active' : ''));
        });
    }
    function post(action, params, signal) {
        var body = new URLSearchParams();
        body.append('action', action);
        Object.keys(params || {}).forEach(function (k) { body.append(k, params[k]); });
        if (csrfEl) { body.append('csrf_token', csrfVal); }
        return fetch('articles.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrfVal, 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString(),
            credentials: 'same-origin',
            signal: signal || undefined
        }).then(function (r) { return r.json(); });
    }
    function stopTimers() {
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
        if (creepTimer) { clearInterval(creepTimer); creepTimer = null; }
    }
    function fail(msg) {
        finished = true;
        stopTimers();
        setPct(99.5);
        bar.style.background = 'linear-gradient(90deg,#dc2626,#ef4444)';
        setTitle('❌ تولید ناموفق بود');
        setDetail(msg || 'خطای نامشخص');
        addLog('❌', msg || 'خطای نامشخص');
        btnRetry.style.display = '';
        btnClose.style.display = '';
    }
    function finish(res) {
        finished = true;
        stopTimers();
        setPct(100);
        bar.style.width = '100%';
        pctEl.textContent = '۱۰۰٪';
        bar.style.background = 'linear-gradient(90deg,#059669,#10b981)';
        setTitle('✅ مقاله با موفقیت تولید و ثبت شد');
        setDetail(res.summary || '');
        addLog('✅', res.summary || 'تولید کامل شد');
        (res.notices || []).forEach(function (n) { addLog('⚠️', n); });
        Array.prototype.forEach.call(stepEls, function (el) { el.className = 'st done'; });
        if (res.article_id) {
            btnOpen.style.display = '';
            btnOpen.onclick = function () { window.location.href = 'articles.php?edit=' + res.article_id; };
        }
        btnClose.style.display = '';
    }
    function pollOnce() {
        if (!taskKey || finished) { return; }
        var body = new URLSearchParams();
        body.append('action', 'generate_progress');
        body.append('task_key', taskKey);
        fetch('articles.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (!res.success || finished) { return; }
            var p = res.progress || {};
            if (typeof p.pct === 'number' && p.pct > 0) { setPct(p.pct); }
            setTitle(p.title); setDetail(p.detail);
            if (p.title || p.detail) { addLog('🔄', (p.title || '') + (p.detail ? ' — ' + p.detail : '')); }
            if (res.phase) { setPhase(res.phase); }
            if (res.done) {
                finish({ article_id: res.article_id, summary: p.detail || 'تولید کامل شد', notices: [] });
            }
        }).catch(function () { /* polling بی‌صدا رد می‌شود */ });
    }
    function runStep(skipImages) {
        if (finished || !taskKey) { return; }
        aborter = ('AbortController' in window) ? new AbortController() : null;
        var hardKill = setTimeout(function () { if (aborter) { aborter.abort(); } }, 185000);
        post('generate_step', { task_key: taskKey, skip_images: skipImages ? '1' : '0' }, aborter ? aborter.signal : undefined)
            .then(function (res) {
                clearTimeout(hardKill);
                if (finished) { return; }
                if (res.busy) { /* گام دیگری در حال اجراست */ setTimeout(function () { runStep(skipImages); }, 1600); return; }
                if (res.success && res.done) { finish(res); return; }
                if (res.success) {
                    stallCount = 0;
                    if (res.phase) { setPhase(res.phase); }
                    setPct((CAPS[res.phase] || 90) - 6);
                    setTimeout(function () { runStep(false); }, 400);
                    return;
                }
                fail(res.error || 'خطای نامشخص در اجرای گام');
            })
            .catch(function () {
                clearTimeout(hardKill);
                if (finished) { return; }
                /* 🩺 شبکه قطع یا درخواست توسط هاست کشته شد — اول وضعیت را بپرس */
                stallCount++;
                setTimeout(function () {
                    if (finished) { return; }
                    addLog('⚠️', 'پاسخ گام قطع شد — تلاش ' + faDig(stallCount) + ' برای ازسرگیری...');
                    if (stallCount >= 3 && phase === 'images') {
                        /* 🛟 سه شکست در گام تصویر — تحویل بدون تصویر (مقاله از قبل ثبت است) */
                        runStep(true);
                        return;
                    }
                    if (stallCount >= 5) {
                        fail('گام «' + (LABELS[phase] || phase) + '» پس از چند تلاش پاسخ نداد — صفحه را نوسازی و دوباره تلاش کنید.');
                        return;
                    }
                    runStep(false);
                }, 1800);
            });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!form.elements.brand_id || !form.elements.brand_id.value) { return; }
        /* 🎬 ریست مودال */
        finished = false; stallCount = 0; shownPct = 1; lastLog = ''; lastTitle = ''; lastDetail = '';
        bar.style.width = '1%'; bar.style.background = 'linear-gradient(90deg,#7c3aed,#2563eb 55%,#0ea5e9)';
        pctEl.textContent = '۱٪'; logEl.innerHTML = '';
        btnRetry.style.display = 'none'; btnOpen.style.display = 'none'; btnClose.style.display = 'none';
        btnGen.disabled = true; btnGen.textContent = '⏳ در حال تولید...';
        setPhase('research');
        setTitle('🚀 آغاز صف تولید گام‌به‌گام');
        setDetail('در حال ساخت وظیفه و اعتبارسنجی ورودی‌ها...');
        modal.style.display = 'flex';

        post('generate_start', {
            brand_id:      form.elements.brand_id.value,
            topic_type:    form.elements.topic_type ? form.elements.topic_type.value : 'troubleshooting',
            device_key:    form.elements.device_key ? form.elements.device_key.value : '',
            custom_title:  form.elements.custom_title ? form.elements.custom_title.value : '',
            research:      form.elements.research && form.elements.research.checked ? '1' : '0',
            research_depth: form.elements.research_depth ? form.elements.research_depth.value : 'balanced',
            with_images:   form.elements.with_images && form.elements.with_images.checked ? '1' : '0'
        }).then(function (res) {
            if (!res.success) { fail(res.error || 'شروع تولید ناموفق بود'); return; }
            taskKey = res.task_key;
            addLog('🚀', 'وظیفه ساخته شد — گام ۱: ' + LABELS.research);
            pollTimer = setInterval(pollOnce, 800);
            creepTimer = setInterval(function () { setPct(shownPct + 0.2); }, 1000);
            runStep(false);
        }).catch(function (err) { fail('خطای ارتباط با سرور: ' + err.message); });
    });

    btnRetry.addEventListener('click', function () {
        /* تلاش مجدد گام از طریق same task (گام‌ها idempotent هستند) */
        if (!taskKey) { form.dispatchEvent(new Event('submit')); return; }
        finished = false; stallCount = 0;
        btnRetry.style.display = 'none';
        bar.style.background = 'linear-gradient(90deg,#7c3aed,#2563eb 55%,#0ea5e9)';
        pollTimer = setInterval(pollOnce, 800);
        creepTimer = setInterval(function () { setPct(shownPct + 0.2); }, 1000);
        runStep(false);
    });
    btnClose.addEventListener('click', function () {
        stopTimers(); finished = true;
        modal.style.display = 'none';
        btnGen.disabled = false; btnGen.textContent = '🚀 تولید مقاله یکتا';
        window.location.reload();
    });
})();
</script>
<?php /* ✍️ v2.44 (S13) — ویرایشگر غنی محتوای مقاله */ ?>
<link rel="stylesheet" href="../assets/css/rich-editor.css?v=2.44">
<script src="../assets/js/rich-editor.js?v=2.44"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
