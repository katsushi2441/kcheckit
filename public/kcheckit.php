<?php
/**
 * Kurage Check It — 管理画面。
 *
 * できること
 *   - 監視対象の一覧・追加・停止・削除
 *   - 「今すぐチェック」（cronが無い環境でもこれだけで使える）
 *   - チェック履歴と、保存された報告書へのリンク
 *   - 監視が壊れている対象の警告
 */
require_once __DIR__ . '/kcheckit_lib.php';
require_once __DIR__ . '/kcheckit_auth.php';
require_once __DIR__ . '/kcheckit_run.php';
require_once __DIR__ . '/kcheckit_samples.php';
date_default_timezone_set('Asia/Tokyo');

$THIS_FILE = basename(__FILE__);
kchk_auth_start();

if (isset($_GET['logout'])) { kchk_auth_logout(); header('Location: ' . $THIS_FILE); exit; }

$is_admin = kchk_is_admin();
$missing  = kchk_setup_missing();
$notice = ''; $error = ''; $login_error = ''; $result = null;

/* ---- ログイン ---- */
if (!$is_admin && isset($_POST['login'])) {
    if (kchk_login_locked()) {
        $login_error = '試行回数の上限に達しました。15分ほどおいてからお試しください。';
    } elseif (kchk_password_login(isset($_POST['password']) ? $_POST['password'] : '')) {
        header('Location: ' . $THIS_FILE); exit;
    } else {
        $login_error = 'パスワードが違います。';
    }
}

$csrf = $is_admin ? kchk_csrf() : '';

