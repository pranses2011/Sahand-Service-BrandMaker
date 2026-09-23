<?php
/**
 * 🎨 اسکیل UI/UX Pro — طراح حرفه‌ای صفحات سایت‌ساز
 * ===============================================
 * مغز طراحی رابط و تجربه کاربر سایت‌ساز برند سهند سرویس.
 *
 * توانایی‌ها:
 *   🪄 designPage()    → تولید چیدمان بهینه هر نوع صفحه (بلوک‌ها + ویژگی‌ها + دلیل هر بخش)
 *   🔍 reviewLayout()  → ممیزی UX چیدمان موجود (امتیاز ۰-۱۰۰ + مشکلات + نقاط قوت)
 *   🛠️ improveLayout() → اصلاح خودکار چیدمان (هیرو، CTA، ریتم بصری، حذف شلوغی و...)
 *
 * دانش: engine/knowledge/uiux-patterns.json
 *   • ۹ قانون کلاسیک UX (هیک، فیتس، یاکوب، فون رستورف و...)
 *   • توکن‌های طراحی (فاصله، تایپ، رنگ ۶۰-۳۰-۱۰، گردی، سایه، بریک‌پوینت)
 *   • قواعد RTL فارسی + دسترس‌پذیری WCAG + الگوهای نرخ تبدیل
 *   • بلوپرینت ۱۷ نوع صفحه + قواعد ۳۴ بلوک + سیستم امتیازدهی
 *
 * @package SahandBrandMaker
 * @version 1.0
 */
class UIUXPro
{
    /** @var string نسخه اسکیل */
    public const SKILL_VERSION = '1.0';

    /** @var string نام نمایشی */
    public const SKILL_NAME_FA = 'اسکیل UI/UX Pro';

    /** @var array پایگاه دانش بارگذاری‌شده */
    private array $kb;

    public function __construct()
    {
        $this->kb = TextProcessor::loadKnowledge('uiux-patterns');
    }

    /* ==================================================
     * ℹ️ فراداده اسکیل
     * ================================================== */

    /**
     * 📇 اطلاعات اسکیل — نسخه، حجم دانش، پوشش
     */
    public function info(): array
    {
        $blueprints = $this->kb['page_blueprints'] ?? [];
        $blockRules = $this->kb['block_rules'] ?? [];
        return [
            'skill'          => self::SKILL_NAME_FA,
            'version'        => self::SKILL_VERSION,
            'ux_laws'        => count($this->kb['ux_laws'] ?? []),
            'page_blueprints' => count($blueprints),
            'block_rules'    => count($blockRules),
            'rtl_rules'      => count($this->kb['rtl_rules'] ?? []),
            'conversion_rules' => count($this->kb['conversion_rules'] ?? []),
            'tokens'         => array_keys($this->kb['design_tokens'] ?? []),
            'capabilities_fa' => [
                'طراحی خودکار چیدمان ۱۷ نوع صفحه',
                'ممیزی UX با امتیاز ۰-۱۰۰',
                'اصلاح خودکار چیدمان موجود',
                'رعایت قواعد RTL فارسی و WCAG',
            ],
        ];
    }

    /**
     * 📚 فهرست انواع صفحه پشتیبانی‌شده
     */
    public function pageTypes(): array
    {
        $out = [];
        foreach (($this->kb['page_blueprints'] ?? []) as $type => $bp) {
            $out[$type] = $bp['name_fa'] ?? $type;
        }
        return $out;
    }

    /* ==================================================
     * 🪄 طراحی صفحه — تولید چیدمان بهینه
     * ================================================== */

