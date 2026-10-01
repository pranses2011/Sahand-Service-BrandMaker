<?php
/**
 * 🧩 CustomFormManager — فرم‌ساز سفارشی (S12 / v2.44)
 * ============================================================
 * درخواست کاربر: «یک قسمت فرم‌ساز باشه تا بتونیم فرم سفارشی برای سایت‌ها
 * بسازیم (مانند Gravity Forms) که همه نوع فیلدی داشته باشد و همه نوع
 * تنظیماتی هم براش بذار و در قالب‌ساز عنصر فرم‌های سفارشی رو هم اضافه کن.»
 *
 * معماری:
 *   custom_forms (تعریف) + form_entries (ورودی — بدون جدول جدید)
 *   form_block='custom' + form_slug → کل زنجیره اعلان/ضداسپم/وب‌هوک موجود
 *
 * نوع فیلد (type):
 *   text | textarea | email | tel | number | url | date | select | radio
 *   | checkbox | consent | rating | hidden | section | html | file
 *
 * تنظیمات هر فیلد: label, name, placeholder, required, default, options,
 *   width (full|half), help, min, max, maxLength, cssClass
 *
 * @package SahandBrandMaker\Core
 * @since 2.44.0
 */
class CustomFormManager
{
    /** 🧰 انواع فیلد مجاز + برچسب + آیکون (پالت فرم‌ساز) */
    const FIELD_TYPES = [
        'text'     => ['متن تک‌خطی', '🔤'],
        'textarea' => ['متن چندخطی', '📝'],
        'email'    => ['ایمیل', '📧'],
        'tel'      => ['شماره تماس', '📞'],
        'number'   => ['عدد', '🔢'],
        'url'      => ['لینک', '🔗'],
        'date'     => ['تاریخ (شمسی)', '🗓'],
        'select'   => ['لیست کشویی', '📋'],
        'radio'    => ['گزینه‌های تک‌انتخابی', '🔘'],
        'checkbox' => ['چند انتخابی', '☑️'],
        'consent'  => ['تأییدیه/توافق‌نامه', '✅'],
        'rating'   => ['امتیاز ستاره‌ای', '⭐'],
        'hidden'   => ['مخفی', '👁️‍🗨️'],
        'section'  => ['عنوان بخش', '🏷'],
        'html'     => ['متن توضیحی آزاد', '💡'],
        'file'     => ['تصویر پیوست', '🖼'],
    ];

    /** 🏷 نوع‌هایی که گزینه‌های انتخابی دارند */
    const OPTION_TYPES = ['select', 'radio', 'checkbox'];

    /** 🏷 نوع‌هایی که مقدار ورودی کاربر نمی‌گیرند (ساختاری) */
    const STRUCTURAL_TYPES = ['section', 'html'];

    /** 🎭 🆕 v2.45 (S11) — ماسک‌های آماده فرمت/اعتبارسنجی فیلد
     *  قالب الگو: «#» = رقم، «A» = حرف لاتین، «*» = هر کاراکتر، بقیه = جداشنما ثابت.
     *  هر پیش‌تنظیم: [برچسب، الگوی نمایش، پیام خطای اعتبارسنجی] */
    const MASK_PRESETS = [
        'none'          => ['بدون ماسک', '', ''],
        'national_code' => ['🆔 کد ملی (۱۰ رقم + بررسی اعتبار)', '##########', 'کد ملی معتبر نیست — دقیقاً ۱۰ رقم وارد کنید.'],
        'mobile'        => ['📱 موبایل (۰۹…)', '#### ### ####', 'شماره موبایل معتبر نیست — مثل ۰۹۱۲ ۳۴۵ ۶۷۸۹.'],
        'phone'         => ['☎️ تلفن ثابت (۰ + کد شهر)', '### ### ####', 'شماره تلفن معتبر نیست — مثل ۰۴۱ ۳۳۳ ۱۲۳۴۵.'],
        'card'          => ['💳 کارت بانکی (۱۶ رقم)', '####-####-####-####', 'شماره کارت معتبر نیست — ۱۶ رقم وارد کنید.'],
        'sheba'         => ['🏦 شبا (IR + ۲۴ رقم)', 'IR########################', 'شماره شبا معتبر نیست — IR به‌همراه ۲۴ رقم.'],
        'postal'        => ['📮 کد پستی (۱۰ رقم)', '##########', 'کد پستی معتبر نیست — دقیقاً ۱۰ رقم.'],
        'custom'        => ['✏️ الگوی دلخواه…', '', 'مقدار واردشده با فرمت خواسته‌شده نمی‌خواند.'],
    ];

