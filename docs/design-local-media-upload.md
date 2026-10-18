# Design: *Extended Abilities*, local media upload over MCP

Status: **milestone 1 implemented** (see [`../extended-abilities/`](../extended-abilities/README.md)), M2–M4 open · Target: WordPress ≥ 6.9 (tested against 7.1.3), MCP Adapter ≥ 0.7

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
2. **M2:** `update-media`, `get-media-item`, `set-featured-image`, settings page.
3. **M3:** `upload-media-inline`, optional PDF profile, GPS stripping, chunked uploads for
   large files.
4. **M4 (optional):** `exab-upload` stdio helper for shell-less clients; `replace-media-file`.

## 9. Open questions

- Should slots also be creatable without MCP, through the Abilities REST endpoint
  (`/wp-json/wp-abilities/v1/abilities/exab/create-upload-slot/run`)? That's free with
  `show_in_rest`, so probably yes.
- Is the dedicated agent user an **Author** (uploads and drafts only) or an **Editor**?
  This decides whether `set_featured` works on other people's posts. On a single-author blog
  it doesn't matter.
- Should the plugin stay private (Git deploy) or go to wordpress.org? That affects the slug,
  i18n and the review requirements.
