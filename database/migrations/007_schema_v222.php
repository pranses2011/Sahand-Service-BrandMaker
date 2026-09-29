<?php
/**
 * 🗃️ مهاجرت schema_v222 — استخراج‌شده از config.php (v2.43 / S14)
 * 
 *
 * @package SahandBrandMaker\Database\Migrations
 * @runner MigrationRunner (core/MigrationRunner.php)
 */
return [
    'name'  => 'schema_v222',
    'order' => 7,
    'up'    => static function (): void {
            $dsn5 = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
            $probe5 = new PDO($dsn5, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3]);
            $probe5 = null;
            $pdo = Database::getInstance()->pdo();

            $rowWs = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'websearch_settings'")->fetch();
            if ($rowWs) {
                $ws = json_decode((string)$rowWs['setting_value'], true);
                if (is_array($ws) && (int)($ws['rate_per_hour'] ?? 0) < 240) {
                    $ws['rate_per_hour'] = 240;
                    $stmt = $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = 'websearch_settings'");
                    $stmt->execute([json_encode($ws, JSON_UNESCAPED_UNICODE)]);
                }
            }

    },
];
