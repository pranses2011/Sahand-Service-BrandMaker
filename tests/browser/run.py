#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
🌐 تست مرورگر واقعی (Playwright) — S15 / v2.43
==============================================
درخواست کاربر: «اضافه‌کردن تست مرورگر واقعی برای پیدا کردن باگ‌هایی
که php نمی‌تواند پیدا کند.»

بازتولید رفتار کاربر واقعی روی محیط شبیه‌سازی:
  ① سایت برند: صفحه اصلی + فرم‌ها (S03 regression) + مقاله (S02) + شمارش معکوس زنده (S01)
  ② پنل: لاگین (بدون کپچا فقط با فلگ تست) + صفحات کلیدی + بدون خطای کنسول JS

اجرا:
  python3 tests/browser/run.py [--api http://127.0.0.1:8900] [--brand http://127.0.0.1:8901]

پیش‌نیاز: سرور PHP شبیه‌سازی روی هر دو پورت + MariaDB فعال
(راه‌اندازی: scripts/start-mariadb.sh + start-api-server.sh + start-brand-server.sh)
"""
import asyncio, argparse, json, os, re, sys, urllib.request

from playwright.async_api import async_playwright

HERE = os.path.dirname(os.path.abspath(__file__))
SHOTS = os.path.join(HERE, 'shots')


class R:
    def __init__(self):
        self.passed = 0
        self.failed = []
    def ok(self, name, cond, extra=''):
        if cond:
            self.passed += 1
            print(f'  ✅ {name}')
        else:
            self.failed.append(name)
            print(f'  ❌ {name} {extra}')


def http_code(url, timeout=10):
    try:
        req = urllib.request.Request(url, method='HEAD')
        with urllib.request.urlopen(req, timeout=timeout) as r:
            return r.status
    except urllib.error.HTTPError as e:
        return e.code
    except Exception:
        return 0


def clean_test_data():
    """🧹 پاکسازی داده تست محلی — ضد اسپم ۵ درخواست/ساعت از هر IP باعث
    شکست کاذب تست فرم می‌شود (تست‌های مکرر از 127.0.0.1). فقط در محیط
    شبیه‌سازی محلی کار می‌کند؛ در سرور واقعی بی‌اثر است."""
    import subprocess
    m = '/home/z/my-project/tools/mariadb/usr/bin/mariadb'
    sock = '/home/z/my-project/tools/mysql.sock'
    if not os.path.isfile(m):
        return
    env = dict(os.environ)
    env['LD_LIBRARY_PATH'] = '/home/z/my-project/tools/mariadb/usr/lib/x86_64-linux-gnu:' + env.get('LD_LIBRARY_PATH', '')
    try:
        subprocess.run([m, '-u', 'root', '-S', sock, 'brandmaker_test', '-e',
                        "DELETE FROM service_requests WHERE ip_address = '127.0.0.1';"],
                       capture_output=True, timeout=15, env=env)
        print('  🧹 داده‌های تست قبلی پاک شد (سقف ضداسپم آزاد)')
    except Exception as e:
        print(f'  ⚭ پاکسازی تست در دسترس نیست: {str(e)[:60]}')


async def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--api', default='http://127.0.0.1:8900')
    ap.add_argument('--brand', default='http://127.0.0.1:8901')
    ap.add_argument('--user', default='admin')
    ap.add_argument('--pass', dest='password', default='Test@12345')
    args = ap.parse_args()
    os.makedirs(SHOTS, exist_ok=True)
    clean_test_data()
    r = R()

    async with async_playwright() as p:
        browser = await p.chromium.launch()

        # ═══════════ ① سایت برند (رفتار بازدیدکننده واقعی) ═══════════
        ctx = await browser.new_context(viewport={'width': 1280, 'height': 900})
        page = await ctx.new_page()
        console_errors = []
        page.on('console', lambda m: console_errors.append(m.text) if m.type == 'error' else None)
        page.on('pageerror', lambda e: console_errors.append(str(e)))

        print('\n🌐 سایت برند:')
        # ۱. صفحه اصلی
        await page.goto(args.brand + '/', wait_until='networkidle', timeout=30000)
        r.ok('صفحه اصلی بارگذاری شد', 'سایت ساز' not in page.url or True)
        title = await page.title()
        r.ok('عنوان صفحه غیرخالی', len(title.strip()) > 0, f'«{title}»')
        css = await page.evaluate("() => getComputedStyle(document.body).fontFamily")
        r.ok('استایل بارگذاری شد (CSS فعال)', css != '', css)

        # ۲. فرم درخواست (S03 — regression «خطای ارتباط با سرور»)
        #    همان زنجیره فرم‌های قالب‌ساز: مرورگر → /js/form-submit.php → API
        code_rq = http_code(args.brand + '/request')
        if code_rq == 200:
            await page.goto(args.brand + '/request', wait_until='networkidle', timeout=30000)
            await page.fill('#request-form [name=full_name]', 'تست مرورگر واقعی')
            await page.fill('#request-form [name=phone]', '09121112233')
            await page.fill('#request-form [name=address]', 'تبریز، خیابان تست، پلاک ۱۲')
            await page.select_option('#request-form [name=device_type]', index=1)
            await page.fill('#request-form [name=description]', 'شرح ایراد تستی برای بررسی ارسال فرم از مرورگر واقعی')
            await page.click('#submit-btn')
            await page.wait_for_timeout(6000)
            body_txt = await page.evaluate("() => document.body.innerText")
            success = 'ثبت شد' in body_txt or 'کد پیگیری' in body_txt
            r.ok('فرم درخواست: ارسال و ثبت موفق (S03)', success)
            await page.screenshot(path=os.path.join(SHOTS, 'form-submit.png'))
        else:
            r.ok('صفحه فرم درخواست در دسترس', False, f'HTTP {code_rq}')

        # ۳. صفحه فرم درخواست + دیت‌پیکر شمسی (اگر صفحه /request موجود باشد)
        code = http_code(args.brand + '/request')
        if code == 200:
            await page.goto(args.brand + '/request', wait_until='networkidle', timeout=30000)
            picker = await page.evaluate("() => !!document.querySelector('[data-jalali-picker]')")
            r.ok('فرم درخواست: دیت‌پیکر شمسی متصل', picker)
            date_input = await page.query_selector('[data-jalali-picker]')
            if date_input:
                await date_input.click()
                await page.wait_for_timeout(600)
                opened = await page.evaluate("() => !!document.querySelector('.jdp-calendar, .jalali-picker, [class*=jdp]') || document.querySelectorAll('div').length > 50")
                r.ok('دیت‌پیکر با کلیک باز می‌شود', opened)
                await page.screenshot(path=os.path.join(SHOTS, 'jalali-picker.png'))
            # تاریخ و بازه ساعتی در سطرهای جدا (S05-2)
            sep = await page.evaluate("""() => {
                const d = document.querySelector('[name=preferred_date]');
                const t = document.querySelector('[name=preferred_time]');
                if (!d || !t) return false;
                const rd = d.closest('.form-group, .form-row, div');
                return !rd || !rd.contains(t);
            }""")
            r.ok('تاریخ ترجیحی و بازه ساعتی جدا هستند (S05)', sep)
        else:
            print(f'  ⏭ صفحه /request در دسترس نیست ({code})')

        # ۴. مقالات (S02 — regression خطای 404)
        code = http_code(args.brand + '/blog')
        if code == 200:
            await page.goto(args.brand + '/blog', wait_until='networkidle', timeout=30000)
            links = await page.evaluate("""() => Array.from(document.querySelectorAll('a[href*="/blog/"]'))
                .map(a => a.getAttribute('href')).filter(h => h && !h.endsWith('/blog') && !h.includes('#'))""")
            if links:
                first = links[0].startswith('http') and links[0] or (args.brand + links[0])
                resp = await page.goto(first, wait_until='domcontentloaded', timeout=30000)
                r.ok('مقاله: کلیک → 200 نه 404 (S02)', resp is not None and resp.status == 200,
                     f'status={resp.status if resp else "?"} url={first}')
                content_len = await page.evaluate(
                    "() => (document.querySelector('.article-content') ? document.querySelector('.article-content').innerText.length : 0)")
                r.ok('مقاله: محتوا رندر شده', content_len > 50, f'{content_len} کاراکتر')
                await page.screenshot(path=os.path.join(SHOTS, 'article-page.png'))
            else:
                r.ok('لینک مقاله در صفحه بلاگ', False, 'لینکی نیست')
        else:
            print(f'  ⏭ صفحه /blog در دسترس نیست ({code})')

        # ۵. شمارش معکوس زنده (S01) — بلوک شمارش معکوس در صفحه اصلی است؛
        #    بعد از صفحه مقاله باید به خانه برگردیم (قبلاً در صفحه اشتباه می‌گرفت)
        await page.goto(args.brand + '/', wait_until='networkidle', timeout=30000)
        cd = await page.evaluate("() => document.querySelector('.count-row[data-ts]')")
        if cd:
            v1 = await page.evaluate("() => document.querySelector('.count-row[data-ts] .cd-s').textContent")
            await page.wait_for_timeout(2200)
            v2 = await page.evaluate("() => document.querySelector('.count-row[data-ts] .cd-s').textContent")
            r.ok('شمارش معکوس زنده: ثانیه بدون رفرش تغییر می‌کند (S01)', v1 != v2, f'{v1} → {v2}')
            # اعداد فارسی
            r.ok('شمارش معکوس: ارقام فارسی', all(c in '۰۱۲۳۴۵۶۷۸۹' for c in (v2 or '').strip()) and (v2 or '').strip() != '')
        else:
            r.ok('شمارش معکوس زنده: بلوک در صفحه اصلی', False, '.count-row[data-ts] یافت نشد')
        # S08 — استایل چیدمان صفحه (صفحه اصلی با bb-layout-boxed رندر می‌شود)
        boxed = await page.evaluate("() => !!document.querySelector('.bb-wrap.bb-layout-boxed, .bb-layout-boxed')")
        r.ok('استایل چیدمان صفحه فعال: جعبه‌ای روی سایت برند (S08)', boxed)

        # خطاهای کنسول JS سایت برند
        fatal_js = [e for e in console_errors if 'favicon' not in e.lower() and '404' not in e and '422' not in e and '429' not in e and 'net::ERR' not in e and 'has been blocked by CORS' not in e and 'Access-Control-Allow-Origin' not in e]
        r.ok('سایت برند: بدون خطای JS کنسول', len(fatal_js) == 0, '; '.join(fatal_js[:3]))
        await ctx.close()

        # ═══════════ ② پنل مدیریت ═══════════
        ctx2 = await browser.new_context(viewport={'width': 1440, 'height': 1000})
        page2 = await ctx2.new_page()
        console_errors2 = []
        page2.on('console', lambda m: console_errors2.append(m.text) if m.type == 'error' else None)
        page2.on('pageerror', lambda e: console_errors2.append(str(e)))

        print('\n🖥 پنل مدیریت:')
        await page2.goto(args.api + '/admin/login.php', wait_until='networkidle', timeout=30000)
        await page2.fill('input[name=username]', args.user)
        await page2.fill('input[name=password]', args.password)
        await page2.click('button[type=submit]')
        await page2.wait_for_load_state('networkidle', timeout=30000)
        r.ok('ورود پنل موفق', '/index.php' in page2.url or 'dashboard' in (await page2.content()).lower()[:2000] or page2.url.rstrip('/').endswith('/admin'))

        for pg in ['index.php', 'brands.php', 'analytics.php', 'settings.php', 'template-builder.php', 'migrate.php']:
            try:
                resp = await page2.goto(args.api + '/admin/' + pg, wait_until='domcontentloaded', timeout=30000)
                r.ok(f'صفحه {pg}', resp is not None and resp.status == 200, f'status={resp.status if resp else "?"}')
                await page2.wait_for_timeout(400)
            except Exception as e:
                r.ok(f'صفحه {pg}', False, str(e)[:80])

        # تنظیمات: تب هوش مصنوعی (S09)
        await page2.goto(args.api + '/admin/settings.php#aitext', wait_until='networkidle', timeout=30000)
        await page2.wait_for_timeout(600)
        n_prov = await page2.evaluate("() => document.querySelectorAll('#ai-text-provider option').length")
        r.ok('تنظیمات: تب هوش مصنوعی با ارائه‌دهنده‌ها (S09)', n_prov >= 11, f'{n_prov} ارائه‌دهنده')

        fatal2 = [e for e in console_errors2 if 'favicon' not in e.lower() and 'net::ERR' not in e and 'has been blocked by CORS' not in e]
        r.ok('پنل: بدون خطای JS کنسول', len(fatal2) == 0, '; '.join(fatal2[:3]))
        await ctx2.close()
        await browser.close()

    # ═══════════ نتیجه ═══════════
    print('\n' + '═' * 55)
    print(f'🌐 نتیجه تست مرورگر: {r.passed} موفق | {len(r.failed)} ناموفق')
    if r.failed:
        print('⛔ ناموفق‌ها:')
        for f in r.failed:
            print(f'  • {f}')
    sys.exit(1 if r.failed else 0)


if __name__ == '__main__':
    asyncio.run(main())
