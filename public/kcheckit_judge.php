<?php
/**
 * JUDGE — 拾ってきた新着が、自社に関係あるかを絞り込む。
 *
 * 【この層で守っていること】
 * LLMに「対応が必要か」を判断させない。やらせるのは
 * 「関係しそうか」と「なぜそう思うか」の2つだけで、決めるのは人。
 *
 * 法令や規制の話は、間違えたときの損害が大きい。自信満々に外した判断は、
 * 何も出さないより有害になる。だから
 *   - 判定は「関係あり / 判断できず」の2択。「対応不要」は出さない
 *   - 必ず理由（どの語がどう引っかかったか）を残す
 *   - LLMが使えない環境ではキーワード照合に落ちる（動かないより良い）
 * という作りにしてある。
 *
 * PHP 5.x でも動く構文だけを使う。
 */
require_once __DIR__ . '/kcheckit_lib.php';

/** LLMを使うか。未設定ならキーワード照合だけで動く。 */
function kchk_llm_enabled() {
    return defined('KCHK_LLM_URL') && KCHK_LLM_URL !== '';
}

/**
 * 自社の事業内容を文章で得る。
 *
 * 設定に書いてあればそれを使う（手で書いたほうが精度が高い）。
 * 無ければ自社サイトのURLから拾う。結果はキャッシュして毎回取りに行かない。
 */
function kchk_profile() {
    $manual = kchk_business_profile();
    if ($manual !== '') { return $manual; }

    $cache = KCHK_DATA_DIR . '/profile.txt';
    if (file_exists($cache) && (time() - filemtime($cache)) < 86400 * 30) {
        return (string)file_get_contents($cache);
    }
    $url = kchk_site_url();
    if ($url === '') { return ''; }

    list($status, $body) = kchk_http_get($url, 20);
    if ($status !== 200 || $body === '') { return ''; }

    $text = kchk_extract_text($body);
    if ($text === '') { return ''; }

    if (!is_dir(KCHK_DATA_DIR)) { @mkdir(KCHK_DATA_DIR, 0705, true); }
    @file_put_contents($cache, $text, LOCK_EX);
    return $text;
}

/** HTMLから本文らしき文字列を取り出す。titleとdescriptionと見出しを優先する。 */
function kchk_extract_text($html) {
    $parts = array();
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
        $parts[] = trim(strip_tags($m[1]));
    }
    if (preg_match('#<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
        $parts[] = trim($m[1]);
    }
    if (preg_match_all('#<h[1-3][^>]*>(.*?)</h[1-3]>#is', $html, $m)) {
        foreach ($m[1] as $h) {
            $h = trim(preg_replace('/\s+/u', ' ', strip_tags($h)));
            if ($h !== '') { $parts[] = $h; }
        }
    }
    $body = preg_replace('#<(script|style|nav|footer)[^>]*>.*?</\1>#is', ' ', $html);
    $body = trim(preg_replace('/\s+/u', ' ', strip_tags($body)));
    if ($body !== '') { $parts[] = mb_substr($body, 0, 1200, 'UTF-8'); }

    return mb_substr(implode("\n", $parts), 0, 2000, 'UTF-8');
}

/**
 * 事業内容から検索用のキーワードを作る。
 * LLMが無い環境でも動く土台になる。
 */
function kchk_keywords($profile) {
    if (defined('KCHK_KEYWORDS') && KCHK_KEYWORDS !== '') {
        $words = preg_split('/[,、\s]+/u', KCHK_KEYWORDS);
        return array_values(array_filter(array_map('trim', $words)));
    }
    // profile から2文字以上のカタカナ・漢字の連なりを拾い、頻度の高い順に取る
    $words = array();
    if (preg_match_all('/[一-龠]{2,6}|[ァ-ヶー]{3,10}/u', (string)$profile, $m)) {
        foreach ($m[0] as $w) {
            if (mb_strlen($w, 'UTF-8') < 2) { continue; }
            $words[$w] = isset($words[$w]) ? $words[$w] + 1 : 1;
        }
    }
    arsort($words);
    return array_slice(array_keys($words), 0, 25);
}

