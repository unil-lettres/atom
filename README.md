## AtoM Docker Configuration
This repository provides a Docker-based development and deployment setup for AtoM (Access to Memory), enhanced with a Makefile for common admin tasks.

###What’s inside
- Docker Compose for:

	- PHP-FPM (with AtoM & UNIL Lettres theme)
	- MySQL
	- Elasticsearch
	- Gearman
	- Memcached (disabled at this time)
	- nginx (dev config only)

- arLettresPlugin included as a git submodule (UNIL Lettres theme)

- Makefile with tasks for :
 - Container lifecycle (up, down, restart, logs)
 - DB export/import & status
 - Uploads export/import
 - Fresh install & post-import upgrades

###Installation
1. Clone the repo (with submodules)
	
	git clone git@github.com:unil-lettres/atom.git

	cd atom
	
	git submodule update --init --recursive

	Tip: After pulling, run
	
	git submodule update --recursive --remote

	if the theme repo has moved forward upstream.

2. Configure environment

	cp .env.example .env

	Edit .env values as needed:

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

3. Dev vs. Prod workflows

	3.1 Development (default)
	
	*docker compose up* auto-loads :

		docker-compose.yml

		docker-compose.override.yml

	(Your dev overrides: local build, mounts, nginx.dev.conf.)

	Use:
	
	make up
	
	—or—
	
	docker compose up -d --build

	The site will be at http://localhost:8080

	3.2 Production
	
	Rename or keep your prod file as, e.g., docker-compose.prod.yml.
	
	Then run:

	docker compose -f docker-compose.yml -f docker-compose.prod.yml
up -d --build

	This uses your prod override (images from registry, named volumes, restart policy).

4. Makefile commands

	4.1 System control
	
		make up → build & start (dev)

		make down → stop & remove containers & network

		make restart → teardown & rebuild

		make logs → tail all logs

	4.2 Database

		make db-export → dump MySQL to atom_dump.sql

		make db-import → import dump + run upgrade + indexing

		make db-status → check if ‘object’ table exists

		make fresh-install → wipe DB & re-run tools:install

	4.3 Uploads & media

		make uploads-export → archive uploads to uploads_backup.tar.gz

		make uploads-import → restore uploads, fix perms, regenerate previews

5. UNIL Lettres theme (arLettresPlugin)

	- Lives as a git submodule in plugins/arLettresPlugin
	- Compiled via LESS at container build time
	- Registered as a Symfony plugin so it appears under Admin → Themes
	- To update to latest upstream theme:
		- git submodule update --remote plugins/arLettresPlugin

License
MIT © UNIL-Lettres