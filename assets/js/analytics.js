/* 📈 منطق داشبورد آمار — P2-23: از admin/analytics.php جدا شد
 * (قبلاً ~۱۰۸۸ خط JS درون‌خطی داخل فایل PHP بود)
 * وابستگی: بوت‌استرپ AD (آرایه داده) باید قبل از این فایل لود شود
 * @package SahandBrandMaker
 */

/* ═══ 🔄 v2.32 — بازحسابی جغرافیایی ═══
   کش جغرافیایی پاک + بازحلابی پیشوندها از سرویس‌های معتبر + حذف
   داده‌های حدسی قدیمی (رفع «کاربر تبریز → خراسان رضوی»). */
function geoRecompute(btn) {
    if (!confirm('کش جغرافیایی پاک و بازدیدهای اخیر دوباره از سرویس‌های معتبر حلابی می‌شوند.\nجواب‌های حدسی قدیمی حذف و با بازدید بعدی درست می‌شوند.\n\nادامه می‌دهید؟ (تا ۲ دقیقه طول می‌کشد)')) { return; }
    const msg = document.getElementById('geo-recompute-msg');
    btn.disabled = true;
    const old = btn.textContent;
    btn.textContent = '⏳ در حال بازحسابی...';
    if (msg) {
        msg.style.display = 'block';
        msg.className = 'alert alert-info';
        msg.innerHTML = '⏳ در حال بازحسابی جغرافیایی — لطفاً این برگه را نبندید...';
    }
    const body = new URLSearchParams({ action: 'geo_recompute' });
    const csrfEl = document.querySelector('input[name="csrf_token"]');
    if (csrfEl) { body.append('csrf_token', csrfEl.value); }
    fetch('analytics.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
    }).then(function (r) { return r.json(); }).then(function (res) {
        btn.disabled = false;
        btn.textContent = old;
        if (msg) {
            msg.style.display = 'block';
            msg.className = 'alert ' + (res && res.success ? 'alert-success' : 'alert-danger');
            msg.innerHTML = (res && (res.data && res.data.message || res.error)) || 'پاسخ نامعتبر';
        }
        if (res && res.success) { setTimeout(function () { location.reload(); }, 2600); }
    }).catch(function () {
        btn.disabled = false;
        btn.textContent = old;
        if (msg) {
            msg.style.display = 'block';
            msg.className = 'alert alert-danger';
            msg.innerHTML = 'خطای ارتباط با سرور — دوباره تلاش کنید.';
        }
    });
}

/* 🔄 v2.30 — همه نمودارها در یک تابع؛ تغییر اندازه پنجره → بازترسیم
   (قبلاً نمودار فقط یک‌بار در لود اول با عرض لحظه‌ای ترسیم می‌شد) */
