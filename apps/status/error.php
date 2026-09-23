<?php
/**
 * Blood Kings Monitoring - branded HTML error page (403 / 404 / 410 / 500 / 503)
 *
 * Served by the web server's ErrorDocument (code in ?code=) and included by
 * db.php when the database is unreachable ($bk_error_code = 503), so an
 * outage shows this page instead of the database's own error text.
 *
 * It loads nothing but its own dictionary: it has to render when the rest of
 * the application is what failed, so no lang.php (which sets a cookie), no
 * session and no database. Its links are absolute because it answers for any
 * path: on /status/foo/bar.php the old relative "index.php" led to
 * /status/foo/index.php, which is another 404.
 */

$code = isset($bk_error_code) ? (int)$bk_error_code : (isset($_GET['code']) ? (int)$_GET['code'] : 404);
if (!in_array($code, [403, 404, 410, 500, 503], true)) {
    $code = 404;
}

// The language: an explicit ?lang= or the visitor's saved choice first (the
// same order as lang.php), then the browser's Accept-Language. A visitor
// whose browser names neither Czech nor Slovak reads English more likely than
// Czech; only no header at all (crawlers, curl) keeps the Czech default.
$err_lang = null;
foreach ([$_GET['lang'] ?? null, $_SESSION['bk_lang'] ?? null, $_COOKIE['bk_lang'] ?? null] as $err_choice) {
    if ($err_choice === 'cs' || $err_choice === 'en') {
        $err_lang = $err_choice;
        break;
    }
}
if ($err_lang === null) {
    $err_accept = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    $err_best_q = -1.0;
    foreach ($err_accept === '' ? [] : explode(',', $err_accept) as $err_part) {
        $err_bits = explode(';', trim($err_part));
        $err_tag = substr(trim($err_bits[0]), 0, 2);
        $err_mapped = $err_tag === 'en' ? 'en' : (($err_tag === 'cs' || $err_tag === 'sk') ? 'cs' : null);
        if ($err_mapped === null) {
            continue;
        }
        $err_q = 1.0;
        foreach (array_slice($err_bits, 1) as $err_param) {
            if (preg_match('/^\s*q\s*=\s*([0-9.]+)/', $err_param, $err_m)) {
                $err_q = (float)$err_m[1];
            }
        }
        if ($err_q > $err_best_q) {
            $err_lang = $err_mapped;
            $err_best_q = $err_q;
        }
    }
    if ($err_lang === null) {
        $err_lang = $err_accept === '' ? 'cs' : 'en';
    }
}
// Literal paths, as in lang.php: the language is allowlisted above, and an
// include built from a request value would look like a file inclusion bug.
$err_strings = $err_lang === 'en' ? require __DIR__ . '/lang/en.php' : require __DIR__ . '/lang/cs.php';

$err_title_keys = [
    403 => 'error_page_title_403',
    404 => 'error_page_title_404',
    410 => 'error_page_title_410',
    500 => 'error_page_title_500',
    503 => 'error_page_title_503',
];
$err_message_keys = [
    403 => 'error_page_msg_403',
    404 => 'error_page_msg_404',
    410 => 'error_page_msg_410',
    500 => 'error_page_msg_500',
    // Only db.php knows the cause is the database; a 503 from the web server
    // itself (overload, maintenance) gets the general sentence.
    503 => isset($bk_error_code) ? 'error_page_msg_503_db' : 'error_page_msg_503',
];

$title = (string)$err_strings[$err_title_keys[$code]];
$message = (string)$err_strings[$err_message_keys[$code]];
$brand = (string)$err_strings['error_page_brand'];
$badge = sprintf((string)$err_strings['error_page_badge'], $code);

if (!headers_sent()) {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    // The text follows the browser's language, so a shared cache must not hand
    // one visitor's language to the next. An error page is never a search result.
    header('Vary: Accept-Language, Cookie');
    header('X-Robots-Tag: noindex');
    if ($code === 503) {
        header('Retry-After: 60');
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $err_lang; ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title><?php echo htmlspecialchars($title); ?> (<?php echo $code; ?>) | <?php echo htmlspecialchars($brand); ?></title>
  <style>
    :root {
      --bg-main: #0b0c10;
      --bg-card: #14161d;
      --border-color: rgba(255, 255, 255, 0.08);
      --accent-red: #b00020;
      --accent-glow: rgba(176, 0, 32, 0.35);
      --text-main: #f4f4f5;
      --text-muted: #a1a1aa;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      background-color: var(--bg-main);
      color: var(--text-main);
      font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
    }

    .error-card {
      background: var(--bg-card);
      border: 1px solid var(--border-color);
      border-radius: 16px;
      padding: 3rem 2.5rem;
      max-width: 520px;
      width: 100%;
      text-align: center;
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6), 0 0 30px var(--accent-glow);
      position: relative;
      overflow: hidden;
    }

    .error-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 4px;
      background: linear-gradient(90deg, #b00020, #ff4d6d);
    }

    .error-code-badge {
      display: inline-block;
      background: rgba(176, 0, 32, 0.12);
      border: 1px solid rgba(176, 0, 32, 0.3);
      color: #ff4d6d;
      font-size: 0.85rem;
      font-weight: 700;
      padding: 0.35rem 1rem;
      border-radius: 20px;
      margin-bottom: 1.25rem;
      letter-spacing: 0.05em;
    }

    .error-title {
      font-size: 1.75rem;
      font-weight: 800;
      margin-bottom: 0.75rem;
      color: #ffffff;
    }

    .error-message {
      color: var(--text-muted);
      font-size: 1rem;
      line-height: 1.6;
      margin-bottom: 2rem;
    }

    .error-actions {
      display: flex;
      gap: 1rem;
      justify-content: center;
      flex-wrap: wrap;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.75rem 1.5rem;
      border-radius: 8px;
      font-weight: 600;
      font-size: 0.95rem;
      text-decoration: none;
      transition: all 0.2s ease;
    }

    .btn-primary {
      background: var(--accent-red);
      color: #ffffff;
      box-shadow: 0 4px 12px var(--accent-glow);
    }

    .btn-primary:hover {
      background: #d30027;
      transform: translateY(-2px);
    }

    .btn-outline {
      background: transparent;
      border: 1px solid var(--border-color);
      color: var(--text-main);
    }

    .btn-outline:hover {
      background: rgba(255, 255, 255, 0.05);
      border-color: rgba(255, 255, 255, 0.2);
    }
  </style>
</head>
<body>
  <div class="error-card">
    <div class="error-code-badge"><?php echo htmlspecialchars($badge); ?></div>
    <h1 class="error-title"><?php echo htmlspecialchars($title); ?></h1>
    <p class="error-message"><?php echo htmlspecialchars($message); ?></p>
    <div class="error-actions">
      <a href="/app/public" class="btn btn-primary"><?php echo htmlspecialchars((string)$err_strings['error_page_link_public']); ?></a>
      <a href="/status/" class="btn btn-outline"><?php echo htmlspecialchars((string)$err_strings['error_page_link_status']); ?></a>
    </div>
  </div>
</body>
</html>
