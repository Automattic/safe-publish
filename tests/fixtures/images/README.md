# Test Image Fixtures

This directory contains minimal test images for integration tests.

## Purpose

These files enable testing of complete media import workflows:

- Downloading media from external URLs
- Creating WordPress attachments
- Storing metadata (original URL, source site)
- URL replacement in content
- Duplicate detection

## Implementation

The integration tests use a two-part mocking strategy:

1. **HTTP Mock** (`pre_http_request` filter) - Returns real file contents from these fixtures
2. **Empty File Fix** (`wp_handle_sideload_prefilter` filter) - Populates temp files before WordPress processes them

`download_url()` creates empty temp files even when HTTP responses contain data. The `fix_empty_temp_files()` method detects empty files and copies fixture content to them before `media_handle_sideload()` validates the file.

This approach enables:

- Fast tests (no network calls or web servers needed)
- Full media validation (WordPress receives real image files)
- Complete import workflow testing (attachments created, metadata stored, URLs replaced)

See `Source_Posts_API_Test_Base.php` for the implementation.
