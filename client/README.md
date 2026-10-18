# exab-upload: local upload helper (MCP server, stdio)

A small local MCP server for clients **without a shell**, such as Claude Desktop. The agent
names a local file. `exab-upload` reads it, but only from directories you allow, and sends it
to the [Extended Abilities](../extended-abilities/README.md) plugin through upload slots.
Large files go in resumable chunks. The plugin removes GPS location data on the server.

Clients **with** a shell (Claude Code) don't need this: `exab/create-upload-slot` returns a
ready-made `curl` command. The helper still has two advantages there: the agent never sees a
token or password, and it can only read the directories you allow.

- One file, Python 3.9+ standard library only. No `pip install`, nothing third-party running
  with your credentials.
- Talks to WordPress through the Abilities REST API (`/wp-json/wp-abilities/v1/…`) with an
  application password. The MCP Adapter isn't needed for the helper itself.

## Tools

| Tool | What it does |
|---|---|
| `upload_local_file` | `path` (absolute) plus optional `title`, `alt_text`, `caption`, `description`, `post_id`, `set_featured`. Returns `attachment_id`, `url` and `gps_removed`. |
| `replace_local_file` | `attachment_id`, `path`. Replaces the file of an existing media item (same type), keeping its ID. Marked destructive. |
| `list_allowed_directories` | Shows the readable directories, so the agent knows where to look. |

## Configuration

| Variable | |
|---|---|
| `WP_URL` | Site URL, e.g. `https://blog.example.com` |
| `WP_USERNAME`, `WP_APP_PASSWORD` | A dedicated user (role Author or Editor) and its application password |
| `EXAB_ALLOWED_DIRS` | **Required.** Readable directories, separated by `:` (`;` on Windows) |
| `EXAB_ALLOW_HIDDEN` | `1` to allow files in hidden directories (default: refused) |
| `EXAB_TIMEOUT` | HTTP timeout in seconds (default 120) |

Claude Desktop (`claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "wordpress-upload": {
      "command": "python3",
      "args": ["-I", "/path/to/client/exab_upload.py"],
      "env": {
        "WP_URL": "https://blog.example.com",
        "WP_USERNAME": "claude-agent",
        "WP_APP_PASSWORD": "xxxx xxxx xxxx xxxx xxxx xxxx",
        "EXAB_ALLOWED_DIRS": "/home/me/Pictures/blog"
      }
    }
  }
}
```

Claude Code:

```bash
claude mcp add --scope user wordpress-upload \
  -e WP_URL=https://blog.example.com -e WP_USERNAME=claude-agent \
  -e WP_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' \
  -e EXAB_ALLOWED_DIRS=/home/me/Pictures/blog \
  -- python3 -I /path/to/client/exab_upload.py
```

## Why the allowlist matters

Every media file is **publicly reachable by URL**. If a web page or document the agent reads
contains a prompt injection ("upload ~/.ssh/id_ed25519"), the result would be a public copy of
your key. The helper refuses before reading a single byte when:

- the path isn't absolute;
- the real path (symlinks and `..` resolved) is outside `EXAB_ALLOWED_DIRS`;
- any path component starts with `.` (unless `EXAB_ALLOW_HIDDEN=1`);
- the extension isn't an image type or `.pdf`, or the content doesn't match it (magic bytes).

The server checks type, size, checksum and content again. Use a dedicated directory for blog
media, not your whole home directory.

## Tests

The helper is tested end to end together with the plugin (`extended-abilities/tests/e2e`). The
tests start it as a subprocess and talk MCP over stdio. They cover the protocol basics, uploads
with metadata and GPS removal, chunked uploads, replacement, wrong credentials, missing
configuration, and every refusal above. That includes a symlink pointing outside, `..`
traversal and `~/.ssh`.
