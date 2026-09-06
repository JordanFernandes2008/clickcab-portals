<?php
/**
 * Click Cabs — customer booking confirmation (Hostinger / any PHP host)
 * =====================================================================
 *
 * Called by admin-bookings.html when an admin moves a booking to
 * "confirmed". Sends the customer an email through Brevo.
 *
 * WHY THIS FILE EXISTS AT ALL
 * The Brevo API key must never reach the browser. Anyone holding it could
 * send mail as Click Cabs. So the key lives here, on the server, in a file
 * that is not committed.
 *
 * HOW IT KNOWS THE CALLER IS AN ADMIN — without a service account
 * The browser sends its Firebase ID token. We do not trust it. We use it to
 * read /admins/{uid} through the Firestore REST API. Firestore verifies the
 * token's signature itself, and the security rules only let a user read
 * their own admin document. So:
 *     a forged token   -> Google rejects it        -> 401
 *     a real non-admin -> no /admins/{uid} document -> 404
 *     a real admin     -> 200 with a document       -> allowed
 * That single request authenticates AND authorises, with no private key
 * anywhere on this server.
 *
 * WHY THE CALLER ONLY SENDS AN ID
 * The request carries a booking id, never an address or a message body. The
 * server reads the booking from Firestore and builds the email itself, so
 * this endpoint cannot be turned into an open relay for arbitrary mail.
 *
 * SETUP
 *   1. Copy mail-config.example.php to mail-config.php
 *   2. Put your Brevo API key and a verified sender address in it
 *   3. Never commit mail-config.php
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

const PROJECT_ID = 'clickcabs-772bd';
const FS_BASE    = 'https://firestore.googleapis.com/v1/projects/' . PROJECT_ID
                 . '/databases/(default)/documents';

function fail($code, $message) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'POST only');
}

/* ---------- config ---------- */
$cfgPath = __DIR__ . '/mail-config.php';
if (!file_exists($cfgPath)) {
    fail(500, 'mail-config.php is missing on the server. Copy mail-config.example.php and add your Brevo key.');
}
$cfg = require $cfgPath;
if (empty($cfg['brevo_key']) || empty($cfg['from_email'])) {
    fail(500, 'mail-config.php is present but brevo_key or from_email is empty.');
}

/* ---------- input ---------- */
$body = json_decode(file_get_contents('php://input'), true);
$idToken   = $body['idToken']   ?? '';
$bookingId = $body['bookingId'] ?? '';
if (!$idToken || !$bookingId) {
    fail(400, 'idToken and bookingId are both required');
}
if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $bookingId)) {
    fail(400, 'bookingId is not a valid document id');
}

/* ---------- small HTTP helper ---------- */
function http_json($method, $url, $headers = [], $payload = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return [0, ['curl' => $err]];
    return [$code, json_decode($raw, true)];
}

/* The uid is read from the token's payload without verifying the signature.
   That is safe here because we never trust it on its own — the Firestore
   read below is what proves the token is genuine and belongs to an admin. */
function uid_from_token($jwt) {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return '';
    $pad = strtr($parts[1], '-_', '+/');
    $pad .= str_repeat('=', (4 - strlen($pad) % 4) % 4);
    $claims = json_decode(base64_decode($pad), true);
    if (!is_array($claims)) return '';
    if (($claims['aud'] ?? '') !== PROJECT_ID) return '';
    return $claims['sub'] ?? '';
}

/* Firestore REST returns typed values; flatten the ones we use. */
function fs_value($v) {
    if (!is_array($v)) return '';
    foreach (['stringValue', 'integerValue', 'doubleValue', 'timestampValue'] as $k) {
        if (isset($v[$k])) return (string) $v[$k];
    }
    if (isset($v['booleanValue'])) return $v['booleanValue'] ? 'true' : 'false';
    return '';
}
function fs_fields($doc) {
    $out = [];
    foreach (($doc['fields'] ?? []) as $k => $v) $out[$k] = fs_value($v);
    return $out;
}

$auth = ['Authorization: Bearer ' . $idToken, 'Content-Type: application/json'];

/* ---------- 1. authenticate + authorise in one request ---------- */
$uid = uid_from_token($idToken);
if (!$uid) fail(401, 'Malformed ID token');

list($code, $adminDoc) = http_json('GET', FS_BASE . '/admins/' . rawurlencode($uid), $auth);
if ($code === 401 || $code === 403) fail(401, 'ID token rejected by Firebase');
if ($code === 404)                  fail(403, 'This account is not an administrator');
if ($code !== 200)                  fail(502, 'Could not verify admin access (HTTP ' . $code . ')');

