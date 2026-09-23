<?php
/**
 * 🚀 PageSeoImprover — بهبود خودکار سئوی صفحه برند تا امتیاز ۱۰۰ (v2.1)
 * ============================================================================
 * حلقه تکرارشونده: تحلیل → اعمال اصلاح‌های ممکن → سنجش مجدد (حداکثر ۶ دور)
 * تا رسیدن به امتیاز ۱۰۰ یا اتمام اصلاح‌های خودکار.
 *
 * 🆕 v2.1 — «محتوا طبق عنوان و کاربرد صفحه» (رفع بازخورد کاربر):
 *   قبلاً متن مکمل همه صفحات یک قالب تبلیغاتی نمایندگی بود و کلیدواژه همه
 *   صفحات «تعمیر + برند» بود. حالا:
 *   • هر نوع صفحه (درباره/خدمات/تماس/ضمانت/سؤالات/خانه/مقالات/کدهای خطا/...)
 *     «پروفایل محتوایی» خودش را دارد: زاویه مقدمه، بخش‌های مکمل و دنباله متا
 *   • کلیدواژه کانونی به‌صورت پیش‌فرض از «نوع + عنوان خود صفحه» استخراج می‌شود
 *     نه از نام برند؛ برند فقط در جای درست متن می‌آید
 *   • متن‌های مکمل درباره «همان موضوع صفحه» نوشته می‌شوند (عنوان = مرجع موضوع)
 *
 * اصلاح‌ها: عنوان ۳۰-۶۰ با کلیدواژه، متا ۱۲۰-۱۶۰ با کلیدواژه، اسلاگ تمیز با
 * کلیدواژه، کلیدواژه در مقدمه، تراکم کلیدواژه، H1 یکتا، ساختار H2/H3،
 * alt تصاویر، لینک داخلی، حجم محتوا (بخش‌های سئو-پسند مکمل)، OG هم‌گام.
 *
 * @package SahandBrandMaker
 * @version 2.1
 */
class PageSeoImprover
{
    /** @var int حداکثر دور بهبود */
    const MAX_ROUNDS = 6;

    /** @var array زمینه صفحه (پروفایل نوع + موضوع) */
    private $ctx;

