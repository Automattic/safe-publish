# Content Seeding

The seeder populates a WordPress environment with realistic test content for verifying import functionality. It covers the content patterns most relevant to the import process: block and classic editor posts, multiple image configurations, various post statuses, slugs, excerpts, meta fields, and taxonomy terms.

Use the npm scripts below to seed; `bin/seed-content.php` is the underlying WP-CLI script and should not be called directly.

## npm Scripts

| Script                            | Description                                    |
| --------------------------------- | ---------------------------------------------- |
| `npm run seed`                    | Seed the source site with defaults             |
| `npm run seed:source`             | Alias for `seed`                               |
| `npm run seed:destination`        | Seed the destination site                      |
| `npm run seed:full`               | Run the full preset on the source site         |
| `npm run seed:update`             | Bump every seeded post on the source site      |
| `npm run seed:update:destination` | Bump every seeded post on the destination site |

Any script accepts additional arguments after `--`:

```sh
# Seed 5 pages on the destination site
npm run seed:destination -- count=5 type=page

# Seed both sites, resetting content first
npm run seed -- site=both fresh=1

# Seed classic posts with resized images
npm run seed -- editor=classic images=2-resized count=10

# Full preset on both sites, starting fresh
npm run seed:full -- site=both fresh=1

# Bump every seeded post on both sites by one revision
npm run seed:update -- site=both

# Drive every seeded post to revision 3 deterministically
npm run seed:update -- revision=3
```

## Arguments

The seeder accepts these arguments:

| Argument       | Default     | Description                                                                                                             |
| -------------- | ----------- | ----------------------------------------------------------------------------------------------------------------------- |
| `site=`        | `source`    | Which site to seed: `source`, `destination`, or `both`                                                                  |
| `mode=`        | `create`    | `create` inserts new posts; `update` bumps existing seeded posts to a new revision (see below)                          |
| `preset=`      | _(none)_    | `full` — runs all meaningful combinations; ignores other args except `site=` and `fresh=`                               |
| `count=`       | `20`        | Number of posts to create (create mode only)                                                                            |
| `start=`       | `1`         | Starting post number; use to avoid duplicate numbers across batches (create mode only)                                  |
| `type=`        | `post`      | Post type slug (`post`, `page`, or any registered CPT). In `mode=update`, omit to touch every seeded post type          |
| `editor=`      | `gutenberg` | Content format: `gutenberg`, `classic`, or `mixed` (2/3 block, 1/3 classic)                                             |
| `images=`      | `auto`      | Image mode (see below)                                                                                                  |
| `date-offset=` | `0`         | Shift all post dates this many extra days into the past; use in multi-batch presets to keep date ranges non-overlapping |
| `fresh=`       | _(off)_     | Set to `1` to delete all previously seeded content, then seed normally. Not allowed with `mode=update`                  |
| `purge=`       | _(off)_     | Set to `1` to delete all previously seeded content and exit without inserting anything. Not allowed with `mode=update`  |
| `prefix=`      | _(none)_    | String prepended to every post title — useful to distinguish multiple runs                                              |
| `revision=`    | _(auto)_    | Target revision number for `mode=update`. Omit to auto-bump each post's current revision by one                         |

### Image Modes

| Mode        | What is created                                                             |
| ----------- | --------------------------------------------------------------------------- |
| `1`         | One image per post                                                          |
| `2`         | Two independent images                                                      |
| `2-resized` | Original image + a half-size copy (tests URL rewriting of resized variants) |
| `auto`      | Rotates through all three modes across posts                                |

## What Gets Seeded

Every post includes:

- Multi-paragraph block or classic HTML content with a heading, paragraphs, and a list
- Excerpt
- Explicit slug (`seeder-{type}-{index}`) to test slug preservation
- Post date spread evenly over the past 90 days (oldest first)
- Rotating status: mostly `publish`, with `draft` every 5th and `private` every 6th
- Featured image (first image from the selected mode)
- Custom meta: `seeder_color` and `seeder_priority`
- Categories and tags (posts only), rotating through a fixed seeder set

All seeded content is tagged with `_seeder_generated=1` meta, which `fresh=1` and `purge=1` use for targeted cleanup.

## Update Mode

`mode=update` finds every post previously created by the seeder (tagged with `_seeder_generated=1` meta) and applies deterministic mutations so the migration's update path has visible changes to propagate:

- Title and excerpt get a `(rev N)` suffix; subsequent updates replace the previous suffix.
- Content gets an extra paragraph noting the revision, wrapped in HTML marker comments so it's replaced (not accumulated) on each update.
- Status, `seeder_color`, `seeder_priority`, and term assignments rotate so the migration's diff logic sees real changes.
- A `_seeder_revision` meta value records the current revision.
- Slug, post date, and attachments are preserved — IDs and media stay stable.

Without `revision=`, each post's revision auto-bumps by one. Pass `revision=N` to drive every post to a specific revision (useful in scripted tests where you want deterministic output).

## The Full Preset

`preset=full` runs three batches in sequence:

1. 10 Gutenberg posts, `images=auto` (posts 1–10)
2. 10 classic posts, `images=auto` (posts 11–20)
3. 5 pages (`type=page`), `editor=gutenberg`, `images=auto` (pages 21–25) — exercises the page post type, which skips taxonomy assignment

When `fresh=1` is passed, a `purge=1` step runs before the batches to wipe previously seeded content. Each batch then appends cleanly without risking wiping content created by an earlier batch.
