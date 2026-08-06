#!/usr/bin/env bash
# デモサイトを公開する。
#   https://proto.exbridge.jp/kcheckit/
#
# デモ設定は KCHK_DEMO=true なので、メールは実際には送信されない。
# heteml 上の素のPHPで動く。常駐プロセスもDBもポートも使わない。
set -euo pipefail
cd "$(dirname "$0")/.."
set -a
. /home/kojima/work/aixec/.env
set +a

remote="/web/proto_exbridge_jp/kcheckit"

upload() {  # upload <local> <remote-path>
  curl --fail --silent --show-error --ftp-create-dirs -T "$1" \
    "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/${2}"
  echo "deployed: ${2}"
}

for f in kcheckit.php kcheckit_run.php kcheckit_check.php kcheckit_judge.php \
         kcheckit_store.php kcheckit_notify.php kcheckit_lib.php \
         kcheckit_auth.php kcheckit_samples.php; do
  upload "public/$f" "$f"
done

upload demo/kcheckit_config.php kcheckit_config.php
upload demo/index.php           index.php
upload demo/.htaccess           .htaccess
# 台帳(監視対象と検知内容)をWebから直接読ませない
upload public/kcheckit_data/.htaccess    kcheckit_data/.htaccess
# 報告書は一覧表示だけ止める（URLを知っている人は開ける）
upload public/kcheckit_reports/.htaccess kcheckit_reports/.htaccess

echo
echo "published: https://proto.exbridge.jp/kcheckit/"
