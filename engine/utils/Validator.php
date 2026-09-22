<?php
/**
 * ✅ کلاس اعتبارسنجی — موتور AI و فرم‌ها
 * ======================================
 * اعتبارسنجی ساختاری داده‌های ورودی موتور و API.
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class Validator
{
    /** @var array خطاهای اعتبارسنجی */
    private $errors = [];

    /**
     * ➕ افزودن قانون اعتبارسنجی
     *
     * @param mixed  $value مقدار تحت بررسی
     * @param string $rule  نوع قانون (required|min:3|max:100|email|mobile|phone|url|numeric|in:a,b,c)
     * @param string $label برچسب فارسی فیلد (برای پیام خطا)
     */
    public function add($value, string $rule, string $label): void
    {
        $rules = explode('|', $rule);
        foreach ($rules as $r) {
            [$name, $param] = array_pad(explode(':', $r, 2), 2, null);
            $value = is_string($value) ? trim($value) : $value;

            switch ($name) {
                case 'required':
                    if ($value === null || $value === '' || $value === []) {
                        $this->errors[] = "فیلد «{$label}» الزامی است.";
                    }
                    break;
                case 'min':
                    if ($value !== null && $value !== '' && is_string($value) && mb_strlen($value) < (int)$param) {
                        $this->errors[] = "فیلد «{$label}» باید حداقل {$param} کاراکتر باشد.";
                    }
                    break;
                case 'max':
                    if ($value !== null && is_string($value) && mb_strlen($value) > (int)$param) {
                        $this->errors[] = "فیلد «{$label}» حداکثر می‌تواند {$param} کاراکتر باشد.";
                    }
                    break;
                case 'email':
                    if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $this->errors[] = "فیلد «{$label}» باید یک ایمیل معتبر باشد.";
                    }
                    break;
                case 'mobile':
                    if ($value !== null && $value !== '' && !is_valid_iran_mobile((string)$value)) {
                        $this->errors[] = "فیلد «{$label}» باید یک شماره موبایل ایرانی معتبر باشد (مثلاً 09123456789).";
                    }
                    break;
                case 'phone':
                    if ($value !== null && $value !== '' && !is_valid_iran_phone((string)$value) && !is_valid_iran_mobile((string)$value)) {
                        $this->errors[] = "فیلد «{$label}» باید یک شماره تلفن معتبر باشد.";
                    }
                    break;
                case 'url':
                    if ($value !== null && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                        $this->errors[] = "فیلد «{$label}» باید یک آدرس URL معتبر باشد.";
                    }
                    break;
                case 'numeric':
                    if ($value !== null && $value !== '' && !is_numeric($value)) {
                        $this->errors[] = "فیلد «{$label}» باید عددی باشد.";
                    }
                    break;
                case 'in':
                    $options = explode(',', (string)$param);
                    if ($value !== null && $value !== '' && !in_array((string)$value, $options, true)) {
                        $this->errors[] = "مقدار فیلد «{$label}» معتبر نیست.";
                    }
                    break;
                case 'hex':
                    if ($value !== null && $value !== '' && !preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string)$value)) {
                        $this->errors[] = "فیلد «{$label}» باید کد رنگ HEX معتبر باشد (مثلاً #1e40af).";
                    }
                    break;
            }
        }
    }

    /**
     * ❓ آیا اعتبارسنجی موفق بوده؟
     */
    public function fails(): bool
    {
        return !empty($this->errors);
    }

    /**
     * ❓ آیا اعتبارسنجی موفق بوده؟ (معکوس fails)
     */
    public function passes(): bool
    {
        return empty($this->errors);
    }

    /**
     * 📋 دریافت خطاها
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * 📋 دریافت اولین خطا
     */
    public function firstError(): string
    {
        return $this->errors[0] ?? '';
    }

    /**
     * 🧼 پاکسازی امن خروجی HTML از ویرایشگر (لیست سفید تگ‌ها)
     * برای ذخیره محتوای WYSIWYG مقالات
     */
    public static function sanitizeHtml(string $html): string
    {
        // حذف تگ‌های خطرناک و محتوا
        $html = preg_replace('/<(script|style|iframe|object|embed|form|input|button)[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $html = preg_replace('/<(script|style|iframe|object|embed|form|input|button|link|meta)[^>]*>/is', '', $html) ?? $html;
        // حذف event handler ها
        $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html) ?? $html;
        // حذف javascript: در href
        $html = preg_replace('/href\s*=\s*(["\'])\s*javascript:[^"\']*\1/i', 'href="#"', $html) ?? $html;
        return $html;
    }
}
