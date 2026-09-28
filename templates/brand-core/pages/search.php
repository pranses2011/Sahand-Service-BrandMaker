<?php
/**
 * 🔍 صفحه جستجوی سایت برند (v2.34 — P1 #11)
 * ==========================================
 * فرم جستجو + نتایج رتبه‌بندی‌شده (مقالات + صفحات) با هایلایت عبارت.
 * ایندکس: مقالات منتشرشده + متن صفحات برند از طریق اندپوینت search API.
 * آدرس تمیز: /search?q=... (rewrite در htaccess.template)
 *
 * @package SahandBrandSite
 */
define('BRAND_INIT', true);
require_once __DIR__ . '/../config.php';

$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$results = null;
$meta = null;

if ($q !== '') {
    $data = fetchFromAPI('brand/' . BRAND_ID . '/search?q=' . urlencode($q) . '&page=' . $page . '&per_page=10', 60);
    $results = $data['data'] ?? null;
    $meta = $data['meta'] ?? null;
}

$pageTitle = $q !== '' ? 'جستجو: ' . $q . ' | ' . BRAND_NAME_FA : 'جستجو | ' . BRAND_NAME_FA;
$pageDesc = 'جستجو در مقالات و صفحات ' . BRAND_NAME_FA;
$crumbTitle = 'جستجو';
$pageNoIndex = true; // 🛡️ صفحات نتیجه جستجو = محتوای کم‌ارزش برای گوگل (جلوگیری از ایندکس صفحات بی‌پایان q=?)
require __DIR__ . '/_page_base.php';

