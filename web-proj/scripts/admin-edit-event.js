// scripts/admin-edit-event.js

import { auth } from "../firebase-config.js";
import {
  onAuthStateChanged,
} from "https://www.gstatic.com/firebasejs/10.7.1/firebase-auth.js";

let currentUser = null;
let currentEventId = null;
let currentImageUrl = null;

// DOM refs (match editEvent.html)
const form = document.getElementById("edit-event-form");
const alertBox = document.getElementById("form-alert");
const submitBtn = document.getElementById("submit-btn");

const titleInput = document.getElementById("title");
const descInput = document.getElementById("description");
const dateInput = document.getElementById("date");
const timeInput = document.getElementById("time");
const locationInput = document.getElementById("location");

const imageFileInput = document.getElementById("image_file");
const imagePreviewWrapper = document.getElementById("image-preview-wrapper");
const imagePreview = document.getElementById("image-preview");

// ---------- helpers ----------
function showAlert(message, type = "info") {
  if (!alertBox) {
    if (message) console.log("ALERT:", type, message);
    return;
  }
  alertBox.textContent = message;
  alertBox.className = "";
  if (message) {
    alertBox.classList.add("alert", `alert-${type}`);
  }
}

function toggleSubmit(disabled) {
  if (!submitBtn) return;
  submitBtn.disabled = disabled;
  submitBtn.textContent = disabled ? "Saving..." : "Save Changes";
}

function updatePreview(url) {
  if (!imagePreviewWrapper || !imagePreview) return;
  if (!url) {
    imagePreviewWrapper.style.display = "none";
    imagePreview.src = "";
    return;
  }
  imagePreviewWrapper.style.display = "block";
  imagePreview.src = url;
}

function getEventIdFromUrl() {
  const params = new URLSearchParams(window.location.search);
  return params.get("id");
}

async function ensureAdmin(user) {
  const idToken = await user.getIdToken();
  const res = await fetch("api/me.php", {
    headers: { Authorization: `Bearer ${idToken}` },
  });

  const data = await res.json();
  if (!res.ok || !data.success) {
    throw new Error(data.error || "Failed to load user profile");
  }

  const role = data.user.role;
  if (role !== "admin" && role !== "owner") {
    throw new Error("You are not authorized to access this page.");
  }

  return true;
}

// ---------- LOAD ONE EVENT (from api/events.php) ----------
async function loadEvent() {
  if (!currentEventId) return;

  showAlert("Loading event...", "info");

  const res = await fetch(
    `api/events.php?id=${encodeURIComponent(currentEventId)}`
  );

  let data;
  try {
    data = await res.json();
  } catch (err) {
    console.error("Invalid JSON from events.php", err);
    throw new Error("Invalid response from server while loading event.");
  }

  if (!res.ok || data.success === false) {
    throw new Error(data.error || "Failed to load event");
  }

  const ev = data.event;

  titleInput.value = ev.name || "";
  descInput.value = ev.description || "";
  dateInput.value = ev.date || "";
  timeInput.value = ev.time || "";
  locationInput.value = ev.location || "";

  currentImageUrl = ev.image_url || null;
  updatePreview(currentImageUrl || "");

  showAlert("");
}

// ---------- IMAGE UPLOAD ----------
async function uploadImageFile(file) {
  if (!file || !currentUser) return null;

  showAlert("Uploading image...", "info");

  const idToken = await currentUser.getIdToken();
  const formData = new FormData();
  formData.append("image", file);

  const res = await fetch("api/upload-image.php", {
    method: "POST",
    headers: {
      Authorization: `Bearer ${idToken}`,
      "X-Firebase-UID": currentUser.uid,
    },
    body: formData,
  });

  const data = await res.json();

  if (!res.ok || !data.success) {
    throw new Error(data.error || "Image upload failed");
  }

  return data.url;
}

// ---------- SUBMIT (SAVE CHANGES) ----------
async function handleSubmit(e) {
  e.preventDefault();
  showAlert("");

  if (!currentUser || !currentEventId) {
    showAlert("Missing user or event id.", "error");
    return;
  }

  const name = titleInput.value.trim();
  const description = descInput.value.trim();
  const date = dateInput.value;
  const time = timeInput.value;
  const location = locationInput.value.trim();

  if (!name || !description || !date || !time || !location) {
    showAlert("Please fill in all required fields.", "error");
    return;
  }

  toggleSubmit(true);

  try {
    // If a new file is selected, upload and override currentImageUrl
    const file = imageFileInput.files[0];
    let imageUrl = currentImageUrl;

    if (file) {
      imageUrl = await uploadImageFile(file);
      currentImageUrl = imageUrl;
      updatePreview(imageUrl);
    }

    const payload = {
      id: Number(currentEventId), // 🔥 tell backend we’re updating this event
      name,
      description,
      date,
      time,
      location,
      image_url: imageUrl,
    };

    const idToken = await currentUser.getIdToken();

    // Reuse existing admin events endpoint (same as Add Event, but with id)
    const res = await fetch("api/admin/events.php", {
      method: "POST", // your add event uses POST, so update can too
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${idToken}`,
        "X-Firebase-UID": currentUser.uid,
      },
      body: JSON.stringify(payload),
    });

    const raw = await res.text();
    let data = {};
    try {
      data = raw ? JSON.parse(raw) : {};
    } catch (err) {
      console.warn("Non-JSON response from events.php:", raw);
    }

    if (!res.ok || data.success === false) {
      throw new Error(data.error || raw || "Update failed");
    }

    showAlert("Event updated successfully!", "success");
  } catch (err) {
    console.error("Edit event error:", err);
    showAlert(err.message, "error");
  } finally {
    toggleSubmit(false);
  }
}

// ---------- INIT ----------
function init() {
  currentEventId = getEventIdFromUrl();
  if (!currentEventId) {
    alert("Missing ?id= in URL for event.");
    window.location.href = "admin.html";
    return;
  }

  onAuthStateChanged(auth, async (user) => {
    if (!user) {
      window.location.href = "login.html";
      return;
    }

    currentUser = user;

    try {
      await ensureAdmin(user);
      await loadEvent(); // uses api/events.php?id=...
    } catch (err) {
      console.error("Error initializing edit page:", err);
      alert(err.message || "Unable to load event.");
      window.location.href = "admin.html";
    }
  });

  if (form) {
    form.addEventListener("submit", handleSubmit);
  }

  if (imageFileInput) {
    imageFileInput.addEventListener("change", async (e) => {
      const file = e.target.files[0];
      if (!file) return;

      try {
        const url = await uploadImageFile(file);
        currentImageUrl = url;
        updatePreview(url);
        showAlert("Image uploaded successfully.", "success");
      } catch (err) {
        console.error("Image upload error:", err);
        showAlert("Image upload failed: " + err.message, "error");
        updatePreview(currentImageUrl || "");
      }
    });
  }
}

document.addEventListener("DOMContentLoaded", init);
