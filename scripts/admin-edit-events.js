const eventList = document.getElementById('event-list');
const editForm = document.getElementById('edit-form');
const alertBox = document.getElementById('form-alert');

const inputs = {
  title: document.getElementById('event-title'),
  status: document.getElementById('event-status'),
  description: document.getElementById('event-description'),
  date: document.getElementById('event-date'),
  time: document.getElementById('event-time'),
  location: document.getElementById('event-location'),
  age: document.getElementById('event-age'),
  price: document.getElementById('event-price'),
  image: document.getElementById('event-image'),
  capacity: document.getElementById('event-capacity'),
};

let currentEvent = null;
let genres = [];

function showAlert(message, type = 'error') {
  if (!alertBox) return;
  alertBox.textContent = message;
  alertBox.className = `alert ${type}`;
  alertBox.style.display = message ? 'block' : 'none';
}

function escapeHtml(str = '') {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function renderGenres() {
  const container = document.getElementById('genre-selection');
  if (!container) return;
  container.innerHTML = '';

  if (!genres.length) {
    container.innerHTML = '<p class="no-events">No genres available.</p>';
    return;
  }

  genres.forEach((genre) => {
    const label = document.createElement('label');
    label.className = 'genre-option';

    const checkbox = document.createElement('input');
    checkbox.type = 'checkbox';
    checkbox.value = genre.id;
    checkbox.name = 'genres[]';
    checkbox.dataset.slug = genre.slug || '';

    const icon = document.createElement('span');
    icon.className = 'genre-icon';
    icon.textContent = genre.icon || '🎭';

    const text = document.createElement('span');
    text.textContent = genre.name;

    label.appendChild(checkbox);
    label.appendChild(icon);
    label.appendChild(text);

    label.addEventListener('click', (e) => {
      if (e.target.tagName !== 'INPUT') {
        checkbox.checked = !checkbox.checked;
      }
      label.classList.toggle('selected', checkbox.checked);
    });

    container.appendChild(label);
  });
}

function renderEvents(events) {
  if (!eventList) return;
  eventList.innerHTML = '';

  if (!events.length) {
    eventList.innerHTML = '<p class="no-events">No events found.</p>';
    return;
  }

  events.forEach((event) => {
    const card = document.createElement('article');
    card.className = 'event-card';
    card.dataset.eventId = event.id;
    card.innerHTML = `
      <div class="event-card-header">
        <div>
          <h4 class="event-card-title">${escapeHtml(event.title || 'Untitled')}</h4>
          <p class="event-card-info">${escapeHtml(event.location || 'No location')}</p>
          <p class="event-card-info">${escapeHtml(event.date || 'No date')} @ ${escapeHtml(event.time || 'No time')}</p>
        </div>
        <span class="event-card-status status-${event.status || 'published'}">${escapeHtml(event.status || 'published')}</span>
      </div>
      <p class="event-card-info">${escapeHtml((event.description || '').slice(0, 100))}${event.description && event.description.length > 100 ? '…' : ''}</p>
      <div class="event-genres">${(event.genres || [])
        .filter(Boolean)
        .map((g) => `<span class="genre-tag">${escapeHtml(g)}</span>`)
        .join('') || '<span class="genre-tag">No genres</span>'}</div>
    `;

    card.addEventListener('click', () => {
      document.querySelectorAll('.event-card').forEach((c) => c.classList.remove('selected'));
      card.classList.add('selected');
      fillForm(event);
    });

    eventList.appendChild(card);
  });
}

function fillForm(event) {
  currentEvent = event;
  editForm.classList.add('active');

  inputs.title.value = event.title || '';
  inputs.status.value = event.status || 'published';
  inputs.description.value = event.description || '';
  inputs.date.value = event.date || '';
  inputs.time.value = event.time || '';
  inputs.location.value = event.location || '';
  inputs.age.value = event.age_restriction ?? '';
  inputs.price.value = event.price ?? '';
  inputs.image.value = event.image_url || '';
  inputs.capacity.value = event.capacity ?? '';

  const selectedGenres = event.genre_slugs || [];
  document.querySelectorAll('#genre-selection input[type="checkbox"]').forEach((checkbox) => {
    const isChecked = selectedGenres.includes(checkbox.dataset.slug);
    checkbox.checked = isChecked;
    checkbox.parentElement.classList.toggle('selected', isChecked);
  });
}

function collectFormData() {
  const selectedGenres = Array.from(document.querySelectorAll('#genre-selection input[type="checkbox"]:checked')).map((input) => Number(input.value));
  return {
    name: inputs.title.value.trim(),
    description: inputs.description.value.trim(),
    date: inputs.date.value,
    time: inputs.time.value,
    location: inputs.location.value.trim(),
    age_restriction: inputs.age.value || null,
    price: inputs.price.value || 0,
    image_url: inputs.image.value.trim() || null,
    status: inputs.status.value,
    genres: selectedGenres,
    capacity: inputs.capacity.value === '' ? null : Number(inputs.capacity.value),
    lat: currentEvent?.lat ?? null,
    lng: currentEvent?.lng ?? null,
  };
}

async function loadGenres() {
  const res = await fetch('/api/genres.php');
  const data = await res.json();
  if (!data.success) throw new Error(data.error || 'Failed to load genres');
  genres = data.genres || [];
  renderGenres();
}

async function loadEvents() {
  const res = await adminAuth.apiRequest('/api/admin/events.php');
  const data = await res.json();
  if (!data.success) throw new Error(data.error || 'Failed to load events');
  renderEvents(data.events || []);
}

async function updateEvent() {
  if (!currentEvent) return;
  showAlert('');
  const payload = collectFormData();
  if (!payload.name || !payload.description || !payload.date || !payload.time || !payload.location) {
    showAlert('Please fill in all required fields', 'error');
    return;
  }

  const res = await adminAuth.apiRequest(`/api/admin/events.php?id=${currentEvent.id}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await res.json();
  if (!data.success) throw new Error(data.error || 'Update failed');

  showAlert('Event updated', 'success');
  await loadEvents();
}

async function deleteEvent() {
  if (!currentEvent) return;
  if (!confirm('Delete this event?')) return;
  showAlert('');
  const res = await adminAuth.apiRequest(`/api/admin/events.php?id=${currentEvent.id}`, { method: 'DELETE' });
  const data = await res.json();
  if (!data.success) throw new Error(data.error || 'Delete failed');
  showAlert('Event deleted', 'success');
  editForm.classList.remove('active');
  await loadEvents();
}

function wireActions() {
  document.getElementById('save-event')?.addEventListener('click', async () => {
    try {
      await updateEvent();
    } catch (err) {
      console.error(err);
      showAlert(err.message, 'error');
    }
  });

  document.getElementById('delete-event')?.addEventListener('click', async () => {
    try {
      await deleteEvent();
    } catch (err) {
      console.error(err);
      showAlert(err.message, 'error');
    }
  });

  document.getElementById('cancel-edit')?.addEventListener('click', () => {
    editForm.classList.remove('active');
    document.querySelectorAll('.event-card').forEach((c) => c.classList.remove('selected'));
  });
}

async function init() {
  try {
    await adminAuth.init();
    adminAuth.updateUIForRole?.();
    await loadGenres();
    await loadEvents();
    wireActions();
  } catch (err) {
    console.error('Admin init failed', err);
    showAlert(err.message || 'Admin authentication failed', 'error');
  }
}

document.addEventListener('DOMContentLoaded', init);
