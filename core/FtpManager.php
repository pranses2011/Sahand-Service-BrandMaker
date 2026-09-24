<?php
/**
 * 📂 کلاس مدیریت FTP — روش جایگزین آپلود فایل‌ها
 * ===============================================
 * طبق سند بخش ۲۱ (بخش ۴): اگر cPanel API محدودیت داشت
 * (مثلاً Fileman غیرفعال باشد)، آپلود فایل‌ها از FTP انجام می‌شود.
 * ⚠️ ساخت زیردامنه همچنان از cPanel API انجام می‌شود.
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class FtpManager
{
    /** @var array تنظیمات FTP (از PathResolver::getSettings) */
    private $settings;

    /** @var resource|null اتصال FTP */
    private $conn = null;

    /** @var string|null آخرین خطا */
    private $lastError = null;

    /** @var bool حالت Passive */
    private $passive = true;

    /**
     * 🔧 سازنده
     */
    public function __construct(?array $settings = null)
    {
        $this->settings = $settings ?? PathResolver::getSettings();
        $this->passive  = (bool)($this->settings['ftp_passive'] ?? 1);
    }

    /**
     * 🔌 اتصال به سرور FTP
     *
     * @return bool موفقیت
     */
    public function connect(): bool
    {
        $host = trim((string)($this->settings['ftp_host'] ?? ''));
        $port = (int)($this->settings['ftp_port'] ?? 21);
        $user = trim((string)($this->settings['ftp_username'] ?? ''));
        $pass = DeployCrypto::decrypt((string)($this->settings['ftp_password_enc'] ?? ''));

        if ($host === '' || $user === '' || $pass === '') {
            $this->lastError = 'تنظیمات FTP کامل نیست — آدرس، نام کاربری و رمز عبور الزامی است.';
            return false;
        }

        // 🔒 FTPS (FTP روی TLS) اگر فعال باشد
        $useSsl = (bool)($this->settings['ftp_ssl'] ?? 0);
        if ($useSsl && function_exists('ftp_ssl_connect')) {
            $this->conn = @ftp_ssl_connect($host, $port, 20);
        }
        // اتصال معمولی
        if ($this->conn === null) {
            $this->conn = @ftp_connect($host, $port, 20);
        }

        if ($this->conn === false || $this->conn === null) {
            $this->lastError = 'اتصال به سرور FTP (' . $host . ':' . $port . ') برقرار نشد.';
            return false;
        }

        if (!@ftp_login($this->conn, $user, $pass)) {
            $this->lastError = 'ورود به FTP ناموفق بود — نام کاربری یا رمز عبور اشتباه است.';
            $this->disconnect();
            return false;
        }

        // 🌐 حالت Passive (پشت فایروال/NAT)
        @ftp_pasv($this->conn, $this->passive);

        return true;
    }

    /**
     * ✅ تست اتصال FTP
     *
     * @return array [success => bool, message => string]
     */
    public function testConnection(): array
    {
        if (!$this->connect()) {
            return ['success' => false, 'message' => $this->lastError ?: 'اتصال FTP برقرار نشد.'];
        }
        $this->disconnect();
        return ['success' => true, 'message' => 'اتصال FTP برقرار است.'];
    }

    /**
     * 📂 ساخت پوشه (بازگشتی) روی سرور FTP
     *
     * @param string $dirPath مسیر کامل (مثلاً /public_html/brands/samsung)
     */
    public function createDirectory(string $dirPath): bool
    {
        if ($this->conn === null && !$this->connect()) {
            return false;
        }

        // مسیرهای نسبی FTP از ریشه home کاربر شروع می‌شوند
        $parts = array_filter(explode('/', trim($dirPath, '/')));
        $current = '';

        foreach ($parts as $part) {
            $current .= '/' . $part;
            // اگر پوشه هست از قبل — رد شو (خطا نادیده گرفته می‌شود)
            if (!@ftp_chdir($this->conn, $current)) {
                if (!@ftp_mkdir($this->conn, $current)) {
                    $this->lastError = 'ساخت پوشه FTP ناموفق: ' . $current;
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * 📤 آپلود فایل روی سرور FTP
     *
     * @param string $localFile مسیر محلی فایل
     * @param string $remoteFile مسیر کامل مقصد
     * @param int $mode حالت انتقال (FTP_BINARY پیش‌فرض — امن برای ZIP)
     */
    public function uploadFile(string $localFile, string $remoteFile, int $mode = FTP_BINARY): bool
    {
        if ($this->conn === null && !$this->connect()) {
            return false;
        }
        if (!file_exists($localFile)) {
            $this->lastError = 'فایل محلی یافت نشد: ' . basename($localFile);
            return false;
        }

        // 📂 ساخت پوشه مقصد در صورت نبود
        $remoteDir = dirname($remoteFile);
        if ($remoteDir !== '/' && $remoteDir !== '.' && !$this->createDirectory($remoteDir)) {
            return false;
        }

        if (!@ftp_put($this->conn, $remoteFile, $localFile, $mode)) {
            $this->lastError = 'آپلود FTP ناموفق: ' . basename($remoteFile);
            return false;
        }
        return true;
    }

    /**
     * 🔐 تنظیم مجوز فایل/پوشه
     *
     * @param string $remotePath مسیر روی سرور
     * @param int $mode مجوز هشتایی (مثلاً 0755)
     */
    public function setPermissions(string $remotePath, int $mode): bool
    {
        if ($this->conn === null && !$this->connect()) {
            return false;
        }
        return @ftp_chmod($this->conn, $mode, $remotePath);
    }

    /**
     * 🗑️ حذف پوشه بازگشتی روی سرور FTP
     */
    public function deleteDirectory(string $remotePath): bool
    {
        if ($this->conn === null && !$this->connect()) {
            return false;
        }

        $list = @ftp_rawlist($this->conn, $remotePath);
        if ($list !== false) {
            foreach ($list as $item) {
                // تفکیک فایل/پوشه از خروجی rawlist
                $info = preg_split('/\s+/', $item, 9);
                if (count($info) < 9) {
                    continue;
                }
                $name = $info[8];
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $isDir = $info[0][0] === 'd';
                $sub = $remotePath . '/' . $name;
                if ($isDir) {
                    $this->deleteDirectory($sub);
                } else {
                    @ftp_delete($this->conn, $sub);
                }
            }
        }
        return @ftp_rmdir($this->conn, $remotePath);
    }

    /**
     * ❓ بررسی وجود فایل/پوشه
     */
    public function fileExists(string $remotePath): bool
    {
        if ($this->conn === null && !$this->connect()) {
            return false;
        }
        // ترفند: chdir موفق یعنی مسیر وجود دارد
        $parent = dirname($remotePath);
        $name = basename($remotePath);
        if (!@ftp_chdir($this->conn, $parent)) {
            return false;
        }
        $list = @ftp_nlist($this->conn, '.');
        return is_array($list) && in_array($name, $list, true);
    }

    /**
     * 🔌 بستن اتصال FTP
     */
    public function disconnect(): void
    {
        if ($this->conn !== null && $this->conn !== false) {
            @ftp_close($this->conn);
        }
        $this->conn = null;
    }

    /**
     * ❌ آخرین خطا
     */
    public function getLastError(): string
    {
        return $this->lastError ?: '';
    }

    /**
     * 🧹 خاموشی — بستن اتصال باز مانده
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
