Event Finder — Deployment Guide

This document summarizes recommended deployment options for the Event Finder project (PHP + MySQL). It assumes the repository contains the current codebase and `api/schema.sql`.

Security note

- Do not deploy the `dev/` folder to production. Remove or protect `dev/db-debug.php`, `dev/test-api.ps1`, and `dev/mysql-setup.ps1` before publishing a public site.
- Always use HTTPS. Set secure cookie flags and HttpOnly on session cookies in production.
- Set `EVENTS_ADMIN_SECRET` environment variable; never commit secrets to source control.

1. DigitalOcean App Platform (quick, managed)

- Create a GitHub repo and push the project.
- On DigitalOcean, create an App and connect it to your repository and branch.
- Set the runtime/build to a PHP environment (no build step required for this simple app).
- Create a Managed Database (MySQL). Note connection details (host, port, db, user, password).
- In App Platform settings, add environment variables:
  - `DB_DRIVER = mysql`
  - `DB_HOST = <managed-db-host>`
  - `DB_PORT = <port>`
  - `DB_NAME = <db-name>`
  - `DB_USER = <user>`
  - `DB_PASS = <password>`
  - `EVENTS_ADMIN_SECRET = <strong-secret>`
- Use `api/schema.sql` to initialize the database (use MySQL client or the DigitalOcean console to run the SQL).
- Deploy the app and verify logs. Enable HTTPS and review firewall rules.

2. Shared PHP hosting / cPanel

- Ensure host supports PHP >= 7.4 and MySQL.
- Upload project files (FTP or file manager). Place files in `public_html` or appropriate web root.
- Create a MySQL database and user via cPanel -> MySQL Databases; note credentials.
- Import `api/schema.sql` using phpMyAdmin (Import tab) or the MySQL CLI if available.
- Set environment variables:
  - cPanel doesn't always support env vars; if not available, set the values in `api/config.php` temporarily (dev only). Prefer using your host's environment variable or `.user.ini` if supported.
- Remove or protect `dev/` folder before going live.

3. Docker deployment (self-hosted / VPS)

- Use the `docker-compose.yml` example in the README or create one similar to it:

  - Service `db`: `mysql:8.0` and environment variables to provision `events_db`.
  - Service `php`: use a PHP image and map project files; run `php -S 0.0.0.0:8000` or set up `nginx` + `php-fpm` for production.

- After starting the stack, import `api/schema.sql` into MySQL and set env vars for the PHP container (or pass them in via `docker-compose.yml` environment section).

4. VPS (nginx + PHP-FPM)

- Provision a Linux server (Ubuntu 22.04 LTS recommended).
- Install Nginx, PHP-FPM (8.1+), MySQL server.
- Place the project in `/var/www/eventfinder` and configure Nginx site to point to that directory.
- Configure PHP-FPM pool to run under a dedicated user.
- Secure the server: firewall (ufw), disable root SSH password, add SSH key login, install certbot and enable HTTPS.
- Create MySQL database and import `api/schema.sql`.
- Set environment variables in systemd unit or `www.conf` or use a `.env` loader (do not commit .env to repo).

Essential post-deploy checklist

- Remove `dev/` and any debug endpoints.
- Verify `EVENTS_ADMIN_SECRET` is set and not default.
- Use HTTPS and set secure cookie flags (update session handling to set cookie params in production).
- Monitor logs for errors and set up basic backups for your MySQL database.

Troubleshooting

- DB connection errors: check `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASS`, and network/firewall rules.
- If you see "MySQL connection failed" in logs but still serve pages, the app may have fallen back to SQLite (dev behavior) — ensure env vars are correct to force MySQL.

If you'd like, I can:

- Add a `DEPLOY_GHACTIONS.yml` GitHub Actions workflow to build and deploy to DigitalOcean.
- Add a small `cleanup-dev.sh` script to remove or rename the `dev/` folder before publishing.
