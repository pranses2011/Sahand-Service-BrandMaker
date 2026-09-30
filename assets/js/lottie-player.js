/**
 * 🎬 SLottie — پخش‌کننده انیمیشن‌های Lottie سایت‌ساز (v2.44 / S14)
 * =================================================================
 * فرمت: .lottie (بسته ZIP سبک شامل manifest + JSON) — دانلود یکبار،
 * کش در حافظه، باز شدن با fflate و پخش با lottie-web.
 *
 * استفاده:
 *   <div data-lottie="dashboard" data-lottie-size="72"></div>
 *   <div data-lottie="deploy" data-lottie-mode="hover"></div>
 *   <div data-lottie="success" data-lottie-speed="1.4"></div>
 *
 * API:
 *   SLottie.mount(el, name, opts) → Promise<lottie>
 *   SLottie.page() → نام انیمیشن متناسب با صفحه فعلی
 *   SLottie.play(name) / SLottie.stop()
 *
 * ویژگی‌ها:
 *   - IntersectionObserver: انیمیشن فقط وقتی در دید است پخش می‌شود (صرفه‌جویی CPU)
 *   - حالت hover: فقط هنگام اشاره ماوس پخش + برگشت به فریم اول
 *   - حالت once: یک‌بار پخش و توقف
 *   - احترام به prefers-reduced-motion (انیمیشن‌های همیشگی متوقف می‌شوند)
 */
