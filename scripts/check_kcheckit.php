<?php
// kcheckit の台帳・差分検知・判定・保存・通知の動作確認。
// 外部サイトへは接続しない（どこでも実行できる）。実サイトでの確認は
// scripts/check_live.php を使う。
//
// 実行: php scripts/check_kcheckit.php

$tmp = sys_get_temp_dir() . '/kcheckit-check-' . getmypid();
@mkdir($tmp . '/reports', 0700, true);
define('KCHK_DATA_DIR', $tmp);
define('KCHK_REPORT_DIR', $tmp . '/reports');
define('KCHK_CONFIG_FILE', '');
define('KCHK_SITE_URL', 'https://example.co.jp/');
define('KCHK_NOTIFY_EMAIL', 'tantou@example.test');
define('KCHK_ORG_NAME', 'テスト建設株式会社');
define('KCHK_MAIL_FROM', 'noreply@example.test');
define('KCHK_KEYWORDS', '建設,産業廃棄物,内装,労働安全');
define('KCHK_REPORT_BASE_URL', 'https://example.test/reports');

require_once __DIR__ . '/../public/kcheckit_lib.php';
require_once __DIR__ . '/../public/kcheckit_check.php';
require_once __DIR__ . '/../public/kcheckit_judge.php';
require_once __DIR__ . '/../public/kcheckit_store.php';
require_once __DIR__ . '/../public/kcheckit_notify.php';

$failures = 0;
function check($label, $actual, $expected) {
    global $failures;
    $ok = $actual === $expected;
    if (!$ok) { $failures++; }
    printf("%s %s (期待 %s / 実際 %s)\n", $ok ? 'ok  ' : 'NG  ', $label,
        var_export($expected, true), var_export($actual, true));
}

/* ============================================================
 * 1. 監視対象の登録
 * ========================================================== */
check('最初は0件', count(kchk_sources()), 0);

$r = kchk_add_source('JPCERT 注意喚起', 'https://www.jpcert.or.jp/rss/jpcert.rdf', 'rss');
check('追加できる', $r[0], true);
check('1件になる', count(kchk_sources()), 1);

check('同じURL・同じ方式は拒否',
    kchk_add_source('重複', 'https://www.jpcert.or.jp/rss/jpcert.rdf', 'rss')[0], false);
// 同じURLでも方式が違えば別物として登録できる
check('方式が違えば登録できる',
    kchk_add_source('別方式', 'https://www.jpcert.or.jp/rss/jpcert.rdf', 'html')[0], true);

check('URLでないものは拒否', kchk_add_source('x', 'not-a-url', 'rss')[0], false);
check('ftpは拒否',           kchk_add_source('x', 'ftp://example.com/', 'rss')[0], false);
check('名前が空なら拒否',    kchk_add_source('', 'https://example.com/', 'rss')[0], false);
// 未知の方式は既定(rss)に倒す。画面から変な値が来ても壊れないように
$r = kchk_add_source('未知方式', 'https://example.com/unknown', 'まほう');
check('未知の方式はrssに倒す', kchk_find_source($r[1]) === null ? 'なし' : 'あり', 'なし');
$all = kchk_sources();
check('未知方式でも登録はされる', $all[count($all) - 1]['method'], 'rss');

$sid = $all[0]['id'];
check('停止できる', kchk_toggle_source($sid)[0], true);
check('停止すると有効一覧から消える', count(kchk_sources_enabled()), count($all) - 1);
kchk_toggle_source($sid);
check('再開できる', count(kchk_sources_enabled()), count($all));

/* ============================================================
 * 2. 差分検知（このシステムの心臓部）
 * ========================================================== */
$items1 = array(
    array('key' => 'a', 'title' => '記事A', 'url' => 'https://x.test/a', 'date' => ''),
    array('key' => 'b', 'title' => '記事B', 'url' => 'https://x.test/b', 'date' => ''),
);
// 初回は全件が新着になってしまうので、記録だけして黙る。
// 導入直後に何十通も通知が飛ぶと、それだけで使うのをやめられる。
check('初回は黙る（記録のみ）', count(kchk_diff_new('src1', $items1)), 0);

$items2 = array(
    array('key' => 'a', 'title' => '記事A', 'url' => 'https://x.test/a', 'date' => ''),
    array('key' => 'c', 'title' => '記事C', 'url' => 'https://x.test/c', 'date' => ''),
);
$new = kchk_diff_new('src1', $items2);
check('2回目は新着だけ返す', count($new), 1);
check('新着はCである', $new[0]['key'], 'c');

