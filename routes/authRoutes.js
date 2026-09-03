const express = require('express');
const router = express.Router();
const bcrypt = require('bcryptjs');
const db = require('../config/db');
const { setFlash } = require('../middleware/auth');
const { logAudit } = require('../utils/cycleEngine');

// GET /login
router.get('/login', (req, res) => {
    if (req.session && req.session.userId && req.session.role === 'admin') {
        return res.redirect('/admin/dashboard');
    }
    res.render('auth/login', {
        pageTitle: 'Administrator Sign In'
    });
});

// POST /login
router.post('/login', async (req, res) => {
    const mobile = (req.body.mobile || '').trim();
    const password = req.body.password || '';

    if (!mobile || !password) {
        setFlash(req, 'danger', 'Please enter both your Admin ID/Mobile and password.');
        return res.redirect('/login');
    }

    try {
        const [rows] = await db.query(
            "SELECT u.*, s.name as student_name FROM users u LEFT JOIN students s ON u.student_id = s.id WHERE (u.mobile = ? OR s.student_code = ?) AND u.role = 'admin' LIMIT 1",
            [mobile, mobile]
        );

        if (!rows || rows.length === 0) {
            setFlash(req, 'danger', 'Invalid mobile number or credentials.');
            return res.redirect('/login');
        }

        const user = rows[0];

        // Check password using bcrypt or plain match
        let isMatch = false;
        if (user.password_hash) {
            if (user.password_hash.startsWith('$2y$') || user.password_hash.startsWith('$2a$') || user.password_hash.startsWith('$2b$')) {
                const standardizedHash = user.password_hash.replace('$2y$', '$2a$');
                isMatch = await bcrypt.compare(password, standardizedHash);
            } else {
                isMatch = (password === user.password_hash);
            }
        }

        if (!isMatch) {
            setFlash(req, 'danger', 'Invalid mobile number or password.');
            return res.redirect('/login');
        }

        if (user.status !== 'active') {
            setFlash(req, 'danger', 'Your account is deactivated. Please contact the administrator.');
            return res.redirect('/login');
        }

        // Set session
        req.session.userId = user.id;
        req.session.role = user.role;
        req.session.user = {
            id: user.id,
            name: 'Administrator',
            mobile: user.mobile,
            role: user.role
        };

        await logAudit(db, user.id, 'ADMIN_LOGIN', 'USER', user.id, 'Admin logged in successfully');
        setFlash(req, 'success', 'Welcome back, Administrator!');
        return res.redirect('/admin/dashboard');
    } catch (err) {
        console.error('Login error:', err);
        setFlash(req, 'danger', 'Database error during sign in.');
        return res.redirect('/login');
    }
});

// GET & POST /logout
router.all('/logout', (req, res) => {
    req.session.destroy(() => {
        res.redirect('/login');
    });
});

module.exports = router;
