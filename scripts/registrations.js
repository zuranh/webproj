import { auth } from "../firebase-config.js";
import { onAuthStateChanged } from "https://www.gstatic.com/firebasejs/10.7.1/firebase-auth.js";

const statusBanner = document.getElementById("status");
const grid = document.getElementById("registrations-grid");
let currentUser = null;

function setStatus(message, isError = false) {
  if (!statusBanner) return;
  statusBanner.textContent = message;
  statusBanner.classList.toggle("error", isError);
  statusBanner.style.display = message ? "block" : "none";
}

function renderEmpty() {
  if (!grid) return;
  grid.innerHTML = `<div class="favorite-card registration-card">
      <div class="favorite-info">
        <h3>No registrations yet</h3>
        <p class="event-details">Find an event you like and tap "Register Now" to claim your spot.</p>
        <div class="favorite-actions">
          <a class="details-btn" href="index.html">Browse Events</a>
        </div>
      </div>
    </div>`;
}

function renderRegistrations(registrations = []) {
  if (!grid) return;
  grid.innerHTML = "";

  if (!registrations.length) {
    renderEmpty();
    return;
  }

  registrations.forEach((reg) => {
    const card = document.createElement("div");
    card.className = "favorite-card registration-card";

    const imageUrl =
      reg.image_url && reg.image_url.trim()
        ? reg.image_url
        : "https://via.placeholder.com/600x300?text=Event";

    const price =
      reg.price && parseFloat(reg.price) > 0
        ? `$${parseFloat(reg.price).toFixed(2)}`
        : "Free";

    const dateLabel = reg.date ? new Date(reg.date).toLocaleDateString() : "Date TBA";
    const timeLabel = reg.time || "Time TBA";

    card.innerHTML = `
      <img class="favorite-image" src="${imageUrl}" alt="${
      reg.name || "Event"
    }" />
      <div class="favorite-info">
        <h3>${reg.name || "Event"}</h3>
        <p class="event-details">${reg.location || "Location TBA"} • ${dateLabel} • ${timeLabel}</p>
        <p class="event-details">${price}</p>
        <div class="favorite-actions">
          <span class="badge">${reg.event_status === "archived" ? "Archived" : "Registered"}</span>
          <div class="action-buttons">
            <a class="details-btn" href="event.html?id=${reg.event_id}">View Event</a>
            <button data-event-id="${reg.event_id}" class="cancel-btn">Cancel</button>
          </div>
        </div>
      </div>
    `;

    grid.appendChild(card);
  });

  grid.querySelectorAll(".cancel-btn").forEach((btn) => {
    btn.addEventListener("click", async (e) => {
      const eventId = parseInt(e.currentTarget.dataset.eventId, 10);
      await cancelRegistration(eventId);
    });
  });
}

async function loadRegistrations(user) {
  setStatus("Loading your registrations...");
  try {
    const res = await fetch("/web-proj/api/registrations.php", {
      headers: { "X-Firebase-UID": user.uid },
    });

    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error(data.error || "Unable to load registrations");
    }

    renderRegistrations(data.registrations || []);
    setStatus("Showing your current registrations.");
  } catch (error) {
    console.error("Failed to load registrations:", error);
    setStatus(error.message || "Unable to load registrations", true);
    renderEmpty();
  }
}

async function cancelRegistration(eventId) {
  if (!currentUser) return;

  const confirmed = confirm("Cancel this registration?");
  if (!confirmed) return;

  setStatus("Canceling registration...");
  try {
    const res = await fetch(
      `/web-proj/api/registrations.php?event_id=${encodeURIComponent(eventId)}`,
      {
        method: "DELETE",
        headers: { "X-Firebase-UID": currentUser.uid },
      }
    );

    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error(data.error || "Unable to cancel registration");
    }

    await loadRegistrations(currentUser);
    setStatus("Registration canceled.");
  } catch (error) {
    console.error("Failed to cancel registration:", error);
    setStatus(error.message || "Unable to cancel registration", true);
  }
}

onAuthStateChanged(auth, async (user) => {
  if (!user) {
    window.location.href = "login.html";
    return;
  }

  currentUser = user;
  await loadRegistrations(user);
});
