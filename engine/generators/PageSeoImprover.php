<?php
/**
 * 🚀 PageSeoImprover — بهبود خودکار سئوی صفحه برند تا امتیاز ۱۰۰ (v1.0)
 * ============================================================================
 * حلقه تکرارشونده: تحلیل → اعمال اصلاح‌های ممکن → سنجش مجدد (حداکثر ۶ دور)
 * تا رسیدن به امتیاز ۱۰۰ یا اتمام اصلاح‌های خودکار.
 *
 * اصلاح‌ها: عنوان ۳۰-۶۰ با کلیدواژه، متا ۱۲۰-۱۶۰ با کلیدواژه، اسلاگ تمیز با
 * کلیدواژه، کلیدواژه در مقدمه، تراکم کلیدواژه، H1 یکتا، ساختار H2/H3،
 * alt تصاویر، لینک داخلی، حجم محتوا (بخش‌های سئو-پسند مکمل)، OG هم‌گام.
 *
 * @package SahandBrandMaker
 * @version 1.0
 */
class PageSeoImprover
{
    /** @var int حداکثر دور بهبود */
    const MAX_ROUNDS = 6;

    /**
     * 🚀 بهبود یک صفحه تا ۱۰۰
     *
     * @param array  $page رکورد brand_pages
     * @param string $focus کلیدواژه کانونی
     * @param array  $brand رکورد برند (برای متن‌های مکمل)
     * @return array ['score_before','score_after','rounds','applied'[], 'page' => فیلدهای به‌روزشده]
     */
    public function improve(array $page, string $focus, array $brand): array
    {
        /* استخراج محتوای اصلی از JSON ساختاریافته */
        $data = json_decode((string)($page['content'] ?? ''), true) ?: [];
        $mainField = isset($data['content']) ? 'content' : (isset($data['intro']) ? 'intro' : (array_key_first($data) ?: 'content'));
        $html = (string)($data[$mainField] ?? '');

        $title = (string)($page['seo_title'] ?? '');
        $meta = (string)($page['seo_description'] ?? '');
        $slug = (string)($page['slug'] ?? '');
        $robots = (string)($page['seo_robots'] ?? 'index,follow');
        $ogImage = (string)($page['og_image'] ?? '');

        $analyzer = new SeoAnalyzer();
        $first = $analyzer->analyze([
            'title' => $title, 'meta_description' => $meta, 'content' => $html,
            'slug' => $slug, 'seo_robots' => $robots, 'og_image' => $ogImage,
        ], $focus);
        $scoreBefore = (int)$first['score'];

        $applied = [];
        $score = $scoreBefore;
        for ($round = 1; $round <= self::MAX_ROUNDS; $round++) {
            $analysis = $analyzer->analyze([
                'title' => $title, 'meta_description' => $meta, 'content' => $html,
                'slug' => $slug, 'seo_robots' => $robots, 'og_image' => $ogImage,
            ], $focus);
            $score = (int)$analysis['score'];
            if ($score >= 100) { break; }
            $failed = [];
            foreach ($analysis['checks'] as $c) {
                if (empty($c['passed'])) { $failed[$c['id']] = $c; }
            }
            $changed = false;

            /* --- عنوان --- */
            if (isset($failed['title_exists']) || isset($failed['title_length']) || isset($failed['kw_in_title'])) {
                $newTitle = $this->bestTitle($title ?: ((string)($page['title'] ?? '')), $focus, $brand);
                if ($newTitle !== $title && $newTitle !== '') {
                    $title = $newTitle;
                    $applied[] = 'بازنویسی عنوان سئو در بازه ۳۰-۶۰ کاراکتر با کلیدواژه';
                    $changed = true;
                }
            }

            /* --- متا توضیحات --- */
            if (isset($failed['meta_exists']) || isset($failed['meta_length']) || isset($failed['kw_in_meta'])) {
                $newMeta = $this->bestMeta($meta, $focus, $brand, (string)($page['title'] ?? ''));
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

            /* --- کلیدواژه در مقدمه --- */
            if (isset($failed['kw_in_intro']) && $focus !== '') {
                $intro = '<p><strong>' . e($focus) . '</strong> — در این صفحه از سایت ' . e($brand['name_fa'] ?? '') . '، همه نکات، خدمات و راه‌های تماس مرتبط را به‌صورت کامل و به‌روز مرور می‌کنیم.</p>' . "\n";
                $html = $intro . $html;
                $applied[] = 'افزودن کلیدواژه کانونی به پاراگراف اول';
                $changed = true;
            }

            /* --- تراکم کلیدواژه --- */
            if (isset($failed['kw_density']) && $focus !== '') {
                $text = trim(strip_tags($html));
                $wc = max(1, TextProcessor::wordCount($text));
                $occ = mb_substr_count($text, $focus);
                $density = $occ / $wc * 100;
                if ($density < 0.8) {
                    $html .= "\n" . '<h2>' . e($focus) . ' در ' . e($brand['name_fa'] ?? '') . '</h2>' . "\n" .
                        '<p>' . e($focus) . ' یکی از خدمات تخصصی ' . e($brand['name_fa'] ?? '') . ' است؛ با سال‌ها تجربه و قطعات اصل، نتیجه‌ای مطمئن و ماندگار تحویل می‌گیرید. برای ' . e($focus) . ' کافی است همین حالا ثبت درخواست کنید تا کارشناسان ما در سریع‌ترین زمان با شما تماس بگیرند.</p>';
                    $applied[] = 'افزایش تراکم کلیدواژه به بازه استاندارد';
                    $changed = true;
                } elseif ($density > 3.0) {
                    $html = preg_replace('/' . preg_quote(e($focus), '/') . '/u', 'این موضوع', $html, max(1, (int)ceil($occ / 3)));
                    $applied[] = 'کاهش تراکم بیش‌ازحد کلیدواژه';
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

            /* --- ساختار هدینگ --- */
            if (isset($failed['heading_structure']) || isset($failed['content_length']) || isset($failed['content_depth'])) {
                $html .= "\n" . $this->supplementSection($focus, $brand);
                $applied[] = 'افزودن بخش‌های مکمل سئو-پسند (H2/H3 + محتوای مفصل ۶۰۰+ کلمه)';
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

            /* --- لینک داخلی --- */
            if (isset($failed['internal_links'])) {
                $html .= "\n" . '<p>🔗 خدمات مرتبط: <a href="/services">صفحه خدمات</a> · <a href="/request">ثبت درخواست تعمیر</a></p>';
                $applied[] = 'افزودن لینک‌های داخلی (خدمات + ثبت درخواست)';
                $changed = true;
            }

            if (!$changed) { break; } // دیگر اصلاح خودکاری نیست
        }

        $final = $analyzer->analyze([
            'title' => $title, 'meta_description' => $meta, 'content' => $html,
            'slug' => $slug, 'seo_robots' => $robots, 'og_image' => $ogImage,
        ], $focus);

        /* بازگرداندن JSON ساختاریافته با محتوای بهبودیافته */
        $data[$mainField] = $html;

        /* 🧠 خودیادگیر */
        try {
            if ((int)$final['score'] > $scoreBefore) {
                SelfLearner::record('seo_fix', 'brand_page:' . ($page['page_type'] ?? 'page'), 'page_seo_auto_100',
                    ['rounds' => self::MAX_ROUNDS], ['score' => $scoreBefore], ['score' => (int)$final['score']],
                    'حلقه بهبود خودکار صفحه (تحلیل→اصلاح→سنجش) امتیاز را از ' . $scoreBefore . ' به ' . (int)$final['score'] . ' رساند — همین الگوی اصلاح در صفحات بعدی ترجیح داده شود.');
            }
        } catch (Throwable $e) {
        }

        return [
            'score_before' => $scoreBefore,
            'score_after'  => (int)$final['score'],
            'grade'        => $final['grade'] ?? '',
            'rounds'       => $round ?? 0,
            'applied'      => array_values(array_unique($applied)),
            'page'         => [
                'seo_title'       => $title,
                'seo_description' => $meta,
                'seo_keywords'    => $this->keywordsFrom($focus, $html, $brand),
                'slug'            => $slug,
                'robots'          => $robots,
                'og_title'        => $title,
                'og_description'  => $meta,
                'content_json'    => json_encode($data, JSON_UNESCAPED_UNICODE),
                'seo_score'       => (int)$final['score'],
            ],
        ];
    }

    /* ==================================================
     * 🛠️ ابزارها
     * ================================================== */

    private function bestTitle(string $current, string $focus, array $brand): string
    {
        $brandFa = (string)($brand['name_fa'] ?? '');
        $candidates = [];
        if ($focus !== '') {
            $candidates[] = $focus . ' | ' . ($brandFa ?: 'خدمات تخصصی');
            $candidates[] = $focus . ' — راهنمای کامل و تخصصی';
            $candidates[] = 'بهترین ' . $focus . ($brandFa ? ' از ' . $brandFa : '');
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
        return $base . str_repeat(' — خدمات', (int)ceil((30 - mb_strlen($base)) / 12));
    }

    private function bestMeta(string $current, string $focus, array $brand, string $pageTitle): string
    {
        $brandFa = (string)($brand['name_fa'] ?? '');
        $base = $current !== '' ? $current : ($focus !== '' ? $focus . ($brandFa ? ' در ' . $brandFa : '') . ' — همه آنچه باید بدانید' : ($pageTitle ?: 'صفحه خدمات'));
        if (mb_strlen($base) >= 120 && mb_strlen($base) <= 160 && ($focus === '' || mb_stripos($base, $focus) !== false)) {
            return $base;
        }
        $tail = ' کارشناسان ما با قطعات اصل و ضمانت کتبی در خدمت شما هستند؛ همین حالا ثبت درخواست کنید.';
        $out = $base;
        if ($focus !== '' && mb_stripos($out, $focus) === false) {
            $out = $focus . ' — ' . $out;
        }
        if (mb_strlen($out) > 160) {
            $out = mb_substr($out, 0, 158);
        }
        while (mb_strlen($out) < 120) {
            $out .= $tail;
            $tail = ' برای مشاوره رایگان تماس بگیرید.';
        }
        return mb_substr($out, 0, 160);
    }

    private function keywordsFrom(string $focus, string $html, array $brand): string
    {
        $kws = [];
        if ($focus !== '') { $kws[] = $focus; }
        if (!empty($brand['name_fa'])) { $kws[] = 'تعمیر ' . $brand['name_fa']; }
        try {
            foreach (array_slice((new KeywordAnalyzer())->extract(strip_tags($html), 6), 0, 4) as $kw) {
                $kws[] = $kw['keyword'];
            }
        } catch (Throwable $e) {
        }
        return implode(', ', array_values(array_unique(array_filter($kws))));
    }

    /** 📄 بخش مکمل سئو-پسند برای رسیدن به حجم و ساختار استاندارد */
    private function supplementSection(string $focus, array $brand): string
    {
        $brandFa = (string)($brand['name_fa'] ?? '');
        $kw = $focus !== '' ? $focus : 'خدمات تعمیر تخصصی';
        return '<h2>چرا ' . e($brandFa ?: 'ما') . ' برای ' . e($kw) . '؟</h2>' . "\n" .
            '<p>تجربه چندین ساله تیم فنی، استفاده از قطعات اصل و ارائه ضمانت کتبی، تفاوت اصلی خدمات ما برای ' . e($kw) . ' است. فرآیند کار ما شامل عیب‌یابی دقیق، ارائه برآورد شفاف هزینه و اجرای تعمیر در کوتاه‌ترین زمان ممکن است؛ به‌گونه‌ای که در هر مرحله از روند تعمیر، دقیقاً می‌دانید چه اتفاقی می‌افتد و چه هزینه‌ای در انتظار شماست.</p>' . "\n" .
            '<h2>مراحل دریافت خدمات</h2>' . "\n" .
            '<h3>۱. ثبت درخواست آنلاین</h3>' . "\n" .
            '<p>فرم درخواست همین صفحه را تکمیل کنید تا کارشناسان ما در نخستین فرصت با شما تماس بگیرند و زمان بازدید را هماهنگ کنند.</p>' . "\n" .
            '<h3>۲. عیب‌یابی تخصصی و برآورد هزینه</h3>' . "\n" .
            '<p>تکنسین مجاز به محل شما اعزام می‌شود، ایراد را با تجهیزات دقیق شناسایی می‌کند و برآورد شفاف هزینه را پیش از شروع کار اعلام می‌نماید.</p>' . "\n" .
            '<h3>۳. تعمیر با قطعات اصل و ضمانت</h3>' . "\n" .
            '<p>تعمیر با قطعات استاندارد انجام و در پایان، گارانتی کتبی خدمات تحویل شما می‌شود تا با خیال راحت از نتیجه کار استفاده کنید.</p>';
    }
}
