# kcheckit — Kurage Check It Framework

**必要な情報を監視して、保存して、知らせる**ための汎用フレームワークです。

自社サイトのURLと、チェックしたいサイトのURLを登録しておくと、新着を毎日拾い、
**自社の事業に関係しそうなものだけ**を根拠つきでメールに知らせます。

- **データベース不要**（JSONファイルに `flock` で書きます）
- **外部ライブラリ不要**（Composer も npm も使いません）
- **PHPが動く共有レンタルサーバーで動きます**（FTPで上げるだけ）
- **cronが無くても使えます**（画面の「今すぐチェック」ボタン）
- **MIT License** — 商用利用・改変・再配布・再販が自由です

デモ: <https://proto.exbridge.jp/kcheckit/>（パスワード `demo2026`・メールは送信されません）

## 骨格は4段階

```
CHECK  → JUDGE   → STORE    → NOTIFY
取りに行く 関係あるか  詳細を保存   要点とURLを送る
```

| 段階 | ファイル | 中身 |
|---|---|---|
| CHECK | `kcheckit_check.php` | RSS / JSON API / HTMLの差分 / SSL証明書の期限 |
| JUDGE | `kcheckit_judge.php` | 自社の事業内容に照らして絞り込む |
| STORE | `kcheckit_store.php` | 静的HTMLで保存し、恒久URLを作る |
| NOTIFY | `kcheckit_notify.php` | 要点＋保存先URLをメールで送る |

**この4段階が入れ替え可能であることが、このフレームワークの中身です。**
監視対象が変われば CHECK を、通知先が変われば NOTIFY を足す。
`kcheckit_run.php` が4つを順に呼ぶだけなので、どこを触ればいいかが一目で分かります。

## 4つのチェック方式

官公庁のサイトを実際に調べたところ、**RSSがあるところと無いところが半々**でした。
1つの方式だけでは半分の情報源が監視できません。だから最初から4方式を持たせています。

| 方式 | 使いどころ | 実際に確認した例 |
|---|---|---|
| `rss` | RSS / Atom がある | JPCERT・IPA・厚生労働省・金融庁・デジタル庁 |
| `json` | APIがある | jGrants（補助金）・e-Gov（法令） |
| `html` | RSSが無い | 消費者庁・環境省 |
| `tls` | 自社サイトの見守り | SSL証明書の期限が30日を切ったら通知 |

方式を増やすときは `kcheckit_check.php` に関数を1つ足し、
`kcheckit_lib.php` の `kchk_methods()` に1行加えるだけです。画面にも自動で出ます。

## 最初から入っている監視対象

設置すると、業種を問わず効くものが自動で登録されます。

- **JPCERT 注意喚起** / **IPA 重要なセキュリティ情報** — 取引先から説明を求められる類
- **jGrants 募集中の補助金** — 締切日つき。中小企業がいちばん逃したくない情報
- **e-Gov 法令の更新** — 全9,537件を舐めて、更新の新しい順に
- **自社サイトのSSL証明書** — 切れるとサイトが開かなくなります

業種別（厚生労働省・環境省・金融庁・消費者庁など）は
`kcheckit_samples.php` のコメントに例を置いてあります。画面から追加してください。

## 設計上、譲っていないこと

**AIに「対応が必要か」を判断させません。**

やらせるのは「関係しそうか」と「なぜそう思うか」の2つだけです。法令や規制の話は、
間違えたときの損害が大きい。**自信満々に外した判断は、何も出さないより有害**になります。

- 判定は「関係あり / 判断できず」の2択。「対応不要」は出しません
- 必ず理由（どの語がどう引っかかったか）を残します
- AIが使えない環境ではキーワード照合だけで動きます

**監視が壊れたことが分かるようにしてあります。**

HTMLの差分監視は、相手がページ構成を変えると黙って0件になります。
「監視しているつもりで何も見ていない」が一番まずいので、連続失敗を数えて、
画面に警告を出し、メールでも知らせます。

**初回は黙ります。**

導入直後に何十通も通知が飛ぶと、それだけで使うのをやめられます。
初回は記録だけして、2回目以降の差分だけを知らせます。

## 動作要件

- **PHP 7.4 以上**（8.x で動作確認済み。PHP 5.x でも動く構文で書いています）
- `mail()` が使えること（使えなくても画面で結果は見られます）
- **データベースは不要**
- Composer・npm は不要
- **SimpleXMLが無くても動きます**（無い場合は自前でRSSを読みます）
- cronは任意（あれば定期実行、無ければボタンで手動実行）

## 設置

1. `public/` の中身をサーバーへ置く
2. `public/kcheckit_config.php.example` をコピーして `kcheckit_config.php` を作る
3. 管理パスワードのハッシュを作って貼る

   ```
   php scripts/make_password_hash.php 'あなたのパスワード'
   ```

4. 自社サイトのURLと通知先メールを設定する

   ```php
   define('KCHK_SITE_URL', 'https://example.co.jp/');
   define('KCHK_NOTIFY_EMAIL', 'tantou@example.co.jp');
   ```

5. `kcheckit.php` を開いてログイン → 「今すぐチェック」

定期実行する場合は cron に1行足します。

```
0 8 * * *  cd /path/to/public && php kcheckit_run.php >> /path/to/kcheckit.log 2>&1
```

## ファイル

| ファイル | 行数 | 役割 |
|---|---|---|
| `public/kcheckit.php` | 約330 | 管理画面。監視対象の一覧・追加・手動チェック・履歴 |
| `public/kcheckit_lib.php` | 約370 | 台帳・設定・共通処理 |
| `public/kcheckit_check.php` | 約400 | CHECK（4方式） |
| `public/kcheckit_judge.php` | 約180 | JUDGE（関連判定） |
| `public/kcheckit_store.php` | 約120 | STORE（静的HTML保存） |
| `public/kcheckit_notify.php` | 約120 | NOTIFY（メール） |
| `public/kcheckit_run.php` | 約115 | 4段階を順に回す本体。cronからも呼べる |
| `public/kcheckit_auth.php` | 約140 | 管理者ログイン |
| `public/kcheckit_samples.php` | 約55 | 最初から入っている監視対象 |
| `public/kcheckit_config.php` | — | **設置する人が作る設定ファイル。**リポジトリには入っていません |

## 確認

```
php scripts/check_kcheckit.php   # 台帳・差分・判定・保存・通知（ネットワーク不要）
php scripts/check_live.php       # 実際の情報源8つに接続して4方式すべてを確認
```

## ライセンス

MIT License（[LICENSE](LICENSE)）。商用利用・改変・再配布・再販が自由に行えます。

## 有償パッケージ

このリポジトリのコードは無償です。**有償で提供しているのは、これをどう作ったかの
記録と、AIと一緒に育てるための作法です。**

- 設置手順書（AIに渡すとそのまま設置が進みます）
- 実際に踏んだ罠の記録
- 監視対象・チェック方式・通知先を増やす練習問題
- VWork フレームワーク（AIと仕事を進めるための作法）

**[Kurage App Store](https://kappstore.exbridge.jp/)** で取り扱っています。
