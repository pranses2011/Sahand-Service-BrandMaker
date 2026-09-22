<?php
/**
 * 🛡️ کلاس بررسی یکتایی محتوا — ضد محتوای تکراری
 * ================================================
 * با الگوریتم Shingle (n-gram) میزان تشابه محتوای جدید
 * با محتوای قبلاً تولیدشده را می‌سنجد تا هیچ دو سایتی
 * متن یکسان نداشته باشند (جلوگیری از Duplicate Content).
 *
 * @package SahandBrandMaker\Engine
 * @version 1.0.0
 */
class UniquenessChecker
{
    /** @var Database نمونه دیتابیس */
    private $db;

    /** @var int آستانه تشابه مجاز (درصد) */
    const MAX_SIMILARITY = 35;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * ✅ بررسی یکتایی متن در برابر تمام محتوای موجود
     *
     * @param string      $content متن جدید
     * @param int         $brandId شناسه برند هدف (برای استثنا)
     * @param string|null $excludeTable جدول مقایسه (brand_articles یا brand_pages)
     * @return array ['unique' => bool, 'similarity' => float, 'matched_with' => string, 'hash' => string]
     */
    public function check(string $content, int $brandId = 0, ?string $excludeTable = null): array
    {
        $hash = TextProcessor::contentHash($content);
        $shingles = TextProcessor::shingles($content, 3);
        if (empty($shingles)) {
            return ['unique' => false, 'similarity' => 100.0, 'matched_with' => 'متن خالی', 'hash' => $hash];
        }
        $shingleSet = array_count_values($shingles);

        // ۱️⃣ بررسی هش دقیق — محتوای عیناً یکسان
        $exact = $this->db->fetch(
            'SELECT id, title FROM brand_articles WHERE uniqueness_hash = ? LIMIT 1',
            [$hash]
        );
        if ($exact) {
            return ['unique' => false, 'similarity' => 100.0, 'matched_with' => 'مقاله: ' . $exact['title'], 'hash' => $hash];
        }

        // ۲️⃣ بررسی تشابه Shingle با مقالات موجود
        $worst = ['similarity' => 0.0, 'matched_with' => ''];
        $articles = $this->db->fetchAll(
            'SELECT id, brand_id, title, content FROM brand_articles ORDER BY id DESC LIMIT 500'
        );
        foreach ($articles as $article) {
            if ((int)$article['brand_id'] === $brandId) {
                continue; // محتوای خود برند مقایسه نمی‌شود
            }
            $sim = $this->similarity($shingleSet, $article['content']);
            if ($sim > $worst['similarity']) {
                $worst = ['similarity' => $sim, 'matched_with' => 'مقاله: ' . $article['title']];
                if ($sim >= 100.0) {
                    break;
                }
            }
        }

        // ۳️⃣ بررسی با محتوای صفحات برندهای دیگر
        if ($worst['similarity'] < self::MAX_SIMILARITY) {
            $pages = $this->db->fetchAll(
                'SELECT brand_id, page_type, content FROM brand_pages WHERE content IS NOT NULL AND content != "" LIMIT 800'
            );
            foreach ($pages as $page) {
                if ((int)$page['brand_id'] === $brandId) {
                    continue;
                }
                $sim = $this->similarity($shingleSet, $page['content']);
                if ($sim > $worst['similarity']) {
                    $worst = ['similarity' => $sim, 'matched_with' => 'صفحه: ' . $page['page_type']];
                }
            }
        }

        return [
            'unique'       => $worst['similarity'] < self::MAX_SIMILARITY,
            'similarity'   => round($worst['similarity'], 2),
            'matched_with' => $worst['matched_with'],
            'hash'         => $hash,
        ];
    }

    /**
     * 📊 محاسبه درصد تشابه دو متن با Jaccard روی 3-gram
     *
     * @param array  $shingleSet مجموعه shingle متن جدید (array_count_values)
     * @param string $other متن مقایسه‌شونده
     */
    public function similarity(array $shingleSet, string $other): float
    {
        $otherShingles = array_count_values(TextProcessor::shingles($other, 3));
        if (empty($otherShingles)) {
            return 0.0;
        }
        $intersection = 0;
        foreach ($shingleSet as $shingle => $count) {
            if (isset($otherShingles[$shingle])) {
                $intersection += min($count, $otherShingles[$shingle]);
            }
        }
        $union = array_sum($shingleSet) + array_sum($otherShingles) - $intersection;
        return $union > 0 ? ($intersection / $union) * 100 : 0.0;
    }

    /**
     * 🔁 تلاش مجدد برای یکتا کردن محتوا
     * متن را با seed های مختلف بازتولید می‌کند تا یکتا شود
     *
     * @param callable $regenerator تابع بازتولید متن (ورودی: seed، خروجی: متن)
     * @param int      $maxTries حداکثر تلاش
     * @param int      $brandId شناسه برند
     * @return array ['content' => string, 'attempts' => int, 'result' => array]
     */
    public function ensureUnique(callable $regenerator, int $maxTries = 5, int $brandId = 0): array
    {
        $attempts = 0;
        $best = null;
        for ($i = 0; $i < $maxTries; $i++) {
            $attempts++;
            $seed = 'attempt-' . $i . '-' . $brandId . '-' . mt_rand();
            $content = $regenerator($seed);
            $result = $this->check($content, $brandId);
            if ($result['unique']) {
                return ['content' => $content, 'attempts' => $attempts, 'result' => $result];
            }
            if ($best === null || $result['similarity'] < $best['result']['similarity']) {
                $best = ['content' => $content, 'attempts' => $attempts, 'result' => $result];
            }
        }
        return $best ?? ['content' => '', 'attempts' => 0, 'result' => []];
    }

    /**
     * 📈 گزارش یکتایی کل سیستم (برای داشبورد)
     */
    public function systemReport(): array
    {
        $total = $this->db->count('brand_articles');
        $withHash = $this->db->count('brand_articles', 'uniqueness_hash IS NOT NULL');
        $duplicateHashes = $this->db->fetchAll(
            'SELECT uniqueness_hash, COUNT(*) as c FROM brand_articles
             WHERE uniqueness_hash IS NOT NULL GROUP BY uniqueness_hash HAVING c > 1'
        );
        return [
            'total_articles'   => $total,
            'analyzed'         => $withHash,
            'duplicate_groups' => count($duplicateHashes),
            'health'           => $total === 0 ? '—' : (count($duplicateHashes) === 0 ? '✅ یکتا' : '⚠️ نیازمند بررسی'),
        ];
    }
}
