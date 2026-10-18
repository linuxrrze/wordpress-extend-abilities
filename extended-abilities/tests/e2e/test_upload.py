"""End-to-end tests for upload slots against a running test site.

Usage: python3 -I test_upload.py --base http://127.0.0.1:9400 --creds creds.json [--fixture image.png]
Only uses the standard library.
"""

import argparse
import base64
import hashlib
import json
import struct
import sys
import threading
import urllib.error
import urllib.request
import zlib

BASE = ""
CREDS = {}
FIXTURE = b""


# --- helpers -----------------------------------------------------------------


def make_png(width, height, seed=0):
    """Builds a valid RGB PNG without external libraries."""
    rows = b"".join(
        b"\x00" + bytes(((x * 7 + y * 3 + seed) % 256) for x in range(width * 3))
        for y in range(height)
    )

    def chunk(kind, data):
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data))

    return (
        b"\x89PNG\r\n\x1a\n"
        + chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0))
        + chunk(b"IDAT", zlib.compress(rows))
        + chunk(b"IEND", b"")
    )


def auth_header(user):
    u = CREDS["users"][user]
    token = base64.b64encode(f"{u['login']}:{u['password']}".encode()).decode()
    return {"Authorization": f"Basic {token}"}


def http(method, path_or_url, body=None, headers=None, user=None):
    """Returns (status, parsed JSON or raw text, response headers)."""
    url = path_or_url if path_or_url.startswith("http") else BASE + path_or_url
    headers = dict(headers or {})
    if user:
        headers.update(auth_header(user))
    if isinstance(body, (dict, list)):
        body = json.dumps(body).encode()
        headers.setdefault("Content-Type", "application/json")
    req = urllib.request.Request(url, data=body, method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=120) as resp:
            status, raw, resp_headers = resp.status, resp.read(), resp.headers
    except urllib.error.HTTPError as err:
        status, raw, resp_headers = err.code, err.read(), err.headers
    try:
        return status, json.loads(raw), resp_headers
    except ValueError:
        return status, raw.decode(errors="replace"), resp_headers


def run_ability(name, params, user, readonly=False):
    path = f"/wp-json/wp-abilities/v1/abilities/{name}/run"
    if readonly:
        query = "&".join(f"input[{k}]={v}" for k, v in params.items())
        return http("GET", f"{path}?{query}", user=user)
    return http("POST", path, {"input": params}, user=user)


def slot_params(data, **extra):
    params = {
        "filename": "fixture.png",
        "mime_type": "image/png",
        "size_bytes": len(data),
        "sha256": hashlib.sha256(data).hexdigest(),
    }
    params.update(extra)
    return params


def create_slot(data, user="author", **extra):
    status, body, _ = run_ability("exab/create-upload-slot", slot_params(data, **extra), user)
    assert status == 200, f"create slot: {status} {body}"
    return body


def put(slot, data, content_type="image/png", token=None):
    return http(
        "PUT",
        slot["upload_url"],
        data,
        {"Content-Type": content_type, "X-Exab-Upload-Token": token or slot["upload_token"]},
    )


def status_of(slot, user="author"):
    return run_ability("exab/get-upload-status", {"slot_id": slot["slot_id"]}, user, readonly=True)


def expect_error(result, code, http_status=None):
    status, body, _ = result
    assert isinstance(body, dict) and body.get("code") == code, f"expected {code}, got {status} {body}"
    if http_status is not None:
        assert status == http_status, f"expected HTTP {http_status} for {code}, got {status}"


# --- tests -------------------------------------------------------------------


def test_abilities_are_registered_and_public():
    for name in ("exab/create-upload-slot", "exab/get-upload-status"):
        status, body, _ = http("GET", f"/wp-json/wp-abilities/v1/abilities/{name}", user="author")
        assert status == 200, f"{name}: {status} {body}"
        assert body["meta"]["mcp"]["public"] is True
        assert body["category"] == "exab-media"


