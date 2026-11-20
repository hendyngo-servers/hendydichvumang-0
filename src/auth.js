import { createHmac, randomBytes, scryptSync, timingSafeEqual } from 'node:crypto';
import { query } from './db.js';

const SESSION_COOKIE = 'hendy_sid';
const CSRF_COOKIE = 'hendy_csrf';

function digest(value, secret) {
  return createHmac('sha256', secret).update(value).digest('hex');
}

function constantTimeTextEqual(left, right) {
  const a = Buffer.from(String(left));
  const b = Buffer.from(String(right));
  return a.length === b.length && timingSafeEqual(a, b);
}

function readCookie(header, name) {
  for (const part of String(header || '').split(';')) {
    const index = part.indexOf('=');
    if (index < 0) continue;
    if (part.slice(0, index).trim() === name) {
      try { return decodeURIComponent(part.slice(index + 1).trim()); } catch { return ''; }
    }
  }
  return '';
}

function passwordMatches(candidate, config) {
  const expected = scryptSync(config.ADMIN_PASSWORD, config.ADMIN_PASSWORD_SALT, 64);
  const actual = scryptSync(candidate, config.ADMIN_PASSWORD_SALT, 64);
  return timingSafeEqual(expected, actual);
}

function cookieOptions(config, httpOnly) {
  return {
    httpOnly,
    secure: config.cookieSecure,
    sameSite: 'strict',
    path: '/',
    maxAge: config.SESSION_TTL_HOURS * 60 * 60 * 1000,
  };
}

export async function login(req, res, config) {
  const { username, password } = req.validatedBody || req.body;
  const userOk = String(username || '') === config.ADMIN_USERNAME;
  const passOk = passwordMatches(String(password || ''), config);
  if (!userOk || !passOk) {
    return res.status(401).json({ error: 'Tên đăng nhập hoặc mật khẩu không đúng.' });
  }

  await query('DELETE FROM admin_sessions WHERE expires_at <= NOW()');
  const sessionToken = randomBytes(32).toString('base64url');
  const csrfToken = randomBytes(32).toString('base64url');
  const expiresAt = new Date(Date.now() + config.SESSION_TTL_HOURS * 60 * 60 * 1000);
  await query(
    `INSERT INTO admin_sessions(token_hash, csrf_hash, username, expires_at)
     VALUES ($1, $2, $3, $4)`,
    [digest(sessionToken, config.SESSION_SECRET), digest(csrfToken, config.SESSION_SECRET), config.ADMIN_USERNAME, expiresAt],
  );
  res.cookie(SESSION_COOKIE, sessionToken, cookieOptions(config, true));
  res.cookie(CSRF_COOKIE, csrfToken, cookieOptions(config, false));
  return res.json({ authenticated: true, username: config.ADMIN_USERNAME, csrfToken });
}

export async function requireAuth(req, res, next, config) {
  try {
    const token = readCookie(req.headers.cookie, SESSION_COOKIE);
    if (!token || token.length > 128) return res.status(401).json({ error: 'Vui lòng đăng nhập.' });
    const result = await query(
      `SELECT username, csrf_hash FROM admin_sessions
       WHERE token_hash = $1 AND expires_at > NOW()`,
      [digest(token, config.SESSION_SECRET)],
    );
    if (!result.rowCount) return res.status(401).json({ error: 'Phiên đăng nhập đã hết hạn.' });
    req.admin = { username: result.rows[0].username, csrfHash: result.rows[0].csrf_hash };
    req.sessionTokenHash = digest(token, config.SESSION_SECRET);
    req.sessionSecret = config.SESSION_SECRET;
    req.csrfCookie = readCookie(req.headers.cookie, CSRF_COOKIE);
    return next();
  } catch (error) {
    return next(error);
  }
}

export function requireCsrf(req, res, next) {
  const csrfHeader = String(req.get('x-csrf-token') || '');
  const csrfCookie = String(req.csrfCookie || readCookie(req.headers.cookie, CSRF_COOKIE));
  if (!req.admin || !csrfCookie || !csrfHeader || !constantTimeTextEqual(csrfHeader, csrfCookie) || digest(csrfHeader, req.sessionSecret) !== req.admin.csrfHash) {
    return res.status(403).json({ error: 'CSRF token không hợp lệ. Hãy tải lại trang và thử lại.' });
  }
  return next();
}

export async function logout(req, res, config) {
  await query('DELETE FROM admin_sessions WHERE token_hash = $1', [req.sessionTokenHash]);
  res.clearCookie(SESSION_COOKIE, { ...cookieOptions(config, true), maxAge: undefined });
  res.clearCookie(CSRF_COOKIE, { ...cookieOptions(config, false), maxAge: undefined });
  return res.json({ authenticated: false });
}

export async function currentSession(req, res) {
  const csrfToken = req.csrfCookie || '';
  if (!csrfToken || digest(csrfToken, req.sessionSecret) !== req.admin.csrfHash) {
    return res.status(403).json({ error: 'Phiên bảo mật không hợp lệ. Vui lòng đăng nhập lại.' });
  }
  await query('UPDATE admin_sessions SET last_seen_at = NOW() WHERE token_hash = $1', [req.sessionTokenHash]);
  return res.json({ authenticated: true, username: req.admin.username, csrfToken });
}

export const authCookies = Object.freeze({ SESSION_COOKIE, CSRF_COOKIE });
