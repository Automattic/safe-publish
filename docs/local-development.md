# Local Development

This repository includes tools for starting a local development environment using [`@wordpress/env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/), which requires Docker and Docker Compose. In addition, both `npm` and `composer` are required to install the local dependencies.

## Set up

Clone this repository and install its dependencies:.

```sh
npm install
```

To start a development environment with Xdebug enabled:

```sh
npm run dev
```

This will spin up two WordPress environments, build the block editor scripts, set the shared secrets, and watch for changes. 

The "destination" WordPress environment will be available at `http://localhost:8888` (admin user: `admin`, password: `password`). The non-prod site URL is automatically configured to `http://host.docker.internal:8889`.

The "source" WordPress environment will be available at `http://localhost:8889` (admin user: `admin`, password: `password`).

Stop the development environment with `Ctrl+C` and resume it by running the same command. You can also manually stop the environment with `npm run dev:stop`. Stopping the environment optionally stops the WordPress containers but preserves their state.

### Setting up authentication for testing

For local development, you'll need two WordPress sites to test import functionality:

1. **Source site** (non-production) - Where content comes from
2. **Destination site** (your dev environment) - Where content is imported to

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
npm run test:e2e
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
- **Node.js debugging port** for JavaScript debugging

**Enable WordPress debug mode:**

Add to `wp-config.php` in your local environment:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'SCRIPT_DEBUG', true );
```

**View debug logs:**
```sh
tail -f wp-content/debug.log
```

### Database Access

Access the MySQL database:

```sh
# Using WP-CLI
npm run wp-cli db query "SELECT * FROM wp_ccp_import_history LIMIT 10"

# Or connect directly
docker exec -it <container-id> mysql -u root -ppassword wordpress
```

### Common Development Tasks

**Reset plugin settings:**
```sh
npm run wp-cli option delete ccp_external_site_url
npm run wp-cli option delete ccp_number_of_posts
```

**Clear import history:**
```sh
npm run wp-cli db query "TRUNCATE TABLE wp_ccp_import_history"
```

**Test authentication:**
```sh
# Test connection to external site
curl -H "X-CCP-Secret: your-secret" https://staging.example.com/wp-json/wp/v2/posts
```

## Local playground

While not suitable for local development, it can sometimes be useful to quickly spin up a local WordPress playground:

```sh
npm run build # or `npm start` in a separate terminal
npm run playground
```

Playgrounds do not closely mirror production environments and are missing persistent object cache, debugging tools, and other important features. Use `npm run dev` for local development.

## Tips for Development

1. **Use two browser windows** - one for source site, one for destination
2. **Test with different post types** - posts, pages, custom types
3. **Test media import** - posts with multiple images
4. **Check Import History** - verify logging is working
5. **Monitor network requests** - use browser DevTools
6. **Test error conditions** - invalid URLs, auth failures, etc.

## Troubleshooting

See the [Troubleshooting Guide](troubleshooting.md) for more help.
