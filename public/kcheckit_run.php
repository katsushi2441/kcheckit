<?php
/**
 * RUN — CHECK → JUDGE → STORE → NOTIFY を1回まわす。
 *
 * このファイルが Kurage Check It の本体。呼び方は2つある。
 *
 *   1. 画面の「今すぐチェック」ボタン（kcheckit.php から）
 *   2. cronでの定期実行
 *        0 8 * * *  cd /path/to/public && php kcheckit_run.php >> /path/to/kcheckit.log 2>&1
 *
 * cronが使えないサーバーでも、ボタンさえ押せば使える。
 * 逆にcronを入れれば、朝メールが届くだけの運用になる。
 *
 * PHP 5.x でも動く構文だけを使う。
 */
require_once __DIR__ . '/kcheckit_lib.php';
require_once __DIR__ . '/kcheckit_check.php';
require_once __DIR__ . '/kcheckit_judge.php';
require_once __DIR__ . '/kcheckit_store.php';
require_once __DIR__ . '/kcheckit_notify.php';

/**
 * 1回まわす。
 *
 * @param bool $notify メールを送るか（画面から試すときは false にできる）
 * @return array 結果のまとめ
 */
function kchk_run($notify = true) {
    $started = time();
    $sources = kchk_sources_enabled();

    $profile  = kchk_profile();
    $keywords = kchk_keywords($profile);

    $items   = array();
    $related = array();
    $checked = 0;
    $failed  = 0;

    foreach ($sources as $s) {
        list($ok, $found, $status, $error) = kchk_check_source($s);
        kchk_record_check($s['id'], $ok, $status, $error);
        $checked++;
        if (!$ok) { $failed++; continue; }

        // 前回に無かったものだけ拾う。初回は記録だけして黙る
        // （導入直後に何十通も通知が飛ぶと、それだけで使うのをやめられる）
        $new = kchk_diff_new($s['id'], $found);

        foreach ($new as $it) {
            $judge = kchk_judge_item($it, $profile, $keywords);
            $row = array(
                'title'       => $it['title'],
                'url'         => $it['url'],
                'date'        => $it['date'],
                'source_name' => $s['name'],
                'source_id'   => $s['id'],
                'related'     => $judge['related'],
                'reason'      => $judge['reason'],
                'judged_by'   => $judge['by'],
            );
            $items[] = $row;
            if ($judge['related']) { $related[] = $row; }
        }
    }

    // 保存（新着があるときだけ報告書を作る）
    $report_file = '';
    if ($items) {
        $report_file = kchk_store_report($items, $related, $started);
        kchk_cleanup_reports();
    }
    $report_url = kchk_report_url($report_file);

    // 記録
    $summary = '新着' . count($items) . '件 / 関連' . count($related) . '件'
             . '（監視' . $checked . '件中' . $failed . '件失敗）';
    $res = kchk_save_finding($items, $related, $report_file, $summary);
    $finding = isset($res[1]) && is_array($res[1]) ? $res[1] : null;

    // 通知（新着が無いときは送らない。毎日「ありません」が届くと読まれなくなる）
    $sent = false;
    if ($notify && $items) {
        $sent = kchk_send_notification($items, $related, $report_url, $started);
        if ($finding) { kchk_mark_notified($finding['id'], $sent); }
    }

    // 監視そのものが壊れていたら別途知らせる
    $broken = kchk_broken_sources();
    if ($notify && $broken) { kchk_send_broken_alert($broken); }

    return array(
        'checked'     => $checked,
        'failed'      => $failed,
        'items'       => count($items),
        'related'     => count($related),
        'report_url'  => $report_url,
        'notified'    => $sent,
        'broken'      => count($broken),
        'summary'     => $summary,
        'elapsed'     => time() - $started,
    );
}

/* ---- コマンドラインから呼ばれたとき（cron用）---- */
if (PHP_SAPI === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    date_default_timezone_set('Asia/Tokyo');
    $r = kchk_run(true);
    printf("[%s] 監視%d件 新着%d件 関連%d件 失敗%d件 %s\n",
        date('Y-m-d H:i:s'), $r['checked'], $r['items'], $r['related'], $r['failed'],
        $r['report_url'] !== '' ? $r['report_url'] : '(報告書なし)');
    exit($r['failed'] > 0 && $r['checked'] === $r['failed'] ? 1 : 0);
}