    /** 🎭 نوع‌هایی که ماسک می‌پذیرند (ورودی متنی تک‌خطی) */
    const MASKABLE_TYPES = ['text', 'tel'];

    /**
     * ✅ 🆕 v2.45 (S11) — اعتبارسنجی مقدار فیلد ماسک‌دار (سمت سرور)
     * @param array $field تعریف فیلد (شامل mask/maskPattern)
     * @param string $value مقدار کاربر
     * @return string|null پیام خطا یا null اگر معتبر
     */
    public static function maskError(array $field, string $value): ?string
    {
        $mask = (string)($field['mask'] ?? 'none');
        if ($mask === '' || $mask === 'none') { return null; }
        $v = self::faDigitsToEn(trim($value));
        $label = (string)($field['label'] ?? 'این فیلد');
        /* برای ماسک دلخواه، رقم/حرف فقط از کاراکترهای الگو چک می‌شود */
        if ($mask === 'custom') {
            $pattern = (string)($field['maskPattern'] ?? '');
            if ($pattern === '') { return null; }
            $digits = preg_replace('/[^#A*]/', '', $pattern) ?? '';
            $needDigits = substr_count($digits, '#');
            $needLetters = substr_count($digits, 'A');
            $anyCount = substr_count($digits, '*');
            $clean = preg_replace('/[^0-9A-Za-z]/', '', $v) ?? '';
            $gotDigits = preg_match_all('/[0-9]/', $v) ?: 0;
            $gotLetters = preg_match_all('/[A-Za-z]/', $v) ?: 0;
            if ($needDigits > 0 && $gotDigits < $needDigits) {
                return $label . ': ' . self::MASK_PRESETS['custom'][2] . ' (حداقل ' . $needDigits . ' رقم لازم است)';
            }
            if ($needLetters > 0 && $gotLetters < $needLetters) {
                return $label . ': ' . self::MASK_PRESETS['custom'][2] . ' (حداقل ' . $needLetters . ' حرف لاتین لازم است)';
            }
            if ($needDigits === 0 && $needLetters === 0 && $anyCount > 0 && mb_strlen($clean) < $anyCount) {
                return $label . ': ' . self::MASK_PRESETS['custom'][2];
            }
            return null;
        }
        if (!isset(self::MASK_PRESETS[$mask])) { return null; }
        $err = $label . ': ' . self::MASK_PRESETS[$mask][2];
        switch ($mask) {
            case 'national_code':
                if (!preg_match('/^\d{10}$/', $v)) { return $err; }
                /* رقم کنترل (الگوریتم استاندارد کد ملی) */
                $sum = 0;
                for ($i = 0; $i < 9; $i++) { $sum += (int)$v[$i] * (10 - $i); }
                $r = $sum % 11;
                $check = (int)$v[9];
                if (($r < 2 ? $r : 11 - $r) !== $check) { return $err . ' (رقم کنترل نامعتبر)'; }
                return null;
            case 'mobile':
                return preg_match('/^09\d{9}$/', preg_replace('/\D/', '', $v) ?? '') ? null : $err;
            case 'phone':
                return preg_match('/^0\d{10}$/', preg_replace('/\D/', '', $v) ?? '') ? null : $err;
            case 'card':
                $digits = preg_replace('/\D/', '', $v) ?? '';
                if (!preg_match('/^\d{16}$/', $digits)) { return $err; }
                /* الگوریتم Luhn */
                $sum = 0;
                for ($i = 0; $i < 16; $i++) {
                    $d = (int)$digits[$i];
                    if ($i % 2 === 0) { $d *= 2; if ($d > 9) { $d -= 9; } }
                    $sum += $d;
                }
                return ($sum % 10 === 0) ? null : $err . ' (شماره کارت وجود ندارد)';
            case 'sheba':
                $digits = preg_replace('/[^0-9]/', '', $v) ?? '';
                return (strlen($digits) === 24) ? null : $err;
            case 'postal':
                return preg_match('/^\d{10}$/', preg_replace('/\D/', '', $v) ?? '') ? null : $err;
        }
        return null;
    }

