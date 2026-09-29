<?php
/**
 * 🤖 AiTextService — سرویس مدل‌های زبانی رایگان با فال‌بک زنجیره‌ای (S09+S16 / v2.43)
 * ==================================================================================
 * درخواست کاربر: «تمامی مدلهای زبانی هوش مصنوعی رایگان (+خودت) که بتوانند در
 * تولید مقاله و خطایابی کمک کنند را در تنظیمات بگذار؛ اگر از یک گزینه جواب
 * نگرفتیم، گزینه‌های بعدی به ترتیب درخواست شوند تا به نتیجه برسیم.»
 *
 * 🏗 ساختار (قرینه‌ی AiPhotoService):
 *   PROVIDERS — رجیستری: کلید => [برچسب، نیاز به کلید؟، مدل پیش‌فرض، توضیح کلید]
 *   settings()/servicesList()/healthCheck()/fallbackChain()
 *   chat() — یک درخواست؛ generateArticleDraft() / generateErrorCodes() — پرامپت‌های آماده
 *   chatWithFallback() — 🔄 S16: زنجیره‌ی خودکار: انتخابی → بدون‌کلیدها → بقیه
 *     با کلید → هر که جواب داد برنده؛ خروجی گزارش می‌دهد کدام مدل جواب داد.
 *
 * 🔌 سازگار با OpenAI Chat Completions (بیشترین ارائه‌دهنده‌ها) + فرمت اختصاصی Gemini.
 *
 * @package SahandBrandMaker\Engine\Services
 * @since 2.43.0
 */
class AiTextService
{
    /**
     * 🌐 رجیستری مدل‌های زبانی — لیست تنظیمات از همین جا خوانده می‌شود
     * کلید => [برچسب فارسی، نیاز به کلید؟، مدل پیش‌فرض، توضیح کلید، base-url]
     *
     * 🆓 بدون کلید (همیشه در زنجیره فال‌بک حاضرند):
     *   pollinations — متن‌ساز رایگان سازگار با OpenAI (بدون ثبت‌نام)
     * 🔑 پلن رایگان (کلید رایگان از سایت ارائه‌دهنده):
     *   zai (GLM — «خودت»)، groq، gemini، openrouter، mistral، together،
     *   cerebras، huggingface، deepseek، cloudflare
     */
    const PROVIDERS = [
        /* 🆓 بدون کلید */
        'pollinations' => ['پولینیشنز — متن (رایگان)',            false, 'openai',          'بدون کلید — سازگار با OpenAI', 'https://text.pollinations.ai/openai'],
        /* 🔑 رایگان با کلید */
        'zai'          => ['Z.ai — GLM-4.5-Flash (پیشنهادی)',     true,  'glm-4.5-flash',   'کلید رایگان از console.z.ai (بخش API Keys)', 'https://api.z.ai/api/paas/v4/chat/completions'],
        'groq'         => ['Groq — Llama 3.3 70B',                true,  'llama-3.3-70b-versatile', 'کلید رایگان از console.groq.com/keys', 'https://api.groq.com/openai/v1/chat/completions'],
        'gemini'       => ['Google — Gemini 2.0 Flash',           true,  'gemini-2.0-flash', 'کلید رایگان از aistudio.google.com/apikey', 'https://generativelanguage.googleapis.com/v1beta/models/{MODEL}:generateContent'],
        'openrouter'   => ['OpenRouter — مدل‌های :free',          true,  'meta-llama/llama-3.3-70b-instruct:free', 'کلید رایگان از openrouter.ai/keys', 'https://openrouter.ai/api/v1/chat/completions'],
        'mistral'      => ['Mistral — La Plateforme',             true,  'mistral-small-latest', 'کلید رایگان از console.mistral.ai', 'https://api.mistral.ai/v1/chat/completions'],
        'together'     => ['Together AI — Llama 3.3 Free',        true,  'meta-llama/Llama-3.3-70B-Instruct-Turbo-Free', 'کلید رایگان از api.together.ai', 'https://api.together.xyz/v1/chat/completions'],
        'cerebras'     => ['Cerebras — Llama 3.3 70B (سریع)',     true,  'llama-3.3-70b',   'کلید رایگان از cloud.cerebras.ai', 'https://api.cerebras.ai/v1/chat/completions'],
        'huggingface'  => ['Hugging Face — Inference API',        true,  'meta-llama/Llama-3.3-70B-Instruct', 'توکن رایگان از huggingface.co/settings/tokens', 'https://router.huggingface.co/v1/chat/completions'],
        'deepseek'     => ['DeepSeek — V3 (ارزان)',               true,  'deepseek-chat',   'کلید از platform.deepseek.com', 'https://api.deepseek.com/v1/chat/completions'],
        'cloudflare'   => ['Cloudflare Workers AI',               true,  '@cf/meta/llama-3.3-70b-instruct', 'Account ID + Token از dash.cloudflare.com', 'https://api.cloudflare.com/client/v4/accounts/{ACCOUNT_ID}/ai/v1/chat/completions'],
    ];

