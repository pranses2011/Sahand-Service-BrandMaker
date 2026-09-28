# 🛠️ پرامپتِ اصلاحِ موانعِ موتور خطایاب — برای عاملِ برنامه‌نویس

> این سند یک **پرامپتِ آماده‌ی اجرا** است. آن را به عامل برنامه‌نویس خودتان (Cursor / Claude Code / Codex / …)
> بدهید تا ۵ مانعی را که مانعِ ذخیره و نمایشِ ۱۴ فیلدِ کد خطا می‌شوند، برطرف کند.

## 🚀 دو راهِ استفاده

| راه | چطور |
|:---:|-------|
| **الف — ارجاع به فایل (ساده‌تر)** | به عامل بگویید: <br>`فایل docs/ERRORCODE-FIX-PROMPT.md را در مخزن بخوان و دقیقاً طبق آن عمل کن.` |
| **ب — کپیِ مستقیم** | بلوکِ «دستورالعمل اصلی» در بخش بعد را کپی کنید **همراه با کلِ ادامه‌ی فایل** (چون مشخصاتِ هر مانع در بخش‌های بعد است) |

> **نسخه‌ی هدف:** v2.32.0 → v2.33.0
> **مخزن:** `pranses2011/Sahand-Service-BrandMaker`
> **شاخه:** `arena/01a0e90c-sahand-service-brandmaker`
>
> 💡 **یادآوری:** [`ERRORCODE-DIAGNOSIS.md`](./ERRORCODE-DIAGNOSIS.md) توضیح می‌دهد *چرا* این موانع وجود دارند،
> و [`ERRORCODE-PROMPT.md`](./ERRORCODE-PROMPT.md) پرامپتِ تولیدِ محتواست. این فایل فقط **اصلاحِ کد** است.

---

## 📌 دستورالعملِ اصلی — این را کپی کنید و به عامل بدهید

````text
تو یک مهندس PHP ارشد هستی که روی پروژه‌ی «سایت‌ساز برند سهند سرویس»
(کلون‌شده در /home/user/Sahand-Service-BrandMaker) کار می‌کنی.

مخزن یک سایت‌ساز PHP خالص است: بدون Composer، بدون فریم‌ورک، بدون وابستگی خارجی،
هدفِ اجرا روی هاست اشتراکی cPanel با PHP 7.4+ (تست‌شده تا 8.3). پایگاه داده MySQL
با PDO ($db->fetch / fetchAll / fetchValue / insert / update / count / query) و
همه‌ی کوئری‌ها باید پارامتری (prepared) باشند.

═══════════════════════════════════════════════════════════════
🎯 هدف
═══════════════════════════════════════════════════════════════
جدول error_codes از قبل ۱۴ فیلد کامل دارد و پنل ادمین (admin/error-codes.php)
همه‌ی آن‌ها را ذخیره و نمایش می‌دهد. اما «مسیر ورود فایل JSON/Excel» و
«نمایش روی سایت برند» ناقص‌اند، بنابراین خروجیِ تولیدشده توسط هوش مصنوعی
عملاً نیمی از فیلدهایش را از دست می‌دهد.

پنج مانع زیر را دقیقاً طبق مشخصاتِ پایین برطرف کن.

═══════════════════════════════════════════════════════════════
🔒 قوانین سراسری (نقض هرکدام = کار مردود)
═══════════════════════════════════════════════════════════════
۱. PHP 7.4+ سازگار بمان — از arrow function با نوعِ بازگشت، enum،
   named arguments، nullsafe operator (?->) و هر سینتکس PHP 8 استفاده نکن.
   (کد فعلی از fn() => و ?? و spread استفاده می‌کند؛ مجاز است.)
۲. هیچ وابستگی جدیدی اضافه نشود (نه composer، نه کتابخانه).
۳. همه‌ی کوئری‌ها prepared و پارامتری باشند.
۴. همه‌ی خروجیِ HTML از e() یا معادلِ htmlspecialchars بگذرد (ضد XSS).
   ورودی‌ها با clean_input().
۵. هر تغییرِ اسکیمای دیتابیس باید idempotent باشد و از الگویِ نشانگر فایل
   پروژه پیروی کند (مثل cache/.schema_v232 در config.php).
   هیچ چیزی را بدون بررسیِ وجود، DROP/ALTER نکن.
۶. رفتارِ فعلی برای داده‌های موجود نشکند: اگر ستون مدل‌ها در رکوردی خالی بود،
   خروجی باید همان رفتار قبلی را داشته باشد (عدم نمایش)، نه خطا.
۷. فقط فایل‌های فهرست‌شده در «محدوده‌ی تغییرات» را دست بزن.
   اگر برای رفع یک مانع نیاز به تغییر فایلِ دیگری داری، اول توضیح بده.
۸. کامنت‌های فارسیِ توضیحی (با ایموجیِ مرسوم پروژه) برای هر تغییر بگذار،
   دقیقاً به سبک کدهای موجود.

