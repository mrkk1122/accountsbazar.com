# accountsbazar.com deployment and SQL connection setup

This repository currently deploys files to cPanel hosting using GitHub Actions FTP deployment.

## 1) Create database in cPanel

1. Open **cPanel → MySQL Databases**.
2. Create a database (example: `accountsbazar_db`).
3. Create a database user.
4. Add the user to the database with **ALL PRIVILEGES**.

## 2) Collect SQL connection values

Collect these values from hosting:

- `DB_HOST` (often `localhost`)
- `DB_PORT` (often `3306`)
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

## 3) Keep credentials out of repository

Do not commit SQL credentials to this repository.

If deployment needs runtime variables, configure them in:

- cPanel environment/server-side config, or
- GitHub repository secrets (for deployment-time generated config)

## 4) GitHub secrets used by current workflow

Set these in **GitHub → Settings → Secrets and variables → Actions**:

- `FTP_SERVER`
- `FTP_USERNAME`
- `FTP_PASSWORD`

## 5) Test DB connection after deploy

1. Deploy from `main` (or run workflow manually).
2. Open the hosted app.
3. Verify the app can connect to MySQL and run queries.

If MySQL is external (not local cPanel DB), allow the hosting server IP in your DB firewall / remote MySQL allowlist.