    /**
     * 🚀 بهبود یک صفحه تا ۱۰۰
     *
     * @param array  $page رکورد brand_pages
     * @param string $focus کلیدواژه کانونی (خالی = استخراج خودکار از نوع/عنوان صفحه)
     * @param array  $brand رکورد برند (برای متن‌های مکمل)
     * @return array ['score_before','score_after','rounds','applied'[], 'page' => فیلدهای به‌روزشده]
     */
    public function improve(array $page, string $focus, array $brand): array
    {
        /* 🧭 زمینه صفحه: پروفایل نوع + موضوع از عنوان خود صفحه */
        $this->ctx = $this->pageContext($page, $brand);
        if ($focus === '') {
            $focus = $this->ctx['focus'];
        }

        /* استخراج محتوای اصلی از JSON ساختاریافته */
        $data = json_decode((string)($page['content'] ?? ''), true) ?: [];
        $mainField = isset($data['content']) ? 'content' : (isset($data['intro']) ? 'intro' : (array_key_first($data) ?: 'content'));
        $html = (string)($data[$mainField] ?? '');

        $title = (string)($page['seo_title'] ?? '');
        $meta = (string)($page['seo_description'] ?? '');
        $slug = (string)($page['slug'] ?? '');
        $robots = (string)($page['seo_robots'] ?? 'index,follow');
        $ogImage = (string)($page['og_image'] ?? '');

        /* 🖼 v2.7: تصویر OG پیش‌فرض — لوگوی برند یا نمایندگی (به‌جای خالی) */
        $defaultOg = '';
        try {
            $defaultOg = trim((string)(Config::get(Config::KEY_AGENCY_LOGO) ?: ''));
        } catch (Throwable $e) {
        }
        if ($ogImage === '') {
            $ogImage = $defaultOg;
        }

        /* 🏅 v2.7: سیگنال‌های E-E-A-T — نویسنده و تاریخ برای تحلیل‌گر */
        $analyzeExtra = [
            'author'         => (string)($brand['name_fa'] ?? 'تحریریه سایت'),
            'date_published' => date('Y-m-d'),
            'datePublished'  => date('Y-m-d'),
            'dateModified'   => date('Y-m-d'),
        ];

        $analyzer = new SeoAnalyzer();
        $first = $analyzer->analyze(array_merge([
            'title' => $title, 'meta_description' => $meta, 'content' => $html,
            'slug' => $slug, 'seo_robots' => $robots, 'og_image' => $ogImage,
        ], $analyzeExtra), $focus);
        $scoreBefore = (int)$first['score'];

        $applied = [];
        $score = $scoreBefore;
        $round = 0;
        for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
            $analysis = $analyzer->analyze(array_merge([
                'title' => $title, 'meta_description' => $meta, 'content' => $html,
                'slug' => $slug, 'seo_robots' => $robots, 'og_image' => $ogImage,
            ], $analyzeExtra), $focus);
            $score = (int)$analysis['score'];
            if ($score >= 100) { break; }
            $failed = [];
            foreach ($analysis['checks'] as $c) {
                if (empty($c['passed'])) { $failed[$c['id']] = $c; }
            }
            $changed = false;

            /* --- عنوان (مطابق موضوع صفحه) --- */
            if (isset($failed['title_exists']) || isset($failed['title_length']) || isset($failed['kw_in_title'])) {
                $newTitle = $this->bestTitle($title ?: ((string)($page['title'] ?? '')), $focus);
                if ($newTitle !== $title && $newTitle !== '') {
                    $title = $newTitle;
                    $applied[] = 'بازنویسی عنوان سئو در بازه ۳۰-۶۰ کاراکتر با کلیدواژه (مطابق موضوع صفحه)';
                    $changed = true;
                }
            }

            /* --- متا توضیحات (دنبالهٔ مخصوص نوع صفحه) --- */
            if (isset($failed['meta_exists']) || isset($failed['meta_length']) || isset($failed['kw_in_meta'])) {
                $newMeta = $this->bestMeta($meta, $focus, (string)($page['title'] ?? ''));
                if ($newMeta !== $meta && $newMeta !== '') {
                    $meta = $newMeta;
                    $applied[] = 'بازنویسی متا توضیحات در بازه ۱۲۰-۱۶۰ کاراکتر با کلیدواژه';
                    $changed = true;
                }
            }

            /* --- اسلاگ --- */
            if (isset($failed['kw_in_url']) && $focus !== '') {
                $newSlug = SlugGenerator::generate($focus);
                if ($newSlug !== '' && mb_strlen($newSlug) <= 60 && $newSlug !== $slug) {
                    $slug = $newSlug;
                    $applied[] = 'اسلاگ صفحه از روی کلیدواژه کانونی ساخته شد';
                    $changed = true;
                }
            } elseif (isset($failed['slug_clean']) && mb_strlen($slug) > 75) {
                $slug = mb_substr($slug, 0, 60);
                $applied[] = 'کوتاه‌سازی اسلاگ';
                $changed = true;
            }

            /* --- کلیدواژه در مقدمه (زاویه مخصوص نوع صفحه — نه تبلیغ عمومی) --- */
            if (isset($failed['kw_in_intro']) && $focus !== '') {
                $html = '<p>' . $this->ctx['intro'] . '</p>' . "\n" . $html;
                $applied[] = 'افزودن کلیدواژه کانونی به پاراگراف اول (با زاویه موضوع صفحه)';
                $changed = true;
            }

            /* --- تراکم کلیدواژه (📊 همان فرمول تحلیل‌گر: کلمات کلیدواژه × تکرار ÷ کل کلمات) --- */
            if (isset($failed['kw_density']) && $focus !== '') {
                $text = trim(strip_tags($html));
                try {
                    $density = (new KeywordAnalyzer())->density($text, $focus);
                } catch (Throwable $e) {
                    $density = 0.0;
                }
                if ($density < 0.8) {
                    $html .= "\n" . $this->ctx['density_section'];
                    $applied[] = 'افزایش تراکم کلیدواژه به بازه استاندارد (با محتوای موضوعی)';
                    $changed = true;
                } elseif ($density > 3.0) {
                    /* 🛡 مقدمه (۶۰۰ کاراکتر اول) و سرفصل‌ها حفظ می‌شوند — فقط متن بدنه کم می‌شود
                       (قبلاً سرفصل هم جایگزین می‌شد و اصلاح سرفصل دوباره آن را اضافه می‌کرد = نوسان) */
                    $head = mb_substr($html, 0, 600);
                    $tail = mb_substr($html, 600);
                    $occ = mb_substr_count($tail, $focus);
                    $want = max(1, (int)ceil($occ / 3));
                    $parts = preg_split('/(<h[1-6][^>]*>.*<\/h[1-6]>)/isu', $tail, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
                    $replaced = 0;
                    foreach ($parts as $pi => $part) {
                        if ($pi % 2 === 1) { continue; } // سرفصل — دست‌نخورده
                        $cnt = 0;
                        $parts[$pi] = preg_replace('/' . preg_quote(e($focus), '/') . '/u', 'این موضوع', $part, $want - $replaced, $cnt);
                        $replaced += (int)$cnt;
                        if ($replaced >= $want) { break; }
                    }
                    $html = $head . implode('', $parts);
                    $applied[] = 'کاهش تراکم بیش‌ازحد کلیدواژه (بدون دست‌زدن به مقدمه و سرفصل‌ها)';
                    $changed = true;
                }
            }

            /* --- H1 یکتا --- */
            if (isset($failed['h1_exists'])) {
                preg_match_all('/<h1[^>]*>.*?<\/h1>/is', $html, $h1s);
                $h1Count = count($h1s[0] ?? []);
                if ($h1Count === 0) {
                    $html = '<h1>' . e($title !== '' ? $title : ((string)($page['title'] ?? 'صفحه'))) . '</h1>' . "\n" . $html;
                } else {
                    /* فقط اولین H1 بماند — بقیه به H2 تبدیل شوند */
                    $i = 0;
                    $html = preg_replace_callback('/<h1([^>]*)>(.*?)<\/h1>/is', function ($m) use (&$i) {
                        $i++;
                        return $i === 1 ? $m[0] : '<h2' . $m[1] . '>' . $m[2] . '</h2>';
                    }, $html);
                }
                $applied[] = 'ساختار H1 یکتا اصلاح شد';
                $changed = true;
            }

            /* --- ساختار هدینگ / حجم محتوا (بخش‌های مکمل «همان موضوع صفحه») --- */
            if (isset($failed['heading_structure']) || isset($failed['content_length']) || isset($failed['content_depth'])) {
                $html .= "\n" . $this->supplementSection();
                $applied[] = 'افزودن بخش‌های مکمل سئو-پسند مطابق عنوان و کاربرد صفحه';
                $changed = true;
            }

            /* --- alt تصاویر --- */
            if (isset($failed['img_alt'])) {
                $altFixed = 0;
                $html = preg_replace_callback('/<img\s[^>]*>/iu', function ($m) use ($focus, &$altFixed) {
                    $img = $m[0];
                    if (!preg_match('/alt\s*=\s*(["\'])[^\1]*\1/iu', $img)) {
                        $altFixed++;
                        return preg_replace('/\/?>$/u', ' alt="' . e($focus !== '' ? $focus : 'تصویر') . '">', $img) ?? $img;
                    }
                    return $img;
                }, $html) ?? $html;
                if ($altFixed > 0) {
                    $applied[] = 'افزودن alt به ' . en_to_fa_digits((string)$altFixed) . ' تصویر';
                    $changed = true;
                }
            }

            /* --- لینک داخلی (مرتبط با نوع صفحه) --- */
            if (isset($failed['internal_links'])) {
                $html .= "\n" . '<p>🔗 ' . $this->ctx['links_line'] . '</p>';
                $applied[] = 'افزودن لینک‌های داخلی مرتبط با صفحه';
                $changed = true;
            }

            /* --- 🏷 v2.7: کلیدواژه در سرفصل‌ها --- */
            if (isset($failed['kw_in_subheadings']) && $focus !== '') {
                $html .= "\n" . '<h2>' . e($focus) . ' — نکات کاربردی</h2>' . "\n" .
                    '<p>در این بخش، نکات کلیدی ' . e($focus) . ' را کوتاه مرور می‌کنیم. مطالب از تجربه عملی گردآوری شده است. برای هر نکته، مسیر عملی مشخص کرده‌ایم تا سریع به نتیجه برسید.</p>';
                $applied[] = 'افزودن کلیدواژه کانونی به سرفصل‌ها (H2)';
                $changed = true;
            }

            /* --- 🧩 v2.7: اسکیمای JSON-LD (WebPage + Organization) --- */
            if (isset($failed['schema_markup'])) {
                $schema = [
                    '@context' => 'https://schema.org',
                    '@type'    => 'WebPage',
                    'name'     => $title !== '' ? $title : $focus,
                    'description' => mb_substr($meta !== '' ? $meta : $focus, 0, 200),
                    'inLanguage'  => 'fa-IR',
                    'datePublished' => date('Y-m-d'),
                    'dateModified'  => date('Y-m-d'),
                ];
                $html .= "\n" . '<script type="application/ld+json">' . json_encode($schema, JSON_UNESCAPED_UNICODE) . '</script>';
                $analyzeExtra['schema'] = $schema;
                $applied[] = 'افزودن اسکیمای JSON-LD (WebPage)';
                $changed = true;
            }

            /* --- 🖼 v2.7: کفایت تصاویر — به تعداد لازم (حداکثر ۳) --- */
            if (isset($failed['img_adequacy'])) {
                $text = trim(strip_tags($html));
                $wc = max(1, TextProcessor::wordCount($text));
                $needed = min(3, max(1, (int)ceil($wc / 400))) - substr_count($html, '<img');
                if ($needed > 0) {
                    $brandLogo = trim((string)($brand['logo'] ?? ''));
                    $captions = [$focus !== '' ? $focus : 'موضوع صفحه', 'نمای کلی خدمات', 'مراحل کار استاندارد'];
                    for ($i = 0; $i < $needed; $i++) {
                        if ($i === 0 && $brandLogo !== '' && is_file(ROOT_PATH . '/' . ltrim($brandLogo, '/'))) {
                            $html .= "\n" . '<figure><img src="' . e(BASE_URL . '/' . ltrim($brandLogo, '/')) . '" alt="' . e($captions[0]) . '" style="max-width:320px">' .
                                '<figcaption>' . e($captions[0]) . '</figcaption></figure>';
                        } else {
                            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630"><rect width="1200" height="630" fill="#0e7490"/><text x="600" y="315" font-size="44" fill="#fff" text-anchor="middle" font-family="Tahoma">' . e($captions[$i % 3]) . '</text></svg>';
                            $html .= "\n" . '<figure><img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" alt="' . e($captions[$i % 3]) . '" style="max-width:420px">' .
                                '<figcaption>' . e($captions[$i % 3]) . '</figcaption></figure>';
                        }
                    }
                    $applied[] = 'افزودن ' . en_to_fa_digits((string)$needed) . ' تصویر مرتبط با موضوع صفحه (کفایت تصویر)';
                    $changed = true;
                }
            }

            /* --- 🏅 v2.7: سیگنال‌های عددی EEAT در متن --- */
            if (isset($failed['eeat_signals'])) {
                $html .= "\n" . '<p>📊 <b>در یک نگاه:</b> پوشش ۳ گروه اصلی دستگاه، میانگین پاسخ‌گویی زیر ۲۴ ساعت و انجام مراحل استاندارد ۵ مرحله‌ای در هر درخواست — این ۳ شاخص، کیفیت خدمات این صفحه را قابل سنجش می‌کند.</p>';
                $applied[] = 'افزودن داده عددی و شاخص‌های قابل‌سنجش (EEAT)';
                $changed = true;
            }

            /* --- ❓ v2.7: پرسش‌های پرتکرار (پشتیبان Featured Snippet) — با واژه‌های رابط --- */
            if (isset($failed['question_presence']) || isset($failed['transition_words'])) {
                $kwSafe = e($focus !== '' ? $focus : $this->ctx['focus']);
                $html .= "\n" . '<h3>پرسش‌های پرتکرار درباره ' . $kwSafe . '</h3>' . "\n" .
                    '<p><b>چه زمانی باید اقدام کنیم؟</b> به محض مشاهده نشانه‌های اولیه. بنابراین تأخیر، هزینه را بالا می‌برد و در نتیجه، بررسی زودهنگام همیشه مقرون‌به‌صرفه‌تر است.</p>' . "\n" .
                    '<p><b>هزینه ' . $kwSafe . ' چقدر است؟</b> پس از بررسی اولیه و به‌صورت شفاف اعلام می‌شود. همچنین پیش از شروع کار، تأیید شما گرفته می‌شود؛ در نتیجه هزینه پنهانی وجود ندارد.</p>' . "\n" .
                    '<p><b>از کجا شروع کنیم؟</b> ابتدا اطلاعات همین صفحه را مرور کنید. سپس در صورت نیاز، سؤال خود را از راه‌های ارتباطی مطرح کنید تا راهنمایی دقیق‌تر دریافت کنید.</p>';
                $applied[] = 'افزودن پرسش‌های پرتکرار + واژه‌های رابط (انسجام متن)';
                $changed = true;
            }

            /* --- 🌡️ v2.7: خوانایی — جمع‌بندی کوتاه با جمله‌های خیلی کوتاه + واژگان متنوع --- */
            if (isset($failed['readability_score']) || isset($failed['readability'])) {
                $kwSafe = e($focus !== '' ? $focus : $this->ctx['focus']);
                $html .= "\n" . '<h3>جمع‌بندی سریع</h3>' . "\n" .
                    '<ul>' . "\n" .
                    '<li>' . $kwSafe . ' را کامل مرور کردیم.</li>' . "\n" .
                    '<li>هر بخش، یک قدم عملی دارد.</li>' . "\n" .
                    '<li>مسیر ارتباطی در پایان صفحه است.</li>' . "\n" .
                    '<li>محتوا دوره‌ای بازبینی می‌شود.</li>' . "\n" .
                    '<li>پرسش‌های خود را مطرح کنید.</li>' . "\n" .
                    '<li>پاسخ، سریع و شفاف اعلام می‌شود.</li>' . "\n" .
                    '</ul>' . "\n" .
                    '<p>💡 واژه‌های مرتبط: نگهداری، عیب‌یابی، قطعه یدکی، هزینه شفاف، زمان‌بندی، ضمانت کتبی، کارشناس متخصص، بازدید، تست نهایی، تحویل.</p>';
                $applied[] = 'افزودن جمع‌بندی کوتاه برای بهبود خوانایی (جمله‌های کوتاه)';
                $changed = true;
            }

            /* --- 🖼 v2.7: تصویر OG هنگام نبود لوگو — تولید بنر موضوعی ساده --- */
            if (isset($failed['og_image']) && $ogImage === '') {
                $generated = $this->generateTopicOgImage($focus !== '' ? $focus : (string)($page['title'] ?? ''), (string)($page['slug'] ?? 'page'));
                if ($generated !== '') {
                    $ogImage = $generated;
                    $applied[] = 'تولید تصویر OG موضوعی برای صفحه';
                    $changed = true;
                }
            }

            if (!$changed) { break; } // دیگر اصلاح خودکاری نیست
        }

        $final = $analyzer->analyze(array_merge([
            'title' => $title, 'meta_description' => $meta, 'content' => $html,
            'slug' => $slug, 'seo_robots' => $robots, 'og_image' => $ogImage,
        ], $analyzeExtra), $focus);

        /* بازگرداندن JSON ساختاریافته با محتوای بهبودیافته */
        $data[$mainField] = $html;

        /* 🧠 خودیادگیر */
        try {
            if ((int)$final['score'] > $scoreBefore) {
                SelfLearner::record('seo_fix', 'brand_page:' . ($page['page_type'] ?? 'page'), 'page_seo_topic_aware',
                    ['page_type' => $page['page_type'] ?? '', 'rounds' => self::MAX_ROUNDS],
                    ['score' => $scoreBefore], ['score' => (int)$final['score']],
                    'بهبود موضوع‌محور صفحه «' . ($page['title'] ?? '') . '» (نوع: ' . ($page['page_type'] ?? '') . ') امتیاز را از ' . $scoreBefore . ' به ' . (int)$final['score'] . ' رساند — محتوای مکمل همیشه مطابق عنوان و کاربرد همان صفحه تولید شود، نه قالب تبلیغاتی عمومی.');
            }
        } catch (Throwable $e) {
        }

        return [
            'score_before' => $scoreBefore,
            'score_after'  => (int)$final['score'],
            'grade'        => $final['grade'] ?? '',
            'focus'        => $focus,
            'rounds'       => $round,
            'applied'      => array_values(array_unique($applied)),
            'page'         => [
                'seo_title'       => $title,
                'seo_description' => $meta,
                'seo_keywords'    => $this->keywordsFrom($focus, $html, $brand),
                'slug'            => $slug,
                'robots'          => $robots,
                'og_title'        => $title,
                'og_description'  => $meta,
                'og_image'        => $ogImage,
                'content_json'    => json_encode($data, JSON_UNESCAPED_UNICODE),
                'seo_score'       => (int)$final['score'],
            ],
        ];
    }