═══════════════════════════════════════════════════════════════
📂 محدوده‌ی تغییرات (فقط این فایل‌ها)
═══════════════════════════════════════════════════════════════
  database.sql                                 (اسکیمای نصب تازه)
  config.php                                   (مهاجرت خودکار)
  engine/generators/ErrorCodeGenerator.php     (مانع ۱ و ۲)
  api/endpoints/error-code.php                 (مانع ۳)
  templates/brand-core/pages/error-codes.php   (مانع ۳ و ۴)
  templates/brand-core/css/style.css           (استایل فیلدهای جدید)
  admin/error-codes.php                        (مانع ۵ — فقط UI گزارش)
  UPGRADE.md                                   (مستندسازی)
````

---

## 🧱 مانع ۱ — مسیرِ ورود فایل، ۶ فیلد از ۱۴ را دور می‌ریزد

**فایل:** `engine/generators/ErrorCodeGenerator.php`
**تابع:** `import()` — سطر ۸۱ تا ۱۲۵
**مشکل:** آرایه‌ی `insert()` در سطر ۱۱۰–۱۲۱ فقط ۹ ستون را می‌نویسد؛
`subtype`، `models`، `category`، `related_part`، `tech_specs`، `part_location` نوشته نمی‌شوند.

### چه کن

`insert()` را به‌شکل زیر بازنویسی کن (هم‌زمان مانع ۲ را هم حل کن):

```php
public function import(int $brandId, array $codes): array
{
    $result = ['imported' => 0, 'skipped' => 0, 'errors' => []];
    /* 🆕 v2.33 — «اطلاعاتی» هم سطحِ معتبر است (قبلاً فقط ۴ سطح بود و
       کدهای informational به medium تنزل می‌یافتند) */
    $validSeverities = ['low', 'medium', 'high', 'critical', 'informational'];

    foreach ($codes as $i => $code) {
        /* ✅ اعتبارسنجی — پذیرش هم کلیدهای قدیمی و هم کلیدهای خروجی AI */
        $codeValue = trim((string)($code['code'] ?? ''));
        $titleValue = trim((string)($code['title_fa'] ?? $code['title'] ?? ''));
        $deviceValue = trim((string)($code['device_key'] ?? $code['device'] ?? ''));

        if ($deviceValue === '' || $codeValue === '' || $titleValue === '') {
            $result['errors'][] = 'ردیف ' . ($i + 1)
                . ': فیلدهای device (یا device_key)، code و title (یا title_fa) الزامی هستند.';
            continue;
        }

        $severity = strtolower(trim((string)($code['severity'] ?? 'medium')));
        if (!in_array($severity, $validSeverities, true)) {
            $severity = 'medium';
        }

        /* 🧭 کلید دستگاه: اگر کلیدِ استاندارد آمد مستقیم، وگرنه از نام فارسی */
        $deviceKey = $this->resolveDeviceKey($deviceValue);

        /* 🔍 جلوگیری از تکرار */
        $exists = $this->db->fetchValue(
            'SELECT COUNT(*) FROM error_codes WHERE (brand_id = ? OR brand_id IS NULL) AND device_key = ? AND code = ?',
            [$brandId, $deviceKey, clean_input($codeValue)]
        );
        if ((int)$exists) {
            $result['skipped']++;
            continue;
        }

        /* 📋 مدل‌ها — رشته (هر خط یک مدل) یا آرایه */
        $modelsRaw = $code['models'] ?? [];
        if (is_string($modelsRaw)) {
            $modelsRaw = preg_split('/[\r\n,،]+/u', $modelsRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $models = array_values(array_filter(array_map(
            fn($m) => trim(clean_input((string)$m)),
            (array)$modelsRaw
        )));

        /* 🏷 دسته‌بندی چندمقداری — آرایه با «،» ادغام، رشته همان‌طور */
        $catRaw = $code['category'] ?? 'سایر';
        $category = is_array($catRaw)
            ? implode('، ', array_map(fn($c) => clean_input((string)$c), $catRaw))
            : clean_input((string)$catRaw);
        if ($category === '') {
            $category = 'سایر';
        }

        /* 🧩 دلایل و راه‌حل‌ها — رشته (هر خط یک مورد) یا آرایه */
        $toList = function ($v) {
            if (is_string($v)) {
                $v = preg_split('/[\r\n]+/u', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
            return array_values(array_filter(array_map(
                fn($x) => trim(clean_input((string)$x)),
                (array)($v ?? [])
            )));
        };

        $this->db->insert('error_codes', [
            'brand_id'         => $brandId ?: null,
            'device_key'       => $deviceKey,
            'code'             => clean_input($codeValue),
            'title'            => clean_input($titleValue),
            'description'      => clean_input((string)($code['description_fa'] ?? $code['description'] ?? '')),
            'causes'           => json_encode($toList($code['causes'] ?? []), JSON_UNESCAPED_UNICODE),
            'solutions'        => json_encode($toList($code['solutions'] ?? []), JSON_UNESCAPED_UNICODE),
            'severity'         => $severity,
            'needs_technician' => isset($code['needs_technician']) ? (int)(bool)$code['needs_technician'] : 1,
            /* 🆕 v2.33 — شش فیلدِ تکمیلی (قبلاً هنگام ورود فایل دور ریخته می‌شدند) */
            'subtype'          => clean_input((string)($code['subtype'] ?? 'همه زیرنوع‌ها')) ?: 'همه زیرنوع‌ها',
            'models'           => json_encode($models, JSON_UNESCAPED_UNICODE),
            'category'         => $category,
            'related_part'     => clean_input((string)($code['related_part'] ?? '')),
            'tech_specs'       => clean_input((string)($code['tech_specs'] ?? '')),
            'part_location'    => clean_input((string)($code['part_location'] ?? '')),
            'source'           => 'manual',
            'is_active'        => 1,
        ]);
        $result['imported']++;
    }
    return $result;
}
```

