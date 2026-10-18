# Changelog

All notable changes to the Extended Abilities plugin and the `exab-upload` helper.
Versions follow [Semantic Versioning](https://semver.org/); before 1.0.0, minor versions may
change behaviour.

## [0.4.0] - 2026-10-18

### Added
- `exab/replace-media-file`: replace the file of an existing media item (same type), keeping
  its ID, file name, title and alt text. Uses upload slots, so chunked uploads and GPS removal
  apply. Changed size URLs are rewritten in posts the user may edit (with revisions); posts
  the user may not edit are listed in `posts_skipped`. Annotated destructive.
- `client/exab_upload.py` (`exab-upload`): local MCP server (stdio, Python standard library
  only) for clients without a shell. Tools `upload_local_file`, `replace_local_file`,
  `list_allowed_directories`. Reads only from `EXAB_ALLOWED_DIRS` (symlinks and `..`
  resolved, hidden paths refused).
- Repository: GPL-2.0 `LICENSE`, this changelog, `bin/build_zip.py`, GitHub Actions CI and
  release workflow, uninstall test.

## [0.3.0] - 2026-10-18

### Added
- GPS removal (on by default) for JPEG, PNG and WebP: EXIF GPS IFD and XMP `exif:GPS*`,
  in place, so orientation and camera data survive.
- Chunked, resumable uploads via `Content-Range`; `curl_chunked` one-liner in
  `create-upload-slot` output.
- `exab/upload-media-inline`: base64 uploads up to 64 KB.
- Optional PDF uploads (off by default).
- Upload options on Settings → Extended Abilities.

## [0.2.0] - 2026-10-18

### Added
- `exab/get-media-item`, `exab/update-media`, `exab/set-featured-image`.
- `exab/schedule-post`, `exab/update-page`, `exab/list-revisions`, `exab/restore-revision`.
- Settings → Extended Abilities: expose other plugins' abilities to MCP.

### Fixed
- Permission checks in tests now assert the Abilities API's error code (caught a
  by-reference bug in `restore-revision` before release).

## [0.1.0] - 2026-10-18

### Added
- Upload slots: `exab/create-upload-slot`, `exab/get-upload-status`, and the
  `PUT /wp-json/exab/v1/upload/{slot}` route. Single-use token, content sniffing, upload as
  the slot owner with title, alt text, caption, parent post and featured image.
- End-to-end tests in WordPress Playground.
