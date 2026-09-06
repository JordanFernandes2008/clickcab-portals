<?php
/**
 * Click Cabs — daily booking digest
 * =================================
 *
 * Emails a summary of the last 24 hours of bookings to the operations
 * address. Run once a day by Hostinger's cron scheduler. Its whole purpose
 * is to make the system useful on days when nobody opens the dashboard.
 *
 * RUN IT FROM CRON, NOT THE BROWSER
 *   php /home/USERNAME/public_html/api/daily-digest.php
 * It refuses to run over HTTP, so nobody can trigger it by guessing the URL.
 *
 * HOW IT READS FIRESTORE WITHOUT A SERVICE ACCOUNT
 * There is no logged-in admin at 7am, so there is no ID token to borrow.
 * Instead this script signs in as a dedicated Firebase account using the
 * Auth REST API, exactly as a browser would, and uses the token it gets
 * back. That account must have a document in /admins so the rules let it
 * read bookings. Its password lives in mail-config.php, which is
 * gitignored — the same place as the Brevo key. No Admin SDK, no
 * service-account JSON, nothing that bypasses the security rules.
 *
 * Create the digest account once, in the Firebase console:
 *   Authentication -> Users -> Add user  (e.g. digest@clickcabs.in)
 * then add its UID to the admins collection the same way you added yours.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script runs from cron only.\n");
}

const PROJECT_ID = 'clickcabs-772bd';
const FS_BASE    = 'https://firestore.googleapis.com/v1/projects/' . PROJECT_ID
                 . '/databases/(default)/documents';

$cfgPath = __DIR__ . '/mail-config.php';
if (!file_exists($cfgPath)) { exit("mail-config.php is missing.\n"); }
$cfg = require $cfgPath;

foreach (['brevo_key', 'from_email', 'web_api_key', 'digest_user', 'digest_pass', 'digest_to'] as $k) {
    if (empty($cfg[$k])) { exit("mail-config.php is missing '$k'.\n"); }
}

function http_json($method, $url, $headers = [], $payload = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($raw, true)];
}

/* ---------- 1. sign in as the digest account ---------- */
list($code, $auth) = http_json('POST',
    'https://identitytoolkit.googleapis.com/v1/accounts:signInWithPassword?key=' . $cfg['web_api_key'],
    ['Content-Type: application/json'],
    ['email' => $cfg['digest_user'], 'password' => $cfg['digest_pass'], 'returnSecureToken' => true]
);
if ($code !== 200 || empty($auth['idToken'])) {
    exit("Digest sign-in failed (HTTP $code): " . ($auth['error']['message'] ?? 'unknown') . "\n");
}
$headers = ['Authorization: Bearer ' . $auth['idToken'], 'Content-Type: application/json'];

/* ---------- 2. everything from the last 24 hours ---------- */
$since = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
list($code, $rows) = http_json('POST', FS_BASE . ':runQuery', $headers, [
    'structuredQuery' => [
        'from'    => [['collectionId' => 'bookings']],
        'where'   => ['fieldFilter' => [
            'field' => ['fieldPath' => 'createdAt'],
            'op'    => 'GREATER_THAN_OR_EQUAL',
            'value' => ['timestampValue' => $since],
        ]],
        'orderBy' => [['field' => ['fieldPath' => 'createdAt'], 'direction' => 'DESCENDING']],
        'limit'   => 200,
    ],
]);
if ($code !== 200) { exit("Query failed (HTTP $code)\n"); }

function val($v) {
    foreach (['stringValue', 'integerValue', 'timestampValue'] as $k) {
        if (isset($v[$k])) return (string) $v[$k];
    }
    return '';
}

$bookings = [];
foreach (($rows ?: []) as $r) {
    if (empty($r['document']['fields'])) continue;
    $f = [];
    foreach ($r['document']['fields'] as $k => $v) $f[$k] = val($v);
    $bookings[] = $f;
}

if (!$bookings) {
    echo "No bookings in the last 24 hours. Nothing sent.\n";
    exit(0);
}

/* ---------- 3. build and send ---------- */
$e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };

$byStatus = [];
foreach ($bookings as $b) {
    $s = $b['status'] ?? 'new';
    $byStatus[$s] = ($byStatus[$s] ?? 0) + 1;
}
$chips = '';
foreach ($byStatus as $s => $n) {
    $chips .= '<span style="display:inline-block;background:#eef3f8;color:#01456c;border-radius:20px;'
            . 'padding:4px 12px;font-size:13px;font-weight:700;margin:0 6px 6px 0;">'
            . $e($s) . ': ' . $n . '</span>';
}

