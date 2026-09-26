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

    // 🗺️ GeoIP سه‌لایه (کش DB + سرویس خارجی + رنج محلی)
    $geo = GeoIP::lookup($ip);
    $keyword = null;
    if ($referrer !== '') {
        // استخراج کلمه جستجو از موتورهای جستجو
        if (preg_match('#(?:google|bing|yahoo)\.[a-z.]+/.*[?&](?:q|p)=([^&]+)#i', $referrer, $km)) {
            $keyword = mb_substr(urldecode($km[1]), 0, 250);
        }
    }

    $ipParts = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? explode('.', $ip) : [];
    $ipPrefix = count($ipParts) === 4 ? ($ipParts[0] . '.' . $ipParts[1] . '.0.0') : null;

    // 💾 ثبت یا بروزرسانی بازدید
    /* 🚨 v2.31 — مقایسه تاریخ همیشه با زمان تهران (قبلاً CURDATE()
       زمان سرور MySQL بود: بین ۰۰:۰۰ تا ۰۳:۳۰ بامداد ایران با تاریخ
       PHP می‌اخت و نشست تکراری درج می‌کرد). */
    $existing = $db->fetch(
        'SELECT id, entry_page, city, province, country FROM visits WHERE session_hash = ? AND visit_date = ? LIMIT 1',
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
        /* 🔄 جغرافیای بهتر اگر ردیف قدیمی شهر خالی/محلی داشت */
        $updateGeo = [];
        if (($existing['city'] === null || $existing['city'] === '') && $geo['city'] !== '') {
            $updateGeo['city'] = $geo['city'];
        }
        if (($existing['province'] === null || $existing['province'] === '') && $geo['province'] !== '') {
            $updateGeo['province'] = $geo['province'];
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
