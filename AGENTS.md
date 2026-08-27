# Global guidelines

## LLM behavior

- Analyze and verify human input before agreeing with it. Prioritize truth over agreement.
- Never provide answers based on unverified or vague assumptions.
- Focus on being helpful and accurate. If uncertain about something, ask clarifying questions.
- Read any provided instruction files in their entirety.

## Writing instructions

- Use US English spelling. Common pitfalls: "behavior" (not "behaviour"), "organize" (not "organise"), "color" (not "colour"), "center" (not "centre"), "license" (not "licence"), "authorization" (not "authorisation"), "recognize" (not "recognise").
- The possessive form of WordPress is WordPress'.

## Files

- Use `trash` instead of `rm` when deleting files or folders. No flags needed for directories.

## Customer data

- Beware when working on private tickets, or any information that contains customer data.
- Never publish customer-identifying data — names, hostnames, URLs, account/site IDs, emails, or environment specifics — to the GitHub repo. This covers code and test fixtures, comments, commit messages, branch names, and PR/issue titles, descriptions, and comments.
- When reproducing a customer-reported bug, replace any customer-identifying values from the report with neutral placeholders (e.g. `example.com`, `/blog`) before committing. Real values can stay in a private tracker.
- If such data is pushed by mistake, treat it as a disclosure incident: notify the team, then scrub it from history and force-push — and note that on a public repo the force-pushed commit stays reachable until purged via GitHub Support.

## Code

- Write concise code.
- Don't offer over-engineered solutions, keep architecture as simple as possible.
- For accessibility, adhere to WCAG 2.2 Level AA standards or higher whenever possible.
- Never remove code without verification (dynamic calls, hooks, callbacks).
- When applying changes, carefully analyze whether:
  - The change could be breaking desired functionality.
  - Any related documentation files need updating.
- Wrap code, comments and docblocks at 80 characters; never wrap them unnecessarily early. `@param`/`@return` descriptions starting beyond column 40 can extend to 100 characters. Line length is measured in display characters, with tabs counting as 4. Markdown prose is exempt; don't hand-wrap it, `npm run format` joins it. GitHub alerts are the one place a line break matters: put a quoted blank line between the marker and its content.

### Comments and docblocks

These apply to comments in every language, not just PHP.

- Write short and to the point comments; lengthy comments allowed only when they provide value.
- Adhere to WordPress inline documentation standards.
- Docblock summaries are plain prose; no backticks or Markdown.
- In comments, capitalize the first word after a colon only when it begins prose — not a code reference such as an identifier or function name (`()` optional, camelCase included), a tag, attribute, slug, or enum value, a quoted string, URL, type shape, or literal. Apply this at every colon followed by a space (not `10:30` or `https://`); for a list, judge by the items — `image, audio, or video` stays lowercase, `Scheme and host only` capitalizes.

### PHP

- Verify PHP files use strict typing, and use type hinting everywhere possible.
- Prefer using `_` instead of `@psalm-suppress PossiblyUnusedParam`.
- Use explicit checks — don't use `empty()`, and don't coerce values with `!`. Reserve `!` for booleans and predicates; write an explicit comparison instead (`0 === $id`, `null === $post`), and for a multi-falsy union a type test (`! ( $result instanceof WP_Post )`, not `! $result`).

## Tests

- Begin test docblocks with "Verifies that".
- Implement tight assertions, prefer using `assertSame()` over `assertEquals()`.
- Structure test bodies with `// ARRANGE:`, `// ACT:`, and `// ASSERT:` comments, with a short description.
- When creating tests, temporarily mutate them to verify they fail when they should.
- When adding or hoisting shared setup, delete it and re-run: if nothing fails, no test depends on it. Have a positive-asserting test depend on it — denial assertions (403, 404, empty result) can pass for the wrong reason.

## PRs

- Keep PR and branch titles short and as identical as possible.
- Keep PR descriptions short, focusing on decisions instead of small technical details; don't add any line wrapping.
- Before creating a PR, ensure all tests pass by running `npm run test` and `npm run test:integration`.

## Code-review skill

While using `/code-review:code-review`:

- Don't switch branches; ask explicitly if absolutely needed.
- **IMPORTANT:** Always post every item worth fixing — this MUST override the skill's default rating-threshold filter. Filter findings only by validity, never by rating.

