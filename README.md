## AtoM Docker Setup (UNIL Lettres)
This repository provides a Docker-based setup for deploying [AtoM (Access to Memory)](https://www.accesstomemory.org), including UNIL Lettres customizations.

###What’s inside
- Docker Compose for:

	- PHP-FPM container with AtoM & UNIL Lettres theme
	- MySQL 8
	- Elasticsearch 6.8 OSS
	- Gearman job server
	- Memcached (defined but disabled by default)
	- nginx (included in dev and optional for Docker-based prod)

- Environment overrides:
	- `docker-compose.override.yml` for development
	- `docker-compose.prod.yml` for production deployments using prebuilt Docker Hub images	
- arLettresPlugin as a git submodule:
	- UNIL theme built at container image build time
	- Easily updatable via `git submodule update --remote`
	
- Production-ready Docker images published to:
	- `unillett/atom`
- Makefile with tasks for :
 - Container lifecycle (`up`, `down`, `restart`, `logs`)
 - Database import/export/status/reset
 - Uploads import/export
 - Full reinitialization (`make fresh-install`)
 - Elasticsearch indexing

###Installation & Deployment
####1. Clone the repository

```
git clone git@github.com:unil-lettres/atom.git
cd atom
git submodule update --init --recursive
```
To pull the latest version of the theme submodule:

```
git submodule update --recursive --remote
```

####2. Configure environment
Copy the example environment file and edit as needed:

```
cp .env.example .env
```

####3. Run AtoM
Run the development environment (default):

```	
docker compose up
```
Will auto-load docker-compose.yml, docker-compose.override.yml

Site available at http://localhost:8080

Run production:

```
docker compose -f docker-compose.yml -f docker-compose.prod.yml
```

License
MIT © UNIL-Lettres