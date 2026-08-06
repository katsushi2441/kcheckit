<?php
/**
 * Kurage Check It — 台帳・設定・共通処理。
 *
 * このシステムの骨格は4段階に分かれている。段階ごとにファイルを分けてあるので、
 * 増やしたいところだけを読めばよい。
 *
 *   CHECK  kcheckit_check.php   監視対象を取りに行く（RSS / API / HTML差分 / TLS）
 *   JUDGE  kcheckit_judge.php   前回との差分を出し、自社に関係あるか判定する
 *   STORE  kcheckit_store.php   詳細を静的HTMLに保存して恒久URLを作る
 *   NOTIFY kcheckit_notify.php  要点と保存先URLをメールで送る
 *
 * heteml にDBを置かない方針（kinvoice/kbilling/kpaylink と同じ）。
 * JSONを flock で直列化する。更新は必ず kchk_update() を通すこと。
 *
 * PHP 5.x でも動く構文だけを使う。
 */

// 設定はここで最初に読む。defineは先勝ちなので、既定値より前に読まないと
// 設定ファイルの値が効かない（画面側で読むと手遅れになる）。
if (!defined('KCHK_CONFIG_FILE')) { define('KCHK_CONFIG_FILE', __DIR__ . '/kcheckit_config.php'); }
if (KCHK_CONFIG_FILE !== '' && file_exists(KCHK_CONFIG_FILE)) { require_once KCHK_CONFIG_FILE; }

if (!defined('KCHK_DATA_DIR'))    { define('KCHK_DATA_DIR', __DIR__ . '/kcheckit_data'); }
if (!defined('KCHK_REPORT_DIR'))  { define('KCHK_REPORT_DIR', __DIR__ . '/kcheckit_reports'); }
define('KCHK_SOURCES', KCHK_DATA_DIR . '/sources.json');   // 監視対象
define('KCHK_FINDINGS', KCHK_DATA_DIR . '/findings.json'); // 検知したもの
define('KCHK_SNAPSHOT_DIR', KCHK_DATA_DIR . '/snapshots'); // 前回の状態

// 1回のチェックで1つの監視対象から拾う最大件数。
// 初回実行で何百件も通知が飛ぶのを防ぐ。
define('KCHK_MAX_ITEMS_PER_SOURCE', 30);

/** 監視方式。増やすときは kcheckit_check.php に関数を足してここに1行加える。 */
function kchk_methods() {
    return array(
        'rss'   => 'RSS / Atom フィード',
        'json'  => 'JSON API',
        'html'  => 'HTMLページの差分',
        'tls'   => 'SSL証明書の期限',
    );
}

/* ---------------- 設定 ---------------- */

/** 自社サイトのURL。関連判定の材料になる。 */
function kchk_site_url() { return defined('KCHK_SITE_URL') ? trim(KCHK_SITE_URL) : ''; }

/** 通知先。担当者のメールアドレス。 */
function kchk_notify_email() { return defined('KCHK_NOTIFY_EMAIL') ? trim(KCHK_NOTIFY_EMAIL) : ''; }

function kchk_org_name() { return defined('KCHK_ORG_NAME') ? KCHK_ORG_NAME : ''; }

/**
 * 自社の事業内容。空なら自社URLから拾う（kcheckit_judge.php）。
 * 手で書いたほうが精度が上がるので、設定に書けるようにしてある。
 */
function kchk_business_profile() {
    return defined('KCHK_BUSINESS') ? trim(KCHK_BUSINESS) : '';
}

function kchk_app_title() {
    return defined('KCHK_APP_TITLE') && KCHK_APP_TITLE !== '' ? KCHK_APP_TITLE : 'Kurage Check It';
}

/** 保存した報告書を外から開くときのURLの基点。 */
function kchk_report_base_url() {
    if (defined('KCHK_REPORT_BASE_URL') && KCHK_REPORT_BASE_URL !== '') {
        return rtrim(KCHK_REPORT_BASE_URL, '/');
    }
    return kchk_base_url() . '/kcheckit_reports';
}

function kchk_base_url() {
    if (defined('KCHK_BASE_URL') && KCHK_BASE_URL !== '') { return rtrim(KCHK_BASE_URL, '/'); }
    $scheme = 'http';
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) {
        $scheme = 'https';
    }
    $host = isset($_SERVER['HTTP_HOST'])
        ? preg_replace('/[^A-Za-z0-9.:\-]/', '', $_SERVER['HTTP_HOST']) : 'localhost';
    $dir = isset($_SERVER['SCRIPT_NAME'])
        ? rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/') : '';
    if ($dir === '/' || $dir === '.') { $dir = ''; }
    return $scheme . '://' . $host . $dir;
}

/**
 * デモモード。触ってもらうための公開インスタンス用。
 * メールを実際に送らない（公開デモを踏み台にされないため）。
 */
function kchk_is_demo() { return defined('KCHK_DEMO') && KCHK_DEMO; }