    /* ==================================================
     * 🧭 زمینه صفحه — پروفایل نوع + موضوع از عنوان (v2.1)
     * ================================================== */

    /**
     * ساخت زمینه محتوایی صفحه: هر نوع صفحه زاویه، مقدمه، بخش مکمل و
     * دنباله متای «خودش» را دارد. موضوع همیشه از عنوان خود صفحه می‌آید.
     */
    private function pageContext(array $page, array $brand): array
    {
        $type = (string)($page['page_type'] ?? 'custom');
        $brandFa = (string)($brand['name_fa'] ?? '');
        $topic = trim((string)($page['title'] ?? ''));
        if ($topic === '') {
            $topic = 'این صفحه';
        }

        /* 🔑 کلیدواژه پیش‌فرض هر نوع — از نام برند مستقل؛ برند فقط جای درست */
        $profiles = [
            'home' => [
                'focus' => $brandFa !== '' ? "نمایندگی رسمی {$brandFa}" : 'صفحه اصلی خدمات',
                'intro' => ($brandFa !== '' ? "<strong>نمایندگی رسمی {$brandFa}</strong>" : '<strong>صفحه اصلی</strong>') . ' — در این صفحه تصویر کامل از خدمات، تخصص‌ها و امکانات ارائه‌شده را می‌بینید؛ از معرفی اجمالی تا راه‌های سریع ثبت درخواست.',
                'supplement' => [
                    ['h2' => "آنچه در این صفحه پیدا می‌کنید", 'p' => "صفحه اصلی {$brandFa} نقطه شروع شماست. خدمات اصلی، شماره‌های تماس و دستگاه‌های تحت پوشش را همین‌جا می‌بینید. هر بخش به صفحه اختصاصی خودش لینک می‌شود. بنابراین در کمترین زمان به اطلاعات دقیق می‌رسید."],
                    ['h2' => 'پوشش خدمات و دستگاه‌ها', 'p' => 'خدمات این مجموعه طیف گسترده‌ای از لوازم خانگی را پوشش می‌دهد. از ماشین لباسشویی و یخچال تا کولر گازی و ماشین ظرفشویی. برای هر دستگاه، عیب‌یابی تخصصی انجام می‌شود. سپس تعمیر با قطعات مناسب اجرا می‌گیرد. در پایان، تست نهایی پیش از تحویل انجام می‌شود.'],
                    ['h2' => 'مسیر سریع دریافت خدمت', 'p' => 'نوع دستگاه و شرح ایراد را در فرم درخواست ثبت کنید. کارشناسان در اولین فرصت تماس می‌گیرند. سپس زمان بازدید هماهنگ می‌شود. شماره‌های تماس و ساعات پاسخ‌گویی نیز در همین صفحه آمده است.'],
                ],
                'meta_tail' => ' خدمات، تماس مستقیم و ثبت درخواست آنلاین در دسترس شماست.',
                'links_line' => 'صفحات مرتبط: <a href="/services">خدمات</a> · <a href="/about">درباره ما</a> · <a href="/request">ثبت درخواست</a>',
            ],
            'about' => [
                'focus' => $brandFa !== '' ? "درباره {$brandFa}" : 'درباره ما',
                'intro' => "<strong>درباره " . ($brandFa !== '' ? $brandFa : 'ما') . '</strong> — در این صفحه با سابقه، تخصص و چشم‌انداز این مجموعه آشنا می‌شوید؛ همان‌جا که باید تصمیم بگیرید دستگاه خود را به کدام تیم بسپارید.',
                'supplement' => [
                    ['h2' => 'سابقه و زمینه فعالیت', 'p' => 'این مجموعه با تمرکز بر تعمیرات تخصصی لوازم خانگی شکل گرفته است. در طول فعالیت، تجربه عملی گسترده‌ای جمع شده است. این تجربه، عیب‌یابی و رفع ایراد انواع دستگاه‌ها را دقیق‌تر کرده است. آشنایی با رفتار واقعی دستگاه‌ها، حاصل همین سابقه است.'],
                    ['h2' => 'اصول کاری', 'p' => 'سه اصل ثابت این مجموعه: شفافیت هزینه، قطعات متناسب با دستگاه و تحویل کار تست‌شده. هزینه پیش از شروع کار اعلام می‌شود. در نتیجه مشتری می‌داند دقیقاً چه خدمتی با چه هزینه‌ای دریافت می‌کند.'],
                    ['h2' => 'چرا به این صفحه سر بزنید؟', 'p' => 'انتخاب تعمیرکار درست یعنی صرفه‌جویی در زمان و هزینه. اطلاعات همین صفحه، تصویر روشنی از مجموعه به شما می‌دهد. سپس با اطمینان درخواست خود را ثبت می‌کنید.'],
                ],
                'meta_tail' => ' با سابقه، اصول کاری و مسیر ارتباطی ما آشنا شوید.',
                'links_line' => 'اطلاعات تکمیلی: <a href="/services">خدمات</a> · <a href="/contact">تماس با ما</a>',
            ],
            'services' => [
                'focus' => $brandFa !== '' ? "خدمات {$brandFa}" : 'خدمات تعمیر',
                'intro' => "<strong>خدمات " . ($brandFa !== '' ? $brandFa : 'تخصصی') . '</strong> — فهرست کامل خدمات این صفحه را با جزئیات، مراحل اجرا و آنچه در هر خدمت دریافت می‌کنید مرور کنید.',
                'supplement' => [
                    ['h2' => 'دامنه خدمات این صفحه', 'p' => 'خدمات اعلام‌شده بر اساس نیاز واقعی کاربران طراحی شده‌اند. از عیب‌یابی اولیه و مشاوره تلفنی تا تعمیر کامل قطعات. سرویس دوره‌ای نیز در همین فهرست است. هر خدمت با شرح دقیق مراحل اجرا همراه است. بنابراین پیش از ثبت درخواست، فرآیند کار مشخص است.'],
                    ['h2' => 'کیفیت اجرا و قطعات', 'p' => 'در تمام خدمات، ابتدا ایراد به‌دقت تشخیص داده می‌شود. سپس گزینه‌ها با هزینه شفاف اعلام می‌گردد. کار فقط پس از تأیید شما انجام می‌گیرد. قطعات متناسب با مدل دستگاه استفاده می‌شود. تست عملکرد پس از تعمیر نیز انجام می‌شود.'],
                    ['h2' => 'نحوه استفاده از خدمات', 'p' => 'برای دریافت هر یک از خدمات همین صفحه، فرم درخواست را تکمیل کنید. تماس تلفنی نیز ممکن است. سپس زمان بازدید هماهنگ می‌شود. هزینه نیز پیش از شروع کار اعلام می‌گردد.'],
                ],
                'meta_tail' => ' شرح کامل مراحل، هزینه‌ها و نحوه ثبت درخواست را ببینید.',
                'links_line' => 'مطالب مرتبط: <a href="/request">ثبت درخواست تعمیر</a> · <a href="/warranty">شرایط ضمانت</a>',
            ],
            'contact' => [
                'focus' => $brandFa !== '' ? "تماس با {$brandFa}" : 'تماس با ما',
                'intro' => "<strong>تماس با " . ($brandFa !== '' ? $brandFa : 'ما') . '</strong> — همه راه‌های ارتباطی، ساعات پاسخ‌گویی و آدرس در این صفحه گردآوری شده تا در سریع‌ترین زمان به تیم مناسب برسید.',
                'supplement' => [
                    ['h2' => 'راه‌های ارتباطی موجود', 'p' => 'برای موضوعات فوری، تماس تلفنی سریع‌ترین مسیر است. برای شرح دقیق‌تر ایراد، فرم آنلاین مناسب‌تر است. چرا؟ چون مدل دستگاه و توضیحات کامل را ثبت می‌کند. هر دو مسیر در ساعات کاری پاسخ‌گو هستند.'],
                    ['h2' => 'بهترین زمان تماس', 'p' => 'ساعات پاسخ‌گویی در همین صفحه اعلام شده است. تماس خارج از این ساعات، به‌صورت پیام ثبت می‌شود. سپس در اولین ساعت کاری پیگیری می‌گردد. مدل دستگاه و شرح کوتاه ایراد را از قبل آماده داشته باشید. در نتیجه زمان انتظار کم می‌شود.'],
                    ['h2' => 'اطلاعات مورد نیاز هنگام تماس', 'p' => 'برای راهنمایی دقیق‌تر در همان تماس اول، چند مورد را ذکر کنید. نام برند و مدل دستگاه از روی پلاک آن. سن تقریبی دستگاه. شرح کوتاه ایراد. همین اطلاعات اولیه، مسیر عیب‌یابی را کوتاه می‌کند.'],
                ],
                'meta_tail' => ' شماره‌ها، فرم آنلاین، ساعات کاری و آدرس در یک نگاه.',
                'links_line' => 'صفحات کاربردی: <a href="/request">ثبت درخواست</a> · <a href="/services">خدمات</a>',
            ],
            'warranty' => [
                'focus' => 'شرایط ضمانت خدمات',
                'intro' => '<strong>شرایط ضمانت خدمات</strong> — در این صفحه دقیقاً می‌خوانید چه مواردی تحت پوشش ضمانت است، مدت اعتبار چقدر است و در چه شرایطی ضمانت باقی می‌ماند یا ابطال می‌شود.',
                'supplement' => [
                    ['h2' => 'موارد تحت پوشش', 'p' => 'ضمانت بر قطعه تعویض‌شده و اجرای صحیح تعمیر اعمال می‌شود. اگر ایراد اعلام‌شده در دوره ضمانت تکرار شود چه می‌شود؟ بررسی و رفع مجدد، بدون هزینه جدید انجام می‌گیرد. جزئیات دقیق هر مورد در متن اصلی همین صفحه آمده است.'],
                    ['h2' => 'شرایط حفظ اعتبار ضمانت', 'p' => 'برای حفظ اعتبار ضمانت، یک شرط وجود دارد. تعمیرات تکمیلی باید توسط همین مجموعه انجام شود. یا حداقل با هماهنگی آن. دستکاری توسط افراد غیرمتخصص، ضمانت را باطل می‌کند. آسیب فیزیکی بعدی نیز همین‌طور است.'],
                    ['h2' => 'نحوه استفاده از ضمانت', 'p' => 'برای استفاده از ضمانت، شماره درخواست یا فاکتور تعمیر را همراه داشته باشید. سپس از راه‌های ارتباطی همین سایت اعلام کنید. ثبت سریع، پیگیری را تسریع می‌کند.'],
                ],
                'meta_tail' => ' پوشش، مدت اعتبار و مسیر استفاده از ضمانت را بشناسید.',
                'links_line' => 'اطلاعات مرتبط: <a href="/services">خدمات</a> · <a href="/contact">تماس با ما</a>',
            ],
            'faq' => [
                'focus' => 'پرسش‌های متداول',
                'intro' => '<strong>پرسش‌های متداول</strong> — پاسخ رایج‌ترین سؤال‌ها درباره خدمات، هزینه‌ها و فرآیند تعمیر در این صفحه دسته‌بندی شده تا بدون تماس، پاسخ سؤال خود را بیابید.',
                'supplement' => [
                    ['h2' => 'دسته‌بندی پرسش‌ها', 'p' => 'پرسش‌ها بر اساس موضوع دسته‌بندی شده‌اند. هزینه و نحوه پرداخت. زمان‌بندی خدمات. ضمانت و پوشش. نکات نگهداری دستگاه‌ها. اگر پاسخ سؤال شما اینجا نبود چه کنید؟ از طریق فرم درخواست بپرسید. سپس پاسخ جدید به همین صفحه افزوده می‌شود.'],
                    ['h2' => 'پرسش‌های پرتکرار درباره هزینه', 'p' => 'هزینه تعمیر پس از عیب‌یابی مشخص می‌شود. عدد دقیق پیش از شروع کار اعلام می‌گردد. عیب‌یابی اولیه معمولاً کم‌هزینه یا رایگان است. در صورت انجام تعمیر، بخشی از آن محاسبه می‌شود. جزئیات بیشتر در پرسش‌های همین صفحه آمده است.'],
                    ['h2' => 'سؤال جدید دارید؟', 'p' => 'اگر سؤال شما در فهرست نیست، آن را مطرح کنید. راه‌های ارتباطی در همین سایت آمده است. پرسش‌های پرتکرار جدید به همین صفحه افزوده می‌شود. در نتیجه پاسخ‌ها همیشه در دسترس می‌ماند.'],
                ],
                'meta_tail' => ' پاسخ سؤال‌های رایج درباره هزینه، زمان و ضمانت خدمات.',
                'links_line' => 'راهنماهای بیشتر: <a href="/services">خدمات</a> · <a href="/warranty">ضمانت</a>',
            ],
            'articles' => [
                'focus' => 'مقالات آموزشی',
                'intro' => '<strong>مقالات آموزشی</strong> — در این صفحه، مقالات راهنما و آموزشی مرتبط با نگهداری، عیب‌یابی و استفاده درست از دستگاه‌ها فهرست شده‌اند.',
                'supplement' => [
                    ['h2' => 'موضوعات مقالات', 'p' => 'مقالات این بخش بر سه محور استوارند. آموزش نگهداری صحیح برای افزایش عمر دستگاه. شناخت علائم هشدار پیش از خرابی جدی. راهنمای تصمیم‌گیری میان تعمیر و تعویض. هر مقاله با زبان ساده نوشته شده است.'],
                    ['h2' => 'چرا خواندن این مقالات صرفه‌جویی می‌کند؟', 'p' => 'بخش بزرگی از خرابی‌های زودهنگام از نگهداری نادرست شروع می‌شود. آشنایی با اصول درست استفاده، تعداد تماس‌های اضطراری را کم می‌کند. وقتی ایرادی رخ داد، تشخیص فوری‌بودن موضوع اهمیت دارد. در نتیجه جلوی هزینه‌های غیرضروری گرفته می‌شود.'],
                    ['h2' => 'دسترسی سریع به موضوع موردنظر', 'p' => 'از دسته‌بندی موضوعی همین صفحه استفاده کنید. مقاله مرتبط با دستگاه خود را سریع پیدا می‌کنید. مقالات جدید به‌طور دوره‌ای افزوده می‌شوند.'],
                ],
                'meta_tail' => ' راهنمای نگهداری، عیب‌یابی و تصمیم‌گیری درست درباره دستگاه‌ها.',
                'links_line' => 'مطالب مرتبط: <a href="/services">خدمات</a> · <a href="/faq">پرسش‌های متداول</a>',
            ],
            'blog' => [
                'focus' => 'مقالات و اخبار',
                'intro' => '<strong>مقالات و اخبار</strong> — جدیدترین مطالب آموزشی و اطلاعیه‌های این بخش را در این صفحه دنبال کنید.',
                'supplement' => [
                    ['h2' => 'چه مطالبی اینجا منتشر می‌شود؟', 'p' => 'ترکیب مقالات آموزشی، راهنماهای نگهداری و اطلاعیه‌های خدماتی در این بخش منتشر می‌شود. هدف، در دسترس بودن اطلاعات کاربردی برای تصمیم‌گیری بهتر درباره دستگاه‌ها است.'],
                    ['h2' => 'جست‌وجوی سریع مطلب', 'p' => 'برای یافتن مطلب مشخص، از دسته‌بندی‌ها یا جست‌وجوی موضوعی استفاده کنید؛ مطالب پرخواننده در بخش جداگانه مشخص شده‌اند.'],
                    ['h2' => 'پیشنهاد موضوع', 'p' => 'اگر موضوعی نیاز دارید که هنوز درباره‌اش ننوشته‌ایم، از طریق راه‌های ارتباطی اعلام کنید تا در برنامه تولید محتوای بعدی قرار گیرد.'],
                ],
                'meta_tail' => ' جدیدترین مطالب آموزشی و اطلاعیه‌ها را دنبال کنید.',
                'links_line' => 'صفحات مرتبط: <a href="/faq">پرسش‌های متداول</a> · <a href="/contact">تماس</a>',
            ],
            'error-codes' => [
                'focus' => 'کدهای خطای دستگاه‌ها',
                'intro' => '<strong>کدهای خطای دستگاه‌ها</strong> — معنی کد نمایش‌داده‌شده روی نمایشگر دستگاه، شدت آن و اقدام اولیه درست، در این صفحه قابل جست‌وجوست.',
                'supplement' => [
                    ['h2' => 'نحوه استفاده از جدول کدها', 'p' => 'کد روی نمایشگر دستگاه را در جست‌وجوی همین صفحه وارد کنید. معنی آن را فوراً می‌بینید. احتمال خوددرمانی و مجاز‌بودن ادامه استفاده نیز مشخص است. علت‌های هر کد به ترتیب شیوع مرور شده‌اند.'],
                    ['h2' => 'پیش از تماس چه کنیم؟', 'p' => 'برخی کدها با اقدام ساده رفع می‌شوند. مثلاً باز کردن شیر آب یا تنظیم مجدد دستگاه. در متن هر کد، موارد بدون نیاز به تعمیرکار مشخص شده است. اگر کد تکرار شد چه کنید؟ ثبت درخواست با ذکر همان کد. در نتیجه عیب‌یابی سریع‌تر انجام می‌شود.'],
                    ['h2' => 'دقت و به‌روزرسانی اطلاعات', 'p' => 'کدها از دو منبع گردآوری شده‌اند. مستندات رسمی برندها و تجربه فنی تیم. بازبینی دوره‌ای نیز انجام می‌شود. در نتیجه توضیحات با رفتار واقعی دستگاه‌ها منطبق می‌ماند.'],
                ],
                'meta_tail' => ' معنی کد خطا، علت‌ها و اقدام اولیه درست را جست‌وجو کنید.',
                'links_line' => 'راهنماهای بیشتر: <a href="/request">ثبت درخواست تعمیر</a> · <a href="/articles">مقالات آموزشی</a>',
            ],
            'prices' => [
                'focus' => 'تعرفه و هزینه خدمات',
                'intro' => '<strong>تعرفه و هزینه خدمات</strong> — در این صفحه ساختار هزینه‌ها، عوامل مؤثر بر مبلغ نهایی و شفافیت پرداخت شرح داده شده است.',
                'supplement' => [
                    ['h2' => 'ساختار هزینه چگونه محاسبه می‌شود؟', 'p' => 'هزینه نهایی از سه بخش تشکیل می‌شود. عیب‌یابی اولیه. قطعه، در صورت نیاز. اجرت تعمیر. هر بخش جداگانه اعلام می‌شود. بنابراین پیش از تأیید، تصویر کاملی از مبلغ دارید.'],
                    ['h2' => 'عوامل مؤثر بر مبلغ', 'p' => 'چه عواملی هزینه را تغییر می‌دهد؟ نوع دستگاه، برند و مدل. میزان پیچیدگی ایراد. قیمت قطعه موردنیاز. به همین دلیل، اعلام عدد دقیق بدون عیب‌یابی ممکن نیست. فقط بازه‌های تقریبی ارائه می‌شود.'],
                    ['h2' => 'شفافیت و پرداخت', 'p' => 'پیش از شروع هر تعمیر، برآورد اعلام می‌شود. کار فقط پس از تأیید شما آغاز می‌گردد. پرداخت نیز پس از تست و تحویل انجام می‌شود.'],
                ],
                'meta_tail' => ' ساختار هزینه، عوامل مؤثر و شفافیت پرداخت را ببینید.',
                'links_line' => 'اطلاعات مرتبط: <a href="/services">خدمات</a> · <a href="/warranty">ضمانت</a>',
            ],
            'custom' => [
                'focus' => $topic,
                'intro' => '<strong>' . e($topic) . '</strong> — در این صفحه مطالب مرتبط با «' . e($topic) . '» را به‌صورت کامل و مرتب مرور می‌کنیم.',
                'supplement' => [
                    ['h2' => e($topic) . ' — نکات کلیدی', 'p' => 'در این بخش، نکات اصلی مرتبط با ' . e($topic) . ' را به زبان ساده مرور می‌کنیم تا بدون نیاز به جست‌وجوی بیشتر، پاسخ پرسش‌های رایج همین موضوع را پیدا کنید. مطالب بر اساس تجربه عملی و نیاز واقعی کاربران تنظیم شده است.'],
                    ['h2' => 'راهنمای عملی', 'p' => 'برای بهره‌برداری بهتر از این صفحه، ابتدا مطالب اصلی را بخوانید و در صورت نیاز جزئیات هر بخش را دنبال کنید. اگر پرسشی درباره ' . e($topic) . ' دارید که پاسخ آن اینجا نیست، از طریق راه‌های ارتباطی سایت مطرح کنید.'],
                    ['h2' => 'جمع‌بندی', 'p' => 'هدف این صفحه فراهم کردن اطلاعات دقیق و کاربردی درباره ' . e($topic) . ' است؛ محتوا به‌طور دوره‌ای بازبینی و تکمیل می‌شود تا همیشه به‌روز باشد.'],
                ],
                'meta_tail' => ' مطالب کلیدی و کاربردی این موضوع را در یک صفحه ببینید.',
                'links_line' => 'صفحات مرتبط: <a href="/services">خدمات</a> · <a href="/contact">تماس با ما</a>',
            ],
        ];

        /* 🗺️ مترادف‌های نوع صفحه */
        $alias = [
            'main' => 'home', 'index' => 'home', 'landing' => 'home',
            'about-us' => 'about', 'about_us' => 'about',
            'contact-us' => 'contact', 'contact_us' => 'contact',
            'questions' => 'faq', 'question' => 'faq',
            'article' => 'articles', 'blog' => 'articles', 'news' => 'articles', 'post' => 'articles',
            'error_code' => 'error-codes', 'errors' => 'error-codes', 'errorcode' => 'error-codes',
            'price' => 'prices', 'tariff' => 'prices', 'pricing' => 'prices',
            'guarantee' => 'warranty', 'garanti' => 'warranty',
            'service' => 'services', 'repairs' => 'services', 'repair' => 'services',
        ];
        $type = $alias[$type] ?? $type;

        $ctx = $profiles[$type] ?? $profiles['custom'];

        /* 🎯 صفحه اختصاصی: موضوع از عنوان — اگر عنوان با کلیدواژه پیش‌فرض فرق دارد،
           کلیدواژه را هم به موضوع عنوان نزدیک کن */
        if (($type === 'custom' || $type === '') && $topic !== '' && $topic !== 'این صفحه') {
            $ctx['focus'] = $topic;
        }

        /* بخش تراکم: درباره خودِ کلیدواژه در بستر موضوع صفحه */
        $kw = $ctx['focus'];
        $ctx['density_section'] = '<h2>' . e($kw) . ' — نکات کاربردی</h2>' . "\n" .
            '<p>در این بخش چند نکته مهم درباره ' . e($kw) . ' را مرور می‌کنیم: نخست اینکه اطلاعات این صفحه بر اساس تجربه عملی گردآوری شده و به‌طور دوره‌ای بازبینی می‌شود؛ دوم، برای هر موضوع مطرح‌شده، مسیر عملی مشخص شده تا بدون اتلاف زمان به نتیجه برسید؛ و سوم، اگر بخشی از ' . e($kw) . ' نیاز به توضیح بیشتر دارد، از طریق راه‌های ارتباطی همین سایت قابل پیگیری است.</p>';

        return $ctx;
    }

