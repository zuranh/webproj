document.addEventListener('DOMContentLoaded', async () => {
  try {
    await adminAuth.init();
    if (!adminAuth.requireOwner()) return;
    adminAuth.updateUIForRole();
    await loadActions();

    const refreshBtn = document.getElementById('refresh-actions');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', () => loadActions());
    }
  } catch (error) {
    console.error('Admin actions init failed:', error);
  }
});

async function loadActions() {
  const tbody = document.querySelector('#actions-table tbody');
  if (tbody) {
    tbody.innerHTML = '<tr><td colspan="5" class="loading-row">Loading...</td></tr>';
  }

  try {
    const response = await adminAuth.apiRequest('/api/admin/actions.php?limit=150');
    const data = await response.json();

    if (!data.success) {
      throw new Error(data.error || 'Failed to load admin actions');
    }

    renderActions(data.actions || []);
  } catch (error) {
    console.error('Failed to load admin actions:', error);
    if (tbody) {
      tbody.innerHTML = '<tr><td colspan="5" class="error-row">Unable to load admin actions</td></tr>';
    }
  }
}

function renderActions(actions) {
  const tbody = document.querySelector('#actions-table tbody');
  if (!tbody) return;
  tbody.innerHTML = '';

  if (!actions.length) {
    tbody.innerHTML = '<tr><td colspan="5" class="empty-row">No admin activity yet</td></tr>';
    return;
  }

  actions.forEach((action) => {
    const tr = document.createElement('tr');
    const adminName = action.admin_name || 'Unknown';
    const target = action.target_name || action.target_type || '-';
    const details = action.details ? escapeHtml(action.details) : '-';

    tr.innerHTML = `
      <td>
        ${escapeHtml(action.action || 'action')}
        <small>${escapeHtml(action.ip_address || '')}</small>
      </td>
      <td>${escapeHtml(adminName)}</td>
      <td>${escapeHtml(target)}</td>
      <td>${details}</td>
      <td>${formatDate(action.created_at)}</td>
    `;

    tbody.appendChild(tr);
  });
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
