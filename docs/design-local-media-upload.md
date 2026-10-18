# Design: *Extended Abilities* (local media upload over MCP and more)

Status: **milestones 1–4 implemented** (0.4.0, see [`../extended-abilities/`](../extended-abilities/README.md) and [`../client/`](../client/README.md)) · Target: WordPress ≥ 6.9 (tested against 7.1.3), MCP Adapter ≥ 0.7

## 1. Goal

An agent like Claude Code should be able to say *"upload `./shots/board.jpg`, alt text …, set
it as featured image of post 2470"* and get it done. That includes:

- files from the **agent's local disk** (not a public URL),
- realistic sizes (screenshots, photos, PDFs: up to `wp_max_upload_size()`),
- metadata in the same step: title, alt text, caption, description, parent post, featured
  image,
- **no long-lived WordPress credential** in shell commands or transcripts,
- the plugin plugs into the existing stack (Abilities API → MCP Adapter default server), next
  to EWPA, with no extra MCP server to configure.

Out of scope for v1: image editing, bulk import, replacing existing files (Enable Media Replace
covers that in wp-admin).

## 2. The core problem

The MCP server is **WordPress itself**, on another host. A tool call carries JSON that the
**model generates token by token**. A file path means nothing to the server, and base64 inside
the arguments has to be typed out by the model. Even a 100 KB screenshot is about 135 K
characters of base64, which already exceeds what fits in one model output.

So the **bytes need a separate channel**, and MCP is used only to control it:

```
 Agent (Claude Code)                     WordPress (Extended Abilities)
 ───────────────────                     ──────────────────────────────
 1. execute-ability
    exab/create-upload-slot  ──────────► check cap + mime + size
    {filename, mime, size, sha256,       store slot (15 min, single use)
     alt_text, post_id, …}   ◄────────── {upload_url, upload_token, expires_at}

 2. curl -X PUT --data-binary @file      write body → temp file
    -H "X-Exab-Upload-Token: …"          verify size, sha256, real MIME
    upload_url               ──────────► media_handle_sideload() as slot owner
                                         apply metadata, featured image
                             ◄────────── {attachment_id, url, sizes, block_markup}

 3. (optional) exab/get-upload-status  ► for clients that cannot see step 2's output
```

The model only handles small values: a filename, a hash, a URL and a short-lived token.

## 3. Components

### 3.1 WordPress plugin `extended-abilities`

Ability namespace **`exab/`**. The slug avoids "wordpress", which wordpress.org doesn't allow
in plugin slugs.

| Ability | Kind | Purpose |
|---|---|---|
| `exab/create-upload-slot` | write | Validate the planned upload and issue URL + token |
| `exab/get-upload-status` | read | `pending` / `completed` (+ attachment) / `failed` (+ reason) / `expired` |
| `exab/upload-media-inline` | write | Base64 for **tiny** files only (default cap 64 KB, configurable) |
| `exab/update-media` | write | Title, alt, caption, description, parent; fills a gap in EWPA |
| `exab/get-media-item` | read | URLs per image size + ready-to-paste `wp:image` block markup |
| `exab/set-featured-image` | write | Set or remove `_thumbnail_id` (also usable on its own) |

Plus one REST route that **isn't an ability**, because abilities only take JSON:

```
PUT /wp-json/exab/v1/upload/{slot_id}
Headers: X-Exab-Upload-Token: <token>
         Content-Type: <mime announced in the slot>
Body:    raw file bytes
```

### 3.2 Optional client helper `exab-upload` (later)

For MCP clients **without a shell** (Claude Desktop, claude.ai), a tiny local stdio MCP
server with one tool, `upload_local_file(path, alt_text, …)`. It runs steps 1 and 2 itself.
It must enforce a **directory allowlist** (see §6). Claude Code doesn't need it, because it
already has `curl`.

## 4. Ability specifications

### `exab/create-upload-slot`

