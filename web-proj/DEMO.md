DEMO — Event Finder (5–7 minute script)

Goal: demonstrate core flows, architecture, and lessons learned.

1. Opening (30s)

- Quick project name and team members.
- One-line elevator: "Event Finder helps users discover nearby events and lets admins manage event listings."

2. Architecture (60s)

- Frontend: static HTML/CSS/vanilla JS (geolocation, fetch API).
- Backend: PHP REST-like endpoints (`api/*.php`) using PDO; dev uses SQLite by default, can connect to MySQL via env vars.
- Persistence: `api/schema.sql` for MySQL; `data/database.sqlite` for local dev fallback.

3. Core user flow demo (2–3 min)

- Show `index.html` and allow geolocation prompt; explain Haversine proximity filter.
- Go to `login.html`, show signup -> automatic login -> redirect to `account.html`.
- Show profile data loaded from `api/me.php`.

4. Admin flow demo (1–2 min)

- Open `addEvents.html` (dev admin page). Enter admin secret when prompted and create an event.
- Show `edit-events.html` to update and delete events.

5. Testing & dev tooling (30s)

- Point to `dev/test-api.ps1` (PowerShell) and `dev/test-api.sh` (shell) to run automated API checks.
- Explain `dev/mysql-setup.ps1` for creating MySQL schema locally.

6. Lessons & next steps (30s)

- What worked: fast dev loop with PHP built-in server and SQLite fallback, simple API design.
- What to improve: production security (HTTPS, secure cookies, proper admin auth), deploy to hosted MySQL and CI.

Notes for presenters: keep UI steps simple and focus on backend features if time runs short.
