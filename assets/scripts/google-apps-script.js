/**
 * 📜 واسط تلگرام — Google Apps Script v3 (سایت‌ساز برند سهند سرویس)
 * =================================================================
 *
 * کاربرد: عبور از تحریم/فیلترینگ — سرورهای گوگل بدون محدودیت به تلگرام می‌رسند.
 *
 * 🆕 نسخه ۳ (v2.9 سایت‌ساز):
 *   ✅ پشتیبانی کامل آپلود فایل (files_b64 → Blob → multipart به تلگرام)
 *   ✅ پروب getRelayCapabilities — پنل سایت‌ساز می‌فهمد اسکریپت به‌روز است یا قدیمی
 *   ✅ مسیر ورودی وب‌هوک: تلگرام → این اسکریپت → سایت‌ساز
 *   ✅ گزارش نسخه در doGet
 *   💡 نکته: سایت‌ساز v2.9 سندها را «با آدرس URL» هم ارسال می‌کند (فقط JSON)
 *      که حتی با اسکریپت‌های قدیمی هم کار می‌کند — اما برای اطمینان کامل
 *      همین نسخه را Deploy کنید تا آپلود مستقیم فایل هم پشتیبانی شود.
 *
 * ⚠️ نکته حیاتی: Bot Token در این اسکریپت ذخیره نمی‌شود؛ با هر درخواست
 *    به‌عنوان پارامتر ارسال می‌شود و از تنظیمات سایت‌ساز خوانده می‌شود.
 *
 * 🔧 راه‌اندازی (۳ دقیقه):
 *   ۱. به script.google.com بروید و «پروژه جدید» بسازید
 *   ۲. کل این فایل را در Code.gs بچسبانید
 *   ۳. دو مقدار زیر را ویرایش کنید (SECRET = همان «راز مشترک» پنل سایت‌ساز):
 *        var SECRET = 'یک-راز-تصادفی-دلخواه';
 *        var SITE_WEBHOOK = 'https://آدرس-سایت-ساز-شما/api/telegram/webhook';
 *   ۴. Deploy → New deployment → Type: Web app
 *      - Execute as: Me
 *      - Who has access: Anyone
 *   ۵. آدرس /exec را کپی و در پنل سایت‌ساز (کارت واسط گوگل) وارد کنید
 *   ۶. در پنل، «تست واسط» را بزنید — باید «✅ ارسال فایل پشتیبانی می‌شود (نسخه ۳)» ببینید
 */

var SECRET = 'این-مقدار-را-با-راز-مشترک-پنل-سایت-ساز-یکسان-کنید';
var SITE_WEBHOOK = ''; // خالی = وب‌هوک ورودی غیرفعال
var RELAY_VERSION = 3;

/**
 * 📨 نقطه ورود Web App — دریافت درخواست POST از سایت‌ساز یا تلگرام
 */
function doPost(e) {
  try {
    var body = JSON.parse(e.postData.contents);

    /* --- ۱) مسیر خروجی: سایت‌ساز → تلگرام --- */
    if (body.method && body.token) {
      if (SECRET !== '' && body.secret !== SECRET) {
        return out({ ok: false, description: 'bad secret' });
      }
      /* 🔎 پروب نسخه/توانایی */
      if (body.method === 'getRelayCapabilities') {
        return out({ ok: true, result: { relay_version: RELAY_VERSION, supports_files: true } });
      }
      /* 📎 آپلود فایل — فایل‌ها base64 می‌آیند و به Blob تبدیل می‌شوند */
      if (body.files_b64) {
        var params = body.params || {};
        for (var field in body.files_b64) {
          var bytes = Utilities.base64Decode(body.files_b64[field]);
          var name = (body.files_name && body.files_name[field]) ? body.files_name[field] : field;
          var mime = (body.files_mime && body.files_mime[field]) ? body.files_mime[field] : 'application/octet-stream';
          /* نام فایل باید پسوند داشته باشد تا تلگرام سند را درست بپذیرد */
          if (name.indexOf('.') === -1) {
            name = name + '.' + (mime === 'image/png' ? 'png' : mime === 'image/jpeg' ? 'jpg' : 'bin');
          }
          params[field] = Utilities.newBlob(bytes, mime, name);
        }
        var tgU = UrlFetchApp.fetch('https://api.telegram.org/bot' + body.token + '/' + body.method, {
          method: 'post',
          payload: params,
          muteHttpExceptions: true
        });
        return out(JSON.parse(tgU.getContentText()));
      }
      /* پیام ساده — فقط JSON */
      var tg = UrlFetchApp.fetch('https://api.telegram.org/bot' + body.token + '/' + body.method, {
        method: 'post',
        contentType: 'application/json',
        payload: JSON.stringify(body.params || {}),
        muteHttpExceptions: true
      });
      return out(JSON.parse(tg.getContentText()));
    }

    /* --- ۲) مسیر ورودی: تلگرام → سایت‌ساز (وب‌هوک) --- */
    if (body.update_id !== undefined) {
      if (SITE_WEBHOOK === '') {
        return out({ ok: true });
      }
      var res = UrlFetchApp.fetch(SITE_WEBHOOK, {
        method: 'post',
        contentType: 'application/json',
        headers: { 'X-Telegram-Bot-Api-Secret-Token': SECRET },
        payload: JSON.stringify(body),
        muteHttpExceptions: true
      });
      return out(JSON.parse(res.getContentText() || '{"ok":true}'));
    }

    return out({ ok: false, description: 'unknown payload' });
  } catch (err) {
    return out({ ok: false, relay_error: String(err) });
  }
}

/**
 * 🧪 تست سلامت — درخواست GET
 */
function doGet() {
  return out({
    ok: true,
    service: 'Sahand Telegram Relay',
    version: RELAY_VERSION,
    supports_files: true,
    note: 'این سرویس آماده است. درخواست‌ها را به صورت POST ارسال کنید.'
  });
}

/**
 * 📋 پاسخ JSON استاندارد
 */
function out(obj) {
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
 *   "secret": "…",                    ← راز مشترک (اختیاری اگر SECRET خالی باشد)
 *   "method": "sendMessage",          ← متد Bot API تلگرام
 *   "token":  "123456:ABC-DEF...",    ← از تنظیمات سایت‌ساز
 *   "params": { "chat_id": "-100…", "text": "سلام" },
 *   "files_b64":  { "document": "base64…" },   ← اختیاری: آپلود فایل
 *   "files_name": { "document": "article.html" },
 *   "files_mime": { "document": "text/html" }
 * }
 */
