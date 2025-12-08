import { auth } from "../firebase-config.js";
import {
  onAuthStateChanged,
  signOut,
} from "https://www.gstatic.com/firebasejs/10.7.1/firebase-auth.js";

let favorites = [];
let registrations = [];

// Load user profile + favorites
async function loadProfile() {
  onAuthStateChanged(auth, async (user) => {
    if (!user) {
      window.location.href = "login.html";
      return;
    }

    await Promise.all([
      loadUserDetails(user),
      loadFavorites(user),
      loadRegistrations(user),
    ]);
  });
}

async function loadUserDetails(user) {
  try {
    const res = await fetch("/web-proj/api/me.php", {
      headers: { "X-Firebase-UID": user.uid },
    });

    if (res.status === 401) {
      window.location.href = "login.html";
      return;
    }

    const data = await res.json();
    if (!res.ok) throw new Error(data.error || "Failed to load profile");

    const userData = data.user;
    const joinedDate = userData.joined_at
      ? new Date(userData.joined_at).toLocaleDateString()
      : "N/A";

    document.getElementById("user-name").textContent = userData.name || "N/A";
    document.getElementById("user-email").textContent =
      userData.email || user.email;
    document.getElementById("user-joined").textContent = joinedDate;
    document.getElementById("user-role").textContent =
      userData.role || "User";

    // Overview tiles
    document.getElementById("account-status").textContent = "Active";
    document.getElementById("member-since").textContent = joinedDate;
  } catch (err) {
    console.error("Profile load error:", err);
    alert("Unable to load profile: " + err.message);
  }
}

async function loadFavorites(user) {
  const list = document.getElementById("favorites-list");
  try {
    const res = await fetch("/web-proj/api/favorites.php", {
      headers: { "X-Firebase-UID": user.uid },
    });

    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error(data.error || "Failed to load favorites");
    }

    favorites = data.favorites || [];
    renderFavorites();
  } catch (err) {
    console.error("Favorites load error:", err);
    list.innerHTML = `<p class="placeholder">${err.message}</p>`;
    document.getElementById("favorites-count").textContent = "0";
    document.getElementById("favorites-helper").textContent =
      "We couldn't fetch your favorites just now.";
  }
}

function renderFavorites() {
  const list = document.getElementById("favorites-list");
  const count = document.getElementById("favorites-count");
  const helper = document.getElementById("favorites-helper");

  if (!favorites || favorites.length === 0) {
    list.innerHTML =
      '<p class="placeholder">You haven\'t saved any favorites yet.</p>';
    count.textContent = "0";
    helper.textContent = "Save events to quickly find them later.";
    return;
  }

  count.textContent = favorites.length.toString();
  helper.textContent = `Last saved on ${new Date(
    favorites[0].favorited_at
  ).toLocaleDateString()}`;

  const recent = favorites.slice(0, 3);
  list.innerHTML = "";

  recent.forEach((fav) => {
    const item = document.createElement("div");
    item.className = "item-row";
    item.innerHTML = `
      <div>
        <p class="item-title">${fav.title || fav.name || "Untitled Event"}</p>
        <p class="item-meta">${fav.location || "Location TBA"} • ${
      fav.date || "Date TBA"
    }</p>
      </div>
      <button class="details-link" data-id="${fav.id}">View</button>
    `;

    item
      .querySelector(".details-link")
      .addEventListener("click", () =>
        (window.location.href = `/web-proj/event.html?id=${fav.id}`)
      );

    list.appendChild(item);
  });
}

async function loadRegistrations(user) {
  const container = document.getElementById("registered-events-container");
  try {
    const res = await fetch("/web-proj/api/registrations.php", {
      headers: { "X-Firebase-UID": user.uid },
    });
    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error(data.error || "Failed to load registrations");
    }

    registrations = data.registrations || [];
    document.getElementById("registrations-count").textContent =
      data.counts?.total ?? registrations.length ?? 0;
    document.getElementById("registrations-helper").textContent =
      data.counts?.upcoming
        ? `${data.counts.upcoming} upcoming registrations`
        : "Sign up for events to see them here.";

    renderRegisteredEvents(data.counts);
  } catch (err) {
    console.error("Registrations load error:", err);
    container.innerHTML = `<p class="placeholder">${err.message}</p>`;
    document.getElementById("registrations-count").textContent = "0";
    document.getElementById("registrations-helper").textContent =
      "Unable to fetch registrations right now.";
  }
}