    /** @var array تنظیمات (article_text_settings از Config) */
    private $cfg;

    /** @var array لاگ تلاش‌های این اجرا [provider => خطا] */
    private $attempts = [];

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? (array)(Config::get('article_text_settings') ?: []);
    }

    /* ═══════════════════════════════════════════════════════════
     * ⚙️ تنظیمات و لیست‌ها (برای پنل — قرینه AiPhotoService)
     * ═══════════════════════════════════════════════════════════ */

    public static function settings(): array
    {
        $cfg = (array)(Config::get('article_text_settings') ?: []);
        return [
            'method'   => (string)($cfg['method'] ?? 'internal'), /* internal | llm */
            'provider' => (string)($cfg['provider'] ?? 'pollinations'),
            'model'    => (string)($cfg['model'] ?? ''),
            'keys'     => is_array($cfg['keys'] ?? null) ? $cfg['keys'] : [],
            'fallback' => !isset($cfg['fallback']) ? true : !empty($cfg['fallback']),
            'timeout'  => max(15, min(180, (int)($cfg['timeout'] ?? AI_LLM_TIMEOUT))),
        ];
    }

    /** 📋 فهرست ارائه‌دهنده‌ها برای پنل */
    public static function providersList(): array
    {
        $out = [];
        foreach (self::PROVIDERS as $key => [$label, $needsKey, $model, $hint]) {
            $out[] = ['key' => $key, 'label' => $label, 'needs_key' => $needsKey, 'model' => $model, 'hint' => $hint];
        }
        return $out;
    }

    /** 🔑 کلید ارائه‌دهنده (از تنظیمات) */
    private function serviceKey(string $provider): string
    {
        return trim((string)($this->cfg['keys'][$provider] ?? ''));
    }

    /**
     * 🔄 S16 — زنجیره فال‌بک به‌ترتیب:
     *   ① ارائه‌دهنده انتخابی کاربر (با مدل/کلید خودش)
     *   ② ارائه‌دهنده‌های بدون کلید (pollinations)
     *   ③ بقیه ارائه‌دهنده‌هایی که کلیدشان ثبت شده — به ترتیب رجیستری
     * تنظیم fallback خاموش باشد → فقط ①.
     * @return string[]
     */
    public function fallbackChain(): array
    {
        /* 🩹 v2.43 — اولویت با کانفیگ نمونه است (تست‌ها/صدا زدن برنامه‌ای)؛
           در نبود آن، تنظیمات ذخیره‌شده پنل */
        $s = array_merge(self::settings(), $this->cfg ?: []);
        $chain = [];
        $sel = (string)($s['provider'] ?? 'pollinations');
        if (isset(self::PROVIDERS[$sel])) {
            $chain[] = $sel;
        }
        if (!isset($s['fallback']) || !empty($s['fallback'])) {
            foreach (array_keys(self::PROVIDERS) as $p) {
                if ($p === $sel) { continue; }
                $needsKey = self::PROVIDERS[$p][1];
                if (!$needsKey) {
                    $chain[] = $p; /* بدون کلید — همیشه امتحان می‌شود */
                } elseif ($this->serviceKey($p) !== '') {
                    $chain[] = $p; /* کلید دارد */
                }
            }
        }
        return array_values(array_unique($chain));
    }

    /* ═══════════════════════════════════════════════════════════
     * 💬 گفتگو با مدل
     * ═══════════════════════════════════════════════════════════ */

    /**
     * 💬 یک درخواست چت به یک ارائه‌دهنده مشخص
     * @return string متن پاسخ (خالی = شکست)
     */
    public function chat(string $provider, string $prompt, string $system = '', ?string $modelOverride = null): string
    {
        if (!isset(self::PROVIDERS[$provider])) {
            return '';
        }
        [$label, $needsKey, $defaultModel, $hint, $baseUrl] = self::PROVIDERS[$provider] + [null, null, null, null, null];
        $key = $this->serviceKey($provider);
        if ($needsKey && $key === '') {
            $this->attempts[$provider] = 'کلید ثبت نشده';
            return '';
        }
        $model = trim((string)($modelOverride ?? ($this->cfg['model'] ?? ''))) ?: $defaultModel;
        $timeout = max(15, min(180, (int)($this->cfg['timeout'] ?? AI_LLM_TIMEOUT)));

        try {
            if ($provider === 'gemini') {
                return $this->callGemini($key, $model, $system, $prompt, $timeout);
            }
            return $this->callOpenAiStyle($baseUrl, $key, $model, $system, $prompt, $timeout, $provider);
        } catch (Throwable $e) {
            $this->attempts[$provider] = $e->getMessage();
            return '';
        }
    }

    /**
     * 🔄 S16 — گفتگو با فال‌بک زنجیره‌ای: تا رسیدن به جواب معتبر
     * @param callable|null $validator اعتبارسنج پاسخ (null => فقط غیرخالی)
     * @return array ['text' => string, 'provider' => string, 'chain' => string[], 'failed' => array]
     */
    public function chatWithFallback(string $prompt, string $system = '', ?callable $validator = null): array
    {
        $chain = $this->fallbackChain();
        $failed = [];
        $validate = $validator ?? static fn(string $t): bool => trim($t) !== '';
        foreach ($chain as $provider) {
            $text = $this->chat($provider, $prompt, $system);
            if ($text !== '' && $validate($text)) {
                return ['text' => $text, 'provider' => $provider, 'chain' => $chain, 'failed' => $failed];
            }
            $failed[$provider] = $this->attempts[$provider] ?? 'پاسخ نامعتبر';
        }
        return ['text' => '', 'provider' => '', 'chain' => $chain, 'failed' => $failed];
    }

    /** گزارش تلاش‌های این اجرا (برای شفافیت در پنل) */
    public function attempts(): array
    {
        return $this->attempts;
    }

    /* ═══════════════════════════════════════════════════════════
     * 🧪 تست سلامت (دکمه پنل)
     * ═══════════════════════════════════════════════════════════ */

    public function healthCheck(string $provider, ?string $keyOverride = null): array
    {
        if (!isset(self::PROVIDERS[$provider])) {
            return ['ok' => false, 'message' => 'ارائه‌دهنده ناشناخته'];
        }
        if ($keyOverride !== null) {
            $this->cfg['keys'][$provider] = $keyOverride;
        }
        $t0 = microtime(true);
        $label = self::PROVIDERS[$provider][0];
        $text = $this->chat($provider, 'فقط بنویس: سلام', 'تو یک دستیار تستی هستی. پاسخ کوتاه بده.');
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if (trim($text) !== '') {
            return ['ok' => true, 'message' => "✅ {$label} پاسخ داد ({$ms}ms): «" . mb_substr(trim($text), 0, 60) . '»'];
        }
        $err = $this->attempts[$provider] ?? 'پاسخ خالی';
        return ['ok' => false, 'message' => "❌ {$label}: {$err}"];
    }

    /* ═══════════════════════════════════════════════════════════
     * ✍️ پرامپت‌های آماده — تولید مقاله (S09)
     * ═══════════════════════════════════════════════════════════ */

    /**
     * 📰 پیش‌نویس مقاله با LLM — خروجی هم‌ساختار ArticleGenerator
     * @param array $ctx [brand_fa, device_fa, topic_type, title, agency, warranty, keywords, research]
     * @return array|null ساختار مقاله یا null (فال‌بک به موتور داخلی)
     */
    public function generateArticleDraft(array $ctx): ?array
    {
        $system = 'تو نویسنده‌ی حرفه‌ای محتوای فارسی سایت‌های تعمیرات تخصصی لوازم خانگی هستی. '
            . 'مقاله‌های سئوشده، دقیق، کاربردی و کاملاً فارسی روان می‌نویسی. '
            . 'خروجی را فقط و فقط JSON معتبر بده — بدون هیچ متن اضافه قبل یا بعد.';
        $topicFa = [
            'troubleshooting' => 'عیب‌یابی و رفع ایراد',
            'user_guide' => 'راهنمای استفاده',
            'maintenance' => 'نگهداری و سرویس دوره‌ای',
            'comparison' => 'مقایسه و انتخاب',
            'error_codes' => 'کدهای خطا',
            'buying_guide' => 'راهنمای خرید',
            'energy_saving' => 'کاهش مصرف انرژی',
            'seasonal_care' => 'آماده‌سازی فصلی',
            'cost_guide' => 'هزینه تعمیر',
            'safety_guide' => 'نکات ایمنی',
            'installation_guide' => 'راهنمای نصب',
            'diy_vs_pro' => 'خودسازی یا تعمیرکار',
            'common_mistakes' => 'اشتباهات رایج',
            'warranty_guide' => 'راهنمای ضمانت',
            'tech_explainer' => 'توضیح فنی اجزا',
            'myths_facts' => 'باورهای غلط و حقیقت',
            'checklist' => 'چک‌لیست',
            'case_study' => 'مطالعه موردی',
            'glossary' => 'واژه‌نامه تخصصی',
            'history_evolution' => 'تاریخچه و تکامل',
            'expert_tips' => 'نکات خبرگان',
            'symptom_focus' => 'نشانه‌محور',
            'statistics' => 'آمار و ارقام',
            'environment' => 'محیط زیست',
            'service_process' => 'فرآیند خدمات',
        ][$ctx['topic_type'] ?? ''] ?? 'عمومی';

        $researchNote = '';
        if (!empty($ctx['research'])) {
            $snippets = [];
            foreach (array_slice((array)$ctx['research'], 0, 6) as $r) {
                $snippets[] = '- ' . mb_substr(trim((string)($r['title'] ?? $r['snippet'] ?? '')), 0, 140);
            }
            if ($snippets) {
                $researchNote = "\n\n🔎 منابع تحقیق (در متن به آن‌ها اشاره کن):\n" . implode("\n", $snippets);
            }
        }

        $prompt = "یک مقاله فارسی کامل برای سایت نمایندگی «{$ctx['brand_fa']}» بنویس."
            . "\nنوع مقاله: {$topicFa} — موضوع: {$ctx['title']}"
            . "\nدستگاه: {$ctx['device_fa']}"
            . "\nنام نمایندگی: {$ctx['agency']}"
            . "\nضمانت خدمات: {$ctx['warranty']}"
            . ($researchNote)
            . "\n\nقواعد:"
            . "\n- حداقل ۹۰۰ کلمه، محتوای واقعی و تخصصی (نه عمومی و پُرگویی)"
            . "\n- مقدمه + ۴ تا ۶ بخش با تیتر h2 + جمع‌بندی + CTA تماس با نمایندگی"
            . "\n- از words کلیدی: {$ctx['keywords']}"
            . "\n\nخروجی JSON دقیقاً با این ساختار:"
            . "\n{\"title\":\"...\",\"excerpt\":\"... (حداکثر ۱۶۰ کاراکتر)\",\"content\":\"<p>...</p><h2>...</h2><p>...</p>\","
            . "\"seo_title\":\"...\",\"seo_description\":\"...\",\"tags\":[\"...\",\"...\"],\"faq\":[{\"q\":\"...\",\"a\":\"...\"}]}";

        $res = $this->chatWithFallback($prompt, $system, static function (string $t): bool {
            /* اعتبارسنج: JSON با کلیدهای الزامی */
            $t = trim($t);
            $j = self::extractJson($t);
            return $j !== null && !empty($j['title']) && !empty($j['content']) && mb_strlen((string)$j['content']) > 800;
        });

        if ($res['text'] === '') {
            return null;
        }
        $j = self::extractJson($res['text']);
        if ($j === null) {
            return null;
        }
        return [
            'title'         => mb_substr(trim((string)$j['title']), 0, 180),
            'excerpt'       => mb_substr(trim((string)($j['excerpt'] ?? '')), 0, 300),
            'content'       => self::sanitizeHtml((string)$j['content']),
            'seo_title'     => mb_substr(trim((string)($j['seo_title'] ?? $j['title'])), 0, 180),
            'seo_description' => mb_substr(trim((string)($j['seo_description'] ?? ($j['excerpt'] ?? ''))), 0, 300),
            'tags'          => array_slice(array_map(static fn($t) => mb_substr(trim((string)$t), 0, 40), (array)($j['tags'] ?? [])), 0, 8),
            'faq'           => array_slice(array_map(static fn($f) => ['q' => mb_substr(trim((string)($f['q'] ?? '')), 0, 200), 'a' => mb_substr(trim((string)($f['a'] ?? '')), 0, 600)], (array)($j['faq'] ?? [])), 0, 6),
            'meta'          => ['engine' => 'llm', 'provider' => $res['provider'], 'failed' => $res['failed']],
        ];
    }

    /* ═══════════════════════════════════════════════════════════
     * 🚨 پرامپت آماده — کدهای خطا (خطایاب)
     * ═══════════════════════════════════════════════════════════ */

    /**
     * 🔢 فهرست کدهای خطای واقعی دستگاه از LLM
     * @param array $ctx [brand_fa, device_fa, existing_codes]
     * @return array[]|null آرایه رکوردهای کد خطا یا null
     */
    public function generateErrorCodes(array $ctx): ?array
    {
        $system = 'تو متخصص فنی لوازم خانگی با دسترسی به دفترچه‌های تعمیر برندهای مختلف هستی. '
            . 'فقط کدهای خطای «واقعی و قابل استناد» می‌دهی و هرگز کد جعل نمی‌کنی. '
            . 'خروجی فقط JSON معتبر.';
        $existing = $ctx['existing_codes'] ?? [];
        $skip = $existing ? ("\n- کدهای زیر از قبل ثبت‌اند و نباید تکرار شوند: " . implode('، ', array_slice((array)$existing, 0, 30))) : '';
        $prompt = "کدهای خطای «{$ctx['device_fa']}» برند «{$ctx['brand_fa']}» را فهرست کن."
            . $skip
            . "\n\nهر کد: code (عنوان کد مثل E4 یا LE)، title (معنی کوتاه)، description (توضیح ۲-۴ جمله)،"
            . "severity (low|medium|high|critical)، category (نوع خرابی مثل سنسور/موتور/برد)،"
            . "solution (راه‌حل گام‌به‌گام ۳+ جمله)، related_part (قطعه مربوطه یا خالی)."
            . "\n\nحداقل ۶ و حداکثر ۱۴ کد — فقط کدهای معتبر و مستند."
            . "\nخروجی: {\"codes\":[{\"code\":\"...\",\"title\":\"...\",\"description\":\"...\",\"severity\":\"...\",\"category\":\"...\",\"solution\":\"...\",\"related_part\":\"...\"}]}";

        $res = $this->chatWithFallback($prompt, $system, static function (string $t): bool {
            $j = self::extractJson($t);
            return $j !== null && is_array($j['codes'] ?? null) && count($j['codes']) >= 3;
        });
        if ($res['text'] === '') {
            return null;
        }
        $j = self::extractJson($res['text']);
        if ($j === null) {
            return null;
        }
        $out = [];
        foreach ((array)($j['codes'] ?? []) as $c) {
            $code = strtoupper(trim((string)($c['code'] ?? '')));
            if ($code === '' || mb_strlen($code) > 20) { continue; }
            $sev = strtolower(trim((string)($c['severity'] ?? 'medium')));
            if (!in_array($sev, ['low', 'medium', 'high', 'critical'], true)) { $sev = 'medium'; }
            $out[] = [
                'code'        => $code,
                'title'       => mb_substr(trim((string)($c['title'] ?? $code)), 0, 200),
                'description' => self::sanitizeHtml((string)($c['description'] ?? '')),
                'severity'    => $sev,
                'category'    => mb_substr(trim((string)($c['category'] ?? '')), 0, 100),
                'solution'    => self::sanitizeHtml((string)($c['solution'] ?? '')),
                'related_part'=> mb_substr(trim((string)($c['related_part'] ?? '')), 0, 200),
                'source'      => 'llm:' . $res['provider'],
            ];
        }
        return $out ?: null;
    }

    /* ═══════════════════════════════════════════════════════════
     * 🔌 فراخوانی‌های HTTP
     * ═══════════════════════════════════════════════════════════ */

    /** OpenAI-compatible (pollinations/groq/openrouter/mistral/together/cerebras/hf/deepseek/zai/cloudflare) */
    private function callOpenAiStyle(string $url, string $key, string $model, string $system, string $prompt, int $timeout, string $provider): string
    {
        $url = str_replace('{MODEL}', rawurlencode($model), $url);
        $accountId = trim((string)($this->cfg['cloudflare_account_id'] ?? ''));
        $url = str_replace('{ACCOUNT_ID}', $accountId !== '' ? $accountId : 'x', $url);
        $payload = [
            'model'    => $model,
            'messages' => array_values(array_filter([
                $system !== '' ? ['role' => 'system', 'content' => $system] : null,
                ['role' => 'user', 'content' => $prompt],
            ])),
            'max_tokens' => (int)AI_LLM_MAX_TOKENS,
            'temperature' => 0.7,
        ];
        $headers = ['Content-Type: application/json'];
        if ($key !== '') {
            $headers[] = 'Authorization: Bearer ' . $key;
        }
        if ($provider === 'openrouter') {
            $headers[] = 'HTTP-Referer: ' . (string)BASE_URL;
            $headers[] = 'X-Title: SahandBrandMaker';
        }
        [$body, $err] = $this->httpJson($url, $payload, $headers, $timeout);
        if ($err !== '') {
            $this->attempts[$provider] = $err;
            return '';
        }
        $data = json_decode((string)$body, true);
        $text = (string)($data['choices'][0]['message']['content'] ?? '');
        if ($text === '' && isset($data['error'])) {
            $this->attempts[$provider] = is_array($data['error']) ? (string)($data['error']['message'] ?? 'API error') : (string)$data['error'];
        }
        return $text;
    }

    /** Google Gemini (فرمت generateContent) */
    private function callGemini(string $key, string $model, string $system, string $prompt, int $timeout): string
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);
        $payload = [
            'contents' => [['parts' => [['text' => ($system !== '' ? $system . "\n\n" : '') . $prompt]]]],
            'generationConfig' => ['maxOutputTokens' => (int)AI_LLM_MAX_TOKENS, 'temperature' => 0.7],
        ];
        [$body, $err] = $this->httpJson($url, $payload, ['Content-Type: application/json'], $timeout);
        if ($err !== '') {
            $this->attempts['gemini'] = $err;
            return '';
        }
        $data = json_decode((string)$body, true);
        return (string)($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }

    /** POST JSON با cURL — خروجی [body, error] */
    private function httpJson(string $url, array $payload, array $headers, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            return ['', 'cURL در دسترس نیست'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['', 'شبکه: ' . ($err !== '' ? $err : 'بدون پاسخ')];
        }
        if ($code >= 400) {
            return [(string)$body, 'HTTP ' . $code . ' — ' . mb_substr((string)$body, 0, 160)];
        }
        return [(string)$body, ''];
    }

    /* ═══════════════════════════════════════════════════════════
     * 🧰 ابزارها
     * ═══════════════════════════════════════════════════════════ */

    /** استخراج JSON از پاسخ مدل (مدل‌ها گاهی ```json ... ``` می‌پیچند) */
    public static function extractJson(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') { return null; }
        /* حذف قاب markdown */
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/us', $text, $m)) {
            $text = $m[1];
        }
        /* اولین { تا آخرین } */
        $a = strpos($text, '{');
        $b = strrpos($text, '}');
        if ($a === false || $b === false || $b <= $a) { return null; }
        $j = json_decode(substr($text, $a, $b - $a + 1), true);
        return is_array($j) ? $j : null;
    }

    /** پاکسازی HTML خروجی مدل (فقط تگ‌های مجاز محتوای مقاله) */
    private static function sanitizeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') { return ''; }
        /* اگر مدل متن ساده داده، پاراگراف‌بندی کن */
        if (stripos($html, '<p') === false && stripos($html, '<h2') === false) {
            $html = '<p>' . str_replace("\n\n", '</p><p>', e($html)) . '</p>';
        }
        /* لیست سفید تگ‌ها */
        $allowed = '<p><h2><h3><h4><ul><ol><li><strong><b><em><i><br><table><thead><tbody><tr><td><th><blockquote><a><span>';
        $html = strip_tags($html, $allowed);
        /* لینک‌ها: فقط http/https + rel امن */
        $html = preg_replace_callback('#<a\s([^>]*)>#i', static function ($m) {
            $attrs = $m[1];
            if (!preg_match('#href\s*=\s*["\']?(https?://[^"\'>\s]+)#i', $attrs, $h)) {
                return '<span>'; /* لینک ناامن → span */
            }
            return '<a href="' . e($h[1]) . '" target="_blank" rel="noopener nofollow">';
        }, $html) ?? $html;
        /* رویدادهای inline حذف */
        $html = preg_replace('/\son\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html) ?? $html;
        $html = preg_replace('/\sstyle\s*=\s*(".*?"|\'.*?\')/is', '', $html) ?? $html;
        return $html;
    }
}
