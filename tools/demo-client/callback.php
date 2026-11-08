<?php

session_start();
$issuer = getenv('CLAUTH_ISSUER') ?: 'http://127.0.0.1:8020';
$clientId = trim((string) @file_get_contents(__DIR__.'/.client_id'));
if (isset($_GET['error']) || ($_GET['state'] ?? '') !== ($_SESSION['state'] ?? null)) {
    header('Location: /?'.http_build_query(['error' => $_GET['error'] ?? 'state_mismatch', 'error_description' => $_GET['error_description'] ?? '']));
    exit;
}
$post = function (string $url, array $data) {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => http_build_query($data), 'ignore_errors' => true]]);

    return json_decode((string) file_get_contents($url, false, $ctx), true);
};
$tok = $post("$issuer/oauth/token", ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'redirect_uri' => 'http://127.0.0.1:8021/callback.php',
    'code' => $_GET['code'], 'code_verifier' => $_SESSION['verifier']]);
if (empty($tok['access_token'])) {
    header('Location: /?error=token_failed&error_description='.urlencode(json_encode($tok)));
    exit;
}

// ---- Offline verification: only the public JWKS is used ----
$b64 = fn ($s) => base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4));
[$h, $p, $sig] = explode('.', $tok['access_token']);
$header = json_decode($b64($h), true);
$claims = json_decode($b64($p), true);
$jwks = json_decode(file_get_contents("$issuer/.well-known/jwks.json"), true);
$jwk = current(array_filter($jwks['keys'], fn ($k) => $k['kid'] === $header['kid']));
$len = fn ($l) => $l < 128 ? chr($l) : chr(0x80 | strlen($b = ltrim(pack('N', $l), "\0"))).$b;
$int = fn ($x) => "\x02".$len(strlen($x = (ord($x[0]) > 0x7F ? "\0".$x : $x))).$x;
$seq = fn ($x) => "\x30".$len(strlen($x)).$x;
$rsa = $seq($int($b64($jwk['n'])).$int($b64($jwk['e'])));
$der = $seq($seq("\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00")."\x03".$len(strlen($rsa) + 1)."\x00".$rsa);
$pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64)."-----END PUBLIC KEY-----\n";
$ok = openssl_verify("$h.$p", $b64($sig), $pem, OPENSSL_ALGO_SHA256) === 1
    && $claims['iss'] === $issuer && $claims['aud'] === 'demo' && $claims['exp'] > time();
if (! $ok) {
    header('Location: /?error=invalid_token');
    exit;
}
$_SESSION['claims'] = $claims;
$_SESSION['kid'] = $header['kid'];
$_SESSION['id_token'] = $tok['id_token'] ?? '';
header('Location: /');
