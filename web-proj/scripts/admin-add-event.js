// web-proj/scripts/admin-add-event.js

import { auth } from "../firebase-config.js";
import {
  onAuthStateChanged,
} from "https://www.gstatic.com/firebasejs/10.7.1/firebase-auth.js";

let currentUser = null;

// ------------ DOM ELEMENTS ------------
const form = document.getElementById("add-event-form");
const genreSelection = document.getElementById("genre-selection");
const alertBox = document.getElementById("form-alert");
const submitBtn = document.getElementById("submit-btn");

const imageFileInput = document.getElementById("image_file");
const imagePreviewWrapper = document.getElementById("image-preview-wrapper");
const imagePreview = document.getElementById("image-preview");

// ------------ HELPERS ------------

function showAlert(message, type = "info") {
  if (alertBox) {
    alertBox.textContent = message;
    alertBox.className = "";
    if (message) alertBox.classList.add("alert", `alert-${type}`);
  } else {
    console.log("ALERT:", type, message);
  }
}

function toggleSubmit(state) {
  if (submitBtn) {
    submitBtn.disabled = state;
    submitBtn.textContent = state ? "Creating..." : "Add Event";
  }
}

function updatePreview(url) {
  if (!url) {
    imagePreviewWrapper.style.display = "none";
    imagePreview.src = "";
    return;
  }
  imagePreviewWrapper.style.display = "block";
  imagePreview.src = url;
}

// ------------ AUTH / ADMIN CHECK ------------

async function ensureAdmin(user) {
  const idToken = await user.getIdToken();

  const res = await fetch("api/me.php", {
    headers: { Authorization: `Bearer ${idToken}` },
  });

  const data = await res.json();
  if (!res.ok || !data.success) throw new Error("Authentication failed");

  const role = data.user.role;
  if (role !== "admin" && role !== "owner")
    throw new Error("Not authorized");

  return true;
}

// ------------ GENRES ------------

async function loadGenres() {
  try {
    const res = await fetch("api/genres.php");
    const data = await res.json();

    if (!res.ok || !data.success) throw new Error(data.error);

    genreSelection.innerHTML = "";
    data.genres.forEach((genre) => {
      const label = document.createElement("label");
      label.className = "genre-option";

      const checkbox = document.createElement("input");
      checkbox.type = "checkbox";
      checkbox.value = genre.id;
      checkbox.name = "genres[]";

      const span = document.createElement("span");
      span.textContent = genre.name;

      label.appendChild(checkbox);
      label.appendChild(span);
      genreSelection.appendChild(label);
    });
  } catch (err) {
    showAlert("Couldn't load genres: " + err.message, "error");
  }
}

// ------------ IMAGE UPLOAD ------------

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
  if (!res.ok || !data.success) throw new Error(data.error);

  return data.url;
}

// ------------ FORM SUBMISSION ------------

async function handleSubmit(e) {
  e.preventDefault();
  showAlert("");

  toggleSubmit(true);

  try {
    // Validate required form fields
    const name = document.getElementById("title").value.trim();
    const description = document.getElementById("description").value.trim();
    const date = document.getElementById("date").value;
    const time = document.getElementById("time").value;
    const location = document.getElementById("location").value.trim();

    if (!name || !description || !date || !time || !location)
      throw new Error("Please fill all required fields.");

    const genres = Array.from(
      document.querySelectorAll('input[name="genres[]"]:checked')
    ).map((cb) => Number(cb.value));

    if (genres.length === 0)
      throw new Error("Please select at least one genre.");

    // Upload image file if selected
    let imageUrl = null;
    const file = imageFileInput.files[0];

    if (file) {
      imageUrl = await uploadImageFile(file);
      updatePreview(imageUrl);
    }

    // Build payload
    const payload = {
      name,
      description,
      date,
      time,
      location,
      image_url: imageUrl, // ONLY file-based images now
      genres,
      status: "published",
    };

    const idToken = await currentUser.getIdToken();

    const response = await fetch("api/admin/events.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Authorization: `Bearer ${idToken}`,
        "X-Firebase-UID": currentUser.uid,
      },
      body: JSON.stringify(payload),
    });

    const raw = await response.text();
    const data = raw ? JSON.parse(raw) : {};

    if (!response.ok || !data.success) throw new Error(data.error || raw);

    showAlert("Event created successfully!", "success");
    form.reset();
    updatePreview("");

  } catch (err) {
    showAlert(err.message, "error");
  } finally {
    toggleSubmit(false);
  }
}

// ------------ INIT ------------

function init() {
  onAuthStateChanged(auth, async (user) => {
    if (!user) return (window.location.href = "login.html");
    currentUser = user;

    try {
      await ensureAdmin(user);
      loadGenres();
    } catch (err) {
      alert(err.message);
      window.location.href = "index.html";
    }
  });

  if (form) form.addEventListener("submit", handleSubmit);

  // Upload image when file is selected
  if (imageFileInput) {
    imageFileInput.addEventListener("change", async (e) => {
      const file = e.target.files[0];
      if (file) {
        try {
          const url = await uploadImageFile(file);
          updatePreview(url);
          showAlert("Image uploaded!", "success");
        } catch (err) {
          updatePreview("");
          showAlert("Upload failed: " + err.message, "error");
        }
      }
    });
  }
}

document.addEventListener("DOMContentLoaded", init);