### هم‌زمان: `resolveDeviceKey()` را کلید-آگاه کن

**سطر ۲۴۵–۲۵۵.** الان فقط نام فارسی را جستجو می‌کند، پس اگر خروجیِ AI
`device_key = "washing_machine"` بفرستد، به `SlugGenerator` می‌افتد و ممکن است
کلید اشتباهی ساخته شود. ابتدا بررسی کن ورودی خودش یک کلید معتبر است:

```php
private function resolveDeviceKey(string $deviceName): string
{
    $devices = TextProcessor::loadKnowledge('devices');
    $raw = trim($deviceName);

    /* 🆕 v2.33 — اگر ورودی خودش کلید استاندارد است، همان را برگردان */
    if (isset($devices[$raw])) {
        return $raw;
    }
    /* 🆕 نگاشتِ کلیدهای سایت‌ساز به کلیدهای پایگاه دانش devices */
    $alias = [
        'washing_machine' => 'washing_machine', 'refrigerator' => 'refrigerator',
        'dishwasher'      => 'dishwasher',      'air_conditioner' => 'air_conditioner',
        'dryer'           => 'dryer',           'microwave'    => 'microwave',
        'oven'            => 'oven',            'stove'        => 'stove',
        'hood'            => 'range_hood',      'water_heater' => 'water_heater',
        'package'         => 'package',         'television'   => 'tv',
        'vacuum_cleaner'  => 'vacuum',
    ];
    if (isset($alias[$raw]) && isset($devices[$alias[$raw]])) {
        return $alias[$raw];
    }

    foreach ($devices as $key => $d) {
        if (($d['name_fa'] ?? '') === $raw) {
            return $key;
        }
    }
    return SlugGenerator::generate($raw);
}
```

> ⚠️ قبل از نوشتنِ نگاشت بالا، `engine/knowledge/devices.json` را باز کن و کلیدهای
> واقعی‌اش را چک کن. کلیدهای آن عبارت‌اند از:
> `refrigerator, washing_machine, dishwasher, microwave, oven, stove, cooktop,
> range_hood, vacuum, air_conditioner, ducted_split, package, water_heater, tv,
> air_fryer, kettle, blender, juicer, mixer, food_processor`
> اگر نگاشت با این فهرست نخواند، نگاشت را اصلاح کن.

---

## 🧱 مانع ۲ — سطحِ 🔵 `informational` در ورود فایل نامعتبر است

**فایل:** `engine/generators/ErrorCodeGenerator.php` — **سطر ۸۴**

```php
$validSeverities = ['low', 'medium', 'high', 'critical'];
```

**چه کن:** `'informational'` را به آرایه اضافه کن *(در کدِ بالا انجام شده)*.
توجه کن که `admin/error-codes.php` (سطر ۳۰۹–۳۱۵) از قبل `informational` را
با برچسب «اطلاعاتی 🔵» می‌شناسد و ENUMِ ستون در `database.sql` هم آن را دارد؛
فقط همین یک خط جامانده بود.

---

## 🧱 مانع ۳ — API و سایت برند، ۶ فیلد را نمی‌بینند

### ۳ الف) `api/endpoints/error-code.php` — سطر ۲۶

الان:
```php
return $db->fetchAll("SELECT device_key, code, title, description, causes, solutions, severity, needs_technician FROM error_codes WHERE {$where} ORDER BY device_key, code", $params);
```

**چه کن:** شش ستون را به SELECT اضافه کن و در نگاشتِ خروجی،
`models` را از JSON به آرایه تبدیل کن (مثل `causes` و `solutions`):

```php
return $db->fetchAll(
    "SELECT device_key, code, title, description, causes, solutions, severity,
            needs_technician, subtype, models, category, related_part,
            tech_specs, part_location
     FROM error_codes WHERE {$where} ORDER BY device_key, code",
    $params
);
```

و داخل `array_map`:
```php
$row['causes']    = json_decode($row['causes'] ?? '[]', true) ?: [];
$row['solutions'] = json_decode($row['solutions'] ?? '[]', true) ?: [];
/* 🆕 v2.33 */
$row['models']    = json_decode($row['models'] ?? '[]', true) ?: [];
$row['needs_technician'] = (bool)$row['needs_technician'];
return $row;
```

> 📌 حواست باشد کشِ این اندپوینت ۳۰۰ ثانیه است (`$cache->remember($cacheKey, 300, …)`).
> برای تست، کش را پاک کن یا ۵ دقیقه صبر کن.

