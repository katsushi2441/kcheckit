<?php
// 管理パスワードのハッシュを作る。
//   php scripts/make_password_hash.php 'あなたのパスワード'
// 出力された1行を kcheckit_config.php に貼る。平文はどこにも保存しない。
$pw = isset($argv[1]) ? $argv[1] : '';
if ($pw === '') {
    fwrite(STDERR, "使い方: php scripts/make_password_hash.php 'パスワード'\n");
    exit(1);
}
if (strlen($pw) < 8) {
    fwrite(STDERR, "パスワードは8文字以上にしてください。\n");
    exit(1);
}
echo "define('KCHK_ADMIN_PASSWORD_HASH', '" . password_hash($pw, PASSWORD_DEFAULT) . "');\n";
