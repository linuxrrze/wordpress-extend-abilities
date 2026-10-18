# Extended Abilities (WordPress plugin)

Upload **local** image files to the WordPress media library from an MCP client such as
Claude Code, without base64 in tool calls and without handing WordPress credentials to the
shell. Built on the core Abilities API (WP ≥ 6.9) and exposed through the
[MCP Adapter](https://github.com/WordPress/mcp-adapter). Design and background:
[`../docs/design-local-media-upload.md`](../docs/design-local-media-upload.md).

Status: **0.1.0, milestone 1**. Images only (JPEG, PNG, GIF, WebP, AVIF).

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
with application passwords, and enables `WP_DEBUG_LOG`. A non-empty PHP debug log fails the
run. There are 21 tests: the happy path with metadata and featured image, POST instead of PUT,
single use, wrong token, wrong content type, size, checksum and content mismatches, schema and
capability checks, foreign slots, expiry, the open-slot limit, parallel uploads, and the full
flow through the MCP Adapter's JSON-RPC endpoint.

`tests/` is not needed on a production site. Leave it out when deploying.

## Deploying

Copy the `extended-abilities` directory (without `tests/`) to `wp-content/plugins/` and
activate it. The two abilities then appear in `mcp-adapter-discover-abilities`, and in EWPA's
ability list under Settings → WP Abilities.
