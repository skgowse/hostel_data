/**
 * Authentication Middleware & Flash Session Helpers
 * Mess & Hostel Management System - Node.js Edition
 */

function requireAdmin(req, res, next) {
    if (req.session && req.session.userId && req.session.role === 'admin') {
        return next();
    }
    req.session.flash = { type: 'warning', message: 'Please sign in to access the administrator portal.' };
    return res.redirect('/login');
}

function setFlash(req, type, message) {
    req.session.flash = { type, message };
}

function flashMiddleware(req, res, next) {
    res.locals.flash = req.session.flash || null;
    delete req.session.flash;

    res.locals.currentUser = req.session.user || null;
    res.locals.csrfToken = req.session.csrfToken || 'token_' + Math.random().toString(36).substring(2);
    req.session.csrfToken = res.locals.csrfToken;

    next();
}

module.exports = {
    requireAdmin,
    setFlash,
    flashMiddleware
};
