import { auth } from "../firebase-config.js";
import {
  onAuthStateChanged,
  signOut,
} from "https://www.gstatic.com/firebasejs/10.7.1/firebase-auth.js";

// Load user profile
async function loadProfile() {
  onAuthStateChanged(auth, async (user) => {
    if (!user) {
      // Not logged in, redirect to login
      window.location.href = "login.html";
      return;
    }

    try {
      // Get Firebase ID token
      const idToken = await user.getIdToken();

      // Fetch user profile from backend
      const res = await fetch("api/me.php", {
        headers: { Authorization: `Bearer ${idToken}` },
      });

      if (res.status === 401) {
        window.location.href = "login.html";
        return;
      }

      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Failed to load profile");

      const userData = data.user;

      // Update DOM with user data
      document.getElementById("user-name").textContent = userData.name || "N/A";
      document.getElementById("user-email").textContent =
        userData.email || user.email;
      document.getElementById("user-age").textContent = userData.age ?? "N/A";
      document.getElementById("user-joined").textContent = userData.joined_at
        ? new Date(userData.joined_at).toLocaleDateString()
        : "N/A";
    } catch (err) {
      console.error("Profile load error:", err);
      alert("Unable to load profile: " + err.message);
    }
  });
}

// Initialize on page load
document.addEventListener("DOMContentLoaded", loadProfile);

// Logout button handler
const logoutBtn = document.getElementById("logout-btn");
if (logoutBtn) {
  logoutBtn.addEventListener("click", async () => {
    try {
      await signOut(auth);
      window.location.href = "login.html";
    } catch (error) {
      console.error("Logout error:", error);
      alert("Logout failed: " + error.message);
    }
  });
}
