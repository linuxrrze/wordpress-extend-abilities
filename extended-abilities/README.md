# Extended Abilities (WordPress plugin)

Upload **local** files (images, optionally PDF) to the WordPress media library from an MCP client such as
Claude Code, without base64 in tool calls and without handing WordPress credentials to the
shell. Built on the core Abilities API (WP ≥ 6.9) and exposed through the
[MCP Adapter](https://github.com/WordPress/mcp-adapter). Design and background:
[`../docs/design-local-media-upload.md`](../docs/design-local-media-upload.md).

Status: **0.4.0, milestones 1–4**. Uploads accept JPEG, PNG, GIF, WebP and AVIF, plus PDF
when enabled. GPS location data is removed from photos by default. Large files can be sent in
resumable chunks, and tiny ones inline as base64. Existing media files can be replaced in
place. For MCP clients without a shell, the local helper [`exab-upload`](../client/README.md)
does the upload.

## Replacing media files

`exab/replace-media-file {attachment_id, size_bytes, sha256}` opens an upload slot in replace
mode. The file then goes up like any other upload (single PUT or chunked), and passes the same
checks: size, checksum, content sniffing (it must be the **same type** as the current file) and
GPS removal. Then:

1. The old file and everything derived from it is deleted: all sizes, `-scaled`/`-rotated`
   copies, and backups from the image editor.
2. The new file is written under the **original file name**, and sizes and metadata are
   regenerated. The attachment ID, title, alt text, caption and parent stay.
3. URLs that changed (sizes with new dimensions, or a `-scaled` copy that is no longer needed)
   are rewritten in the content of every post the user **may edit**. Each such update creates
   a revision. Posts the user may not edit are left alone and reported in `posts_skipped`.

The ability is annotated **destructive**: the old file can't be restored. The main URL stays the
same unless WordPress scales or rotates one of the two versions. Browsers and CDNs may keep
showing the old image under an unchanged URL until their cache expires. References in post meta
(page builders, custom fields) are not rewritten.

Besides uploads, the plugin fills gaps that the standard plugins leave (see design doc §10):
editing media metadata, scheduling existing drafts, updating pages, restoring revisions, and
exposing other plugins' hidden abilities to MCP.

## How it works

```text
1. exab/create-upload-slot   {filename, mime_type, size_bytes, sha256, alt_text, post_id, …}
                           → {upload_url, upload_token, expires_at, curl}
2. curl -sS -X PUT --data-binary @FILE \
     -H 'Content-Type: image/png' -H 'X-Exab-Upload-Token: …' '<upload_url>'
                           → 201 {status: completed, attachment_id, url, set_as_featured}
3. exab/get-upload-status    {slot_id}   (optional, e.g. for clients without a shell)
```

Over the MCP Adapter default server, step 1 and 3 run through `mcp-adapter-execute-ability`.
Step 2 is plain HTTP. The token is the only credential it needs.

**Large files: chunked and resumable.** Send the file in pieces of at most `max_chunk_bytes`
(default 8 MB) with `Content-Range: bytes START-END/TOTAL`. Chunks must arrive in order. Each
one returns `202 {status: receiving, received_bytes}`, and the last one returns the normal
`201`. After an interruption, `exab/get-upload-status` reports `received_bytes`, and the
upload continues from there. A chunk at the wrong offset gets `409` with `expected_offset`.
`create-upload-slot` returns a ready-made bash one-liner (`curl_chunked`) that does all of
this with `tail`, `head` and `curl`. Set `OFFSET=<received_bytes>` to resume. Every accepted
chunk extends the slot's lifetime.

**Tiny files: inline.** `exab/upload-media-inline` takes the file as base64 (at most 64 KB,
and a `data:` URI prefix is fine). It suits generated icons or small diagrams. For anything
bigger, the base64 costs too many output tokens.

## Abilities

| Ability | Annotations | Permission |
|---|---|---|
| `exab/create-upload-slot` | write, not destructive | `upload_files`, plus `edit_post` on `post_id` if given |
| `exab/get-upload-status` | read-only, idempotent | `upload_files`, own slots only |
| `exab/upload-media-inline` | write, not destructive | `upload_files`, plus `edit_post` on `post_id` if given |
| `exab/get-media-item` | read-only | `read_post` on the attachment |
| `exab/update-media` | idempotent | `edit_post` on the attachment (and on the new parent post) |
| `exab/set-featured-image` | idempotent | `edit_post` on the post |
| `exab/schedule-post` | idempotent | `edit_post` + `publish_posts` of the post type |
| `exab/update-page` | idempotent | `edit_post`; `publish_pages` to publish or make private |
| `exab/list-revisions` | read-only | `edit_post` on the post |
| `exab/restore-revision` | idempotent | `edit_post` on the post (the current state becomes a new revision first) |
| `exab/replace-media-file` | **destructive** | `upload_files` + `edit_post` on the attachment; posts are rewritten only where `edit_post` allows |

`get-media-item` also returns ready-to-paste `wp:image` block markup for a chosen size.
`schedule-post` takes ISO 8601 dates; a date without a UTC offset is in the site time zone.

REST route (not an ability, because it takes raw bytes):
`PUT|POST /wp-json/exab/v1/upload/{slot_id}` with header `X-Exab-Upload-Token`.

## Security properties

- Token: 256 bits, valid for one file (15 minutes, extended by each chunk), stored only as
  SHA-256. It is bound to one file name, MIME type, size and (optionally) checksum.
- A slot, and each chunk offset, is claimed atomically (compare-and-swap on the options row).
  Parallel uploads with the same token can't both succeed, and neither can two chunks for the
  same offset.
- The real type is sniffed from the content (`wp_get_image_mime()`, or the `%PDF-` header for
  PDF). A script named `.png`, or a GIF named `.png`, is rejected.
- **GPS removal** (on by default): GPS coordinates are blanked in EXIF (the GPS IFD and the
  values it references) and XMP (`exif:GPS*`) for JPEG, PNG (`eXIf`, uncompressed `iTXt`)
  and WebP (`EXIF`, `XMP `). This happens in place, without changing offsets, so the rest of
  the metadata (camera, orientation) stays valid, and WordPress still auto-rotates portrait
  photos. Not covered: AVIF/HEIF metadata and compressed PNG text chunks.
- Partial chunked uploads are stored in `uploads/exab-chunks/` under a keyed, unguessable name
  (with `.htaccess` deny for Apache). They are deleted on completion or failure, and an hourly
  cleanup removes abandoned ones once their slot has expired. On nginx, deny that directory
  explicitly (`location ^~ /wp-content/uploads/exab-chunks/ { deny all; }`).
- The upload runs as the user who created the slot. `upload_files` and `edit_post` are checked
  again at upload time.
- Attachments go through `media_handle_sideload()`, so sub-sizes, EXIF and other plugins'
  upload filters behave as in wp-admin.
- Failure handling: a bad token or a wrong `Content-Type` does **not** use up the slot. A size,
  checksum or content mismatch marks it `failed`, and you create a new slot.

Note that media files are publicly reachable by URL. Keep your MCP client's confirmation
prompts on for uploads, so a prompt-injected agent can't quietly publish local files.

## Exposing other plugins' abilities

**Settings → Extended Abilities** lists every registered ability that is not MCP-public
(read-only first, destructive ones highlighted). Checked abilities get `meta.mcp.public = true`
through the `wp_register_ability_args` filter and then show up in
`mcp-adapter-discover-abilities`. Their own permission checks still apply: exposing an
admin-only ability does not let an author run it. Example for the blog: Visualizer's
`visualizer/list-charts`, `get-chart` and `upsert-chart`.

## Upload settings

Also under **Settings → Extended Abilities**:

- **Location data:** remove GPS coordinates from uploaded images (default **on**).
- **PDF:** allow `application/pdf` uploads (default **off**). PDFs are publicly reachable like
  every media file, so only enable this if the agent should publish documents.

## Filters

| Filter | Default |
|---|---|
| `exab_allowed_mime_types` | JPEG, PNG, GIF, WebP, AVIF, plus PDF if enabled (always intersected with the user's allowed types) |
| `exab_max_upload_bytes` | `wp_max_upload_size()` (can only be lowered) |
| `exab_chunk_max_bytes` | 8 MB (minimum 1 KB): keep it below the web server body limit and `memory_limit` |
| `exab_inline_max_bytes` | 64 KB (never above `exab_max_upload_bytes`) |
| `exab_strip_gps` | value of the setting |
| `exab_slot_ttl` | 900 seconds (minimum 60) |
| `exab_max_open_slots` | 10 open (pending or receiving) slots per user |

Action `exab_media_uploaded( $attachment_id, $upload )` fires after each successful upload
(slot or inline).

## Server requirements

- WordPress ≥ 6.9 (Abilities API), PHP ≥ 8.1, MCP Adapter for MCP access.
- The web server must allow request bodies of the largest *single request*: the whole file
  for a plain PUT, or one chunk (`client_max_body_size` for nginx, `LimitRequestBody` for
  Apache). With chunked uploads, files larger than the body limit work too.
- WordPress REST reads each request body into memory, so `memory_limit` should comfortably
  exceed the chunk size (or the file size for plain PUTs). GPS removal reads the whole image
  into memory once.
- Plugins that block anonymous REST access must allow `/exab/v1/upload/*`. That request
  carries no WordPress login, only the token.

## Tests

End-to-end tests run against a throwaway WordPress 7.1 / PHP 8.3 in
[WordPress Playground](https://wordpress.github.io/wordpress-playground/) (PHP as WebAssembly
with SQLite). They need only Node.js and Python 3, with no PHP, MySQL or Docker:

```bash
tests/e2e/run.sh                         # generated test image
tests/e2e/run.sh --fixture some.png      # real image for the happy path
tests/e2e/run.sh -k mcp                  # filter by test name
```

The blueprint installs MCP Adapter v0.7.1, creates users (admin, two authors, a subscriber)
with application passwords, test posts, pages and revisions, sets the site time zone to
Europe/Berlin, generates JPEG and WebP fixtures with GD, limits chunks to 100 KB, and enables
`WP_DEBUG_LOG`. Any PHP notice, warning or error in that log fails the run. There are 73 tests:

- **Uploads (21):** the happy path with metadata and featured image, POST instead of PUT,
  single use, wrong token, wrong content type, size, checksum and content mismatches, schema
  and capability checks, foreign slots, expiry, the open-slot limit, parallel uploads, and the
  full flow through the MCP Adapter's JSON-RPC endpoint.
- **Milestone 2 (19):** media item with sizes and block markup, partial metadata updates,
  featured image set and remove, scheduling with and without a UTC offset (including daylight
  saving), rejection of past dates and published posts, page updates with parent-loop
  protection, listing and restoring revisions, and the expose setting through MCP (including
  that exposing doesn't bypass permissions). Every "forbidden" test checks for the Abilities
  API's permission error code, so a crash that happens to return 403 can't pass as a denial.
- **Milestone 3 (20):**
  - GPS removed from real JPEG (EXIF + XMP), PNG (with valid CRCs afterwards) and extended WebP
    uploads, with the file size unchanged and camera data kept.
  - A portrait photo (orientation 6) is still rotated by WordPress after scrubbing.
  - With the setting off, the original bytes are stored. JPEGs without GPS come out
    byte-identical.
  - Big-endian TIFF, and malformed or truncated input, cause no change and no PHP warnings.
  - Chunked uploads: in order, resume after a wrong offset, bad ranges that don't burn the
    slot, a plain PUT rejected mid-upload, parallel chunks for one offset, checksum failure
    with cleanup of the partial file, and the `curl_chunked` one-liner run with real `bash`
    (resuming at `OFFSET`).
  - Inline uploads: featured image, `data:` URI with GPS removal, size, base64 and content
    checks, and through MCP.
  - PDF: off by default; when enabled, real vs. fake PDF, and PDFs can't become featured images.
- **Milestone 4 (13):**
  - Replace: ID, main URL, title and alt text are kept. The changed size URL is rewritten in
    your own post (with a revision) and left alone in someone else's. Old size files return
    404. Also covered: a `-scaled` image replaced by a small one, chunked replacement, wrong
    type (the old file stays untouched), permissions, and the destructive annotation.
  - Helper (`client/exab_upload.py`, run as a subprocess over stdio): tools and protocol,
    upload with metadata and GPS removal, chunked upload, replace (including a type
    mismatch), wrong password, missing configuration, and every allowlist refusal (outside,
    symlink, `..`, hidden, `~/.ssh`, wrong type, fake content, relative path).
  - Both security checks were mutation-tested: with the symlink resolution or the per-post
    permission check removed, the tests fail.

`tests/` is not needed on a production site. Leave it out when deploying.

## Deploying

Copy the `extended-abilities` directory (without `tests/`) to `wp-content/plugins/` and
activate it. The eleven `exab/*` abilities then appear in `mcp-adapter-discover-abilities`, and
in EWPA's ability list under Settings → WP Abilities. Check the upload options under
Settings → Extended Abilities.