/** 設定が足りているか。画面で案内するために使う。 */
function kchk_setup_missing() {
    $miss = array();
    if (kchk_notify_email() === '') { $miss[] = 'KCHK_NOTIFY_EMAIL（通知先メールアドレス）'; }
    if (kchk_site_url() === '')     { $miss[] = 'KCHK_SITE_URL（自社サイトのURL）'; }
    if (!defined('KCHK_ADMIN_PASSWORD_HASH') || KCHK_ADMIN_PASSWORD_HASH === '') {
        $miss[] = 'KCHK_ADMIN_PASSWORD_HASH（管理パスワード。scripts/make_password_hash.php で作成）';
    }
    return $miss;
}

/* ---------------- 共通 ---------------- */

function kchk_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function kchk_random_hex($bytes) {
    if (function_exists('random_bytes')) { return bin2hex(random_bytes($bytes)); }
    if (function_exists('openssl_random_pseudo_bytes')) {
        return bin2hex(openssl_random_pseudo_bytes($bytes));
    }
    $out = '';
    for ($i = 0; $i < $bytes * 2; $i++) { $out .= dechex(mt_rand(0, 15)); }
    return $out;
}

/**
 * 外部からHTTPで取ってくる。curl が無い共有サーバーでも動くよう2経路持つ。
 * 監視対象は他人のサイトなので、必ずタイムアウトを付ける。
 *
 * @return array list($status, $body)
 */
function kchk_http_get($url, $timeout = 20) {
    if (!preg_match('#^https?://#i', $url)) { return array(0, ''); }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'kcheckit/1.0');
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return array($status, $body === false ? '' : (string)$body);
    }
    $ctx = stream_context_create(array(
        'http' => array('timeout' => $timeout, 'ignore_errors' => true,
                        'header' => "User-Agent: kcheckit/1.0\r\n"),
        'ssl'  => array('verify_peer' => true, 'verify_peer_name' => true),
    ));
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) &&
        preg_match('#HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    return array($status, $body === false ? '' : (string)$body);
}

/* ---------------- 台帳（監視対象） ---------------- */

function kchk_update($file, $key, $callback) {
    if (!is_dir(KCHK_DATA_DIR) && !@mkdir(KCHK_DATA_DIR, 0705, true)) {
        return array(false, '台帳ディレクトリを作成できません');
    }
    $fp = @fopen($file, 'c+');
    if (!$fp) { return array(false, '台帳を開けません'); }
    if (!flock($fp, LOCK_EX)) { fclose($fp); return array(false, '台帳をロックできません'); }
    rewind($fp);
    $data = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($data)) { $data = array(); }
    if (!isset($data[$key]) || !is_array($data[$key])) { $data[$key] = array(); }
    $result = $callback($data);
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    @chmod($file, 0600);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

function kchk_read($file, $key) {
    if (!file_exists($file)) { return array(); }
    $fp = @fopen($file, 'r');
    if (!$fp) { return array(); }
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $d = json_decode((string)$raw, true);
    return (is_array($d) && isset($d[$key]) && is_array($d[$key])) ? $d[$key] : array();
}

function kchk_sources()         { return kchk_read(KCHK_SOURCES, 'sources'); }
function kchk_sources_enabled() {
    $out = array();
    foreach (kchk_sources() as $s) { if (!empty($s['enabled'])) { $out[] = $s; } }
    return $out;
}

function kchk_find_source($id) {
    foreach (kchk_sources() as $s) { if ($s['id'] === $id) { return $s; } }
    return null;
}

/** 監視対象を追加する。 */
function kchk_add_source($name, $url, $method, $note = '') {
    $name   = trim($name);
    $url    = trim($url);
    $method = isset(kchk_methods()[$method]) ? $method : 'rss';
    if ($name === '') { return array(false, '名前をご入力ください。'); }
    if (!preg_match('#^https?://#i', $url)) { return array(false, 'URLは http:// か https:// で始めてください。'); }
    if (mb_strlen($name, 'UTF-8') > 60)  { return array(false, '名前が長すぎます（60文字まで）。'); }
    if (mb_strlen($url, 'UTF-8') > 300)  { return array(false, 'URLが長すぎます。'); }

    return kchk_update(KCHK_SOURCES, 'sources', function (&$data) use ($name, $url, $method, $note) {
        foreach ($data['sources'] as $s) {
            if ($s['url'] === $url && $s['method'] === $method) {
                return array(false, '同じURL・同じ方式が既に登録されています。');
            }
        }
        $data['sources'][] = array(
            'id'         => kchk_random_hex(8),
            'name'       => $name,
            'url'        => $url,
            'method'     => $method,
            'note'       => (string)$note,
            'enabled'    => true,
            'created_at' => time(),
            'checked_at' => 0,
            'last_status'=> '',
            'last_error' => '',
            'ok_count'   => 0,
            'fail_count' => 0,
        );
        return array(true, '監視対象を追加しました。');
    });
}

