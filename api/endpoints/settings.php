<?php
/**
 * ⚙️ اندپوینت تنظیمات عمومی قابل نمایش
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

function api_public_settings(): void
{
    $cache = new Cache();
    $data = $cache->remember('api_public_settings', 600, function () {
        return [
            'agency' => [
                'name_fa'   => Config::get(Config::KEY_AGENCY_NAME_FA),
                'name_en'   => Config::get(Config::KEY_AGENCY_NAME_EN),
                'slogan_fa' => Config::get(Config::KEY_AGENCY_SLOGAN_FA),
                'logo'      => Config::get(Config::KEY_AGENCY_LOGO) ? asset_url((string)Config::get(Config::KEY_AGENCY_LOGO)) : '',
                'favicon'   => Config::get(Config::KEY_AGENCY_FAVICON) ? asset_url((string)Config::get(Config::KEY_AGENCY_FAVICON)) : '',
                'main_site' => Config::get(Config::KEY_MAIN_SITE),
            ],
            'contacts'  => Config::get(Config::KEY_CONTACTS),
            'emails'    => Config::get(Config::KEY_EMAILS),
            'addresses' => Config::get(Config::KEY_ADDRESSES),
            'socials'   => Config::get(Config::KEY_SOCIALS),
            'work_hours'=> Config::get(Config::KEY_WORK_HOURS),
            'warranty'  => Config::get(Config::KEY_WARRANTY),
            'cost'      => Config::get(Config::KEY_COST),
            'linking'   => Config::get(Config::KEY_LINKING),
            'font'      => Config::get(Config::KEY_DEFAULT_FONT),
        ];
    });
    json_response(['success' => true, 'data' => $data]);
}
