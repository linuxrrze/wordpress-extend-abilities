#!/usr/bin/env python3
"""exab-upload: a local MCP server (stdio) that uploads local files to WordPress.

For MCP clients without a shell (Claude Desktop, claude.ai connectors via a local bridge, …).
The agent names a file; this process reads it from an allowed directory and sends it to the
Extended Abilities plugin (upload slots, chunked for large files). WordPress credentials stay
in this process's environment and never appear in the conversation.

Standard library only. Python 3.9+.

Environment:
  WP_URL              Site URL, e.g. https://blog.example.com (required)
  WP_USERNAME         WordPress user (required)
  WP_APP_PASSWORD     Application password of that user (required)
  EXAB_ALLOWED_DIRS   Directories files may be read from, separated by ":" (";" on Windows).
                      Required. Symlinks are resolved before the check.
  EXAB_ALLOW_HIDDEN   "1" to allow paths with a component starting with "." (default: refused)
  EXAB_TIMEOUT        HTTP timeout in seconds (default 120)

Tools:
  upload_local_file        Upload a file as a new media item.
  replace_local_file       Replace the file of an existing media item.
  list_allowed_directories Show where files may be read from.
"""

import base64
import hashlib
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

SERVER_NAME = "exab-upload"
SERVER_VERSION = "0.4.0"
PROTOCOL_VERSIONS = ("2025-06-18", "2025-03-26", "2024-11-05")

# Extension -> MIME type. The server checks the real content again.
MIME_TYPES = {
    ".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".jpe": "image/jpeg",
    ".png": "image/png", ".gif": "image/gif", ".webp": "image/webp", ".avif": "image/avif",
    ".pdf": "application/pdf",
}
# Magic bytes, checked locally so obviously wrong files never leave the machine.
MAGIC = {
    "image/jpeg": lambda b: b[:3] == b"\xff\xd8\xff",
    "image/png": lambda b: b[:8] == b"\x89PNG\r\n\x1a\n",
    "image/gif": lambda b: b[:6] in (b"GIF87a", b"GIF89a"),
    "image/webp": lambda b: b[:4] == b"RIFF" and b[8:12] == b"WEBP",
    "image/avif": lambda b: b[4:8] == b"ftyp" and b[8:12] in (b"avif", b"avis"),
    "application/pdf": lambda b: b[:5] == b"%PDF-",
}

METADATA_PROPERTIES = {
    "title": {"type": "string", "description": "Media library title (default: file name)."},
    "alt_text": {"type": "string", "description": "Alternative text: describe the image for screen readers."},
    "caption": {"type": "string"},
    "description": {"type": "string"},
    "post_id": {"type": "integer", "description": "Attach to this post."},
    "set_featured": {"type": "boolean", "description": "Also make it the featured image of post_id."},
}

TOOLS = [
    {
        "name": "upload_local_file",
        "description": (
            "Uploads a file from the local disk to the WordPress media library (images; PDF if the site allows it). "
            "Only files inside the allowed directories can be read (see list_allowed_directories). "
            "GPS location data is removed by the site. Returns the attachment ID and URL."
        ),
        "inputSchema": {
            "type": "object",
            "required": ["path"],
            "properties": {"path": {"type": "string", "description": "Absolute path of the local file."}, **METADATA_PROPERTIES},
        },
        "annotations": {"readOnlyHint": False, "destructiveHint": False, "idempotentHint": False, "openWorldHint": True},
    },
    {
        "name": "replace_local_file",
        "description": (
            "Replaces the file of an existing media item with a local file of the same type. Keeps the attachment ID, "
            "title and alt text; the old file is deleted. References in posts the user may edit are updated."
        ),
        "inputSchema": {
            "type": "object",
            "required": ["attachment_id", "path"],
            "properties": {
                "attachment_id": {"type": "integer"},
                "path": {"type": "string", "description": "Absolute path of the local file."},
            },
        },
        "annotations": {"readOnlyHint": False, "destructiveHint": True, "idempotentHint": False, "openWorldHint": True},
    },
    {
        "name": "list_allowed_directories",
        "description": "Lists the local directories that upload_local_file and replace_local_file may read from.",
        "inputSchema": {"type": "object", "properties": {}},
        "annotations": {"readOnlyHint": True, "openWorldHint": False},
    },
]


class ToolError(Exception):
    """An error the agent should see as a tool result (isError), not a protocol error."""


# --- configuration -----------------------------------------------------------