### ۳ ب) `templates/brand-core/pages/error-codes.php`

**سطر ۱۹** — نقشه‌ی شدت بدون `informational`:
```php
$severityMap = ['low' => ['کم', 'sev-low'], 'medium' => ['متوسط', 'sev-med'], 'high' => ['زیاد', 'sev-high'], 'critical' => ['بحرانی', 'sev-crit']];
```
**چه کن:** اضافه کن
```php
'informational' => ['اطلاعاتی', 'sev-info'],
```
و در CSS کلاس `sev-info` را تعریف کن (مانع ۳ ج).

**سطر ۴۱–۶۴** — کارتِ فعلی فقط کد/دستگاه/شدت/عنوان/توضیح/علت‌ها/راه‌حل‌ها را نشان می‌دهد.
**چه کن:** بعد از خطِ «راه‌حل‌ها» و قبل از `<div class="ecode-foot">`،
یک بلوکِ «جزئیات فنی» اضافه کن که فقط فیلدهای **پُر** را نشان دهد
(قانون ۶: رفتار فعلی برای رکوردهای قدیمی نباید عوض شود):

```php
<?php
/* 🆕 v2.33 — نمایش شش فیلد تکمیلی (فقط اگر مقدار دارند) */
$ecModels = is_array($code['models'] ?? null) ? array_filter((array)$code['models']) : [];
$ecSubtype = trim((string)($code['subtype'] ?? ''));
$ecCategory = trim((string)($code['category'] ?? ''));
$ecPart = trim((string)($code['related_part'] ?? ''));
$ecSpecs = trim((string)($code['tech_specs'] ?? ''));
$ecLoc = trim((string)($code['part_location'] ?? ''));
$ecHasMeta = ($ecSubtype !== '' && $ecSubtype !== 'همه زیرنوع‌ها') || $ecModels || $ecCategory !== '';
$ecHasPart = ($ecPart !== '' || $ecSpecs !== '' || $ecLoc !== '');
?>
<?php if ($ecHasMeta || $ecHasPart): ?>
    <div class="ecode-meta">
        <?php if ($ecHasMeta): ?>
            <div class="ecode-meta-row">
                <?php if ($ecSubtype !== '' && $ecSubtype !== 'همه زیرنوع‌ها'): ?>
                    <span class="ecode-chip"><b>زیرنوع:</b> <?= e($ecSubtype) ?></span>
                <?php endif; ?>
                <?php if ($ecCategory !== ''): ?>
                    <span class="ecode-chip"><b>نوع خطا:</b> <?= e($ecCategory) ?></span>
                <?php endif; ?>
                <?php if ($ecModels): ?>
                    <?php
                    /* 🛡 fa_num فقط در config.php نسخه‌های جدید است؛ برای سایت‌های
                       از قبل مستقرشده که config قدیمی دارند، بدون فانکشن هم کار کند */
                    $moreN = count($ecModels) - 6;
                    $moreTxt = $moreN > 0
                        ? ' و ' . (function_exists('fa_num') ? fa_num((string)$moreN) : (string)$moreN) . ' مدل دیگر'
                        : '';
                    ?>
                    <span class="ecode-chip"><b>مدل‌ها:</b>
                        <?= e(implode('، ', array_slice($ecModels, 0, 6))) ?><?= e($moreTxt) ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php if ($ecHasPart): ?>
            <div class="ecode-part">
                <?php if ($ecPart !== ''): ?>
                    <div><b>🔧 قطعه مربوطه:</b> <?= e($ecPart) ?></div>
                <?php endif; ?>
                <?php if ($ecSpecs !== ''): ?>
                    <div><b>📐 مشخصات فنی:</b> <?= e($ecSpecs) ?></div>
                <?php endif; ?>
                <?php if ($ecLoc !== ''): ?>
                    <div><b>📍 محل قطعه:</b> <?= e($ecLoc) ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
```

> ⚠️ **دو تابع را اشتباه نگیر:**
> - `fa_num()` — در `templates/brand-core/config.php:200` تعریف شده و در سایت برند **در دسترس است**.
> - `en_to_fa_digits()` — در `includes/helpers.php` ی **سایت‌ساز** است و در سایت برند
>   **موجود نیست**. هرگز در `templates/brand-core/` صدا زده نشود (این دقیقاً ریشه‌ی
>   خطای ۵۰۰ صفحه آمار در v2.28 بود).
>
> چون سایت‌های از قبل مستقرشده ممکن است `config.php` قدیمی داشته باشند،
> هر فراخوانیِ `fa_num()` را با `function_exists('fa_num')` محافظت کن
> (در قطعه‌ی بالا همین کار انجام شده).

### ۳ ج) `templates/brand-core/css/style.css`

بعد از خط ۳۷۱ (`.ecode-severity`) و تعاریفِ `sev-*` موجود، اضافه کن:

