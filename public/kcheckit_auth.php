<?php
/**
 * 管理者認証。2つのモードを持つ。
 *
 *   password（既定） — 設定に入れたパスワードハッシュで入る。
 *                      どのサーバーでも動く。導入したら普通はこちら。
 *   x               — X(Twitter)ログイン。auth_common.php が同じ場所に
 *                      必要で、OAuth を受ける側の準備も要る。自社運用向け。
 *
 * 設定を置かない状態では誰も入れない（KCHK_ADMIN_PASSWORD_HASH も
 * KCHK_ADMIN も空なら、常に false を返す）。
 */
require_once __DIR__ . '/kcheckit_lib.php';

function kchk_auth_mode() {
    $m = defined('KCHK_AUTH') ? strtolower(KCHK_AUTH) : 'password';
    return $m === 'x' ? 'x' : 'password';
}

/* ---------------- パスワードモード ---------------- */

define('KCHK_LOGIN_FAILS', KCHK_DATA_DIR . '/login_fails.json');
define('KCHK_LOGIN_MAX_FAIL', 8);
define('KCHK_LOGIN_LOCK_SEC', 900);  // 15分

function kchk_client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '0.0.0.0';
}

/** 総当たり対策。IPごとに失敗を数え、一定回数で一定時間止める。 */
function kchk_login_locked() {
    if (!file_exists(KCHK_LOGIN_FAILS)) { return false; }
    $d = json_decode((string)@file_get_contents(KCHK_LOGIN_FAILS), true);
    $ip = kchk_client_ip();
    if (!is_array($d) || !isset($d[$ip])) { return false; }
    $e = $d[$ip];
    return (int)$e['n'] >= KCHK_LOGIN_MAX_FAIL && (time() - (int)$e['at']) < KCHK_LOGIN_LOCK_SEC;
}

function kchk_login_record_fail() {
    if (!is_dir(KCHK_DATA_DIR)) { @mkdir(KCHK_DATA_DIR, 0705, true); }
    $fp = @fopen(KCHK_LOGIN_FAILS, 'c+');
    if (!$fp) { return; }
    flock($fp, LOCK_EX);
    rewind($fp);
    $d = json_decode((string)stream_get_contents($fp), true);
    if (!is_array($d)) { $d = array(); }
    $ip = kchk_client_ip();
    $now = time();
    // 古い記録は捨てる（ファイルが際限なく育たないように）
    foreach ($d as $k => $v) {
        if ($now - (int)$v['at'] > KCHK_LOGIN_LOCK_SEC * 4) { unset($d[$k]); }
    }
    $n = (isset($d[$ip]) && $now - (int)$d[$ip]['at'] < KCHK_LOGIN_LOCK_SEC) ? (int)$d[$ip]['n'] + 1 : 1;
    $d[$ip] = array('n' => $n, 'at' => $now);
    rewind($fp); ftruncate($fp, 0);
    fwrite($fp, json_encode($d));
    fflush($fp);
    @chmod(KCHK_LOGIN_FAILS, 0600);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function kchk_login_clear_fail() {
    if (!file_exists(KCHK_LOGIN_FAILS)) { return; }
    $d = json_decode((string)@file_get_contents(KCHK_LOGIN_FAILS), true);
    if (!is_array($d)) { return; }
    unset($d[kchk_client_ip()]);
    @file_put_contents(KCHK_LOGIN_FAILS, json_encode($d), LOCK_EX);
}

function kchk_password_hash_set() {
    return defined('KCHK_ADMIN_PASSWORD_HASH') && KCHK_ADMIN_PASSWORD_HASH !== '';
}

/** パスワードを照合してログインする。 */
function kchk_password_login($password) {
    if (!kchk_password_hash_set()) { return false; }
    if (kchk_login_locked()) { return false; }
    if (!password_verify((string)$password, KCHK_ADMIN_PASSWORD_HASH)) {
        kchk_login_record_fail();
        // 総当たりの速度を落とす
        usleep(400000);
        return false;
    }
    kchk_login_clear_fail();
    session_regenerate_id(true);   // ログイン後にIDを変える（固定化対策）
    $_SESSION['kchk_admin'] = true;
    return true;
}

/* ---------------- 共通 ---------------- */

/** セッションを開始する。モードによって担当が違う。 */
function kchk_auth_start() {
    if (kchk_auth_mode() === 'x') {
        // auth_common.php 側がセッションを持つ
        if (!function_exists('url2ai_auth_bootstrap')) {
            if (file_exists(__DIR__ . '/config.php'))      { require_once __DIR__ . '/config.php'; }
            if (file_exists(__DIR__ . '/auth_common.php')) { require_once __DIR__ . '/auth_common.php'; }
        }
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('KCHKSESSID');
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        @session_set_cookie_params(0, '/', '', $secure, true);
        @session_start();
    }
}

/** いま管理者としてログインしているか。 */
function kchk_is_admin() {
    if (kchk_auth_mode() === 'x') {
        if (!function_exists('url2ai_auth_bootstrap')) { return false; }
        if (KCHK_ADMIN === '') { return false; }
        $auth = url2ai_auth_bootstrap();
        if (empty($auth['logged_in'])) { return false; }
        $user = strtolower(ltrim(trim((string)$auth['session_user']), '@'));
        return $user !== '' && hash_equals(strtolower(KCHK_ADMIN), $user);
    }
    return !empty($_SESSION['kchk_admin']) && kchk_password_hash_set();
}

function kchk_auth_logout() {
    if (kchk_auth_mode() === 'x') { return; }  // 画面側がリダイレクトを組む
    unset($_SESSION['kchk_admin']);
    @session_destroy();
}

/** CSRFトークン。フォームごとに埋めて照合する。 */
function kchk_csrf() {
    if (empty($_SESSION['kchk_csrf'])) { $_SESSION['kchk_csrf'] = kchk_random_hex(24); }
    return (string)$_SESSION['kchk_csrf'];
}

function kchk_csrf_ok($sent) {
    return !empty($_SESSION['kchk_csrf']) && is_string($sent) && $sent !== ''
        && hash_equals((string)$_SESSION['kchk_csrf'], $sent);
}
