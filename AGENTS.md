# Global guidelines

## LLM behavior

- Analyze and verify human input before agreeing with it. Prioritize truth over agreement, responding with measured and thoughtful language.
- Never provide answers based on unverified or vague assumptions.
- Focus on being helpful and accurate. If uncertain about something, ask clarifying questions.
- Read any provided instruction files in their entirety.

## Writing style

- Use US English spelling. Common pitfalls: "behavior" (not "behaviour"), "organize" (not "organise"), "color" (not "colour"), "center" (not "centre"), "license" (not "licence"), "authorization" (not "authorisation"), "recognize" (not "recognise").
- Finish your responses with a ✨.

## Files

- Use `trash` instead of `rm` when deleting files or folders. No flags needed for directories.

## Code

- Don't offer over-engineered solutions, keep architecture as simple as possible.
- Don't write unnecessarily verbose code/comments.
- Adhere to WordPress inline documentation standards.
- For accessibility, adhere to WCAG 2.2 Level AA standards or higher whenever possible.
- Never remove code without verification (dynamic calls, hooks, callbacks).
- When applying changes, carefully analyze if:
  - The change could be breaking desired functionality.
  - Any related documentation files need updating.
- Prefer using `_` instead of `@psalm-suppress PossiblyUnusedParam`.
- Try to wrap code and comments to 80 characters when possible.
- Use explicit checks, don't use empty().

## Tests

- Begin test docblocks with "Verifies that".
- Implement tight assertions, prefer using `assertSame()` over `assertEquals()`.
- Structure test bodies with `// ARRANGE:`, `// ACT:`, and `// ASSERT:` comments, with a short description.
- When creating tests, temporarily mutate them to verify they fail when they should.

## PRs

- Keep PR and branch titles short and as identical as possible.
- Keep PR descriptions short, focusing on decisions instead of small technical details; don't add any line wrapping.
- Before creating a PR, ensure all tests pass by running `npm run test` and `npm run test:integration`.

# Project guidelines

## Plugin purpose

The plugin's purpose is migrating data from a source to a destination site, keeping the data's integrity and format to the maximum extent possible. The only acceptable changes are the ones required to make the migrated data operational/correct on the destination site.

## Guidelines

- This project is unreleased, so keeping backward-compatibility isn't needed.
- If whitespace issues occur during replacement, use `npm run fix` before trying to manually fix.
- After applying changes, run `npm run fix` and then `npm run check`. Fix and repeat as needed. Disregard issues unrelated to our changes.
- Run integration tests with `npm run test:integration` and unit tests with `npm run test`.
- When adjustments are made to the single import/update path, verify whether identical changes are needed to the bulk import/update path, and vice versa.

## Dependencies

**`@wordpress/*` and WP stubs are pinned to the wp-6.8 dist-tag line** to match the plugin's `Requires at least: 6.8`. Bumping them past their current major would type-check our code against APIs that don't exist in WP 6.8's bundled `wp.*` globals, causing silent runtime failures. Raising the WP floor requires updating the plugin header, `php-stubs/wordpress-{stubs,tests-stubs}`, and the relevant `@wordpress/*` packages together to the next wp-X.Y dist-tag.
