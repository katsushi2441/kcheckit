<?php
/**
 * CHECK — 監視対象を取りに行き、前回との差分を返す。
 *
 * 4つの方式を持つ。官公庁を調べたところ、RSSがある省庁と無い省庁が
 * 半々だった（厚労省・デジタル庁・経産省・金融庁はRSSあり、国交省・
 * 環境省・消費者庁・特許庁は無いかアクセス拒否）。1方式では半分の
 * 情報源が監視できないので、最初から4方式を持たせている。
 *
 *   rss   RSS / Atom フィード
 *   json  JSON API（jGrants補助金・e-Gov法令など）
 *   html  HTMLページの差分（RSSが無いところ用）
 *   tls   SSL証明書の期限（自社サイトの見守り）
 *
 * 方式を増やすときは、ここに kchk_check_xxx() を足して
 * kcheckit_lib.php の kchk_methods() に1行加える。それだけで画面にも出る。
 *
 * PHP 5.x でも動く構文だけを使う。
 */
require_once __DIR__ . '/kcheckit_lib.php';

/**
 * 監視対象を1つチェックする。
 *
 * @return array list($ok, $items, $status, $error)
 *   items は array('key'=>一意キー, 'title'=>見出し, 'url'=>リンク, 'date'=>日付)
 */
function kchk_check_source($source) {
    $method = isset($source['method']) ? $source['method'] : 'rss';
    if ($method === 'rss')  { return kchk_check_rss($source); }
    if ($method === 'json') { return kchk_check_json($source); }
    if ($method === 'html') { return kchk_check_html($source); }
    if ($method === 'tls')  { return kchk_check_tls($source); }
    return array(false, array(), '', '未対応の方式です: ' . $method);
}

/* ---------------- RSS / Atom ---------------- */

function kchk_check_rss($source) {
    list($status, $body) = kchk_http_get($source['url']);
    if ($status !== 200 || $body === '') {
        return array(false, array(), $status, 'フィードを取得できませんでした');
    }
    // SimpleXMLが無い共有サーバーがある（実際に遭遇した）。
    // 買った人のサーバー構成は選べないので、無ければ自前で読む。
    $items = function_exists('simplexml_load_string')
        ? kchk_parse_feed_xml($body)
        : kchk_parse_feed_regex($body);

    if (!is_array($items)) {
        return array(false, array(), $status, 'フィードを解釈できませんでした（XMLではない可能性）');
    }
    if (!$items) {
        return array(false, array(), $status, '項目が0件でした（フィードの形式が変わった可能性）');
    }
    return array(true, $items, $status, '');
}

/** SimpleXMLがある環境。RSS 2.0 / RDF / Atom で要素名が違うので順に見る。 */
function kchk_parse_feed_xml($body) {
    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if ($xml === false) { return null; }

    $nodes = array();
    if (isset($xml->channel->item)) { $nodes = $xml->channel->item; }
    elseif (isset($xml->item))      { $nodes = $xml->item; }
    elseif (isset($xml->entry))     { $nodes = $xml->entry; }

    $items = array();
    foreach ($nodes as $n) {
        $title = trim((string)$n->title);
        $link  = trim((string)$n->link);
        if ($link === '' && isset($n->link['href'])) { $link = trim((string)$n->link['href']); }
        $date  = '';
        foreach (array('pubDate', 'updated', 'published', 'date') as $f) {
            if (isset($n->$f) && (string)$n->$f !== '') { $date = trim((string)$n->$f); break; }
        }
        if ($title === '' && $link === '') { continue; }
        $items[] = array(
            'key'   => $link !== '' ? $link : md5($title),
            'title' => $title,
            'url'   => $link,
            'date'  => $date,
        );
        if (count($items) >= KCHK_MAX_ITEMS_PER_SOURCE) { break; }
    }
    return $items;
}

/**
 * SimpleXMLが無い環境向け。item / entry を切り出して中身を拾う。
 *
 * XMLを正規表現で読むのは本来よくないが、RSSは構造が単純で、
 * ここで欲しいのは title / link / 日付の3つだけ。
 * 「動かない」より「多少雑でも動く」を選んでいる。
 */
function kchk_parse_feed_regex($body) {
    if (!preg_match_all('#<(item|entry)[\s>].*?</\1>#is', $body, $m)) {
        // 属性なしの <item> にも対応する
        if (!preg_match_all('#<(item|entry)>.*?</\1>#is', $body, $m)) { return array(); }
    }
    $items = array();
    foreach ($m[0] as $chunk) {
        $title = kchk_tag_text($chunk, 'title');
        $link  = kchk_tag_text($chunk, 'link');
        if ($link === '' && preg_match('#<link[^>]+href=["\']([^"\']+)#i', $chunk, $lm)) {
            $link = trim($lm[1]);   // Atom の <link href="...">
        }
        $date = '';
        foreach (array('pubDate', 'updated', 'published', 'dc:date', 'date') as $f) {
            $date = kchk_tag_text($chunk, $f);
            if ($date !== '') { break; }
        }
        if ($title === '' && $link === '') { continue; }
        $items[] = array(
            'key'   => $link !== '' ? $link : md5($title),
            'title' => $title,
            'url'   => $link,
            'date'  => $date,
        );
        if (count($items) >= KCHK_MAX_ITEMS_PER_SOURCE) { break; }
    }
    return $items;
}