/** ✨ هایلایت توکن‌های جستجو در متن نتیجه (خروجی امن — متن از e() رد شده) */
function search_highlight(string $text, array $tokens): string
{
    $safe = e($text);
    if ($tokens === []) {
        return $safe;
    }
    $patterns = [];
    foreach ($tokens as $t) {
        $t = trim((string)$t);
        if (mb_strlen($t) >= 2) {
            $patterns[] = '(' . preg_quote(e($t), '/') . ')';
        }
    }
    if ($patterns === []) {
        return $safe;
    }
    // ارقام فارسی/عربی هم معادل لاتین هایلایت شوند
    return preg_replace('/' . implode('|', $patterns) . '/u', '<mark>$0</mark>', $safe) ?? $safe;
}
?>
<section class="section">
    <div class="container">
        <div class="search-head" style="text-align:center;margin-bottom:28px">
            <h1 style="font-size:26px;margin-bottom:6px">🔍 جستجو در <?= e(BRAND_NAME_FA) ?></h1>
            <p style="color:var(--text-light,#64748b);font-size:14px">مقالات، خدمات و صفحات سایت را جستجو کنید</p>
            <form method="get" action="/search" class="search-form" style="max-width:560px;margin:20px auto 0" role="search">
                <div style="display:flex;gap:10px">
                    <input type="search" name="q" value="<?= e($q) ?>" required minlength="2" maxlength="80"
                           placeholder="مثلاً: تعمیر لباسشویی، کد خطا E24، گارانتی…"
                           aria-label="عبارت جستجو"
                           style="flex:1;padding:14px 18px;border:1.5px solid var(--border,#e2e8f0);border-radius:14px;font-family:inherit;font-size:15px;background:var(--card,#fff);color:var(--text,#0f172a);outline:none"
                           onfocus="this.style.borderColor='#3b82f6'" onblur="this.style.borderColor='var(--border,#e2e8f0)'">
                    <button type="submit" class="btn btn-primary" style="padding:14px 24px;border-radius:14px;white-space:nowrap">جستجو</button>
                </div>
            </form>
        </div>

        <?php if ($q !== '' && $results !== null): ?>
            <?php if ($meta['hint'] ?? ''): ?>
                <div style="text-align:center;padding:30px 16px;color:var(--text-light,#64748b)">
                    <div style="font-size:44px;margin-bottom:10px">✍️</div>
                    <?= e($meta['hint']) ?>
                </div>
            <?php elseif ($results === []): ?>
                <div style="text-align:center;padding:34px 16px;color:var(--text-light,#64748b)">
                    <div style="font-size:48px;margin-bottom:12px">🔎</div>
                    <h2 style="font-size:18px;color:var(--text,#0f172a)">نتیجه‌ای برای «<?= e($q) ?>» پیدا نشد</h2>
                    <p style="font-size:13.5px;line-height:2;margin-top:8px">
                        پیشنهادها: کلمه کوتاه‌تری امتحان کنید · املای عبارت را بررسی کنید ·
                        <a href="/blog" style="color:#2563eb">همه مقالات را ببینید</a> ·
                        <a href="/request" style="color:#2563eb">ثبت درخواست تعمیر</a>
                    </p>
                </div>
            <?php else: ?>
                <div style="margin:0 0 18px;font-size:13.5px;color:var(--text-light,#64748b)">
                    <?= e(fa_num((string)($meta['total'] ?? count($results)))) ?> نتیجه برای
                    «<b style="color:var(--text,#0f172a)"><?= e($q) ?></b>»
                    <?php if ((int)($meta['pages'] ?? 1) > 1): ?>
                        — صفحه <?= e(fa_num((string)($meta['page'] ?? 1))) ?> از <?= e(fa_num((string)$meta['pages'])) ?>
                    <?php endif; ?>
                </div>
                <div class="search-results">
                    <?php foreach ($results as $r): ?>
                        <a href="<?= e($r['url']) ?>" class="search-result-card" style="display:block;text-decoration:none;background:var(--card,#fff);border:1px solid var(--border,#e2e8f0);border-radius:16px;padding:18px 20px;margin-bottom:12px;transition:border-color .18s,box-shadow .18s" onmouseover="this.style.borderColor='#3b82f6';this.style.boxShadow='0 5px 18px rgba(59,130,246,.12)'" onmouseout="this.style.borderColor='var(--border,#e2e8f0)';this.style.boxShadow='none'">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:7px">
                                <span style="font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;background:<?= $r['type'] === 'article' ? '#eff6ff;color:#1d4ed8' : '#f0fdf4;color:#166534' ?>">
                                    <?= $r['type'] === 'article' ? '📰 مقاله' : '📄 صفحه' ?>
                                </span>
                                <?php if (!empty($r['date'])): ?>
                                    <span style="font-size:11.5px;color:var(--text-light,#64748b)">📅 <?= e(fa_num(date('Y/m/d', strtotime((string)$r['date'])))) ?></span>
                                <?php endif; ?>
                            </div>
                            <h2 style="font-size:16.5px;margin:0 0 6px;color:var(--text,#0f172a)"><?= search_highlight((string)$r['title'], $meta['tokens'] ?? []) ?></h2>
                            <p style="font-size:13px;line-height:2;color:var(--text-light,#64748b);margin:0">
                                <?= search_highlight((string)$r['excerpt'], $meta['tokens'] ?? []) ?>
                            </p>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php
                $pages = (int)($meta['pages'] ?? 1);
                $cur = (int)($meta['page'] ?? 1);
                if ($pages > 1):
                    ?>
                    <nav class="search-pagination" style="display:flex;gap:8px;justify-content:center;margin-top:26px;flex-wrap:wrap" aria-label="صفحه‌بندی نتایج">
                        <?php if ($cur > 1): ?>
                            <a href="/search?q=<?= e(urlencode($q)) ?>&page=<?= $cur - 1 ?>" style="padding:9px 16px;border-radius:10px;border:1px solid var(--border,#e2e8f0);text-decoration:none;color:var(--text,#0f172a);font-size:13px">قبلی</a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= min($pages, 9); $i++): ?>
                            <a href="/search?q=<?= e(urlencode($q)) ?>&page=<?= $i ?>" style="padding:9px 15px;border-radius:10px;<?= $i === $cur ? 'background:#1d4ed8;color:#fff;border-color:#1d4ed8' : 'border:1px solid var(--border,#e2e8f0);color:var(--text,#0f172a)' ?>;text-decoration:none;font-size:13px;font-weight:<?= $i === $cur ? '800' : '500' ?>"><?= e(fa_num((string)$i)) ?></a>
                        <?php endfor; ?>
                        <?php if ($cur < $pages): ?>
                            <a href="/search?q=<?= e(urlencode($q)) ?>&page=<?= $cur + 1 ?>" style="padding:9px 16px;border-radius:10px;border:1px solid var(--border,#e2e8f0);text-decoration:none;color:var(--text,#0f172a);font-size:13px">بعدی</a>
                        <?php endif; ?>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        <?php elseif ($q === ''): ?>
            <div style="text-align:center;padding:26px 16px;color:var(--text-light,#64748b);font-size:13.5px;line-height:2.2">
                💡 نکته: می‌توانید نام دستگاه (لباسشویی، یخچال…)، کد خطا (E24، F21…) یا موضوع (گارانتی، هزینه سرویس) را جستجو کنید.
            </div>
        <?php endif; ?>
    </div>
</section>
<style>
mark { background: #fde047; color: inherit; border-radius: 4px; padding: 0 3px; }
[data-theme="dark"] mark { background: rgba(253, 224, 71, .3); color: #fde047; }
</style>
<?php require __DIR__ . '/../includes/floating-btn.php'; require __DIR__ . '/../includes/footer.php'; ?>