    /**
     * 🪄 طراحی چیدمان حرفه‌ای برای یک نوع صفحه
     *
     * @param string $pageType نوع صفحه (home/services/contact/...)
     * @param array  $context  زمینه اختیاری: devices_count, articles_count,
     *                         has_testimonials, brand_fa, agency_fa
     * @return array ['layout', 'ux_score', 'grade_fa', 'rationale', 'page_type', 'page_name_fa', 'goal_fa']
     */
    public function designPage(string $pageType, array $context = []): array
    {
        $pageType = $this->normalizePageType($pageType);
        $blueprint = $this->kb['page_blueprints'][$pageType] ?? $this->kb['page_blueprints']['custom'];

        // 🏗️ ساخت چیدمان از بلوپرینت دانش
        $layout = [];
        foreach (($blueprint['sections'] ?? []) as $section) {
            $block = (string)($section['block'] ?? '');
            if ($block === '') {
                continue;
            }
            $layout[] = [
                'block' => $block,
                'props' => array_merge(
                    ['visible' => true],
                    is_array($section['props'] ?? null) ? $section['props'] : []
                ),
            ];
        }

        // 🧠 تنظیمات زمینه‌محور — واقعی‌سازی چیدمان با داده‌های برند
        $contextChanges = $this->applyContext($layout, $pageType, $context);

        // 📊 ممیزی نتیجه
        $review = $this->reviewLayout($layout, $pageType);

        $rationale = [];
        foreach (($blueprint['sections'] ?? []) as $section) {
            if (!empty($section['why_fa'])) {
                $rationale[] = $section['why_fa'];
            }
        }
        foreach ($contextChanges as $change) {
            $rationale[] = $change;
        }

        return [
            'page_type'   => $pageType,
            'page_name_fa' => $blueprint['name_fa'] ?? $pageType,
            'goal_fa'     => $blueprint['goal_fa'] ?? '',
            'layout'      => $layout,
            'ux_score'    => $review['score'],
            'grade_fa'    => $review['grade_fa'],
            'rationale'   => array_slice($rationale, 0, 10),
            'skill'       => self::SKILL_NAME_FA . ' v' . self::SKILL_VERSION,
        ];
    }

    /* ==================================================
     * 🔍 ممیزی چیدمان — امتیاز UX
     * ================================================== */

