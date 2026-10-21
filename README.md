# YIELD — Poultry Farm Operations and Accounting Management System

YIELD is a PHP + MySQL platform for a poultry farm, made up of four
independent systems that share one login page and one database.

## Requirements

- PHP 8.1 or newer (developed against PHP 8.3 on WAMP; uses `declare(strict_types=1)`,
  typed properties, and `match` — nothing newer than 8.1 needs).
- MySQL 8.0+ or MariaDB 10.4+ (uses `GENERATED ALWAYS AS ... STORED` columns).
- Apache with `mod_rewrite` not required; `.htaccess` here is only used to
  block direct access to uploaded files, via `Require all denied`
  (Apache 2.4 syntax).
- Any Apache + PHP + MySQL stack works (WAMP, XAMPP, MAMP, a bare LAMP box).
  This project was built and tested on WAMP64 with Apache + PHP 8.3 + MySQL 8.4.

## Offline use

The system runs fully offline, with no dependency on any CDN or external
network access at runtime. Bootstrap, Bootstrap Icons, and Chart.js are
vendored locally under `shared/vendor/` (alongside the existing FPDF library)
and loaded from there, rather than from `cdn.jsdelivr.net`. A fresh clone of
this repo, once the database steps below are done, works with no internet
connection at all.

## Setup

1. Clone the repo into your server's web root (e.g. `C:\wamp64\www\SIA2` on
   WAMP, so the app is reachable at `http://localhost/SIA2/`).
2. Create the database and import the schema, then the starter seed data:
   ```
   mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS sia2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p sia2 < database/schema.sql
   mysql -u root -p sia2 < database/seed.sql
   ```
3. Run the numbered migrations in `database/migrations/`, in order, if you
   are upgrading an older copy of this database that predates `schema.sql`.
   A brand-new database created from `schema.sql` already has everything
   those migrations add, so this step is **not** needed on a fresh install —
   only when bringing an existing `sia2` database up to date.
   ```
   for f in database/migrations/*.sql; do mysql -u root -p sia2 < "$f"; done
   ```
4. Create the four initial user accounts (one per system) by running the
   setup script from the command line. It refuses to run any other way:
   ```
   php database/setup/provision_accounts.php
   ```
   This prints each generated username/password **once**, to the terminal
   only — nothing is written to a file or logged anywhere. Write them down
   immediately; there is no way to recover them afterward (see below).
5. (Optional) Populate PMS/AIS/Integrated with demo data for a
   walkthrough/demo, using the files in `database/seed/`:
   ```
   mysql -u root -p sia2 < database/seed/pms_demo_data.sql
   mysql -u root -p sia2 < database/seed/ais_demo_data.sql
   mysql -u root -p sia2 < database/seed/integrated_demo_data.sql
   ```
6. Open `http://localhost/SIA2/` (adjust the path/port to your server). The
   landing page's three system cards and the flower-icon link in the top
   left (User Management) will take you to the shared login.

If you ever lose a generated password, there is no recovery path — the
database only ever stores a bcrypt hash. An existing IT Administrator can
reset any account's password from **User Management → Edit User**; if no
account can sign in at all, re-run `provision_accounts.php` after deleting
the affected row from the `users` table (it only creates accounts that don't
already exist).

## Database configuration

`shared/config/database.php` reads these environment variables, all
optional (each has a working default for a stock local WAMP/XAMPP install):

| Variable      | Default     |
|---------------|-------------|
| `DB_HOST`     | `127.0.0.1` |
| `DB_PORT`     | `3306`      |
| `DB_NAME`     | `sia2`      |
| `DB_USER`     | `root`      |
| `DB_PASSWORD` | *(empty)*   |

## The four systems

| System | URL prefix | Role (`users.role`) | What they do there | `system_access` value |
|---|---|---|---|---|
| Poultry Management System (PMS) | `/pms/` | Poultry Admin | flocks, inventory, activities, production, health | `pms` |
| Accounting Information System (AIS) | `/ais/` | Accounting Admin | transactions, receivables/payables, financial reports | `ais` |
| Integrated System | `/integrated/` | Manager | batch costing, cost efficiency, replenishment, scenario analysis across both PMS and AIS data (read-only against both; never writes to either) | `integrated` |
| User Management | `/shared/user-management/` | IT Administrator | create/edit/deactivate/delete accounts for the other three systems, review the audit trail | `user_management` |

Every account is authorized for exactly one of these four. Signing in checks
the username, the password, **and** that the account's `system_access`
matches the system you're signing in to — the same login page serves all
four, but a correct password for the wrong system is refused just like a
wrong password would be.

## Note on `database/legacy/`

`database/legacy/poultry_farm_system.sql` is the schema for an older,
unrelated database (`poultry_farm_system`) from an earlier iteration of this
project. It is kept for reference only — this application exclusively uses
the `sia2` database described above.
