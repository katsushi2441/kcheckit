<?php
// デモ用の設定。proto.exbridge.jp/kcheckit/ に置く。

// メールを実際に送らない。公開デモに送信機能を置くと、誰でも任意の
// アドレス宛に送れる踏み台になり、ドメインの送信評価が落ちる。
define('KCHK_DEMO', true);

define('KCHK_ADMIN_PASSWORD_HASH', '$2y$10$jqvS4KJAUrNtKIIe.j/G0eoGpCcLY.zaefYNuk/24nr516XNuS4qC');

// デモの想定：愛知県の建設業（内装・原状回復、産廃収集運搬の許可あり）
define('KCHK_SITE_URL', 'https://kurage.exbridge.jp/');
define('KCHK_NOTIFY_EMAIL', 'demo@example.co.jp');
define('KCHK_ORG_NAME', 'デモ建設株式会社');
define('KCHK_MAIL_FROM', 'demo@example.co.jp');
define('KCHK_BUSINESS', '愛知県で建設業。内装工事と原状回復。産業廃棄物の収集運搬業の許可あり。従業員30名。');
define('KCHK_KEYWORDS', '建設,産業廃棄物,内装,労働安全,下請,建築基準,補助金,脆弱性');
