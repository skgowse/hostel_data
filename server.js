/**
 * Mess & Hostel Management System - Node.js Application Server
 * Built with Express.js, EJS, and MySQL2
 */

require('dotenv').config();
const express = require('express');
const session = require('express-session');
const path = require('path');
const cors = require('cors');

const authRoutes = require('./routes/authRoutes');
const adminRoutes = require('./routes/adminRoutes');
const apiRoutes = require('./routes/apiRoutes');
const { flashMiddleware } = require('./middleware/auth');

const app = express();
const PORT = parseInt(process.env.PORT || '8000', 10);
const HOST = process.env.HOST || '0.0.0.0';

// View Engine Setup (EJS)
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));

// Middleware
app.use(cors());
app.use(express.urlencoded({ extended: true }));
app.use(express.json());

// Session Configuration
app.use(session({
    secret: process.env.SESSION_SECRET || 'hostel_system_secure_secret_key_2026',
    resave: false,
    saveUninitialized: false,
    cookie: {
        maxAge: 24 * 60 * 60 * 1000, // 24 hours
        httpOnly: true,
        sameSite: 'lax'
    }
}));

// Flash Message & Session Locals
app.use(flashMiddleware);

// Static Assets
app.use('/assets', express.static(path.join(__dirname, 'assets')));

// Mount Application Routes
app.use('/', authRoutes);
app.use('/admin', adminRoutes);
app.use('/api', apiRoutes);

// Root Router: Redirect to dashboard if logged in, else login
app.get('/', (req, res) => {
    if (req.session && req.session.userId && req.session.role === 'admin') {
        return res.redirect('/admin/dashboard');
    }
    return res.redirect('/login');
});

// 404 Handler
app.use((req, res) => {
    res.status(404).render('layouts/header', {
        pageTitle: 'Page Not Found',
        currentUser: req.session ? req.session.user : null
    }, (err, headerHtml) => {
        const body = `
            <div class="container py-5 text-center">
                <div class="display-1 text-primary fw-bold mb-3">404</div>
                <h4 class="fw-bold mb-2">Page Not Found</h4>
                <p class="text-muted small mb-4">The requested page or route could not be found on this server.</p>
                <a href="/admin/dashboard" class="btn btn-primary fw-semibold"><i class="bi bi-speedometer2 me-1"></i> Return to Dashboard</a>
            </div>
        `;
        res.send(headerHtml + body + '</body></html>');
    });
});

// Start Server
if (require.main === module) {
    app.listen(PORT, HOST, () => {
        console.log(`\n=============================================================`);
        console.log(`🚀 Node.js Mess & Hostel Management System is live!`);
        console.log(`🌐 Localhost PC:    http://127.0.0.1:${PORT}`);
        console.log(`📱 Mobile Network:  http://192.168.29.40:${PORT}`);
        console.log(`=============================================================\n`);
    });
}

module.exports = app;