/**
 * 新着1件が自社に関係あるかを見る。
 *
 * @return array array('related'=>bool, 'reason'=>string, 'by'=>'keyword'|'llm')
 */
function kchk_judge_item($item, $profile, $keywords) {
    $hay = $item['title'] . ' ' . (isset($item['url']) ? $item['url'] : '');

    $hits = array();
    foreach ($keywords as $w) {
        if ($w !== '' && mb_strpos($hay, $w, 0, 'UTF-8') !== false) { $hits[] = $w; }
    }
    if ($hits) {
        return array(
            'related' => true,
            'reason'  => '「' . implode('」「', array_slice($hits, 0, 4)) . '」が見出しに含まれます',
            'by'      => 'keyword',
        );
    }
    if (kchk_llm_enabled() && $profile !== '') {
        $r = kchk_judge_by_llm($item, $profile);
        if ($r !== null) { return $r; }
    }
    return array('related' => false, 'reason' => '', 'by' => 'keyword');
}

/**
 * LLMに聞く。返させるのは「関係するか」と「理由」だけ。
 *
 * 「対応が必要か」「いつまでに何をすべきか」は聞かない。
 * それは人が決めることで、モデルに答えさせると、それらしい断定が返ってきて
 * しまう。ここで欲しいのは絞り込みであって、判断ではない。
 */
function kchk_judge_by_llm($item, $profile) {
    $prompt = "次の会社にとって、この情報が関係するかどうかだけを判定してください。\n"
            . "対応が必要かどうかは判断しないでください。関係の有無と理由だけを答えます。\n\n"
            . "【会社の事業内容】\n" . mb_substr($profile, 0, 800, 'UTF-8') . "\n\n"
            . "【情報】\n" . $item['title'] . "\n\n"
            . "次の形式だけを出力してください（説明や前置きは不要）:\n"
            . "関係あり|理由を40字以内\n"
            . "または\n"
            . "関係なし|\n";

    $payload = json_encode(array(
        'model'  => defined('KCHK_LLM_MODEL') ? KCHK_LLM_MODEL : 'gemma4:12b-it-qat',
        'prompt' => $prompt,
        'stream' => false,
        // gemma4系は思考型。think未指定だと隠れ推論が出力枠を食い潰し、
        // 応答が空になる（既知の罠。Ollama再起動でも直らない）。
        'think'  => false,
        'options'=> array('temperature' => 0.1, 'num_predict' => 120),
    ), JSON_UNESCAPED_UNICODE);

    $res = kchk_llm_post(KCHK_LLM_URL, $payload);
    if ($res === '') { return null; }
    $d = json_decode($res, true);
    $text = is_array($d) && isset($d['response']) ? trim($d['response']) : '';
    if ($text === '') { return null; }

    if (mb_strpos($text, '関係あり', 0, 'UTF-8') === 0) {
        $reason = '';
        $pos = mb_strpos($text, '|', 0, 'UTF-8');
        if ($pos !== false) { $reason = trim(mb_substr($text, $pos + 1, 80, 'UTF-8')); }
        return array('related' => true, 'reason' => $reason !== '' ? $reason : 'AIが関連ありと判定', 'by' => 'llm');
    }
    return array('related' => false, 'reason' => '', 'by' => 'llm');
}

function kchk_llm_post($url, $payload) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_TIMEOUT, defined('KCHK_LLM_TIMEOUT') ? (int)KCHK_LLM_TIMEOUT : 60);
        $out = curl_exec($ch);
        curl_close($ch);
        return $out === false ? '' : (string)$out;
    }
    $ctx = stream_context_create(array('http' => array(
        'method' => 'POST', 'header' => "Content-Type: application/json\r\n",
        'content' => $payload, 'timeout' => 60, 'ignore_errors' => true,
    )));
    $out = @file_get_contents($url, false, $ctx);
    return $out === false ? '' : (string)$out;
}
