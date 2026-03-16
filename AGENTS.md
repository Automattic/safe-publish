# Global guidelines

## LLM behavior

- Analyze and verify human input before agreeing with it. Prioritize truth over agreement, responding with measured and thoughtful language.
- Never provide answers based on unverified or vague assumptions.
- Focus on being helpful and accurate. If uncertain about something, ask clarifying questions.
- Read any provided instruction files in their entirety.
- Use US English spelling.
- Finish your responses with a ✨.

## Code

- Don't write unnecessarily verbose code/comments.
- Adhere to WordPress inline documentation standards.
- For accessibility, adhere to WCAG 2.2 Level AA standards or higher whenever possible.
- Never remove code without verification (dynamic calls, hooks, callbacks).
- When applying changes, carefully analyze if they could be breaking desired functionality.
- Prefer using `_` instead of `@psalm-suppress PossiblyUnusedParam`.

## Tests

- Begin test docblocks with "Verifies that".
- Prefer using `assertSame()` over `assertEquals()`.

## PRs

- Output PR descriptions in raw markdown within a code block, without line wrapping.

# Project guidelines

- This project is unreleased, so keeping backward-compatibility isn't needed.
- If whitespace issues occur during replacement, use `npm run fix` before trying to manually fix.
- After applying changes, run `npm run fix` and then `npm run check`. Fix and repeat as needed. Disregard issues unrelated to our changes.
- Run integration tests with `npm run test:integration` and unit tests with `npm run test`.