/* ---- 操作 ---- */
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['login'])) {
    if (!kchk_csrf_ok(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
        $error = '送信を確認できませんでした。もう一度お試しください。';
    } elseif (isset($_POST['add_source'])) {
        $res = kchk_add_source(
            isset($_POST['name']) ? $_POST['name'] : '',
            isset($_POST['url']) ? $_POST['url'] : '',
            isset($_POST['method']) ? $_POST['method'] : 'rss',
            isset($_POST['note']) ? trim((string)$_POST['note']) : ''
        );
        if (empty($res[0])) { $error = $res[1]; } else { $notice = $res[1]; }
    } elseif (isset($_POST['toggle'])) {
        $res = kchk_toggle_source((string)$_POST['toggle']);
        if (empty($res[0])) { $error = $res[1]; } else { $notice = $res[1]; }
    } elseif (isset($_POST['delete'])) {
        $res = kchk_delete_source((string)$_POST['delete']);
        if (empty($res[0])) { $error = $res[1]; } else { $notice = $res[1]; }
    } elseif (isset($_POST['run'])) {
        // 画面から回すときも本番と同じ経路を通す。
        // 「ボタンでは動くがcronでは動かない」を作らないため。
        $result = kchk_run(true);
        $notice = 'チェックを実行しました。';
    }
}

// 初回ログイン時にサンプルを入れる。空の画面を見せても何をすればいいか伝わらない。
if ($is_admin && !$missing) {
    $installed = kchk_install_samples();
    if ($installed > 0 && $notice === '') {
        $notice = 'よく使う監視対象を ' . $installed . ' 件登録しました。不要なものは停止・削除できます。';
    }
}

$sources  = $is_admin ? kchk_sources() : array();
$findings = $is_admin ? kchk_findings() : array();
$broken   = $is_admin ? kchk_broken_sources() : array();
$methods  = kchk_methods();
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo kchk_h(kchk_app_title()); ?></title>
<meta name="robots" content="noindex, nofollow">
<style>
:root{--ink:#12202f;--muted:#55697a;--bg:#f5fbfb;--panel:#e7f3f2;--line:#cde5e2;
  --teal:#12a99f;--deep:#0a726b;--gold:#c98a1e;--goldbg:#fbf2db;--goldline:#ecd9a8;
  --shadow:0 14px 40px rgba(10,40,45,.10)}
@media(prefers-color-scheme:dark){:root{--ink:#eaf3f3;--muted:#9fb3ba;--bg:#0c1720;
  --panel:#12242a;--line:#1f3a3f;--teal:#2bd4c6;--deep:#1c9e93;--gold:#f2c766;
  --goldbg:#241b08;--goldline:#4c3c17;--shadow:0 14px 40px rgba(0,0,0,.38)}}
*{box-sizing:border-box;margin:0;padding:0}
body{color:var(--ink);background:var(--bg);line-height:1.85;font-size:15px;
  font-family:-apple-system,"Hiragino Sans","Yu Gothic",Meiryo,system-ui,sans-serif}
a{color:var(--deep)}
.wrap{max-width:980px;margin:0 auto;padding:0 20px}
header.site{border-bottom:1px solid var(--line);background:var(--panel)}
header.site .wrap{display:flex;align-items:center;gap:12px;padding:13px 20px;flex-wrap:wrap}
.brand{display:flex;gap:10px;align-items:center;color:inherit;text-decoration:none}
.mark{width:36px;height:36px;border-radius:11px;flex:none;display:grid;place-items:center;
  background:linear-gradient(145deg,var(--teal),var(--deep));color:#fff;font-weight:900;font-size:13px}
.brand strong{font-size:15px;font-weight:800;display:block;line-height:1.3}
.brand span{font-size:11px;color:var(--muted)}
.hnav{margin-left:auto;display:flex;gap:8px;align-items:center}
.chip{font-size:12px;font-weight:700;color:var(--muted);border:1px solid var(--line);
  border-radius:999px;padding:4px 12px;background:var(--bg);text-decoration:none}
.btn{border:0;border-radius:999px;padding:11px 22px;font-weight:800;font-size:13.5px;cursor:pointer;
  display:inline-flex;align-items:center;gap:7px;text-decoration:none;font-family:inherit;
  background:linear-gradient(135deg,var(--teal),var(--deep));color:#fff;
  box-shadow:0 10px 24px rgba(18,169,159,.26)}
.btn.ghost{background:transparent;color:var(--muted);border:1.5px solid var(--line);box-shadow:none}
.btn.small{padding:6px 14px;font-size:12px;box-shadow:none}
section{padding:24px 0}
h1{font-size:23px;font-weight:800;margin-bottom:8px}
h2{font-size:17px;font-weight:800;margin:26px 0 10px}
.lead{font-size:14px;color:var(--muted);margin-bottom:16px}
.card{background:var(--panel);border:1.5px solid var(--line);border-radius:16px;
  padding:20px;box-shadow:var(--shadow);margin-bottom:14px}
.card.plain{background:var(--bg);box-shadow:none}
label{display:block;font-size:13px;font-weight:700;margin:14px 0 5px}
input[type=text],input[type=url],input[type=password],select{width:100%;font:inherit;font-size:15px;
  color:inherit;background:var(--bg);border:1.5px solid var(--line);border-radius:9px;padding:10px 12px}
input:focus,select:focus{outline:2px solid var(--teal);border-color:var(--teal)}
.hint{font-size:12px;color:var(--muted);margin-top:5px}
.ok{background:var(--goldbg);border:1.5px solid var(--goldline);border-radius:10px;
  padding:12px 14px;font-size:13.5px;margin-bottom:14px}
.err{background:#fdecea;border:1.5px solid #f5c6c2;color:#a3261b;border-radius:10px;
  padding:12px 14px;font-size:13.5px;margin-bottom:14px}
@media(prefers-color-scheme:dark){.err{background:#2a1512;border-color:#5c2a24;color:#ff9d92}}
.row{display:flex;gap:12px;align-items:center;flex-wrap:wrap;border-top:1px solid var(--line);padding:13px 2px}
.row:first-child{border-top:0}
.row .grow{flex:1;min-width:220px}
.tag{font-size:11px;font-weight:800;border-radius:999px;padding:2px 10px;
  border:1.5px solid var(--line);color:var(--muted);white-space:nowrap}
.tag.on{border-color:var(--teal);color:var(--deep)}
.tag.ng{border-color:#d9534f;color:#c9302c}
.muted{color:var(--muted);font-size:12.5px}
.stat{display:flex;gap:22px;flex-wrap:wrap;font-size:14px}
.stat b{font-size:22px;display:block;line-height:1.2}
.demo-bar{background:var(--goldbg);border-bottom:1px solid var(--goldline);text-align:center;
  font-size:12.5px;padding:7px 16px;color:var(--gold)}
footer.site{text-align:center;color:var(--muted);font-size:12.5px;padding:30px 20px 44px;
  border-top:1px solid var(--line);margin-top:20px}
</style>
<?php if (defined('KCHK_HEAD_EXTRA')) { echo KCHK_HEAD_EXTRA; } ?>
</head>
<body>
<?php if (kchk_is_demo()): ?>
<div class="demo-bar">デモサイトです。メールは実際には送信されません。</div>
<?php endif; ?>

<header class="site"><div class="wrap">
  <?php // 左上のタイトルは自分自身へのリンク。押せば初期表示に戻る ?>
  <a class="brand" href="<?php echo kchk_h($THIS_FILE); ?>">
    <span class="mark">CI</span>
    <span><strong><?php echo kchk_h(kchk_app_title()); ?></strong>
      <span>チェックして、保存して、知らせる</span></span>
  </a>
  <?php if ($is_admin): ?>
  <nav class="hnav"><a class="chip" href="?logout=1">ログアウト</a></nav>
  <?php endif; ?>
</div></header>

<main class="wrap">
<?php if (!$is_admin): /* ================= ログイン ================= */ ?>
<section>
  <h1>ログイン</h1>
  <p class="lead">監視の設定を変更できるのは管理者だけです。</p>
  <?php if ($missing): ?>
  <div class="err"><b>設置が終わっていません。</b>次の設定が必要です。
    <ul style="margin:8px 0 0 18px">
      <?php foreach ($missing as $m): ?><li><?php echo kchk_h($m); ?></li><?php endforeach; ?>
    </ul>
    <p style="margin-top:8px;font-size:12.5px">
      <code>kcheckit_config.php.example</code> をコピーして <code>kcheckit_config.php</code> を作ってください。</p>
  </div>
  <?php endif; ?>
  <?php if ($login_error !== ''): ?><p class="err"><?php echo kchk_h($login_error); ?></p><?php endif; ?>
  <form method="post" class="card">
    <label for="password">管理パスワード</label>
    <input type="password" id="password" name="password" required autocomplete="current-password" autofocus>
    <button type="submit" name="login" value="1" class="btn" style="margin-top:16px">ログイン</button>
  </form>
</section>

<?php else: /* ================= 管理 ================= */ ?>
<section>
  <?php if ($notice !== ''): ?><p class="ok"><?php echo kchk_h($notice); ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="err"><?php echo kchk_h($error); ?></p><?php endif; ?>

  <?php if ($broken): ?>
  <div class="err">
    <b>監視が続けて失敗している対象があります。</b>相手のサイト構成が変わった可能性があります。
    <ul style="margin:8px 0 0 18px">
      <?php foreach ($broken as $b): ?>
        <li><?php echo kchk_h($b['name']); ?>（<?php echo (int)$b['fail_count']; ?>回連続）
          <?php if (!empty($b['last_error'])): ?><br><span class="muted"><?php echo kchk_h($b['last_error']); ?></span><?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <h1>監視の状況</h1>
  <div class="card">
    <div class="stat">
      <div>監視対象<b><?php echo count(kchk_sources_enabled()); ?></b></div>
      <div>登録済み<b><?php echo count($sources); ?></b></div>
      <div>チェック履歴<b><?php echo count($findings); ?></b></div>
      <div>自社サイト<b style="font-size:14px;line-height:1.9"><?php
        echo kchk_site_url() !== '' ? kchk_h(preg_replace('#^https?://#', '', kchk_site_url())) : '未設定'; ?></b></div>
      <div>通知先<b style="font-size:14px;line-height:1.9"><?php
        echo kchk_notify_email() !== '' ? kchk_h(kchk_notify_email()) : '未設定'; ?></b></div>
    </div>
    <form method="post" style="margin-top:16px">
      <input type="hidden" name="csrf" value="<?php echo kchk_h($csrf); ?>">
      <button type="submit" name="run" value="1" class="btn">今すぐチェック</button>
      <span class="hint" style="display:inline;margin-left:10px">
        定期実行は cron から <code>php kcheckit_run.php</code>（INSTALL.md 参照）</span>
    </form>
    <?php if ($result): ?>
    <div class="card plain" style="margin-top:16px;margin-bottom:0">
      <b>実行結果</b>
      <p style="font-size:14px;margin-top:4px">
        監視 <?php echo (int)$result['checked']; ?> 件 ／
        新着 <b><?php echo (int)$result['items']; ?></b> 件 ／
        関連 <b><?php echo (int)$result['related']; ?></b> 件
        <?php if ((int)$result['failed'] > 0): ?>
        ／ <span style="color:#c9302c">取得できず <?php echo (int)$result['failed']; ?> 件</span>
        <?php endif; ?>
        （<?php echo (int)$result['elapsed']; ?>秒）
      </p>
      <?php if (!empty($result['report_url'])): ?>
        <p style="margin-top:8px"><a class="btn ghost small" href="<?php echo kchk_h($result['report_url']); ?>" target="_blank" rel="noopener">保存された報告書を開く</a></p>
      <?php endif; ?>
      <?php if (kchk_is_demo()): ?>
        <p class="hint">デモのため、メールは送信していません。</p>
      <?php elseif (!empty($result['notified'])): ?>
        <p class="hint"><?php echo kchk_h(kchk_notify_email()); ?> へ通知しました。</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <h2>監視対象</h2>
  <?php if (!$sources): ?>
    <p class="muted">まだ登録がありません。下のフォームから追加してください。</p>
  <?php else: ?>
  <div class="card">
    <?php foreach ($sources as $s): ?>
    <div class="row">
      <div class="grow">
        <b><?php echo kchk_h($s['name']); ?></b>
        <span class="tag"><?php echo kchk_h(isset($methods[$s['method']]) ? $methods[$s['method']] : $s['method']); ?></span><br>
        <span class="muted"><a href="<?php echo kchk_h($s['url']); ?>" target="_blank" rel="noopener nofollow"><?php
          echo kchk_h(mb_strimwidth($s['url'], 0, 68, '…', 'UTF-8')); ?></a></span>
        <?php if (!empty($s['checked_at'])): ?>
        <br><span class="muted">最終 <?php echo date('n/j H:i', (int)$s['checked_at']); ?>
          <?php if (!empty($s['last_error'])): ?>
            ／ <span style="color:#c9302c"><?php echo kchk_h(mb_strimwidth($s['last_error'], 0, 46, '…', 'UTF-8')); ?></span>
          <?php else: ?>／ 正常<?php endif; ?></span>
        <?php endif; ?>
      </div>
      <span class="tag <?php echo !empty($s['enabled']) ? 'on' : ''; ?>"><?php
        echo !empty($s['enabled']) ? '監視中' : '停止中'; ?></span>
      <form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?php echo kchk_h($csrf); ?>">
        <button type="submit" name="toggle" value="<?php echo kchk_h($s['id']); ?>" class="btn ghost small"><?php
          echo !empty($s['enabled']) ? '停止' : '再開'; ?></button></form>
      <form method="post" style="margin:0" onsubmit="return confirm('この監視対象を削除します。よろしいですか。')">
        <input type="hidden" name="csrf" value="<?php echo kchk_h($csrf); ?>">
        <button type="submit" name="delete" value="<?php echo kchk_h($s['id']); ?>" class="btn ghost small">削除</button></form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="card plain">
    <h2 style="margin-top:0">監視対象を追加</h2>
    <form method="post">
      <input type="hidden" name="csrf" value="<?php echo kchk_h($csrf); ?>">
      <label for="name">名前<span style="color:#c0392b">*</span></label>
      <input type="text" id="name" name="name" maxlength="60" required placeholder="例：JPCERT 注意喚起">

      <label for="url">URL<span style="color:#c0392b">*</span></label>
      <input type="url" id="url" name="url" maxlength="300" required placeholder="https://www.jpcert.or.jp/rss/jpcert.rdf">

      <label for="method">チェック方式<span style="color:#c0392b">*</span></label>
      <select id="method" name="method">
        <?php foreach ($methods as $k => $v): ?>
        <option value="<?php echo kchk_h($k); ?>"><?php echo kchk_h($v); ?></option>
        <?php endforeach; ?>
      </select>
      <p class="hint">RSSが無いサイトは「HTMLページの差分」を選びます。</p>

      <label for="note">補助設定（任意）</label>
      <input type="text" id="note" name="note" maxlength="200"
             placeholder="例：contains=/press/　または　list=result;title=title">
      <p class="hint">
        HTML差分は <code>contains=/press/</code> で拾うリンクを絞れます。<br>
        JSON APIは <code>list=result;title=title;url=detail_url;date=end_date</code> のように項目名を指定できます。</p>

      <button type="submit" name="add_source" value="1" class="btn" style="margin-top:18px">追加する</button>
    </form>
  </div>

  <h2>チェック履歴</h2>
  <?php if (!$findings): ?>
    <p class="muted">まだ実行していません。</p>
  <?php else: ?>
  <div class="card">
    <?php foreach (array_slice($findings, 0, 30) as $f): ?>
    <div class="row">
      <div class="grow">
        <b><?php echo date('n/j H:i', (int)$f['checked_at']); ?></b>
        <span class="muted">　<?php echo kchk_h($f['summary']); ?></span>
      </div>
      <?php if ((int)$f['related'] > 0): ?>
        <span class="tag on">関連 <?php echo (int)$f['related']; ?> 件</span>
      <?php endif; ?>
      <?php if (!empty($f['report_file'])): ?>
        <a class="chip" href="<?php echo kchk_h(kchk_report_url($f['report_file'])); ?>" target="_blank" rel="noopener">報告書</a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
</main>

<footer class="site"><div class="wrap">
  Kurage Check It<?php if (kchk_org_name() !== ''): ?>　/　<?php echo kchk_h(kchk_org_name()); ?><?php endif; ?>
</div></footer>
</body>
</html>