// 一覧から落ちた古い記事を再検知しない（前回分と混ぜて保存しているか）
$items3 = array(array('key' => 'b', 'title' => '記事B', 'url' => 'https://x.test/b', 'date' => ''));
check('一覧から消えて再登場しても再検知しない', count(kchk_diff_new('src1', $items3)), 0);

check('変化がなければ0件', count(kchk_diff_new('src1', $items3)), 0);

/* ============================================================
 * 3. 関連判定
 *
 * この層で守っているのは「AIに対応要否を判断させない」こと。
 * 出すのは「関係あり」と理由だけ。
 * ========================================================== */
$kw = kchk_keywords('');
check('設定のキーワードを使う', in_array('産業廃棄物', $kw, true), true);

$j = kchk_judge_item(
    array('title' => '産業廃棄物処理法の一部改正について', 'url' => 'https://x.test/1'), '', $kw);
check('関係あると判定', $j['related'], true);
check('理由が入る', $j['reason'] !== '', true);
check('判定根拠が分かる', $j['by'], 'keyword');

$j2 = kchk_judge_item(
    array('title' => '水産物の輸出促進に関する説明会', 'url' => 'https://x.test/2'), '', $kw);
check('無関係は関係なし', $j2['related'], false);
check('無関係には理由を付けない', $j2['reason'], '');

// LLM未設定でも動く（キーワードだけで完結する）
check('LLM未設定でも動く', kchk_llm_enabled(), false);

/* ============================================================
 * 4. 監視の失敗を数える
 *
 * HTML差分は相手のページ構成が変わると黙って0件になる。
 * 「監視しているつもりで何も見ていない」が一番まずいので、
 * 連続失敗を数えて気づけるようにする。
 * ========================================================== */
kchk_record_check($sid, false, 200, 'リンクを1件も拾えませんでした');
kchk_record_check($sid, false, 200, 'リンクを1件も拾えませんでした');
check('失敗を数える', (int)kchk_find_source($sid)['fail_count'], 2);
check('2回では警告しない', count(kchk_broken_sources(3)), 0);
kchk_record_check($sid, false, 200, '同上');
check('3回連続で警告に出る', count(kchk_broken_sources(3)), 1);
check('エラー内容が残る', kchk_find_source($sid)['last_error'], '同上');

kchk_record_check($sid, true, 200, '');
check('成功したら失敗数が戻る', (int)kchk_find_source($sid)['fail_count'], 0);
check('警告も消える', count(kchk_broken_sources(3)), 0);
check('成功時はエラーを消す', kchk_find_source($sid)['last_error'], '');

/* ============================================================
 * 5. 報告書の保存（恒久URL）
 * ========================================================== */
$items = array(
    array('title' => '産業廃棄物処理法の改正', 'url' => 'https://x.test/1', 'date' => '2026-08-07',
          'source_name' => '環境省', 'source_id' => 's1', 'related' => true,
          'reason' => '「産業廃棄物」が見出しに含まれます', 'judged_by' => 'keyword'),
    array('title' => '水産物の輸出促進', 'url' => 'https://x.test/2', 'date' => '',
          'source_name' => '水産庁', 'source_id' => 's2', 'related' => false,
          'reason' => '', 'judged_by' => 'keyword'),
);
$related = array($items[0]);
$file = kchk_store_report($items, $related, time());
check('報告書が作られる', $file !== '', true);
check('HTMLである', substr($file, -5), '.html');
$html = file_get_contents(KCHK_REPORT_DIR . '/' . $file);
check('関連ありが載る', strpos($html, '産業廃棄物処理法の改正') !== false, true);
check('その他の新着も載る', strpos($html, '水産物の輸出促進') !== false, true);
check('判定理由が載る', strpos($html, 'が見出しに含まれます') !== false, true);
// 監視内容が検索に出てしまうと、取引先や競合の監視が外に漏れる
check('検索避けが入っている', strpos($html, 'noindex') !== false, true);
check('対応要否は判断していないと明記',
    strpos($html, '対応が必要かどうかの判断は行っていません') !== false, true);
check('報告書URLが組める',
    kchk_report_url($file), 'https://example.test/reports/' . $file);
check('ファイルが無ければ空URL', kchk_report_url(''), '');

/* ============================================================
 * 6. 通知メール
 * ========================================================== */
$body = kchk_mail_body($items, $related, 'https://example.test/reports/x.html', time());
check('本文に関連ありが入る', strpos($body, '産業廃棄物処理法の改正') !== false, true);
check('本文に理由が入る',     strpos($body, '見出しに含まれます') !== false, true);
check('本文に報告書URLが入る', strpos($body, 'https://example.test/reports/x.html') !== false, true);
// メールに全文を書かない（kzabbixの分担を踏襲）。要点＋URLに留める
check('その他の新着は本文に書かない', strpos($body, '水産物の輸出促進') !== false, false);
check('免責が入る', strpos($body, '対応が必要かどうかの判断は行っていません') !== false, true);
check('件名に件数', strpos(kchk_mail_subject(1, 2), '1 件') !== false, true);
check('件名に会社名', strpos(kchk_mail_subject(1, 2), 'テスト建設株式会社') !== false, true);