class Config:
    def __init__(self, env):
        missing = [k for k in ("WP_URL", "WP_USERNAME", "WP_APP_PASSWORD", "EXAB_ALLOWED_DIRS") if not env.get(k)]
        if missing:
            raise SystemExit(f"{SERVER_NAME}: missing environment variables: {', '.join(missing)}")
        self.site = env["WP_URL"].rstrip("/")
        token = base64.b64encode(f"{env['WP_USERNAME']}:{env['WP_APP_PASSWORD']}".encode()).decode()
        self.auth = f"Basic {token}"
        self.allowed_dirs = [os.path.realpath(d) for d in env["EXAB_ALLOWED_DIRS"].split(os.pathsep) if d.strip()]
        self.allow_hidden = env.get("EXAB_ALLOW_HIDDEN") == "1"
        self.timeout = float(env.get("EXAB_TIMEOUT", "120"))


# --- local file checks -------------------------------------------------------


def resolve_allowed(path, config):
    """Returns the real path if the file is inside an allowed directory, else raises ToolError."""
    if not path or not os.path.isabs(os.path.expanduser(path)):
        raise ToolError("Give an absolute path.")
    real = os.path.realpath(os.path.expanduser(path))  # resolves symlinks and ".."
    inside = next((d for d in config.allowed_dirs if os.path.commonpath([real, d]) == d), None)
    if not inside:
        raise ToolError(f"{path} is outside the allowed directories: {', '.join(config.allowed_dirs)}")
    if not config.allow_hidden:
        relative = os.path.relpath(real, inside)
        if any(part.startswith(".") for part in relative.split(os.sep)):
            raise ToolError(f"{path} is a hidden file or inside a hidden directory; refusing to upload it.")
    if not os.path.isfile(real):
        raise ToolError(f"{path} is not a regular file.")
    return real


def local_file_info(real):
    ext = os.path.splitext(real)[1].lower()
    mime = MIME_TYPES.get(ext)
    if not mime:
        raise ToolError(f"Unsupported file type {ext or '(none)'}; allowed: {', '.join(sorted(MIME_TYPES))}.")
    with open(real, "rb") as fh:
        head = fh.read(16)
    if not MAGIC[mime](head):
        raise ToolError(f"The content of {os.path.basename(real)} is not a {mime} file.")
    digest = hashlib.sha256()
    with open(real, "rb") as fh:
        for block in iter(lambda: fh.read(1024 * 1024), b""):
            digest.update(block)
    return {"mime": mime, "size": os.path.getsize(real), "sha256": digest.hexdigest()}


# --- WordPress ---------------------------------------------------------------


def http(method, url, body=None, headers=None, timeout=120):
    req = urllib.request.Request(url, data=body, method=method, headers=headers or {})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            status, raw = resp.status, resp.read()
    except urllib.error.HTTPError as err:
        status, raw = err.code, err.read()
    except urllib.error.URLError as err:
        raise ToolError(f"Cannot reach {url}: {err.reason}") from err
    try:
        return status, json.loads(raw)
    except ValueError:
        return status, {"message": raw.decode(errors="replace")[:500]}


def run_ability(config, name, params):
    url = f"{config.site}/wp-json/wp-abilities/v1/abilities/{name}/run"
    status, body = http(
        "POST", url, json.dumps({"input": params}).encode(),
        {"Authorization": config.auth, "Content-Type": "application/json"}, config.timeout,
    )
    if status != 200:
        raise ToolError(f"{name} failed ({status}): {body.get('message', body)}")
    return body


def send_file(config, slot, real, info):
    """Sends the file in one request, or in chunks if it is larger than max_chunk_bytes."""
    headers = {"Content-Type": info["mime"], "X-Exab-Upload-Token": slot["upload_token"]}
    chunk = int(slot.get("max_chunk_bytes") or info["size"])

    if info["size"] <= chunk:
        with open(real, "rb") as fh:
            status, body = http("PUT", slot["upload_url"], fh.read(), headers, config.timeout)
        if status != 201:
            raise ToolError(f"Upload failed ({status}): {body.get('message', body)}")
        return body

    offset, retries = 0, 0
    with open(real, "rb") as fh:
        while offset < info["size"]:
            fh.seek(offset)
            data = fh.read(chunk)
            end = offset + len(data) - 1
            status, body = http(
                "PUT", slot["upload_url"], data,
                dict(headers, **{"Content-Range": f"bytes {offset}-{end}/{info['size']}"}), config.timeout,
            )
            if status == 202:
                offset, retries = body["received_bytes"], 0
            elif status == 201:
                return body
            elif status == 409 and "expected_offset" in body.get("data", {}) and retries < 3:
                offset, retries = body["data"]["expected_offset"], retries + 1  # resume where the server is
            else:
                raise ToolError(f"Chunk at offset {offset} failed ({status}): {body.get('message', body)}")
    raise ToolError("Upload ended without a final response.")


