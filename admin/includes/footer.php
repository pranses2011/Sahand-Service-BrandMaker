<?php
/**
 * 🔻 فوتر مشترک پنل مدیریت
 * ==========================
 * @package SahandBrandMaker
 */
?>
        </main><!-- /content -->

        <footer style="padding:16px 26px;text-align:center;color:var(--text-light);font-size:11.5px;border-top:1px solid var(--border)">
            🏗️ سایت ساز برند <?= e(Config::get(Config::KEY_AGENCY_NAME_FA) ?: 'سهند سرویس') ?> — نسخه <?= SAHAND_VERSION ?>
            | ⚙️ PHP <?= PHP_VERSION ?> | 🗄️ MySQL
        </footer>
    </div><!-- /main -->
</div><!-- /layout -->

<!-- admin.js از نسخه ۲.۴.۱ در هدر با defer بارگذاری می‌شود تا منو حتی در صورت خطای رندر کار کند -->
</body>
</html>
