<?php
/**
 * 📊 اندپوینت آمار — ثبت بازدید توسط tracker.js
 * ===============================================
 * اطلاعات ثبت‌شده: صفحه، UA، رفرر، رزولوشن، زبان، مدت حضور
 * (IP خام ذخیره نمی‌شود — فقط هش برای شمارش یکتا + پیشوند GeoIP)
 *
 * 🆕 v2.31:
 *   ① ثبت استان (province) از GeoIP سه‌لایه
 *   ② visited_at و viewed_at صریح با زمان تهران (ریشه ساعت/روز غلط:
 *      ستون DEFAULT CURRENT_TIMESTAMP زمان سرور MySQL بود نه ایران)
 *   ③ last_seen در هر پینگ بروزرسانی می‌شود (کاربران آنلاین)
 *   ④ heartbeat: پینگ ۶۰ ثانیه‌ای بدون درج صفحه جدید
 * 🆕 v2.32 (ریشه «کاربر تبریز → خراسان رضوی»):
 *   ⑤ ip_prefix به‌صورت /24 (a.b.c.0) ذخیره می‌شود — هم‌کلید با کش
 *      GeoIP؛ قبلاً /16 بود و در نمایش برای «آدرس پایه شبکه» از سرویس
 *      خارجی پرسیده می‌شد و محل ثبت ISP (خراسان رضوی!) برمی‌گشت
 *   ⑥ ستون geo_src (api/cache/local) ثبت می‌شود
 *   ⑦ بک‌فیل هوشمند: نتیجه api جای جواب حدسی local را می‌گیرد
 *      (قبلاً استان حدسی غلط برای همیشه قفل می‌شد)
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

function api_track_visit(): void
{
    $db = Database::getInstance();
    $input = Router::jsonInput();

    $brandId = (int)($input['brand_id'] ?? 0);
    $sessionHash = (string)($input['session'] ?? '');
    if ($brandId <= 0 || $sessionHash === '' || !preg_match('/^[a-f0-9]{16,64}$/', $sessionHash)) {
        json_response(['success' => false, 'error' => 'پارامترهای ردیابی نامعتبر'], 422);
    }

    $ip = Logger::clientIp();
    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? $input['user_agent'] ?? ''), 0, 490);
    $pageUrl = mb_substr((string)($input['page'] ?? ''), 0, 490);
    $referrer = mb_substr((string)($input['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? '')), 0, 490);
    $nowTehran = date('Y-m-d H:i:s');
    $todayTehran = date('Y-m-d');

    /* 💓 heartbeat — فقط بروزرسانی last_seen؛ هیچ صفحه جدید درج نمی‌شود */
    if (!empty($input['heartbeat'])) {
        try {
            $db->query(
                'UPDATE visits SET last_seen = ? WHERE session_hash = ? AND visit_date = ?',
                [$nowTehran, $sessionHash, $todayTehran]
            );
        } catch (Throwable $hbE) { /* بی‌صدا */ }
        json_response(['success' => true]);
    }

    // 🔍 تشخیص نوع دستگاه / مرورگر / سیستم‌عامل از UA
    $deviceType = preg_match('/mobile|android.*mobile|iphone/i', $ua) ? 'mobile'
        : (preg_match('/ipad|tablet|android(?!.*mobile)/i', $ua) ? 'tablet'
        : (preg_match('/bot|crawl|spider|slurp/i', $ua) ? 'bot' : 'desktop'));
    preg_match('/(firefox|edg|chrome|safari|opera|msie|trident)\/?[\d.]*/i', $ua, $bm);
    $browser = isset($bm[1]) ? ucfirst(mb_strtolower($bm[1] === 'edg' ? 'Edge' : ($bm[1] === 'trident' ? 'IE' : $bm[1]))) : null;
    preg_match('/(windows nt [\d.]+|mac os x|android [\d.]+|linux|iphone os [\d.]+|ipad)/i', $ua, $om);
    $os = $om[1] ?? null;
    if ($os !== null) {
        $os = ucfirst(mb_substr($os, 0, 30));
    }

    /* 🗺️ GeoIP چهارلایه (کش DB + ۳ سرویس خارجی + رنج ملی) */
    $geo = GeoIP::lookup($ip);
    $keyword = null;
    if ($referrer !== '') {
        // استخراج کلمه جستجو از موتورهای جستجو
        if (preg_match('#(?:google|bing|yahoo)\.[a-z.]+/.*[?&](?:q|p)=([^&]+)#i', $referrer, $km)) {
            $keyword = mb_substr(urldecode($km[1]), 0, 250);
        }
    }

    /* 🚨 v2.32 — پیشوند /24 (هم‌کلید کش GeoIP) — قبلاً /16 بود و ریشه
       جغرافیای غلط در نمایش بود؛ IPv6 = پیشوند /64 */
    $ipPrefix = GeoIP::prefixOf($ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $ipPrefix = null;
    }

    // 💾 ثبت یا بروزرسانی بازدید
    /* 🚨 v2.31 — مقایسه تاریخ همیشه با زمان تهران (قبلاً CURDATE()
       زمان سرور MySQL بود: بین ۰۰:۰۰ تا ۰۳:۳۰ بامداد ایران با تاریخ
       PHP می‌اخت و نشست تکراری درج می‌کرد). */
    $existing = $db->fetch(
        'SELECT id, entry_page, city, province, country, geo_src FROM visits WHERE session_hash = ? AND visit_date = ? LIMIT 1',
        [$sessionHash, $todayTehran]
    );
    if (!$existing) {
        $db->insert('visits', [
            'brand_id'    => $brandId,
            'session_hash'=> $sessionHash,
            'ip_hash'     => hash('sha256', $ip . date('Y-m')),
            'ip_prefix'   => $ipPrefix,
            'user_agent'  => $ua,
            'device_type' => $deviceType,
            'browser'     => $browser,
            'os'          => $os,
            'resolution'  => mb_substr((string)($input['resolution'] ?? ''), 0, 20) ?: null,
            'language'    => mb_substr((string)($input['language'] ?? ''), 0, 10) ?: null,
            'country'     => $geo['country'],
            'city'        => $geo['city'] ?: null,
            'province'    => $geo['province'] ?: null,
            /* 📱 v2.44 (S07) — بازدید اپراتور موبایل: منبع «mobile» تا آمار
               آن را از «نامشخص» جدا کند (گروه «اینترنت موبایل») */
            'geo_src'     => !empty($geo['is_mobile']) ? 'mobile' : ($geo['source'] ?? 'local'),
            'referrer'    => $referrer ?: null,
            'search_keyword' => $keyword,
            'entry_page'  => $pageUrl,
            'visit_date'  => $todayTehran,
            'visited_at'  => $nowTehran,
            'last_seen'   => $nowTehran,
        ]);
        $visitId = $db->lastInsertId();
    } else {
        $visitId = (int)$existing['id'];
        /* 🔄 جغرافیای بهتر — 🚨 v2.32 بک‌فیل هوشمند:
           ① خانه خالی → پر می‌شود (مثل قبل)
           ② جواب قدیمی با منبع local (حدس استاتیک) و جواب جدید api →
              جایگزین می‌شود (ریشه قفل‌شدن استان غلط برای همیشه) */
        $updateGeo = [];
        $oldSrc = (string)($existing['geo_src'] ?? '');
        $newIsApi = in_array($geo['source'] ?? '', ['api', 'cache'], true);
        $oldIsWeak = ($existing['city'] === null || $existing['city'] === '') && ($existing['province'] === null || $existing['province'] === '') || $oldSrc === 'local' || $oldSrc === '';
        if ($newIsApi && ($geo['city'] !== '' || $geo['province'] !== '')) {
            if ($oldIsWeak) {
                if ($geo['city'] !== '' && $geo['city'] !== (string)$existing['city']) { $updateGeo['city'] = $geo['city']; }
                if ($geo['province'] !== '' && $geo['province'] !== (string)$existing['province']) { $updateGeo['province'] = $geo['province']; }
                $updateGeo['geo_src'] = $geo['source'];
            }
        } else {
            if (($existing['city'] === null || $existing['city'] === '') && $geo['city'] !== '') {
                $updateGeo['city'] = $geo['city'];
            }
            if (($existing['province'] === null || $existing['province'] === '') && $geo['province'] !== '') {
                $updateGeo['province'] = $geo['province'];
            }
            if ($updateGeo && !empty($geo['source'])) { $updateGeo['geo_src'] = $geo['source']; }
        }
        if ($updateGeo) {
            try { $db->update('visits', $updateGeo, 'id = ?', [$visitId]); } catch (Throwable $gE) { /* قدیمی */ }
        }
        try { $db->update('visits', ['last_seen' => $nowTehran], 'id = ?', [$visitId]); } catch (Throwable $lsE) { /* ستون جدید */ }
    }

    // 📄 ثبت بازدید صفحه
    $isExit = !empty($input['is_exit']);
    $duration = max(0, min(3600, (int)($input['duration'] ?? 0)));
    if ($isExit) {
        $db->update(
            'visit_details',
            ['duration' => $duration, 'is_exit' => 1],
            'visit_id = ? AND page_url = ? AND is_exit = 0',
            [$visitId, $pageUrl]
        );
    } else {
        $db->insert('visit_details', [
            'visit_id'   => $visitId,
            'page_url'   => $pageUrl,
            'duration'   => $duration,
            'is_exit'    => 0,
            'viewed_at'  => $nowTehran,
        ]);
    }

    json_response(['success' => true]);
}
