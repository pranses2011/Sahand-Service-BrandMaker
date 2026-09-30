<?php
/**
 * 🧩 اندپوینت فرم‌های سفارشی (S12 / v2.44)
 * ==========================================
 * GET  brand/{id}/custom-forms          → فهرست فرم‌های فعال (عنصر قالب‌ساز)
 * GET  brand/{id}/custom-form/{slug}    → تعریف کامل فرم (رندر سایت برند)
 *
 * ثبت ورودی از همان اندپوینت form-entry می‌گذرد (form_slug ضمیمه می‌شود)
 * — یعنی اعلان چندکاناله، ضداسپم و وب‌هوک موجود بی‌تغییر کار می‌کند.
 *
 * @package SahandBrandMaker
 */
if (!defined('SAHAND_INIT')) { http_response_code(403); exit; }

/**
 * 📋 فهرست فرم‌های فعال برای پالت عنصر «فرم سفارشی» قالب‌ساز
 */
function api_custom_forms_list(int $brandId): void
{
    $forms = class_exists('CustomFormManager') ? CustomFormManager::listActive($brandId) : [];
    json_response(['success' => true, 'data' => $forms]);
}

/**
 * 🧩 تعریف کامل یک فرم برای رندر روی سایت برند
 */
function api_custom_form_single(int $brandId, string $slug): void
{
    $form = class_exists('CustomFormManager') ? CustomFormManager::bySlug($slug) : null;
    if (!$form) {
        json_response(['success' => false, 'error' => 'فرم یافت نشد'], 404);
    }
    /* فرمِ برند دیگر؟ (brand_id پر و متفاوت) */
    if ($form['brand_id'] !== null && $form['brand_id'] !== $brandId) {
        json_response(['success' => false, 'error' => 'فرم یافت نشد'], 404);
    }
    unset($form['id'], $form['brand_id']);
    json_response(['success' => true, 'data' => $form]);
}
