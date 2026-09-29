<?php
/**
 * ⚙️ ذخیره‌سازی و آپلود
 * سقف آپلود + تضمین پوشه‌های سیستمی
 * 🧩 v2.43 (S12): از config.php تک‌عظیم تفکیک شد — config.php نقش Bootstrap دارد.
 * @package SahandBrandMaker\Config
 */

define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024);   // حداکثر حجم آپلود (۱۰ مگابایت)

/* 📂 تضمین وجود پوشه‌های سیستمی (نصب تمیز/بروزرسانی) */
foreach ([UPLOADS_PATH, CACHE_PATH, LOGS_PATH] as $__dir) {
    if (!is_dir($__dir)) { @mkdir($__dir, 0755, true); }
}
unset($__dir);