```css
/* 🆕 v2.33 — سطح «اطلاعاتی» + بلوک جزئیات فنی کد خطا */
.sev-info { background: #eff6ff; color: #1d4ed8; }
.ecode-meta { margin-top: 12px; border-top: 1px dashed var(--color-border); padding-top: 11px; }
.ecode-meta-row { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.ecode-chip { font-size: 11.5px; background: var(--color-surface); color: var(--color-text-light);
              padding: 4px 10px; border-radius: 20px; line-height: 1.7; }
.ecode-chip b { color: var(--color-text); font-weight: 700; }
.ecode-part { font-size: 12px; color: var(--color-text-light); line-height: 1.9; }
.ecode-part b { color: var(--color-text); font-weight: 700; }
.ecode-part div { margin-bottom: 3px; }
```

و در بلوکِ `@media` مربوط به موبایل (حوالی خط ۴۴۱ و ۴۹۸)، مطمئن شو
`.ecode-meta-row` روی موبایل به‌درستی می‌شکند (flex-wrap از قبل کافی است).

> 📦 **مهم:** این فایل درون `templates/brand-core/` است که هنگام ساخت ZIP/استقرار
> بسته‌بندی می‌شود (`core/Deployer.php:1732` و `admin/export.php:73`).
> بنابراین سایت‌های **از قبل مستقرشده** این تغییر را نمی‌بینند مگر اینکه
> از «ویرایش برند ← استقرار ← بروزرسانی استقرار» دوباره مستقر شوند.
> این را در UPGRADE.md حتماً بنویس.

---

## 🧱 مانع ۴ — اسکیمای `FAQPage` برای سئو (اختیاری اما توصیه‌شده)

در `templates/brand-core/pages/error-codes.php`، داخل حلقه‌ی هر کارت،
یک بلوکِ JSON-LD اضافه کن تا گوگل این صفحات را به‌عنوان محتوای پرسش‌وپاسخ ببیند
(یک تگ در هر کارت؛ تکرارِ `FAQPage` در یک صفحه برای چند پرسش مجاز است
اگر هرکدام `mainEntity` مستقل داشته باشند):

```php
<?php if (!empty($code['causes']) || !empty($code['solutions'])): ?>
<script type="application/ld+json"><?= json_encode([
    '@context' => 'https://schema.org',
    '@type'    => 'FAQPage',
    'mainEntity' => array_values(array_filter([
        empty($code['description']) ? null : [
            '@type' => 'Question',
            'name'  => 'کد خطای ' . $code['code'] . ' در ' . BRAND_NAME_FA . ' یعنی چه؟',
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => mb_substr(strip_tags((string)$code['description']), 0, 900)],
        ],
        empty($code['causes']) ? null : [
            '@type' => 'Question',
            'name'  => 'علت بروز کد خطای ' . $code['code'] . ' چیست؟',
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => implode('. ', array_slice((array)$code['causes'], 0, 5))],
        ],
        empty($code['solutions']) ? null : [
            '@type' => 'Question',
            'name'  => 'چطور کد خطای ' . $code['code'] . ' را برطرف کنیم؟',
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => implode('. ', array_slice((array)$code['solutions'], 0, 5))],
        ],
    ])),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
```

> فقط اگر مطمئنی که با ساختار صفحه تداخل ندارد اضافه کن. در صورت تردید،
> این مانع را رد کن و در گزارش بنویس «انجام نشد — نیاز به بررسی».

---

## 🧱 مانع ۵ — هیچ دروازه‌ی یکتایی در سطح دیتابیس نیست

الان هیچ مکانیزمی نداریم که بفهمد دو کدِ خطا محتوای تکراری دارند
(برخلاف `brand_articles` که `uniqueness_hash` دارد). بدون این،
«گوگل برچسب کپی نزند» فقط در سطح پرامپت تضمین می‌شود، نه در سطح سیستم.

### ۵ الف) `database.sql` — ستون جدید

در تعریف جدول `error_codes`، بعد از ستون `source_urls` اضافه کن:

```sql
  `content_hash` CHAR(64) NULL COMMENT 'هش SHA-256 محتوای نرمال‌شده (تشخیص کپی/تکراری)',
  KEY `idx_ecode_hash` (`content_hash`),
```

### ۵ ب) `config.php` — مهاجرت خودکار (الگویِ پروژه)

یک بلوکِ جدید دقیقاً بعد از بلوکِ `schema_v232` (سطر ۷۵۶–۷۷۲) و قبل از
بخش «شروع امن نشست» اضافه کن:

```php
/* --------------------------------------------------
 * 🆕 v2.33 — error_codes.content_hash
 * هش SHA-256 از محتوای نرمال‌شده (عنوان + توضیح + علت‌ها + راه‌حل‌ها)
 * برای تشخیص کدهای خطای کپی/تکراری (الزام سئو: گوگل نباید برچسب تکراری بزند).
 * فقط یک بار (cache/.schema_v233).
 * -------------------------------------------------- */
if (!defined('SAHAND_NO_DB_MIGRATE')) {
    try {
        $v233Marker = ROOT_PATH . '/cache/.schema_v233';
        if (!file_exists($v233Marker)) {
            $pdo = Database::getInstance()->pdo();
            $hasHash = $pdo->query("SHOW COLUMNS FROM `error_codes` LIKE 'content_hash'")->fetchAll();
            if (empty($hasHash)) {
                $pdo->exec("ALTER TABLE `error_codes` ADD COLUMN `content_hash` CHAR(64) NULL COMMENT 'هش SHA-256 محتوای نرمال‌شده (تشخیص کپی/تکراری)' AFTER `source_urls`");
                $pdo->exec("ALTER TABLE `error_codes` ADD KEY `idx_ecode_hash` (`content_hash`)");
            }
            /* 🧮 پر کردن هش برای رکوردهای موجود */
            try {
                $rows = $pdo->query("SELECT id, title, description, causes, solutions FROM error_codes WHERE content_hash IS NULL OR content_hash = ''")->fetchAll(PDO::FETCH_ASSOC);
                $upd = $pdo->prepare("UPDATE error_codes SET content_hash = ? WHERE id = ?");
                foreach ($rows as $r) {
                    $upd->execute([error_code_content_hash($r), (int)$r['id']]);
                }
            } catch (Throwable $bh) { /* بی‌صدا */ }
            @file_put_contents($v233Marker, date('Y-m-d H:i:s'));
        }
    } catch (Throwable $v233SchemaE) {
        // نصب تازه یا دسترسی محدود — بی‌صدا رد می‌شود
    }
}
```

و یک تابعِ کمکی در `includes/helpers.php` (آخرِ فایل، بیرون از هر کلاس) اضافه کن:

```php
/**
 * 🔐 هش محتوای یکتای کد خطا (v2.33)
 * از عنوان + توضیح + علت‌ها + راه‌حل‌ها، پس از نرمال‌سازی
 * (حذف فاصله‌های اضافه و نویسه‌های کنترلی و یکسان‌سازی ارقام عربی/فارسی).
 * کاربرد: تشخیص کدهای خطای کپی/تکراری (سئو).
 *
 * @param array $r ردیف با کلیدهای title, description, causes, solutions
 * @return string هش SHA-256 یا رشته خالی اگر محتوایی نباشد
 */
if (!function_exists('error_code_content_hash')) {
    function error_code_content_hash(array $r): string
    {
        $parts = [
            (string)($r['title'] ?? ''),
            (string)($r['description'] ?? ''),
            is_array($r['causes'] ?? null) ? implode(' ', $r['causes']) : (string)($r['causes'] ?? ''),
            is_array($r['solutions'] ?? null) ? implode(' ', $r['solutions']) : (string)($r['solutions'] ?? ''),
        ];
        $text = implode(' ', $parts);
        if (trim($text) === '') {
            return '';
        }
        /* یکسان‌سازی ارقام عربی/فارسی و حذف نویسه‌های کنترلی */
        $text = str_replace(['\u0660','\u0661','\u0662','\u0663','\u0664','\u0665','\u0666','\u0667','\u0668','\u0669'], ['0','1','2','3','4','5','6','7','8','9'], $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', (string)$text) ?? $text;
        return hash('sha256', trim($text));
    }
}
```

> ⚠️ در PHP 7.4 رشته‌ی `'\u0660'` تک‌نقل‌شده **escape نمی‌شود**.
> یا از `"\u{0660}"` (دو‌نقل‌شده، PHP 7+) استفاده کن، یا مستقیم کاراکترهای
> عربی را در رشته بنویس. نسخه‌ی درست:
> ```php
> $text = str_replace(['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'], ['0','1','2','3','4','5','6','7','8','9'], $text);
> ```
> این را رعایت کن.

### ۵ ج) محاسبه‌ی هش هنگام ذخیره

در `engine/generators/ErrorCodeGenerator.php::import()`، بعد از ساختِ
آرایه‌ی داده و قبل از `insert()`، اضافه کن:

```php
/* 🔐 v2.33 — هش یکتایی محتوا (تشخیص کپی در سطح سیستم) */
$hashSource = [
    'title'       => $titleValue,
    'description' => (string)($code['description_fa'] ?? $code['description'] ?? ''),
    'causes'      => $toList($code['causes'] ?? []),
    'solutions'   => $toList($code['solutions'] ?? []),
];
```
و کلید `'content_hash' => function_exists('error_code_content_hash') ? error_code_content_hash($hashSource) : null,`
را به آرایه‌ی `insert()` اضافه کن.

همین کار را برای دو جایِ دیگر هم انجام بده تا هش همیشه به‌روز بماند:
- `admin/error-codes.php` → `action=save_edit` (سطر ۸۸) و `action=add` (سطر ۲۳)
- `engine/generators/ErrorCodeEngine.php` → `buildRecord()` (سطر ۱۹۳۰ به بعد، آرایه‌ی بازگشتی)

در هر سه، بعد از آماده شدنِ آرایه‌ی `$data`، این را بگذار:
```php
if (function_exists('error_code_content_hash')) {
    $data['content_hash'] = error_code_content_hash([
        'title' => $data['title'] ?? '', 'description' => $data['description'] ?? '',
        'causes' => $data['causes'] ?? [], 'solutions' => $data['solutions'] ?? [],
    ]);
}
```

### ۵ د) `admin/error-codes.php` — گزارشِ تکراری‌ها (UI)

