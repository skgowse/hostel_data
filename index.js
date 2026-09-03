const functions = require('firebase-functions');
const app = require('./server');

// Export Express app as a Firebase Cloud HTTPS Function
exports.app = functions.https.onRequest(app);
