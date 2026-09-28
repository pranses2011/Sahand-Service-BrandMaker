<?php
/**
 * 🧪 تست انطباق دو رندرگر — v2.33 (گزارش تحلیل P0-۱۱)
 * =====================================================
 * «اجرای ۱۵۳ بلوک روی هر دو و مقایسه — خروجی متفاوت = خطا»
 *
 * روش (رگرسیون شباهت): هر بلوک در هر دو رندرگر (پیش‌نمایش و سایت برند)
 * با props استاندارد رندر می‌شود؛ شباهت متنی (Jaccard توکن‌های خالی از تگ)
 * محاسبه و با «خط پایه» مقایسه می‌شود. اگر شباهت از خط پایه بیش از ۲٪
 * پایین بیاید = یک رندرگر تغییر کرده و دیگری نه → خطا (جلوگیری خودکار
 * از انحراف آینده — دقیقاً ریشه باگ‌های «پیش‌نمایش با سایت فرق دارد»).
 *
 * --update-baseline → بازنویسی خط پایه (بعد از تغییر عمدی هر دو رندرگر)
 */

$baselineFile = __DIR__ . '/conformance-baseline.json';
$php = PHP_BINARY;

/* ۱) رندر هر دو صحنه در دو زیرپروسه (توابع هم‌نام — ایزوله) */
$T->section('رندر دو صحنه');
$cmd = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/render-all.php') . ' preview 2>/dev/null';
$previewJson = (string)shell_exec($cmd);
$cmd2 = escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/render-all.php') . ' site 2>/dev/null';
$siteJson = (string)shell_exec($cmd2);
$preview = json_decode($previewJson, true);
$site = json_decode($siteJson, true);
$T->assert('رندرگر پیش‌نمایش پاسخ JSON داد', is_array($preview) && $preview !== []);
$T->assert('رندرگر سایت پاسخ JSON داد', is_array($site) && $site !== []);
if (!is_array($preview) || !is_array($site) || !$preview || !$site) {
    return; /* بقیه تست معنا ندارد */
}
$blocks = array_values(array_intersect(array_keys($preview), array_keys($site)));
$blockCount = count($blocks);
$T->assert("هر دو رندرگر {$blockCount} بلوک مشترک دارند", $blockCount > 100);

/* ۲) شباهت متنی هر بلوک */
$T->section('شباهت بلوک‌به‌بلوک');
$similarity = static function (string $a, string $b): float {
    $tokens = static function (string $html): array {
        $text = trim(preg_replace('/\s+/u', '', strip_tags($html)) ?? '');
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        /* n-gram کاراکتری ۴تایی — مقاوم در برابر تفاوت‌های جزئی markup */
        $grams = [];
        $n = 4;
        $len = count($chars);
        for ($i = 0; $i + $n <= $len; $i++) {
            $grams[implode('', array_slice($chars, $i, $n))] = true;
        }
        return array_keys($grams);
    };
    $ta = $tokens($a);
    $tb = $tokens($b);
    if (!$ta || !$tb) { return 0.0; }
    $inter = count(array_intersect($ta, $tb));
    $union = count(array_unique(array_merge($ta, $tb)));
    return $union > 0 ? $inter / $union : 0.0;
};

$current = [];
$emptyBoth = 0;
foreach ($blocks as $b) {
    $pvH = (string)($preview[$b] ?? '');
    $stH = (string)($site[$b] ?? '');
    if (trim($pvH) === '' && trim($stH) === '') { $emptyBoth++; continue; }
    $current[$b] = round($similarity($pvH, $stH), 4);
}
$T->assert("{$emptyBoth} بلوک در هر دو صحنه خالی‌اند (pelement و خاص)", $emptyBoth < 5);

$avg = round(array_sum($current) / max(1, count($current)), 4);
$T->assert("شباهت میانگین {$avg} (انتظار > ۰.۵)", $avg > 0.5);

/* ۳) مقایسه با خط پایه */
$T->section('خط پایه انطباق');
$baseline = is_file($baselineFile) ? (json_decode((string)file_get_contents($baselineFile), true) ?: []) : [];
if (!empty($GLOBALS['updateBaseline'])) {
    ksort($current);
    file_put_contents($baselineFile, json_encode(['generated_at' => date('Y-m-d H:i:s'), 'blocks' => $current], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $T->assert('خط پایه بازنویسی شد (' . count($current) . ' بلوک)', true);
    return;
}
if (!$baseline) {
    $T->assert('خط پایه موجود نیست — یک‌بار با --update-baseline بسازید', false);
    return;
}
$bl = $baseline['blocks'] ?? [];
$T->assert('خط پایه بارگذاری شد (' . count($bl) . ' بلوک)', count($bl) > 100);

$regressions = [];
foreach ($current as $b => $sim) {
    $base = $bl[$b] ?? null;
    if ($base === null) { continue; }
    if ($sim < $base - 0.02) {
        $regressions[] = sprintf('%s: %.2f → %.2f', $b, $base, $sim);
    }
}
$T->assert('صفر رگرسیون شباهت (افت > ۲٪)', count($regressions) === 0);
if ($regressions) {
    echo "  ⚠️ بلوک‌های منحرف:\n";
    foreach (array_slice($regressions, 0, 10) as $r) { echo "     • {$r}\n"; }
    echo "  💡 اگر تغییر عمدی بوده: php tests/run.php --update-baseline\n";
}
$improved = 0;
foreach ($current as $b => $sim) {
    if (isset($bl[$b]) && $sim > $bl[$b] + 0.02) { $improved++; }
}
if ($improved > 0) { echo "  ℹ️ {$improved} بلوک هم‌گراتر از خط پایه شد (عالی — دوباره baseline بگیرید)\n"; }