    /** 🔢 تبدیل ارقام فارسی/عربی → انگلیسی */
    public static function faDigitsToEn(string $s): string
    {
        $fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        return str_replace($fa, $en, $s);
    }

    /**
     * 🧼 پاکسازی و نرمال‌سازی تعریف فیلدهای فرم (ورودی مدیر)
     * @param array $fields آرایه خام فیلدها از فرم پنل
     * @return array فیلدهای تمیز و امن
     */
    public static function sanitizeFields(array $fields): array
    {
        $out = [];
        $usedNames = [];
        $i = 0;
        foreach ($fields as $f) {
            if (!is_array($f)) { continue; }
            $type = (string)($f['type'] ?? 'text');
            if (!isset(self::FIELD_TYPES[$type])) { $type = 'text'; }
            $label = mb_substr(trim(strip_tags((string)($f['label'] ?? ''))), 0, 120);
            if ($label === '') { $label = self::FIELD_TYPES[$type][0]; }

            /* نام فیلد: اسلاگ لاتین یکتا */
            $name = strtolower(trim((string)($f['name'] ?? '')));
            $name = preg_replace('/[^a-z0-9_]/', '', $name) ?: '';
            if ($name === '') {
                $name = 'field_' . ($i + 1);
            }
            while (isset($usedNames[$name])) {
                $name .= '_x';
            }
            $usedNames[$name] = true;

            $clean = [
                'type'     => $type,
                'label'    => $label,
                'name'     => $name,
                'required' => !empty($f['required']),
            ];
            if (in_array($type, ['text', 'textarea', 'email', 'tel', 'url', 'number', 'date', 'select'], true) || $type === 'file') {
                $clean['placeholder'] = mb_substr(trim(strip_tags((string)($f['placeholder'] ?? ''))), 0, 150);
                $clean['default'] = mb_substr(trim(strip_tags((string)($f['default'] ?? ''))), 0, 300);
            }
            /* 🎭 🆕 v2.45 (S11) — ماسک/فرمت ورودی (فقط فیلدهای متنی تک‌خطی) */
            if (in_array($type, self::MASKABLE_TYPES, true)) {
                $mask = (string)($f['mask'] ?? 'none');
                if (!isset(self::MASK_PRESETS[$mask])) { $mask = 'none'; }
                if ($mask !== 'none') {
                    $clean['mask'] = $mask;
                    if ($mask === 'custom') {
                        /* الگوی دلخواه: فقط #/A/* و جداشنماها — حداکثر ۳۰ خانه */
                        $pat = preg_replace('/[^#A*a-z0-9\-\/: ._]/', '', (string)($f['maskPattern'] ?? '')) ?? '';
                        $pat = mb_substr(trim($pat), 0, 30);
                        if ($pat !== '' && preg_match('/[#A*]/', $pat)) {
                            $clean['maskPattern'] = $pat;
                        } else {
                            unset($clean['mask']); /* الگوی بی‌خاصیت = بدون ماسک */
                        }
                    }
                }
            }
            if (in_array($type, self::OPTION_TYPES, true)) {
                $opts = [];
                /* دو شکل ورودی پذیرفته می‌شود:
                   ① رشته چندخطی (textarea پنل — هر خط یک گزینه)
                   ② آرایه JSON (فراخوانی برنامه‌ای/API) */
                $rawOptions = $f['options'] ?? '';
                if (is_array($rawOptions)) {
                    foreach ($rawOptions as $o) {
                        $o = mb_substr(trim(strip_tags((string)$o)), 0, 150);
                        if ($o !== '') { $opts[] = $o; }
                    }
                } else {
                    foreach (preg_split('/\r\n|\r|\n/', (string)$rawOptions) ?: [] as $line) {
                        $line = mb_substr(trim($line), 0, 150);
                        if ($line !== '') { $opts[] = $line; }
                    }
                }
                if (!$opts) { $opts = ['گزینه ۱', 'گزینه ۲']; }
                $clean['options'] = array_slice($opts, 0, 30);
            }
            if ($type === 'number') {
                $clean['min'] = is_numeric($f['min'] ?? null) ? (float)$f['min'] : null;
                $clean['max'] = is_numeric($f['max'] ?? null) ? (float)$f['max'] : null;
            }
            if ($type === 'textarea') {
                $clean['rows'] = max(2, min(12, (int)($f['rows'] ?? 4)));
            }
            if ($type === 'consent' || $type === 'html') {
                $clean['text'] = mb_substr(trim(strip_tags((string)($f['text'] ?? ''), '<b><strong><i><em><a><span><small>')), 0, 900);
            }
            $clean['width'] = ($f['width'] ?? 'full') === 'half' ? 'half' : 'full';
            $clean['help'] = mb_substr(trim(strip_tags((string)($f['help'] ?? ''))), 0, 200);
            $clean['cssClass'] = mb_substr(trim(preg_replace('/[^a-zA-Z0-9 \-_]/', '', (string)($f['cssClass'] ?? ''))), 0, 80);
            $out[] = $clean;
            $i++;
        }
        return $out;
    }

