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

    /** 📋 فهرست فرم‌های فعال (برای عنصر قالب‌ساز) */
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
