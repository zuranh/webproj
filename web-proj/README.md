# Event Finder — Local PHP API & Frontend

Last updated: 2025-11-25

This project is a small web app (frontend HTML/CSS/JS) with a lightweight PHP API backed by SQLite. It implements user registration/login (PHP sessions), a profile page, and an `events` endpoint with a proximity filter (Haversine).

---

## What I added

- `api/db.php` — creates `data/database.sqlite` (if missing) and seeds a sample event.
- `api/register.php` — user registration endpoint (POST JSON: `name`, `email`, `password`, `age`).
- `api/login.php` — login endpoint (POST JSON: `email`, `password`) — starts PHP session.
- `api/me.php` — returns current session user (requires session cookie).
- `api/logout.php` — logs out (destroys session).
- `api/events.php` — GET returns events; supports `?lat=...&lng=...&radius=...` for nearby events; POST to create event (protected by `X-Admin-Secret` header).
- `scripts/account.js` — client script to load profile and logout.
- Updated `login.js`, `index.html`, `account.html` to call the API.
- `everything.css` — merged login/account styles for consistent look.

## Where files are

- Project root: `c:\Users\carlo\OneDrive\Desktop\web project\Web group project`
- PHP API: `./api/*.php`
- Client script: `./scripts/account.js`
- SQLite DB (created on first run): `./data/database.sqlite`

## Quick local run (Windows PowerShell)

1. Open PowerShell and change directory to the project root:

```powershell
cd "c:\Users\carlo\OneDrive\Desktop\web project\Web group project"
```

2. Start PHP built-in server (dev only):

```powershell
php -S localhost:8000
```

3. Open the app in your browser:

- Home: `http://localhost:8000/`
- Login/Sign-up: `http://localhost:8000/login.html`
- Profile (after login): `http://localhost:8000/account.html`

Notes:

- The API uses PHP sessions. The browser will send the session cookie automatically when you call endpoints from the same origin (served by the built-in server).

## Example API calls (PowerShell/curl)

Register a user:

```powershell
curl -X POST "http://localhost:8000/api/register.php" -H "Content-Type: application/json" -d '{"name":"Alice","email":"alice@example.com","password":"secret","age":25}'
```

Login (will set session cookie in the browser when called from frontend). For curl testing (keeps cookies in a file):

```powershell
curl -c cookies.txt -X POST "http://localhost:8000/api/login.php" -H "Content-Type: application/json" -d '{"email":"alice@example.com","password":"secret"}'
```

Get current user (with cookie):

```powershell
curl -b cookies.txt "http://localhost:8000/api/me.php"
```

Logout:

```powershell
curl -b cookies.txt -X POST "http://localhost:8000/api/logout.php"
```

Get events near coordinates (example uses seeded event near London: lat=51.5074, lng=-0.1278):

```powershell
curl "http://localhost:8000/api/events.php?lat=51.5074&lng=-0.1278&radius=20"
```

Create an event (dev/admin) — requires `X-Admin-Secret` header. The default secret in `api/events.php` is `change-me-to-a-secure-value` — change it before production.

```powershell
curl -X POST "http://localhost:8000/api/events.php" -H "Content-Type: application/json" -H "X-Admin-Secret: change-me-to-a-secure-value" -d '{"title":"New Event","description":"Desc","location":"Park","lat":51.5,"lng":-0.12,"date":"2025-12-01","time":"18:00","price":5}'
```

## Client-side integration notes

- `login.js` uses `fetch('api/login.php', { credentials: 'include' })` so the session cookie is stored by the browser and used for subsequent calls like `api/me.php`.
- `index.html` uses `navigator.geolocation` to request the user's location and then calls `api/events.php?lat=...&lng=...&radius=...` to render nearby events.

## SQLite DB

- Location: `./data/database.sqlite` (created automatically when you first hit any API endpoint). If you need to inspect it, use DB Browser for SQLite or the `sqlite3` CLI.

Example quick inspect with `sqlite3` (if installed):

```powershell
sqlite3 .\data\database.sqlite
.tables
SELECT * FROM events;
SELECT * FROM users;
```

## Security & production notes

- This setup is for local development and demos only.
- Move secrets out of code (admin secret), use HTTPS, set secure cookie flags, and use prepared statements (already used) and input validation.
- For production consider a hardened stack (MySQL/Postgres, cloud-hosted DB, proper session storage, CSRF protection).

## Admin secret (config)

The event creation endpoint requires an admin secret sent in the `X-Admin-Secret` header. You can set this in two ways for local development:

- Recommended (temporary for current PowerShell session):

```powershell
$env:EVENTS_ADMIN_SECRET = 'your-strong-secret'
php -S localhost:8000
```

- Or edit the local file `api/config.php` (dev-only) and set the `'admin_secret'` value there. Do NOT commit secrets to a public repository.

When deployed to a server, set the `EVENTS_ADMIN_SECRET` environment variable in your hosting environment instead of editing files.

## Deployment options (simple)

Below are easy deployment options for a beginner. All assume a simple PHP + SQLite app.

1. Shared PHP hosting (easiest)

   - Upload the project files to your hosting provider via FTP or their file manager.
   - Ensure PHP version is >= 7.4 and SQLite is enabled (most providers support this).
   - Do NOT expose `dev/db-debug.php` publicly — remove or restrict it before uploading.
   - Set `EVENTS_ADMIN_SECRET` in your hosting control panel if available, or edit `api/config.php` (dev-only).