    /**
     * 🧼 تنظیمات فرم
     */
    public static function sanitizeSettings(array $s): array
    {
        $dests = [];
        foreach ((array)($s['dest'] ?? ['panel']) as $d) {
            if (in_array($d, ['panel', 'email', 'telegram', 'bale'], true)) { $dests[] = $d; }
        }
        if (!$dests) { $dests = ['panel']; }
        return [
            'btnText'   => mb_substr(trim(strip_tags((string)($s['btnText'] ?? 'ارسال'))), 0, 60) ?: 'ارسال',
            'successMsg' => mb_substr(trim(strip_tags((string)($s['successMsg'] ?? ''))), 0, 300)
                ?: '✅ اطلاعات شما با موفقیت ثبت شد — به‌زودی با شما تماس می‌گیریم.',
            'dest'      => $dests,
            'layout'    => ($s['layout'] ?? 'two') === 'one' ? 'one' : 'two',
            'labelsFa'  => !empty($s['labelsFa']), /* برچسب فارسی فیلدها در اعلان‌ها */
        ];
    }

    /**
     * 💾 ساخت/ویرایش فرم
     * @return array ['ok'=>bool, 'id'=>int, 'error'=>string]
     */
    public function save(?int $id, array $in): array
    {
        $db = Database::getInstance();
        $title = mb_substr(trim(strip_tags((string)($in['title'] ?? ''))), 0, 190);
        if (mb_strlen($title) < 2) {
            return ['ok' => false, 'error' => 'عنوان فرم الزامی است.'];
        }
        /* اسلاگ یکتا */
        $slug = strtolower(trim((string)($in['slug'] ?? '')));
        $slug = preg_replace('/[^a-z0-9\-_]/', '', $slug) ?: '';
        if ($slug === '') {
            $slug = 'form-' . substr(bin2hex(random_bytes(4)), 0, 8);
        }
        $dup = $db->fetch('SELECT id FROM custom_forms WHERE slug = ?' . ($id ? ' AND id != ' . (int)$id : ''), [$slug]);
        if ($dup) {
            $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }
        $fields = self::sanitizeFields((array)($in['fields'] ?? []));
        if (!$fields) {
            return ['ok' => false, 'error' => 'حداقل یک فیلد اضافه کنید.'];
        }
        $settings = self::sanitizeSettings((array)($in['settings'] ?? []));
        $brandId = !empty($in['brand_id']) ? (int)$in['brand_id'] : null;
        $row = [
            'brand_id'    => $brandId,
            'title'       => $title,
            'slug'        => $slug,
            'description' => mb_substr(trim(strip_tags((string)($in['description'] ?? ''))), 0, 600) ?: null,
            'fields'      => json_encode($fields, JSON_UNESCAPED_UNICODE),
            'settings'    => json_encode($settings, JSON_UNESCAPED_UNICODE),
            'is_active'   => empty($in['is_active']) ? 0 : 1,
        ];
        if ($id) {
            $db->update('custom_forms', $row, 'id = ?', [$id]);
        } else {
            $id = (int)$db->insert('custom_forms', $row);
        }
        return ['ok' => true, 'id' => $id, 'slug' => $slug];
    }

