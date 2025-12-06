// Admin events helper for addEvents.html and edit-events.html
(async function(){
  async function promptSecret() {
    const s = prompt('Enter admin secret (for dev only):');
    return s ? s.trim() : '';
  }

  // Add event form handling
  const addForm = document.getElementById('add-event-form');
  if (addForm) {
    addForm.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const data = new FormData(addForm);
      const payload = {};
      for (const [k,v] of data.entries()) payload[k.replace(/-/g,'_')] = v;
      const secret = await promptSecret();
      if (!secret) { alert('Admin secret required'); return; }
      try {
        const res = await fetch('api/events.php', { method:'POST', headers:{'Content-Type':'application/json','X-Admin-Secret':secret}, body: JSON.stringify(payload)});
        const json = await res.json();
        if (!res.ok) { alert(json.error || JSON.stringify(json)); return; }
        alert('Event created (id='+json.id+')');
        window.location.href = 'admin.html';
      } catch (err) { alert('Network error'); }
    });
  }

  // Edit events page: populate select and handle update/delete
  const eventSelect = document.getElementById('event-select');
  if (eventSelect) {
    async function loadEvents() {
      const res = await fetch('api/events.php');
      const events = await res.json();
      eventSelect.innerHTML = '<option value="">--Choose Event--</option>';
      events.forEach(ev => {
        const opt = document.createElement('option');
        opt.value = ev.id;
        opt.textContent = ev.title + ' — ' + (ev.location||'');
        opt.dataset.ev = JSON.stringify(ev);
        eventSelect.appendChild(opt);
      });
    }
    await loadEvents();

    eventSelect.addEventListener('change', ()=>{
      const opt = eventSelect.selectedOptions[0];
      if (!opt || !opt.value) return;
      const ev = JSON.parse(opt.dataset.ev || '{}');
      document.getElementById('event-title').value = ev.title || '';
      document.getElementById('event-description').value = ev.description || '';
      document.getElementById('event-date').value = ev.date || '';
      document.getElementById('event-time').value = ev.time || '';
      document.getElementById('event-location').value = ev.location || '';
      document.getElementById('event-age').value = ev.age_restriction || '';
      document.getElementById('event-price').value = ev.price || '';
    });

    // Update
    const editForm = document.getElementById('edit-event-form');
    if (editForm) {
      editForm.addEventListener('submit', async (e)=>{
        e.preventDefault();
        const id = parseInt(eventSelect.value);
        if (!id) { alert('Select an event'); return; }
        const payload = {
          id: id,
          title: document.getElementById('event-title').value,
          description: document.getElementById('event-description').value,
          date: document.getElementById('event-date').value,
          time: document.getElementById('event-time').value,
          location: document.getElementById('event-location').value,
          age_restriction: document.getElementById('event-age').value,
          price: document.getElementById('event-price').value
        };
        const secret = await promptSecret(); if (!secret) { alert('Admin secret required'); return; }
        try {
          const res = await fetch('api/events.php', { method:'PUT', headers: {'Content-Type':'application/json','X-Admin-Secret':secret}, body: JSON.stringify(payload) });
          const json = await res.json();
          if (!res.ok) { alert(json.error || JSON.stringify(json)); return; }
          alert('Event updated');
          await loadEvents();
        } catch (err) { alert('Network error'); }
      });
    }

    // Delete
    const delBtn = document.getElementById('delete-event');
    if (delBtn) {
      delBtn.addEventListener('click', async ()=>{
        const id = parseInt(eventSelect.value);
        if (!id) { alert('Select an event'); return; }
        if (!confirm('Delete this event?')) return;
        const secret = await promptSecret(); if (!secret) { alert('Admin secret required'); return; }
        try {
          const res = await fetch('api/events.php?id='+id, { method:'DELETE', headers: {'Content-Type':'application/json','X-Admin-Secret':secret} });
          const json = await res.json();
          if (!res.ok) { alert(json.error || JSON.stringify(json)); return; }
          alert('Event deleted');
          await loadEvents();
        } catch (err) { alert('Network error'); }
      });
    }
  }

})();
