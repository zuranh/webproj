let allUsers = [];
let currentUserId = null;

async function initUserAdmin() {
  try {
    const user = await adminAuth.init();
    currentUserId = user.id;

    if (!adminAuth.requireOwner()) {
      return;
    }

    adminAuth.updateUIForRole();
    await loadUsers();
  } catch (error) {
    console.error('Admin users init failed:', error);
  }
}

document.addEventListener('DOMContentLoaded', initUserAdmin);

async function loadUsers() {
  try {
    setUsersLoading();
    const response = await adminAuth.apiRequest('/api/admin/users.php');
    const data = await response.json();

    if (data.success) {
      allUsers = data.users || [];
      renderUsers();
      updateStats();
    } else {
      throw new Error(data.error || 'Failed to load users');
    }
  } catch (error) {
    console.error('Failed to load users:', error);
    showAlert('Failed to load users', 'error');
    setUsersError();
  }
}

function renderUsers() {
  const container = document.getElementById('users-container');

  if (!allUsers.length) {
    container.innerHTML = '<div class="no-users"><h3>No users found</h3></div>';
    return;
  }

  container.innerHTML = '<div class="users-grid"></div>';
  const grid = container.querySelector('.users-grid');

  allUsers.forEach((user) => {
    const card = document.createElement('div');
    card.className = 'user-card';

    const isCurrentUser = user.id === currentUserId;
    const statusClass = user.is_active == 1 ? 'active' : 'inactive';
    const statusText = user.is_active == 1 ? '✅ Active' : '❌ Inactive';

    card.innerHTML = `
      <div class="user-card-header">
        <div class="user-info">
          <h3 class="user-name">${escapeHtml(user.name || 'User')} ${isCurrentUser ? '(You)' : ''}</h3>
          <p class="user-email">${escapeHtml(user.email || 'Unknown')}</p>
        </div>
        <span class="role-badge role-${user.role}">${(user.role || 'user').toUpperCase()}</span>
      </div>

      <div class="user-meta">
        <span>🆔 ID: ${user.id}</span>
        <span>📅 Joined: ${formatDate(user.joined_at)}</span>
        ${user.age ? `<span>🎂 Age: ${user.age}</span>` : ''}
        ${user.permission_count ? `<span>🔑 Perms: ${user.permission_count}</span>` : ''}
      </div>

      <div class="user-actions">
        <select class="role-select" id="role-${user.id}" ${isCurrentUser ? 'disabled' : ''}>
          <option value="user" ${user.role === 'user' ? 'selected' : ''}>User</option>
          <option value="admin" ${user.role === 'admin' ? 'selected' : ''}>Admin</option>
          <option value="owner" ${user.role === 'owner' ? 'selected' : ''}>Owner</option>
        </select>

        <button class="btn-toggle ${statusClass}" id="status-${user.id}" data-user-id="${user.id}" ${isCurrentUser ? 'disabled' : ''}>
          ${statusText}
        </button>

        <button class="btn-save" data-user-id="${user.id}" ${isCurrentUser ? 'disabled' : ''}>Save Changes</button>
      </div>
    `;

    grid.appendChild(card);
  });

  // wire up buttons after render
  grid.querySelectorAll('.btn-save').forEach((btn) => {
    btn.addEventListener('click', () => updateUserRole(parseInt(btn.dataset.userId, 10)));
  });

  grid.querySelectorAll('.btn-toggle').forEach((btn) => {
    btn.addEventListener('click', () => toggleUserStatus(parseInt(btn.dataset.userId, 10)));
  });
}

async function updateUserRole(userId) {
  const roleSelect = document.getElementById(`role-${userId}`);
  if (!roleSelect) return;
  const newRole = roleSelect.value;

  if (!confirm(`Change this user's role to ${newRole.toUpperCase()}?`)) {
    return;
  }

  try {
    const response = await adminAuth.apiRequest(`/api/admin/users.php?id=${userId}`, {
      method: 'PUT',
      body: JSON.stringify({ role: newRole }),
    });

    const data = await response.json();
    if (data.success) {
      showAlert('User role updated successfully!', 'success');
      await loadUsers();
    } else {
      throw new Error(data.error || 'Failed to update role');
    }
  } catch (error) {
    console.error('Error updating user role:', error);
    showAlert(error.message || 'Failed to update user role', 'error');
  }
}

async function toggleUserStatus(userId) {
  const target = allUsers.find((u) => u.id === userId);
  if (!target) return;

  const newStatus = target.is_active == 1 ? 0 : 1;
  const action = newStatus === 1 ? 'activate' : 'deactivate';

  if (!confirm(`Are you sure you want to ${action} this user?`)) {
    return;
  }

  try {
    const response = await adminAuth.apiRequest(`/api/admin/users.php?id=${userId}`, {
      method: 'PUT',
      body: JSON.stringify({ is_active: newStatus }),
    });

    const data = await response.json();
    if (data.success) {
      showAlert(`User ${action}d successfully!`, 'success');
      await loadUsers();
    } else {
      throw new Error(data.error || `Failed to ${action} user`);
    }
  } catch (error) {
    console.error(`Error ${action}ing user:`, error);
    showAlert(error.message || `Failed to ${action} user`, 'error');
  }
}

function updateStats() {
  const total = allUsers.length;
  const owners = allUsers.filter((u) => u.role === 'owner').length;
  const admins = allUsers.filter((u) => u.role === 'admin').length;
  const users = allUsers.filter((u) => u.role === 'user').length;

  document.getElementById('stat-total').textContent = total;
  document.getElementById('stat-owners').textContent = owners;
  document.getElementById('stat-admins').textContent = admins;
  document.getElementById('stat-users').textContent = users;
}

function showAlert(message, type) {
  const container = document.getElementById('alert-container');
  const alert = document.createElement('div');
  alert.className = `alert ${type}`;
  alert.textContent = message;
  container.innerHTML = '';
  container.appendChild(alert);
  alert.style.display = 'block';

  setTimeout(() => {
    alert.style.display = 'none';
  }, 4500);
}

function setUsersLoading() {
  const container = document.getElementById('users-container');
  container.innerHTML = '<div class="loading"><p>Loading users...</p></div>';
}

function setUsersError() {
  const container = document.getElementById('users-container');
  container.innerHTML = '<div class="no-users"><h3>Unable to load users</h3></div>';
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
  return date.toLocaleDateString();
}
