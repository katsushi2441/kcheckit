<?php
// 実際の情報源に接続して、4方式すべてが動くことを確かめる。
// ネットワークに出るので、CIではなく手元での確認用。
//
// 実行: php scripts/check_live.php

$tmp = sys_get_temp_dir() . '/kcheckit-live-' . getmypid();
@mkdir($tmp, 0700, true);
define('KCHK_DATA_DIR', $tmp);
define('KCHK_REPORT_DIR', $tmp);
define('KCHK_CONFIG_FILE', '');
define('KCHK_NOTIFY_EMAIL', 'x@example.test');

require_once __DIR__ . '/../public/kcheckit_lib.php';
require_once __DIR__ . '/../public/kcheckit_check.php';

$targets = array(
    array('name' => 'JPCERT 注意喚起', 'method' => 'rss',
          'url' => 'https://www.jpcert.or.jp/rss/jpcert.rdf', 'note' => ''),
    array('name' => 'IPA 重要なお知らせ', 'method' => 'rss',
          'url' => 'https://www.ipa.go.jp/security/alert-rss.rdf', 'note' => ''),
    array('name' => '厚生労働省 新着', 'method' => 'rss',
          'url' => 'https://www.mhlw.go.jp/stf/news.rdf', 'note' => ''),
    array('name' => 'jGrants 募集中の補助金', 'method' => 'json',
          'url' => 'https://api.jgrants-portal.go.jp/exp/v1/public/subsidies?keyword=%E8%A3%9C%E5%8A%A9&sort=created_date&order=DESC&acceptance=1',
          'note' => 'list=result;title=title;date=acceptance_end_datetime'),
    array('name' => 'e-Gov 法令一覧', 'method' => 'json',
          'url' => 'https://laws.e-gov.go.jp/api/2/laws?limit=1000&offset=0&response_format=json',
          'note' => 'list=laws;sort=updated;pages=10'),
    array('name' => '消費者庁 政策', 'method' => 'html',
          'url' => 'https://www.caa.go.jp/policies/policy/', 'note' => 'contains=/policies/'),
    array('name' => '環境省 報道発表', 'method' => 'html',
          'url' => 'https://www.env.go.jp/press/', 'note' => 'contains=/press/'),
    array('name' => '自社サイトのSSL期限', 'method' => 'tls',
          'url' => 'https://kurage.exbridge.jp/', 'note' => ''),
);

$ng = 0;
foreach ($targets as $i => $t) {
    $t['id'] = sprintf('%016d', $i);
    list($ok, $items, $status, $error) = kchk_check_source($t);
    printf("%s %-24s %-5s %s\n",
        $ok ? 'ok  ' : 'NG  ', $t['name'], $t['method'],
        $ok ? count($items) . '件  ' . ($items ? mb_strimwidth($items[0]['title'], 0, 46, '…', 'UTF-8') : '(期限に余裕あり)')
            : $error);
    if (!$ok) { $ng++; }
}

foreach (glob($tmp . '/snapshots/*') as $f) { @unlink($f); }
@rmdir($tmp . '/snapshots'); @rmdir($tmp);

echo $ng === 0 ? "\n8つの情報源すべてから取得できました\n" : "\n{$ng} 件が取得できませんでした\n";
exit($ng === 0 ? 0 : 1);
