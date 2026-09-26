<?php
/**
 * 📝 فرم ثبت درخواست خدمات آنلاین
 * ==================================
 * مطابق بخش ۵.۹ سند — ۱۰ فیلد + اعتبارسنجی ایرانی + AJAX
 * @package SahandBrandSite
 */
define('BRAND_INIT', true);
require_once __DIR__ . '/../config.php';

// 📥 دستگاه‌های برند برای لیست کشویی
$devices = fetchFromAPI('brand/' . BRAND_ID . '/devices')['data'] ?? [];
$pageData = fetchFromAPI('brand/' . BRAND_ID . '/page/request');
$pageTitle = 'ثبت درخواست خدمات آنلاین | ' . BRAND_NAME_FA;
$pageDesc = 'فرم آنلاین ثبت درخواست تعمیر ' . BRAND_NAME_FA . ' — پاسخگویی سریع و اعزام تکنسین';
$crumbTitle = 'ثبت درخواست';
require __DIR__ . '/_page_base.php';
?>
<section class="section">
    <div class="container request-page">
        <h1 class="page-title">📝 ثبت درخواست خدمات آنلاین</h1>
        <p class="page-intro">فرم زیر را تکمیل کنید — کارشناسان ما در اولین فرصت با شما تماس خواهند گرفت. هزینه پس از بررسی و ایرادیابی دستگاه اعلام می‌شود.</p>

        <form id="request-form" class="request-form" novalidate>
            <div class="form-row">
                <div class="form-group">
                    <label>نام و نام خانوادگی <span class="req">*</span></label>
                    <input type="text" name="full_name" required minlength="3" placeholder="مثلاً علی محمدی">
                </div>
                <div class="form-group">
                    <label>شماره تماس <span class="req">*</span></label>
                    <input type="tel" name="phone" required pattern="(\+98|0098|98|0)?9\d{9}|0\d{9,10}" placeholder="09123456789" dir="ltr">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>شماره تماس دوم (اختیاری)</label>
                    <input type="tel" name="phone2" placeholder="09123456789" dir="ltr">
                </div>
                <div class="form-group">
                    <label>نوع دستگاه <span class="req">*</span></label>
                    <select name="device_type" required id="device-select">
                        <option value="">انتخاب کنید...</option>
                        <?php foreach ($devices as $device): ?>
                            <option value="<?= e($device['device_key']) ?>"><?= e($device['name_fa']) ?></option>
                        <?php endforeach; ?>
                        <option value="other">سایر</option>
                    </select>
                </div>
            </div>
            <!-- 📌 فیلد سایر — فقط با انتخاب گزینه «سایر» نمایان می‌شود (الزام سند) -->
            <div class="form-group hidden" id="other-device-box">
                <label>نام دستگاه <span class="req">*</span></label>
                <input type="text" name="device_other" placeholder="نوع دستگاه را بنویسید">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>مدل دستگاه (اختیاری)</label>
                    <input type="text" name="device_model" placeholder="مثلاً WS12T440">
                </div>
                <div class="form-group">
                    <label>زمان مراجعه ترجیحی (اختیاری)</label>
                    <div style="display:flex;gap:8px">
                        <input type="date" name="preferred_date" style="flex:1">
                        <select name="preferred_time" style="flex:1">
                            <option value="">بازه ساعتی...</option>
                            <option>۹ تا ۱۲</option><option>۱۲ تا ۱۵</option>
                            <option>۱۵ تا ۱۸</option><option>۱۸ تا ۲۱</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>آدرس <span class="req">*</span></label>
                <textarea name="address" required rows="2" placeholder="آدرس دقیق خود را وارد کنید"></textarea>
            </div>
            <div class="form-group">
                <label>شرح ایراد <span class="req">*</span></label>
                <textarea name="description" required minlength="10" rows="4" placeholder="مشکل دستگاه را توضیح دهید..."></textarea>
            </div>
            <div class="form-group">
                <label>تصویر دستگاه (اختیاری — حداکثر ۳ تصویر)</label>
                <input type="file" name="images" accept="image/*" multiple id="images-input">
                <div class="hint">در صورت امکان، از صفحه نمایش خطا یا محل ایراد عکس بگیرید.</div>
                <div id="images-preview" class="images-preview"></div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg btn-block" id="submit-btn">🚀 ثبت درخواست</button>
        </form>

        <!-- ✅ نتیجه -->
        <div id="request-result" class="request-result hidden">
            <div class="result-icon">✅</div>
            <h2>درخواست شما ثبت شد!</h2>
            <p id="result-message"></p>
            <p class="result-id" id="result-id"></p>
            <a href="/" class="btn btn-outline">بازگشت به صفحه اصلی</a>
        </div>
    </div>
