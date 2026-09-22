/**
 * 📨 اسکریپت کمکی فرم درخواست
 * =============================
 * اعتبارسنجی سمت کلاینت فرمت ایرانی + تبدیل اعداد فارسی
 */
(function () {
    window.sahandValidatePhone = function (value) {
        // تبدیل اعداد فارسی/عربی به انگلیسی
        var fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        var ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        var v = String(value);
        for (var i = 0; i < 10; i++) {
            v = v.replace(new RegExp(fa[i], 'g'), i).replace(new RegExp(ar[i], 'g'), i);
        }
        return /^(\+98|0098|98|0)?9\d{9}$|^0\d{9,10}$/.test(v.trim());
    };
})();