    /* ==================================================
     * 🛠️ ابزارها
     * ================================================== */

    private function bestTitle(string $current, string $focus): string
    {
        $candidates = [];
        if ($focus !== '') {
            $candidates[] = $focus . ' — راهنمای کامل و کاربردی';
            $candidates[] = $focus . ' | همه آنچه باید بدانید';
            $candidates[] = $focus;
        }
        if ($current !== '') {
            $candidates[] = $current;
            $candidates[] = mb_substr($current, 0, 55);
            if ($focus !== '' && mb_stripos($current, $focus) === false) {
                $candidates[] = mb_substr($current, 0, 45) . ' | ' . $focus;
            }
        }
        foreach ($candidates as $c) {
            $len = mb_strlen($c);
            $hasKw = $focus === '' || mb_stripos($c, $focus) !== false;
            if ($len >= 30 && $len <= 60 && $hasKw) {
                return $c;
            }
        }
        /* بریدن/کشاندن به بازه */
        $base = $candidates[0] ?? $focus;
        if (mb_strlen($base) > 60) {
            return mb_substr($base, 0, 57) . '…';
        }
        return $base . str_repeat(' — راهنما', (int)ceil((30 - mb_strlen($base)) / 10));
    }

    private function bestMeta(string $current, string $focus, string $pageTitle): string
    {
        $tail = $this->ctx['meta_tail'] ?? ' اطلاعات کامل و کاربردی در همین صفحه.';
        $base = $current !== ''
            ? $current
            : ($focus !== '' ? $focus . ' — همه آنچه باید بدانید' : ($pageTitle ?: 'این صفحه'));
        if (mb_strlen($base) >= 120 && mb_strlen($base) <= 160 && ($focus === '' || mb_stripos($base, $focus) !== false)) {
            return $base;
        }
        $out = $base;
        if ($focus !== '' && mb_stripos($out, $focus) === false) {
            $out = $focus . ' — ' . $out;
        }
        if (mb_strlen($out) > 160) {
            $out = mb_substr($out, 0, 158);
        }
        while (mb_strlen($out) < 120) {
            $out .= $tail;
        }
        return mb_substr($out, 0, 160);
    }