// 宛先が無ければ送らない（誤送信しない）
check('宛先不正では送らない', kchk_send_broken_alert(array()), false);

/* ============================================================
 * 7. 履歴
 * ========================================================== */
$res = kchk_save_finding($items, $related, $file, 'テスト');
check('履歴を保存できる', $res[0], true);
check('履歴を引ける', count(kchk_findings()), 1);
check('件数が残る', (int)kchk_findings()[0]['related'], 1);
check('通知済みを記録できる', kchk_mark_notified($res[1]['id'], true)[0], true);
check('通知済みになる', kchk_findings()[0]['notified'], true);

/* ============================================================
 * 8. 並べ替え
 *
 * e-Gov法令APIは既定の並びが公布順に近く、そのまま先頭を取ると
 * 明治時代の布告ばかりになる（実測で確認）。APIに並べ替えの
 * パラメータが無いので、取得後にこちらで並べ替える。
 * ========================================================== */
$raw = array(
    array('a' => array('id' => '1'), 'b' => array('t' => '古いもの', 'updated' => '2024-02-15')),
    array('a' => array('id' => '2'), 'b' => array('t' => '新しいもの', 'updated' => '2026-08-06')),
    array('a' => array('id' => '3'), 'b' => array('t' => '中くらい',   'updated' => '2025-06-01')),
);
$made = array(
    array('key' => '1', 'title' => '古いもの',   'url' => '', 'date' => '2024-02-15'),
    array('key' => '2', 'title' => '新しいもの', 'url' => '', 'date' => '2026-08-06'),
    array('key' => '3', 'title' => '中くらい',   'url' => '', 'date' => '2025-06-01'),
);
$sorted = kchk_sort_by_field($raw, $made, 'updated');
check('新しい順に並べ替える', $sorted[0]['title'], '新しいもの');
check('2番目も正しい',       $sorted[1]['title'], '中くらい');
check('最後は最も古い',       $sorted[2]['title'], '古いもの');
// 件数がずれたら並べ替えない（誤対応で別の記事に日付が付くのを防ぐ）
check('件数がずれたら触らない',
    kchk_sort_by_field(array_slice($raw, 0, 2), $made, 'updated')[0]['title'], '古いもの');

// 入れ子の平坦化（e-Govは law_info / revision_info に分かれている）
$flat = kchk_flatten(array('law_info' => array('law_id' => 'X'), 'revision_info' => array('law_title' => 'テスト法')));
check('入れ子から項目を拾える', $flat['law_title'], 'テスト法');
check('入れ子の別階層も拾える', $flat['law_id'], 'X');

// 一覧の場所の判定
check('list指定で見つける', count(kchk_locate_list(array('laws' => array(1, 2)), 'laws')), 2);
check('指定なしでも推測する', count(kchk_locate_list(array('result' => array(1, 2, 3)), '')), 3);
check('見つからなければnull', kchk_locate_list(array('foo' => 'bar'), ''), null);

/* ============================================================
 * 9. 補助設定のパース
 * ========================================================== */
$m = kchk_parse_map('list=result;title=title;url=detail_url');
check('mapを読める', $m['list'], 'result');
check('複数項目', $m['url'], 'detail_url');
check('空でも壊れない', count(kchk_parse_map('')), 0);
check('相対URLを絶対に', kchk_absolute_url('/a/b.html', 'https://x.test/press/index.html'), 'https://x.test/a/b.html');
check('同階層の相対URL', kchk_absolute_url('c.html', 'https://x.test/press/index.html'), 'https://x.test/press/c.html');
check('絶対URLはそのまま', kchk_absolute_url('https://y.test/z', 'https://x.test/'), 'https://y.test/z');

/* 後片付け */
foreach (glob($tmp . '/reports/*') as $f) { @unlink($f); }
foreach (glob($tmp . '/snapshots/*') as $f) { @unlink($f); }
foreach (glob($tmp . '/*') as $f) { if (is_file($f)) { @unlink($f); } }
@rmdir($tmp . '/reports'); @rmdir($tmp . '/snapshots'); @rmdir($tmp);

echo $failures === 0 ? "\nすべて期待どおり\n" : "\n{$failures} 件が期待と違う\n";
exit($failures === 0 ? 0 : 1);
