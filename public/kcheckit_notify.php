<?php
/**
 * NOTIFY — 要点と保存先URLをメールで送る。
 *
 * 【メールに全文を書かない】
 * 元にした kzabbix は、詳細をブログに保存し、メールには要点と
 * そのURLだけを載せている。これが効いていて、
 *   - メールが短く保たれ、毎日届いても読む気になる
 *   - 後から全文を追える（メールを探し直さなくてよい）
 *   - 転送しても、受け取った人が同じものを見られる
 * という利点がある。この分担をそのまま踏襲した。
 *
 * PHP 5.x でも動く構文だけを使う。
 */
require_once __DIR__ . '/kcheckit_lib.php';
require_once __DIR__ . '/kcheckit_store.php';

function kchk_mail_subject($related_count, $total) {
    $org = kchk_org_name();
    $head = $org !== '' ? '【' . $org . '】' : '【Check It】';
    if ($related_count > 0) {
        return $head . '関連する新着が ' . $related_count . ' 件あります';
    }
    return $head . '本日の新着 ' . $total . ' 件（関連なし）';
}

function kchk_mail_body($items, $related, $report_url, $checked_at) {
    $body = date('Y年n月j日 H:i', $checked_at) . " のチェック結果です。\n\n";
    $body .= "新着 " . count($items) . " 件 / 関連する可能性 " . count($related) . " 件\n";
    $body .= "──────────────────────────\n\n";

    if ($related) {
        // 要点だけ。全文は報告書URLで見てもらう
        $n = 0;
        foreach ($related as $r) {
            $n++;
            $body .= $n . '. ' . $r['title'] . "\n";
            $body .= '   出所: ' . $r['source_name'];
            if (!empty($r['date'])) { $body .= '　' . $r['date']; }
            $body .= "\n";
            if (!empty($r['reason'])) { $body .= '   理由: ' . $r['reason'] . "\n"; }
            if (!empty($r['url']))    { $body .= '   ' . $r['url'] . "\n"; }
            $body .= "\n";
            if ($n >= 10) {
                $body .= '   ほか ' . (count($related) - 10) . " 件は報告書をご覧ください。\n\n";
                break;
            }
        }
    } else {
        $body .= "関連しそうなものはありませんでした。\n\n";
    }

    if ($report_url !== '') {
        $body .= "▼ すべての新着と詳細はこちら\n" . $report_url . "\n\n";
    }
    $body .= "──────────────────────────\n";
    $body .= "※ 関連の有無を機械的に絞り込んだものです。\n";
    $body .= "※ 対応が必要かどうかの判断は行っていません。内容は必ず原典でご確認ください。\n";
    if (kchk_org_name() !== '') { $body .= "\n" . kchk_org_name() . "\n"; }
    return $body;
}

/**
 * 通知を送る。
 *
 * デモモードでは実際に送らない。公開デモに送信機能を置くと、
 * 誰でも任意のアドレス宛に送れる踏み台になり、そのドメインの送信評価が
 * 落ちて本物の通知まで届かなくなるため。
 */
function kchk_send_notification($items, $related, $report_url, $checked_at) {
    if (kchk_is_demo()) { return true; }
    $to = kchk_notify_email();
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { return false; }

    $from = defined('KCHK_MAIL_FROM') ? KCHK_MAIL_FROM : $to;
    $name = kchk_org_name() !== '' ? kchk_org_name() : 'Kurage Check It';
    $headers = implode("\r\n", array(
        'From: ' . $name . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: Kurage Check It',
    ));
    return @mail(
        $to,
        '=?UTF-8?B?' . base64_encode(kchk_mail_subject(count($related), count($items))) . '?=',
        chunk_split(base64_encode(kchk_mail_body($items, $related, $report_url, $checked_at))),
        $headers
    );
}

/**
 * 監視が壊れていることを知らせる。
 *
 * HTMLの差分監視は、相手がページ構成を変えると黙って0件になる。
 * 「監視しているつもりで何も見ていない」状態が一番まずいので、
 * 続けて失敗している対象があれば別途知らせる。
 */
function kchk_send_broken_alert($broken) {
    if (kchk_is_demo() || !$broken) { return false; }
    $to = kchk_notify_email();
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) { return false; }

    $body = "監視が続けて失敗している対象があります。\n";
    $body .= "相手のサイト構成が変わった可能性があります。設定をご確認ください。\n\n";
    foreach ($broken as $b) {
        $body .= '・' . $b['name'] . '（' . (int)$b['fail_count'] . "回連続で失敗）\n";
        $body .= '  ' . $b['url'] . "\n";
        if (!empty($b['last_error'])) { $body .= '  ' . $b['last_error'] . "\n"; }
        $body .= "\n";
    }
    $from = defined('KCHK_MAIL_FROM') ? KCHK_MAIL_FROM : $to;
    $headers = implode("\r\n", array(
        'From: Kurage Check It <' . $from . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ));
    return @mail($to, '=?UTF-8?B?' . base64_encode('【Check It】監視が失敗しています') . '?=',
        chunk_split(base64_encode($body)), $headers);
}