function renderRegisteredEvents(counts = {}) {
  const container = document.getElementById("registered-events-container");
  const upcomingEl = document.getElementById("registered-upcoming");
  const pastEl = document.getElementById("registered-past");
  const cancelledEl = document.getElementById("registered-cancelled");

  if (!registrations || registrations.length === 0) {
    container.innerHTML =
      '<p class="placeholder">No registrations yet. Browse events to register.</p>';
    upcomingEl.textContent = "0";
    pastEl.textContent = "0";
    cancelledEl.textContent = "0";
    return;
  }

  const upcomingCount = counts.upcoming ??
    registrations.filter((r) => r.status === "registered" && r.is_upcoming).length;
  const pastCount = counts.past ?? registrations.filter((r) => !r.is_upcoming).length;
  const cancelledCount =
    counts.cancelled ?? registrations.filter((r) => r.status === "cancelled").length;

  upcomingEl.textContent = upcomingCount;
  pastEl.textContent = pastCount;
  cancelledEl.textContent = cancelledCount;

  container.innerHTML = "";

  registrations.forEach((reg) => {
    const card = document.createElement("div");
    card.className = "registered-event-card";

    const left = document.createElement("div");
    const title = document.createElement("h4");
    title.textContent = reg.event_name || "Event";
    left.appendChild(title);

    const meta = document.createElement("p");
    meta.className = "event-details";
    meta.textContent = `${reg.date || "Date TBA"} • ${reg.time || "Time TBA"} • ${
      reg.location || "Location TBA"
    }`;
    left.appendChild(meta);

    const badge = document.createElement("span");
    badge.className =
      "registration-badge" + (reg.status === "cancelled" ? " cancelled" : "");
    badge.textContent = reg.status === "cancelled" ? "Cancelled" : "Registered";
    left.appendChild(badge);

    const right = document.createElement("div");
    right.className = "registration-actions";

    const viewBtn = document.createElement("button");
    viewBtn.className = "details-btn";
    viewBtn.textContent = "View";
    viewBtn.addEventListener(
      "click",
      () => (window.location.href = `/web-proj/event.html?id=${reg.event_id}`)
    );
    right.appendChild(viewBtn);

    if (reg.can_cancel) {
      const cancelBtn = document.createElement("button");
      cancelBtn.className = "cancel-btn";
      cancelBtn.textContent = "Cancel";
      cancelBtn.addEventListener("click", () => cancelRegistration(reg.event_id));
      right.appendChild(cancelBtn);
    }

    card.appendChild(left);
    card.appendChild(right);
    container.appendChild(card);
  });
}

async function cancelRegistration(eventId) {
  if (!confirm("Cancel this registration?")) return;
  try {
    const user = auth.currentUser;
    const res = await fetch(
      `/web-proj/api/registrations.php?event_id=${encodeURIComponent(eventId)}`,
      {
        method: "DELETE",
        headers: { "X-Firebase-UID": user.uid },
      }
    );
    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error(data.error || "Failed to cancel registration");
    }
    await loadRegistrations(user);
  } catch (err) {
    console.error("Cancel registration error:", err);
    alert(err.message);
  }
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

// Stub actions
document
  .getElementById("edit-profile-btn")
  ?.addEventListener("click", () =>
    alert("Profile editing will be available in the next update.")
  );

document
  .getElementById("change-password-btn")
  ?.addEventListener("click", () =>
    alert("Password change flow is coming soon.")
  );

document
  .getElementById("notification-settings-btn")
  ?.addEventListener("click", () =>
    alert("Notification preferences will be configurable shortly.")
  );

document
  .getElementById("delete-account-btn")
  ?.addEventListener("click", () =>
    alert("Account deletion requires admin review and will be added later.")
  );
