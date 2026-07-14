const admin = require('firebase-admin');

// Initialize the Admin SDK once per running function instance.
if (!admin.apps.length) {
  admin.initializeApp({
    credential: admin.credential.cert({
      projectId: process.env.FIREBASE_PROJECT_ID,
      clientEmail: process.env.FIREBASE_CLIENT_EMAIL,
      // Vercel env vars store newlines as literal "\n" -- convert them back.
      privateKey: (process.env.FIREBASE_PRIVATE_KEY || '').replace(/\\n/g, '\n'),
    }),
  });
}

const db = admin.firestore();

module.exports = async (req, res) => {
  if (req.method !== 'POST') {
    res.status(405).json({ error: 'Method not allowed.' });
    return;
  }

  try {
    const { fullName, email, password, secretCode } = req.body || {};

    if (!fullName || !email || !password || !secretCode) {
      res.status(400).json({ error: 'Please fill in all fields.' });
      return;
    }
    if (typeof password !== 'string' || password.length < 8) {
      res.status(400).json({ error: 'Password must be at least 8 characters.' });
      return;
    }

    // The only place this comparison happens is here, on the server.
    // process.env.ADMIN_SECRET is set in the Vercel dashboard and is
    // never sent to, or readable by, the browser.
    if (secretCode !== process.env.ADMIN_SECRET) {
      res.status(403).json({ error: 'Incorrect secret code. Contact Click Cabs management.' });
      return;
    }

    // Admin SDK creates the auth user server-side.
    const userRecord = await admin.auth().createUser({
      email,
      password,
      displayName: fullName,
    });

    // Admin SDK writes bypass Firestore security rules entirely, so this
    // write succeeds even if/when you lock the "admins" collection down
    // to deny all client-side writes (recommended -- see rules note).
    await db.collection('admins').doc(userRecord.uid).set({
      fullName,
      email,
      role: 'admin',
      createdAt: new Date().toISOString(),
    });

    res.status(200).json({ success: true });
  } catch (error) {
    if (error.code === 'auth/email-already-exists') {
      res.status(409).json({ error: 'This email is already registered.' });
    } else if (error.code === 'auth/invalid-email') {
      res.status(400).json({ error: 'Please enter a valid email address.' });
    } else {
      console.error('create-admin error:', error);
      res.status(500).json({ error: 'Something went wrong. Please try again.' });
    }
  }
};