```jsonc
// input_schema
{
  "type": "object",
  "required": ["filename", "mime_type", "size_bytes"],
  "properties": {
    "filename":    { "type": "string", "maxLength": 200 },
    "mime_type":   { "type": "string", "description": "e.g. image/png" },
    "size_bytes":  { "type": "integer", "minimum": 1 },
    "sha256":      { "type": "string", "pattern": "^[a-f0-9]{64}$",
                     "description": "Recommended. Upload is rejected on mismatch." },
    "title":       { "type": "string" },
    "alt_text":    { "type": "string" },
    "caption":     { "type": "string" },
    "description": { "type": "string" },
    "post_id":     { "type": "integer", "description": "Attach to this post" },
    "set_featured":{ "type": "boolean", "default": false }
  }
}
// output_schema
{
  "slot_id": "string", "upload_url": "string", "upload_token": "string",
  "expires_at": "ISO-8601", "max_bytes": "integer",
  "curl": "string"   // ready-made command; the model only replaces the local path
}
```

Behavior:

- `permission_callback`: `current_user_can('upload_files')`. If `post_id` is given, also
  `edit_post` on it.
- Rejects the request up front if the MIME type isn't in `get_allowed_mime_types( $user )`, or if
  `size_bytes > wp_max_upload_size()` or the plugin's own cap.
- Token: `bin2hex( random_bytes(32) )`. Only `hash('sha256', $token)` is stored.
- Slot is stored as a transient (`exab_slot_{id}`, TTL 15 min) holding user ID, all announced
  values and the metadata. Limit: at most N (default 10) open slots per user.
- `meta`: `show_in_rest => true`, `mcp => ['public' => true]`,
  `annotations => ['readonly' => false, 'destructive' => false, 'idempotent' => false]`.

### REST `PUT /exab/v1/upload/{slot_id}`

1. Load the slot and check `hash_equals()` against the token hash. Reject expired or used
   slots. The token header is **the only credential**: `permission_callback` returns `true`
   and the check runs in the handler, so a cookie or application password alone gets no access.
2. Mark the slot as `in_progress` right away (single use, no races).
3. Write `$request->get_body()` to `wp_tempnam()`, then compare length and `sha256` (if
   announced). Note: WP core's REST server reads the **whole body into memory**
   (`WP_REST_Server::get_raw_data()`) before the handler runs, so a REST route can't stream.
   That is fine for images (a few MB). For big files, either add chunked uploads
   (`Content-Range`, M3) or hook `rest_pre_serve_request` / a non-REST endpoint that reads
   `php://input` as a stream.
4. `wp_check_filetype_and_ext( $tmp, $filename )` sniffs the **real** type. Reject the file if
   it differs from the announced MIME type, or if it isn't an image when the slot was created
   through the image profile (see §6).
5. `wp_set_current_user( $slot['user_id'] )` and check `upload_files` again (the role may have
   changed in the meantime).
6. `media_handle_sideload( ['name' => sanitize_file_name($filename), 'tmp_name' => $tmp], $post_id )`.
   This runs the normal pipeline: thumbnails, EXIF, and `wp_handle_upload` filters, so other
   plugins (optimizers, Enable Media Replace) keep working.
7. Apply the metadata: `_wp_attachment_image_alt`, `post_title`, `post_excerpt` (caption),
   `post_content` (description). `set_post_thumbnail()` if requested.
8. Mark the slot `completed` with the attachment ID, and fire
   `do_action( 'exab_media_uploaded', $attachment_id, $slot )` for auditing.
9. Response: `{attachment_id, url, mime_type, width, height, sizes, block_markup}`.

Server prerequisites to check: `client_max_body_size` (nginx), `LimitRequestBody` (Apache),
PHP `memory_limit` (the body is held in memory, see step 3) and request timeouts.
`upload_max_filesize` only applies to multipart form uploads, not to a raw `PUT` body. The
plugin enforces `wp_max_upload_size()` itself so that behavior matches wp-admin.

### `exab/upload-media-inline`

Same metadata as above plus `data_base64`, with a hard limit (64 KB decoded by default,
`exab_inline_max_bytes` filter). It's meant for generated SVG icons, favicons and tiny
diagrams. The description tells the model explicitly to use upload slots for anything bigger.

### `exab/update-media`, `exab/get-media-item`, `exab/set-featured-image`

Thin wrappers around `wp_update_post` / `update_post_meta` / `set_post_thumbnail` with
`edit_post` checks. `get-media-item` returns ready-made block markup, for example:

```html
<!-- wp:image {"id":2481,"sizeSlug":"large","linkDestination":"none"} -->
<figure class="wp-block-image size-large"><img src="…-1024x576.png" alt="…" class="wp-image-2481"/></figure>
<!-- /wp:image -->
```

