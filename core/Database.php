<?php
/**
 * 🗄️ کلاس دیتابیس — لایه ارتباط با MySQL
 * =========================================
 * از PDO و Prepared Statements استفاده می‌کند تا
 * سیستم در برابر حملات SQL Injection کاملاً ایمن باشد.
 *
 * @package SahandBrandMaker
 * @version 1.0.0
 */
class Database
{
    /** @var Database نمونه یکتا (Singleton) */
    private static $instance = null;

    /** @var PDO اتصال PDO */
    private $pdo;

    /**
     * 🔒 سازنده خصوصی — فقط از طریق getInstance قابل دسترسی است
     */
    private function __construct()
    {
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_PORT,
                DB_NAME,
                DB_CHARSET
            );
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // پرتاب استثنا در خطا
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,          // خروجی آرایه انجمنی
                PDO::ATTR_EMULATE_PREPARES   => false,                     // Prepared واقعی
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET . " COLLATE utf8mb4_unicode_ci",
            ]);
        } catch (PDOException $e) {
            error_log('[DB] خطای اتصال: ' . $e->getMessage());
            die('<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><body style="font-family:Tahoma;display:flex;align-items:center;justify-content:center;height:100vh"><div style="text-align:center"><h2>🗄️ خطای اتصال به دیتابیس</h2><p>لطفاً تنظیمات config.php را بررسی کنید یا <a href="' . BASE_URL . '/install.php">نصب را اجرا کنید</a>.</p></div></body></html>');
        }
    }

    /**
     * 📥 دریافت نمونه یکتای دیتابیس
     */
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 🎯 دریافت اتصال خام PDO (برای تراکنش و ...)
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * 🔎 اجرای کوئری SELECT و برگرداندن یک ردیف
     *
     * @param string $sql    کوئری SQL با پارامترهای ?
     * @param array  $params مقادیر پارامترها
     * @return array|null
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * 🔎 اجرای کوئری SELECT و برگرداندن همه ردیف‌ها
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * 📊 برگرداندن مقدار تک‌ستونه (اولین ستون اولین ردیف)
     */
    public function fetchValue(string $sql, array $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * ➕ درج ردیف جدید — نام ستون‌ها به صورت خودکار escape می‌شوند
     *
     * @param string $table  نام جدول
     * @param array  $data   آرایه کلید => مقدار
     * @return int شناسه ردیف درج‌شده
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $safeColumns = array_map(function ($c) {
            return '`' . str_replace('`', '', $c) . '`'; // حذف کاراکتر خطرناک بک‌تیک
        }, $columns);
        $placeholders = rtrim(str_repeat('?, ', count($columns)), ', ');
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            str_replace('`', '', $table),
            implode(', ', $safeColumns),
            $placeholders
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($data));
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * ✏️ بروزرسانی ردیف‌ها
     *
     * @param string $table       نام جدول
     * @param array  $data        مقادیر جدید (کلید => مقدار)
     * @param string $whereClause شرط WHERE با پارامتر ?
     * @param array  $whereParams مقادیر شرط
     * @return int تعداد ردیف‌های تغییریافته
     */
    public function update(string $table, array $data, string $whereClause, array $whereParams = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $column) {
            $safeCol = '`' . str_replace('`', '', $column) . '`';
            $sets[] = "$safeCol = ?";
        }
        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE %s',
            str_replace('`', '', $table),
            implode(', ', $sets),
            $whereClause
        );
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    /**
     * 🗑️ حذف ردیف‌ها
     */
    public function delete(string $table, string $whereClause, array $params = []): int
    {
        $sql = sprintf('DELETE FROM `%s` WHERE %s', str_replace('`', '', $table), $whereClause);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * 🔢 شمارش ردیف‌ها
     */
    public function count(string $table, string $whereClause = '1=1', array $params = []): int
    {
        return (int)$this->fetchValue(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', str_replace('`', '', $table), $whereClause),
            $params
        );
    }

    /**
     * ▶️ شروع تراکنش (برای عملیات چندمرحله‌ای اتمیک)
     */
    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    /**
     * ✅ تأیید تراکنش
     */
    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    /**
     * ↩️ بازگردانی تراکنش
     */
    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * 📋 دریافت شناسه آخرین ردیف درج‌شده
     */
    public function lastInsertId(): int
    {
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * 🔒 جلوگیری از کپی‌برداری نمونه
     */
    private function __clone() {}
    public function __wakeup()
    {
        throw new RuntimeException('نمی‌توان نمونه Database را unserialize کرد.');
    }
}
