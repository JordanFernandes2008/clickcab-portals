/**
 * Click Cabs — customer booking confirmation (Vercel / Node twin)
 * ===============================================================
 *
 * Behaviourally identical to api/send-confirmation.php. That file is the one
 * production uses, because production is Hostinger. This one exists so the
 * Vercel staging copy of the dashboard works the same way.
 *
 * If you change the logic in one, change it in the other.
 *
 * Auth model, in short: the browser sends its Firebase ID token; we use that
 * token to read /admins/{uid} through the Firestore REST API. Firebase checks
 * the signature, the security rules check the membership. A forged token gets
 * a 401 from Google, a real non-admin gets a 404. No service-account key is
 * needed or stored here.
 *
 * The caller sends only a booking id — never an address or a body — so this
 * cannot be used as an open relay.
 *
 * SETUP (Vercel → Project → Settings → Environment Variables)
 *   BREVO_API_KEY   your Brevo v3 API key
 *   MAIL_FROM_EMAIL a sender address verified in Brevo
 *   MAIL_FROM_NAME  optional, defaults to "Click Cabs"
 */

const PROJECT_ID = 'clickcabs-772bd';
const FS_BASE = 'https://firestore.googleapis.com/v1/projects/' + PROJECT_ID
              + '/databases/(default)/documents';

function uidFromToken(jwt) {
  const parts = String(jwt || '').split('.');
  if (parts.length !== 3) return '';
  try {
    const claims = JSON.parse(Buffer.from(parts[1], 'base64url').toString('utf8'));
    if (claims.aud !== PROJECT_ID) return '';
    return claims.sub || '';
  } catch (e) {
    return '';
  }
}

function fsValue(v) {
  if (!v || typeof v !== 'object') return '';
  for (const k of ['stringValue', 'integerValue', 'doubleValue', 'timestampValue']) {
    if (v[k] !== undefined) return String(v[k]);
  }
  if (v.booleanValue !== undefined) return v.booleanValue ? 'true' : 'false';
  return '';
}

function fsFields(doc) {
  const out = {};
  for (const [k, v] of Object.entries((doc && doc.fields) || {})) out[k] = fsValue(v);
  return out;
}