That way the agent can insert the image into post content without guessing URLs for each size.

## 5. Registration sketch

```php
<?php
/**
 * Plugin Name: Extended Abilities
 * Description: Additional MCP-ready abilities: local media upload via upload slots.
 * Requires at least: 6.9
 * Requires PHP: 8.1
 */

add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'exab-media', [
		'label'       => __( 'Media (Extended Abilities)', 'exab' ),
		'description' => __( 'Upload and manage media library items.', 'exab' ),
	] );
} );

add_action( 'wp_abilities_api_init', function () {
	wp_register_ability( 'exab/create-upload-slot', [
		'label'               => __( 'Create Media Upload Slot', 'exab' ),
		'description'         => __( 'Prepares an upload of a LOCAL file. Returns a one-time URL and token; send the raw file with HTTP PUT (see "curl" in the result). Use this instead of base64 for anything larger than a few KB.', 'exab' ),
		'category'            => 'exab-media',
		'input_schema'        => Exab\Schemas::upload_slot_input(),
		'output_schema'       => Exab\Schemas::upload_slot_output(),
		'permission_callback' => [ Exab\Upload_Slots::class, 'can_create' ],
		'execute_callback'    => [ Exab\Upload_Slots::class, 'create' ],
		'meta'                => [
			'show_in_rest' => true,
			'mcp'          => [ 'public' => true ],
			'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
		],
	] );
	// … get-upload-status, upload-media-inline, update-media, get-media-item, set-featured-image
} );

add_action( 'rest_api_init', function () {
	register_rest_route( 'exab/v1', '/upload/(?P<slot>[a-f0-9]{32})', [
		'methods'             => 'PUT',
		'callback'            => [ Exab\Upload_Slots::class, 'receive' ],
		'permission_callback' => '__return_true', // token is checked inside receive()
	] );
} );
```

Suggested layout:

```
extended-abilities/
├── extended-abilities.php        bootstrap, hooks above
├── src/Schemas.php               JSON schemas in one place
├── src/Upload_Slots.php          create / receive / status, transient storage
├── src/Media_Abilities.php       update-media, get-media-item, set-featured-image, inline
├── src/Settings.php              caps: max size, allowed types, inline limit, slot TTL
├── uninstall.php                 delete exab_* transients and options
└── tests/                        wp-env + PHPUnit, plus an end-to-end curl script
```

## 6. Security

| Risk | Mitigation |
|---|---|
| **Exfiltration through the media library.** Prompt injection makes the agent "upload" `~/.ssh/id_ed25519` or `.env`. Media files are **publicly reachable**. | Server: MIME sniffing with `wp_check_filetype_and_ext`, default profile **images only** (jpeg, png, gif, webp, avif), PDFs etc. only through an explicit setting. Client helper: directory allowlist. Claude Code: permission prompts for `curl` stay on. |
| Token leaks (transcript, logs) | Single use, 15 min TTL, bound to one filename, size and hash, stored hashed. A leaked token can upload at most the one announced file. |
| Privilege confusion | Upload runs as the user who created the slot, and the capability is checked again at upload time. The REST route doesn't accept any other auth. |
| Oversized or slow uploads (DoS) | Size announced and enforced, open slots per user limited, web server body limit (it is the only limit that applies *before* PHP buffers the body). |
| Polyglots / SVG XSS | No SVG in the default profile. If enabled later, only with sanitizing (for example `enshrined/svg-sanitize`). |
| Unexpected metadata | Text fields go through `sanitize_text_field` / `wp_kses_post`. EXIF GPS can optionally be stripped (setting `strip_gps`, default **on** for photos from phones). |

## 7. How it plays with the existing plugins

- **MCP Adapter:** nothing to configure. Abilities with `mcp.public` appear in
  `discover-abilities` on the default server. Optionally, a dedicated server
  (`mcp_adapter_init` → `create_server()`) could expose the six abilities as direct MCP tools.
- **EWPA:** complements it, with no overlap except `ewpa/upload-image` (URL sideload, which
  stays useful). The EWPA ability toggle page also lists third-party abilities, so `exab/*` can
  be switched on and off there.