(function () {
    'use strict';

    var BASE = (function () {
        /* assets/js/lottie-player.js → assets/lottie/ */
        var scripts = document.querySelectorAll('script[src*="lottie-player"]');
        for (var i = 0; i < scripts.length; i++) {
            var m = String(scripts[i].getAttribute('src') || '').match(/^(.*\/)js\/lottie-player/);
            if (m) { return m[1] + 'lottie/'; }
        }
        return 'assets/lottie/';
    })();

    var cache = {};        /* name → animation-data یا 'ERR' */
    var mounted = [];      /* همه نمونه‌ها */
    var reduceMotion = false;
    try {
        reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (e) { reduceMotion = false; }

    function fetchAnim(name) {
        if (cache[name]) { return Promise.resolve(cache[name]); }
        var url = BASE + name + '.lottie' + (window.SAHAND_VER ? '?v=' + window.SAHAND_VER : '');
        return fetch(url).then(function (r) {
            if (!r.ok) { throw new Error('HTTP ' + r.status); }
            return r.arrayBuffer();
        }).then(function (buf) {
            var ff = window.fflate;
            if (!ff || !ff.unzipSync) { throw new Error('fflate missing'); }
            var files = ff.unzipSync(new Uint8Array(buf));
            /* پیدا کردن animations/*.json داخل بسته */
            var jsonKey = null;
            for (var k in files) {
                if (files.hasOwnProperty(k) && /animations\/.+\.json$/.test(k)) { jsonKey = k; break; }
            }
            if (!jsonKey) { throw new Error('no animation json'); }
            var text = ff.strFromU8(files[jsonKey]);
            var data = JSON.parse(text);
            cache[name] = data;
            return data;
        }).catch(function (err) {
            cache[name] = 'ERR';
            return null;
        });
    }

    function mount(el, name, opts) {
        opts = opts || {};
        if (!el || el.__slottie) { return Promise.resolve(null); }
        el.__slottie = true;
        var size = parseInt(el.getAttribute('data-lottie-size') || opts.size || el.getAttribute('data-lottie-size') || '64', 10) || 64;
        var speed = parseFloat(el.getAttribute('data-lottie-speed') || opts.speed || '1') || 1;
        var mode = el.getAttribute('data-lottie-mode') || opts.mode || 'loop';

        el.classList.add('slottie');
        el.style.width = size + 'px';
        el.style.height = size + 'px';
        if (mode === 'hover') { el.classList.add('slottie-hover'); }

        return fetchAnim(name).then(function (data) {
            if (!data || !window.lottie) {
                el.classList.add('slottie-fallback');
                el.textContent = '';
                return null;
            }
            var anim = window.lottie.loadAnimation({
                container: el,
                renderer: 'svg',
                loop: mode !== 'once',
                autoplay: false,
                animationData: JSON.parse(JSON.stringify(data))
            });
            anim.setSpeed(speed);
            var inst = { el: el, anim: anim, mode: mode, name: name, playing: false };
            mounted.push(inst);

            var play = function () {
                if (inst.playing) { return; }
                inst.playing = true;
                el.classList.add('is-playing');
                anim.play();
            };
            var stop = function () {
                inst.playing = false;
                el.classList.remove('is-playing');
                if (mode === 'hover') { anim.goToAndStop(0, true); }
                else { anim.pause(); }
            };

            if (mode === 'hover') {
                anim.goToAndStop(0, true);
                el.addEventListener('mouseenter', play, { passive: true });
                el.addEventListener('mouseleave', stop, { passive: true });
                /* دسترسی‌پذیری: فوکوس هم پخش کند */
                el.addEventListener('focus', play, { passive: true });
                el.addEventListener('blur', stop, { passive: true });
            } else if (reduceMotion) {
                anim.goToAndStop(Math.floor(anim.totalFrames * 0.42), true);
                el.classList.add('is-static');
            } else {
                play();
            }
            el.__slottieInst = inst;
            return anim;
        });
    }

    /* نقشه صفحه فعلی → انیمیشن (برای تزریق خودکار) */
    var PAGE_MAP = {
        'index.php': 'dashboard', 'brands.php': 'brands', 'brand-new.php': 'brands',
        'brand-build.php': 'builder', 'brand-edit.php': 'builder',
        'articles.php': 'articles', 'error-codes.php': 'error-codes',
        'templates.php': 'templates', 'template-builder.php': 'builder',
        'themes.php': 'themes', 'menus.php': 'menus', 'icons.php': 'icons', 'fonts.php': 'fonts',
        'requests.php': 'requests', 'request-print.php': 'requests',
        'form-entries.php': 'form-entries', 'form-builder.php': 'form-builder',
        'analytics.php': 'analytics', 'analytics-online.php': 'analytics', 'analytics-report.php': 'analytics',
        'seo.php': 'seo', 'ai-learning.php': 'ai-learning',
        'telegram.php': 'telegram', 'bale.php': 'bale', 'media.php': 'media',
        'comments.php': 'comments', 'ab-tests.php': 'ab-tests', 'webhooks.php': 'webhooks',
        'revisions.php': 'revisions', 'export.php': 'export', 'deploy.php': 'deploy',
        'deploy-status.php': 'deploy', 'health-dashboard.php': 'health', 'backups.php': 'backups',
        'deployment-logs.php': 'logs', 'cpanel-settings.php': 'cpanel', 'migrate.php': 'migrate',
        'users.php': 'users', 'settings.php': 'settings', 'api-keys.php': 'api-keys',
        'webmaster.php': 'webmaster', 'docs.php': 'docs', 'profile.php': 'profile',
        'login.php': 'login', 'forgot-password.php': 'login'
    };

    function page() {
        var p = String(location.pathname || '').split('/').pop() || 'index.php';
        return PAGE_MAP[p] || 'dashboard';
    }

    /* 🌟 تزریق خودکار: چیپ انیمیشن کنار عنوان صفحه + تزئین سایدبار */
    function autoDecorate() {
        /* ۱) آیکون کنار عنوان بالای صفحه (topbar h2) */
        var h2 = document.querySelector('.topbar-titles h2');
        if (h2 && !h2.__slottieChip && !document.querySelector('.topbar .slottie-chip')) {
            var chip = document.createElement('span');
            chip.className = 'slottie-chip';
            chip.setAttribute('data-lottie', page());
            chip.setAttribute('data-lottie-size', '46');
            chip.setAttribute('title', '');
            h2.parentNode.insertBefore(chip, h2.parentNode.firstChild);
            h2.__slottieChip = true;
        }
        /* ۲) لوگوی کنار نام سایت‌ساز در سایدبار */
        var brand = document.querySelector('.sidebar-brand .logo');
        if (brand && !brand.__slottie) {
            var bwrap = document.createElement('span');
            bwrap.className = 'slottie slottie-brand';
            bwrap.setAttribute('data-lottie', 'builder');
            bwrap.setAttribute('data-lottie-size', '34');
            bwrap.setAttribute('data-lottie-mode', 'hover');
            brand.style.display = 'none';
            brand.parentNode.insertBefore(bwrap, brand.nextSibling);
        }
        /* ۳) آیتم فعال منو → انیمیشن کوچک روی آیکون */
        var active = document.querySelector('.nav-link.active .icon');
        if (active && !active.__slottie && active.parentNode && !active.parentNode.querySelector('.slottie-menu')) {
            var pageAnim = page();
            var mwrap = document.createElement('span');
            mwrap.className = 'slottie-menu';
            mwrap.setAttribute('data-lottie', pageAnim);
            mwrap.setAttribute('data-lottie-size', '26');
            active.style.display = 'none';
            active.parentNode.insertBefore(mwrap, active.nextSibling);
        }
        /* ۴) حالت خالی جداول — انیمیشن empty در ظرف‌های .empty-state */
        document.querySelectorAll('.empty-state:not(.slottie-done), .no-results:not(.slottie-done)').forEach(function (es) {
            es.classList.add('slottie-done');
            var w = document.createElement('div');
            w.className = 'slottie-empty';
            w.setAttribute('data-lottie', 'empty');
            w.setAttribute('data-lottie-size', '84');
            es.insertBefore(w, es.firstChild);
        });
    }

    /* اسکن خودکار عناصر data-lottie — فقط وقتی در دید قرار می‌گیرند سوار می‌شوند */
    function scan() {
        var els = document.querySelectorAll('[data-lottie]:not(.slottie-mounted)');
        if (!('IntersectionObserver' in window)) {
            els.forEach(function (el) { el.classList.add('slottie-mounted'); mount(el, el.getAttribute('data-lottie')); });
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
                if (en.isIntersecting) {
                    var el = en.target;
                    io.unobserve(el);
                    el.classList.add('slottie-mounted');
                    mount(el, el.getAttribute('data-lottie'));
                }
            });
        }, { rootMargin: '80px' });
        els.forEach(function (el) { io.observe(el); });
    }

    /* توقف/ادامه همه (مثلاً هنگام تغییر تب مرورگر) */
    document.addEventListener('visibilitychange', function () {
        mounted.forEach(function (m) {
            if (document.hidden) { m.anim.pause(); }
            else if (m.playing && m.mode !== 'hover') { m.anim.play(); }
        });
    });

    window.SLottie = {
        mount: mount,
        page: page,
        scan: scan,
        autoDecorate: autoDecorate,
        play: function (name) { mounted.forEach(function (m) { if (!name || m.name === name) { m.playing = true; m.anim.play(); } }); },
        stop: function (name) { mounted.forEach(function (m) { if (!name || m.name === name) { m.playing = false; m.anim.pause(); } }); }
    };

    /* راه‌اندازی خودکار پس از بارگذاری DOM */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { autoDecorate(); scan(); });
    } else {
        autoDecorate(); scan();
    }
})();