def test_happy_path_with_metadata_and_featured_image():
    post_id = CREDS["posts"]["by_author"]
    slot = create_slot(
        FIXTURE,
        filename="architecture.png",
        title="Architecture",
        alt_text="Architecture diagram",
        caption="The <em>caption</em>",
        description="Longer description",
        post_id=post_id,
        set_featured=True,
    )
    assert slot["method"] == "PUT" and "FILE" in slot["curl"] and slot["upload_token"] in slot["curl"]

    status, body, _ = put(slot, FIXTURE)
    assert status == 201, f"upload: {status} {body}"
    assert body["status"] == "completed" and body["set_as_featured"] is True
    attachment_id = body["attachment_id"]
    assert body["url"].endswith(".png")

    status, media, _ = http("GET", f"/wp-json/wp/v2/media/{attachment_id}?context=edit", user="author")
    assert status == 200, media
    assert media["alt_text"] == "Architecture diagram"
    assert media["title"]["raw"] == "Architecture"
    assert media["caption"]["raw"] == "The <em>caption</em>"
    assert media["description"]["raw"] == "Longer description"
    assert media["post"] == post_id
    assert media["author"] == CREDS["users"]["author"]["id"], "attachment must belong to the slot owner"

    status, post, _ = http("GET", f"/wp-json/wp/v2/posts/{post_id}?context=edit", user="author")
    assert post["featured_media"] == attachment_id

    status, st, _ = status_of(slot)
    assert status == 200 and st["status"] == "completed" and st["attachment_id"] == attachment_id


def test_upload_works_with_post_instead_of_put():
    data = make_png(16, 16, seed=1)
    slot = create_slot(data)
    status, body, _ = http(
        "POST", slot["upload_url"], data, {"Content-Type": "image/png", "X-Exab-Upload-Token": slot["upload_token"]}
    )
    assert status == 201, f"{status} {body}"


def test_slot_is_single_use():
    data = make_png(16, 16, seed=2)
    slot = create_slot(data)
    assert put(slot, data)[0] == 201
    expect_error(put(slot, data), "exab_slot_used", 409)


def test_wrong_token_is_rejected():
    data = make_png(16, 16, seed=3)
    slot = create_slot(data)
    expect_error(put(slot, data, token="0" * 64), "exab_invalid_token", 403)
    expect_error(http("PUT", slot["upload_url"], data, {"Content-Type": "image/png"}), "exab_invalid_token", 403)
    assert status_of(slot)[1]["status"] == "pending", "a bad token must not burn the slot"


def test_wrong_content_type_keeps_slot_usable():
    data = make_png(16, 16, seed=4)
    slot = create_slot(data)
    expect_error(put(slot, data, content_type="application/x-www-form-urlencoded"), "exab_wrong_content_type", 415)
    assert put(slot, data)[0] == 201


def test_size_mismatch_fails_slot():
    data = make_png(16, 16, seed=5)
    slot = create_slot(data)
    expect_error(put(slot, data + b"extra"), "exab_size_mismatch", 400)
    status, st, _ = status_of(slot)
    assert st["status"] == "failed" and "bytes" in st["error"]


def test_checksum_mismatch_is_rejected():
    data = make_png(16, 16, seed=6)
    other = make_png(16, 16, seed=7)
    assert len(data) == len(other)
    slot = create_slot(data)
    expect_error(put(slot, other), "exab_checksum_mismatch", 400)


def test_non_image_content_is_rejected():
    fake = b"#!/bin/sh\necho this is not a png\n" * 4
    slot = create_slot(fake, filename="evil.png")
    expect_error(put(slot, fake), "exab_content_mismatch", 415)


def test_gif_disguised_as_png_is_rejected():
    # Real image of another type: content sniffing must catch the mismatch.
    gif = b"GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;"
    slot = create_slot(gif, filename="really-a-gif.png")
    expect_error(put(slot, gif), "exab_content_mismatch", 415)


def test_extension_must_match_mime():
    data = make_png(8, 8)
    expect_error(run_ability("exab/create-upload-slot", slot_params(data, filename="x.jpg"), "author"), "exab_extension_mismatch")


def test_non_image_mime_is_rejected_by_schema():
    status, body, _ = run_ability(
        "exab/create-upload-slot",
        {"filename": "doc.pdf", "mime_type": "application/pdf", "size_bytes": 100},
        "author",
    )
    assert status == 400, f"{status} {body}"


def test_too_large_is_rejected():
    data = make_png(8, 8)
    params = slot_params(data, size_bytes=CREDS["max_upload_bytes"] + 1)
    expect_error(run_ability("exab/create-upload-slot", params, "author"), "exab_file_too_large")


def test_subscriber_cannot_create_slot():
    status, body, _ = run_ability("exab/create-upload-slot", slot_params(make_png(8, 8)), "subscriber")
    assert status == 403, f"{status} {body}"


def test_author_cannot_attach_to_foreign_post():
    params = slot_params(make_png(8, 8), post_id=CREDS["posts"]["by_admin"], set_featured=True)
    status, body, _ = run_ability("exab/create-upload-slot", params, "author")
    assert status == 403, f"{status} {body}"