$rowsHtml = '';
foreach ($bookings as $b) {
    $trip = $b['tour'] ?? '';
    if ($trip === '') {
        $trip = trim(($b['pickup'] ?? '') . ($b['drop'] ? ' -> ' . $b['drop'] : ''));
    }
    $rowsHtml .= '<tr style="border-bottom:1px solid #eef2f6;">'
      . '<td style="padding:9px 6px;font-family:monospace;font-weight:700;color:#01456c;font-size:13px;">' . $e($b['ref'] ?? '') . '</td>'
      . '<td style="padding:9px 6px;font-size:13px;"><strong>' . $e($b['name'] ?? '') . '</strong><br>'
      .   '<span style="color:#6b7c8d;">' . $e($b['phone'] ?? '') . '</span></td>'
      . '<td style="padding:9px 6px;font-size:13px;text-transform:capitalize;">' . $e($b['service'] ?? '') . '</td>'
      . '<td style="padding:9px 6px;font-size:13px;">' . $e($trip) . '</td>'
      . '<td style="padding:9px 6px;font-size:13px;text-transform:capitalize;">' . $e($b['status'] ?? 'new') . '</td>'
      . '</tr>';
}

$html = '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:720px;margin:0 auto;">'
  . '<div style="background:#01456c;padding:20px 24px;border-radius:10px 10px 0 0;">'
  . '<div style="color:#fff;font-size:18px;font-weight:800;">CLICK<span style="color:#f5a800;">CABS</span>'
  . ' &mdash; Daily Bookings</div>'
  . '<div style="color:rgba(255,255,255,.75);font-size:12px;margin-top:3px;">'
  . 'Last 24 hours &middot; ' . gmdate('d M Y') . '</div></div>'
  . '<div style="border:1px solid #dde3ea;border-top:none;border-radius:0 0 10px 10px;padding:22px 24px;">'
  . '<p style="font-size:26px;font-weight:900;color:#1a2b3c;margin:0 0 4px;">' . count($bookings) . '</p>'
  . '<p style="font-size:13px;color:#6b7c8d;margin:0 0 14px;">'
  . (count($bookings) === 1 ? 'booking' : 'bookings') . ' received</p>'
  . '<div style="margin-bottom:16px;">' . $chips . '</div>'
  . '<table style="width:100%;border-collapse:collapse;">'
  . '<tr style="background:#f8fafc;">'
  . '<th style="text-align:left;padding:8px 6px;font-size:11px;color:#6b7c8d;text-transform:uppercase;">Ref</th>'
  . '<th style="text-align:left;padding:8px 6px;font-size:11px;color:#6b7c8d;text-transform:uppercase;">Customer</th>'
  . '<th style="text-align:left;padding:8px 6px;font-size:11px;color:#6b7c8d;text-transform:uppercase;">Service</th>'
  . '<th style="text-align:left;padding:8px 6px;font-size:11px;color:#6b7c8d;text-transform:uppercase;">Trip</th>'
  . '<th style="text-align:left;padding:8px 6px;font-size:11px;color:#6b7c8d;text-transform:uppercase;">Status</th>'
  . '</tr>' . $rowsHtml . '</table>'
  . '<p style="margin:20px 0 0;"><a href="' . $e($cfg['dashboard_url'] ?? 'https://clickcab-portals.vercel.app/admin-bookings.html')
  . '" style="background:#01456c;color:#fff;text-decoration:none;padding:11px 20px;border-radius:8px;'
  . 'font-size:14px;font-weight:700;display:inline-block;">Open the dashboard</a></p>'
  . '</div></div>';

list($code, $out) = http_json('POST', 'https://api.brevo.com/v3/smtp/email', [
    'api-key: ' . $cfg['brevo_key'],
    'Content-Type: application/json',
    'Accept: application/json',
], [
    'sender'      => ['name' => $cfg['from_name'] ?? 'Click Cabs', 'email' => $cfg['from_email']],
    'to'          => [['email' => $cfg['digest_to']]],
    'subject'     => 'Click Cabs — ' . count($bookings) . ' booking'
                     . (count($bookings) === 1 ? '' : 's') . ' in the last 24 hours',
    'htmlContent' => $html,
]);

if ($code < 200 || $code >= 300) {
    exit('Brevo rejected the digest: ' . ($out['message'] ?? ('HTTP ' . $code)) . "\n");
}
echo 'Digest sent to ' . $cfg['digest_to'] . ' (' . count($bookings) . " bookings).\n";
