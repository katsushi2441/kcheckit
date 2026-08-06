<?php
/**
 * STORE — 検知した内容を静的HTMLに保存し、恒久URLを作る。
 *
 * 【なぜCMSを入れないのか】
 * 元にした kzabbix は詳細をBluditブログへ投稿し、メールにはそのURLだけ
 * 載せている。この分担は良い（メールが短く保たれ、後から全文を追える）。
 * ただし欲しいのは「恒久URL」であって、CMSそのものではない。
 *
 * Bluditを同梱すると825ファイル・13MBが付いてくる。このシステム本体は
 * 10ファイル程度なので、127倍の荷物を「保存先」のために抱えることになる。
 * 設置手順も増え（管理者初期設定・パーミッション・APIトークン）、
 * 「FTPで上げるだけ」が言えなくなる。
 *
 * 静的HTMLを書き出すだけで同じ目的を達成できるので、既定はこちらにした。
 * Bludit や WordPress へ投稿したい人は kchk_store_report() の最後に
 * 数行足せばよい（docs/03-exercises.md に練習問題として置いてある）。
 *
 * PHP 5.x でも動く構文だけを使う。
 */
require_once __DIR__ . '/kcheckit_lib.php';

/**
 * 報告書を1枚のHTMLとして保存する。
 *
 * @return string 保存したファイル名（失敗したら空文字）
 */
function kchk_store_report($items, $related, $checked_at) {
    if (!is_dir(KCHK_REPORT_DIR) && !@mkdir(KCHK_REPORT_DIR, 0705, true)) { return ''; }

    $name = date('Y-m-d-His', $checked_at) . '-' . kchk_random_hex(4) . '.html';
    $path = KCHK_REPORT_DIR . '/' . $name;

    $org = kchk_org_name();
    $h = 'kchk_h';

    $html  = "<!doctype html>\n<html lang=\"ja\">\n<head>\n";
    $html .= "<meta charset=\"utf-8\">\n";
    $html .= "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    // 保存物は検索避けにする。取引先や競合の監視内容が外へ出ないように。
    $html .= "<meta name=\"robots\" content=\"noindex, nofollow\">\n";
    $html .= "<title>" . $h('チェック結果 ' . date('Y-m-d H:i', $checked_at)) . "</title>\n";
    $html .= "<style>\n"
           . "body{font-family:-apple-system,'Hiragino Sans','Yu Gothic',Meiryo,sans-serif;"
           . "line-height:1.8;color:#12202f;background:#f5fbfb;margin:0;padding:24px}\n"
           . ".wrap{max-width:860px;margin:0 auto}\n"
           . "h1{font-size:21px;margin:0 0 6px}h2{font-size:16px;margin:28px 0 10px}\n"
           . ".meta{color:#55697a;font-size:13px;margin-bottom:20px}\n"
           . ".card{background:#e7f3f2;border:1.5px solid #cde5e2;border-radius:14px;padding:16px;margin-bottom:12px}\n"
           . ".card.plain{background:#fff}\n"
           . ".reason{color:#0a726b;font-size:12.5px;margin-top:4px}\n"
           . ".src{color:#55697a;font-size:12px}\n"
           . "a{color:#0a726b}\n"
           . "ul{padding-left:20px;font-size:14px}li{margin-bottom:7px}\n"
           . "</style>\n</head>\n<body>\n<div class=\"wrap\">\n";

    $html .= "<h1>チェック結果</h1>\n";
    $html .= "<p class=\"meta\">" . $h(date('Y年n月j日 H:i', $checked_at))
           . ($org !== '' ? '　/　' . $h($org) : '')
           . "　/　新着 " . count($items) . " 件中、関連 " . count($related) . " 件</p>\n";

    if ($related) {
        $html .= "<h2>関連する可能性のあるもの（" . count($related) . "件）</h2>\n";
        foreach ($related as $r) {
            $html .= "<div class=\"card\">\n";
            $t = $h($r['title']);
            $html .= !empty($r['url'])
                ? "  <a href=\"" . $h($r['url']) . "\" target=\"_blank\" rel=\"noopener\"><b>" . $t . "</b></a>\n"
                : "  <b>" . $t . "</b>\n";
            $html .= "  <div class=\"src\">" . $h($r['source_name'])
                   . (!empty($r['date']) ? '　' . $h($r['date']) : '') . "</div>\n";
            if (!empty($r['reason'])) {
                $html .= "  <div class=\"reason\">関連と見た理由: " . $h($r['reason']) . "</div>\n";
            }
            $html .= "</div>\n";
        }
        $html .= "<p class=\"src\">※ 関連の有無を機械的に絞り込んだものです。"
               . "対応が必要かどうかの判断は行っていません。内容は必ず原典でご確認ください。</p>\n";
    } else {
        $html .= "<div class=\"card plain\"><p>関連しそうなものはありませんでした。</p></div>\n";
    }

    $others = array();
    foreach ($items as $it) {
        if (empty($it['related'])) { $others[] = $it; }
    }
    if ($others) {
        $html .= "<h2>その他の新着（" . count($others) . "件）</h2>\n<ul>\n";
        foreach ($others as $o) {
            $t = $h($o['title']);
            $html .= "<li>" . (!empty($o['url'])
                ? "<a href=\"" . $h($o['url']) . "\" target=\"_blank\" rel=\"noopener\">" . $t . "</a>"
                : $t) . " <span class=\"src\">" . $h($o['source_name']) . "</span></li>\n";
        }
        $html .= "</ul>\n";
    }

    $html .= "<p class=\"src\" style=\"margin-top:30px\">Kurage Check It</p>\n";
    $html .= "</div>\n</body>\n</html>\n";

    if (@file_put_contents($path, $html, LOCK_EX) === false) { return ''; }
    @chmod($path, 0644);
    return $name;
}

/** 保存した報告書の公開URL。メールにはこれを載せる。 */
function kchk_report_url($file) {
    if ($file === '') { return ''; }
    return kchk_report_base_url() . '/' . $file;
}

/** 報告書が増え続けないよう、古いものを消す。 */
function kchk_cleanup_reports($keep = 200) {
    if (!is_dir(KCHK_REPORT_DIR)) { return; }
    $files = glob(KCHK_REPORT_DIR . '/*.html');
    if (!$files || count($files) <= $keep) { return; }
    sort($files);
    foreach (array_slice($files, 0, count($files) - $keep) as $f) { @unlink($f); }
}
