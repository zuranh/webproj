import { auth } from "../firebase-config.js";
import {
  onAuthStateChanged,
  deleteUser,
  signOut,
} from "https://www.gstatic.com/firebasejs/10.7.1/firebase-auth.js";

const editProfileBtn = document.getElementById("edit-profile-btn");
const changePasswordBtn = document.getElementById("change-password-btn");
const deleteAccountBtn = document.getElementById("delete-account-btn");
let currentUser = null;

function isFullName(value) {
  if (!value) return false;
  const trimmed = value.trim();
  const parts = trimmed.split(/\s+/).filter(Boolean);
  if (parts.length < 2) return false;

  const partOk = parts.every((part) => /^[A-Za-z][A-Za-z'\-]{1,}[A-Za-z]$/.test(part));
  if (!partOk) return false;

  return trimmed.replace(/\s+/g, " ").length >= 5;
}

// Ensure a database record exists for the signed-in Firebase user.
async function syncUserRecord(user) {
  const idToken = await user.getIdToken();

  let nameToSend = user.displayName?.trim() || "";

  if (!isFullName(nameToSend)) {
    throw new Error(
      "A valid full name is required. Please sign out and sign up again with your full name."
    );
  }

  nameToSend = nameToSend.replace(/\s+/g, " ");

  const res = await fetch("api/sync-user.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${idToken}`,
    },
    body: JSON.stringify({
      uid: user.uid,
      email: user.email,
      name: nameToSend,
    }),
  });

  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    throw new Error(data.error || "Failed to sync account");
  }
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
      let res = await fetch("api/me.php", {
        headers: { Authorization: `Bearer ${idToken}` },
      });

      if (res.status === 401) {
        window.location.href = "login.html";
        return;
      }

      // If the record is missing (e.g., after deletion), re-sync and retry once
      if (res.status === 404) {
        await syncUserRecord(user);
        res = await fetch("api/me.php", {
          headers: { Authorization: `Bearer ${idToken}` },
        });
      }

      const data = await res.json();
      if (!res.ok) throw new Error(data.error || "Failed to load profile");

      const userData = data.user;

      // Update DOM with user data
      document.getElementById("user-name").textContent = userData.name || "N/A";
      document.getElementById("user-email").textContent =
        userData.email || user.email;
      document.getElementById("user-age").textContent = userData.age ?? "N/A";
      document.getElementById("user-location").textContent =
        userData.location || "N/A";
      document.getElementById("user-phone").textContent = userData.phone || "N/A";
      document.getElementById("user-bio").textContent = userData.bio || "Add a short bio";
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

// Edit profile navigation
if (editProfileBtn) {
  editProfileBtn.addEventListener("click", () => {
    window.location.href = "edit-profile.html";
  });
}

// Change password navigation
if (changePasswordBtn) {
  changePasswordBtn.addEventListener("click", () => {
    window.location.href = "change-password.html";
  });
}

// Delete account handler
if (deleteAccountBtn) {
  deleteAccountBtn.addEventListener("click", async () => {
    if (!currentUser) {
      window.location.href = "login.html";
      return;
    }

    const confirmed = confirm(
      "Are you sure you want to permanently delete your account? This cannot be undone."
    );

    if (!confirmed) return;

    try {
      const idToken = await currentUser.getIdToken();
      const res = await fetch("api/delete-account.php", {
        method: "DELETE",
        headers: {
          Authorization: `Bearer ${idToken}`,
        },
      });

      const data = await res.json();
      if (!res.ok || !data.success) {
        throw new Error(data.error || "Failed to delete account");
      }

      try {
        await deleteUser(currentUser);
      } catch (firebaseErr) {
        // If the session is too old, require a fresh login before deletion
        if (firebaseErr?.code === "auth/requires-recent-login") {
          alert(
            "Please sign in again to confirm account deletion, then try deleting once more."
          );
        } else {
          console.warn("Firebase account deletion skipped:", firebaseErr);
        }
      }

      await signOut(auth);
      alert("Your account has been deleted.");
      window.location.href = "login.html";
    } catch (err) {
      console.error("Delete account error:", err);
      alert(err.message || "Unable to delete account.");
    }
  });
}
