import { auth } from "../firebase-config.js";
import {
  onAuthStateChanged,
  signOut,
} from "https://www.gstatic.com/firebasejs/10.7.1/firebase-auth.js";

let currentUser = null;

async function requireAuth() {
  return new Promise((resolve, reject) => {
    onAuthStateChanged(
      auth,
      async (user) => {
        if (!user) {
          window.location.href = "login.html";
          return;
        }
        currentUser = user;
        resolve(user);
      },
      reject
    );
  });
}

// Load user profile
async function loadProfile() {
  onAuthStateChanged(auth, async (user) => {
    if (!user) {
      // Not logged in, redirect to login
      window.location.href = "login.html";
      return;
    }

    currentUser = user;

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

      const adminLink = document.getElementById("admin-link");
      if (adminLink && (userData.role === "admin" || userData.role === "owner")) {
        adminLink.style.display = "list-item";
      }
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

// Navigate to edit profile
const editProfileBtn = document.getElementById("edit-profile-btn");
if (editProfileBtn) {
  editProfileBtn.addEventListener("click", () => {
    window.location.href = "edit-profile.html";
  });
}

// Navigate to change password
const changePasswordBtn = document.getElementById("change-password-btn");
if (changePasswordBtn) {
  changePasswordBtn.addEventListener("click", () => {
    window.location.href = "change-password.html";
  });
}

// Delete account handler
const deleteAccountBtn = document.getElementById("delete-account-btn");
if (deleteAccountBtn) {
  deleteAccountBtn.addEventListener("click", async () => {
    if (!confirm("Are you sure you want to delete your account?")) return;

    try {
      const user = currentUser || (await requireAuth());
      const idToken = await user.getIdToken();

      const res = await fetch("api/delete-account.php", {
        method: "DELETE",
        headers: { Authorization: `Bearer ${idToken}` },
      });

      const data = await res.json().catch(() => ({}));

      if (!res.ok || !data.success) {
        throw new Error(data.error || "Delete failed");
      }

      await signOut(auth);
      alert("Your account was deleted.");
      window.location.href = "login.html";
    } catch (err) {
      console.error("Delete account error:", err);
      alert("Unable to delete account: " + err.message);
    }
  });
}
