# Drupal AI

A Drupal 11 project, pre-configured and ready to start.

## Requirements

- [Docker Desktop](https://www.docker.com/products/docker-desktop/)
- [DDEV](https://ddev.com/get-started/)

## Quick start

```bash
git clone <repo-url>
cd drupal-ai
ddev start
ddev import-db --file=db-init/init.sql.gz
ddev launch
```

Log in with **admin / admin**.

## Stopping the project

```bash
ddev stop
```

## Resetting to a clean state

```bash
ddev delete -O
ddev start
ddev import-db --file=db-init/init.sql.gz
```

## Services

| Service | URL |
|---|---|
| Drupal | https://drupal-ai.ddev.site |
| Mailpit | https://drupal-ai.ddev.site:8026 |