def test_featured_requires_post():
    expect_error(
        run_ability("exab/create-upload-slot", slot_params(make_png(8, 8), set_featured=True), "author"),
        "exab_featured_needs_post",
    )


def test_status_hides_foreign_slots():
    slot = create_slot(make_png(8, 8, seed=8))
    expect_error(status_of(slot, user="author2"), "exab_slot_not_found")


def test_expired_slot_is_rejected():
    data = make_png(16, 16, seed=9)
    slot = create_slot(data)
    status, body, _ = http("POST", f"/wp-json/exab-e2e/v1/expire/{slot['slot_id']}", user="admin")
    assert status == 200, body
    assert status_of(slot)[1]["status"] == "expired"
    expect_error(put(slot, data), "exab_slot_expired", 410)


def test_open_slot_limit():
    # author2 has no slots before this test.
    for i in range(10):
        create_slot(make_png(4, 4, seed=i), user="author2")
    expect_error(
        run_ability("exab/create-upload-slot", slot_params(make_png(4, 4)), "author2"), "exab_too_many_slots"
    )


def test_parallel_uploads_only_one_wins():
    data = make_png(64, 64, seed=10)
    slot = create_slot(data)
    results = []

    def worker():
        results.append(put(slot, data)[0])

    threads = [threading.Thread(target=worker) for _ in range(4)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    assert sorted(results).count(201) == 1, f"exactly one upload must succeed, got {results}"
    assert all(code in (201, 409) for code in results), results


def test_full_flow_through_mcp_adapter():
    endpoint = "/wp-json/mcp/mcp-adapter-default-server"
    base_headers = {"Accept": "application/json, text/event-stream"}

    def rpc(method, params, msg_id, session=None):
        headers = dict(base_headers)
        if session:
            headers["Mcp-Session-Id"] = session
            headers["MCP-Protocol-Version"] = "2025-06-18"
        payload = {"jsonrpc": "2.0", "method": method, "params": params}
        if msg_id is not None:
            payload["id"] = msg_id
        return http("POST", endpoint, payload, headers, user="author")

    status, body, headers = rpc(
        "initialize",
        {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "exab-e2e", "version": "1"}},
        1,
    )
    assert status == 200, f"initialize: {status} {body}"
    session = headers.get("Mcp-Session-Id")
    rpc("notifications/initialized", {}, None, session)

    def call(ability, params, msg_id):
        status, body, _ = rpc(
            "tools/call",
            {"name": "mcp-adapter-execute-ability", "arguments": {"ability_name": ability, "parameters": params}},
            msg_id,
            session,
        )
        assert status == 200 and "result" in body, f"tools/call {ability}: {status} {body}"
        result = body["result"]
        assert not result.get("isError"), f"{ability} failed: {result}"
        data = result.get("structuredContent") or json.loads(result["content"][0]["text"])
        return data.get("data", data) if isinstance(data, dict) and "success" in data else data

    data = make_png(32, 32, seed=11)
    slot = call("exab/create-upload-slot", slot_params(data, filename="via-mcp.png", alt_text="via MCP"), 2)
    status, uploaded, _ = put(slot, data)
    assert status == 201, f"{status} {uploaded}"
    st = call("exab/get-upload-status", {"slot_id": slot["slot_id"]}, 3)
    assert st["status"] == "completed" and st["attachment_id"] == uploaded["attachment_id"], st


# --- runner ------------------------------------------------------------------


def main():
    global BASE, CREDS, FIXTURE
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", required=True)
    parser.add_argument("--creds", required=True)
    parser.add_argument("--fixture", help="Real image for the happy path (PNG). Default: generated.")
    parser.add_argument("-k", help="Only run tests whose name contains this string")
    args = parser.parse_args()

    BASE = args.base.rstrip("/")
    with open(args.creds) as fh:
        CREDS = json.load(fh)
    if args.fixture:
        with open(args.fixture, "rb") as fh:
            FIXTURE = fh.read()
    else:
        FIXTURE = make_png(400, 300)

    tests = [(n, f) for n, f in globals().items() if n.startswith("test_") and callable(f)]
    if args.k:
        tests = [(n, f) for n, f in tests if args.k in n]

    failed = 0
    for name, func in tests:
        try:
            func()
            print(f"PASS  {name}")
        except Exception as exc:  # noqa: BLE001 - report every failure and continue
            failed += 1
            print(f"FAIL  {name}: {exc}")
    print(f"\n{len(tests) - failed} passed, {failed} failed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
