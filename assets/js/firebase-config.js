/**
 * Firebase Integration Configuration
 * Project: hostel-star
 */

// Web app's Firebase configuration
const firebaseConfig = {
  apiKey: "AIzaSyCdqVjAr7fk0GTEeBuQuWdUtgbvEZaCW0A",
  authDomain: "hostel-star.firebaseapp.com",
  projectId: "hostel-star",
  storageBucket: "hostel-star.firebasestorage.app",
  messagingSenderId: "463647095026",
  appId: "1:463647095026:web:500c2c393764bcca4e386b"
};

// Initialize Firebase using Global Compat SDK if loaded
if (typeof firebase !== 'undefined') {
    if (!firebase.apps.length) {
        firebase.initializeApp(firebaseConfig);
        console.log("Firebase initialized successfully for hostel-star (Compat Mode).");
    }
    window.firebaseApp = firebase.app();
}

window.firebaseConfig = firebaseConfig;