function sahandDrawCharts() {

/* 📈 نمودار خطی روند بازدید */
(function () {
    const canvas = document.getElementById('visits-chart');
    if (!canvas) return;
    const data = AD[0];
    if (!data.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">📉</div><p>هنوز بازدیدی ثبت نشده است.</p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    canvas.width = w * dpr; canvas.height = 240 * dpr;
    ctx.scale(dpr, dpr);
    const pad = { t: 15, r: 15, b: 28, l: 40 };
    const cw = w - pad.l - pad.r, ch = 240 - pad.t - pad.b;
    const maxV = Math.max(...data.map(d => d.v), 4);
    // خطوط راهنما
    ctx.strokeStyle = '#e2e8f0'; ctx.lineWidth = 1;
    for (let i = 0; i <= 4; i++) {
        const y = pad.t + ch - (ch * i / 4);
        ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(pad.l + cw, y); ctx.stroke();
        ctx.fillStyle = '#64748b'; ctx.font = '10px Tahoma'; ctx.textAlign = 'right';
        ctx.fillText(Math.round(maxV * i / 4), pad.l - 6, y + 3);
    }
    const xAt = i => pad.l + (data.length === 1 ? cw / 2 : cw * i / (data.length - 1));
    const yAt = v => pad.t + ch - (ch * v / maxV);
    // ناحیه زیر نمودار
    const gradient = ctx.createLinearGradient(0, pad.t, 0, pad.t + ch);
    gradient.addColorStop(0, 'rgba(30,64,175,.22)'); gradient.addColorStop(1, 'rgba(30,64,175,0)');
    ctx.beginPath(); ctx.moveTo(xAt(0), yAt(data[0].v));
    data.forEach((d, i) => ctx.lineTo(xAt(i), yAt(d.v)));
    ctx.lineTo(xAt(data.length - 1), pad.t + ch); ctx.lineTo(xAt(0), pad.t + ch); ctx.closePath();
    ctx.fillStyle = gradient; ctx.fill();
    // خط بازدید کل
    ctx.beginPath(); ctx.strokeStyle = '#1e40af'; ctx.lineWidth = 2.2;
    data.forEach((d, i) => i ? ctx.lineTo(xAt(i), yAt(d.v)) : ctx.moveTo(xAt(i), yAt(d.v)));
    ctx.stroke();
    // خط بازدید یکتا
    ctx.beginPath(); ctx.strokeStyle = '#16a34a'; ctx.lineWidth = 1.8; ctx.setLineDash([5, 4]);
    data.forEach((d, i) => i ? ctx.lineTo(xAt(i), yAt(d.u)) : ctx.moveTo(xAt(i), yAt(d.u)));
    ctx.stroke(); ctx.setLineDash([]);
    // تاریخ‌ها
    ctx.fillStyle = '#64748b'; ctx.font = '9px Tahoma'; ctx.textAlign = 'center';
    const step = Math.ceil(data.length / 7);
    data.forEach((d, i) => { if (i % step === 0) ctx.fillText(d.date.slice(5), xAt(i), 232); });
})();

/* ═══════════════════════════════════════════════════════════════
 * 🆕 v2.29 — نمودارهای جدید: ساعتی / روز هفته / منابع ورود / جدید-بازگشتی
 * بدون وابستگی خارجی — Canvas خالص با گرادیانت و میله‌های گرد
 * ═══════════════════════════════════════════════════════════════ */

/* 🔧 ابزار مشترک میله‌ای افقی/عمودی با میله‌های گرد و گرادیانت */
function sahandBars(canvasId, data, opts) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    if (!data || !data.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">📊</div><p>داده‌ای موجود نیست.</p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    const H = opts.height || 200;
    canvas.width = w * dpr; canvas.height = H * dpr;
    ctx.scale(dpr, dpr);
    const horizontal = !!opts.horizontal;
    const pad = { t: 14, r: 14, b: 26, l: horizontal ? 110 : 34 };
    const cw = w - pad.l - pad.r, ch = H - pad.t - pad.b;
    const maxV = Math.max(...data.map(d => d.v), 4);
    const c1 = opts.color1 || '#1e40af', c2 = opts.color2 || '#3b82f6';
    data.forEach((d, i) => {
        const grad = horizontal
            ? ctx.createLinearGradient(pad.l, 0, pad.l + cw, 0)
            : ctx.createLinearGradient(0, pad.t + ch, 0, pad.t);
        grad.addColorStop(0, c1); grad.addColorStop(1, c2);
        ctx.fillStyle = grad;
        if (horizontal) {
            const bh = Math.min(26, ch / data.length - 7);
            const y = pad.t + i * (ch / data.length) + (ch / data.length - bh) / 2;
            const bw = Math.max(3, d.v / maxV * cw);
            ctx.beginPath();
            const rr = Math.min(bh / 2, 7);
            ctx.moveTo(pad.l + rr, y); ctx.arcTo(pad.l + bw, y, pad.l + bw, y + bh, rr);
            ctx.arcTo(pad.l + bw, y + bh, pad.l, y + bh, rr); ctx.arcTo(pad.l, y + bh, pad.l, y, rr);
            ctx.arcTo(pad.l, y, pad.l + bw, y, rr); ctx.closePath(); ctx.fill();
            ctx.fillStyle = '#1e293b'; ctx.font = 'bold 11.5px Vazirmatn, Tahoma'; ctx.textAlign = 'right';
            ctx.fillText(d.l, pad.l - 8, y + bh / 2 + 4);
            ctx.fillStyle = '#64748b'; ctx.font = '10.5px Vazirmatn, Tahoma'; ctx.textAlign = 'left';
            ctx.fillText(new Intl.NumberFormat('fa-IR').format(d.v), pad.l + bw + 6, y + bh / 2 + 4);
        } else {
            const bw = Math.min(38, cw / data.length - 6);
            const x = pad.l + i * (cw / data.length) + (cw / data.length - bw) / 2;
            const bh = Math.max(3, d.v / maxV * ch);
            const y = pad.t + ch - bh;
            ctx.beginPath();
            const rr = Math.min(6, bw / 2);
            ctx.moveTo(x + rr, y); ctx.arcTo(x + bw, y, x + bw, y + bh, rr);
            ctx.arcTo(x + bw, y + bh, x, y + bh, 0); ctx.lineTo(x, pad.t + ch);
            ctx.arcTo(x, y + bh, x, y, rr); ctx.closePath(); ctx.fill();
            ctx.fillStyle = '#64748b'; ctx.font = '10px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
            ctx.fillText(d.l, x + bw / 2, H - 8);
            if (d.v > 0) {
                ctx.fillStyle = '#1e40af'; ctx.font = 'bold 10px Vazirmatn, Tahoma';
                ctx.fillText(new Intl.NumberFormat('fa-IR').format(d.v), x + bw / 2, y - 4);
            }
        }
    });
}

/* 🕐 ساعات شبانه‌روز */
(function () {
    const hMap = AD[1];
    const labels = AD[2];
    sahandBars('hours-chart', hMap.map((v, h) => ({ l: labels[h], v })), { height: 200 });
})();

/* 📅 روزهای هفته */
(function () {
    const wdMap = AD[3];
    const wdFa = AD[4];
    sahandBars('weekday-chart', wdMap.map((v, d) => ({ l: wdFa[d], v })), { height: 200, color1: '#0f766e', color2: '#14b8a6' });
})();

/* 🔗 منابع ورود */
(function () {
    const refs = AD[5];
    const refLabels = AD[6];
    sahandBars('referrers-chart', refLabels.map((l, i) => ({ l, v: refs[i] })), { height: 230, horizontal: true, color1: '#7c2d12', color2: '#f59e0b' });
})();

/* 🔄 جدید در برابر بازگشتی — دونات */
(function () {
    const canvas = document.getElementById('newret-chart');
    if (!canvas) return;
    const nv = AD[7], rv = AD[8];
    if (nv + rv === 0) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">🔄</div><p>داده‌ای موجود نیست.</p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    canvas.width = w * dpr; canvas.height = 175 * dpr;
    ctx.scale(dpr, dpr);
    const total = nv + rv;
    const cx = w * 0.5, cy = 87, r = 66;
    let angle = -Math.PI / 2;
    [[nv, '#1e40af', 'جدید'], [rv, '#16a34a', 'بازگشتی']].forEach(([val, color]) => {
        const slice = (val / total) * Math.PI * 2;
        ctx.beginPath(); ctx.moveTo(cx, cy); ctx.arc(cx, cy, r, angle, angle + slice); ctx.closePath();
        ctx.fillStyle = color; ctx.fill();
        angle += slice;
    });
    ctx.beginPath(); ctx.arc(cx, cy, r * 0.58, 0, Math.PI * 2); ctx.fillStyle = '#fff'; ctx.fill();
    ctx.fillStyle = '#1e293b'; ctx.font = 'bold 17px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
    ctx.fillText(new Intl.NumberFormat('fa-IR').format(total), cx, cy + 6);
    ctx.font = '11px Vazirmatn, Tahoma';
    const nw = Math.round(nv / total * 100), rw = 100 - nw;
    ctx.textAlign = 'right'; ctx.fillStyle = '#1e40af';
    ctx.fillText('🆕 جدید: ' + new Intl.NumberFormat('fa-IR').format(nv) + ' (' + new Intl.NumberFormat('fa-IR').format(nw) + '٪)', w - 14, 26);
    ctx.fillStyle = '#16a34a';
    ctx.fillText('🔁 بازگشتی: ' + new Intl.NumberFormat('fa-IR').format(rv) + ' (' + new Intl.NumberFormat('fa-IR').format(rw) + '٪)', w - 14, 48);
})();

/* 🥧 نمودار دایره‌ای سهم برندها */
(function () {
    const canvas = document.getElementById('brands-chart');
    if (!canvas) return;
    const data = AD[9];
    if (!data.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">🥧</div><p>داده‌ای موجود نیست.</p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    canvas.width = w * dpr; canvas.height = 260 * dpr;
    ctx.scale(dpr, dpr);
    const colors = ['#1e40af', '#16a34a', '#f59e0b', '#0891b2', '#7c3aed', '#dc2626', '#db2777', '#4d7c0f'];
    const total = data.reduce((s, d) => s + d.value, 0);
    const cx = w * 0.32, cy = 130, r = 82;
    let angle = -Math.PI / 2;
    data.forEach((d, i) => {
        const slice = (d.value / total) * Math.PI * 2;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, angle, angle + slice);
        ctx.closePath();
        ctx.fillStyle = colors[i % colors.length];
        ctx.fill();
        angle += slice;
    });
    // حفره مرکزی (دونات)
    ctx.beginPath(); ctx.arc(cx, cy, r * 0.55, 0, Math.PI * 2);
    ctx.fillStyle = '#fff'; ctx.fill();
    ctx.fillStyle = '#1e293b'; ctx.font = 'bold 15px Tahoma'; ctx.textAlign = 'center';
    ctx.fillText(new Intl.NumberFormat('fa-IR').format(total), cx, cy + 5);
    // راهنما
    ctx.textAlign = 'right'; ctx.font = '12px Tahoma';
    data.forEach((d, i) => {
        const y = 40 + i * 24;
        ctx.fillStyle = colors[i % colors.length];
        ctx.fillRect(w * 0.62, y - 9, 13, 13);
        ctx.fillStyle = '#1e293b';
        ctx.fillText(d.label + ' (' + new Intl.NumberFormat('fa-IR').format(d.value) + ')', w - 10, y + 2);
    });
})();

/* ═══════════════════════════════════════════════════════════════
 * 🆕 v2.30 — نمودارهای جدید: حرارتی / کشورها / مقایسه / مدت / ورود-خروج
 * ═══════════════════════════════════════════════════════════════ */

/* 🔥 نقشه حرارتی ساعت × روز هفته */
(function () {
    const canvas = document.getElementById('heatmap-chart');
    if (!canvas) return;
    const heat = AD[10];
    const wdFa = AD[4];
    const faNum = n => new Intl.NumberFormat('fa-IR').format(n);
    const totalCells = heat.flat().filter(v => v > 0).length;
    if (!totalCells) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">🔥</div><p>داده‌ای برای نقشه حرارتی موجود نیست.</p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    const H = 250;
    canvas.width = w * dpr; canvas.height = H * dpr;
    ctx.scale(dpr, dpr);
    const pad = { t: 26, r: 14, b: 26, l: 58 };
    const cw = w - pad.l - pad.r, ch = H - pad.t - pad.b;
    const cellW = cw / 24, cellH = ch / 7;
    const maxC = Math.max(...heat.flat(), 1);
    /* خط‌کش ساعت (بالای شبکه) */
    ctx.fillStyle = '#64748b'; ctx.font = '9px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
    for (let h = 0; h < 24; h += 3) {
        ctx.fillText(String(h).padStart(2, '0'), pad.l + cellW * (h + 0.5), pad.t - 7);
    }
    for (let d = 0; d < 7; d++) {
        /* برچسب روز (سمت راست — RTL) */
        ctx.fillStyle = '#334155'; ctx.font = 'bold 10.5px Vazirmatn, Tahoma'; ctx.textAlign = 'right';
        ctx.fillText(wdFa[d], w - pad.r, pad.t + cellH * (d + 0.5) + 4);
        for (let h = 0; h < 24; h++) {
            const v = heat[d][h] || 0;
            const t = Math.pow(v / maxC, 0.65);
            const x = w - pad.r - cellW * (h + 1) + 1.2; /* RTL: ساعت از راست */
            const y = pad.t + cellH * d + 1.2;
            ctx.fillStyle = v > 0 ? 'rgba(30,64,175,' + (0.1 + 0.9 * t).toFixed(2) + ')' : '#f1f5f9';
            ctx.beginPath();
            const rr = Math.min(3.5, cellW / 3);
            ctx.moveTo(x + rr, y); ctx.arcTo(x + cellW - 2.4, y, x + cellW - 2.4, y + cellH - 2.4, rr);
            ctx.arcTo(x + cellW - 2.4, y + cellH - 2.4, x, y + cellH - 2.4, rr);
            ctx.arcTo(x, y + cellH - 2.4, x, y, rr); ctx.arcTo(x, y, x + cellW - 2.4, y, rr);
            ctx.closePath(); ctx.fill();
            if (v / maxC >= 0.55) {
                ctx.fillStyle = '#fff'; ctx.font = 'bold 9.5px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
                ctx.fillText(faNum(v), x + (cellW - 2.4) / 2, y + cellH / 2 + 3.5);
            }
        }
    }
})();

/* 🌍 دونات پراکندگی کشورها */
(function () {
    const canvas = document.getElementById('countries-chart');
    if (!canvas) return;
    const data = AD[11];
    if (!data.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">🌍</div><p>داده‌ای موجود نیست.</p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    canvas.width = w * dpr; canvas.height = 230 * dpr;
    ctx.scale(dpr, dpr);
    const colors = ['#1e40af', '#0891b2', '#16a34a', '#f59e0b', '#7c3aed', '#dc2626', '#db2777', '#4d7c0f'];
    const total = data.reduce((s, d) => s + d.value, 0);
    const cx = w * 0.30, cy = 115, r = 76;
    let angle = -Math.PI / 2;
    data.forEach((d, i) => {
        const slice = (d.value / total) * Math.PI * 2;
        ctx.beginPath();
        ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, angle, angle + slice);
        ctx.closePath();
        ctx.fillStyle = colors[i % colors.length];
        ctx.fill();
        /* فاصله بین قاچ‌ها */
        ctx.strokeStyle = '#fff'; ctx.lineWidth = 2.4; ctx.stroke();
        angle += slice;
    });
    ctx.beginPath(); ctx.arc(cx, cy, r * 0.56, 0, Math.PI * 2);
    ctx.fillStyle = '#fff'; ctx.fill();
    ctx.fillStyle = '#1e293b'; ctx.font = 'bold 15px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
    ctx.fillText(new Intl.NumberFormat('fa-IR').format(total), cx, cy + 5);
    ctx.font = '9.5px Vazirmatn, Tahoma'; ctx.fillStyle = '#64748b';
    ctx.fillText('بازدیدکننده', cx, cy + 20);
    /* راهنما */
    ctx.textAlign = 'right'; ctx.font = '11.5px Vazirmatn, Tahoma';
    data.forEach((d, i) => {
        const y = 34 + i * 25;
        ctx.fillStyle = colors[i % colors.length];
        ctx.fillRect(w * 0.60, y - 9, 13, 13);
        ctx.fillStyle = '#1e293b';
        ctx.fillText(d.label + ' — ' + new Intl.NumberFormat('fa-IR').format(d.value) + ' (' + Math.round(d.value / total * 100) + '٪)', w - 8, y + 2);
    });
})();

/* 📊 میله‌های جفتی مقایسه دوره جاری با دوره قبل */
(function () {
    const canvas = document.getElementById('compare-chart');
    if (!canvas) return;
    const cur = { u: AD[12], v: AD[13], b: AD[8] };
    const prev = { u: AD[14], v: AD[15] };
    const faNum = n => new Intl.NumberFormat('fa-IR').format(n);
    const rows = [
        { l: 'بازدیدکننده یکتا', a: cur.u, b: prev.u },
        { l: 'بازدید صفحات', a: cur.v, b: prev.b !== undefined ? prev.v : 0 },
    ];
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    const H = 230;
    canvas.width = w * dpr; canvas.height = H * dpr;
    ctx.scale(dpr, dpr);
    const pad = { t: 34, r: 14, b: 26, l: 14 };
    const cw = w - pad.l - pad.r, ch = H - pad.t - pad.b;
    const maxV = Math.max(cur.u, cur.v, prev.u, prev.v, 4);
    /* راهنمای رنگ */
    ctx.font = 'bold 11px Vazirmatn, Tahoma'; ctx.textAlign = 'right';
    ctx.fillStyle = '#1e40af'; ctx.fillRect(w - 150, 8, 12, 12);
    ctx.fillStyle = '#1e293b'; ctx.fillText('دوره جاری', w - 158, 18);
    ctx.fillStyle = '#cbd5e1'; ctx.fillRect(w - 260, 8, 12, 12);
    ctx.fillStyle = '#1e293b'; ctx.fillText('دوره قبل', w - 268, 18);
    const groupW = cw / rows.length;
    rows.forEach((row, gi) => {
        const gx = w - pad.r - groupW * (gi + 1); /* RTL */
        const barW = Math.min(44, groupW / 2 - 14);
        const h1 = row.a / maxV * ch, h2 = row.b / maxV * ch;
        const xA = gx + groupW / 2 + 3, xB = gx + groupW / 2 - barW - 3;
        /* میله دوره جاری (راست) */
        const g1 = ctx.createLinearGradient(0, pad.t + ch - h1, 0, pad.t);
        g1.addColorStop(0, '#1e40af'); g1.addColorStop(1, '#3b82f6');
        ctx.fillStyle = g1;
        ctx.beginPath(); ctx.moveTo(xA + 6, pad.t + ch - h1);
        ctx.arcTo(xA + barW, pad.t + ch - h1, xA + barW, pad.t + ch, 6);
        ctx.lineTo(xA + barW, pad.t + ch); ctx.lineTo(xA, pad.t + ch);
        ctx.arcTo(xA, pad.t + ch, xA, pad.t + ch - h1, 6); ctx.closePath(); ctx.fill();
        /* میله دوره قبل (چپ) */
        ctx.fillStyle = '#cbd5e1';
        ctx.beginPath(); ctx.moveTo(xB + 6, pad.t + ch - h2);
        ctx.arcTo(xB + barW, pad.t + ch - h2, xB + barW, pad.t + ch, 6);
        ctx.lineTo(xB + barW, pad.t + ch); ctx.lineTo(xB, pad.t + ch);
        ctx.arcTo(xB, pad.t + ch, xB, pad.t + ch - h2, 6); ctx.closePath(); ctx.fill();
        /* اعداد بالای میله‌ها */
        ctx.fillStyle = '#1e40af'; ctx.font = 'bold 11.5px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
        ctx.fillText(faNum(row.a), xA + barW / 2, pad.t + ch - h1 - 6);
        ctx.fillStyle = '#64748b'; ctx.font = '10.5px Vazirmatn, Tahoma';
        ctx.fillText(faNum(row.b), xB + barW / 2, pad.t + ch - h2 - 6);
        /* برچسب گروه */
        ctx.fillStyle = '#334155'; ctx.font = 'bold 11.5px Vazirmatn, Tahoma';
        ctx.fillText(row.l, gx + groupW / 2, H - 8);
        /* خط صفر */
        ctx.strokeStyle = '#e2e8f0'; ctx.lineWidth = 1;
        ctx.beginPath(); ctx.moveTo(pad.l, pad.t + ch); ctx.lineTo(w - pad.r, pad.t + ch); ctx.stroke();
    });
})();

/* ⏱ روند میانگین مدت حضور */
(function () {
    const canvas = document.getElementById('duration-chart');
    if (!canvas) return;
    const data = AD[16];
    if (!data.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">⏱</div><p>هنوز داده مدت حضور ثبت نشده است.<br><small>با خروج کاربر از سایت، مدت حضور واقعی ثبت می‌شود.</small></p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    canvas.width = w * dpr; canvas.height = 210 * dpr;
    ctx.scale(dpr, dpr);
    const pad = { t: 15, r: 15, b: 26, l: 44 };
    const cw = w - pad.l - pad.r, ch = 210 - pad.t - pad.b;
    const maxV = Math.max(...data.map(d => d.v), 10);
    const xAt = i => pad.l + (data.length === 1 ? cw / 2 : cw * i / (data.length - 1));
    const yAt = v => pad.t + ch - (ch * v / maxV);
    for (let i = 0; i <= 4; i++) {
        const y = pad.t + ch - (ch * i / 4);
        ctx.strokeStyle = '#e2e8f0'; ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(pad.l + cw, y); ctx.stroke();
        ctx.fillStyle = '#64748b'; ctx.font = '9.5px Vazirmatn, Tahoma'; ctx.textAlign = 'right';
        ctx.fillText(Math.round(maxV * i / 4), pad.l - 5, y + 3);
    }
    const gradient = ctx.createLinearGradient(0, pad.t, 0, pad.t + ch);
    gradient.addColorStop(0, 'rgba(13,148,136,.25)'); gradient.addColorStop(1, 'rgba(13,148,136,0)');
    ctx.beginPath(); ctx.moveTo(xAt(0), yAt(data[0].v));
    data.forEach((d, i) => ctx.lineTo(xAt(i), yAt(d.v)));
    ctx.lineTo(xAt(data.length - 1), pad.t + ch); ctx.lineTo(xAt(0), pad.t + ch); ctx.closePath();
    ctx.fillStyle = gradient; ctx.fill();
    ctx.beginPath(); ctx.strokeStyle = '#0d9488'; ctx.lineWidth = 2.2;
    data.forEach((d, i) => i ? ctx.lineTo(xAt(i), yAt(d.v)) : ctx.moveTo(xAt(i), yAt(d.v)));
    ctx.stroke();
    /* نقطه‌ها */
    data.forEach((d, i) => {
        ctx.beginPath(); ctx.arc(xAt(i), yAt(d.v), 3, 0, Math.PI * 2);
        ctx.fillStyle = '#0d9488'; ctx.fill();
        ctx.strokeStyle = '#fff'; ctx.lineWidth = 1.4; ctx.stroke();
    });
    ctx.fillStyle = '#64748b'; ctx.font = '9px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
    const step = Math.ceil(data.length / 6);
    data.forEach((d, i) => { if (i % step === 0) ctx.fillText(d.date.slice(5), xAt(i), 200); });
})();

/* 🚪 صفحات ورود و خروج — میله‌های افقی جفتی */
(function () {
    const canvas = document.getElementById('entryexit-chart');
    if (!canvas) return;
    const entries = AD[17];
    const exits = AD[18];
    if (!entries.length && !exits.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">🚪</div><p>داده ورود/خروج ثبت نشده است.</p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    const H = 230;
    canvas.width = w * dpr; canvas.height = H * dpr;
    ctx.scale(dpr, dpr);
    const faNum = n => new Intl.NumberFormat('fa-IR').format(n);
    const pad = { t: 24, r: 118, b: 12, l: 60 };
    const cw = w - pad.l - pad.r;
    /* نیمه راست: ورود | نیمه چپ: خروج */
    const half = cw / 2 - 8;
    const maxE = Math.max(...entries.map(d => d.v), 1);
    const maxX = Math.max(...exits.map(d => d.v), 1);
    const rowH = Math.min(26, (H - pad.t - pad.b) / Math.max(entries.length, exits.length, 1) - 4);
    ctx.font = 'bold 10.5px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
    ctx.fillStyle = '#16a34a'; ctx.fillText('⬅️ ورود', w - pad.r - half / 2, 12);
    ctx.fillStyle = '#dc2626'; ctx.fillText('خروج ➡️', pad.l + half / 2, 12);
    entries.slice(0, 7).forEach((d, i) => {
        const y = pad.t + i * (rowH + 4);
        const bw = Math.max(3, d.v / maxE * (half - 46));
        const x = w - pad.r - bw;
        const g = ctx.createLinearGradient(x, 0, w - pad.r, 0);
        g.addColorStop(0, '#15803d'); g.addColorStop(1, '#4ade80');
        ctx.fillStyle = g;
        ctx.beginPath();
        const rr = Math.min(rowH / 2, 6);
        ctx.moveTo(x + rr, y); ctx.arcTo(w - pad.r, y, w - pad.r, y + rowH, rr);
        ctx.arcTo(w - pad.r, y + rowH, x, y + rowH, rr); ctx.arcTo(x, y + rowH, x, y, rr);
        ctx.arcTo(x, y, w - pad.r, y, rr); ctx.closePath(); ctx.fill();
        ctx.fillStyle = '#334155'; ctx.font = '10px Vazirmatn, Tahoma'; ctx.textAlign = 'left';
        ctx.fillText(d.l, w - pad.r + 6, y + rowH / 2 + 3.5);
        ctx.fillStyle = '#15803d'; ctx.font = 'bold 9.5px Vazirmatn, Tahoma'; ctx.textAlign = 'right';
        ctx.fillText(faNum(d.v), x - 4, y + rowH / 2 + 3.5);
    });
    exits.slice(0, 7).forEach((d, i) => {
        const y = pad.t + i * (rowH + 4);
        const bw = Math.max(3, d.v / maxX * (half - 46));
        const g = ctx.createLinearGradient(pad.l, 0, pad.l + bw, 0);
        g.addColorStop(0, '#f87171'); g.addColorStop(1, '#b91c1c');
        ctx.fillStyle = g;
        ctx.beginPath();
        const rr = Math.min(rowH / 2, 6);
        ctx.moveTo(pad.l + rr, y); ctx.arcTo(pad.l + bw, y, pad.l + bw, y + rowH, rr);
        ctx.arcTo(pad.l + bw, y + rowH, pad.l, y + rowH, rr); ctx.arcTo(pad.l, y + rowH, pad.l, y, rr);
        ctx.arcTo(pad.l, y, pad.l + bw, y, rr); ctx.closePath(); ctx.fill();
        ctx.fillStyle = '#334155'; ctx.font = '10px Vazirmatn, Tahoma'; ctx.textAlign = 'right';
        ctx.fillText(d.l, pad.l - 6, y + rowH / 2 + 3.5);
        ctx.fillStyle = '#b91c1c'; ctx.font = 'bold 9.5px Vazirmatn, Tahoma'; ctx.textAlign = 'left';
        ctx.fillText(faNum(d.v), pad.l + bw + 4, y + rowH / 2 + 3.5);
    });
})();

/* ═══════════════════════════════════════════════════════════════
 * 🆕 v2.31 — نمودارهای جدید: رشد هفتگی آبشاری + عملکرد مقالات
 * ═══════════════════════════════════════════════════════════════ */

/* 📈 رشد هفتگی — میله‌های رنگی با خط روند */
(function () {
    const canvas = document.getElementById('weekly-chart');
    if (!canvas) return;
    const data = AD[19];
    if (!data.length) {
        canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">📈</div><p>داده هفتگی موجود نیست.</p></div>';
        return;
    }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    const H = 230;
    canvas.width = w * dpr; canvas.height = H * dpr;
    ctx.scale(dpr, dpr);
    const faNum = n => new Intl.NumberFormat('fa-IR').format(n);
    const pad = { t: 22, r: 12, b: 30, l: 38 };
    const cw = w - pad.l - pad.r, ch = H - pad.t - pad.b;
    const maxV = Math.max(...data.map(d => d.u), 4);
    for (let i = 0; i <= 3; i++) {
        const y = pad.t + ch - (ch * i / 3);
        ctx.strokeStyle = '#e2e8f0'; ctx.beginPath(); ctx.moveTo(pad.l, y); ctx.lineTo(pad.l + cw, y); ctx.stroke();
        ctx.fillStyle = '#64748b'; ctx.font = '9.5px Vazirmatn, Tahoma'; ctx.textAlign = 'right';
        ctx.fillText(Math.round(maxV * i / 3), pad.l - 5, y + 3);
    }
    const bw = Math.min(40, cw / data.length - 10);
    data.forEach((d, i) => {
        const x = pad.l + cw - (i + 0.5) * (cw / data.length) - bw / 2; /* RTL */
        const bh = Math.max(3, d.u / maxV * ch);
        const y = pad.t + ch - bh;
        /* رنگ میله بر اساس رشد نسبت به هفته قبل */
        const prev = i > 0 ? data[i - 1].u : d.u;
        const up = d.u >= prev;
        const grad = ctx.createLinearGradient(0, y, 0, pad.t + ch);
        if (up) { grad.addColorStop(0, '#1e40af'); grad.addColorStop(1, '#60a5fa'); }
        else { grad.addColorStop(0, '#b45309'); grad.addColorStop(1, '#fbbf24'); }
        ctx.fillStyle = grad;
        ctx.beginPath();
        const rr = Math.min(7, bw / 2);
        ctx.moveTo(x + rr, y); ctx.arcTo(x + bw, y, x + bw, y + bh, rr);
        ctx.lineTo(x + bw, y + bh); ctx.lineTo(x, y + bh);
        ctx.arcTo(x, y + bh, x, y, rr); ctx.arcTo(x, y, x + bw, y, rr); ctx.closePath(); ctx.fill();
        ctx.fillStyle = '#334155'; ctx.font = 'bold 10px Vazirmatn, Tahoma'; ctx.textAlign = 'center';
        ctx.fillText(faNum(d.u), x + bw / 2, y - 4);
        ctx.fillStyle = '#64748b'; ctx.font = '9px Vazirmatn, Tahoma';
        ctx.fillText(d.label, x + bw / 2, H - 10);
    });
    /* خط روند */
    ctx.beginPath(); ctx.strokeStyle = '#0d9488'; ctx.lineWidth = 2; ctx.setLineDash([]);
    data.forEach((d, i) => {
        const x = pad.l + cw - (i + 0.5) * (cw / data.length);
        const y = pad.t + ch - Math.max(3, d.u / maxV * ch);
        i ? ctx.lineTo(x, y) : ctx.moveTo(x, y);
    });
    ctx.stroke();
})();

/* 📰 عملکرد مقالات — میله‌های افقی با مدت مطالعه */
(function () {
    const canvas = document.getElementById('articles-chart');
    if (!canvas) return;
    const data = AD[20];
    if (!data.length) { return; }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    const H = Math.max(140, data.length * 34);
    canvas.width = w * dpr; canvas.height = H * dpr;
    ctx.scale(dpr, dpr);
    const faNum = n => new Intl.NumberFormat('fa-IR').format(n);
    const pad = { t: 12, r: 14, b: 10, l: 190 };
    const cw = w - pad.l - pad.r, ch = H - pad.t - pad.b;
    const maxV = Math.max(...data.map(d => d.v), 1);
    data.forEach((d, i) => {
        const rowH = ch / data.length;
        const y = pad.t + i * rowH + 3;
        const bh = rowH - 8;
        const bw = Math.max(4, d.v / maxV * (cw - 60));
        const x = w - pad.r - bw;
        const grad = ctx.createLinearGradient(x, 0, w - pad.r, 0);
        grad.addColorStop(0, '#7c2d12'); grad.addColorStop(1, '#fb923c');
        ctx.fillStyle = grad;
        ctx.beginPath();
        const rr = Math.min(bh / 2, 6);
        ctx.moveTo(x + rr, y); ctx.arcTo(w - pad.r, y, w - pad.r, y + bh, rr);
        ctx.arcTo(w - pad.r, y + bh, x, y + bh, rr); ctx.arcTo(x, y + bh, x, y, rr);
        ctx.arcTo(x, y, w - pad.r, y, rr); ctx.closePath(); ctx.fill();
        ctx.fillStyle = '#334155'; ctx.font = '10px Vazirmatn, Tahoma'; ctx.textAlign = 'right';
        ctx.fillText(d.l, w - pad.r + 4, y + bh / 2 + 3.5);
        ctx.fillStyle = '#7c2d12'; ctx.font = 'bold 10.5px Vazirmatn, Tahoma'; ctx.textAlign = 'left';
        ctx.fillText(faNum(d.v) + (d.dur > 0 ? ' · ' + faNum(Math.round(d.dur)) + 'ث' : ''), x - 5, y + bh / 2 + 3.5);
    });
})();

/* ═══════════════════════════════════════════════════════════════
 * 👥 v2.31 — کاربران آنلاین: بارگذاری زنده + کادر جزئیات
 * ═══════════════════════════════════════════════════════════════ */
window.sahandLoadOnlineUsers = function () {
    const body = document.getElementById('online-users-body');
    const badge = document.getElementById('online-badge');
    if (!body) return;
    fetch('analytics-online.php?ajax=1' + AD[21], { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function (res) {
            if (!res || !res.success) { return; }
            const faNum = n => new Intl.NumberFormat('fa-IR').format(n);
            if (badge) {
                badge.textContent = '🟢 ' + faNum(res.count) + ' نفر آنلاین';
                badge.className = 'badge ' + (res.count > 0 ? 'badge-success' : '');
            }
            if (!res.users || !res.users.length) {
                body.innerHTML = '<div class="empty-state" style="padding:18px"><div class="icon">👤</div><p>در ۵ دقیقه اخیر کاربر فعالی روی سایت‌های برند مشاهده نشده است.</p></div>';
                return;
            }
            let html = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px">';
            res.users.forEach(function (u) {
                const devIcon = { mobile: '📱', desktop: '🖥️', tablet: '📲', bot: '🤖' }[u.device_type] || '🖥️';
                html += '<button type="button" class="online-user-btn" data-session="' + u.session + '" style="text-align:right;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:10px 13px;cursor:pointer;font-family:inherit;transition:all .15s">'
                    + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:5px">'
                    + '<span style="font-size:19px">' + devIcon + '</span>'
                    + '<b style="font-size:12.5px;color:#0f172a">' + (u.brand_name || 'برند') + '</b>'
                    + '<span style="margin-inline-start:auto;font-size:10.5px;color:#16a34a;font-weight:700">● ' + (u.minutes_ago === 0 ? 'همین حالا' : faNum(u.minutes_ago) + ' دقیقه پیش') + '</span>'
                    + '</div>'
                    + '<div style="font-size:11px;color:#475569">📄 ' + (u.current_page || '—') + '</div>'
                    + '<div style="font-size:11px;color:#475569">📍 ' + (u.city || u.province || 'نامشخص') + ' · ' + (u.browser || '—') + '</div>'
                    + '</button>';
            });
            html += '</div>';
            body.innerHTML = html;
            body.querySelectorAll('.online-user-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    sahandShowOnlineDetails(btn.getAttribute('data-session'));
                });
            });
        })
        .catch(function () { /* بی‌صدا — تلاش بعدی در ۳۰ ثانیه */ });
};

window.sahandShowOnlineDetails = function (sessionHash) {
    fetch('analytics-online.php?ajax=1&details=' + encodeURIComponent(sessionHash) + AD[21], { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
        .then(function (res) {
            if (!res || !res.success || !res.details) {
                if (window.SahandDialog) { SahandDialog.dialog({ title: '👤 جزئیات کاربر آنلاین', html: '<div class="empty-state" style="padding:18px"><div class="icon">🔇</div><p>نشست دیگر فعال نیست یا یافت نشد — فهرست هر ۳۰ ثانیه بروزرسانی می‌شود.</p></div>', buttons: [{ text: 'بستن', btn: 'primary' }] }); }
                return;
            }
            const d = res.details;
            const faNum = n => new Intl.NumberFormat('fa-IR').format(n);
            const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
            /* 🎨 هدر پروفایل: آواتار دستگاه + برند + نشان زنده */
            const devEmo = { mobile: '📱', desktop: '🖥️', tablet: '📲', bot: '🤖' }[d.device_raw] || '🖥️';
            let html = '<div style="display:flex;align-items:center;gap:12px;background:linear-gradient(135deg,#eef2ff,#e0f2fe);border:1px solid #c7d2fe;border-radius:14px;padding:12px 14px;margin-bottom:13px">'
                + '<div style="width:52px;height:52px;border-radius:14px;background:#fff;border:1px solid #dbeafe;display:flex;align-items:center;justify-content:center;font-size:26px;flex:none;box-shadow:0 2px 8px rgba(30,64,175,.12)">' + devEmo + '</div>'
                + '<div style="flex:1;min-width:0">'
                + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">'
                + (d.brand_logo ? '<img src="' + esc(d.brand_logo) + '" alt="" style="width:22px;height:22px;border-radius:6px;object-fit:contain;background:#fff;border:1px solid #e2e8f0">' : '')
                + '<b style="font-size:14.5px;color:#0f172a">' + esc(d.brand_name || 'برند') + '</b>'
                + (d.brand_domain ? '<span style="font-size:10.5px;color:#64748b;direction:ltr">' + esc(d.brand_domain) + '</span>' : '')
                + '<span style="display:inline-flex;align-items:center;gap:4px;font-size:10px;font-weight:800;color:#15803d;background:#dcfce7;border:1px solid #bbf7d0;border-radius:20px;padding:2px 9px"><span style="width:7px;height:7px;border-radius:50%;background:#22c55e;display:inline-block;box-shadow:0 0 0 3px rgba(34,197,94,.18)"></span> آنلاین</span>'
                + '</div>'
                + '<div style="font-size:11.5px;color:#475569;margin-top:4px">⏱️ ' + esc(d.duration_text) + ' حضور · 📄 ' + faNum(d.pages_seen) + ' صفحه در این بازدید' + (d.visit_count > 1 ? ' · 🔁 ' + faNum(d.visit_count) + ' بازدید تا امروز' : ' · 🆕 بازدیدکننده امروز') + '</div>'
                + '</div></div>';
            /* 📊 چهار شاخص کلیدی */
            const lastTime = (d.last_seen || '—').split(' ')[1] || (d.last_seen || '—');
            html += '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:13px">'
                + [['⏱️', d.duration_text, 'مدت حضور'], ['📄', faNum(d.pages_seen), 'صفحات'], ['🔁', faNum(d.visit_count), 'بازدیدها'], ['🕒', lastTime, 'ساعت آخرین فعالیت']]
                    .map(c => '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:11px;padding:9px 6px;text-align:center"><div style="font-size:17px">' + c[0] + '</div><div style="font-size:13px;font-weight:900;color:#0f172a;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(c[1]) + '</div><div style="font-size:9.5px;color:#64748b;margin-top:2px">' + c[2] + '</div></div>').join('')
                + '</div>';
            /* 🗂 مشخصات کامل */
            const row = (k, v, ltr) => '<div style="background:#fff;border:1px solid #eef2f7;border-radius:10px;padding:8px 11px"><div style="font-size:10px;color:#64748b;margin-bottom:3px">' + k + '</div><b style="font-size:12px;color:#0f172a;' + (ltr ? 'direction:ltr;display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap' : '') + '">' + (v ? esc(v) : '—') + '</b></div>';
            html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px">';
            html += row('📱 دستگاه', d.device_type);
            html += row('🌐 مرورگر', d.browser);
            html += row('💻 سیستم‌عامل', d.os);
            html += row('🖥️ رزولوشن', d.resolution, true);
            html += row('🗣️ زبان', d.language, true);
            html += row('📍 مکان', [d.city, d.province, d.country].filter(Boolean).join(' / ') || 'نامشخص');
            html += row('🚪 صفحه ورود', d.entry_page, true);
            html += row('📄 صفحه فعلی', d.current_page, true);
            html += row('🔗 مبدأ ورود', d.referrer, true);
            html += row('🔎 کلمه جستجو', d.search_keyword);
            html += row('🕐 اولین بازدید', d.first_seen);
            html += row('🗺️ منبع موقعیت', d.geo_src === 'api' ? 'سرویس جغرافیایی' : (d.geo_src === 'cache' ? 'کش محلی' : (d.geo_src ? 'محلی' : '—')));
            html += '</div>';
            /* 📜 مسیر بازدید با تایم‌لاین */
            if (d.recent_pages && d.recent_pages.length) {
                html += '<div style="margin-top:13px;font-size:11.5px;font-weight:800;color:#334155;margin-bottom:7px">📜 مسیر بازدید (آخرین ' + faNum(d.recent_pages.length) + ' صفحه — از جدید به قدیم):</div>';
                html += '<div style="max-height:230px;overflow-y:auto;border:1px solid #eef2f7;border-radius:11px;padding:6px;background:#fff">';
                d.recent_pages.forEach(function (p, idx) {
                    const durTxt = p.duration > 0 ? faNum(p.duration) + ' ثانیه' : 'در حال مطالعه';
                    const durColor = p.duration >= 60 ? '#15803d' : (p.duration >= 15 ? '#b45309' : '#64748b');
                    html += '<div style="display:flex;align-items:center;gap:9px;padding:6px 8px;border-bottom:1px dashed #f1f5f9">'
                        + '<span style="flex:none;width:22px;height:22px;border-radius:50%;background:' + (idx === 0 ? '#1e40af' : '#e2e8f0') + ';color:' + (idx === 0 ? '#fff' : '#475569') + ';font-size:10.5px;font-weight:800;display:inline-flex;align-items:center;justify-content:center">' + faNum(idx + 1) + '</span>'
                        + '<span style="flex:1;min-width:0;font-size:11px;direction:ltr;text-align:left;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#0f172a">' + esc(p.url) + '</span>'
                        + '<span style="flex:none;font-size:10px;color:#64748b">' + esc(p.time) + '</span>'
                        + '<span style="flex:none;font-size:10px;font-weight:800;color:' + durColor + ';background:#f8fafc;border:1px solid #e2e8f0;border-radius:20px;padding:2px 8px">' + durTxt + '</span>'
                        + '</div>';
                });
                html += '</div>';
            }
            if (window.SahandDialog) {
                SahandDialog.dialog({ title: '👤 جزئیات کاربر آنلاین', html: html, buttons: [{ text: 'بستن', btn: 'primary' }] });
            } else {
                alert(html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' '));
            }
        })
        .catch(function () {
            if (window.SahandDialog) { SahandDialog.dialog({ title: '👤 جزئیات کاربر آنلاین', html: '<div class="alert alert-danger">خطا در دریافت جزئیات — دوباره تلاش کنید.</div>', buttons: [{ text: 'بستن', btn: 'primary' }] }); }
        });
};

sahandLoadOnlineUsers();
setInterval(sahandLoadOnlineUsers, 30000);

/* ═══════════════════════════════════════════════════════════════
 * 🆕 v2.32 — هفت نمودار جدید (تقویم/پیش‌بینی/روند دستگاه/تعامل/
 * عمق/رادار/نرخ پرش) — Canvas دست‌ساز بدون وابستگی
 * ═══════════════════════════════════════════════════════════════ */

/* 📆 ① تقویم فعالیت ۱۲ هفته — سبک گیت‌هاب (DOM، نه Canvas) */
(function () {
    const holder = document.getElementById('activity-calendar');
    if (!holder) return;
    let days = {};
    try { days = JSON.parse(holder.getAttribute('data-days') || '{}'); } catch (e) { days = {}; }
    const max = Math.max(1, parseInt(holder.getAttribute('data-max') || '1', 10));
    const faD = s => String(s).replace(/[0-9]/g, d => '۰۱۲۳۴۵۶۷۸۹'[+d]);
    const total = 84;
    const today = new Date();
    /* شروع از شنبه هفته ۱۲ هفته قبل */
    const start = new Date(today);
    start.setDate(today.getDate() - (total - 1));
    const startDow = (start.getDay() + 1) % 7; /* شنبه=۰ */
    start.setDate(start.getDate() - startDow);
    let html = '<div style="display:flex;gap:4px;direction:rtl;overflow-x:auto;padding:4px 0">';
    /* ۱۲ ستون هفته */
    let d = new Date(start);
    const monthNames = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
    let weeks = [];
    for (let w = 0; w < 13; w++) {
        let col = '<div style="display:flex;flex-direction:column;gap:3px">';
        for (let dow = 0; dow < 7; dow++) {
            const ds = d.toISOString().slice(0, 10);
            const isFuture = d > today;
            const val = days[ds] || 0;
            let bg = '#eef2f7';
            if (val > 0) {
                const t = Math.pow(val / max, 0.65);
                const r = Math.round(226 + (30 - 226) * t), g = Math.round(232 + (64 - 232) * t), b = Math.round(240 + (175 - 240) * t);
                bg = 'rgb(' + r + ',' + g + ',' + b + ')';
            }
            col += '<span title="' + ds + (val > 0 ? ' — ' + faD(val) + ' بازدیدکننده' : '') + '" style="width:15px;height:15px;border-radius:3.5px;background:' + (isFuture ? 'transparent' : bg) + ';display:inline-block"></span>';
            d.setDate(d.getDate() + 1);
            if (d > today && dow < 6) { /* سلول‌های آینده خالی */ }
        }
        col += '</div>';
        weeks.push(col);
        if (d > today) break;
    }
    /* چیدمان RTL: هفته‌های جدیدتر سمت راست */
    html += weeks.reverse().join('');
    html += '</div>';
    holder.innerHTML = html;
})();

/* 🔮 ② پیش‌بینی ۷ روز آینده — رگرسیون خطی روی ۲۸ روز اخیر */
(function () {
    const canvas = document.getElementById('forecast-chart');
    if (!canvas) return;
    const series = AD[22];
    if (!series.length) { canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">🔮</div><p>داده کافی برای پیش‌بینی نیست (۲۸ روز اخیر خالی است).</p></div>'; return; }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth || 500;
    const H = 230;
    canvas.width = w * dpr; canvas.height = H * dpr;
    ctx.scale(dpr, dpr);
    /* رگرسیون خطی: y = a + b·i */
    const n = series.length;
    let sx = 0, sy = 0, sxy = 0, sxx = 0;
    series.forEach(function (p, i) { sx += i; sy += p[1]; sxy += i * p[1]; sxx += i * i; });
    const b = (n * sxy - sx * sy) / Math.max(1e-9, (n * sxx - sx * sx));
    const a = (sy - b * sx) / n;
    const forecasts = [];
    for (let k = 0; k < 7; k++) { forecasts.push(Math.max(0, a + b * (n + k))); }
    const allVals = series.map(function (p) { return p[1]; }).concat(forecasts);
    const maxV = Math.max.apply(null, allVals.concat([1]));
    const padL = 44, padR = 12, padT = 14, padB = 30;
    const plotW = w - padL - padR, plotH = H - padT - padB;
    const xOf = i => padL + (i / (n + 6)) * plotW;
    const yOf = v => padT + plotH - (v / maxV) * plotH;
    /* خطوط شبکه */
    ctx.strokeStyle = '#e8edf4'; ctx.lineWidth = 1;
    for (let g = 0; g <= 4; g++) {
        const y = padT + plotH * g / 4;
        ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(w - padR, y); ctx.stroke();
        ctx.fillStyle = '#94a3b8'; ctx.font = '10px Tahoma'; ctx.textAlign = 'left';
        ctx.fillText(String(Math.round(maxV * (1 - g / 4))), 6, y + 3);
    }
    /* ناحیه زیر داده واقعی */
    ctx.beginPath();
    series.forEach(function (p, i) { const x = xOf(i), y = yOf(p[1]); i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y); });
    ctx.lineTo(xOf(n - 1), padT + plotH); ctx.lineTo(xOf(0), padT + plotH); ctx.closePath();
    const gradA = ctx.createLinearGradient(0, padT, 0, padT + plotH);
    gradA.addColorStop(0, 'rgba(37,99,235,.22)'); gradA.addColorStop(1, 'rgba(37,99,235,0)');
    ctx.fillStyle = gradA; ctx.fill();
    /* خط واقعی */
    ctx.beginPath();
    series.forEach(function (p, i) { const x = xOf(i), y = yOf(p[1]); i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y); });
    ctx.strokeStyle = '#2563eb'; ctx.lineWidth = 2.4; ctx.lineJoin = 'round'; ctx.stroke();
    /* ناحیه اطمینان پیش‌بینی */
    ctx.beginPath();
    ctx.moveTo(xOf(n - 1), yOf(series[n - 1][1]));
    forecasts.forEach(function (v, k) { ctx.lineTo(xOf(n + k), yOf(v)); });
    ctx.strokeStyle = '#d97706'; ctx.lineWidth = 2.2; ctx.setLineDash([7, 5]); ctx.lineJoin = 'round'; ctx.stroke();
    ctx.setLineDash([]);
    /* نقطه‌های پیش‌بینی */
    forecasts.forEach(function (v, k) {
        ctx.beginPath(); ctx.arc(xOf(n + k), yOf(v), 3.4, 0, Math.PI * 2);
        ctx.fillStyle = '#fff'; ctx.fill(); ctx.strokeStyle = '#d97706'; ctx.lineWidth = 2; ctx.stroke();
    });
    /* جداکننده امروز */
    ctx.strokeStyle = '#94a3b8'; ctx.setLineDash([3, 4]); ctx.lineWidth = 1.2;
    ctx.beginPath(); ctx.moveTo(xOf(n - 1), padT); ctx.lineTo(xOf(n - 1), padT + plotH); ctx.stroke();
    ctx.setLineDash([]);
    ctx.fillStyle = '#64748b'; ctx.font = 'bold 10px Tahoma'; ctx.textAlign = 'center';
    ctx.fillText('امروز', xOf(n - 1), H - 12);
    ctx.fillText('۷ روز آینده ←', xOf(n + 3), H - 12);
    ctx.textAlign = 'right';
    ctx.fillText('۲۸ روز اخیر', xOf(2), H - 12);
})();

/* 📱 ③ روند سهم دستگاه‌ها — نمودار سطح انباشته */
(function () {
    const canvas = document.getElementById('devicetrend-chart');
    if (!canvas) return;
    const data = AD[23];
    if (!data.dates.length) { canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">📱</div><p>داده‌ای موجود نیست.</p></div>'; return; }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth || 500, H = 230;
    canvas.width = w * dpr; canvas.height = H * dpr; ctx.scale(dpr, dpr);
    const n = data.dates.length;
    const totals = [];
    for (let i = 0; i < n; i++) { totals.push((data.mobile[i] || 0) + (data.desktop[i] || 0) + (data.tablet[i] || 0)); }
    const maxT = Math.max.apply(null, totals.concat([1]));
    const padL = 40, padR = 10, padT = 12, padB = 26;
    const plotW = w - padL - padR, plotH = H - padT - padB;
    const xOf = i => padL + (n === 1 ? plotW / 2 : (i / (n - 1)) * plotW);
    const layers = [
        { key: 'mobile', color: '#2563eb' },
        { key: 'desktop', color: '#059669' },
        { key: 'tablet', color: '#d97706' },
    ];
    /* رسم از پایین به بالا (انباشته) */
    let base = new Array(n).fill(0);
    layers.forEach(function (L) {
        const vals = data[L.key] || [];
        ctx.beginPath();
        for (let i = 0; i < n; i++) {
            const y = padT + plotH - ((base[i] + (vals[i] || 0)) / maxT) * plotH;
            i === 0 ? ctx.moveTo(xOf(i), y) : ctx.lineTo(xOf(i), y);
        }
        for (let i = n - 1; i >= 0; i--) {
            const y = padT + plotH - (base[i] / maxT) * plotH;
            ctx.lineTo(xOf(i), y);
        }
        ctx.closePath();
        ctx.fillStyle = L.color + 'cc'; ctx.fill();
        ctx.strokeStyle = L.color; ctx.lineWidth = 1.4; ctx.stroke();
        base = base.map(function (b, i) { return b + (vals[i] || 0); });
    });
    /* محور */
    ctx.strokeStyle = '#e8edf4'; ctx.lineWidth = 1;
    ctx.fillStyle = '#94a3b8'; ctx.font = '10px Tahoma';
    for (let g = 0; g <= 3; g++) {
        const y = padT + plotH * g / 3;
        ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(w - padR, y); ctx.stroke();
        ctx.textAlign = 'left'; ctx.fillText(String(Math.round(maxT * (1 - g / 3))), 5, y + 3);
    }
    ctx.textAlign = 'center';
    [0, Math.floor((n - 1) / 2), n - 1].forEach(function (i) {
        ctx.fillText(data.dates[i] ? data.dates[i].slice(5) : '', xOf(i), H - 10);
    });
})();

/* ⏳ ④ سطوح تعامل — دونات گرادیانی */
(function () {
    const canvas = document.getElementById('engage-chart');
    if (!canvas) return;
    const lv = AD[24];
    const entries = Object.keys(lv).map(function (k) { return [k, lv[k]]; }).filter(function (e) { return e[1] > 0; });
    const total = entries.reduce(function (s, e) { return s + e[1]; }, 0);
    if (!total) { canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">⏳</div><p>داده تعامل ثبت نشده است.</p></div>'; return; }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth || 430, H = 230;
    canvas.width = w * dpr; canvas.height = H * dpr; ctx.scale(dpr, dpr);
    const cx = w / 2, cy = H / 2, R = Math.min(w, H) / 2 - 18, r = R * 0.58;
    const colors = { 'پرش سریع': '#ef4444', 'کوتاه': '#f59e0b', 'متوسط': '#0ea5e9', 'عمیق': '#059669' };
    let ang = -Math.PI / 2;
    entries.forEach(function (e) {
        const frac = e[1] / total;
        const a2 = ang + frac * Math.PI * 2;
        ctx.beginPath();
        ctx.arc(cx, cy, R, ang, a2); ctx.arc(cx, cy, r, a2, ang, true); ctx.closePath();
        ctx.fillStyle = colors[e[0]] || '#64748b'; ctx.fill();
        ctx.strokeStyle = '#fff'; ctx.lineWidth = 2.5; ctx.stroke();
        /* درصد داخل قطاع */
        if (frac > 0.07) {
            const mid = (ang + a2) / 2, rr = (R + r) / 2;
            ctx.fillStyle = '#fff'; ctx.font = 'bold 11px Tahoma'; ctx.textAlign = 'center';
            ctx.fillText(Math.round(frac * 100) + '٪', cx + Math.cos(mid) * rr, cy + Math.sin(mid) * rr + 4);
        }
        ang = a2;
    });
    /* متن مرکز */
    ctx.fillStyle = '#1e293b'; ctx.font = 'bold 21px Tahoma'; ctx.textAlign = 'center';
    ctx.fillText(String(total), cx, cy - 2);
    ctx.fillStyle = '#64748b'; ctx.font = '10.5px Tahoma';
    ctx.fillText('بازدید تعامل‌دار', cx, cy + 17);
    /* راهنما */
    ctx.font = '10.5px Tahoma'; ctx.textAlign = 'right';
    let ly = 14;
    entries.forEach(function (e) {
        ctx.fillStyle = colors[e[0]] || '#64748b';
        ctx.fillRect(w - 14, ly - 8, 10, 10);
        ctx.fillStyle = '#475569';
        ctx.fillText(e[0] + ' (' + e[1] + ')', w - 20, ly + 1);
        ly += 17;
    });
})();

/* 🎯 ⑤ عمق گردش — میله‌ای گرادیانی */
(function () {
    const canvas = document.getElementById('depth-chart');
    if (!canvas) return;
    const dist = AD[25];
    const labels = AD[26];
    if (!dist.length) { canvas.parentElement.innerHTML = '<div class="empty-state"><div class="icon">🎯</div><p>داده گردش ثبت نشده است.</p></div>'; return; }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth || 430, H = 230;
    canvas.width = w * dpr; canvas.height = H * dpr; ctx.scale(dpr, dpr);
    const n = dist.length;
    const maxV = Math.max.apply(null, dist.concat([1]));
    const padL = 38, padR = 10, padT = 16, padB = 32;
    const plotW = w - padL - padR, plotH = H - padT - padB;
    const bw = Math.min(46, plotW / n * 0.62);
    for (let g = 0; g <= 3; g++) {
        const y = padT + plotH * g / 3;
        ctx.strokeStyle = '#e8edf4'; ctx.beginPath(); ctx.moveTo(padL, y); ctx.lineTo(w - padR, y); ctx.stroke();
        ctx.fillStyle = '#94a3b8'; ctx.font = '10px Tahoma'; ctx.textAlign = 'left';
        ctx.fillText(String(Math.round(maxV * (1 - g / 3))), 5, y + 3);
    }
    dist.forEach(function (v, i) {
        const cx = padL + (i + 0.5) * (plotW / n);
        const bh = (v / maxV) * plotH;
        const g2 = ctx.createLinearGradient(0, padT + plotH - bh, 0, padT + plotH);
        g2.addColorStop(0, '#7c3aed'); g2.addColorStop(1, '#a78bfa');
        ctx.fillStyle = g2;
        /* گوشه گرد بالا */
        const r = Math.min(7, bw / 2);
        const x = cx - bw / 2, y = padT + plotH - bh;
        ctx.beginPath();
        ctx.moveTo(x, y + bh); ctx.lineTo(x, y + r); ctx.quadraticCurveTo(x, y, x + r, y);
        ctx.lineTo(x + bw - r, y); ctx.quadraticCurveTo(x + bw, y, x + bw, y + r); ctx.lineTo(x + bw, y + bh);
        ctx.closePath(); ctx.fill();
        ctx.fillStyle = '#334155'; ctx.font = 'bold 10.5px Tahoma'; ctx.textAlign = 'center';
        if (v > 0) { ctx.fillText(String(v), cx, y - 5); }
        ctx.fillStyle = '#64748b'; ctx.font = '10.5px Tahoma';
        ctx.fillText(labels[i], cx, H - 12);
    });
    ctx.fillStyle = '#94a3b8'; ctx.font = '10.5px Tahoma'; ctx.textAlign = 'right';
    ctx.fillText('تعداد صفحات دیده‌شده →', w - padR, H - 12);
})();

/* 🕸 ⑥ رادار سلامت — پنج محور نرمال‌شده */
(function () {
    const canvas = document.getElementById('radar-chart');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth || 430, H = 260;
    canvas.width = w * dpr; canvas.height = H * dpr; ctx.scale(dpr, dpr);
    /* پنج محور: بازدید / مدت حضور / عمق گردش / بازگشتی / تعامل خوب */
    const raw = [
        AD[12],
        AD[27],
        AD[28],
        AD[29],
        AD[30],
    ];
    /* نرمال‌سازی هر محور به ۰..۱ با سقف مرجع */
    const caps = [Math.max(20, raw[0]), 240, 4, 60, 80];
    const vals = raw.map(function (v, i) { return Math.max(0.04, Math.min(1, v / caps[i])); });
    const labels = ['بازدید', 'مدت حضور', 'عمق گردش', 'بازگشتی', 'تعامل'];
    const raws = [
        'AD[31]',
        'AD[32] ث',
        'AD[33] ص',
        'AD[34]٪',
        'AD[35]٪',
    ];
    const cx = w / 2, cy = H / 2 + 6, R = Math.min(w, H) / 2 - 42;
    const N = 5;
    const pt = (i, f) => [cx + Math.cos(-Math.PI / 2 + i * 2 * Math.PI / N) * R * f, cy + Math.sin(-Math.PI / 2 + i * 2 * Math.PI / N) * R * f];
    /* شبکه */
    ctx.strokeStyle = '#e2e8f0'; ctx.lineWidth = 1;
    for (let ring = 1; ring <= 4; ring++) {
        ctx.beginPath();
        for (let i = 0; i <= N; i++) { const p = pt(i % N, ring / 4); i === 0 ? ctx.moveTo(p[0], p[1]) : ctx.lineTo(p[0], p[1]); }
        ctx.stroke();
    }
    for (let i = 0; i < N; i++) {
        const p = pt(i, 1);
        ctx.beginPath(); ctx.moveTo(cx, cy); ctx.lineTo(p[0], p[1]); ctx.stroke();
    }
    /* چندضلعی مقدار */
    ctx.beginPath();
    for (let i = 0; i <= N; i++) { const p = pt(i % N, vals[i % N]); i === 0 ? ctx.moveTo(p[0], p[1]) : ctx.lineTo(p[0], p[1]); }
    ctx.closePath();
    const rg = ctx.createRadialGradient(cx, cy, 0, cx, cy, R);
    rg.addColorStop(0, 'rgba(37,99,235,.34)'); rg.addColorStop(1, 'rgba(124,58,237,.18)');
    ctx.fillStyle = rg; ctx.fill();
    ctx.strokeStyle = '#2563eb'; ctx.lineWidth = 2.2; ctx.stroke();
    /* رئوس */
    for (let i = 0; i < N; i++) {
        const p = pt(i, vals[i]);
        ctx.beginPath(); ctx.arc(p[0], p[1], 4, 0, Math.PI * 2);
        ctx.fillStyle = '#fff'; ctx.fill(); ctx.strokeStyle = '#2563eb'; ctx.lineWidth = 2; ctx.stroke();
    }
    /* برچسب‌ها */
    ctx.font = 'bold 11px Tahoma'; ctx.textAlign = 'center';
    for (let i = 0; i < N; i++) {
        const p = pt(i, 1.24);
        ctx.fillStyle = '#1e293b';
        ctx.fillText(labels[i], p[0], p[1] - 4);
        ctx.font = '10px Tahoma'; ctx.fillStyle = '#64748b';
        ctx.fillText(raws[i], p[0], p[1] + 9);
        ctx.font = 'bold 11px Tahoma';
    }
})();

/* 📊 ⑦ نرخ پرش صفحات — میله‌های افقی رنگی */
(function () {
    const canvas = document.getElementById('bouncerate-chart');
    if (!canvas) return;
    const pages = AD[36];
    if (!pages.length) return;
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth || 430, H = Math.max(150, pages.length * 38);
    canvas.width = w * dpr; canvas.height = H * dpr; ctx.scale(dpr, dpr);
    const rowH = H / pages.length;
    pages.forEach(function (pg, i) {
        const y = i * rowH + rowH / 2;
        /* پس‌زمینه ردیف */
        ctx.fillStyle = i % 2 ? '#f8fafc' : '#fff';
        ctx.fillRect(0, i * rowH, w, rowH);
        /* برچسب صفحه */
        ctx.fillStyle = '#334155'; ctx.font = '11px Tahoma'; ctx.textAlign = 'right';
        let name = pg.page || '/';
        if (name.length > 26) { name = '…' + name.slice(-25); }
        ctx.fillText(name, w - 8, y - 5);
        ctx.fillStyle = '#94a3b8'; ctx.font = '9.5px Tahoma';
        ctx.fillText(pg.entries + ' ورود', w - 8, y + 10);
        /* میزه نرخ */
        const barX = 14, barW = w - 160 - 14;
        ctx.fillStyle = '#eef2f7';
        ctx.beginPath();
        if (ctx.roundRect) { ctx.roundRect(barX, y - 7, barW, 14, 7); ctx.fill(); } else { ctx.fillRect(barX, y - 7, barW, 14); }
        const rate = Math.max(0, Math.min(100, pg.rate));
        const col = rate > 65 ? '#dc2626' : (rate > 40 ? '#d97706' : '#059669');
        ctx.fillStyle = col;
        ctx.beginPath();
        if (ctx.roundRect) { ctx.roundRect(barX + barW * (1 - rate / 100), y - 7, barW * rate / 100, 14, 7); ctx.fill(); } else { ctx.fillRect(barX + barW * (1 - rate / 100), y - 7, barW * rate / 100, 14); }
        /* درصد */
        ctx.fillStyle = col; ctx.font = 'bold 11.5px Tahoma'; ctx.textAlign = 'left';
        ctx.fillText(rate + '٪', 12, y + 4);
    });
})();

/* ═══ 🆕 v2.39 — تحلیل درخواست‌های خدمات (چهار نمودار جدید) ═══ */

/* 🔧 ① دستگاه‌های پردرخواست — میله‌های افقی گرادیانی */
(function () {
    const canvas = document.getElementById('reqdevices-chart');
    if (!canvas) return;
    const data = AD[AD.length - 4] || [];
    if (!data.length) { canvas.parentElement.innerHTML = '<div class="empty-state" style="padding:12px"><div class="icon">🔧</div><p>داده‌ای موجود نیست.</p></div>'; return; }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth, h = canvas.offsetHeight || data.length * 40;
    canvas.width = w * dpr; canvas.height = h * dpr;
    ctx.scale(dpr, dpr);
    const faN = new Intl.NumberFormat('fa-IR');
    const max = Math.max(...data.map(d => d.value), 1);
    const rowH = Math.min(44, (h - 10) / data.length);
    data.forEach((d, i) => {
        const y = 8 + i * rowH;
        /* برچسب راست */
        ctx.textAlign = 'right'; ctx.fillStyle = '#1e293b'; ctx.font = '12.5px Tahoma';
        ctx.fillText(String(d.label).slice(0, 22), w - 8, y + rowH / 2 + 4);
        /* میله از راست به چپ */
        const barRight = w - 150, barW = (barRight - 10) * (d.value / max);
        const grad = ctx.createLinearGradient(barRight, 0, barRight - barW, 0);
        grad.addColorStop(0, '#7c3aed'); grad.addColorStop(1, '#a78bfa');
        ctx.fillStyle = grad;
        if (ctx.roundRect) { ctx.beginPath(); ctx.roundRect(barRight - barW, y + rowH / 2 - 9, barW, 18, 8); ctx.fill(); }
        else { ctx.fillRect(barRight - barW, y + rowH / 2 - 9, barW, 18); }
        /* عدد */
        ctx.textAlign = 'left'; ctx.fillStyle = '#6d28d9'; ctx.font = 'bold 12.5px Tahoma';
        ctx.fillText(faN.format(d.value), 8, y + rowH / 2 + 4);
    });
})();

/* 📊 ② توزیع وضعیت درخواست‌ها — دونات */
(function () {
    const canvas = document.getElementById('reqstatus-chart');
    if (!canvas) return;
    const map = AD[AD.length - 3] || {};
    const data = Object.keys(map).map(k => ({ label: k, value: map[k] })).filter(d => d.value > 0);
    if (!data.length) { canvas.parentElement.innerHTML = '<div class="empty-state" style="padding:12px"><div class="icon">📊</div><p>داده‌ای موجود نیست.</p></div>'; return; }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth;
    canvas.width = w * dpr; canvas.height = 230 * dpr;
    ctx.scale(dpr, dpr);
    const faN = new Intl.NumberFormat('fa-IR');
    const colors = ['#dc2626', '#f59e0b', '#0891b2', '#16a34a', '#94a3b8', '#7c3aed'];
    const total = data.reduce((s, d) => s + d.value, 0);
    const cx = w * 0.30, cy = 115, r = 76;
    let angle = -Math.PI / 2;
    data.forEach((d, i) => {
        const slice = (d.value / total) * Math.PI * 2;
        ctx.beginPath(); ctx.moveTo(cx, cy);
        ctx.arc(cx, cy, r, angle, angle + slice); ctx.closePath();
        ctx.fillStyle = colors[i % colors.length]; ctx.fill();
        angle += slice;
    });
    ctx.beginPath(); ctx.arc(cx, cy, r * 0.58, 0, Math.PI * 2); ctx.fillStyle = '#fff'; ctx.fill();
    ctx.fillStyle = '#1e293b'; ctx.font = 'bold 16px Tahoma'; ctx.textAlign = 'center';
    ctx.fillText(faN.format(total), cx, cy + 6);
    ctx.font = '10.5px Tahoma'; ctx.fillStyle = '#64748b';
    ctx.fillText('کل درخواست‌ها', cx, cy + 24);
    ctx.textAlign = 'right'; ctx.font = '12px Tahoma';
    data.forEach((d, i) => {
        const y = 34 + i * 26;
        ctx.fillStyle = colors[i % colors.length];
        ctx.fillRect(w * 0.60, y - 9, 13, 13);
        ctx.fillStyle = '#1e293b';
        ctx.fillText(d.label + ' — ' + faN.format(d.value) + ' (' + faN.format(Math.round(d.value / total * 100)) + '٪)', w - 8, y + 2);
    });
})();

/* 📈 ③ روند هفتگی درخواست‌ها — ستون‌های بنفش */
(function () {
    const canvas = document.getElementById('reqtrend-chart');
    if (!canvas) return;
    const data = AD[AD.length - 2] || [];
    if (!data.length) { canvas.parentElement.innerHTML = '<div class="empty-state" style="padding:12px"><div class="icon">📈</div><p>داده‌ای موجود نیست.</p></div>'; return; }
    const ctx = canvas.getContext('2d');
    const dpr = window.devicePixelRatio || 1;
    const w = canvas.offsetWidth, h = 180;
    canvas.width = w * dpr; canvas.height = h * dpr;
    ctx.scale(dpr, dpr);
    const faN = new Intl.NumberFormat('fa-IR');
    const max = Math.max(...data.map(d => d.value), 1);
    const bw = Math.min(52, (w - 20) / data.length - 8);
    data.forEach((d, i) => {
        const cx = 10 + (i + 0.5) * ((w - 20) / data.length);
        const bh = Math.max(4, (h - 52) * (d.value / max));
        const grad = ctx.createLinearGradient(0, h - 30 - bh, 0, h - 30);
        grad.addColorStop(0, '#8b5cf6'); grad.addColorStop(1, '#4c1d95');
        ctx.fillStyle = grad;
        if (ctx.roundRect) { ctx.beginPath(); ctx.roundRect(cx - bw / 2, h - 30 - bh, bw, bh, 6); ctx.fill(); }
        else { ctx.fillRect(cx - bw / 2, h - 30 - bh, bw, bh); }
        if (d.value > 0) {
            ctx.fillStyle = '#6d28d9'; ctx.font = 'bold 11px Tahoma'; ctx.textAlign = 'center';
            ctx.fillText(faN.format(d.value), cx, h - 36 - bh);
        }
        if (data.length <= 12 || i % 2 === 0) {
            ctx.fillStyle = '#64748b'; ctx.font = '10px Tahoma';
            ctx.fillText(d.label, cx, h - 12);
        }
    });
})();

/* 🏆 ④ برندهای برتر بر اساس درخواست + نرخ تبدیل — فهرست HTML */
(function () {
    const box = document.getElementById('reqbrands-list');
    if (!box) return;
    const data = AD[AD.length - 1] || [];
    if (!data.length) { box.innerHTML = '<div class="empty-state" style="padding:12px"><div class="icon">🏆</div><p>داده‌ای موجود نیست.</p></div>'; return; }
    const faN = new Intl.NumberFormat('fa-IR');
    const maxReq = Math.max(...data.map(d => d.value), 1);
    box.innerHTML = data.map(d => {
        const pct = Math.max(6, Math.round(d.value / maxReq * 100));
        const convColor = d.conv >= 3 ? '#16a34a' : (d.conv >= 1 ? '#d97706' : '#94a3b8');
        return '<div style="display:flex;align-items:center;gap:12px;padding:8px 10px;border-radius:11px;transition:.14s" onmouseover="this.style.background=\'rgba(124,58,237,.05)\'" onmouseout="this.style.background=\'\'">'
            + (d.logo ? '<img src="' + String(d.logo).replace(/"/g, '&quot;') + '" alt="" style="width:34px;height:34px;border-radius:9px;object-fit:cover;border:1px solid #e2e8f0;flex:none">' : '<span style="width:34px;height:34px;border-radius:9px;background:#f1f5f9;display:inline-flex;align-items:center;justify-content:center;flex:none">🏷️</span>')
            + '<div style="flex:1;min-width:0">'
            + '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center"><b style="font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + String(d.label).replace(/</g, '&lt;') + '</b>'
            + '<span style="font-size:11.5px;font-weight:800;color:#6d28d9;flex:none">' + faN.format(d.value) + ' درخواست</span></div>'
            + '<div style="display:flex;align-items:center;gap:9px;margin-top:5px">'
            + '<div style="height:7px;border-radius:6px;background:#ede9fe;flex:1;overflow:hidden"><div style="height:100%;width:' + pct + '%;border-radius:6px;background:linear-gradient(90deg,#7c3aed,#a78bfa)"></div></div>'
            + '<span style="font-size:10.5px;color:' + convColor + ';font-weight:800;flex:none">🎯 ' + faN.format(d.conv) + '٪ تبدیل (' + faN.format(d.visits) + ' بازدید)</span>'
            + '</div></div></div>';
    }).join('');
})();

} /* پایان sahandDrawCharts */

/* ▶ اجرای اولیه + بازترسیم با تأخیر هنگام تغییر اندازه */
sahandDrawCharts();
let sahandResizeTimer = null;
window.addEventListener('resize', function () {
    clearTimeout(sahandResizeTimer);
    sahandResizeTimer = setTimeout(sahandDrawCharts, 180);
});
/* بازترسیم پس از بارگذاری کامل فونت (متن نمودارها با فونت درست) */
if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(function () { sahandDrawCharts(); });
}