2. DigitalOcean App Platform or similar (recommended for small apps)

   - Create an App and point it at your GitHub repository (push the project to GitHub first).
   - Set the build/runtime to use PHP and set environment variable `EVENTS_ADMIN_SECRET` in the platform settings.
   - Use a managed DB (MySQL/Postgres) if you expect multi-user production traffic — SQLite is fine for small single-server apps but not for scaling.

3. VPS (DigitalOcean droplet / Linode / VPS)
   - Provision a small Ubuntu droplet, install PHP and a web server (nginx/apache), deploy files, and configure PHP-FPM.
   - Secure the server (firewall, HTTPS via Let's Encrypt) and set env vars in the service configuration.

### MySQL / Local development

The course requires MySQL for persistence. You can run MySQL locally via XAMPP or Docker. Below are quick setup options.

XAMPP (Windows)

- Download and install XAMPP (https://www.apachefriends.org/).
- Start Apache and MySQL from the XAMPP control panel.
- Create a database `events_db` using phpMyAdmin (http://localhost/phpmyadmin) or the MySQL CLI.

Docker (recommended reproducible dev environment)

Create a `docker-compose.yml` in your project root (example):

```yaml
version: "3.8"
services:
  db:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: example
      MYSQL_DATABASE: events_db
      MYSQL_USER: events_user
      MYSQL_PASSWORD: events_pass
    ports:
      - "3306:3306"
    volumes:
      - db_data:/var/lib/mysql

  php:
    image: php:8.1-cli
    working_dir: /var/www/html
    volumes:
      - ./:/var/www/html
    ports:
      - "8000:8000"
    depends_on:
      - db

volumes:
  db_data:
```

Start the stack with:

```bash
docker-compose up -d
```

Import the SQL schema into MySQL (example with CLI):

```bash
mysql -h 127.0.0.1 -P 3306 -u events_user -p events_db < api/schema.sql
```

Set environment variables for the app (example, PowerShell):

```powershell
$env:DB_DRIVER = 'mysql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3306'
$env:DB_NAME = 'events_db'
$env:DB_USER = 'events_user'
$env:DB_PASS = 'events_pass'
$env:EVENTS_ADMIN_SECRET = 'your-admin-secret'
php -S localhost:8000
```

The app will attempt to connect to MySQL when `DB_DRIVER` is set to `mysql` or when `DB_HOST` is present in the environment. If the MySQL connection fails, the code will fall back to SQLite for convenience during development.

Production checklist

- Remove or protect the `dev/` folder before going live.
- Use HTTPS and set secure session cookies.
- Use a stronger secret and keep it in environment variables, not in code.
- Consider migrating to a server DB (MySQL/Postgres) for multi-instance deployments.

## Next steps I can do for you

- Add a small admin UI to manage events.
- Harden authentication (CSRF protection, HttpOnly cookies, HTTPS guidance).
- Deploy instructions for a hosting provider (e.g., shared PHP host, DigitalOcean App Platform).

If you want, I can also add a simple debug page that lists DB rows (dev-only) so you can see seeded users/events in the browser.

---

## Research & References

This section documents useful sources consulted while building the project and serves as references for the technologies and best practices used.

- MDN Web Docs — HTML, CSS, JavaScript references and accessibility guidance.

  - APA: Mozilla Developer Network. (n.d.). MDN Web Docs. https://developer.mozilla.org/
  - IEEE: Mozilla Developer Network, "MDN Web Docs," Accessed 2025. [Online]. Available: https://developer.mozilla.org/

- OWASP — security guidance and input validation recommendations.

  - APA: OWASP Foundation. (n.d.). OWASP Top Ten. https://owasp.org/
  - IEEE: OWASP Foundation, "OWASP Top Ten," Accessed 2025. [Online]. Available: https://owasp.org/

- Geolocation and distance calculation (Haversine formula) references used for proximity filtering.
  - APA: Sinnott, R. W. (1984). Virtues of the Haversine. Sky and Telescope, 68(2), 159.
  - IEEE: R. W. Sinnott, "Virtues of the Haversine," Sky and Telescope, vol. 68, no. 2, p. 159, 1984.

Include any additional readings or papers your team used here and format them per your instructor's expectations (APA or IEEE).

## AI-use Disclosure

Some project content (for example, draft code snippets, suggestions for validation logic, and documentation text) was generated or assisted using AI tools during development. The team reviewed, tested, and adapted all AI-assisted outputs; final functionality and code review were performed by the project team.

If you used AI to write specific files or sections, list them here with brief notes on how the output was reviewed (e.g., "login.js: logic reviewed and tested; password handling verified server-side").

## Course Deliverables Checklist

- Backend: PHP API endpoints implemented (`api/*.php`) with server-side validation and structured JSON errors.
- Persistence: Local SQLite used for development; recommend migrating to MySQL for final submission (see TODOs in repository).
- Frontend: Responsive HTML/CSS/JS, accessible labels, and consolidated stylesheet `everything.css`.
- Research: References and AI disclosure included above.
- Demo: Prepare a 5–7 minute demo script (create `DEMO.md` if you want help writing it).