- **AI plugin:** its *Alt Text Generation* experiment can be used in wp-admin afterwards. The
  agent usually writes better alt text itself, because it has seen the local image.
- **Enable Media Replace:** a later `exab/replace-media-file` could reuse the slot mechanism
  and keep the attachment ID.

## 8. Milestones

1. **M1 (done, 0.1.0):** `create-upload-slot`, REST receive, `get-upload-status`, images only.
   Tested end to end in WordPress Playground (21 tests, including the full flow through MCP
   Adapter 0.7.1) instead of wp-env, because the dev machine has no Docker or PHP. Still open: a
   run against a staging copy of the real site (nginx/Apache body limits, real MySQL).
2. **M2 (done, 0.2.0; scope extended 2026-10-18, see §10):** media metadata (`update-media`,
   `get-media-item`, `set-featured-image`), the content gaps found on the live site
   (`schedule-post`, `update-page`, `list-revisions`, `restore-revision`), and a settings page
   that exposes other plugins' abilities to MCP.
3. **M3 (done, 0.3.0, see §11):** `upload-media-inline`, optional PDF uploads, GPS
   removal, chunked (resumable) uploads for large files.
4. **M4 (done, 0.4.0, see §12):** `exab-upload` stdio helper for shell-less clients;
   `replace-media-file`.

## 9. Open questions

- Should slots also be creatable without MCP, through the Abilities REST endpoint
  (`/wp-json/wp-abilities/v1/abilities/exab/create-upload-slot/run`)? That's free with
  `show_in_rest`, so probably yes.
- Is the dedicated agent user an **Author** (uploads and drafts only) or an **Editor**?
  This decides whether `set_featured` works on other people's posts. On a single-author blog
  it doesn't matter.
- Should the plugin stay private (Git deploy) or go to wordpress.org? That affects the slug,
  i18n and the review requirements.

## 10. Milestone 2: filling the gaps

### 10.1 Why the scope grew

An inventory of the live site (2026-10-18, WordPress 7.1.3, 79 abilities over REST) found two
kinds of gaps.

**Abilities that exist but MCP can't see**, because they lack `meta.mcp.public`:

- **Visualizer** registers `visualizer/list-charts`, `get-chart`, `upsert-chart`,
  `set-data-source` and `refresh-chart-data`. They are reachable over REST, but hidden from MCP.
- The **AI plugin** registers its abilities only while the matching experiment is switched on
  (none are on the blog). Even then only `ai/alt-text-generation` and
  `ai/image-prompt-generation` are MCP-public. `core/content-query`, `core/settings-get` and
  `core/users-query` (experiment "Custom Abilities") are REST-only.

**Tasks with no ability at all** (EWPA 2.13.7 + core):

| Gap | Today's workaround |
|---|---|
| Edit alt text, caption or title of a media item | `ewpa/update-post-meta` for alt text only |
| Get one media item with all sizes | `ewpa/get-media` (list) |
| Set or remove a featured image independently | `ewpa/update-post` (posts only) |
| Schedule an existing draft | none: `ewpa/update-post` has no `future` status and no date |
| Update a page | none: only `create-page` / `get-page`; whether `update-post` works on pages is unverified |
| List or restore revisions | none |

Not planned: abilities for users, plugins, themes or core updates. An agent with those rights
could take over the site, so that gap is deliberate.

### 10.2 New abilities

All abilities are MCP-public and `show_in_rest`. Each one checks normal WordPress
capabilities on the specific object. Category `exab-media` for media, new category
`exab-content` for posts, pages and revisions.

| Ability | Input (required in **bold**) | Capability | Annotations |
|---|---|---|---|
| `exab/get-media-item` | **attachment_id**, size (default `large`) | `read_post` on the attachment | read-only |
| `exab/update-media` | **attachment_id**, title, alt_text, caption, description, post_id (0 = detach) | `edit_post` on the attachment; `edit_post` on the new parent | idempotent |
| `exab/set-featured-image` | **post_id**, **attachment_id** (0 = remove) | `edit_post` on the post; attachment must be an image | idempotent |
| `exab/schedule-post` | **post_id**, **date** (ISO 8601; without an offset it is site-local time) | `edit_post` + `publish_posts` of the post type | idempotent |
| `exab/update-page` | **page_id**, title, content, excerpt, slug, status, parent_id, menu_order | `edit_post`; `publish_pages` to publish | idempotent |
| `exab/list-revisions` | **post_id**, limit (default 20) | `edit_post` on the parent | read-only |
| `exab/restore-revision` | **revision_id** | `edit_post` on the parent | not destructive (the current state becomes a new revision) |