    /**
     * 🔍 ممیزی UX چیدمان موجود
     *
     * @param array      $layout   چیدمان [{block, props}, ...]
     * @param string|null $pageType نوع صفحه (اختیاری — برای قواعد صفحه‌محور)
     * @return array ['score', 'grade_fa', 'grade_key', 'issues', 'wins', 'stats']
     */
    public function reviewLayout(array $layout, ?string $pageType = null): array
    {
        $w = $this->kb['scoring']['weights'] ?? [];
        $thinPages = $this->kb['scoring']['thin_pages'] ?? [];
        $isThin = $pageType !== null && in_array($pageType, $thinPages, true);
        $ideal = $isThin
            ? ($this->kb['scoring']['block_count_ideal_thin'] ?? [2, 8])
            : ($this->kb['scoring']['block_count_ideal'] ?? [6, 13]);
        $maxBlocks = (int)($this->kb['scoring']['block_count_max'] ?? 16);
        $score = 0.0;
        $issues = [];
        $wins = [];

        // 🧹 فقط بلوک‌های معتبر و نمایان
        $visible = [];
        foreach ($layout as $item) {
            if (is_array($item) && !empty($item['block']) && ($item['props']['visible'] ?? true) !== false) {
                $visible[] = $item;
            }
        }

        $blocks = array_column($visible, 'block');
        $count = count($blocks);
        $heroList = $this->kb['scoring']['hero_blocks'] ?? [];
        $ctaList = $this->kb['scoring']['cta_blocks'] ?? [];
        $trustList = $this->kb['scoring']['trust_blocks'] ?? [];

        /* ---------- ۱) هیرو ---------- */
        $heroIdx = [];
        foreach ($blocks as $i => $b) {
            if (in_array($b, $heroList, true)) {
                $heroIdx[] = $i;
            }
        }
        if (!empty($heroIdx) && $heroIdx[0] <= 2) {
            $score += (float)($w['hero_first'] ?? 15);
            $wins[] = '✅ بخش هیرو بالای صفحه قرار دارد — پیام اصلی بلافاصله دیده می‌شود';
        } elseif (!empty($heroIdx)) {
            $score += 6;
            $issues[] = ['severity' => 'high', 'fa' => 'بخش هیرو باید در ابتدای صفحه (بعد از هدر) باشد؛ در حال حاضر پایین‌تر قرار گرفته است.'];
        } else {
            $issues[] = ['severity' => 'high', 'fa' => 'هیرو وجود ندارد — کاربر در ۵ ثانیه اول نمی‌فهمد صفحه چه می‌گوید. یک هیرو با وعده ارزش + CTA اضافه کنید.'];
        }
        if (count($heroIdx) <= 1) {
            $score += (float)($w['hero_single'] ?? 5);
        } else {
            $issues[] = ['severity' => 'medium', 'fa' => 'بیش از یک هیرو در صفحه است — تمرکز پیام از بین می‌رود (قانون فون رستورف). یکی کافی است.'];
        }

        /* ---------- ۲) CTA ---------- */
        $ctaPositions = [];
        foreach ($blocks as $i => $b) {
            if (in_array($b, $ctaList, true)) {
                $ctaPositions[] = $i;
            }
        }
        if (!empty($ctaPositions)) {
            $score += (float)($w['cta_present'] ?? 18);
            $wins[] = '✅ دعوت به اقدام (CTA) در صفحه وجود دارد';
            if (end($ctaPositions) >= max(0, $count - 3)) {
                $score += (float)($w['cta_ending'] ?? 7);
                $wins[] = '✅ صفحه با CTA قوی بسته می‌شود (قانون اوج و پایان)';
            } else {
                $issues[] = ['severity' => 'medium', 'fa' => 'پایان صفحه بدون CTA است — کاربر بعد از خواندن، بی‌راهنما رها می‌شود. یک cta-phone یا cta-banner انتهای صفحه اضافه کنید.'];
            }
        } else {
            $issues[] = ['severity' => 'critical', 'fa' => 'هیچ CTA در صفحه نیست — مهم‌ترین عامل افت نرخ تبدیل. حداقل یک دکمه تماس یا درخواست اضافه کنید.'];
        }

        /* ---------- ۳) تعداد بخش‌ها ---------- */
        if ($count >= $ideal[0] && $count <= $ideal[1]) {
            $score += (float)($w['block_count_range'] ?? 10);
            $wins[] = '✅ تعداد بخش‌ها در بازه بهینه (' . $ideal[0] . '-' . $ideal[1] . ') است';
        } elseif ($count > 0 && $count < $ideal[0]) {
            $issues[] = ['severity' => 'medium', 'fa' => 'صفحه کم‌محتواست (' . $count . ' بخش) — برای اعتمادسازی و سئو حداقل ' . $ideal[0] . ' بخش لازم است.'];
        } else {
            $issues[] = ['severity' => 'low', 'fa' => 'صفحه بلند است (' . $count . ' بخش) — اگر بخش تکراری دارید حذف کنید یا صفحه را بشکافید.'];
        }
        if ($count <= $maxBlocks) {
            $score += (float)($w['no_clutter'] ?? 5);
        } else {
            $issues[] = ['severity' => 'medium', 'fa' => 'بیش از ' . $maxBlocks . ' بخش = شلوغی و افت تجربه (قانون هیک). بخش‌های کم‌ارزش را حذف کنید.'];
        }

        /* ---------- ۴) تکرارهای پشت‌سرهم ---------- */
        $dup = false;
        for ($i = 1; $i < $count; $i++) {
            if ($blocks[$i] === $blocks[$i - 1]) {
                $dup = true;
                break;
            }
        }
        if (!$dup) {
            $score += (float)($w['no_consecutive_duplicate'] ?? 5);
        } else {
            $issues[] = ['severity' => 'low', 'fa' => 'دو بلوک یکسان پشت‌سرهم قرار دارند — ریتم بصری یکنواخت و خسته‌کننده می‌شود.'];
        }

        /* ---------- ۵) بردکرامب صفحات داخلی ---------- */
        $innerNeeds = $this->kb['scoring']['inner_pages_need_breadcrumb'] ?? [];
        if ($pageType !== null && in_array($pageType, $innerNeeds, true)) {
            if (in_array('breadcrumb', $blocks, true)) {
                $score += (float)($w['breadcrumb_inner_page'] ?? 5);
            } else {
                $issues[] = ['severity' => 'low', 'fa' => 'صفحه داخلی بدون بردکرامب است — جهت‌یابی کاربر و سئو بهبود می‌یابد اگر اضافه شود.'];
            }
        } else {
            $score += (float)($w['breadcrumb_inner_page'] ?? 5); // صفحه اصلی نیازی ندارد
        }

        /* ---------- ۶) سیگنال‌های اعتماد (برای صفحات محتوایی) ---------- */
        $trustFound = [];
        foreach ($blocks as $b) {
            if (in_array($b, $trustList, true)) {
                $trustFound[] = $b;
            }
        }
        if (!empty($trustFound)) {
            $score += (float)($w['trust_signals'] ?? 10);
            $wins[] = '✅ سیگنال اعتماد موجود است (' . count($trustFound) . ' مورد: آمار/نظرات/تیم/ویژگی‌ها)';
        } elseif ($isThin) {
            $score += (float)($w['trust_signals'] ?? 10); // صفحه کم‌بخش (حقوقی/نقشه) نیازی به سیگنال اعتماد ندارد
        } else {
            $issues[] = ['severity' => 'medium', 'fa' => 'سیگنال اعتماد (نظرات مشتریان، آمار، ضمانت) غایب است — بدون اعتماد، CTA هم اثر ندارد.'];
        }

        /* ---------- ۷) ریتم بصری پس‌زمینه ---------- */
        $bgs = [];
        foreach ($visible as $item) {
            $bgs[] = (string)($item['props']['background'] ?? 'default');
        }
        $bgVariety = count(array_unique($bgs));
        if ($bgVariety >= 3) {
            $score += (float)($w['background_rhythm'] ?? 8);
            $wins[] = '✅ ریتم بصری پس‌زمینه متنوع است (' . $bgVariety . ' حالت)';
        } elseif ($bgVariety === 2) {
            $score += 4;
        } else {
            $issues[] = ['severity' => 'low', 'fa' => 'همه بخش‌ها پس‌زمینه یکسان دارند — تنوع پس‌زمینه (معمولی/کمرنگ/رنگ اصلی) جداسازی ادراکی بخش‌ها را بهتر می‌کند.'];
        }

        /* ---------- ۸) تنوع فاصله ---------- */
        $pads = [];
        foreach ($visible as $item) {
            $pads[] = (string)($item['props']['padding'] ?? 'default');
        }
        if (count(array_unique($pads)) >= 2) {
            $score += (float)($w['padding_variety'] ?? 4);
        } else {
            $issues[] = ['severity' => 'low', 'fa' => 'فاصله داخلی همه بخش‌ها یکسان است — هیرو جادار (roomy) و بخش‌های میانی متعادل باشند.'];
        }

        /* ---------- ۹) FAQ (فقط صفحات نیازمند) ---------- */
        $needFaq = !$isThin && in_array($pageType, ['home', 'services', 'contact', 'faq', 'warranty', 'request'], true);
        if (!$needFaq || in_array('faq-accordion', $blocks, true)) {
            $score += (float)($w['faq_presence'] ?? 5);
            if ($needFaq) {
                $wins[] = '✅ سوالات متداول موجود است — رفع تردید + اسکیمای FAQ گوگل';
            }
        } else {
            $issues[] = ['severity' => 'medium', 'fa' => 'این نوع صفحه به بخش سوالات متداول نیاز دارد — تردیدهای نهایی کاربر را رفع می‌کند و رتبه سئو را بالا می‌برد.'];
        }

        /* ---------- ۱۰) بلوک‌های مخفی ---------- */
        $hiddenCount = count($layout) - count($visible);
        if ($hiddenCount === 0) {
            $score += (float)($w['no_hidden_blocks'] ?? 3);
        } else {
            $issues[] = ['severity' => 'low', 'fa' => $hiddenCount . ' بلوک مخفی در چیدمان است — برای شفافیت حذفشان کنید.'];
        }

        /* ---------- ۱۱) قواعد بلوک‌ها (نقض max_per_page) ---------- */
        $blockRules = $this->kb['block_rules'] ?? [];
        $counts = array_count_values($blocks);
        foreach ($counts as $blockKey => $n) {
            $limit = (int)($blockRules[$blockKey]['max_per_page'] ?? 0);
            if ($limit > 0 && $n > $limit) {
                $issues[] = ['severity' => 'medium', 'fa' => 'بلوک «' . $blockKey . '» بیش از حد مجاز تکرار شده (' . $n . ' از ' . $limit . ') — حذف یا ادغام کنید.'];
            }
        }

        /* ---------- جمع‌بندی ---------- */
        $maxPossible = 0;
        foreach ($w as $weight) {
            $maxPossible += (float)$weight;
        }
        $final = $maxPossible > 0 ? round(min(100, $score / $maxPossible * 100)) : 0;

        // مرتب‌سازی مشکلات: بحرانی → بالا → متوسط → پایین
        $order = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        usort($issues, function ($a, $b) use ($order) {
            return ($order[$a['severity']] ?? 9) <=> ($order[$b['severity']] ?? 9);
        });

        return [
            'score'    => (int)$final,
            'grade_key' => $final >= 85 ? 'a' : ($final >= 70 ? 'b' : ($final >= 50 ? 'c' : 'd')),
            'grade_fa' => $this->gradeFa($final),
            'issues'   => array_slice($issues, 0, 10),
            'wins'     => array_slice($wins, 0, 8),
            'stats'    => [
                'blocks'       => $count,
                'cta_count'    => count($ctaPositions),
                'trust_count'  => count($trustFound),
                'bg_variety'   => $bgVariety,
                'hidden'       => $hiddenCount,
            ],
            'skill'    => self::SKILL_NAME_FA . ' v' . self::SKILL_VERSION,
        ];
    }

