# Local Development

This repository includes tools for starting a local development environment using [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/), which requires Docker and Docker Compose. Node.js 24, npm, and Composer are required to install the local dependencies. The repository's `.nvmrc` selects Node.js 24 when using nvm.

## Set up

### Hosts file

Both sites are served on the `host.docker.internal` hostname so each container reaches the other at the same address WordPress emits in its URLs. Docker Desktop resolves the name inside containers; the host machine doesn't by default. Add a one-line entry so your browser can reach either site at the same URL the containers use internally:

```sh
echo "127.0.0.1 host.docker.internal" | sudo tee -a /etc/hosts
```

Without this, browsing either site from your machine fails, and source URLs baked into imported content (image src, links) won't resolve when previewing posts in the destination admin.

### Install and start

Clone this repository and install its dependencies:

```sh
npm install
```

To start a development environment with Xdebug enabled:

```sh
npm run dev
```

This will spin up two WordPress environments, build the block editor scripts, inject the shared secret configured in `.wp-env.json` into each site's `wp-config.php`, and watch for changes.

Both WordPress environments are served on `host.docker.internal` (admin user: `admin`, password: `password`):

- Destination: `http://host.docker.internal:8888`
- Source: `http://host.docker.internal:8889`

The same hostname is used from your browser and from inside either container, so cross-container HTTP calls and HMAC validation both line up against the same canonical URL.

### How the two-site environment works

`wp-env` normally creates a development environment and a test environment. Safe Publish uses both as regular WordPress sites so the complete transfer can be exercised locally:

| Role        | `wp-env` environment | CLI target  | Default port | Sync mode |
| ----------- | -------------------- | ----------- | ------------ | --------- |
| Destination | Development          | `cli`       | 8888         | Import    |
| Source      | Tests                | `tests-cli` | 8889         | Export    |

Starting the environment runs the `afterStart` lifecycle script configured in `.wp-env.json`. Its `config` entries inject the shared authentication secret into both sites as a `wp-config.php` constant. The `bin/setup-env` script assigns each site's sync mode, connects the sites to each other, and configures their canonical URLs and permalinks.

Commands such as `npm run seed:full` use wrapper scripts that select the appropriate CLI target. Source seeding runs against `tests-cli`, while destination seeding runs against `cli`. You do not need to start or configure a second `wp-env` process manually.

Stop the development environment with `Ctrl+C` and resume it by running the same command. You can also manually stop the environment with `npm run dev:stop`. Stopping the environment optionally stops the WordPress containers but preserves their state.

### Worktrees

To run additional checkouts (e.g. `git worktree` siblings) alongside the main one, each worktree's wp-env needs its own port pair so it doesn't collide with other running wp-envs. From the worktree, run:

```sh
bin/setup-worktree
```

The script installs dependencies, assigns the next free `WP_ENV_PORT` and `WP_ENV_TESTS_PORT` pair (8890/8891 for the first worktree, 8892/8893 for the next, and so on), and records the pair in `.devports`. Start the environment normally; the development scripts load those ports automatically:

```sh
npm run dev
```

When you're done with a worktree, tear it down so containers and volumes don't orphan:

```sh
# From inside the worktree:
npm run dev:destroy

# Then from another worktree (e.g. the main checkout):
git worktree remove ../<worktree-dir>
```

Skipping `dev:destroy` before removing leaves wp-env containers and volumes behind, identifiable via `docker ps -a` and `docker volume ls`.

### Setting up authentication for testing

For local development, you'll need two WordPress sites to test import functionality:

1. **Source site** (non-production) - Where content comes from
2. **Destination site** (your dev environment) - Where content is imported to

### Seeding test content

To populate the source or destination site with realistic test content for manual testing or import verification, see [Content Seeding](content-seeding.md).

To seed the Needs attention tab for UI testing, see [Seeding Import Degradations](seeding-degradations.md).

### Code Quality

Before committing, validate and fix code quality:

```sh
# Check all code (linting, formatting, types)
npm run check

# Auto-fix all fixable issues
npm run fix
```

The pre-commit hook automatically runs linting and formatting on staged files, auto-fixing what it can and blocking commits with unfixable errors.

### Testing

Run unit tests:

```sh
# all unit tests
npm run test

# only JavaScript unit tests
npm run test:js

# only PHP unit tests
npm run test:php

# only a specific test file
npm run test:js some/test/file.js
npm run test:php -- --filter SomeTestClass
```

For e2e tests, ensure the development environment is running, then execute:

```sh
WP_BASE_URL=http://host.docker.internal:8888 npm run test:e2e
```

### Logs

Watch logs from the WordPress container:

```sh
npx wp-env logs
```

### WP-CLI

Run WP-CLI commands:

```sh
npm run wp-cli option get siteurl
```

### Destroy

Destroy your local environment and irreversibly delete all content, configuration, and data:

```sh
npm run dev:destroy
```

### Debugging

The development environment includes:

- **Xdebug** for PHP debugging (port 9003)

**Enable WordPress debug mode:**

Create the git-ignored `.wp-env.override.json` file in the repository root so the settings apply inside the wp-env containers:

```json
{
	"config": {
		"WP_DEBUG": true,
		"WP_DEBUG_LOG": true,
		"WP_DEBUG_DISPLAY": false,
		"SCRIPT_DEBUG": true
	},
	"env": {
		"tests": {
			"config": {
				"WP_DEBUG": true,
				"WP_DEBUG_LOG": true,
				"WP_DEBUG_DISPLAY": false,
				"SCRIPT_DEBUG": true
			}
		}
	}
}
```

Restart the environment after changing the override.

**View debug logs:**

```sh
# Destination site
npx wp-env run cli -- tail -f /var/www/html/wp-content/debug.log

# Source site
npx wp-env run tests-cli -- tail -f /var/www/html/wp-content/debug.log
```

**VSCode users:** You can rename `.vscode/launch.json.example` to `.vscode/launch.json` to enable Xdebug debugging in the editor.

### Database Access

Access the MySQL database:

```sh
# View import sessions
npm run wp-cli db query "SELECT * FROM wp_safe_publish_imports LIMIT 10"

# View import items
npm run wp-cli db query "SELECT * FROM wp_safe_publish_import_items LIMIT 10"

# View audit log events
npm run wp-cli db query "SELECT * FROM wp_safe_publish_audit_log LIMIT 10"

# Or connect directly
docker exec -it <container-id> mysql -u root -ppassword wordpress
```

### Common Development Tasks

**Reset plugin settings or clear import history:**

See the [Troubleshooting guide](troubleshooting.md#resetting-configuration).

**Test authentication:**

Use the **Test Connection** button on the settings page.

## Local playground

While not suitable for local development, it can sometimes be useful to quickly spin up a local WordPress playground:

```sh
npm run build # or `npm start` in a separate terminal
npm run playground
```

Playgrounds do not closely mirror production environments and are missing persistent object cache, debugging tools, and other important features. Use `npm run dev` for local development.

## Tips for Development

1. **Use two browser windows** - one for source site, one for destination.
2. **Test with different post types** - posts, pages, custom types.
3. **Test media import** - posts with multiple images.
4. **Check the Manage page** - verify the Posts and Needs attention tabs reflect each attempt.
5. **Monitor network requests** - use browser DevTools.
6. **Test error conditions** - invalid URLs, auth failures, etc.

## Troubleshooting

See the [Troubleshooting Guide](troubleshooting.md) for more help.