Details:

- `get-media-item` returns id, title, alt_text, caption, description, mime_type, url, width,
  height, filesize, parent post, every registered size `{url, width, height}`, and ready-to-paste
  `block_markup` (a `wp:image` block for the requested size, falling back to full).
- `update-media` only changes the fields it is given. An empty string clears a field.
- `schedule-post` rejects dates in the past (WordPress would publish immediately instead). It
  returns the local and GMT dates. To unschedule, set the status back to draft with
  `ewpa/update-post`.
- `update-page` only accepts post type `page`. `parent_id` must be another existing page and
  must not create a cycle.
- `list-revisions` returns id, date (GMT), author, title, `is_autosave` and content length.
  It doesn't return the full content, to keep responses small.
- `restore-revision` uses `wp_restore_post_revision()`, which saves the current state as a new
  revision first, so a restore can itself be undone.

### 10.3 Exposing other plugins' abilities

New page **Settings → Extended Abilities**, visible to `manage_options` only:

- It lists every registered ability **not** already MCP-public, with its name, label, plugin
  namespace and annotations (read-only or destructive). Each one has a checkbox.
- The stored option `exab_expose_abilities` is a list of names. A `wp_register_ability_args`
  filter sets `meta.mcp.public = true` for those names while they are registered.
- Exposing an ability **doesn't bypass anything**. Its own `permission_callback` still runs
  for the calling user.
- Destructive abilities are highlighted, and read-only abilities are listed first.

Example for the blog: expose `visualizer/list-charts`, `visualizer/get-chart` and
`visualizer/upsert-chart`.

### 10.4 Code layout additions

```
src/class-media-abilities.php     get-media-item, update-media, set-featured-image
src/class-content-abilities.php   schedule-post, update-page, list-revisions, restore-revision
src/class-expose-abilities.php    settings page + wp_register_ability_args filter
```

### 10.5 Implementation notes

- Schemas for the M2 abilities live next to their callbacks (`class-media-abilities.php`,
  `class-content-abilities.php`), not in `class-schemas.php`.
- WordPress doesn't save the first version of a post as a revision, only later updates. So
  `list-revisions` can't return the original state of a post that was never updated.
- `wp_get_post_revision()` takes its argument by reference. Passing an expression throws, and
  the tests caught that.
- The settings page writes `exab_expose_abilities` through `options.php`. Uninstall deletes it.

### 10.6 Tests

The existing Playground suite is extended. Each ability gets a test for the normal case and
one for capability checks (author vs. admin vs. subscriber). The exposure setting is tested
by registering a hidden test ability, exposing it, and checking that it shows up through
`mcp-adapter-discover-abilities`. The settings page is rendered once to catch PHP notices.

## 11. Milestone 3: implementation notes

### 11.1 Shared import pipeline

`Importer` (`src/class-importer.php`) holds everything the three upload paths (single PUT,
chunked, inline) share:
- `prepare()`: type, extension, size and post checks, and metadata sanitizing (as the
  requesting user);
- `import_file()`: size and checksum check, permission re-check as the owner, content
  sniffing, GPS removal, `media_handle_sideload()`, alt text and featured image.

The type configuration lives there too. The `mime_type` enum in the schemas is built from it
at registration, so enabling PDF takes effect on the next request.

### 11.2 Chunked uploads

- **Protocol:** `PUT` (or `POST`) to the slot URL with `Content-Range: bytes START-END/TOTAL`.
  TOTAL must equal `size_bytes`.
- **Strictly sequential:** START must equal `received_bytes`. Otherwise the server answers
  `409` with `expected_offset`, which makes resuming trivial and keeps the server simple (no
  sparse files, no reassembly).
- **Locking:** each chunk claims the offset with compare-and-swap (`chunk_lock` holds a
  timestamp). A lock older than 120 s counts as abandoned, so a client that died mid-chunk
  doesn't block the slot forever.