    /* ==================================================
     * 🛠️ اصلاح چیدمان — بهبود خودکار
     * ================================================== */

    /**
     * 🛠️ اصلاح خودکار چیدمان موجود بر اساس قواعد اسکیل
     *
     * @param array  $layout   چیدمان ورودی
     * @param string $pageType نوع صفحه
     * @return array ['layout', 'changes', 'score_before', 'score_after', 'grade_fa', 'issues_left']
     */
    public function improveLayout(array $layout, string $pageType): array
    {
        $pageType = $this->normalizePageType($pageType);
        $before = $this->reviewLayout($layout, $pageType);
        $changes = [];
        $maxBlocks = (int)($this->kb['scoring']['block_count_max'] ?? 16);

        /* ---------- ۱) حذف بلوک‌های مخفی ---------- */
        $clean = [];
        foreach ($layout as $item) {
            if (is_array($item) && !empty($item['block']) && ($item['props']['visible'] ?? true) !== false) {
                $clean[] = $item;
            } else {
                $changes[] = '🧹 بلوک مخفی حذف شد';
            }
        }
        $layout = $clean;

        /* ---------- ۲) حذف تکرارهای پشت‌سرهم ---------- */
        $clean = [];
        $last = '';
        foreach ($layout as $item) {
            if ($item['block'] === $last && !in_array($item['block'], ['spacer', 'separator'], true)) {
                $changes[] = '🧹 بلوک تکراری پشت‌سرهم حذف شد (' . $item['block'] . ')';
                continue;
            }
            $clean[] = $item;
            $last = $item['block'];
        }
        $layout = $clean;

        /* ---------- ۳) تضمین هیرو در ابتدا ---------- */
        $heroList = $this->kb['scoring']['hero_blocks'] ?? ['hero'];
        $heroIdx = null;
        foreach ($layout as $i => $item) {
            if (in_array($item['block'], $heroList, true)) {
                $heroIdx = $i;
                break;
            }
        }
        if ($heroIdx === null) {
            array_splice($layout, $this->afterHeaderIndex($layout), 0, [[
                'block' => 'hero',
                'props' => ['padding' => 'roomy', 'background' => 'gradient', 'visible' => true],
            ]]);
            $changes[] = '🪄 هیرو به ابتدای صفحه اضافه شد (وعده ارزش + CTA بالای تاشدگی)';
        } elseif ($heroIdx > 3) {
            $hero = array_splice($layout, $heroIdx, 1);
            array_splice($layout, $this->afterHeaderIndex($layout), 0, $hero);
            $changes[] = '↕️ هیرو به ابتدای صفحه منتقل شد';
        }

        /* ---------- ۴) تضمین CTA پایانی ---------- */
        $ctaList = $this->kb['scoring']['cta_blocks'] ?? [];
        $hasCta = false;
        foreach (array_slice($layout, -4) as $item) {
            if (in_array($item['block'], $ctaList, true)) {
                $hasCta = true;
                break;
            }
        }
        if (!$hasCta && !empty($layout)) {
            $layout[] = [
                'block' => 'cta-phone',
                'props' => ['padding' => 'roomy', 'background' => 'primary', 'visible' => true],
            ];
            $changes[] = '📞 دکمه تماس بزرگ (CTA) به پایان صفحه اضافه شد';
        }

        /* ---------- ۵) بردکرامب صفحات داخلی ---------- */
        $innerNeeds = $this->kb['scoring']['inner_pages_need_breadcrumb'] ?? [];
        $blockKeys = array_column($layout, 'block');
        if (in_array($pageType, $innerNeeds, true) && !in_array('breadcrumb', $blockKeys, true)) {
            array_splice($layout, $this->afterHeaderIndex($layout), 0, [[
                'block' => 'breadcrumb',
                'props' => ['padding' => 'compact', 'background' => 'default', 'visible' => true],
            ]]);
            $changes[] = '🧭 بردکرامب اضافه شد (جهت‌یابی + سئو)';
        }

        /* ---------- ۶) ریتم پس‌زمینه و فاصله ---------- */
        $lastBg = '';
        foreach ($layout as &$item) {
            $isHero = in_array($item['block'], $heroList, true);
            $isCta = in_array($item['block'], $ctaList, true);
            $item['props'] = $item['props'] ?? [];

            // هیرو: جادار + گرادیانت؛ CTA: رنگ اصلی
            if ($isHero) {
                $item['props']['padding'] = $item['props']['padding'] ?? 'roomy';
                if ((string)($item['props']['background'] ?? 'default') === 'default') {
                    $item['props']['background'] = 'gradient';
                }
            } elseif ($isCta) {
                $item['props']['padding'] = $item['props']['padding'] ?? 'roomy';
                if ((string)($item['props']['background'] ?? 'default') === 'default') {
                    $item['props']['background'] = 'primary';
                }
            } else {
                // تناوب نرم پس‌زمینه برای جداسازی بخش‌ها
                $cur = (string)($item['props']['background'] ?? 'default');
                if ($cur === $lastBg && $cur !== 'default') {
                    $item['props']['background'] = 'default';
                    $cur = 'default';
                }
                $lastBg = $cur;
            }
            $item['props']['visible'] = $item['props']['visible'] ?? true;
        }
        unset($item);
        $changes[] = '🎨 ریتم بصری پس‌زمینه و فاصله‌ها هماهنگ شد';

        /* ---------- ۷) مهار شلوغی ---------- */
        if (count($layout) > $maxBlocks) {
            $layout = array_slice($layout, 0, $maxBlocks);
            $changes[] = '✂️ شلوغی مهار شد — حداکثر ' . $maxBlocks . ' بخش (قانون هیک)';
        }

        $after = $this->reviewLayout($layout, $pageType);

        return [
            'layout'       => $layout,
            'changes'      => array_slice(array_values(array_unique($changes)), 0, 12),
            'score_before' => $before['score'],
            'score_after'  => $after['score'],
            'grade_fa'     => $after['grade_fa'],
            'issues_left'  => $after['issues'],
        ];
    }

