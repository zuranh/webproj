const genreSelection = document.getElementById("genre-selection");
const alertContainer = document.getElementById("alert-container");
const form = document.getElementById("add-event-form");
const submitBtn = document.getElementById("submit-btn");
const btnText = document.getElementById("btn-text");
const btnLoader = document.getElementById("btn-loader");

function setStatusSelectionHandlers() {
  document.querySelectorAll(".status-option").forEach((option) => {
    option.addEventListener("click", () => {
      document
        .querySelectorAll(".status-option")
        .forEach((o) => o.classList.remove("selected"));
      option.classList.add("selected");
      option.querySelector("input[type='radio']").checked = true;
    });
  });
}

function showAlert(message, type = "error") {
  if (!message) {
    alertContainer.innerHTML = "";
    return;
  }
  alertContainer.innerHTML = "";
  const alert = document.createElement("div");
  alert.className = `alert ${type}`;
  alert.textContent = message;
  alertContainer.appendChild(alert);
}

function toggleSubmit(disabled) {
  submitBtn.disabled = disabled;
  btnText.style.display = disabled ? "none" : "inline";
  btnLoader.style.display = disabled ? "inline" : "none";
}

async function loadGenres() {
  try {
    const response = await fetch("/api/genres.php");
    const data = await response.json();

    if (!data.success) {
      throw new Error(data.error || "Failed to load genres");
    }

    renderGenres(data.genres || []);
  } catch (err) {
    console.error("Failed to load genres", err);
    renderGenres([]);
    showAlert("Failed to load genres", "error");
  }
}

function renderGenres(genres) {
  if (!genreSelection) return;

  if (!genres.length) {
    genreSelection.innerHTML = "<p class=\"muted\">No genres available.</p>";
    return;
  }

  genreSelection.innerHTML = "";
  genres.forEach((genre) => {
    const label = document.createElement("label");
    label.className = "genre-option";

    const checkbox = document.createElement("input");
    checkbox.type = "checkbox";
    checkbox.name = "genres[]";
    checkbox.value = genre.id;

    const icon = document.createElement("span");
    icon.className = "genre-icon";
    icon.textContent = genre.icon || "🎫";

    const text = document.createElement("span");
    text.textContent = genre.name;

    label.appendChild(checkbox);
    label.appendChild(icon);
    label.appendChild(text);

    label.addEventListener("click", (e) => {
      if (e.target.tagName !== "INPUT") {
        checkbox.checked = !checkbox.checked;
      }
      label.classList.toggle("selected", checkbox.checked);
    });

    genreSelection.appendChild(label);
  });
}

function collectFormData() {
  const genres = Array.from(
    document.querySelectorAll('input[name="genres[]"]:checked')
  ).map((checkbox) => Number(checkbox.value));

  return {
    name: document.getElementById("title").value.trim(),
    description: document.getElementById("description").value.trim(),
    date: document.getElementById("date").value,
    time: document.getElementById("time").value,
    location: document.getElementById("location").value.trim(),
    lat: document.getElementById("lat").value || null,
    lng: document.getElementById("lng").value || null,
    age_restriction: document.getElementById("age_restriction").value || null,
    price: document.getElementById("price").value || 0,
    image_url: document.getElementById("image_url").value.trim() || null,
    status: document.querySelector('input[name="status"]:checked').value,
    genres,
  };
}

function validateForm(data) {
  if (!data.name) return "Event title is required.";
  if (!data.description) return "Description is required.";
  if (!data.date || !data.time) return "Date and time are required.";
  if (!data.location) return "Location is required.";
  if (!data.genres.length) return "Please select at least one genre.";
  return null;
}

async function handleSubmit(event) {
  event.preventDefault();
  const payload = collectFormData();
  const validationError = validateForm(payload);

  if (validationError) {
    showAlert(validationError, "error");
    return;
  }

  toggleSubmit(true);
  showAlert("");

  try {
    const response = await adminAuth.apiRequest("/api/admin/events.php", {
      method: "POST",
      body: JSON.stringify(payload),
    });
    const data = await response.json();

    if (!data.success) {
      throw new Error(data.error || "Failed to create event");
    }

    showAlert("Event created successfully!", "success");
    form.reset();
    document
      .querySelectorAll(".genre-option")
      .forEach((el) => el.classList.remove("selected"));
    document
      .querySelectorAll(".status-option")
      .forEach((el, idx) => el.classList.toggle("selected", idx === 0));
  } catch (err) {
    console.error("Error creating event", err);
    showAlert(err.message, "error");
  } finally {
    toggleSubmit(false);
  }
}

async function init() {
  try {
    await adminAuth.init();
    adminAuth.updateUIForRole();
    setStatusSelectionHandlers();
    await loadGenres();
  } catch (err) {
    console.error("Admin auth failed", err);
  }
}

if (form) {
  form.addEventListener("submit", handleSubmit);
}

document.addEventListener("DOMContentLoaded", init);
