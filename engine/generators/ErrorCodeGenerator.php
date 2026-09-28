<?php
/**
 * 🚨 ژنراتور کدهای خطا — تولید کدهای خطای رایج دستگاه‌ها
 * =========================================================
 * با استفاده از پایگاه دانش مرجع، کدهای خطای رایج هر
 * دستگاه را برای برند تولید و ثبت می‌کند.
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class ErrorCodeGenerator
{
    /** @var Database دیتابیس */
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * 🚨 تولید کدهای خطای رایج برای دستگاه‌های یک برند
     *
     * @param int   $brandId شناسه برند
     * @param bool  $overwrite رونویسی کدهای قبلی
     * @return int تعداد کدهای ثبت‌شده
     */
    public function generateForBrand(int $brandId, bool $overwrite = false): int
    {
        $devices = $this->db->fetchAll(
            'SELECT device_key, name_fa FROM brand_devices WHERE brand_id = ? AND is_active = 1',
            [$brandId]
        );
        if (empty($devices)) {
            return 0;
        }

        if ($overwrite) {
            $this->db->delete('error_codes', 'brand_id = ?', [$brandId]);
        }

        $knowledge = TextProcessor::loadKnowledge('error-codes');
        $count = 0;

        foreach ($devices as $device) {
            $codes = $knowledge[$device['device_key']] ?? [];
            foreach ($codes as $code) {
                // 🔍 جلوگیری از درج تکراری
                $exists = $this->db->fetchValue(
                    'SELECT COUNT(*) FROM error_codes WHERE brand_id = ? AND device_key = ? AND code = ?',
                    [$brandId, $device['device_key'], $code['code']]
                );
                if ((int)$exists) {
                    continue;
                }
                $this->db->insert('error_codes', [
                    'brand_id'         => $brandId,
                    'device_key'       => $device['device_key'],
                    'code'             => $code['code'],
                    'title'            => $code['title'],
                    'description'      => $code['description'] ?? '',
                    'causes'           => json_encode($code['causes'] ?? [], JSON_UNESCAPED_UNICODE),
                    'solutions'        => json_encode($code['solutions'] ?? [], JSON_UNESCAPED_UNICODE),
                    'severity'         => $code['severity'] ?? 'medium',
                    'needs_technician' => (int)($code['needs_technician'] ?? true),
                    'is_active'        => 1,
                ]);
                $count++;
            }
        }
        return $count;
    }

    /**
     * 📥 ورود گروهی کد خطا از آرایه (استاندارد JSON/Excel)
     *
     * @param int   $brandId شناسه برند (null = عمومی)
     * @param array $codes آرایه استاندارد کدها
     * @return array ['imported' => int, 'skipped' => int, 'errors' => []]
     */
    public function import(int $brandId, array $codes): array
    {
        $result = ['imported' => 0, 'skipped' => 0, 'errors' => []];
        /* 🆕 v2.33 — «اطلاعاتی» هم سطح معتبر است (قبلاً فقط ۴ سطح بود و
           کدهای informational هنگام ورود فایل به medium تنزل می‌یافتند) */
        $validSeverities = ['low', 'medium', 'high', 'critical', 'informational'];

        foreach ($codes as $i => $code) {
            /* ✅ اعتبارسنجی — پذیرش هم کلیدهای قدیمی و هم کلیدهای خروجیِ استاندارد */
            $codeValue   = trim((string)($code['code'] ?? ''));
            $titleValue  = trim((string)($code['title_fa'] ?? $code['title'] ?? ''));
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

            // تشخیص کلید دستگاه از نام (یا پذیرش کلید استاندارد)
            $deviceKey = $this->resolveDeviceKey($deviceValue);

            // 🔍 جلوگیری از تکرار
            $exists = $this->db->fetchValue(
                'SELECT COUNT(*) FROM error_codes WHERE (brand_id = ? OR brand_id IS NULL) AND device_key = ? AND code = ?',
                [$brandId, $deviceKey, clean_input((string)$code['code'])]
            );
            if ((int)$exists) {
                $result['skipped']++;
                continue;
            }

            /* 📋 مدل‌ها — رشته (هر خط/کاما یک مدل) یا آرایه */
            $modelsRaw = $code['models'] ?? [];
            if (is_string($modelsRaw)) {
                $modelsRaw = preg_split('/[\r\n,،]+/u', $modelsRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            }
            $models = array_values(array_filter(array_map(
                fn($m) => trim(clean_input((string)$m)),
                (array)$modelsRaw
            )));

            /* 🏷 دسته‌بندی چندمقداری — آرایه با «،» ادغام می‌شود */
            $catRaw = $code['category'] ?? 'سایر';
            $category = is_array($catRaw)
                ? implode('، ', array_map(fn($x) => clean_input((string)$x), $catRaw))
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
            $causesList    = $toList($code['causes'] ?? []);
            $solutionsList = $toList($code['solutions'] ?? []);

            /* 🧬 تشخیص زیرسیستم علّی — برای انسجام علت‌ها (v2.33) */
            $subsystem = null;
            $needsReview = 0;
            if (class_exists('ErrorCodeSubsystem')) {
                $sub = ErrorCodeSubsystem::resolve($deviceKey, [
                    'code'      => $codeValue,
                    'title'     => $titleValue,
                    'part'      => (string)($code['related_part'] ?? ''),
                    'category'  => $category,
                    'subsystem' => (string)($code['subsystem'] ?? ''),
                ]);
                if (!empty($sub['key'])) {
                    $subsystem = (string)$sub['key'];
                    if (in_array((string)($sub['confidence'] ?? ''), ['low', 'none'], true)) {
                        $needsReview = 1;
                    }
                    /* اگر فیلدهای فنی خالی آمده‌اند، از زیرسیستمِ همین کد پر شوند */
                    if ($category === 'سایر') {
                        $subCat = ErrorCodeSubsystem::category($sub['data']);
                        if ($subCat !== '') {
                            $category = $subCat;
                        }
                    }
                    if (trim((string)($code['related_part'] ?? '')) === '') {
                        $subPart = ErrorCodeSubsystem::part($sub['data'], $titleValue);
                        if ($subPart !== '') {
                            $code['related_part'] = $subPart;
                        }
                    }
                    if (trim((string)($code['tech_specs'] ?? '')) === '') {
                        $subSpecs = ErrorCodeSubsystem::specs($sub['data']);
                        if ($subSpecs !== '') {
                            $code['tech_specs'] = $subSpecs;
                        }
                    }
                    if (trim((string)($code['part_location'] ?? '')) === '') {
                        $subLoc = ErrorCodeSubsystem::location($sub['data']);
                        if ($subLoc !== '') {
                            $code['part_location'] = $subLoc;
                        }
                    }
                } else {
                    $needsReview = 1;
                }
            }

            $row = [
                'brand_id'         => $brandId ?: null,
                'device_key'       => $deviceKey,
                'code'             => clean_input($codeValue),
                'title'            => clean_input($titleValue),
                'description'      => clean_input((string)($code['description_fa'] ?? $code['description'] ?? '')),
                'causes'           => json_encode($causesList, JSON_UNESCAPED_UNICODE),
                'solutions'        => json_encode($solutionsList, JSON_UNESCAPED_UNICODE),
                'severity'         => $severity,
                'needs_technician' => isset($code['needs_technician']) ? (int)(bool)$code['needs_technician'] : 1,
                /* 🆕 v2.33 — شش فیلد تکمیلی (قبلاً هنگام ورود فایل دور ریخته می‌شدند) */
                'subtype'          => clean_input((string)($code['subtype'] ?? 'همه زیرنوع‌ها')) ?: 'همه زیرنوع‌ها',
                'models'           => json_encode($models, JSON_UNESCAPED_UNICODE),
                'category'         => $category,
                'related_part'     => clean_input((string)($code['related_part'] ?? '')),
                'tech_specs'       => clean_input((string)($code['tech_specs'] ?? '')),
                'part_location'    => clean_input((string)($code['part_location'] ?? '')),
                'is_active'        => 1,
            ];
            if ($subsystem !== null) {
                $row['subsystem'] = $subsystem;
            }
            $row['needs_review'] = $needsReview;

            /* 🔐 هش یکتایی محتوا — تشخیص کپی در سطح سیستم */
            if (function_exists('error_code_content_hash')) {
                $row['content_hash'] = error_code_content_hash([
                    'title'       => $row['title'],
                    'description' => $row['description'],
                    'causes'      => $causesList,
                    'solutions'   => $solutionsList,
                ]);
            }

            $this->db->insert('error_codes', $row);
            $result['imported']++;
        }
        return $result;
    }

    /**
     * 🧩 پارس فایل JSON استاندارد کدهای خطا
     */
    public function parseJsonFile(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }
        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }
        // دو ساختار پشتیبانی می‌شود: {error_codes:[...]} یا [...]
        return $data['error_codes'] ?? $data;
    }

    /**
     * 📊 پارس فایل Excel (xlsx) بدون وابستگی خارجی
     * خواندن xlsx به عنوان آرشیو ZIP و استخراج sheet1.xml
     */
    public function parseExcelFile(string $filePath): array
    {
        if (!class_exists('ZipArchive')) {
            return [];
        }
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return [];
        }

        // 📖 خواندن sharedStrings (مقادیر متنی سلول‌ها)
        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            preg_match_all('/<si>(.*?)<\/si>/s', $ssXml, $siMatches);
            foreach ($siMatches[1] ?? [] as $si) {
                preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $si, $tMatches);
                $shared[] = implode('', array_map('html_entity_decode', $tMatches[1] ?? []));
            }
        }

        // 📖 خواندن صفحه اول
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheetXml === false) {
            return [];
        }

        // 🧩 استخراج ردیف‌ها
        $rows = [];
        preg_match_all('/<row[^>]*>(.*?)<\/row>/s', $sheetXml, $rowMatches);
        foreach ($rowMatches[1] ?? [] as $rowXml) {
            $cells = [];
            preg_match_all('/<c([^>]*)>(.*?)<\/c>|<c([^>]*)\/>/', $rowXml, $cellMatches, PREG_SET_ORDER);
            foreach ($cellMatches as $cm) {
                $attrs = $cm[1] ?? $cm[3] ?? '';
                $inner = $cm[2] ?? '';
                $type = preg_match('/t="([^"]*)"/', $attrs, $tm) ? $tm[1] : '';
                $value = '';
                if (preg_match('/<v>(.*?)<\/v>/', $inner, $vm)) {
                    $value = $vm[1];
                    if ($type === 's') {
                        $value = $shared[(int)$value] ?? $value; // ایندکس sharedStrings
                    }
                } elseif (preg_match('/<is><t[^>]*>(.*?)<\/t><\/is>/s', $inner, $im)) {
                    $value = html_entity_decode($im[1]);
                }
                $cells[] = (string)$value;
            }
            if (!empty($cells)) {
                $rows[] = $cells;
            }
        }
        if (count($rows) < 2) {
            return [];
        }

        // 🏷️ سطر اول = هدرها
        $headers = array_map(fn($h) => mb_strtolower(trim($h)), array_shift($rows));
        $codes = [];
        foreach ($rows as $row) {
            $record = [];
            foreach ($headers as $i => $header) {
                $record[$header] = $row[$i] ?? '';
            }
            // فیلدهای لیستی (causes/solutions) با جداکننده | یا ؛
            foreach (['causes', 'solutions'] as $listField) {
                if (!empty($record[$listField]) && is_string($record[$listField])) {
                    $record[$listField] = array_values(array_filter(array_map('trim', preg_split('/[|؛;]/u', $record[$listField]))));
                }
            }
            if (isset($record['needs_technician'])) {
                $record['needs_technician'] = in_array(mb_strtolower((string)$record['needs_technician']), ['1', 'true', 'بله', 'yes', 'درست'], true);
            }
            if (isset($record['severity'])) {
                $map = ['کم' => 'low', 'متوسط' => 'medium', 'زیاد' => 'high', 'بحرانی' => 'critical'];
                $fa = $record['severity'];
                $record['severity'] = $map[$fa] ?? $fa;
            }
            $codes[] = $record;
        }
        return $codes;
    }

    /**
     * 📥 قالب نمونه CSV برای دانلود (راهنمای مدیر)
     */
    public static function sampleCsv(): string
    {
        return "device,code,title,description,causes,solutions,severity,needs_technician\n"
            . "لباسشویی,E1,خطای تخلیه آب,دستگاه قادر به تخلیه آب نیست,گرفتگی فیلتر تخلیه | خرابی پمپ تخلیه,تمیز کردن فیلتر تخلیه | تماس با تعمیرکار,زیاد,بله\n"
            . "یخچال,E2,خطای دماسنج,دمای مناسب حفظ نمی‌شود,خرابی سنسور دما | یخ‌زدگی اواپراتور,بررسی سنسور | تماس با تکنسین,متوسط,بله\n";
    }

    /**
     * 🔤 تبدیل نام فارسی دستگاه به کلید استاندارد
     */
    private function resolveDeviceKey(string $deviceName): string
    {
        $devices = TextProcessor::loadKnowledge('devices');
        $raw = trim($deviceName);

        /* 🆕 v2.33 — اگر ورودی خودش کلید استاندارد پایگاه دانش است، همان را برگردان
           (ریشه‌ی مشکل: خروجیِ استاندارد device_key می‌فرستاد اما این تابع فقط
            نام فارسی را جستجو می‌کرد و در نهایت یک کلیدِ اسلاگِ اشتباه می‌ساخت) */
        if (isset($devices[$raw])) {
            return $raw;
        }

        /* 🆕 نگاشت کلیدهای سایت‌ساز به کلیدهای پایگاه دانش devices.json */
        $alias = [
            'washing_machine' => 'washing_machine',
            'refrigerator'    => 'refrigerator',
            'dishwasher'      => 'dishwasher',
            'air_conditioner' => 'air_conditioner',
            'dryer'           => 'dryer',
            'microwave'       => 'microwave',
            'oven'            => 'oven',
            'stove'           => 'stove',
            'hood'            => 'range_hood',
            'water_heater'    => 'water_heater',
            'package'         => 'package',
            'television'      => 'tv',
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
        // اگر یافت نشد، خود نام به عنوان کلید ذخیره می‌شود
        return SlugGenerator::generate($raw);
    }
}