    /* ==================================================
     * 🧰 متدهای داخلی
     * ================================================== */

    /**
     * 🧠 تنظیم چیدمان بر اساس زمینه واقعی برند
     */
    private function applyContext(array &$layout, string $pageType, array $context): array
    {
        $changes = [];

        // اگر مقاله‌ای نیست، بخش مقالات اخیر حذف شود
        $articlesCount = (int)($context['articles_count'] ?? 1);
        if ($articlesCount < 1) {
            $this->removeBlock($layout, 'articles-recent');
            $changes[] = 'حذف بخش مقالات (هنوز مقاله‌ای منتشر نشده)';
        }

        // اگر دستگاهی ثبت نیست، کارت خدمات جایگزین متن شود
        $devicesCount = (int)($context['devices_count'] ?? 1);
        if ($devicesCount < 1) {
            $this->replaceBlock($layout, 'services-grid', 'three-col');
            $changes[] = 'کارت خدمات به‌جای شبکه دستگاه‌ها با ستون‌های توضیحی جایگزین شد';
        }

        // نظرات مشتریان فقط اگر داده‌ای داریم
        $hasTestimonials = (bool)($context['has_testimonials'] ?? true);
        if (!$hasTestimonials) {
            $this->replaceBlock($layout, 'testimonials', 'features');
            $changes[] = 'نظرات مشتریان با بخش ویژگی‌ها جایگزین شد (داده نظرات موجود نیست)';
        }

        // عنوان هیرو با نام برند (اگر موجود)
        $brandFa = trim((string)($context['brand_fa'] ?? ''));
        if ($brandFa !== '') {
            foreach ($layout as &$item) {
                $isHero = in_array($item['block'], ($this->kb['scoring']['hero_blocks'] ?? []), true);
                if ($isHero && empty($item['props']['title'])) {
                    $item['props']['title'] = 'تعمیرات تخصصی ' . $brandFa;
                    $changes[] = 'عنوان هیرو با نام برند شخصی‌سازی شد';
                    break;
                }
            }
            unset($item);
        }

        return $changes;
    }

