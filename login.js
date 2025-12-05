import { auth } from "./firebase-config.js";
import {
  signInWithEmailAndPassword,
  createUserWithEmailAndPassword,
  updateProfile,
  signOut,
} from "https://www.gstatic.com/firebasejs/10.7.1/firebase-auth.js";

const loginTab = document.getElementById("login-tab");
const signupTab = document.getElementById("signup-tab");
const loginForm = document.getElementById("login-form");
const signupForm = document.getElementById("signup-form");
const switchToSignup = document.getElementById("switch-to-signup");
const switchToLogin = document.getElementById("switch-to-login");

function isStrongPassword(value) {
  // At least one uppercase, one lowercase, one digit, one symbol, and 8+ characters
  return /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/.test(value);
}

function showLogin() {
  loginForm.classList.add("active");
  signupForm.classList.remove("active");
  loginTab.classList.add("active");
  signupTab.classList.remove("active");
}

function showSignup() {
  signupForm.classList.add("active");
  loginForm.classList.remove("active");
  signupTab.classList.add("active");
  loginTab.classList.remove("active");
}

loginTab.addEventListener("click", showLogin);
signupTab.addEventListener("click", showSignup);
switchToSignup.addEventListener("click", (e) => {
  e.preventDefault();
  showSignup();
});
switchToLogin.addEventListener("click", (e) => {
  e.preventDefault();
  showLogin();
});

// ========================================
// LOGIN HANDLER (Firebase)
// ========================================
loginForm.addEventListener("submit", async (e) => {
  e.preventDefault();
  const emailEl = document.getElementById("login-email");
  const passwordEl = document.getElementById("login-password");
  const errorEl = document.getElementById("login-error");

  errorEl.style.display = "none";
  errorEl.textContent = "";

  const email = emailEl.value.trim();
  const password = passwordEl.value;

  // Client-side validation
  const clientErrors = [];
  if (!email) clientErrors.push("Email is required");
  else if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email))
    clientErrors.push("Enter a valid email");
  if (!password) clientErrors.push("Password is required");

  if (clientErrors.length) {
    errorEl.textContent = clientErrors.join(".  ");
    errorEl.style.display = "block";
    return;
  }

  try {
    // Sign in with Firebase
    const userCredential = await signInWithEmailAndPassword(
      auth,
      email,
      password
    );
    const user = userCredential.user;

    // Get Firebase ID token
    const idToken = await user.getIdToken();

    // Sync with backend (optional - ensures user exists in your DB)
    await fetch("api/sync-user.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${idToken}`,
      },
      body: JSON.stringify({
        uid: user.uid,
        email: user.email,
      }),
    });

    // Redirect to account page
    window.location.href = "account.html";
  } catch (error) {
    console.error("Login error:", error);

    // User-friendly error messages
    let errorMessage = "Login failed";
    if (
      error.code === "auth/invalid-credential" ||
      error.code === "auth/wrong-password"
    ) {
      errorMessage = "Invalid email or password";
    } else if (error.code === "auth/user-not-found") {
      errorMessage = "No account found with this email";
    } else if (error.code === "auth/too-many-requests") {
      errorMessage = "Too many failed attempts. Try again later.";
    } else if (error.message) {
      errorMessage = error.message;
    }

    errorEl.textContent = errorMessage;
    errorEl.style.display = "block";
  }
});

// ========================================
// SIGNUP HANDLER (Firebase)
// ========================================
signupForm.addEventListener("submit", async (e) => {
  e.preventDefault();
  const nameEl = document.getElementById("fullname");
  const ageEl = document.getElementById("age");
  const emailEl = document.getElementById("signup-email");
  const phoneEl = document.getElementById("signup-phone");
  const locationEl = document.getElementById("signup-location");
  const passwordEl = document.getElementById("signup-password");
  const confirmEl = document.getElementById("confirm-password");
  const errorEl = document.getElementById("signup-error");

  errorEl.style.display = "none";
  errorEl.textContent = "";

  const name = nameEl.value.trim();
  const age = ageEl.value.trim();
  const email = emailEl.value.trim();
  const phone = phoneEl.value.trim();
  const location = locationEl.value.trim();
  const password = passwordEl.value;
  const confirm = confirmEl.value;

  // Client-side validation
  const clientErrors = [];
  nameEl.setCustomValidity("");
  if (!email) clientErrors.push("Email is required");
  else if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email))
    clientErrors.push("Enter a valid email");
  if (!password) clientErrors.push("Password is required");
  else if (!isStrongPassword(password))
    clientErrors.push(
      "Password must be 8+ chars with upper, lower, number, and symbol"
    );
  if (password !== confirm) clientErrors.push("Passwords do not match");
  if (!age || isNaN(age) || parseInt(age) < 13)
    clientErrors.push("Age must be 13 or older");
  if (!phone)
    clientErrors.push("Phone number is required with country code (e.g., +1 ...)");
  else if (!/^\+[0-9()\s-]{7,}$/.test(phone))
    clientErrors.push("Enter a valid phone number with country code (e.g., +1 555 123 4567)");
  if (!location)
    clientErrors.push("Location is required (e.g., City, Country)");

  if (clientErrors.length) {
    errorEl.textContent = clientErrors.join(". ");
    errorEl.style.display = "block";
    return;
  }

  try {
    const normalizedName = name.replace(/\s+/g, " ");

    // Create user in Firebase
    const userCredential = await createUserWithEmailAndPassword(
      auth,
      email,
      password
    );
    const user = userCredential.user;

    if (normalizedName) {
      await updateProfile(user, { displayName: normalizedName });
    }

    // Get Firebase ID token
    const idToken = await user.getIdToken();

    // Send additional user data to backend
    const syncRes = await fetch("api/sync-user.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${idToken}`,
      },
      body: JSON.stringify({
        uid: user.uid,
        email: user.email,
        name: normalizedName || null,
        age: parseInt(age),
        phone,
        location,
      }),
    });

    if (!syncRes.ok) {
      const data = await syncRes.json().catch(() => ({}));
      const message = data.error || "Could not finish account setup";
      await signOut(auth);
      throw new Error(message);
    }

    // Redirect to account page
    window.location.href = "account.html";
  } catch (error) {
    console.error("Signup error:", error);

    // User-friendly error messages
    let errorMessage = "Registration failed";
    if (error.code === "auth/email-already-in-use") {
      errorMessage = "Email already registered";
    } else if (error.code === "auth/invalid-email") {
      errorMessage = "Invalid email address";
    } else if (error.code === "auth/weak-password") {
      errorMessage = "Password is too weak";
    } else if (error.message) {
      errorMessage = error.message;
    }

    errorEl.textContent = errorMessage;
    errorEl.style.display = "block";
  }
});
