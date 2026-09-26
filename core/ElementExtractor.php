<?php
/**
 * 🌐 استخراج‌گر عناصر از سایت خارجی (v2.26)
 * ===========================================
 * آدرس یک سایت می‌گیرد، HTML و CSSهای آن را واکشی می‌کند و عناصر
 * رابط کاربری (دکمه/کارت/هدر/فرم/...) را همراه با استایل «تخت‌شده»
 * (flattened) برمی‌گرداند تا در قالب‌ساز پیش‌نمایش و ذخیره شوند.
 *
 * 🛡️ امنیت:
 *   - فقط http/https + ممنوعیت آدرس‌های داخلی (SSRF)
 *   - حذف <script> / رویدادهای on* / javascript: از خروجی
 *   - سقف حجم (۲MB) و مهلت (۱۵ ثانیه)
 *
 * @package SahandBrandMaker\Core
 * @version 1.0.0
 */

class ElementExtractor
{
    /** @var int حداکثر طول واکشی HTML صفحه */
    const MAX_HTML = 2 * 1024 * 1024;
    /** @var int حداکثر طول هر فایل CSS */
    const MAX_CSS = 512 * 1024;
    /** @var int حداکثر تعداد استایل‌شیت‌های خارجی که واکشی می‌شوند */
    const MAX_SHEETS = 6;
    /** @var int حداکثر تعداد عناصر برگردانده‌شده */
    const MAX_ELEMENTS = 60;
    /** @var int مهلت هر درخواست HTTP (ثانیه) */
    const TIMEOUT = 15;

    /* ==================================================
     * 🌐 ورودی عمومی
     * ================================================== */

