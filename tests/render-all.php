<?php
/**
 * 🎭 رندر همه بلوک‌ها در یکی از دو رندرگر — خروجی JSON برای تست انطباق
 * اجرا: php tests/render-all.php preview|site
 * زیرپروسه‌ای است چون دو رندرگر توابع هم‌نام دارند (pvItems و ...) و
 * در یک فرآیند بارگذاری هم‌زمان ممکن نیست.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$mode = $argv[1] ?? '';
if (!in_array($mode, ['preview', 'site'], true)) {
    fwrite(STDERR, "حالت نامعتبر\n");
    exit(1);
}

/* لیست بلوک‌های case از هسته رندرگر واحد (P2-21 — سوییچ در core است) */
$bb = (string)file_get_contents(dirname(__DIR__) . '/templates/brand-core/includes/block-renderer-core.php');
preg_match_all("/case\s+'([a-z0-9\-_]+)'\s*:/", $bb, $mm);
$blocks = array_values(array_filter(array_unique($mm[1]), static fn($b) => $b !== 'pelement'));

/* props استاندارد تست انطباق — هر بلوک عنوان و آیتم نمونه می‌گیرد */
$stdProps = [
    'title' => 'عنوان تست',
    'subtitle' => 'زیرعنوان تست',
    'text' => 'متن تست',
    'items' => [['icon' => '۱', 'text' => 'آیتم تست', 'desc' => 'توضیح تست']],
    'btnText' => 'دکمه تست',
    'phone' => '04135557788',
    'formFields' => [],
    'renderType' => 'cards',
];

define('SAHAND_INIT', true);
define('SAHAND_NO_DB_MIGRATE', true);
define('SAHAND_NO_SESSION', true);

$out = [];
if ($mode === 'preview') {
    require dirname(__DIR__) . '/config.php';
    /* نشست جعلی مدیر (CLI) — رندرگر پیش‌نمایش requireLogin دارد */
    $_SESSION['user_id'] = 1;
    $_SESSION['username'] = 'admin';
    $_SESSION['user_role'] = 'admin';
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    ob_start();
    require dirname(__DIR__) . '/admin/template-preview.php';
    ob_end_clean();
    foreach ($blocks as $b) {
        /* 🆕 v2.40 — رندرگر «کامل» (pv_render_block با پس‌پردازش) نه نسخه خام
           (pv_render_block_inner): سایت از bb_render_block کامل رندر می‌شود؛
           مقایسه خام‌با‌کامل بعد از v2.39 (تزریق زیرعنوان/کلاس‌ها در
           پس‌پردازش) انحراف کاذب ۱۰ بلوک می‌ساخت. حالا هر دو طرف کامل‌اند
           و تفاوت فقط از شاخه‌های واقعی حالت site/preview می‌آید. */
        $out[$b] = @renderPreviewBlock($b, $stdProps);
    }
} else {
    define('BRAND_INIT', true);
    define('BRAND_ID', 1);
    if (!function_exists('fa_num')) { function fa_num(string $v): string { return strtr($v, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']); } }
    if (!function_exists('cdn_asset')) { function cdn_asset(string $p): string { return $p; } }
    if (!function_exists('fetchFromAPI')) { function fetchFromAPI(string $ep, int $t = 300): array { return ['data' => []]; } }
    /* e() در سایت واقعی از config.php قالب (خط ۳۴۴) می‌آید — همان تعریف */
    if (!function_exists('e')) { function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); } }
    require dirname(__DIR__) . '/templates/brand-core/includes/blocks.php';
    foreach ($blocks as $b) {
        $out[$b] = @bb_render_block($b, $stdProps);
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
