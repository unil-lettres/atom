# AtoM Docker Configuration

This repository provides a Docker-based development and deployment setup for [AtoM (Access to Memory)](https://www.accesstomemory.org), enhanced with a `Makefile` for common admin tasks :

- **Docker Compose** configuration for:
  - PHP-FPM (with AtoM)
  - MySQL
  - Elasticsearch
  - Gearman
  - Memcached
  - nginx

- **Makefile** with tasks for:
  - Starting/stopping containers
  - Exporting/importing the database
  - Exporting/importing uploads/media
  - Viewing logs
  - Checking DB initialization
  - Forcing a fresh install of AtoM
  - Running post-import upgrade commands

## How to run

1. **Clone this repository:**

   ```bash
   git clone git@github.com:unil-lettres/atom.git
   cd atom
   ```

2. **Configure environment variables:**

   Copy the example `.env` file and edit values as needed:

   ```bash
   cp .env.example .env
   ```

   Sample `.env` values:

   ```env
   ATOM_VERSION=2.9.0
   SITE_TITLE=Access to Memory
   SITE_DESCRIPTION=AtoM archival platform
   SITE_URL=http://localhost:8080
   DB_HOST=mysql
   DB_NAME=atom
   DB_USER=atom
   DB_PASS=atompass
   ES_HOST=elasticsearch
   ES_PORT=9200
   GEARMAND_HOST=gearman
   GEARMAND_PORT=4730
   MEMCACHED_HOST=memcached
   MEMCACHED_PORT=11211
   ```

3. **Start the system:**

   ```bash
   make up
   ```

   AtoM should be accessible at [http://localhost:8080](http://localhost:8080)

---

## Makefile Commands

### System Control

| Command         | Description                                      |
|-----------------|--------------------------------------------------|
| `make up`       | Start all Docker containers                      |
| `make down`     | Stop and remove containers and networks          |
| `make restart`  | Rebuild and restart containers                   |
| `make logs`     | Follow logs of the entire stack                  |

### Database Management

| Command             | Description                                      |
|---------------------|--------------------------------------------------|
| `make db-export`    | Export MySQL DB to `atom_dump.sql`              |
| `make db-import`    | Import dump and run AtoM upgrade and indexing   |
| `make db-status`    | Check if AtoM DB is initialized                 |
| `make fresh-install`| Wipe DB and re-trigger tools:install           |

### Uploads Handling

| Command               | Description                                              |
|-----------------------|----------------------------------------------------------|
| `make uploads-export` | Archive uploads folder into `uploads_backup.tar.gz`     |
| `make uploads-import` | Restore uploads, fix ownership, and regenerate previews |

---

## 📄 License

This configuration is released under the MIT License.
>>>>>>> a3deaae (Initial commit: Add Docker config and Makefile for AtoM)