/** タグの中身を取り出す。CDATAと実体参照をほどく。 */
function kchk_tag_text($chunk, $tag) {
    $t = preg_quote($tag, '#');
    if (!preg_match('#<' . $t . '(?:\s[^>]*)?>(.*?)</' . $t . '>#is', $chunk, $m)) { return ''; }
    $v = $m[1];
    if (preg_match('#<!\[CDATA\[(.*?)\]\]>#is', $v, $c)) { $v = $c[1]; }
    $v = html_entity_decode(strip_tags($v), ENT_QUOTES, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $v));
}

/* ---------------- JSON API ---------------- */

/**
 * JSON APIから一覧を取る。
 *
 * APIごとに項目名が違うので、設定で対応づけできるようにしてある。
 * source['note'] に "list=result;title=title;url=front_subsidy_detail_page_url;date=acceptance_end_datetime"
 * のように書く（省略時はよくある名前を順に試す）。
 */
function kchk_check_json($source) {
    $map = kchk_parse_map(isset($source['note']) ? $source['note'] : '');
    $pages = isset($map['pages']) ? max(1, min(20, (int)$map['pages'])) : 1;

    $list = array();
    $url = $source['url'];
    $status = 0;
    for ($p = 0; $p < $pages; $p++) {
        list($status, $body) = kchk_http_get($url, 30);
        if ($status !== 200 || $body === '') {
            return array(false, array(), $status, 'APIから取得できませんでした');
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return array(false, array(), $status, 'JSONを解釈できませんでした');
        }
        $chunk = kchk_locate_list($data, isset($map['list']) ? $map['list'] : '');
        if (!is_array($chunk)) {
            return array(false, array(), $status, '一覧の場所が分かりませんでした（noteで list= を指定してください）');
        }
        $list = array_merge($list, $chunk);

        // APIが次ページの位置を教えてくれるなら、それをたどる。
        //
        // e-Gov法令APIは全9537件あり、既定の並びは公布順に近い。1ページだけ
        // 取ると明治時代の布告ばかりが並び、最近の改正に届かない（実測: limit=20で
        // 2025年、limit=500で2026-08-03）。pages= を指定して全体を舐めてから
        // 並べ替えることで、本当に最近更新された法令を拾える。
        if (empty($data['next_offset']) || !$chunk) { break; }
        $url = preg_replace('/([?&])offset=\d+/', '$1offset=' . (int)$data['next_offset'], $source['url']);
        if (strpos($url, 'offset=') === false) {
            $url = $source['url'] . (strpos($source['url'], '?') === false ? '?' : '&')
                 . 'offset=' . (int)$data['next_offset'];
        }
    }
    if (!$list) {
        return array(false, array(), $status, '一覧が空でした');
    }

    $items = array();
    foreach ($list as $row) {
        if (!is_array($row)) { continue; }
        // 1件が入れ子になっているAPIがある（e-Govは law_info / revision_info に分かれる）。
        // 1段だけ平らにしてから項目を探す。
        $row = kchk_flatten($row);
        $title = kchk_pick($row, !empty($map['title']) ? array($map['title']) : array('title', 'name', 'law_title', 'law_name', 'subject'));
        $url   = kchk_pick($row, !empty($map['url'])   ? array($map['url'])   : array('url', 'link', 'detail_url', 'front_subsidy_detail_page_url'));
        $date  = kchk_pick($row, !empty($map['date'])  ? array($map['date'])  : array('date', 'updated', 'created_date', 'acceptance_end_datetime', 'amendment_promulgate_date'));
        $id    = kchk_pick($row, array('id', 'law_revision_id', 'law_id', 'subsidy_id'));
        if ($title === '' && $id === '') { continue; }
        $items[] = array(
            'key'   => $id !== '' ? $id : md5($title . $url),
            'title' => $title !== '' ? $title : $id,
            'url'   => $url,
            'date'  => $date,
        );
        // 並べ替えるなら、先に打ち切ると古いものだけが残ってしまう。
        // sort= が指定されているときは全件読んでから切る。
        if (empty($map['sort']) && count($items) >= KCHK_MAX_ITEMS_PER_SOURCE) { break; }
    }
    if (!$items) {
        return array(false, array(), $status, '項目が0件でした（APIの形式が変わった可能性）');
    }

    // note に "sort=updated" と書くと、その項目の新しい順に並べ替えてから上位を取る。
    //
    // e-Gov法令APIがこれを必要とする。並べ替えずに先頭から取ると、明治時代の
    // 太政官布告のような「古い順に並んだ一覧の先頭」が毎回来てしまい、
    // 実際に改正されたものが拾えない（実測で確認）。
    // APIにも並べ替えパラメータが無いので、こちらで並べ替える。
    if (!empty($map['sort'])) {
        $items = kchk_sort_by_field($list, $items, $map['sort']);
        $items = array_slice($items, 0, KCHK_MAX_ITEMS_PER_SOURCE);
    }
    return array(true, $items, $status, '');
}