# Project guidelines

## Plugin purpose

The plugin's purpose is migrating data from a source to a destination site, keeping the data's integrity and format to the maximum extent possible. The only acceptable changes are the ones required to make the migrated data operational/correct on the destination site.

## Development state

Currently in closed beta used by customers, soon to become public; anything introducing breaking changes or threatening backward-compatibility needs to be explicitly reported, and human-approved before implementation.

## Workflow

- If whitespace issues occur during replacement, use `npm run fix` before trying to manually fix.
- After applying changes, run `npm run fix` and then `npm run check`. Fix and repeat as needed. Disregard issues unrelated to our changes.
- Run unit tests with `npm run test` and integration tests with `npm run test:integration`.
- When adjustments are made to the single import/update path, verify whether identical changes are needed to the bulk import/update path, and vice versa.

## Worktrees

- Run `bin/setup-worktree` to set up a worktree: it installs dependencies and assigns a free wp-env port pair, recording it in `.devports` (git-ignored) so `npm run dev` loads it automatically — no manual `WP_ENV_PORT` exports. Allocation is locked so simultaneous setups get distinct pairs, and a pre-set `WP_ENV_PORT`/`WP_ENV_TESTS_PORT` (e.g. from an external orchestrator) takes precedence.
- Before removing a worktree, run `npm run dev:destroy` from inside it to avoid orphan wp-env containers and volumes.

## Dependencies

**Runtime `@wordpress/*` packages and WP stubs are pinned to the wp-6.9 dist-tag line** to match the plugin's `Requires at least: 6.9`. Externalized packages resolve to `wp.*` globals, so an off-line version type-checks against APIs the floor lacks. `@wordpress/dataviews` and `@wordpress/icons` are bundled instead, but dataviews unlocks private APIs from the externalized `@wordpress/components`: an off-line copy destructures names core doesn't expose, yielding `undefined` silently until render. Development-only build, lint, test, and local-environment tools may move beyond the line when the upgrade exposes no newer browser APIs or types to plugin code — `@wordpress/base-styles` counts, since it compiles into our own stylesheet and never resolves against core; call out any such decision in the PR description. Raising the WP floor requires updating the plugin header, `minimum_supported_wp_version` in `phpcs.xml.dist`, `php-stubs/wordpress-{stubs,tests-stubs}`, `wp-phpunit/wp-phpunit`, the relevant runtime `@wordpress/*` packages to the next wp-X.Y dist-tag, and the minimum stated in the docs.

## CI compatibility matrix

Update the CI compatibility matrix manually; don't add a live compatibility lookup, scheduled updater, generated cache, or automated pull request.

Use these sources of truth:

- Read the minimum WordPress version from `Requires at least` in `safe-publish.php`.
- Read the minimum PHP version from the `php` entry under `require` in `composer.json`.
- Read supported combinations from the [WordPress PHP compatibility matrix](https://make.wordpress.org/core/handbook/references/php-compatibility-and-wordpress-versions/).

When WordPress or PHP compatibility changes, or the plugin's minimum WordPress or PHP version changes, update all of the following together:

1. In `.github/workflows/integration-tests.yml`, list every stable WordPress major/minor release from the plugin's minimum through the current release, and every PHP major/minor release from the plugin's minimum through the newest version supported by at least one of those WordPress releases. Add an `exclude` entry for every combination marked unsupported by WordPress. Keep every supported combination running on every pull request.
2. In `.github/workflows/e2e-tests.yml`, include every supported WordPress major/minor release once, paired with the highest PHP version that release supports.
3. In `.github/workflows/unit-tests.yml`, test every PHP major/minor version from the plugin's minimum through the newest PHP version represented in the integration matrix.
4. In `.github/workflows/static-checks.yml`, run PHP checks on the plugin's minimum PHP version.
5. Verify each stable `WordPress/WordPress#X.Y-branch` ref exists, parse every workflow as YAML, and run `npm run fix` followed by `npm run check`.

The integration commands use wp-env's `/wordpress-phpunit` mount so the test library matches each matrix row; don't replace it with the Composer-pinned minimum-version test library in CI.

Repository rulesets should require the stable aggregate checks named `Integration tests`, `Unit tests`, `Static checks`, and `End-to-end tests`, not individual matrix rows.