def result_summary(body, info, real):
    summary = {
        "attachment_id": body["attachment_id"],
        "url": body["url"],
        "file": os.path.basename(real),
        "bytes": info["size"],
        "gps_removed": body.get("gps_removed", False),
    }
    if body.get("set_as_featured"):
        summary["set_as_featured"] = True
    if body.get("replaced"):
        summary["posts_updated"] = body.get("posts_updated", [])
        summary["posts_skipped"] = body.get("posts_skipped", [])
    return summary


# --- tools -------------------------------------------------------------------


def tool_upload_local_file(config, args):
    real = resolve_allowed(args.get("path", ""), config)
    info = local_file_info(real)
    params = {"filename": os.path.basename(real), "mime_type": info["mime"], "size_bytes": info["size"], "sha256": info["sha256"]}
    params.update({k: args[k] for k in METADATA_PROPERTIES if k in args})
    slot = run_ability(config, "exab/create-upload-slot", params)
    return result_summary(send_file(config, slot, real, info), info, real)


def tool_replace_local_file(config, args):
    if not isinstance(args.get("attachment_id"), int):
        raise ToolError("attachment_id must be an integer.")
    real = resolve_allowed(args.get("path", ""), config)
    info = local_file_info(real)
    slot = run_ability(
        config, "exab/replace-media-file",
        {"attachment_id": args["attachment_id"], "size_bytes": info["size"], "sha256": info["sha256"]},
    )
    if slot["headers"]["Content-Type"] != info["mime"]:
        raise ToolError(f"The media item is a {slot['headers']['Content-Type']} file, but {real} is {info['mime']}.")
    return result_summary(send_file(config, slot, real, info), info, real)


def tool_list_allowed_directories(config, _args):
    return {"allowed_directories": config.allowed_dirs, "hidden_files_allowed": config.allow_hidden}


HANDLERS = {
    "upload_local_file": tool_upload_local_file,
    "replace_local_file": tool_replace_local_file,
    "list_allowed_directories": tool_list_allowed_directories,
}


# --- MCP over stdio (newline-delimited JSON-RPC 2.0) ----------------------------


def handle(config, message):
    """Returns a response dict, or None for notifications."""
    method, msg_id = message.get("method"), message.get("id")
    if msg_id is None:
        return None  # notifications (initialized, cancelled, …) need no answer

    def ok(result):
        return {"jsonrpc": "2.0", "id": msg_id, "result": result}

    if method == "initialize":
        requested = (message.get("params") or {}).get("protocolVersion")
        return ok({
            "protocolVersion": requested if requested in PROTOCOL_VERSIONS else PROTOCOL_VERSIONS[0],
            "capabilities": {"tools": {}},
            "serverInfo": {"name": SERVER_NAME, "version": SERVER_VERSION},
            "instructions": "Uploads local files to WordPress. Call list_allowed_directories to see readable locations.",
        })
    if method == "ping":
        return ok({})
    if method == "tools/list":
        return ok({"tools": TOOLS})
    if method == "tools/call":
        params = message.get("params") or {}
        handler = HANDLERS.get(params.get("name"))
        if not handler:
            return {"jsonrpc": "2.0", "id": msg_id, "error": {"code": -32602, "message": f"Unknown tool: {params.get('name')}"}}
        try:
            data = handler(config, params.get("arguments") or {})
            return ok({"content": [{"type": "text", "text": json.dumps(data)}], "structuredContent": data, "isError": False})
        except ToolError as err:
            return ok({"content": [{"type": "text", "text": str(err)}], "isError": True})
        except OSError as err:
            return ok({"content": [{"type": "text", "text": f"Local file error: {err}"}], "isError": True})
    return {"jsonrpc": "2.0", "id": msg_id, "error": {"code": -32601, "message": f"Method not found: {method}"}}


def main():
    config = Config(os.environ)
    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        try:
            message = json.loads(line)
        except ValueError:
            response = {"jsonrpc": "2.0", "id": None, "error": {"code": -32700, "message": "Parse error"}}
        else:
            response = handle(config, message)
        if response is not None:
            sys.stdout.write(json.dumps(response) + "\n")
            sys.stdout.flush()


if __name__ == "__main__":
    main()
