# Test Media Fixtures

Minimal non-image media used by integration tests.

## Files

- `test-tiny.mp4` — a 1.4 KB valid MP4, extracted from WordPress core's own `core__cover__video` block fixture (a public-domain test asset). Used to exercise the core/media-text video attachment-ID remap without a network download.

The tests serve these bytes via the same two-part mock described in `../images/README.md` (a `pre_http_request` filter for the response and a `wp_handle_sideload_prefilter` filter to populate the empty temp file).
