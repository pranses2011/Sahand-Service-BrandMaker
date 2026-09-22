/**
 * 📜 اسکریپت Google Apps Script — واسط ارسال تلگرام در زمان تحریم
 * =================================================================
 *
 * ⚠️ نکته حیاتی مطابق الزامات سند:
 *   Bot Token و Chat ID در این اسکریپت ذخیره نمی‌شوند!
 *   این مقادیر با هر درخواست به عنوان پارامتر POST ارسال می‌شوند
 *   و از تنظیمات سایت ساز خوانده می‌شوند.
 *
 * 📖 راهنمای نصب (کامل در مستندات):
 *   1. به script.google.com بروید و «پروژه جدید» بسازید
 *   2. کل این فایل را در ویرایشگر کپی کنید
 *   3. Deploy → New deployment → Type: Web app
 *      - Execute as: Me
 *      - Who has access: Anyone
 *   4. آدرس Web App را کپی و در تنظیمات سایت ساز (بخش واسط گوگل) وارد کنید
 */

/**
 * 📨 نقطه ورود Web App — دریافت درخواست POST از سایت ساز
 */
function doPost(e) {
  try {
    // 📥 پارامترهای ارسالی از سایت ساز
    var params = JSON.parse(e.postData.contents);

    // ✅ اعتبارسنجی پارامترهای الزامی
    if (!params.bot_token || !params.chat_id || !params.message) {
      return jsonResult({
        success: false,
        error: 'پارامترهای bot_token، chat_id و message الزامی هستند'
      });
    }

    // 🔐 اعتبارسنجی ساده امنیتی (کلید امضا اختیاری)
    if (params.secret && params.secret !== '') {
      var expectedSecret = PropertiesService.getScriptProperties().getProperty('SHARED_SECRET');
      if (expectedSecret && params.secret !== expectedSecret) {
        return jsonResult({ success: false, error: 'کلید امنیتی نامعتبر است' });
      }
    }

    // 📤 ارسال پیام به تلگرام با فرمت HTML
    var telegramUrl = 'https://api.telegram.org/bot' + params.bot_token + '/sendMessage';
    var payload = {
      chat_id: params.chat_id,
      text: params.message,
      parse_mode: 'HTML',
      disable_web_page_preview: false
    };

    // اگر لوگو ارسال شده، ابتدا عکس ارسال شود
    if (params.photo_url) {
      try {
        UrlFetchApp.fetch('https://api.telegram.org/bot' + params.bot_token + '/sendPhoto', {
          method: 'post',
          contentType: 'application/json',
          payload: JSON.stringify({
            chat_id: params.chat_id,
            photo: params.photo_url,
            caption: params.caption || ''
          }),
          muteHttpExceptions: true
        });
      } catch (photoErr) {
        // خطای عکس مانع ارسال متن نمی‌شود
        console.warn('خطای ارسال عکس: ' + photoErr);
      }
    }

    // 📤 ارسال پیام متنی
    var response = UrlFetchApp.fetch(telegramUrl, {
      method: 'post',
      contentType: 'application/json',
      payload: JSON.stringify(payload),
      muteHttpExceptions: true
    });

    var result = JSON.parse(response.getContentText());

    if (result.ok) {
      return jsonResult({ success: true, message_id: result.result.message_id });
    } else {
      return jsonResult({ success: false, error: result.description || 'خطای تلگرام' });
    }

  } catch (err) {
    // 🚨 مدیریت خطا — پاسخ خطای ساختاریافته
    return jsonResult({ success: false, error: err.toString() });
  }
}

/**
 * 🧪 تست سلامت — درخواست GET برای بررسی دسترسی
 */
function doGet() {
  return jsonResult({
    success: true,
    service: 'Sahand Service — Telegram Bridge',
    version: '1.0.0',
    note: 'این سرویس آماده است. درخواست‌ها را به صورت POST ارسال کنید.'
  });
}

/**
 * 📋 پاسخ JSON استاندارد
 */
function jsonResult(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}

/**
 * 🔧 راهنمای فرمت درخواست POST (برای توسعه‌دهندگان):
 *
 * POST /exec
 * Content-Type: application/json
 *
 * {
 *   "bot_token": "123456:ABC-DEF...",   ← از تنظیمات سایت ساز
 *   "chat_id": "-1001234567890",         ← از تنظیمات سایت ساز
 *   "message": "<b>📨 درخواست جدید</b>\n...",  ← متن HTML فرمت‌شده
 *   "photo_url": "https://.../logo.png", ← اختیاری: لوگوی برند
 *   "caption": "برند: سامسونگ",          ← اختیاری
 *   "secret": ""                         ← اختیاری: کلید امنیتی
 * }
 */
