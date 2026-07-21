<?php
/**
 * Blood Kings Monitoring - Custom HTML Error Page (404 / 403 / 500)
 */

$code = isset($_GET['code']) ? (int)$_GET['code'] : 404;
if (!in_array($code, [403, 404, 500], true)) {
    $code = 404;
}

http_response_code($code);

$error_titles = [
    403 => 'Přístup odepřen (403)',
    404 => 'Stránka nenalezena (404)',
    500 => 'Interní chyba serveru (500)',
];

$error_messages = [
    403 => 'K této sekci nebo souboru nemáte dostatečná přístupová práva.',
    404 => 'Požadovaná stránka neexistuje nebo byla přemístěna na jinou adresu.',
    500 => 'Došlo k neočekávané chybě na straně serveru. Zkuste to prosím znovu za malou chvíli.',
];

$title = $error_titles[$code];
$message = $error_messages[$code];
?>
<!DOCTYPE html>
<html lang="cs">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars($title); ?> | Blood Kings Monitoring</title>
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
    <div class="error-code-badge">CHYBA <?php echo $code; ?></div>
    <h1 class="error-title"><?php echo htmlspecialchars($title); ?></h1>
    <p class="error-message"><?php echo htmlspecialchars($message); ?></p>
    <div class="error-actions">
      <a href="index.php" class="btn btn-primary">🏠 Zpět na Status</a>
      <a href="admin.php" class="btn btn-outline">⚙️ Administrace</a>
    </div>
  </div>
</body>
</html>