- **Storage:** `uploads/exab-chunks/{slot}-{hmac}.part`. The HMAC uses the auth salt, so the
  name can't be derived from the public slot ID. The uploads directory is used instead of the
  system temp dir because partial files must survive between requests (and, on multi-server
  setups, uploads is the directory most likely to be shared).
- **Expiry:** sliding, so every accepted chunk extends `expires_at` by the TTL.
- **Errors that don't burn the slot:** bad token, wrong content type, malformed or wrong
  ranges, oversized chunks, wrong offset. Write failures and failed final checks (size,
  checksum, content) mark it `failed` and delete the partial file.

### 11.3 GPS removal

- Done in place (`Gps_Scrubber`), never by re-encoding. Re-encoding with GD would lose all
  metadata and image quality. `Imagick::stripImage()` would also drop the orientation and the
  ICC profile.
- **EXIF:** the GPS IFD's entries and every value they point to are zeroed. The GPS pointer in
  IFD0 stays and points to an empty IFD, which is valid TIFF.
- **XMP:** the values of `exif:GPS*` attributes and elements are replaced by spaces of the same
  length.
- **PNG:** CRCs are recomputed for modified chunks.
- **Robustness:** every offset is bounds-checked, and malformed data is left unchanged.
- **Not covered:** AVIF (ISO BMFF `Exif` items) and compressed PNG `zTXt`/`iTXt`. HEIC, the
  default format of iPhone photos, isn't an accepted upload type at all. Convert to JPEG before
  uploading; the GPS data in the resulting JPEG is then removed.

### 11.4 Deviations from the original plan

- No separate `exab-upload` settings for size limits. They remain filters, and only the two
  options with security impact (PDF, GPS) are on the settings page.
- PDF sniffing only checks the `%PDF-` magic. `media_handle_sideload()` additionally runs
  WordPress' own file type checks.

## 12. Milestone 4: implementation notes

### 12.1 `exab/replace-media-file`

- **Reuses the slot machinery.** `Media_Replacer::prepare()` builds slot metadata with
  `replace_attachment_id`, and `Upload_Slots::open_slot()` (split out of `create()`) stores it.
  Chunked uploads, checksums, content sniffing and GPS removal therefore apply unchanged, and
  `Importer` only branches at the very end (`Media_Replacer::replace()` instead of
  `media_handle_sideload()`).
- **Same type only.** The slot's MIME type is the attachment's. Changing type would change
  the extension and therefore every URL.
- **Target path:** the original upload (`wp_get_original_image_path()`), not a `-scaled` or
  `-rotated` copy. Old files go through `wp_delete_attachment_files()`; backups are passed
  only when present, because that branch reads `$meta['file']`, which non-images lack.
- **URL mapping:** old and new URLs of every size, plus `full` (and `original` for scaled
  images). A size that no longer exists maps to the new full URL. `strtr()` applies all
  mappings in one pass, longest match first, so `name.png` never clobbers
  `name-1024x751.png`.
- **Content rewrite:** done through `wp_update_post()`, which creates a revision and fires the
  normal hooks, and only for posts the current user may edit. The others are reported, never
  changed: replacing one's own image must not become a way to edit other people's posts.
- **Annotated destructive** (the old file is gone), so MCP clients ask for confirmation.
- **Not done:** post meta (page builders), `srcset` width descriptors in hard-coded HTML, and
  cache busting. All three are documented as limitations.

### 12.2 `exab-upload` helper

- **Python standard library only**, instead of the Node MCP SDK. One auditable file; nothing
  from a package registry runs with the WordPress credentials. MCP over stdio is small enough
  to implement directly: `initialize` (with version negotiation), `tools/list`, `tools/call`
  and `ping`.
- **Talks REST, not MCP, to WordPress** (`/wp-json/wp-abilities/v1/abilities/*/run`). That is
  simpler than an MCP session, and works even where the MCP Adapter isn't installed.
- **Allowlist:** `os.path.realpath` before the containment check (symlinks and `..`),
  `commonpath`-based containment (no prefix tricks like `/allowed-evil`), hidden components
  refused by default, extension and magic-byte checks before any upload.
- **Chunking:** follows `max_chunk_bytes` from the slot, and resumes at `expected_offset` on a
  409 (at most three times in a row).
- **Tool errors** (refusals, HTTP errors) come back as `isError: true` results that the agent
  can read. Only protocol errors become JSON-RPC errors.
