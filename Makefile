# Makefile for AtoM Docker Environment
# ------------------------------------
# Provides common commands to manage the system, including:
# - Starting/stopping containers
# - Exporting/importing DB dumps
# - Exporting/importing uploads/media
# - Viewing logs
# - Checking DB initialization
# - Forcing a fresh install of AtoM

include .env
export

# --------------------------------------------------------------------------
# CONFIGURATION
# --------------------------------------------------------------------------
DOCKER_COMPOSE := docker compose
MYSQL_CONTAINER := $(DB_HOST)
WEB_CONTAINER := atom-app


# --------------------------------------------------------------------------
# TARGETS
# --------------------------------------------------------------------------

.PHONY: help up down restart logs db-export db-import uploads-export uploads-import db-status fresh-install

## help: Show this help
help:
	@echo "Usage: make [target]"
	@echo ""
	@echo "Common Targets:"
	@echo "  up               Start all Docker containers (in background)"
	@echo "  down             Stop containers, remove them and networks"
	@echo "  restart          Recreate/Restart containers"
	@echo "  logs             Follow logs of entire stack"
	@echo "  db-export        Export MySQL database from the container to '$(DB_DUMP_FILE)'"
	@echo "  db-import        Import '$(DB_DUMP_FILE)' into the MySQL container"
	@echo "  uploads-export   Create a tarball of uploads ('$(UPLOADS_TAR)') from local folder"
	@echo "  uploads-import   Extract '$(UPLOADS_TAR)' into local uploads folder"
	@echo "  db-status        Check if the '$(DB_NAME)' DB is initialized (looking for 'object' table)"
	@echo "  fresh-install    Drop DB + remove symfony file -> triggers fresh AtoM install on restart"
	@echo "  help             Show this help message"

## up: Bring up containers in detached mode
up:
	$(DOCKER_COMPOSE) up -d

## down: Stop containers and remove them, plus networks
down:
	$(DOCKER_COMPOSE) down

## restart: Recreate containers (pull changes) and start fresh
restart:
	$(DOCKER_COMPOSE) down
	$(DOCKER_COMPOSE) pull
	$(DOCKER_COMPOSE) up -d --build

## logs: Follow logs for all containers
logs:
	$(DOCKER_COMPOSE) logs -f

## db-export: Dump DB from container's MySQL to a file on the host
db-export:
	@echo "Exporting DB '$(DB_NAME)' to '$(DB_DUMP_FILE)'..."
	$(DOCKER_COMPOSE) exec $(MYSQL_CONTAINER) \
		sh -c "mysqldump --no-tablespaces -u$(DB_USER) -p$(DB_PASS) $(DB_NAME) > /tmp/$(DB_DUMP_FILE)"
	$(DOCKER_COMPOSE) cp "$(MYSQL_CONTAINER):/tmp/$(DB_DUMP_FILE)" "$(DB_DUMP_FILE)"
	$(DOCKER_COMPOSE) exec $(MYSQL_CONTAINER) rm -f "/tmp/$(DB_DUMP_FILE)"
	@echo "Export complete."

## db-import: Load DB file $(DB_DUMP_FILE) into MySQL, then upgrade SQL and populate search index
db-import:
	@echo "Importing '$(DB_DUMP_FILE)' into DB '$(DB_NAME)'..."
	$(DOCKER_COMPOSE) cp "$(DB_DUMP_FILE)" "$(MYSQL_CONTAINER):/tmp/$(DB_DUMP_FILE)"
	$(DOCKER_COMPOSE) exec $(MYSQL_CONTAINER) \
		sh -c "mysql -u$(DB_USER) -p$(DB_PASS) $(DB_NAME) < /tmp/$(DB_DUMP_FILE)"
	$(DOCKER_COMPOSE) exec $(MYSQL_CONTAINER) rm -f /tmp/$(DB_DUMP_FILE)
	@echo "Import complete."
	@echo "Running tools:upgrade-sql..."
	$(DOCKER_COMPOSE) exec $(WEB_CONTAINER) php symfony tools:upgrade-sql
	@echo "Repopulating Elasticsearch index..."
	$(DOCKER_COMPOSE) exec $(WEB_CONTAINER) php symfony search:populate
	@echo "Done."

## uploads-export: Tar up local watched/uploads to '$(UPLOADS_TAR)'
uploads-export:
	@echo "Creating tarball '$(UPLOADS_TAR)' from '$(UPLOADS_HOST_DIR)'..."
	tar -czf $(UPLOADS_TAR) -C $(UPLOADS_HOST_DIR) .
	@echo "Uploads export complete."

## uploads-import: Extract '$(UPLOADS_TAR)' into local watched/uploads folder, fix permissions, and regenerate derivatives
uploads-import:
	@echo "Extracting '$(UPLOADS_TAR)' into '$(UPLOADS_HOST_DIR)'..."
	mkdir -p $(UPLOADS_HOST_DIR)
	tar -xzf $(UPLOADS_TAR) -C $(UPLOADS_HOST_DIR)
	@echo "Uploads import complete."
	@echo "Fixing ownership to www-data..."
	$(DOCKER_COMPOSE) exec $(WEB_CONTAINER) chown -R www-data:www-data /usr/share/nginx/atom/web/uploads
	@echo "Regenerating digital object derivatives..."
	$(DOCKER_COMPOSE) exec $(WEB_CONTAINER) php symfony digitalobject:regen-derivatives
	@echo "Derivatives regeneration complete."

## db-status: Check if DB 'object' table exists -> indicates if AtoM is initialized
db-status:
	@echo "Checking if DB '$(DB_NAME)' is initialized (looking for table 'object')..."
	@$(DOCKER_COMPOSE) exec $(MYSQL_CONTAINER) \
	  sh -c "mysql -u$(DB_USER) -p$(DB_PASS) -D$(DB_NAME) -e 'SHOW TABLES LIKE \"object\";' | grep -q 'object' \
	    && echo 'DB is initialized (object table found).' \
	    || echo 'DB is NOT initialized (no object table found).'"

## fresh-install: Force a fresh AtoM install by dropping the DB & removing 'symfony' so entrypoint re-runs tools:install
fresh-install:
	@echo "WARNING: This will ERASE the existing '$(DB_NAME)' database and remove /usr/share/nginx/atom/symfony!"
	@read -p 'Are you sure you want to proceed? (y/N) ' confirm && [ $${confirm:-N} = y ]
	@echo "Dropping and recreating DB '$(DB_NAME)'..."
	$(DOCKER_COMPOSE) exec $(MYSQL_CONTAINER) \
	  sh -c "mysql -u$(DB_USER) -p$(DB_PASS) -e 'DROP DATABASE IF EXISTS \`$(DB_NAME)\`; CREATE DATABASE \`$(DB_NAME)\`; '"
	@echo "Removing symfony file so next container start triggers fresh install..."
	$(DOCKER_COMPOSE) exec $(WEB_CONTAINER) rm -f /usr/share/nginx/atom/symfony
	@echo "Now run 'docker compose restart $(WEB_CONTAINER)' or 'make up' again to initiate a fresh install."
