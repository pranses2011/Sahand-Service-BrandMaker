<?php
/**
 * 🧪 فریمورک تست بومی سایت‌ساز سهند سرویس — v2.33
 * =====================================================
 * بدون composer و بدون وابستگی — اجرا: `php tests/run.php`
 * گزینه‌ها:
 *   --update-baseline   بازنویسی خط پایه تست انطباق رندرگرها
 *   --filter=نام        اجرای فقط فایل‌های منطبق
 *
 * فایل‌های تست: tests/*.test.php — هرکدام $T (این کلاس) را در دسترس دارند:
 *   $T->assert('توضیح', $condition)
 *   $T->assertEquals('توضیح', $expected, $actual)
 *
 * @package SahandBrandMaker\Tests
 */

error_reporting(E_ALL & ~E_DEPRECATED);

class TestRunner
{
    public int $pass = 0;
    public int $fail = 0;
    public int $skipped = 0;
    /** @var string[] */
    public array $failures = [];
    private string $currentFile = '';
    private bool $skipCurrent = false;

    public function file(string $name): void
    {
        $this->currentFile = $name;
        $this->skipCurrent = false;
        echo "\n━━ {$name} ━━\n";
    }

    public function section(string $name): void
    {
        echo "── {$name}\n";
    }

    public function assert(string $name, bool $cond): void
    {
        if ($this->skipCurrent) { return; }
        if ($cond) {
            $this->pass++;
            echo "  ✅ {$name}\n";
        } else {
            $this->fail++;
            $this->failures[] = "[{$this->currentFile}] {$name}";
            echo "  ❌ {$name}\n";
        }
    }

    public function assertEquals(string $name, $expected, $actual): void
    {
        if ($this->skipCurrent) { return; }
        $ok = $expected === $actual;
        if ($ok) {
            $this->pass++;
            echo "  ✅ {$name}\n";
        } else {
            $this->fail++;
            $this->failures[] = "[{$this->currentFile}] {$name}";
            echo "  ❌ {$name}\n";
            echo "     انتظار: " . var_export($expected, true) . "\n";
            echo "     واقعی:  " . var_export($actual, true) . "\n";
        }
    }

    public function skip(string $reason): void
    {
        $this->skipCurrent = true;
        $this->skipped++;
        echo "  ⏭ رد شد — {$reason}\n";
    }

    public function summary(): int
    {
        echo "\n" . str_repeat('═', 56) . "\n";
        echo "🧪 نتیجه: {$this->pass} موفق | {$this->fail} ناموفق | {$this->skipped} ردشده\n";
        if ($this->failures) {
            echo "\n⛔ ناموفق‌ها:\n";
            foreach ($this->failures as $f) { echo "  • {$f}\n"; }
        }
        echo str_repeat('═', 56) . "\n";
        return $this->fail > 0 ? 1 : 0;
    }
}

$T = new TestRunner();
$updateBaseline = in_array('--update-baseline', $argv, true);
$filter = '';
foreach ($argv as $a) {
    if (strpos($a, '--filter=') === 0) { $filter = substr($a, 9); }
}

/* کشف و اجرای فایل‌های تست */
$testDir = __DIR__;
$files = glob($testDir . '/*.test.php');
sort($files);
if (!$files) {
    echo "⛔ هیچ فایل تستی یافت نشد\n";
    exit(1);
}
foreach ($files as $f) {
    $base = basename($f, '.test.php');
    if ($filter !== '' && strpos($base, $filter) === false) { continue; }
    $T->file($base);
    try {
        (static function () use ($f, $T, $updateBaseline): void {
            $GLOBALS['T'] = $T;
            $GLOBALS['updateBaseline'] = $updateBaseline;
            require $f;
        })();
    } catch (Throwable $e) {
        $T->assert('استثنای غیرمنتظره: ' . $e->getMessage(), false);
    }
}
exit($T->summary());
