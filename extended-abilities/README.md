# Extended Abilities (WordPress plugin)

Upload **local** image files to the WordPress media library from an MCP client such as
Claude Code, without base64 in tool calls and without handing WordPress credentials to the
shell. Built on the core Abilities API (WP ≥ 6.9) and exposed through the
[MCP Adapter](https://github.com/WordPress/mcp-adapter). Design and background:
[`../docs/design-local-media-upload.md`](../docs/design-local-media-upload.md).

Status: **0.2.0, milestones 1 and 2**. Uploads are images only (JPEG, PNG, GIF, WebP, AVIF).

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

## Abilities

| Ability | Annotations | Permission |
|---|---|---|
| `exab/create-upload-slot` | write, not destructive | `upload_files`, plus `edit_post` on `post_id` if given |
| `exab/get-upload-status` | read-only, idempotent | `upload_files`, own slots only |
| `exab/get-media-item` | read-only | `read_post` on the attachment |
| `exab/update-media` | idempotent | `edit_post` on the attachment (and on the new parent post) |
| `exab/set-featured-image` | idempotent | `edit_post` on the post |
| `exab/schedule-post` | idempotent | `edit_post` + `publish_posts` of the post type |
| `exab/update-page` | idempotent | `edit_post`; `publish_pages` to publish or make private |
| `exab/list-revisions` | read-only | `edit_post` on the post |
| `exab/restore-revision` | idempotent | `edit_post` on the post (the current state becomes a new revision first) |

`get-media-item` also returns ready-to-paste `wp:image` block markup for a chosen size.
`schedule-post` takes ISO 8601 dates; a date without a UTC offset is in the site time zone.

REST route (not an ability, because it takes raw bytes):
`PUT|POST /wp-json/exab/v1/upload/{slot_id}` with header `X-Exab-Upload-Token`.

## Security properties

- Token: 256 bits, single use, valid 15 minutes, stored only as SHA-256. It is bound to one
  file name, MIME type, size and (optionally) checksum.
- A slot is claimed atomically (compare-and-swap on the options row), so parallel uploads
  with the same token can't both succeed.
- The real type is sniffed from the content (`wp_get_image_mime()`). A script named `.png`,
  or a GIF named `.png`, is rejected.
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

## Filters

| Filter | Default |
|---|---|
| `exab_allowed_mime_types` | JPEG, PNG, GIF, WebP, AVIF (always intersected with the user's allowed types) |
| `exab_max_upload_bytes` | `wp_max_upload_size()` (can only be lowered) |
| `exab_slot_ttl` | 900 seconds (minimum 60) |
| `exab_max_open_slots` | 10 pending slots per user |

Action `exab_media_uploaded( $attachment_id, $slot )` fires after each successful upload.

## Server requirements

- WordPress ≥ 6.9 (Abilities API), PHP ≥ 8.1, MCP Adapter for MCP access.
- The web server must allow request bodies of the maximum file size (`client_max_body_size`
  for nginx, `LimitRequestBody` for Apache).
- WordPress REST reads the whole body into memory, so `memory_limit` should comfortably
  exceed the maximum file size.
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
Europe/Berlin, and enables `WP_DEBUG_LOG`. Any PHP notice, warning or error in that log fails
the run. There are 40 tests:

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

`tests/` is not needed on a production site. Leave it out when deploying.

## Deploying

Copy the `extended-abilities` directory (without `tests/`) to `wp-content/plugins/` and
activate it. The two abilities then appear in `mcp-adapter-discover-abilities`, and in EWPA's
ability list under Settings → WP Abilities.
