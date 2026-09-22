<?php
/**
 * 📊 اندپوینت آمار — ثبت بازدید توسط tracker.js
 * ===============================================
 * اطلاعات ثبت‌شده: صفحه، UA، رفرر، رزولوشن، زبان، مدت حضور
 * (IP خام ذخیره نمی‌شود — فقط هش برای شمارش یکتا + پیشوند GeoIP)
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

    // 🗺️ GeoIP محلی
    $geo = GeoIP::lookup($ip);
    $keyword = null;
    if ($referrer !== '') {
        // استخراج کلمه جستجو از موتورهای جستجو
        if (preg_match('#(?:google|bing|yahoo)\.[a-z.]+/.*[?&](?:q|p)=([^&]+)#i', $referrer, $km)) {
            $keyword = mb_substr(urldecode($km[1]), 0, 250);
        }
    }

    // 💾 ثبت یا بروزرسانی بازدید
    $existing = $db->fetch(
        'SELECT id, entry_page FROM visits WHERE session_hash = ? AND visit_date = CURDATE() LIMIT 1',
        [$sessionHash]
    );
    if (!$existing) {
        $db->insert('visits', [
            'brand_id'    => $brandId,
            'session_hash'=> $sessionHash,
            'ip_hash'     => hash('sha256', $ip . date('Y-m')),
            'ip_prefix'   => implode('.', array_slice(explode('.', $ip), 0, 2)) . '.0.0',
            'user_agent'  => $ua,
            'device_type' => $deviceType,
            'browser'     => $browser,
            'os'          => $os,
            'resolution'  => mb_substr((string)($input['resolution'] ?? ''), 0, 20) ?: null,
            'language'    => mb_substr((string)($input['language'] ?? ''), 0, 10) ?: null,
            'country'     => $geo['country'],
            'city'        => $geo['city'] ?: null,
            'referrer'    => $referrer ?: null,
            'search_keyword' => $keyword,
            'entry_page'  => $pageUrl,
            'visit_date'  => date('Y-m-d'),
        ]);
        $visitId = $db->lastInsertId();
    } else {
        $visitId = (int)$existing['id'];
    }

    // 📄 ثبت بازدید صفحه
    $db->insert('visit_details', [
        'visit_id'   => $visitId,
        'page_url'   => $pageUrl,
        'duration'   => max(0, min(3600, (int)($input['duration'] ?? 0))),
        'is_exit'    => 0,
    ]);

    json_response(['success' => true]);
}