/**
 * 指定した項目の新しい順に並べ替える。
 * 元データ($list)から並べ替えキーを引き直し、items と同じ順序で対応づける。
 */
function kchk_sort_by_field($list, $items, $field) {
    $keys = array();
    $i = 0;
    foreach ($list as $row) {
        if (!is_array($row)) { continue; }
        $flat = kchk_flatten($row);
        $keys[$i] = isset($flat[$field]) ? (string)$flat[$field] : '';
        $i++;
    }
    // items は空行を飛ばしているので、件数がずれたら並べ替えない（誤対応させない）
    if (count($keys) !== count($items)) { return $items; }

    $pairs = array();
    foreach ($items as $n => $it) { $pairs[] = array($keys[$n], $it); }
    usort($pairs, function ($a, $b) {
        return strcmp($b[0], $a[0]);   // 新しい順（降順）
    });
    $out = array();
    foreach ($pairs as $p) { $out[] = $p[1]; }
    return $out;
}

/** 応答のどこに一覧が入っているかを決める。 */
function kchk_locate_list($data, $hint) {
    if ($hint !== '' && isset($data[$hint]) && is_array($data[$hint])) { return $data[$hint]; }
    foreach (array('result', 'results', 'items', 'laws', 'data', 'records') as $k) {
        if (isset($data[$k]) && is_array($data[$k])) { return $data[$k]; }
    }
    if (isset($data[0])) { return $data; }
    return null;
}

/** "list=result;title=title" 形式を配列にする。 */
function kchk_parse_map($note) {
    $map = array();
    foreach (explode(';', (string)$note) as $pair) {
        $pair = trim($pair);
        if ($pair === '' || strpos($pair, '=') === false) { continue; }
        list($k, $v) = explode('=', $pair, 2);
        $map[trim($k)] = trim($v);
    }
    return $map;
}

/**
 * 入れ子を1段だけ平らにする。
 * 浅いほうを優先して残す（同じキー名が両方にある場合、上位が正）。
 */
function kchk_flatten($row) {
    $flat = array();
    foreach ($row as $k => $v) {
        if (is_array($v)) {
            foreach ($v as $k2 => $v2) {
                if (!is_array($v2) && !isset($flat[$k2]) && !isset($row[$k2])) { $flat[$k2] = $v2; }
            }
        } else {
            $flat[$k] = $v;
        }
    }
    return $flat;
}

/** 候補のキー名を順に試して、最初に見つかった値を返す。 */
function kchk_pick($row, $keys) {
    foreach ($keys as $k) {
        if (isset($row[$k]) && (is_string($row[$k]) || is_numeric($row[$k]))) {
            $v = trim((string)$row[$k]);
            if ($v !== '') { return $v; }
        }
    }
    return '';
}

/* ---------------- HTMLページの差分 ---------------- */

/**
 * RSSが無い省庁向け。ページ内のリンクを拾って、前回に無かったものを新着とみなす。
 *
 * 【この方式は壊れる前提で使う】
 * 相手がページ構成を変えると、黙って0件になる。そのため0件は「異常」として
 * 扱い、連続すると画面に警告を出す（kchk_broken_sources）。
 * 「監視しているつもりで何も見ていない」が一番まずい。
 */
