# Bioly development commands. Run "make" to see all of them.
# The whole flow is explained in docs/10-arbeitsablauf.md.

COMPOSE = docker compose
APP     = $(COMPOSE) exec -u www-data app
DATA    = data/app.sqlite data/app.sqlite-wal data/app.sqlite-shm data/config.php
TESTSITE = $(COMPOSE) --profile testsite

.DEFAULT_GOAL := help
.PHONY: help reset-demo reset-empty migrate check fix check-icons \
        zip testsite testsite-reset keys release

help: ## Show all commands
	@awk 'BEGIN {FS = ":.*## "} /^##@/ {printf "\n\033[1m%s\033[0m\n", substr($$0, 5)} /^[a-z-]+:.*## / {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

##@ Develop

reset-demo: ## Delete all data and create the demo content again
	rm -f $(DATA)
	rm -rf data/cache/*
	$(APP) php bin/seed.php

reset-empty: ## Delete all data, the installer starts on the next visit
	rm -f $(DATA)
	rm -rf data/cache/* data/uploads/*

migrate: ## Run new migrations without restarting the container
	$(APP) php bin/migrate.php

##@ Check (before every commit)

check: ## Everything the CI checks: code style, PHPStan, tests, icons, security audit
	$(COMPOSE) exec app composer check

fix: ## Fix the code style automatically (changes files)
	$(COMPOSE) exec app composer fix

check-icons: ## Check only the icon folders, e.g. after adding an icon
	$(COMPOSE) exec app composer check-icons

##@ Try an update

zip: ## Build a test zip in dist/ (not signed, VERSION and CHANGELOG stay as they are)
	$(COMPOSE) run --rm --entrypoint php app bin/release.php --test

testsite: ## Put the newest zip from dist/ (or ZIP=dist/...) into the test site http://localhost:8081, like an FTP upload
	$(TESTSITE) up -d --build testsite
	$(TESTSITE) exec testsite bioly-install-zip /dist/$(notdir $(or $(ZIP),$(lastword $(sort $(wildcard dist/bioly-*.zip)))))

testsite-reset: ## Empty the test site; the next make testsite installs from scratch
	$(TESTSITE) exec testsite sh -c 'rm -rf /var/www/html/* /var/www/html/.[!.]*'

##@ Release

keys: ## Create your signing key pair (only once, ever; asks twice)
	$(COMPOSE) run --rm --entrypoint php app bin/keys.php

release: ## Build the signed release zip, set VERSION and CHANGELOG (asks for the new version)
	$(COMPOSE) run --rm --entrypoint php app bin/release.php
