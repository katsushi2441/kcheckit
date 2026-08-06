<?php
/**
 * 監視対象のサンプル。初回起動時に自動で登録される。
 *
 * 既定は「業種を問わず効くもの」だけにしてある。業種別のものを既定に入れると、
 * 関係ない通知が届いて、それだけで使うのをやめられる。
 * 業種別は下のコメントを参考に、画面から足していく想定。
 *
 * ここに並んでいるURLは 2026-08-07 に実際に取得できることを確認したもの
 * （scripts/check_live.php で再確認できる）。
 */
function kchk_sample_sources() {
    return array(
        // --- セキュリティ（全業種共通。取引先から説明を求められる類）---
        array('JPCERT 注意喚起', 'https://www.jpcert.or.jp/rss/jpcert.rdf', 'rss', ''),
        array('IPA 重要なセキュリティ情報', 'https://www.ipa.go.jp/security/alert-rss.rdf', 'rss', ''),

        // --- 補助金（中小企業がいちばん逃したくない情報。締切つき）---
        array('jGrants 募集中の補助金',
              'https://api.jgrants-portal.go.jp/exp/v1/public/subsidies?keyword=%E8%A3%9C%E5%8A%A9&sort=created_date&order=DESC&acceptance=1',
              'json', 'list=result;title=title;date=acceptance_end_datetime'),

        // --- 法令（改正日・施行日が構造化データで取れる）---
        // 全9537件を10ページで舐めてから、更新の新しい順に並べ替える。
        // 1ページだけだと明治時代の布告ばかりが並び、最近の改正に届かない。
        array('e-Gov 法令の更新', 'https://laws.e-gov.go.jp/api/2/laws?limit=1000&offset=0&response_format=json',
              'json', 'list=laws;sort=updated;pages=10'),

        // --- 自社の見守り（証明書が切れるとサイトが開かなくなる）---
        array('自社サイトのSSL証明書', defined('KCHK_SITE_URL') ? KCHK_SITE_URL : 'https://example.co.jp/', 'tls', ''),

        /* 業種別の例（画面から追加してください）
         * 労務・製造・食品  厚生労働省      https://www.mhlw.go.jp/stf/news.rdf              rss
         * 環境・廃棄物      環境省          https://www.env.go.jp/press/                     html  contains=/press/
         * 消費者向け商売    消費者庁        https://www.caa.go.jp/policies/policy/           html  contains=/policies/
         * 金融・保険        金融庁          https://www.fsa.go.jp/fsaNewsListAll_rss2.xml    rss
         * IT・行政手続      デジタル庁      https://www.digital.go.jp/rss/news.xml           rss
         * 製造・輸出        経済産業省      https://www.meti.go.jp/ml_index_release_atom.xml rss
         * 取引先・競合      各社のRSS                                                        rss
         */
    );
}

/** 初回だけサンプルを入れる。1件でも登録済みなら何もしない。 */
function kchk_install_samples() {
    if (kchk_sources()) { return 0; }
    $n = 0;
    foreach (kchk_sample_sources() as $s) {
        $r = kchk_add_source($s[0], $s[1], $s[2], $s[3]);
        if (!empty($r[0])) { $n++; }
    }
    return $n;
}