function kchk_check_html($source) {
    list($status, $body) = kchk_http_get($source['url'], 25);
    if ($status !== 200 || $body === '') {
        return array(false, array(), $status, 'ページを取得できませんでした');
    }
    $base = $source['url'];
    // note に "contains=/press/" と書くと、そのURLだけに絞れる
    $map = kchk_parse_map(isset($source['note']) ? $source['note'] : '');
    $contains = isset($map['contains']) ? $map['contains'] : '';

    $items = array();
    $seen  = array();
    if (preg_match_all('#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $body, $m, PREG_SET_ORDER)) {
        foreach ($m as $one) {
            $href  = trim($one[1]);
            $title = trim(preg_replace('/\s+/u', ' ', strip_tags($one[2])));
            if ($href === '' || $title === '') { continue; }
            if (strpos($href, '#') === 0 || stripos($href, 'javascript:') === 0) { continue; }
            if (mb_strlen($title, 'UTF-8') < 6) { continue; }   // 「次へ」等のナビを捨てる
            $abs = kchk_absolute_url($href, $base);
            if ($abs === '') { continue; }
            if ($contains !== '' && strpos($abs, $contains) === false) { continue; }
            if (isset($seen[$abs])) { continue; }
            $seen[$abs] = true;
            $items[] = array('key' => $abs, 'title' => $title, 'url' => $abs, 'date' => '');
            if (count($items) >= KCHK_MAX_ITEMS_PER_SOURCE) { break; }
        }
    }
    if (!$items) {
        return array(false, array(), $status,
            'リンクを1件も拾えませんでした（ページ構成が変わった可能性。noteの contains= を見直してください）');
    }
    return array(true, $items, $status, '');
}

/** 相対URLを絶対URLにする。 */
function kchk_absolute_url($href, $base) {
    if (preg_match('#^https?://#i', $href)) { return $href; }
    $p = parse_url($base);
    if (!isset($p['scheme']) || !isset($p['host'])) { return ''; }
    $root = $p['scheme'] . '://' . $p['host'];
    if (strpos($href, '//') === 0) { return $p['scheme'] . ':' . $href; }
    if (strpos($href, '/') === 0)  { return $root . $href; }
    $dir = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', $p['path']) : '/';
    return $root . $dir . $href;
}

/* ---------------- SSL証明書の期限 ---------------- */

/**
 * 自社サイトの見守り。証明書が切れるとサイトが開かなくなり、
 * 取引先からの信用に直結する。30日を切ったら新着として通知する。
 */
function kchk_check_tls($source) {
    $p = parse_url($source['url']);
    if (empty($p['host'])) { return array(false, array(), '', 'URLからホスト名を取れませんでした'); }
    $host = $p['host'];
    $port = isset($p['port']) ? (int)$p['port'] : 443;

    if (!function_exists('stream_socket_client')) {
        return array(false, array(), '', 'この環境では証明書を確認できません');
    }
    $ctx = stream_context_create(array('ssl' => array(
        'capture_peer_cert' => true, 'SNI_enabled' => true, 'peer_name' => $host,
    )));
    $errno = 0; $errstr = '';
    $client = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, 15,
        STREAM_CLIENT_CONNECT, $ctx);
    if (!$client) {
        return array(false, array(), '', 'TLS接続できませんでした: ' . $errstr);
    }
    $params = stream_context_get_params($client);
    fclose($client);
    if (empty($params['options']['ssl']['peer_certificate'])) {
        return array(false, array(), '', '証明書を取得できませんでした');
    }
    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
    if (!$cert || empty($cert['validTo_time_t'])) {
        return array(false, array(), '', '証明書を解釈できませんでした');
    }
    $expire = (int)$cert['validTo_time_t'];
    $days   = (int)floor(($expire - time()) / 86400);

    $items = array();
    if ($days <= 30) {
        // 期限が近いときだけ新着にする。日数をキーに入れておくと、
        // 残日数が変わるたびに1回だけ通知される。
        $items[] = array(
            'key'   => 'tls-' . $host . '-' . $days,
            'title' => $host . ' のSSL証明書は残り ' . $days . ' 日です（期限 ' . date('Y-m-d', $expire) . '）',
            'url'   => $source['url'],
            'date'  => date('Y-m-d', $expire),
        );
    }
    // 期限に余裕がある場合も「正常に確認できた」ので ok を返す
    return array(true, $items, '有効期限 ' . date('Y-m-d', $expire) . '（残り' . $days . '日）', '');
}

/* ---------------- 差分 ---------------- */

/**
 * 前回のスナップショットに無かったものだけを返す。
 *
 * 初回は全件が新着になってしまうので、記録だけして空を返す。
 * 導入直後に何十通も通知が飛ぶと、それだけで使うのをやめられる。
 */
function kchk_diff_new($source_id, $items, $first_run_silent = true) {
    $prev = kchk_load_snapshot($source_id);
    $prev_keys = array();
    foreach ($prev as $k) { $prev_keys[$k] = true; }

    $new = array();
    $all_keys = array();
    foreach ($items as $it) {
        $all_keys[] = $it['key'];
        if (!isset($prev_keys[$it['key']])) { $new[] = $it; }
    }
    // 前回分と今回分を混ぜて保存する（一覧から落ちた古い記事を再検知しないため）
    $merged = array_values(array_unique(array_merge($prev, $all_keys)));
    kchk_save_snapshot($source_id, $merged);

    if (!$prev && $first_run_silent) { return array(); }
    return $new;
}
