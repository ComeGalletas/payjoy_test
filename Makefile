# Thin wrapper over the Composer scripts in api/composer.json, which do the real work.
# Every recipe line is a single plain command, so it runs the same whether GNU Make uses
# cmd.exe or sh (Windows) or sh (Ubuntu). Run from the repository root.

# Not called COMPOSER: Composer reads a COMPOSER environment variable as the composer.json name,
# and make exports command-line variables to recipes.
# On Windows the recipe below with quotes runs through sh when sh is on PATH, and sh can't find
# a bare `composer` when only composer.bat is installed, so name the .bat there. Override with
# `make COMPOSER_BIN=...` if your Composer is installed differently.
ifeq ($(OS),Windows_NT)
COMPOSER_BIN ?= composer.bat
else
COMPOSER_BIN ?= composer
endif
PHP ?= php
API := api

# TEST with backslashes turned into slashes and a leading ./ and api/ removed, so the path is
# relative to api/. Make splits on spaces, so runs of spaces inside a path collapse to one.
TEST_PATH = $(patsubst $(API)/%,%,$(patsubst ./%,%,$(subst \,/,$(strip $(TEST)))))

.PHONY: init check test-guards acceptance

## init [FORCE=1]: install Composer dependencies in api/, then install the git hooks (main
## checkout) or only verify them (linked worktree). FORCE=1 replaces hooks this project
## did not install, keeping them as <name>.bak.<YYYYMMDD-HHMMSS>.
init:
	$(COMPOSER_BIN) --working-dir=$(API) install --no-interaction --no-progress
	$(PHP) $(API)/bin/install-hooks.php $(if $(FORCE),--force)

## check: the whole gate (pre-push hook and CI run this)
check:
	$(COMPOSER_BIN) --working-dir=$(API) validate --strict
	$(COMPOSER_BIN) --working-dir=$(API) check
	$(COMPOSER_BIN) --working-dir=$(API) test-guards

## test-guards: PHPUnit guard tests for the git hooks and the hook installer
test-guards:
	$(COMPOSER_BIN) --working-dir=$(API) test-guards

## acceptance TEST=<path>: run PHPUnit on one test path (relative to the repo root or to api/)
acceptance:
	$(if $(strip $(TEST)),,$(error Usage: make acceptance TEST=api/tests/Unit/SmokeTest.php))
	$(COMPOSER_BIN) --working-dir=$(API) acceptance -- "$(TEST_PATH)"