یک کارتِ هشدار جدید در بالای صفحه اضافه کن (بعد از فلش‌مسیج‌ها)،
که فقط وقتی نمایش داده شود که هشِ تکراری وجود داشته باشد:

```php
<?php
/* 🆕 v2.33 — هشدار محتوای تکراری (سئو) */
$dupGroups = [];
try {
    $dupGroups = $db->fetchAll(
        "SELECT content_hash, COUNT(*) AS cnt, GROUP_CONCAT(CONCAT(device_key,'/',code) SEPARATOR '، ') AS codes
         FROM error_codes
         WHERE content_hash IS NOT NULL AND content_hash <> '' AND is_active = 1
         GROUP BY content_hash HAVING cnt > 1 ORDER BY cnt DESC LIMIT 5"
    );
} catch (Throwable $dupE) {
    $dupGroups = []; // ستون هنوز ایجاد نشده — بی‌صدا
}
?>
<?php if ($dupGroups): ?>
<div class="alert alert-warning">
    <b>⚠️ <?= en_to_fa_digits((string)count($dupGroups)) ?> گروه کد خطای تکراری یافت شد</b>
    <div style="font-size:12.5px;margin-top:6px">
        این کدها محتوای یکسان دارند و ممکن است گوگل آن‌ها را تکراری ببیند.
        هر کدام را ویرایش و متن/علت‌ها را اختصاصی کنید.
    </div>
    <ul style="margin:8px 18px 0 0;font-size:12.5px">
        <?php foreach ($dupGroups as $g): ?>
            <li><code><?= e((string)$g['codes']) ?></code></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
```

---

## 🧪 برنامه‌ی تأیید (اجرا کن و نتیجه را گزارش بده)

> ⚠️ اگر `php` روی محیط نصب نیست، اول نصب کن:
> `apt-get install -y php-cli` (یا `brew install php`).
> اگر امکان‌پذیر نیست، `php -l` را حذف و به‌جای آن یک بررسیِ چشمیِ دقیق انجام بده،
> و در گزارش بنویس «سینتکس با ابزار خودکار تأیید نشد».

```bash
# ۱) سینتکس همه‌ی فایل‌های تغییر‌یافته
for f in config.php database.sql \
         engine/generators/ErrorCodeGenerator.php \
         engine/generators/ErrorCodeEngine.php \
         api/endpoints/error-code.php \
         admin/error-codes.php \
         includes/helpers.php \
         templates/brand-core/pages/error-codes.php; do
  php -l "$f" || echo "FAIL: $f"
done

# ۲) یک فایل JSONِ نمونه مطابق شِما بساز و مطمئن شو import() همه ۱۴ فیلد را می‌نویسد
#    (یک اسکریپت تست موقت بنویس، اجرا کن، بعد پاکش کن)

# ۳) بررسی کن که خروجی API شش کلید جدید را دارد
#    (فراخوانی مستقیم تابع یا curl به /api/brand/{id}/error-codes?q=OE)

# ۴) بررسی کن که severity=informational دیگر به medium تنزل نمی‌یابد

# ۵) بررسی کن که رکوردهای قدیمی (بدون models/specs) همچنان بدون خطا رندر می‌شوند
```

### سناریوی تستِ دستی (حتماً انجام بده)

۱. یک فایل `test-codes.json` با این ساختار بساز:
```json
{
  "brand_fa": "ال‌جی", "brand_en": "LG",
  "device_key": "washing_machine",
  "device_fa": "ماشین لباسشویی", "device_en": "Washing Machine",
  "error_codes": [
    {
      "code": "ZZ-TEST", "subtype": "درب از جلو | Front-Load",
      "models": ["WM3997HWA", "WM3470HVA", "WM4270HWA"],
      "title_fa": "خطای آزمایشی", "severity": "informational",
      "description_fa": "توصیف آزمایشی.",
      "causes": ["علت ۱", "علت ۲", "علت ۳"],
      "solutions": ["[کاربر] راه‌حل ۱", "[تکنسین] راه‌حل ۲"],
      "category": ["سنسور", "قفل و ایمنی"],
      "related_part": "سنسور تست | Test Sensor",
      "tech_specs": "مقاومت: ۵ kΩ",
      "part_location": "پشت دیگ",
      "needs_technician": false
    }
  ]
}
```
۲. از `admin/error-codes.php` آن را برای یک برند وارد کن.
۳. در دیتابیس چک کن که **هر ۱۴ ستون** پر شده‌اند
   (به‌ویژه `subtype`, `models`, `category`, `related_part`, `tech_specs`, `part_location`, `content_hash`)
   و `severity` دقیقاً `informational` است (نه `medium`).
۴. رکوردِ تست را حذف کن.
۵. یک رکوردِ قدیمی (با `models` خالی) را باز کن و مطمئن شو صفحه‌ی سایت برند خطا نمی‌دهد.

---

## 📋 معیارهای پذیرش (همه باید true باشند)