function esc(s) {
  return String(s === undefined || s === null ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function buildHtml(b) {
  const pairs = [
    ['Booking reference', b.ref], ['Service', b.service],
    ['Pickup', b.pickup], ['Drop', b.drop], ['Tour', b.tour],
    ['Package', b.package], ['Vehicle', b.vehicle],
    ['Date', b.date], ['Time', b.time], ['Passengers', b.passengers]
  ];
  const rows = pairs.filter(p => p[1]).map(p =>
    '<tr>'
    + '<td style="padding:7px 0;color:#6b7c8d;font-size:14px;width:150px;">' + esc(p[0]) + '</td>'
    + '<td style="padding:7px 0;color:#1a2b3c;font-size:14px;font-weight:600;">' + esc(p[1]) + '</td>'
    + '</tr>').join('');

  return '<div style="font-family:Segoe UI,Arial,sans-serif;max-width:560px;margin:0 auto;">'
    + '<div style="background:#01456c;padding:22px 26px;border-radius:10px 10px 0 0;">'
    + '<div style="color:#fff;font-size:20px;font-weight:800;letter-spacing:.5px;">CLICK<span style="color:#f5a800;">CABS</span></div>'
    + '<div style="color:rgba(255,255,255,.75);font-size:12px;margin-top:2px;">Your City, Your Ride</div>'
    + '</div>'
    + '<div style="border:1px solid #dde3ea;border-top:none;border-radius:0 0 10px 10px;padding:26px;">'
    + '<p style="font-size:16px;color:#1a2b3c;margin:0 0 6px;">Hi ' + esc(b.name || 'there') + ',</p>'
    + '<p style="font-size:15px;color:#1a7f4b;font-weight:700;margin:0 0 18px;">Your booking is confirmed.</p>'
    + '<table style="width:100%;border-collapse:collapse;border-top:1px solid #eef2f6;">' + rows + '</table>'
    + '<p style="font-size:14px;color:#6b7c8d;line-height:1.6;margin:18px 0 0;">'
    + 'Your driver details will be shared closer to the pickup time. '
    + 'Quote your reference if you call or message us.</p>'
    + '<p style="margin:18px 0 0;">'
    + '<a href="https://wa.me/919702290804" style="background:#25d366;color:#fff;text-decoration:none;'
    + 'padding:11px 20px;border-radius:8px;font-size:14px;font-weight:700;display:inline-block;">Message us on WhatsApp</a>'
    + '</p>'
    + '<p style="font-size:12px;color:#9aa8b6;margin:22px 0 0;">Click Cabs &middot; +91 97022 90804</p>'
    + '</div></div>';
}

module.exports = async (req, res) => {
  const fail = (code, error) => res.status(code).json({ ok: false, error });

  if (req.method !== 'POST') return fail(405, 'POST only');

  const KEY  = process.env.BREVO_API_KEY;
  const FROM = process.env.MAIL_FROM_EMAIL;
  if (!KEY || !FROM) {
    return fail(500, 'BREVO_API_KEY or MAIL_FROM_EMAIL is not set on this deployment.');
  }

  const body = typeof req.body === 'string' ? JSON.parse(req.body || '{}') : (req.body || {});
  const { idToken, bookingId } = body;
  if (!idToken || !bookingId) return fail(400, 'idToken and bookingId are both required');
  if (!/^[A-Za-z0-9_-]{1,64}$/.test(bookingId)) return fail(400, 'bookingId is not a valid document id');

  const uid = uidFromToken(idToken);
  if (!uid) return fail(401, 'Malformed ID token');

  const auth = { Authorization: 'Bearer ' + idToken, 'Content-Type': 'application/json' };

  /* 1. authenticate and authorise in one request */
  const adminRes = await fetch(FS_BASE + '/admins/' + encodeURIComponent(uid), { headers: auth });
  if (adminRes.status === 401 || adminRes.status === 403) return fail(401, 'ID token rejected by Firebase');
  if (adminRes.status === 404) return fail(403, 'This account is not an administrator');
  if (!adminRes.ok) return fail(502, 'Could not verify admin access (HTTP ' + adminRes.status + ')');

  /* 2. read the booking */
  const docRes = await fetch(FS_BASE + '/bookings/' + encodeURIComponent(bookingId), { headers: auth });
  if (docRes.status === 404) return fail(404, 'Booking not found');
  if (!docRes.ok) return fail(502, 'Could not read the booking (HTTP ' + docRes.status + ')');

  const b = fsFields(await docRes.json());
  const to = String(b.email || '').trim();
  if (!to) return fail(422, 'This booking has no email address');
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(to)) return fail(422, 'The stored email address is not valid');

  /* 3. send */
  const brevo = await fetch('https://api.brevo.com/v3/smtp/email', {
    method: 'POST',
    headers: { 'api-key': KEY, 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({
      sender: { name: process.env.MAIL_FROM_NAME || 'Click Cabs', email: FROM },
      to: [{ email: to, name: b.name || '' }],
      subject: 'Your Click Cabs booking is confirmed' + (b.ref ? ' (' + b.ref + ')' : ''),
      htmlContent: buildHtml(b)
    })
  });

  if (!brevo.ok) {
    const out = await brevo.json().catch(() => ({}));
    return fail(502, 'Brevo rejected the message: ' + (out.message || ('HTTP ' + brevo.status)));
  }

  /* 4. record that it went — best effort, never reported as a send failure */
  try {
    await fetch(
      FS_BASE + '/bookings/' + encodeURIComponent(bookingId) + '?updateMask.fieldPaths=confirmationSentAt',
      {
        method: 'PATCH',
        headers: auth,
        body: JSON.stringify({ fields: { confirmationSentAt: { timestampValue: new Date().toISOString() } } })
      }
    );
  } catch (e) { /* the email is already out; this is only bookkeeping */ }

  return res.status(200).json({ ok: true, sent: to });
};
