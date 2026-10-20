# Thin wrapper over the Composer scripts in api/composer.json, which do the real work.
# Every recipe line is a single plain command, so it runs the same whether GNU Make uses
# cmd.exe or sh (Windows) or sh (Ubuntu). Run from the repository root.

COMPOSER ?= composer
PHP ?= php
API := api

.PHONY: init check test-guards acceptance

## init: install Composer dependencies in api/ and copy .githooks/* into git's hooks dir
init:
	$(COMPOSER) --working-dir=$(API) install --no-interaction --no-progress
	$(PHP) $(API)/bin/install-hooks.php

## check: the whole gate (pre-push hook and CI run this)
check:
	$(COMPOSER) --working-dir=$(API) validate --strict
	$(COMPOSER) --working-dir=$(API) check
	$(COMPOSER) --working-dir=$(API) test-guards

## test-guards: PHPUnit guard tests that drive the git hooks through sh
test-guards:
	$(COMPOSER) --working-dir=$(API) test-guards

## acceptance TEST=<path>: run PHPUnit on one test path (relative to the repo root or to api/)
acceptance:
	$(if $(strip $(TEST)),,$(error Usage: make acceptance TEST=api/tests/Unit/SmokeTest.php))
	$(COMPOSER) --working-dir=$(API) acceptance -- $(patsubst $(API)/%,%,$(subst \,/,$(strip $(TEST))))
