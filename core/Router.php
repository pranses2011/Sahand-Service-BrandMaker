<?php
/**
 * 🧭 کلاس روتر — مسیریابی درخواست‌های API داخلی
 * ==============================================
 * آدرس‌های /api/{resource}/{action} را به کنترلرهای
 * مربوطه در پوشه api/endpoints هدایت می‌کند.
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class Router
{
    /** @var array لیست مسیرهای ثبت‌شده */
    private $routes = [];

    /** @var array میان‌افزارهای سراسری */
    private $globalMiddleware = [];

    /**
     * ➕ ثبت مسیر جدید
     *
     * @param string   $method     متد HTTP (GET|POST|PUT|DELETE)
     * @param string   $pattern    الگوی مسیر مثل brand/{id}/page/{slug}
     * @param callable $handler    تابع مدیریت‌کننده
     * @param array    $middleware میان‌افزارهای اختصاصی این مسیر
     */
    public function add(string $method, string $pattern, callable $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method'     => strtoupper($method),
            'pattern'    => $pattern,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    /**
     * ➕ میان‌افزار سراسری (روی تمام مسیرها اعمال می‌شود)
     */
    public function use(callable $middleware): void
    {
        $this->globalMiddleware[] = $middleware;
    }

    /**
     * 🏃 اجرای روتر — تطبیق درخواست جاری با مسیرهای ثبت‌شده
     */
    public function dispatch(): void
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        // استخراج مسیر پس از /api/
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = preg_replace('#^.*?/api/#', '', $uri);
        $path = trim($path, '/');

        foreach ($this->routes as $route) {
            // بررسی متد
            if ($route['method'] !== $method) {
                continue;
            }
            // تبدیل الگو به Regex — {param} به گروه نامدار تبدیل می‌شود
            $regex = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $route['pattern']);
            $regex = '#^' . $regex . '$#';
            if (preg_match($regex, $path, $matches)) {
                // استخراج پارامترهای نام‌دار
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                // اجرای میان‌افزارهای سراسری و اختصاصی
                $middlewares = array_merge($this->globalMiddleware, $route['middleware']);
                foreach ($middlewares as $mw) {
                    $continue = $mw($params);
                    if ($continue === false) {
                        return; // میان‌افزار درخواست را متوقف کرد
                    }
                }

                // اجرای کنترلر مسیر
                call_user_func($route['handler'], $params);
                return;
            }
        }

        // ❌ مسیر یافت نشد
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => 'مسیر API یافت نشد',
            'path'    => $path,
        ], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 📥 دریافت بدنه JSON درخواست
     */
    public static function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return $_POST;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