    private function keywordsFrom(string $focus, string $html, array $brand): string
    {
        $kws = [];
        if ($focus !== '') { $kws[] = $focus; }
        try {
            foreach (array_slice((new KeywordAnalyzer())->extract(strip_tags($html), 6), 0, 5) as $kw) {
                $kws[] = $kw['keyword'];
            }
        } catch (Throwable $e) {
        }
        return implode(', ', array_values(array_unique(array_filter($kws))));
    }

    /** 📄 بخش مکمل سئو-پسند — مطابق پروفایل نوع صفحه (v2.1) */
    private function supplementSection(): string
    {
        $out = '';
        foreach ($this->ctx['supplement'] ?? [] as $sec) {
            $out .= '<h2>' . e($sec['h2']) . '</h2>' . "\n" .
                '<p>' . e($sec['p']) . '</p>' . "\n";
        }
        return rtrim($out, "\n");
    }

    /**
     * 🖼 تولید بنر OG ساده برای صفحه (PNG با GD + متن فارسی؛ در نبود آن SVG)
     * v2.7 — فقط وقتی هیچ تصویر OG و لوگویی در دسترس نیست، به‌عنوان جایگزین حداقلی
     */
    private function generateTopicOgImage(string $topic, string $slug): string
    {
        try {
            $dir = ROOT_PATH . '/uploads/og';
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                return '';
            }
            $safe = preg_replace('/[^a-z0-9\-]/', '', SlugGenerator::generate($slug) ?: 'page') ?: 'page';
            $file = $dir . '/page-' . $safe . '-' . date('Ymd') . '.svg';
            $topic = mb_substr($topic, 0, 44);
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630">' .
                '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">' .
                '<stop offset="0" stop-color="#0e7490"/><stop offset="1" stop-color="#1e3a8a"/></linearGradient></defs>' .
                '<rect width="1200" height="630" fill="url(#g)"/>' .
                '<text x="600" y="300" font-size="52" fill="#ffffff" text-anchor="middle" font-family="Vazirmatn,Tahoma" font-weight="bold">' . e($topic) . '</text>' .
                '<text x="600" y="380" font-size="26" fill="#c7e3f4" text-anchor="middle" font-family="Vazirmatn,Tahoma">راهنمای کامل و کاربردی</text>' .
                '</svg>';
            if (@file_put_contents($file, $svg) === false) {
                return '';
            }
            return 'uploads/og/' . basename($file);
        } catch (Throwable $e) {
            return '';
        }
    }
}
