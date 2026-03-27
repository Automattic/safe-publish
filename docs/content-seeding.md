# Content Seeding

The seeder populates a WordPress environment with realistic test content for verifying import functionality. It covers the content patterns most relevant to the import process: block and classic editor posts, multiple image configurations, various post statuses, slugs, excerpts, meta fields, and taxonomy terms.

The entry point is `bin/seed` (or the npm scripts below). `bin/seed-content.php` is the underlying WP-CLI script and should not be called directly.

## Quick Start

Seed the source site with 20 posts (default settings):

```sh
npm run seed
```

Seed and reset previously seeded content first:

```sh
npm run seed -- fresh=1
```

Run a full preset covering all meaningful combinations (~25 posts):

```sh
npm run seed:full
```

## npm Scripts

| Script                     | Description                            |
| -------------------------- | -------------------------------------- |
| `npm run seed`             | Seed the source site with defaults     |
| `npm run seed:source`      | Alias for `seed`                       |
| `npm run seed:destination` | Seed the destination site              |
| `npm run seed:full`        | Run the full preset on the source site |

All scripts accept additional arguments after `--`:

```sh
npm run seed -- count=5 type=page fresh=1
```

## Arguments

These arguments are passed directly to `bin/seed`:

| Argument  | Default     | Description                                                                               |
| --------- | ----------- | ----------------------------------------------------------------------------------------- |
| `site=`   | `source`    | Which site to seed: `source`, `destination`, or `both`                                    |
| `preset=` | _(none)_    | `full` — runs all meaningful combinations; ignores other args except `site=` and `fresh=` |
| `count=`  | `20`        | Number of posts to create                                                                 |
| `start=`  | `1`         | Starting post number; use to avoid duplicate numbers across batches                       |
| `type=`   | `post`      | Post type slug (`post`, `page`, or any registered CPT)                                    |
| `editor=` | `gutenberg` | Content format: `gutenberg`, `classic`, or `mixed` (2/3 block, 1/3 classic)               |
| `images=` | `auto`      | Image mode (see below)                                                                    |
| `fresh=`  | _(off)_     | Set to `1` to delete all previously seeded content before seeding                         |

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

All seeded content is tagged with `_seeder_generated=1` meta, which `fresh=1` uses for targeted cleanup.

## The Full Preset

`preset=full` runs three batches in sequence:

1. 10 Gutenberg posts, `images=auto` (posts 1–10)
2. 10 classic posts, `images=auto` (posts 11–20)
3. 5 pages (`type=page`), `editor=gutenberg`, `images=auto` (pages 21–25) — exercises the page post type, which skips taxonomy assignment

Only the first batch passes `fresh=1` (when provided), so subsequent batches append rather than reset.

## Examples

```sh
# Seed 5 pages on the destination site
bin/seed site=destination count=5 type=page

# Seed both sites, resetting content first
bin/seed site=both fresh=1

# Seed classic posts with resized images
bin/seed editor=classic images=2-resized count=10

# Full preset on both sites, starting fresh
bin/seed preset=full site=both fresh=1
```
