<?php
// M0 sample app: a plain-PHP OIDC client (public client, PKCE) that signs in through Clauth
// and verifies the access token OFFLINE against JWKS. Local only:
//   php -S 127.0.0.1:8021 -t tools/demo-client   (Clauth on 127.0.0.1:8020, `php artisan db:seed` first)
session_start();
$issuer = getenv('CLAUTH_ISSUER') ?: 'http://127.0.0.1:8020';
$clientId = trim((string) @file_get_contents(__DIR__.'/.client_id'));
$redirect = 'http://127.0.0.1:8021/callback.php';
if (isset($_GET['login'])) {
    $_SESSION['verifier'] = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $_SESSION['state'] = bin2hex(random_bytes(8));
    $_SESSION['nonce'] = bin2hex(random_bytes(8));
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $_SESSION['verifier'], true)), '+/', '-_'), '=');
    header('Location: '.$issuer.'/oauth/authorize?'.http_build_query([
        'client_id' => $clientId, 'redirect_uri' => $redirect, 'response_type' => 'code', 'scope' => 'openid profile email clauth offline_access',
        'state' => $_SESSION['state'], 'nonce' => $_SESSION['nonce'], 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]));
    exit;
}
if (isset($_GET['logout'])) {
    $hint = $_SESSION['id_token'] ?? '';
    session_destroy();
    header('Location: '.$issuer.'/oauth/logout?'.http_build_query(['id_token_hint' => $hint, 'post_logout_redirect_uri' => 'http://127.0.0.1:8021/']));
    exit;
}
$claims = $_SESSION['claims'] ?? null;
?><!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Demo App</title>
<body style="font-family:system-ui;max-width:720px;margin:40px auto;padding:0 16px;background:#FFFBEB;color:#1C1917">
<h1>Demo App <small style="font-weight:400;color:#78716C">— signs in with Clauth</small></h1>
<?php if (! $claims) { ?>
  <?php if (isset($_GET['error'])) { ?><p style="color:#B91C1C">Clauth said: <?= htmlspecialchars($_GET['error'].' '.($_GET['error_description'] ?? '')) ?></p><?php } ?>
  <p><a href="?login=1" style="display:inline-block;background:#FBBF24;color:#111;padding:12px 18px;border-radius:10px;font-weight:700;text-decoration:none">Sign in with Clauth</a></p>
<?php } else { ?>
  <p>✅ Signed in as <b><?= htmlspecialchars($claims['name'] ?? '') ?></b> (<?= htmlspecialchars($claims['email'] ?? '') ?>). Access token verified <b>offline</b> with JWKS (kid <?= htmlspecialchars($_SESSION['kid']) ?>).</p>
  <pre style="background:#1C1917;color:#FDE68A;padding:16px;border-radius:10px;overflow:auto"><?= htmlspecialchars(json_encode($claims, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
  <p><a href="?logout=1">Sign out</a></p>
<?php }