/* ---------- 2. read the booking ---------- */
list($code, $doc) = http_json('GET', FS_BASE . '/bookings/' . rawurlencode($bookingId), $auth);
if ($code === 404) fail(404, 'Booking not found');
if ($code !== 200) fail(502, 'Could not read the booking (HTTP ' . $code . ')');

$b = fs_fields($doc);
$to = trim($b['email'] ?? '');
if ($to === '')                                  fail(422, 'This booking has no email address');
if (!filter_var($to, FILTER_VALIDATE_EMAIL))     fail(422, 'The stored email address is not valid');

/* ---------- 3. build and send ---------- */
$name = $b['name'] ?? 'there';
$ref  = $b['ref']  ?? '';
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };

$rows = '';
foreach ([
    'Booking reference' => $ref,
    'Service'           => $b['service']    ?? '',
    'Pickup'            => $b['pickup']     ?? '',
    'Drop'              => $b['drop']       ?? '',
    'Tour'              => $b['tour']       ?? '',
    'Package'           => $b['package']    ?? '',
    'Vehicle'           => $b['vehicle']    ?? '',
    'Date'              => $b['date']       ?? '',
    'Time'              => $b['time']       ?? '',
    'Passengers'        => $b['passengers'] ?? '',
] as $label => $value) {
    if ($value === '') continue;
    $rows .= '<tr>'
           . '<td style="padding:7px 0;color:#6b7c8d;font-size:14px;width:150px;">' . $e($label) . '</td>'
           . '<td style="padding:7px 0;color:#1a2b3c;font-size:14px;font-weight:600;">' . $e($value) . '</td>'
           . '</tr>';
}

$html = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:560px;margin:0 auto;">'
      . '<div style="background:#01456c;padding:22px 26px;border-radius:10px 10px 0 0;">'
      . '<div style="color:#fff;font-size:20px;font-weight:800;letter-spacing:.5px;">CLICK<span style="color:#f5a800;">CABS</span></div>'
      . '<div style="color:rgba(255,255,255,.75);font-size:12px;margin-top:2px;">Your City, Your Ride</div>'
      . '</div>'
      . '<div style="border:1px solid #dde3ea;border-top:none;border-radius:0 0 10px 10px;padding:26px;">'
      . '<p style="font-size:16px;color:#1a2b3c;margin:0 0 6px;">Hi ' . $e($name) . ',</p>'
      . '<p style="font-size:15px;color:#1a7f4b;font-weight:700;margin:0 0 18px;">Your booking is confirmed.</p>'
      . '<table style="width:100%;border-collapse:collapse;border-top:1px solid #eef2f6;">' . $rows . '</table>'
      . '<p style="font-size:14px;color:#6b7c8d;line-height:1.6;margin:18px 0 0;">'
      . 'Your driver details will be shared closer to the pickup time. '
      . 'Quote your reference if you call or message us.</p>'
      . '<p style="margin:18px 0 0;">'
      . '<a href="https://wa.me/919702290804" style="background:#25d366;color:#fff;text-decoration:none;'
      . 'padding:11px 20px;border-radius:8px;font-size:14px;font-weight:700;display:inline-block;">Message us on WhatsApp</a>'
      . '</p>'
      . '<p style="font-size:12px;color:#9aa8b6;margin:22px 0 0;">'
      . 'Click Cabs &middot; +91 97022 90804</p>'
      . '</div></div>';

list($code, $out) = http_json('POST', 'https://api.brevo.com/v3/smtp/email', [
    'api-key: ' . $cfg['brevo_key'],
    'Content-Type: application/json',
    'Accept: application/json',
], [
    'sender'      => ['name' => $cfg['from_name'] ?? 'Click Cabs', 'email' => $cfg['from_email']],
    'to'          => [['email' => $to, 'name' => $name]],
    'subject'     => 'Your Click Cabs booking is confirmed' . ($ref ? ' (' . $ref . ')' : ''),
    'htmlContent' => $html,
]);

if ($code < 200 || $code >= 300) {
    fail(502, 'Brevo rejected the message: ' . ($out['message'] ?? ('HTTP ' . $code)));
}

/* ---------- 4. record that it went ---------- */
/* Best effort. The customer already has the email; a failure to stamp the
   document must not be reported as a failure to send. */
http_json('PATCH',
    FS_BASE . '/bookings/' . rawurlencode($bookingId) . '?updateMask.fieldPaths=confirmationSentAt',
    $auth,
    ['fields' => ['confirmationSentAt' => ['timestampValue' => gmdate('Y-m-d\TH:i:s\Z')]]]
);

echo json_encode(['ok' => true, 'sent' => $to]);