function kchk_delete_source($id) {
    return kchk_update(KCHK_SOURCES, 'sources', function (&$data) use ($id) {
        foreach ($data['sources'] as $i => $s) {
            if ($s['id'] === $id) {
                array_splice($data['sources'], $i, 1);
                return array(true, '監視対象を削除しました。');
            }
        }
        return array(false, '監視対象が見つかりません。');
    });
}

function kchk_toggle_source($id) {
    return kchk_update(KCHK_SOURCES, 'sources', function (&$data) use ($id) {
        foreach ($data['sources'] as $i => $s) {
            if ($s['id'] === $id) {
                $data['sources'][$i]['enabled'] = empty($s['enabled']);
                return array(true, $data['sources'][$i]['enabled'] ? '監視を再開しました。' : '監視を止めました。');
            }
        }
        return array(false, '監視対象が見つかりません。');
    });
}

/**
 * チェック結果を監視対象に書き戻す。
 *
 * 連続失敗の回数を持つのが要点。HTMLの差分監視は、相手がページ構成を
 * 変えると黙って0件になる。「壊れたことが分かる」ようにしておかないと、
 * 監視しているつもりで何も見ていない状態が続く。
 */
function kchk_record_check($id, $ok, $status, $error = '') {
    return kchk_update(KCHK_SOURCES, 'sources', function (&$data) use ($id, $ok, $status, $error) {
        foreach ($data['sources'] as $i => $s) {
            if ($s['id'] !== $id) { continue; }
            $data['sources'][$i]['checked_at']  = time();
            $data['sources'][$i]['last_status'] = (string)$status;
            $data['sources'][$i]['last_error']  = $ok ? '' : (string)$error;
            if ($ok) {
                $data['sources'][$i]['ok_count']   = (int)$s['ok_count'] + 1;
                $data['sources'][$i]['fail_count'] = 0;
            } else {
                $data['sources'][$i]['fail_count'] = (int)$s['fail_count'] + 1;
            }
            return array(true, '');
        }
        return array(false, '');
    });
}

/** 続けて失敗している監視対象。画面で赤く出して気づけるようにする。 */
function kchk_broken_sources($threshold = 3) {
    $out = array();
    foreach (kchk_sources() as $s) {
        if ((int)$s['fail_count'] >= $threshold) { $out[] = $s; }
    }
    return $out;
}

/* ---------------- 検知したもの ---------------- */

function kchk_findings() { return array_reverse(kchk_read(KCHK_FINDINGS, 'findings')); }

function kchk_find_finding($id) {
    foreach (kchk_read(KCHK_FINDINGS, 'findings') as $f) {
        if ($f['id'] === $id) { return $f; }
    }
    return null;
}

/**
 * 1回のチェックの結果を1件の「検知」として記録する。
 * items は各監視対象から拾った新着。related は関連ありと判定されたもの。
 */
function kchk_save_finding($items, $related, $report_file, $summary) {
    return kchk_update(KCHK_FINDINGS, 'findings', function (&$data) use ($items, $related, $report_file, $summary) {
        $f = array(
            'id'          => kchk_random_hex(8),
            'checked_at'  => time(),
            'total'       => count($items),
            'related'     => count($related),
            'report_file' => (string)$report_file,
            'summary'     => (string)$summary,
            'notified'    => false,
        );
        $data['findings'][] = $f;
        // 履歴が無限に伸びないよう、直近200件だけ残す
        if (count($data['findings']) > 200) {
            $data['findings'] = array_slice($data['findings'], -200);
        }
        return array(true, $f);
    });
}

function kchk_mark_notified($id, $ok) {
    return kchk_update(KCHK_FINDINGS, 'findings', function (&$data) use ($id, $ok) {
        foreach ($data['findings'] as $i => $f) {
            if ($f['id'] === $id) {
                $data['findings'][$i]['notified'] = (bool)$ok;
                return array(true, '');
            }
        }
        return array(false, '');
    });
}

/* ---------------- 前回の状態（差分の土台） ---------------- */

/**
 * 監視対象ごとに「前回見たもの」を覚えておく。
 * これが無いと毎回すべてが新着になる。
 */
function kchk_snapshot_path($source_id) {
    return KCHK_SNAPSHOT_DIR . '/' . preg_replace('/[^a-f0-9]/', '', $source_id) . '.json';
}

function kchk_load_snapshot($source_id) {
    $p = kchk_snapshot_path($source_id);
    if (!file_exists($p)) { return array(); }
    $d = json_decode((string)@file_get_contents($p), true);
    return is_array($d) ? $d : array();
}

function kchk_save_snapshot($source_id, $keys) {
    if (!is_dir(KCHK_SNAPSHOT_DIR)) { @mkdir(KCHK_SNAPSHOT_DIR, 0705, true); }
    // 覚えておく数には上限を置く。際限なく増えるとファイルが太る。
    if (count($keys) > 500) { $keys = array_slice($keys, -500); }
    @file_put_contents(kchk_snapshot_path($source_id),
        json_encode(array_values($keys), JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod(kchk_snapshot_path($source_id), 0600);
}
