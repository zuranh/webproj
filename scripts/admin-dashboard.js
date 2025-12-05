document.addEventListener('DOMContentLoaded', async () => {
  try {
    await adminAuth.init();
    adminAuth.updateUIForRole();
    await loadStats();
  } catch (error) {
    console.error('Admin init failed:', error);
  }
});

async function loadStats() {
  setLoadingRows('recent-registrations');
  setLoadingRows('recent-events');

  try {
    const response = await adminAuth.apiRequest('/api/admin/stats.php');
    const data = await response.json();

    if (!data.success) {
      throw new Error(data.error || 'Failed to load stats');
    }

    renderCounts(data.counts);
    renderRecentRegistrations(data.recentRegistrations || []);
    renderRecentEvents(data.recentEvents || []);
    renderGenres(data.genreBreakdown || []);
    renderActions(data.recentActions || [], data.role);
  } catch (error) {
    console.error('Failed to load dashboard stats:', error);
    showErrorRow('recent-registrations', 'Unable to load registrations');
    showErrorRow('recent-events', 'Unable to load events');
    showErrorRow('genre-breakdown', 'Unable to load genres');
    showErrorRow('recent-actions', 'Unable to load activity');
  }
}

function renderCounts(counts) {
  const fmt = (value) => (typeof value === 'number' ? value.toLocaleString() : '--');
  document.getElementById('stat-events').textContent = fmt(counts.events);
  document.getElementById('stat-upcoming').textContent = `${fmt(counts.upcoming)} upcoming`;
  document.getElementById('stat-users').textContent = fmt(counts.users);
  document.getElementById('stat-registrations').textContent = fmt(counts.registrations);
  document.getElementById('stat-cancellations').textContent = `${fmt(counts.cancellations)} canceled`;

   const capacity = counts.capacity ?? 0;
   const available = counts.available ?? 0;
   const utilization = capacity > 0 ? Math.min(100, Math.round(((counts.registrations || 0) / capacity) * 100)) : 0;

   document.getElementById('stat-capacity').textContent = fmt(capacity);
   document.getElementById('stat-available').textContent = `${fmt(available)} available`;
   document.getElementById('stat-utilization').textContent = `${utilization}%`;

  const recentCount = (counts.events || 0) + (counts.registrations || 0);
  document.getElementById('stat-recent').textContent = fmt(recentCount);
}

function renderRecentRegistrations(rows) {
  const tbody = document.querySelector('#recent-registrations tbody');
  tbody.innerHTML = '';

  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="4" class="empty-row">No registrations yet</td></tr>';
    return;
  }

  rows.forEach((row) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${escapeHtml(row.event_title || 'Untitled')}</td>
      <td>${escapeHtml(row.user_name || 'Unknown')}</td>
      <td><span class="status-pill status-${row.status}">${row.status || 'registered'}</span></td>
      <td>${formatDate(row.created_at)}</td>
    `;
    tbody.appendChild(tr);
  });
}

function renderGenres(genres) {
  const tbody = document.querySelector('#genre-breakdown tbody');
  if (!tbody) return;
  tbody.innerHTML = '';

  if (!genres.length) {
    tbody.innerHTML = '<tr><td colspan="2" class="empty-row">No genre data yet</td></tr>';
    return;
  }

  genres.forEach((genre) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${escapeHtml(genre.name || 'Untitled')}</td>
      <td>${genre.event_count ?? 0}</td>
    `;
    tbody.appendChild(tr);
  });
}

function renderActions(actions, role) {
  const panel = document.getElementById('actions-panel');
  const tbody = document.querySelector('#recent-actions tbody');

  if (!panel || !tbody) return;

  if (role !== 'owner') {
    panel.style.display = 'none';
    return;
  }

  panel.style.display = '';
  tbody.innerHTML = '';

  if (!actions.length) {
    tbody.innerHTML = '<tr><td colspan="4" class="empty-row">No admin actions yet</td></tr>';
    return;
  }

  actions.forEach((action) => {
    const tr = document.createElement('tr');
    const target = action.target_type ? `${action.target_type} #${action.target_id ?? ''}` : '—';
    tr.innerHTML = `
      <td>${escapeHtml(action.admin_name || 'Unknown')}</td>
      <td>${escapeHtml(action.action || '')}</td>
      <td>${escapeHtml(target)}</td>
      <td>${formatDate(action.created_at)}</td>
    `;
    tbody.appendChild(tr);
  });
}

function renderRecentEvents(rows) {
  const tbody = document.querySelector('#recent-events tbody');
  tbody.innerHTML = '';

  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="4" class="empty-row">No events found</td></tr>';
    return;
  }

  rows.forEach((row) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${escapeHtml(row.title || 'Untitled')}</td>
      <td><span class="status-pill status-${row.status || 'draft'}">${row.status || 'draft'}</span></td>
      <td>${formatDate(row.date)}</td>
      <td>${escapeHtml(row.creator_name || 'Unknown')}</td>
    `;
    tbody.appendChild(tr);
  });
}

function setLoadingRows(tableId) {
  const tbody = document.querySelector(`#${tableId} tbody`);
  if (tbody) {
    tbody.innerHTML = '<tr><td colspan="4" class="loading-row">Loading...</td></tr>';
  }
}

function showErrorRow(tableId, message) {
  const tbody = document.querySelector(`#${tableId} tbody`);
  if (tbody) {
    const cols = tbody.parentElement?.querySelectorAll('thead th').length || 1;
    tbody.innerHTML = `<tr><td colspan="${cols}" class="error-row">${escapeHtml(message)}</td></tr>`;
  }
}

function escapeHtml(str) {
  if (typeof str !== 'string') return '';
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function formatDate(value) {
  if (!value) return '-';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}