    /**
     * 🎯 استخراج عناصر از یک آدرس
     *
     * @param string $url آدرس کامل http(s)
     * @return array ['success'=>bool, 'error'=>?, 'url'=>?, 'title'=>?, 'count'=>?, 'elements'=>[...]]
     */
    public function extract(string $url): array
    {
        /* ① اعتبارسنجی + محافظت SSRF */
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return ['success' => false, 'error' => 'آدرس باید با http:// یا https:// شروع شود'];
        }
        $host = (string)parse_url($url, PHP_URL_HOST);
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) && $this->isPrivateIp($host)) {
            return ['success' => false, 'error' => 'آدرس میزبان نامعتبر است'];
        }
        /* نام میزبان‌های ممنوع */
        $blocked = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'];
        if (in_array(strtolower($host), $blocked, true) || preg_match('/\.local$/i', $host) || preg_match('/\.internal$/i', $host)) {
            return ['success' => false, 'error' => 'آدرس‌های داخلی مجاز نیستند'];
        }
        /* رزولو DNS + بررسی IP خصوصی (SSRF) */
        $ips = @dns_get_record($host, DNS_A);
        if (is_array($ips)) {
            foreach ($ips as $r) {
                if (!empty($r['ip']) && $this->isPrivateIp($r['ip'])) {
                    return ['success' => false, 'error' => 'آدرس‌های داخلی مجاز نیستند'];
                }
            }
        }

        /* ② واکشی HTML */
        $html = $this->httpGet($url);
        if ($html === null) {
            return ['success' => false, 'error' => 'دانلود صفحه ناموفق بود — آدرس را بررسی کنید (سایت ممکن است دسترسی ربات‌ها را محدود کرده باشد)'];
        }

        /* ③ پارس DOM */
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $utf8 = '<?xml encoding="utf-8"?>' . $html;
        $ok = $doc->loadHTML($utf8, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        if (!$ok || $doc->documentElement === null) {
            return ['success' => false, 'error' => 'تحلیل ساختار صفحه ناموفق بود'];
        }

        /* ④ جمع‌آوری CSS (داخلی + خارجی هم‌مبدأ) */
        $cssText = $this->collectCss($doc, $url);
        $rules = $this->parseCss($cssText);

        /* ⑤ کشف عناصر بر اساس نقش */
        $elements = $this->discover($doc, $rules, $url);

        $title = '';
        $titleNodes = $doc->getElementsByTagName('title');
        if ($titleNodes->length > 0) { $title = trim($titleNodes->item(0)->textContent); }

        return [
            'success'  => true,
            'url'      => $url,
            'title'    => mb_substr($title, 0, 190),
            'count'    => count($elements),
            'elements' => $elements,
        ];
    }

    /**
     * 🧹 HTML عنصر را برای ذخیره/پیش‌نمایش ایمن می‌کند
     * (استاتیک تا هنگام «ذخیره عنصر شخصی» هم قابل استفاده باشد)
     */
    public static function sanitize(string $html): string
    {
        if ($html === '') { return ''; }
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $ok = $doc->loadHTML('<?xml encoding="utf-8"?><div id="__root">' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        if (!$ok) { return ''; }
        $root = $doc->getElementById('__root');

        /* حذف تگ‌های خطرناک */
        $dangerous = ['script', 'iframe', 'object', 'embed', 'link', 'meta', 'base', 'form'];
        foreach ($dangerous as $tag) {
            $nodes = iterator_to_array($doc->getElementsByTagName($tag));
            foreach ($nodes as $n) {
                /* فرم فقط shell خالی می‌شود (inputها می‌مانند) */
                if ($tag === 'form' && $n->parentNode) {
                    $frag = $doc->createDocumentFragment();
                    while ($n->firstChild) { $frag->appendChild($n->firstChild); }
                    $n->parentNode->replaceChild($frag, $n);
                } elseif ($n->parentNode) {
                    $n->parentNode->removeChild($n);
                }
            }
        }

        /* پاک‌سازی خصیصه‌ها روی همه گره‌ها */
        $walker = new DOMXPath($doc);
        if ($root) {
            $all = $walker->query('.//*', $root);
            if ($all) {
                foreach ($all as $el) {
                    /** @var DOMElement $el */
                    foreach (iterator_to_array($el->attributes) as $attr) {
                        $name = strtolower($attr->name);
                        $val = trim($attr->value);
                        if (str_starts_with($name, 'on')) { $el->removeAttribute($attr->name); continue; }
                        if (in_array($name, ['src', 'href', 'style'], true)) { continue; }
                        if (in_array($name, ['srcset', 'action', 'formaction', 'data', 'poster', 'background'], true)) { $el->removeAttribute($attr->name); continue; }
                        if ($name === 'srcdoc') { $el->removeAttribute($attr->name); continue; }
                    }
                    /* javascript: در href */
                    $href = trim((string)$el->getAttribute('href'));
                    if (preg_match('#^\s*(javascript|vbscript)\s*:#i', $href) || preg_match('#^\s*data\s*:\s*(?!image/)#i', $href)) { $el->removeAttribute('href'); }
                }
            }
            $out = '';
            foreach (iterator_to_array($root->childNodes) as $child) {
                $out .= $doc->saveHTML($child);
            }
            return $out;
        }
        return '';
    }

    /**
     * 🖼 سند مستقل پیش‌نمایش عنصر (iframe srcdoc)
     *
     * 🆕 v2.27: پارامتر css اکنون «قوانین کامل با انتخابگر» است (نه اعلان
     * خام) — مستقیم داخل <style> می‌رود و فرزندان هم استایل می‌گیرند.
     */
    public static function previewDocument(string $html, string $css, string $bg = '#ffffff'): string
    {
        $safeCss = preg_replace('#</#i', '\\/', (string)$css); /* ضد خروج از style */
        return '<!doctype html><html dir="rtl" lang="fa"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<style>*{box-sizing:border-box}body{margin:0;padding:18px;background:' . $bg . ';font-family:Vazirmatn,Tahoma,sans-serif}'
            . 'img{max-width:100%;height:auto}a{text-decoration:none}'
            . $safeCss . '</style></head><body>' . $html . '</body></html>';
    }

    /* ==================================================
     * 🔎 کشف عناصر
     * ================================================== */

    /**
     * نقش‌ها و الگوهای کشف — [نقش، XPath، برچسب فارسی، آیکون]
     */
    private function roles(): array
    {
        return [
            ['button',  '//*[self::button or (self::a and (contains(translate(@class,"BUTTON","button"),"btn") or contains(translate(@class,"BUTTON","button"),"button") or @role="button"))] | //input[@type="submit" or @type="button"]', 'دکمه', '🔘'],
            ['badge',   '//*[contains(translate(@class,"BADGE","badge"),"badge") or contains(translate(@class,"TAGPIL","tagpil"),"tag") or contains(translate(@class,"CHIP","chip"),"chip") or contains(translate(@class,"PILL","pill"),"pill")]', 'نشان / برچسب', '🏷️'],
            ['card',    '//*[contains(translate(@class,"CARD","card"),"card") or contains(translate(@class,"PRODUCT","product"),"product") or contains(translate(@class,"POST","post"),"post") or contains(translate(@class,"ITEMBOX","itembox"),"item")]', 'کارت', '🗂'],
            ['nav',     '//nav | //header//ul[li[a]]', 'منوی ناوبری', '🧭'],
            ['header',  '//header', 'هدر', '🔝'],
            ['footer',  '//footer', 'فوتر', '🔚'],
            ['alert',   '//*[contains(translate(@class,"ALERT","alert"),"alert") or contains(translate(@class,"NOTICE","notice"),"notice") or contains(translate(@class,"WARNING","warning"),"warning") or contains(translate(@class,"MESSAGE","message"),"message")]', 'هشدار / اطلاعیه', '⚠️'],
            ['quote',   '//blockquote', 'نقل‌قول', '❝'],
            ['input',   '//input[@type!="hidden" and @type!="submit" and @type!="button"] | //select | //textarea', 'فیلد ورودی', '⌨️'],
            ['list',    '//ul[li] | //ol[li]', 'لیست', '📋'],
            ['heading', '//h1 | //h2 | //h3', 'تیتر', '🅷'],
            ['image',   '//img[@src]', 'تصویر', '🖼️'],
        ];
    }

    private function discover(DOMDocument $doc, array $rules, string $baseUrl): array
    {
        $xp = new DOMXPath($doc);
        $out = [];
        $seen = [];
        $perRole = ['button' => 10, 'badge' => 6, 'card' => 10, 'nav' => 2, 'header' => 2, 'footer' => 2, 'alert' => 4, 'quote' => 3, 'input' => 6, 'list' => 4, 'heading' => 6, 'image' => 6];
        $counts = array_fill_keys(array_keys($perRole), 0);

        foreach ($this->roles() as [$role, $xpath, $label, $icon]) {
            if ($counts[$role] >= $perRole[$role]) { continue; }
            $nodes = @$xp->query($xpath);
            if (!$nodes) { continue; }
            foreach ($nodes as $node) {
                if ($counts[$role] >= $perRole[$role] || count($out) >= self::MAX_ELEMENTS) { break 2; }
                /** @var DOMElement $node */
                if (!($node instanceof DOMElement)) { continue; }

                /* 🔑 شناسه یکتای گره — getNodePath (نه spl_object_id که
                   با آزادشدن wrapperهای DOM ممکن است handle بازاستفاده شود) */
                $nodePath = $node->getNodePath();
                if ($nodePath === null) { continue; }

                /* فیلتر کیفیت: عنصر باید متن یا تصویر داشته باشد */
                $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
                $hasImg = $node->getElementsByTagName('img')->length > 0;
                if ($text === '' && !$hasImg && !in_array($role, ['image', 'input'], true)) { continue; }
                /* خیلی بزرگ (کل صفحه) یا خیلی کوچک */
                $len = strlen($node->C14N());
                if ($len > 60000) { continue; }
                if ($len < 12 && $role !== 'input') { continue; }
                /* تکرار با پدر هم‌نقش — اگر خودِ عنصر قبلاً با نقش دیگری برداشت شده */
                if (isset($seen[$nodePath])) { continue; }
                /* پدرِ قبلاً برداشته‌شده → عنصر داخلی همان محتواست؛ فقط برای نقش‌های
                   تودرتوی پرتکرار رد می‌شود (card/list/nav/button/badge/heading) */
                $skip = false;
                for ($p = $node->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
                    $pp = $p->getNodePath();
                    if ($pp !== null && isset($seen[$pp])) { $skip = true; break; }
                }
                if ($skip && in_array($role, ['card', 'list', 'nav', 'button', 'badge', 'heading'], true)) { continue; }
                $seen[$nodePath] = true;

                /* HTML خام + مطلق‌سازی آدرس‌ها */
                $raw = $node->C14N();
                $raw = $this->absolutize($raw, $baseUrl);

                /* نام = نقش + بریده متن */
                $name = $label . ' — ' . mb_substr($text !== '' ? $text : ($hasImg ? 'تصویر' : 'بدون متن'), 0, 40);

                /* 🎨 v2.27 — ریشه «پیش‌نمایش فقط متن نشان می‌دهد» (سه ایراد همزمان):
                   ① استایل «تخت‌شده» بدون انتخابگر داخل <style> می‌رفت = CSS نامعتبر
                   که مرورگر نادیده می‌گرفت → عنصر کاملاً بی‌استایل رندر می‌شد.
                   ② فقط استایل خودِ عنصر ریشه جمع می‌شد؛ فرزندان (متن/آیکون/…)
                   هیچ استایلی نمی‌گرفتند.
                   ③ absolutize کالبک $fix را هرگز صدا نمی‌زد → تصاویر نسبی 404.
                   اکنون: css = قوانین معتبر زیردرخت (انتخابگر + اعلان، همان
                   ترتیب آبشار سایت مبدأ) + استایل تخت ریشه به‌صورت style درون‌خطی
                   روی خود عنصر تزریق می‌شود → پیش‌نمایش/ذخیره/استفاده مجدد همه
                   «دقیقاً مثل سایت مبدأ» اند. */
                $flat = $this->matchedCss($node, $rules);
                $out[] = [
                    'type'   => $role,
                    'label'  => $label,
                    'icon'   => $icon,
                    'name'   => $name,
                    'text'   => mb_substr($text, 0, 90),
                    'html'   => self::sanitize($this->injectRootStyle($raw, $flat)),
                    'css'    => $this->subtreeCss($node, $rules),
                    'size'   => $len,
                ];
                $counts[$role]++;
            }
        }
        return $out;
    }

    /**
     * 🔗 مطلق‌سازی آدرس‌های نسبی در HTML عنصر (src/href + url() در استایل)
     *
     * 🚨 v2.27 — باگ تاریخی: کالبک $fix تعریف می‌شد اما هرگز فراخوانی نمی‌شد
     * (خروجی کالبک بیرونی همان ورودی بود!) → همه آدرس‌های نسبی، نسبی می‌ماندند
     * → تصاویر/فونت‌های عنصر در پیش‌نمایش 404 و بی‌استایل دیده می‌شدند.
     */
    private function absolutize(string $html, string $baseUrl): string
    {
        $base = rtrim(preg_replace('#^(https?://[^/]+).*#i', '$1', $baseUrl), '/');
        $dir = rtrim(preg_replace('#/[^/]*$#', '', $baseUrl), '/');

        /* ① خصیصه‌های آدرس‌دار */
        $html = preg_replace_callback('#(src|href|data-src|poster)\s*=\s*(["\'])([^"\']*)\2#i', static function (array $m) use ($base, $dir): string {
            $v = trim($m[3]);
            if ($v === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $v) || $v[0] === '#' || stripos($v, 'data:') === 0) {
                return $m[0];
            }
            $abs = $v[0] === '/' ? ($base . $v) : self::normalizeUrlPath($dir . '/' . $v);
            return $m[1] . '=' . $m[2] . $abs . $m[2];
        }, $html) ?? $html;

        /* ② url(...) در استایل درون‌خطی (پس‌زمینه/فونت و ...) */
        return preg_replace_callback('#url\(\s*(["\']?)([^"\)\']+)\1\s*\)#i', static function (array $m) use ($base, $dir): string {
            $v = trim($m[2]);
            if ($v === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $v) || $v[0] === '#') {
                return $m[0];
            }
            $abs = $v[0] === '/' ? ($base . $v) : self::normalizeUrlPath($dir . '/' . $v);
            return 'url(' . $m[1] . $abs . $m[1] . ')';
        }, $html) ?? $html;
    }

    /**
     * 🧭 v2.27 — resolve واقعی segment های ./ و ../ در مسیر URL
     * (css/../img/x.png → img/x.png — مرورگر این را می‌فهمد ولی
     * ذخیره/استفاده مجدد باید آدرس تمیز قطعی داشته باشد)
     */
    private static function normalizeUrlPath(string $url): string
    {
        /* فقط بخش مسیر را resolve می‌کنیم — scheme/host دست‌نخورده */
        if (!preg_match('#^(https?://[^/]+)(/.*)$#i', $url, $mu)) {
            return $url;
        }
        $origin = $mu[1];
        $segs = explode('/', ltrim($mu[2], '/'));
        $out = [];
        foreach ($segs as $s) {
            if ($s === '.') { continue; }
            if ($s === '..') { array_pop($out); continue; }
            $out[] = $s;
        }
        return $origin . '/' . implode('/', $out);
    }

    /**
     * 🎨 v2.27 — CSS کامل زیردرخت عنصر (قوانین معتبر + همان ترتیب منبع)
     * ================================================================
     * برای هر قانون CSS سایت مبدأ بررسی می‌شود آیا «راست‌ترین ترکیبِ» هر
     * بخش انتخابگر با کلاس/آیدی/تگ یکی از گره‌های زیردرخت تطبیق دارد؛
     * بخش‌های مطابق با انتخابگر خودشان (نه اعلان خام) برمی‌گردند تا در
     * iframe ایزوله دقیقاً همان آبشار سایت مبدأ اجرا شود — فرزندان هم
     * استایل می‌گیرند، نه فقط عنصر ریشه.
     *
     * ابرمجموعه‌ی امن است: قانونی که به‌اشتباه وارد شود در iframe بی‌اثر
     * می‌ماند (چون انتخابگرش دقیق است) اما هیچ قانونِ لازمِ حذف نمی‌شود.
     */
    private function subtreeCss(DOMElement $node, array $rules): string
    {
        if (empty($rules)) {
            return '';
        }
        /* ① امضای زیردرخت: همه کلاس‌ها/آیدی‌ها/تگ‌ها (سقف ۶۰۰ گره) */
        $classes = [];
        $ids = [];
        $tags = [];
        $stack = [$node];
        $seen = 0;
        while ($stack) {
            /** @var DOMElement $el */
            $el = array_pop($stack);
            if (++$seen > 600) {
                break;
            }
            $tags[strtolower($el->nodeName)] = true;
            $id = trim((string)$el->getAttribute('id'));
            if ($id !== '') {
                $ids[$id] = true;
            }
            foreach (preg_split('/\s+/', trim((string)$el->getAttribute('class')) ?: '', -1, PREG_SPLIT_NO_EMPTY) as $c) {
                $classes[$c] = true;
            }
            foreach ($el->childNodes as $ch) {
                if ($ch instanceof DOMElement) {
                    $stack[] = $ch;
                }
            }
        }

        /* ② پالایش قوانین — فقط بخش‌هایی که زیردرخت را لمس می‌کنند */
        $out = '';
        $scanned = 0;
        foreach ($rules as [$sel, $decl]) {
            if (++$scanned > 9000 || strlen($out) > 54000) {
                break; /* سقف کار/حجم (ستون css در DB تا ۶۴KB) */
            }
            foreach (array_map('trim', explode(',', $sel)) as $part) {
                if ($part === '' || $part === '@') {
                    continue;
                }
                if ($this->partTouchesSubtree($part, $classes, $ids, $tags)) {
                    $out .= $part . '{' . $decl . '}';
                    break; /* این قانون وارد شد — بخش بعدی اگر مطابق باشد خودش می‌آید */
                }
            }
        }
        return $out;
    }

    /**
     * 🔎 آیا این بخش انتخابگر، زیردرخت را لمس می‌کند؟
     * راست‌ترین ترکیب (بعد از آخرین جداکننده) بررسی می‌شود:
     * .class / #id / tag یا * باید با امضای زیردرخت تطبیق کند.
     */
    private function partTouchesSubtree(string $part, array $classes, array $ids, array $tags): bool
    {
        /* راست‌ترین ترکیب: بعد از آخرین فاصله/>/+/~ */
        $rightmost = trim(preg_replace('#[\s>+~][^\s>+~]*$#', '', $part) ?? $part);
        if ($rightmost === '') {
            $rightmost = trim($part);
        }
        if ($rightmost === '' || $rightmost === '*') {
            return $rightmost === '*';
        }
        $touched = false;
        /* کلاس‌های ترکیب */
        if (preg_match_all('/\.([\w-]+)/', $rightmost, $cm)) {
            foreach ($cm[1] as $c) {
                if (isset($classes[$c])) {
                    $touched = true;
                    break;
                }
            }
            if ($touched) {
                return true;
            }
        }
        /* آیدی */
        if (preg_match('/#([\w-]+)/', $rightmost, $im) && isset($ids[$im[1]])) {
            return true;
        }
        /* تگِ ابتدای ترکیب */
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9-]*/', $rightmost, $tm)) {
            return isset($tags[strtolower($tm[0])]);
        }
        return false; /* ترکیب صرفاً pseudo/attr — نادیده */
    }

    /**
     * 💉 v2.27 — تزریق استایل تخت‌شدهٔ ریشه به‌صورت style درون‌خطی
     * (روی اولین عنصر HTML قطعه؛ اگر متن/کامنت جلوتر باشد رد می‌شود)
     */
    private function injectRootStyle(string $html, string $flat): string
    {
        if ($flat === '' || trim($html) === '') {
            return $html;
        }
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $ok = $doc->loadHTML('<?xml encoding="utf-8"?><div id="__rs">' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        if (!$ok) {
            return $html;
        }
        $root = $doc->getElementById('__rs');
        if ($root === null) {
            return $html;
        }
        foreach (iterator_to_array($root->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $cur = trim((string)$child->getAttribute('style'));
                /* semicolon تکراری نگذار (flat ممکن است خودش ; داشته باشد) */
                $flatNorm = rtrim($flat, "; 	");
                $child->setAttribute('style', $flatNorm . ($cur !== '' ? ';' . $cur : ''));
                break;
            }
        }
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    /* ==================================================
     * 🎨 CSS — جمع‌آوری + پارس + تطبیق
     * ================================================== */

    private function collectCss(DOMDocument $doc, string $baseUrl): string
    {
        $css = '';
        /* استایل‌های درون‌صفحه‌ای — آدرس‌های url() نسبت به خود صفحه مطلق می‌شوند */
        foreach (iterator_to_array($doc->getElementsByTagName('style')) as $style) {
            $css .= "\n" . $this->absolutizeCssUrls((string)$style->textContent, $baseUrl);
        }
        /* استایل‌شیت‌های خارجی هم‌مبدأ */
        $baseHost = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
        $fetched = 0;
        foreach (iterator_to_array($doc->getElementsByTagName('link')) as $link) {
            if ($fetched >= self::MAX_SHEETS) { break; }
            /** @var DOMElement $link */
            if (strtolower($link->getAttribute('rel')) !== 'stylesheet') { continue; }
            $href = trim($link->getAttribute('href'));
            if ($href === '' || str_starts_with($href, 'data:')) { continue; }
            if (!preg_match('#^https?://#i', $href)) {
                $host = strtolower((string)parse_url($href, PHP_URL_HOST));
                if ($host !== '' && $host !== $baseHost) { continue; }
            }
            $abs = $this->resolveUrl($href, $baseUrl);
            if ($abs === null) { continue; }
            if (strtolower((string)parse_url($abs, PHP_URL_HOST)) !== $baseHost) { continue; } /* فقط هم‌مبدأ */
            $sheet = $this->httpGet($abs);
            if ($sheet !== null) {
                /* 🎨 v2.27: url() داخل شیت نسبت به «آدرس خود شیت» مطلق می‌شود
                   (قبلاً نسبی می‌ماند → فونت/پس‌زمینه در پیش‌نمایش 404) */
                $css .= "\n" . $this->absolutizeCssUrls($sheet, $abs);
                $fetched++;
            }
        }
        return $css;
    }

    /**
     * 🔗 مطلق‌سازی url() های یک قطعه CSS نسبت به آدرس پایه (صفحه یا شیت)
     */
    private function absolutizeCssUrls(string $css, string $baseHref): string
    {
        if (strpos($css, 'url(') === false) {
            return $css;
        }
        $origin = rtrim(preg_replace('#^(https?://[^/]+).*#i', '$1', $baseHref), '/');
        $dir = rtrim(preg_replace('#/[^/]*$#', '', $baseHref), '/');
        return preg_replace_callback('#url\(\s*(["\']?)([^"\)\']+)\1\s*\)#i', static function (array $m) use ($origin, $dir): string {
            $v = trim($m[2]);
            if ($v === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $v) || $v[0] === '#') {
                return $m[0];
            }
            $abs = $v[0] === '/' ? ($origin . $v) : self::normalizeUrlPath($dir . '/' . $v);
            return 'url(' . $m[1] . $abs . $m[1] . ')';
        }, $css) ?? $css;
    }

    /**
     * 🧩 پارس CSS ساده — خروجی: [['selector', 'declarations'], ...] به ترتیب منبع
     * (بدون @media wrapper — قوانین داخلی آن باز می‌شوند)
     */
    private function parseCss(string $css): array
    {
        /* حذف کامنت‌ها */
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        /* باز کردن @media — قوانین داخلی نگه داشته می‌شوند */
        $css = preg_replace_callback('#@media[^{]*\{((?:[^{}]*\{[^{}]*\})*)\s*\}#s', static function ($m) {
            return $m[1];
        }, $css);
        /* حذف بلوک‌های نامربوط */
        $css = preg_replace('#@(font-face|keyframes|supports|import|charset)[^{;]*(\{[^{}]*\}|;)#s', '', $css);

        $rules = [];
        if (!preg_match_all('#([^{}]+)\{([^{}]*)\}#', $css, $ms, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($ms as $m) {
            $sel = trim($m[1]);
            $decl = trim($m[2]);
            if ($sel === '' || $decl === '' || str_starts_with($sel, '@')) { continue; }
            /* فقط انتخابگرهای ساده قابل تطبیق — پیچیده‌ها (pseudo-element) حذف */
            if (strpos($sel, '{') !== false) { continue; }
            $rules[] = [$sel, $decl];
        }
        return $rules;
    }

    /**
     * 🎯 استایل تخت‌شده عنصر — تطبیق قوانین + style درون‌خطی
     */
    private function matchedCss(DOMElement $node, array $rules): string
    {
        $matched = [];
        foreach ($rules as $idx => [$sel, $decl]) {
            foreach (array_map('trim', explode(',', $sel)) as $part) {
                if ($part === '') { continue; }
                $spec = $this->selectorMatches($node, $part);
                if ($spec !== null) {
                    $matched[] = [$spec, $idx, $decl];
                }
            }
        }
        /* مرتب‌سازی با اختصاصی‌بودن (شاخص: تعداد id×100 + class×10 + tag) سپس ترتیب منبع */
        usort($matched, static function ($a, $b) {
            return [$a[0], $a[1]] <=> [$b[0], $b[1]];
        });

        /* ادغام اعلان‌ها به ترتیب — دومی برنده */
        $final = [];
        foreach ($matched as [, , $decl]) {
            foreach (array_filter(array_map('trim', explode(';', $decl))) as $prop) {
                $colon = strpos($prop, ':');
                if ($colon === false) { continue; }
                $key = strtolower(trim(substr($prop, 0, $colon)));
                $val = trim(substr($prop, $colon + 1));
                if ($key !== '' && $val !== '') { $final[$key] = $val; }
            }
        }
        /* style درون‌خطی هم‌یشه برنده */
        $inline = trim((string)$node->getAttribute('style'));
        if ($inline !== '') {
            foreach (array_filter(array_map('trim', explode(';', $inline))) as $prop) {
                $colon = strpos($prop, ':');
                if ($colon === false) { continue; }
                $final[strtolower(trim(substr($prop, 0, $colon)))] = trim(substr($prop, $colon + 1));
            }
        }
        $out = '';
        foreach ($final as $k => $v) { $out .= $k . ':' . $v . ';'; }
        return $out;
    }

    /**
     * تطبیق یک انتخابگر (تک‌بخشی یا تودرتو با فاصله) با عنصر
     * خروجی: شاخص اختصاصی‌بودن یا null
     */
    private function selectorMatches(DOMElement $node, string $selector): ?int
    {
        $parts = preg_split('#\s+#', trim($selector));
        if (!$parts) { return null; }
        $spec = 0;
        $current = $node;
        /* از راست به چپ */
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $part = $parts[$i];
            $found = false;
            /* پیمایش از current به بالا تا تطبیق این بخش */
            for ($el = $current; $el instanceof DOMElement; $el = $el->parentNode) {
                $s = $this->compoundMatches($el, $part);
                if ($s !== null) {
                    $spec += $s;
                    $current = $el->parentNode;
                    $found = true;
                    break;
                }
            }
            if (!$found) { return null; }
        }
        return $spec;
    }

    /**
     * تطبیق بخش ترکیبی: tag.class#id (بدون > یا +)
     */
    private function compoundMatches(DOMElement $el, string $part): ?int
    {
        if ($part === '' || $part === '*' || str_contains($part, '>') || str_contains($part, '+') || str_contains($part, '~') || str_contains($part, '[') || str_contains($part, ':')) {
            /* انتخابگرهای پیشرفته: فقط * پشتیبانی می‌شود */
            return $part === '*' ? 0 : null;
        }
        $spec = 0;
        $rest = $part;
        /* تگ */
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9-]*#', $rest, $tm)) {
            if (strtolower($tm[0]) !== strtolower($el->nodeName)) { return null; }
            $rest = substr($rest, strlen($tm[0]));
            $spec += 1;
        }
        /* #id */
        if (preg_match('/^#([\w-]+)/', $rest, $im)) {
            if ($el->getAttribute('id') !== $im[1]) { return null; }
            $rest = substr($rest, strlen($im[0]));
            $spec += 100;
        }
        /* .class* */
        while (preg_match('/^\.([\w-]+)/', $rest, $cm)) {
            $cls = preg_split('/\s+/', trim((string)$el->getAttribute('class')));
            if (!in_array($cm[1], $cls, true)) { return null; }
            $rest = substr($rest, strlen($cm[0]));
            $spec += 10;
        }
        return $rest === '' ? $spec : null;
    }

    /* ==================================================
     * 🔌 ابزار HTTP / شبکه
     * ================================================== */

    private function isPrivateIp(string $ip): bool
    {
        $filtered = filter_var($ip, FILTER_VALIDATE_IP);
        if ($filtered === false) { return true; }
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function httpGet(string $url): ?string
    {
        $url = filter_var($url, FILTER_SANITIZE_URL);
        if (!preg_match('#^https?://#i', (string)$url)) { return null; }
        /* محافظت SSRF دومرحله‌ای */
        $host = (string)parse_url((string)$url, PHP_URL_HOST);
        if (filter_var($host, FILTER_VALIDATE_IP) && $this->isPrivateIp($host)) { return null; }

        $ch = curl_init((string)$url);
        if ($ch === false) { return null; }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_BUFFERSIZE => 65536,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SahandBrandMaker/2.26; +element-extractor)',
            CURLOPT_ACCEPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $size = (int)curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        curl_close($ch);
        if (!is_string($body) || $code >= 400) { return null; }
        if ($size > self::MAX_HTML) { $body = substr($body, 0, self::MAX_HTML); }
        return $body;
    }

    private function resolveUrl(string $href, string $base): ?string
    {
        if (preg_match('#^https?://#i', $href)) { return $href; }
        $origin = rtrim(preg_replace('#^(https?://[^/]+).*#i', '$1', $base), '/');
        if (str_starts_with($href, '//')) { return 'https:' . $href; }
        if (str_starts_with($href, '/')) { return $origin . $href; }
        $dir = preg_replace('#/[^/]*$#', '', $base);
        return rtrim($dir, '/') . '/' . $href;
    }
}
