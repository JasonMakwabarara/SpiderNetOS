'use strict';
/* Rejects anyone without a live session, and anyone who still owes a password
   change, before a route can touch content. */

function createRequireAuth({ sessions, store }) {
  return function requireAuth(req, res, next) {
    const raw = req.cookies && req.cookies[sessions.cookieName];
    const row = sessions.read(raw);
    if (!row) {
      res.clearCookie(sessions.cookieName, sessions.cookieOptions());
      return res.status(401).json({ error: 'not_signed_in', message: 'Please sign in again.' });
    }

    const user = store.findUserById(row.userId);
    if (!user || user.disabled) {
      sessions.destroy(row.id);
      res.clearCookie(sessions.cookieName, sessions.cookieOptions());
      return res.status(401).json({ error: 'not_signed_in', message: 'Please sign in again.' });
    }

    req.session = row;
    req.user = user;

    /* While a password change is outstanding, only the routes that let someone
       complete it are reachable.
       Compare against originalUrl, not req.path: this middleware runs inside
       mounted routers, where req.path is relative to the mount point, so
       "/api/auth/password" arrives here as "/password". Matching on req.path
       would block the very route someone needs to get unstuck. */
    const allowed = ['/api/auth/me', '/api/auth/password', '/api/auth/logout', '/api/csrf'];
    const fullPath = String(req.originalUrl || req.url).split('?')[0].replace(/\/+$/, '') || '/';
    if (user.mustChangePassword && !allowed.includes(fullPath)) {
      return res.status(403).json({
        error: 'password_change_required',
        message: 'Choose a new password before making changes.'
      });
    }
    next();
  };
}

module.exports = { createRequireAuth };
