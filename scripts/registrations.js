import { auth } from "../firebase-config.js";
import { onAuthStateChanged } from "https://www.gstatic.com/firebasejs/10.7.1/firebase-auth.js";

let registrations = [];

onAuthStateChanged(auth, async (user) => {
  if (!user) {
    window.location.href = "login.html";
    return;
  }
  await loadRegistrations(user);
});

async function loadRegistrations(user) {
  const upcomingList = document.getElementById("upcoming-list");
  const pastList = document.getElementById("past-list");

  try {
    const res = await fetch("/web-proj/api/registrations.php", {
      headers: { "X-Firebase-UID": user.uid },
    });
    const data = await res.json();
    if (!res.ok || !data.success) {
      throw new Error(data.error || "Failed to load registrations");
    }

    registrations = data.registrations || [];
    document.getElementById("upcoming-count").textContent =
      data.counts?.upcoming ?? 0;
    document.getElementById("past-count").textContent = data.counts?.past ?? 0;
    document.getElementById("cancelled-count").textContent =
      data.counts?.cancelled ?? 0;

    renderLists();
  } catch (err) {
    console.error("Registrations load error", err);
    upcomingList.innerHTML = `<p class="placeholder">${err.message}</p>`;
    pastList.innerHTML = `<p class="placeholder">${err.message}</p>`;
  }
}

function renderLists() {
  const upcomingList = document.getElementById("upcoming-list");
  const pastList = document.getElementById("past-list");
  upcomingList.innerHTML = "";
  pastList.innerHTML = "";

  const upcoming = registrations.filter(
    (reg) => reg.status === "registered" && reg.is_upcoming
  );
  const past = registrations.filter((reg) => !reg.is_upcoming || reg.status === "cancelled");

  if (upcoming.length === 0) {
    upcomingList.innerHTML =
      '<p class="placeholder">No upcoming registrations yet.</p>';
  } else {
    upcoming.forEach((reg) => {
      upcomingList.appendChild(createCard(reg, true));
    });
  }

  if (past.length === 0) {
    pastList.innerHTML = '<p class="placeholder">No past events yet.</p>';
  } else {
    past.forEach((reg) => pastList.appendChild(createCard(reg, false)));
  }
}

function createCard(reg, isUpcoming) {
  const card = document.createElement("div");
  card.className = "registration-card";

  const left = document.createElement("div");
  const title = document.createElement("h3");
  title.textContent = reg.event_name || "Event";
  left.appendChild(title);

  const meta = document.createElement("p");
  meta.className = "registration-meta";
  meta.textContent = `${reg.date || "Date TBA"} • ${reg.time || "Time TBA"} • ${
    reg.location || "Location TBA"
  }`;
  left.appendChild(meta);

  const status = document.createElement("span");
  status.className = "badge" + (reg.status === "cancelled" ? " alert" : "");
  status.textContent = reg.status === "cancelled" ? "Cancelled" : "Registered";
  left.appendChild(status);

  const right = document.createElement("div");
  right.className = "registration-actions";

  const viewBtn = document.createElement("a");
  viewBtn.href = `/web-proj/event.html?id=${reg.event_id}`;
  viewBtn.textContent = "View details";
  viewBtn.className = "btn-secondary";
  right.appendChild(viewBtn);

  if (isUpcoming && reg.can_cancel) {
    const cancelBtn = document.createElement("button");
    cancelBtn.className = "btn-danger";
    cancelBtn.textContent = "Cancel";
    cancelBtn.addEventListener("click", () => cancelRegistration(reg.event_id));
    right.appendChild(cancelBtn);
  }

  card.appendChild(left);
  card.appendChild(right);
  return card;
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
    console.error("Cancel error", err);
    alert(err.message);
  }
}
