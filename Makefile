# Everything runs in Docker (docker-compose.dev.yml); only make and Docker are
# needed on the host.
#
#   make check                      lint + analyse + test, the local gate
#   make test ARGS="--filter=Slots"  extra arguments are passed to Pest
#   make test PHP_VERSION=8.4       any target on another PHP version
#   make matrix                     the test suite on every supported PHP version

PHP_VERSION ?= 8.5
PHP_VERSIONS := 8.4 8.5

export PHP_VERSION
export DOCKER_UID := $(shell id -u)
export DOCKER_GID := $(shell id -g)

DC = docker compose -f docker-compose.dev.yml
RUN = $(DC) run --rm app

build:
	$(DC) build app

install:
	$(RUN) composer install

vendor/autoload.php: composer.json
	$(RUN) composer install
	@touch $@

lint: vendor/autoload.php
	$(RUN) composer lint

format: vendor/autoload.php
	$(RUN) composer format

analyse: vendor/autoload.php
	$(RUN) composer analyse

test: vendor/autoload.php
	$(RUN) composer test -- $(ARGS)

coverage: vendor/autoload.php
	$(RUN) composer coverage

check: lint analyse test

# Each PHP version resolves its own dependencies into build/matrix/<version>,
# mounted over vendor/, so the host's vendor/ is left untouched.
matrix:
	@set -e; for version in $(PHP_VERSIONS); do \
		echo "==> PHP $$version"; \
		mkdir -p build/matrix/$$version; \
		PHP_VERSION=$$version $(DC) run --rm --build \
			--volume "$(CURDIR)/build/matrix/$$version:/app/vendor" app \
			sh -c 'composer update --no-interaction --no-progress --quiet && composer test'; \
	done

secrets:
	$(DC) run --rm gitleaks git --no-banner --redact --verbose .

shell:
	$(RUN) sh

down:
	$(DC) down --remove-orphans

.PHONY: build install lint format analyse test coverage check matrix secrets shell down
