# Drupal AI

A Drupal 11 project running in Docker, pre-configured and ready to start.

## Requirements

- [Docker Desktop](https://www.docker.com/products/docker-desktop/)

## Quick start

```bash
git clone <repo-url>
cd drupal-ai
docker compose up -d
```

Then open [http://localhost:8080](http://localhost:8080) in your browser.

The database is seeded automatically on first startup — no installer steps required.

## Stopping the project

```bash
docker compose down
```

## Resetting to a clean state

If you need to wipe the database and start fresh:

```bash
docker compose down -v
docker compose up -d
```

The `-v` flag removes the database volume so it will be re-seeded from `db-init/init.sql` on next startup.

## Services

| Service | URL / Port |
|---|---|
| Drupal | http://localhost:8080 |
| PostgreSQL | localhost:5432 |