- [ ] `php -l` برای همه‌ی فایل‌های تغییر‌یافته بدون خطا
- [ ] `import()` هر ۱۴ فیلد را می‌نویسد (تأیید با تستِ سناریوی بالا)
- [ ] `severity = informational` دست‌نخورده ذخیره می‌شود
- [ ] `device_key` مستقیم (مثل `washing_machine`) به‌درستی تشخیص داده می‌شود
- [ ] API سایت برند شش کلید جدید را برمی‌گرداند و `models` آرایه است
- [ ] سایت برند زیرنوع / نوع خطا / مدل‌ها / قطعه / مشخصات / محل را نمایش می‌دهد
- [ ] سطح «اطلاعاتی 🔵» روی سایت برند درست نمایش داده می‌شود
- [ ] رکوردهای قدیمیِ ناقص، بدون خطا و بدون بلوکِ خالی رندر می‌شوند
- [ ] مهاجرت `schema_v233` یک‌بار اجرا می‌شود و در اجرایِ دوم کاری نمی‌کند
- [ ] هیچ فایلی خارج از «محدوده‌ی تغییرات» دست نخورده
- [ ] `UPGRADE.md` به‌روزرسانی شده (شامل هشدارِ «نیاز به بروزرسانی استقرار برای سایت‌های مستقر»)

---

## 📝 به‌روزرسانیِ UPGRADE.md

یک بخش جدید اضافه کن:

```markdown
## 🆕 نسخه ۲.۳۳٫۰ — تکمیل ۱۴ فیلدِ کدهای خطا

| تغییر | توضیح |
|-------|-------|
| ورود فایل JSON/Excel | از این پس هر ۱۴ فیلد (زیرنوع، مدل‌ها، نوع خطا، قطعه، مشخصات فنی، محل قطعه) ذخیره می‌شوند — قبلاً ۶ فیلد دور ریخته می‌شد |
| سطح «اطلاعاتی 🔵» | در ورود فایل معتبر شد (قبلاً به «متوسط» تنزل می‌یافت) |
| نمایش روی سایت برند | شش فیلد تکمیلی + سطح اطلاعاتی نمایش داده می‌شوند |
| `error_codes.content_hash` | ستون جدید برای تشخیص کدهای خطای کپی/تکراری (مهاجرت خودکار) |
| هشدار تکراری در پنل | اگر چند کد محتوای یکسان داشته باشند، بالای صفحه هشدار می‌بینید |

> ⚠️ **برای سایت‌های از قبل مستقرشده:** تغییراتِ `templates/brand-core/`
> (صفحه و CSS کدهای خطا) فقط با **بروزرسانی استقرار** منتقل می‌شود.
> از «ویرایش برند ← استقرار ← بروزرسانی استقرار» یک‌بار اجرا کنید.
```

---

## 🚫 کارهایی که نباید انجام بدهی

- تغییر یا حذفِ رفتارِ فعلیِ `add` و `save_edit` در `admin/error-codes.php`
  (این دو از قبل ۱۴ فیلد را درست ذخیره می‌کنند — فقط هش را به آن‌ها اضافه کن).
- بازنویسیِ `ErrorCodeEngine::generateForDevice()` یا `buildRecord()`
  (تغییر فقط اضافه کردنِ `content_hash` است).
- تغییرِ ENUMِ ستون `severity` (از قبل `informational` را دارد).
- دست زدن به `engine/knowledge/*.json`.
- افزودن هرگونه وابستگی یا فایل خارجی.
- تغییرِ رفتارِ نمایش وقتی فیلدها خالی‌اند (نباید بلوکِ خالی یا «—» نمایش داده شود).

---

## 📤 خروجیِ مورد انتظار از تو

در پایان یک گزارش بده شامل:
1. فهرستِ فایل‌های تغییر‌یافته با تعداد خطوط افزوده/حذف‌شده
2. نتیجه‌ی `php -l` برای هر فایل
3. نتیجه‌ی سناریوی تستِ دستی (آیا هر ۱۴ ستون پر شد؟ آیا informational حفظ شد؟)
4. هر مانعی که نتوانستی رفع کنی + علت دقیق
5. هر انحرافی از این دستورالعمل + دلیل
````

---

## 💡 نکته‌های اجرایی برای خودتان

| نکته | توضیح |
|------|-------|
| **ترتیب اجرا** | مانع ۱ و ۲ را اول انجام بدهید (بدون آن‌ها بقیه بی‌فایده است — داده‌ای ذخیره نمی‌شود که نمایش داده شود) |
| **قبل از اجرا** | از شاخه‌ی فعلی یک بکاپ بگیرید: `git branch backup/pre-errorcode-fix` |
| **بعد از اجرا** | `UPGRADE.md` را بخوانید و مرحله‌ی «بروزرسانی استقرار» را برای هر برند انجام دهید، وگرنه روی سایت‌های زنده چیزی عوض نمی‌شود |
| **سپس** | تازه [`ERRORCODE-PROMPT.md`](./ERRORCODE-PROMPT.md) را اجرا کنید و خروجی را وارد کنید — حالا ۱۴ فیلد واقعاً ذخیره و نمایش داده می‌شوند |
| **بررسی نهایی** | یک صفحه‌ی کد خطا را روی سایت برند باز کنید و هر ۱۴ فیلد را ببینید |