    private function removeBlock(array &$layout, string $blockKey): void
    {
        $layout = array_values(array_filter($layout, function ($item) use ($blockKey) {
            return ($item['block'] ?? '') !== $blockKey;
        }));
    }

    private function replaceBlock(array &$layout, string $from, string $to): void
    {
        foreach ($layout as &$item) {
            if (($item['block'] ?? '') === $from) {
                $item['block'] = $to;
            }
        }
        unset($item);
    }

    /**
     * 📍 ایندکس بعد از هدر/نوار بالایی — نقطه درج بخش‌های ابتدایی
     */
    private function afterHeaderIndex(array $layout): int
    {
        $insertAt = 0;
        foreach ($layout as $i => $item) {
            if (in_array($item['block'] ?? '', ['top-bar', 'header-v1', 'header-v2', 'header-v3'], true)) {
                $insertAt = $i + 1;
            }
        }
        return $insertAt;
    }

    /**
     * 🔤 نرمال‌سازی نوع صفحه — تطبیق کلیدهای مترادف
     */
    private function normalizePageType(string $pageType): string
    {
        $pageType = trim($pageType);
        if ($pageType === '' || $pageType === '0') {
            return 'home';
        }
        $aliases = [
            'about-us' => 'about-agency',
            'about_agency' => 'about-agency',
            'about_brand' => 'about-brand',
            'brand' => 'about-brand',
            'area' => 'service-area',
            'codes' => 'error-codes',
            'faqs' => 'faq',
            'terms-of-service' => 'terms',
            'privacy-policy' => 'privacy',
            'sitemap' => 'sitemap-page',
        ];
        $pageType = $aliases[$pageType] ?? $pageType;
        $known = array_keys($this->kb['page_blueprints'] ?? []);
        return in_array($pageType, $known, true) ? $pageType : 'custom';
    }

    /**
     * 🎖️ تبدیل امتیاز به توصیف فارسی
     */
    private function gradeFa(int $score): string
    {
        $grades = $this->kb['scoring']['grade_fa'] ?? [];
        if ($score >= 85) {
            return ($grades['a'] ?? 'عالی') . ' (' . $score . '/۱۰۰)';
        }
        if ($score >= 70) {
            return ($grades['b'] ?? 'خوب') . ' (' . $score . '/۱۰۰)';
        }
        if ($score >= 50) {
            return ($grades['c'] ?? 'متوسط') . ' (' . $score . '/۱۰۰)';
        }
        return ($grades['d'] ?? 'ضعیف') . ' (' . $score . '/۱۰۰)';
    }
}