</section>
<script>
/* 📨 ارسال فرم درخواست با AJAX */
(function () {
    var form = document.getElementById('request-form');
    var deviceSelect = document.getElementById('device-select');
    var otherBox = document.getElementById('other-device-box');

    // 📌 نمایش/مخفی فیلد «سایر»
    deviceSelect.addEventListener('change', function () {
        otherBox.classList.toggle('hidden', this.value !== 'other');
    });

    // 🖼️ پیش‌نمایش تصاویر
    document.getElementById('images-input').addEventListener('change', function () {
        var preview = document.getElementById('images-preview');
        preview.innerHTML = '';
        Array.from(this.files).slice(0, 3).forEach(function (file) {
            var img = document.createElement('img');
            img.src = URL.createObjectURL(file);
            preview.appendChild(img);
        });
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = document.getElementById('submit-btn');
        btn.disabled = true;
        btn.innerHTML = '⏳ در حال ارسال...';

        var data = {};
        new FormData(form).forEach(function (v, k) { data[k] = v; });

        /* 🚀 v2.25 — زنجیره سه‌مرحله‌ای ارسال:
           ① پروکسی همان‌مبدأ /js/form-submit.php (بدون CORS/SSL مرورگر — مسیر اصلی)
           ② ارسال مستقیم به API سایت ساز (پشتیبان — برای بسته‌های قدیمی‌تر)
           ③ پیام خطای دقیق و اقدام‌پذیر به کاربر */
        var apiBase = <?= json_encode((string)BRANDMAKER_API) ?>;
        function postJson(url, body) {
            return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
                .then(function (r) {
                    if (!r.ok && r.status !== 422 && r.status !== 401 && r.status !== 429) { throw new Error('http-' + r.status); }
                    return r.text();
                })
                .then(function (txt) {
                    try { return JSON.parse(txt); }
                    catch (err) { throw new Error('bad-json'); }
                });
        }
        postJson('/js/form-submit.php', data)
            .catch(function () {
                /* پروکسی موجود نبود (بسته قدیمی) یا خطا داد → مسیر مستقیم */
                return postJson(apiBase + '/brand/<?= (int)BRAND_ID ?>/request',
                    Object.assign({}, data, { api_key: <?= json_encode((string)BRAND_API_KEY) ?> }));
            })
            .then(function (res) {
                if (res && res.success) {
                    form.classList.add('hidden');
                    document.getElementById('request-result').classList.remove('hidden');
                    document.getElementById('result-message').textContent = (res.data && res.data.message) || 'درخواست شما با موفقیت ثبت شد.';
                    document.getElementById('result-id').textContent = res.data && res.data.request_id ? 'کد پیگیری: ' + res.data.request_id : '';
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } else {
                    var msg = (res && (res.error || (res.errors || []).join('\n'))) || 'خطا در ثبت درخواست';
                    alert(msg);
                    btn.disabled = false;
                    btn.innerHTML = '🚀 ثبت درخواست';
                }
            })
            .catch(function () {
                alert('خطای ارتباط با سرور — لطفاً اتصال اینترنت را بررسی کرده و دوباره تلاش کنید.\nاگر خطا تکرار شد با شماره تماس سایت تماس بگیرید.');
                btn.disabled = false;
                btn.innerHTML = '🚀 ثبت درخواست';
            });
    });
})();
</script>
<?php require __DIR__ . '/../includes/floating-btn.php'; require __DIR__ . '/../includes/footer.php'; ?>
