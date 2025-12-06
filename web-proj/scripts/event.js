import { auth } from "/web-proj/firebase-config.js";
import { onAuthStateChanged as fbOnAuthStateChanged } from "https://www.gstatic.com/firebasejs/10.7.1/firebase-auth.js";

// Safety: read event id from URL and bail early if missing
const urlParams = new URLSearchParams(window.location.search);
const eventId = urlParams.get("id");

if (!eventId) {
  document.addEventListener("DOMContentLoaded", () => {
    showError("No event ID provided");
  });
  throw new Error("Missing event id");
}

let currentUser = null;
let currentEvent = null;
let isFavorited = false;
let registrationStatus = "unknown";

window.addEventListener("DOMContentLoaded", async () => {
  fbOnAuthStateChanged(auth, async (firebaseUser) => {
    if (firebaseUser) {
      await loadCurrentUser(firebaseUser);
      updateUIForLoggedIn();
      await checkIfFavorited();
      await refreshRegistrationState();
    }
  });

  await loadEvent();
});

async function loadCurrentUser(firebaseUser) {
  try {
    const response = await fetch("/web-proj/api/me.php", {
      headers: { "X-Firebase-UID": firebaseUser.uid },
    });
    const data = await response.json();
    if (data.user) {
      currentUser = data.user;
    }
  } catch (error) {
    console.error("Failed to load user:", error);
  }
}

function updateUIForLoggedIn() {
  if (currentUser && ["admin", "owner"].includes(currentUser.role)) {
    document.getElementById("admin-link").style.display = "block";
  }
  const regLink = document.getElementById("registrations-link");
  if (regLink) {
    regLink.style.display = "block";
  }
}

async function loadEvent() {
  try {
    const response = await fetch(`/web-proj/api/events.php?id=${encodeURIComponent(eventId)}`);
    const data = await response.json();

    if (data.success && data.event) {
      currentEvent = data.event;
      renderEvent();
    } else {
      showError("Event not found");
    }
  } catch (error) {
    console.error("Failed to load event:", error);
    showError("Failed to load event details");
  }
}

function renderEvent() {
  const event = currentEvent;

  document.getElementById("loading-container").style.display = "none";
  document.getElementById("event-content").style.display = "block";

  const imageUrl =
    event.image_url && typeof event.image_url === "string" && event.image_url.trim()
      ? event.image_url
      : `https://via.placeholder.com/1200x400?text=Event:${encodeURIComponent(event.id || eventId)}`;
  document.getElementById("event-main-image").src = imageUrl;
  document.getElementById("event-title").textContent = event.title || event.name || "Untitled Event";
  document.getElementById("event-subtitle").textContent = `${
    event.location || "Location TBA"
  } • ${event.date || "Date TBA"}${event.time ? " • " + event.time : ""}`;

  const genresContainer = document.getElementById("event-genres");
  genresContainer.innerHTML = "";
  if (Array.isArray(event.genres) && event.genres.length) {
    event.genres.forEach((gName) => {
      const badge = document.createElement("span");
      badge.className = "genre-badge";
      badge.textContent = gName;
      genresContainer.appendChild(badge);
    });
  } else if (event.genre_name) {
    const badge = document.createElement("span");
    badge.className = "genre-badge";
    badge.textContent = event.genre_name;
    genresContainer.appendChild(badge);
  }

  document.getElementById("event-description").textContent = event.description || "No description provided.";
  document.getElementById("info-date").textContent = event.date || "TBA";
  document.getElementById("info-time").textContent = event.time || "TBA";
  document.getElementById("info-location").textContent = event.location || "TBA";
  const price =
    event.price && parseFloat(event.price) > 0
      ? `$${parseFloat(event.price).toFixed(2)}`
      : "FREE";
  document.getElementById("info-price").textContent = price;
  document.getElementById("info-age").textContent = event.age_restriction
    ? `${event.age_restriction}+`
    : "All ages";
  document.getElementById("info-status").textContent = event.status || "Published";

  if (event.creator_name) {
    const createdAt = event.created_at ? new Date(event.created_at).toLocaleDateString() : "-";
    document.getElementById("creator-info").style.display = "block";
    document.getElementById("creator-name").textContent = event.creator_name;
    document.getElementById("created-date").textContent = createdAt;
  }

  document.getElementById("ticket-price").textContent = price;
  document.getElementById("ticket-info").textContent =
    event.price && parseFloat(event.price) > 0
      ? "Purchase your tickets today!"
      : "Free entry - Register to attend!";

  resetButton("register-btn").addEventListener("click", handleRegisterClick);

  const favoriteBtn = document.getElementById("favorite-btn");
  favoriteBtn.replaceWith(favoriteBtn.cloneNode(true));
  document.getElementById("favorite-btn").addEventListener("click", toggleFavorite);

  updateRegisterButton();

  document.title = `${event.title || "Event"} | Event Finder`;
}

