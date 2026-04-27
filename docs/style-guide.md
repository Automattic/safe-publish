# Documentation Style Guide

This is the authoritative style guide for all Safe Publish documentation — internal and external.

## Audience

Every doc targets one of two audiences. Know which one before you start writing.

| Audience | Who they are | Assumed knowledge |
|----------|-------------|-------------------|
| **End-user** | Content editors, content managers | Moderate WordPress knowledge; no coding skills |
| **Developer** | Engineers installing, extending, or maintaining the plugin | PHP, WordPress plugin development, REST API |

## Voice and Tone

- Write in **second person, present tense, active voice**: "You click **Import**" not "The import button should be clicked."
- **Lead with the task**, not the system: "Import a post" not "The import system allows posts to be imported."
- **One idea per sentence.** Break long sentences.
- **End-user docs**: patient, assumes nothing beyond basic WordPress familiarity. No jargon without definition.
- **Developer docs**: direct, terse, assumes WordPress and PHP competence.

## Formatting

### Headers

- Use `#` for page title only (one per page).
- Use `##` for major sections.
- Use `###` for subsections.
- Headers must be sentence case: "Import a post" not "Import A Post."

### Lists

- Use bullets for unordered items with no implied sequence.
- Use numbered lists for steps that must be followed in order.
- Keep list items parallel: all start with a verb, or all are nouns, not mixed.
- All list items end with a period.

### Code

- All code — PHP, JavaScript, shell commands, file paths — goes in a fenced code block with a language tag.

  ````
  ```php
  add_filter( 'safe_publish_request_timeout', fn( int $t ): int => 30 );
  ```
  ````

- Inline code (variable names, function names, option keys) uses backticks: `safe_publish_request_timeout`.
- Code examples must be complete enough to run, or must be clearly marked as pseudocode.
- Never leave out required imports, `add_action`, or context that makes the example non-functional.

### UI elements

Bold UI labels exactly as they appear in the plugin: click **Import**, not click "Import" or click the import button.

### Notes and warnings

Use GitHub-flavored blockquote admonitions for notes that need emphasis:

```markdown
> [!NOTE]
> The shared secret must be at least 32 characters.

> [!WARNING]
> Never disable SSL verification in production.
```

## Links

- Link to related docs rather than duplicating content.
- Use relative links within the docs directory: `[Troubleshooting](../troubleshooting.md)`.
- External links should open as-is (do not force `target="_blank"`).
- Every page must end with a **Next Steps** or **Related** section linking to at least one other page.

## Examples

Every concept and every hook, filter, or endpoint must have at least one example.

- PHP examples: use WordPress coding standards (tabs for indentation, space before parentheses in function calls).
- Shell examples: use `$` prefix for commands the reader types.
- Examples must be accurate against the current codebase — never document behavior the code does not implement.

## Accuracy

- Document **behavior and contracts**, not implementation details.
- Before adding a hook, filter, or endpoint example, verify it exists in the source code.
- If a feature is only available under certain conditions (e.g., `WP_DEBUG = true`), say so.
- Never copy-paste examples from hypothetical code. Trace them mentally first.

## File naming

- Use lowercase, hyphen-separated names: `editor-guide.md`, not `EditorGuide.md`.
- Place end-user docs at the top level of `docs/`.
- Place developer/conceptual docs in `docs/concepts/`.
- Place extension/developer docs in `docs/extending/`.

## What not to document

- Internal implementation details that may change next sprint.
- Code that is unreachable, deprecated, or not yet merged.
- Features with a `// TODO` or similar marker in the source — wait until they ship.

## Related

- [Hooks and Filters](extending/hooks.md) — Reference for all actions and filters
- [REST API Extension](extending/api.md) — Endpoint reference and extension patterns
- [Extending Guide](extending/index.md) — Overview of all extension points
