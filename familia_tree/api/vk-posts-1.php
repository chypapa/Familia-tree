<?php
/**
 * Последние посты группы ВКонтакте для блока «Наш блог».
 *
 * Отдаёт JSON: { "updated": "...", "posts": [ { title, text, image, image_small, url, date } ] }
 *
 * Правила отбора:
 *  - закреплённый пост, реклама и репосты — пропускаются;
 *  - нет картинки или нет текста — это не пост, пропускается;
 *  - первое предложение со словом «здравствуйте» (приветствие) убирается;
 *  - заголовок — первая строка текста, основной текст — всё остальное;
 *  - эмодзи удаляются из всего текста.
 *
 * Ответ ВК кэшируется в api/cache на cache_ttl секунд, поэтому ВК спрашивают
 * не чаще раза в 10 минут. Если ВК недоступен, отдаётся последний сохранённый список.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$config = require __DIR__ . '/vk-config.php';

$token    = trim((string)($config['token'] ?? ''));
$domain   = (string)($config['domain'] ?? 'russiagenealogy');
$limit    = max(1, (int)($config['limit'] ?? 4));
$ttl      = max(60, (int)($config['cache_ttl'] ?? 600));
$vkSite   = rtrim((string)($config['vk_site'] ?? 'https://vk.com'), '/');
$apiBase  = getenv('VK_API_BASE') ?: 'https://api.vk.com/method/';   // переопределяется только для тестов

$cacheDir  = __DIR__ . '/cache';
$cacheFile = $cacheDir . '/vk-posts.json';

// 1. Свежий кэш — отдаём сразу
if (is_file($cacheFile) && time() - filemtime($cacheFile) < $ttl) {
    header('Cache-Control: public, max-age=300');
    readfile($cacheFile);
    exit;
}

// 2. Спрашиваем ВК
$error = null;
$posts = null;

if ($token === '' || strpos($token, 'ВСТАВЬТЕ') === 0) {
    $error = 'no_token';
} else {
    $response = vk_request($apiBase . 'wall.get', [
        'domain'       => $domain,
        'count'        => 50,          // берём с запасом: часть постов отсеется фильтрами
        'filter'       => 'owner',
        'v'            => '5.199',
        'access_token' => $token,
    ]);

    if (!is_array($response)) {
        $error = 'vk_unavailable';
    } elseif (isset($response['error'])) {
        $error = 'vk_error_' . ($response['error']['error_code'] ?? 'unknown');
    } else {
        $posts = [];
        foreach (($response['response']['items'] ?? []) as $item) {
            $post = make_post($item, $vkSite);
            if ($post !== null) {
                $posts[] = $post;
                if (count($posts) >= $limit) break;
            }
        }
    }
}

// 3. Удачно — сохраняем и отдаём
if ($posts !== null) {
    $json = json_encode(
        ['updated' => date('c'), 'source' => $vkSite . '/' . $domain, 'posts' => $posts],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $tmp = $cacheFile . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) @rename($tmp, $cacheFile);
    header('Cache-Control: public, max-age=300');
    echo $json;
    exit;
}

// 4. ВК не ответил — отдаём старый кэш, если он есть
if (is_file($cacheFile)) {
    header('Cache-Control: public, max-age=60');
    readfile($cacheFile);
    exit;
}

http_response_code($error === 'no_token' ? 500 : 502);
header('Cache-Control: no-store');
echo json_encode(['posts' => [], 'error' => $error], JSON_UNESCAPED_UNICODE);


/* ===================================================================== */

/** Запрос к API ВК. Возвращает массив или null. */
function vk_request(string $url, array $params): ?array
{
    $url .= '?' . http_build_query($params);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'familia-tree-site/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx  = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = $body === false ? 0 : 200;
    }

    if (!is_string($body) || $body === '' || $code >= 500 || $code === 0) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/** Превращает пост ВК в карточку блога или возвращает null, если пост не подходит. */
function make_post(array $item, string $vkSite): ?array
{
    if (!empty($item['is_pinned']))      return null;   // закреплённый
    if (!empty($item['marked_as_ads']))  return null;   // реклама
    if (!empty($item['copy_history']))   return null;   // репост из другой группы
    if (isset($item['post_type']) && $item['post_type'] !== 'post') return null;

    $photo = pick_photo($item['attachments'] ?? []);
    if ($photo === null) return null;                   // нет картинки — не пост

    $text = strip_greeting(clean_text((string)($item['text'] ?? '')));
    if ($text === '') return null;                      // нет текста — не пост

    $lines = preg_split('/\n/u', $text);
    $title = '';
    while ($lines && $title === '') $title = trim((string)array_shift($lines));
    $body  = trim(preg_replace('/\s*\n\s*/u', ' ', implode("\n", $lines)) ?? '');

    return [
        'id'          => (int)$item['id'],
        'title'       => $title,
        'text'        => excerpt($body, 420),
        'image'       => $photo['large'],
        'image_small' => $photo['small'],
        'url'         => $vkSite . '/wall' . (int)$item['owner_id'] . '_' . (int)$item['id'],
        'date'        => (int)($item['date'] ?? 0),
    ];
}