function resetButton(id) {
  const btn = document.getElementById(id);
  const clone = btn.cloneNode(true);
  btn.replaceWith(clone);
  return document.getElementById(id);
}

async function checkIfFavorited() {
  if (!currentUser || !eventId) return;

  try {
    const firebaseUser = auth.currentUser;
    const response = await fetch("/web-proj/api/favorites.php", {
      headers: { "X-Firebase-UID": firebaseUser.uid },
    });
    const data = await response.json();

    if (data.success) {
      const favoriteIds = data.favorites.map((f) => f.id);
      isFavorited = favoriteIds.includes(parseInt(eventId));
      updateFavoriteButton();
    }
  } catch (error) {
    console.error("Failed to check favorites:", error);
  }
}

function updateFavoriteButton() {
  const btn = document.getElementById("favorite-btn");
  if (!btn) return;
  if (isFavorited) {
    btn.textContent = "❤️ Favorited";
    btn.classList.add("favorited");
  } else {
    btn.textContent = "♡ Add to Favorites";
    btn.classList.remove("favorited");
  }
}

async function toggleFavorite() {
  if (!currentUser) {
    alert("Please log in to add favorites");
    window.location.href = "/web-proj/login.html";
    return;
  }

  const firebaseUser = auth.currentUser;

  try {
    const response = await fetch(
      "/web-proj/api/favorites.php" + (isFavorited ? `?event_id=${encodeURIComponent(eventId)}` : ""),
      {
        method: isFavorited ? "DELETE" : "POST",
        headers: {
          "Content-Type": "application/json",
          "X-Firebase-UID": firebaseUser.uid,
        },
        body: isFavorited ? null : JSON.stringify({ event_id: parseInt(eventId) }),
      }
    );

    const data = await response.json();

    if (data.success) {
      isFavorited = !isFavorited;
      updateFavoriteButton();
    } else {
      alert("Failed to update favorite: " + (data.error || "Unknown error"));
    }
  } catch (error) {
    console.error("Failed to toggle favorite:", error);
    alert("Failed to update favorite");
  }
}

async function refreshRegistrationState() {
  if (!currentUser || !eventId) return;

  try {
    const response = await fetch(`/web-proj/api/registrations.php?event_id=${encodeURIComponent(eventId)}` , {
      headers: { "X-Firebase-UID": auth.currentUser.uid },
    });

    const data = await response.json();
    if (!response.ok || !data.success) {
      throw new Error(data.error || "Unable to load registration state");
    }

    registrationStatus = data.status || "not_registered";
    updateRegisterButton(data.capacity);
  } catch (error) {
    console.error("Failed to check registration:", error);
    setRegisterStatus(error.message, true);
  }
}

function updateRegisterButton(capacityInfo = null) {
  const btn = document.getElementById("register-btn");
  if (!btn) return;

  btn.disabled = false;
  btn.textContent = "🎫 Register Now";
  btn.classList.remove("registered");

  if (capacityInfo && capacityInfo.capacity > 0) {
    const available = capacityInfo.available;
    if (available !== null && available <= 0) {
      btn.disabled = true;
      btn.textContent = "Sold Out";
      setRegisterStatus("This event is at capacity.", true);
      return;
    }
    if (available !== null) {
      setRegisterStatus(`${available} spots remaining`);
    }
  }

  if (registrationStatus === "registered") {
    btn.disabled = true;
    btn.textContent = "Registered";
    btn.classList.add("registered");
    setRegisterStatus("You're registered for this event!");
  }
}

async function handleRegisterClick() {
  if (!currentUser) {
    alert("Please log in to register for this event.");
    window.location.href = "/web-proj/login.html";
    return;
  }

  const btn = document.getElementById("register-btn");
  btn.disabled = true;
  btn.textContent = "Registering...";
  setRegisterStatus("");

  try {
    const response = await fetch("/web-proj/api/registrations.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-Firebase-UID": auth.currentUser.uid,
      },
      body: JSON.stringify({ event_id: Number(eventId) }),
    });

    const data = await response.json();
    if (!response.ok || !data.success) {
      throw new Error(data.error || "Registration failed");
    }

    registrationStatus = data.status || "registered";
    setRegisterStatus("Registration confirmed!");
    updateRegisterButton();
  } catch (error) {
    console.error("Registration error:", error);
    setRegisterStatus(error.message, true);
    btn.disabled = false;
    btn.textContent = "🎫 Register Now";
  }
}

function setRegisterStatus(message, isError = false) {
  const statusEl = document.getElementById("register-status");
  if (!statusEl) return;
  statusEl.textContent = message;
  statusEl.classList.toggle("error", Boolean(isError));
}

function showError(message) {
  document.getElementById("loading-container").innerHTML = `
                <div class="error-state">
                    <h2>⚠️ Error</h2>
                    <p>${message}</p>
                    <a href="/web-proj/index.html" class="back-btn">Go Back Home</a>
                </div>
            `;
}