    /** 📋 فرم کامل با اسلاگ (برای رندر سایت برند) */
    public static function bySlug(string $slug): ?array
    {
        $slug = preg_replace('/[^a-z0-9\-_]/', '', strtolower($slug)) ?? '';
        if ($slug === '') { return null; }
        $row = Database::getInstance()->fetch(
            'SELECT * FROM custom_forms WHERE slug = ? AND is_active = 1 LIMIT 1',
            [$slug]
        );
        return $row ? self::hydrate($row) : null;
    }

    /** 📋 فهرست فرم‌های فعال (برای عنصر قالب‌ساز)
     *  🚨 v2.45 (S09): فرم brand-specific فقط با brandId درست دیده می‌شد —
     *  قالب‌ساز بدون پارامتر می‌گرفت → فرم‌های اختصاصی برند هرگز در
     *  انتخابگر «فرم سفارشی» ظاهر نمی‌شدند (ریشه گزارش کاربر). */
    public static function listActive(?int $brandId = null): array
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            'SELECT id, title, slug, description, entries_count, updated_at FROM custom_forms WHERE is_active = 1 AND (brand_id IS NULL' . ($brandId ? ' OR brand_id = ' . (int)$brandId : '') . ') ORDER BY updated_at DESC LIMIT 100'
        );
        return array_map(static function ($r) {
            $r['entries_count'] = (int)$r['entries_count'];
            $r['updated_at'] = (string)$r['updated_at'];
            return $r;
        }, (array)$rows);
    }

    /** 📋 🆕 v2.45 (S09) — فهرست «همه» فرم‌های فعال با نام برند
     *  انتخابگر قالب‌ساز باید هر فرمی را نشان دهد (سراسری + اختصاصی هر
     *  برند) تا کاربر بتواند از بین همه فرم‌های ساخته‌شده انتخاب کند.
     *  نام برند کنار عنوان می‌آید تا محدوده هر فرم روشن باشد. */
    public static function listAll(): array
    {
        try {
            $rows = Database::getInstance()->fetchAll(
                'SELECT cf.id, cf.title, cf.slug, cf.description, cf.entries_count, cf.updated_at,
                        cf.brand_id, b.name_fa AS brand_name
                 FROM custom_forms cf
                 LEFT JOIN brands b ON b.id = cf.brand_id
                 WHERE cf.is_active = 1
                 ORDER BY (cf.brand_id IS NULL) DESC, cf.updated_at DESC
                 LIMIT 200'
            );
        } catch (Throwable $e) {
            return [];
        }
        return array_map(static function ($r) {
            $r['brand_id'] = $r['brand_id'] !== null ? (int)$r['brand_id'] : null;
            $r['brand_name'] = $r['brand_id'] !== null ? (string)($r['brand_name'] ?? '') : '';
            $r['entries_count'] = (int)$r['entries_count'];
            $r['updated_at'] = (string)$r['updated_at'];
            return $r;
        }, (array)$rows);
    }

    /** 💧 هیدراته کردن ردیف دیتابیس */
    public static function hydrate(array $row): array
    {
        return [
            'id'          => (int)$row['id'],
            'brand_id'    => $row['brand_id'] !== null ? (int)$row['brand_id'] : null,
            'title'       => (string)$row['title'],
            'slug'        => (string)$row['slug'],
            'description' => (string)($row['description'] ?? ''),
            'fields'      => json_decode((string)$row['fields'], true) ?: [],
            'settings'    => json_decode((string)($row['settings'] ?? '{}'), true) ?: [],
            'is_active'   => !empty($row['is_active']),
            'entries_count' => (int)($row['entries_count'] ?? 0),
        ];
    }

    /**
     * 🏷 برچسب فارسی فیلد برای ذخیره/اعلان (به‌جای نام لاتین)
     */
    public static function labeledValues(array $form, array $rawValues): array
    {
        $out = [];
        foreach ($form['fields'] as $f) {
            if (in_array($f['type'], self::STRUCTURAL_TYPES, true)) { continue; }
            $v = $rawValues[$f['name']] ?? '';
            if (is_array($v)) { $v = implode('، ', array_map('strval', $v)); }
            if (trim((string)$v) === '') { continue; }
            $out[$f['label']] = (string)$v;
        }
        return $out;
    }
}