/** Первая фотография поста: большая (≥ 1080px) и уменьшенная (≥ 640px) версии. */
function pick_photo(array $attachments): ?array
{
    foreach ($attachments as $a) {
        if (($a['type'] ?? '') !== 'photo' || empty($a['photo']['sizes'])) continue;

        $sizes = array_values(array_filter($a['photo']['sizes'], function ($s) {
            return !empty($s['url']) && !empty($s['width']);
        }));
        if (!$sizes) continue;
        usort($sizes, function ($x, $y) { return $x['width'] <=> $y['width']; });

        $pick = function (int $min) use ($sizes) {
            foreach ($sizes as $s) if ($s['width'] >= $min) return $s['url'];
            return end($sizes)['url'];
        };
        return ['large' => $pick(1080), 'small' => $pick(640)];
    }
    return null;
}

/** Убирает эмодзи и разметку ссылок ВК, чистит пробелы. Переводы строк сохраняются. */
function clean_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = str_replace("\u{00AD}", '', $text);                      // мягкие переносы

    // ссылки ВК вида [id123|Имя], [club123|Группа], [https://...|текст] → текст
    $text = preg_replace('/\[(?:id|club|public|event)\d+\|([^\]]+)\]/u', '$1', $text) ?? $text;
    $text = preg_replace('/\[https?:\/\/[^\]|]+\|([^\]]+)\]/u', '$1', $text) ?? $text;

    // эмодзи: цифры-клавиши (1️⃣), флаги, пиктограммы, символы, модификаторы и связки
    $text = preg_replace('/[0-9#*]\x{FE0F}?\x{20E3}/u', '', $text) ?? $text;
    $text = preg_replace(
        '/[\x{1F000}-\x{1FAFF}'          // смайлики, пиктограммы, транспорт, флаги, оттенки кожи
        . '\x{2600}-\x{27BF}'            // ☀ ☎ ✅ ✨ ❤ ➡ и прочие символы-эмодзи
        . '\x{2B05}-\x{2B07}\x{2B1B}\x{2B1C}\x{2B50}\x{2B55}'
        . '\x{231A}\x{231B}\x{2328}\x{23CF}\x{23E9}-\x{23F3}\x{23F8}-\x{23FA}'
        . '\x{2194}-\x{2199}\x{21A9}\x{21AA}\x{2934}\x{2935}'
        . '\x{25AA}\x{25AB}\x{25B6}\x{25C0}\x{25FB}-\x{25FE}'
        . '\x{203C}\x{2049}\x{2122}\x{2139}\x{24C2}\x{3030}\x{303D}\x{3297}\x{3299}\x{00A9}\x{00AE}'
        . '\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u',
        '',
        $text
    ) ?? $text;

    // пробелы: двойные → одинарные, без пробела перед знаками препинания, края строк
    $text = preg_replace('/[ \t\x{00A0}]{2,}/u', ' ', $text) ?? $text;
    $text = preg_replace('/ +([,.!?:;…)])/u', '$1', $text) ?? $text;
    $text = implode("\n", array_map('trim', explode("\n", $text)));

    return trim($text);
}

/**
 * Убирает приветствие: если в первом предложении есть «здравствуй…»
 * («Всем здравствуйте!», «Здравствуйте, дорогие друзья!»), это предложение удаляется.
 * Предложение заканчивается на . ! ? … или на конце строки.
 */
function strip_greeting(string $text): string
{
    $text = ltrim($text);
    if (preg_match('/^[^\n]*?(?:[.!?…]+(?=\s|$)|(?=\n)|$)/u', $text, $m)
        && preg_match('/здравствуй/iu', $m[0])) {
        $text = ltrim(substr($text, strlen($m[0])));
    }
    return $text;
}

/** Обрезает текст по границе слова. */
function excerpt(string $text, int $max): string
{
    if (mb_strlen($text) <= $max) return $text;
    $cut = mb_substr($text, 0, $max);
    $space = mb_strrpos($cut, ' ');
    if ($space !== false && $space > $max * 0.6) $cut = mb_substr($cut, 0, $space);
    return rtrim($cut, " ,.;:—–-") . '…';
}
