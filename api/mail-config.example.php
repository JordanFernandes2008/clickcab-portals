<?php
/**
 * Copy this file to mail-config.php and fill in the real values.
 *
 *   cp mail-config.example.php mail-config.php
 *
 * mail-config.php is listed in .gitignore and must NEVER be committed or
 * uploaded anywhere public. It holds every secret this project has.
 *
 * Used by:
 *   send-confirmation.php   needs brevo_key, from_email, from_name
 *   daily-digest.php        needs all of the above plus web_api_key,
 *                           digest_user, digest_pass, digest_to
 */

return [

    /* ---- Brevo ---------------------------------------------------------
     * Brevo dashboard -> SMTP & API -> API Keys -> Generate a new key.
     * It starts with "xkeysib-".
     *
     * from_email must be an address you have verified in Brevo under
     * Senders, Domains & Dedicated IPs. Brevo rejects mail from an
     * unverified sender.
     */
    'brevo_key'  => 'xkeysib-REPLACE-ME',
    'from_email' => 'bookings@clickcabs.in',
    'from_name'  => 'Click Cabs',

    /* ---- Daily digest --------------------------------------------------
     * Only needed if you set up the cron job for daily-digest.php.
     *
     * web_api_key  the PUBLIC Firebase web API key — the same apiKey that
     *              appears in js/cc-booking.js. Safe to put here.
     *
     * digest_user  a dedicated Firebase Auth account created solely for the
     * digest_pass  digest. Create it in Firebase console -> Authentication
     *              -> Users -> Add user, then add its UID to the admins
     *              collection so the security rules let it read bookings.
     *              Do not reuse a human admin's password here.
     *
     * digest_to    where the summary is emailed each morning.
     */
    'web_api_key'   => 'AIzaSyCoITUbvm1c0-Ud9VN_jVOZg99dg42jPgY',
    'digest_user'   => 'digest@clickcabs.in',
    'digest_pass'   => 'REPLACE-ME',
    'digest_to'     => 'bookings@clickcabs.in',
    'dashboard_url' => 'https://clickcab-portals.vercel.app/admin-bookings.html',
];
