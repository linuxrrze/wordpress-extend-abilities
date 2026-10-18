"""End-to-end tests for upload slots against a running test site.

Usage: python3 -I test_upload.py --base http://127.0.0.1:9400 --creds creds.json [--fixture image.png]
Only uses the standard library.
"""

import argparse
import base64
import hashlib
import json
import os
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


def expect_forbidden(result):
    """A denied permission check: HTTP 403 with the Abilities API's code, not a crash that happens to be 403."""
    status, body, _ = result
    code = body.get("code") if isinstance(body, dict) else None
    assert status == 403 and code == "rest_ability_cannot_execute", f"expected permission denial, got {status} {body}"


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
    expect_forbidden(run_ability("exab/create-upload-slot", slot_params(make_png(8, 8)), "subscriber"))


def test_author_cannot_attach_to_foreign_post():
    params = slot_params(make_png(8, 8), post_id=CREDS["posts"]["by_admin"], set_featured=True)
    expect_forbidden(run_ability("exab/create-upload-slot", params, "author"))


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


class McpClient:
    """Minimal MCP client for the adapter's default server (streamable HTTP, JSON responses)."""

    ENDPOINT = "/wp-json/mcp/mcp-adapter-default-server"
    VERSION = "2025-06-18"

    def __init__(self, user):
        self.user = user
        self.session = None
        self.next_id = 1
        status, body, headers = self._rpc(
            "initialize",
            {"protocolVersion": self.VERSION, "capabilities": {}, "clientInfo": {"name": "exab-e2e", "version": "1"}},
        )
        assert status == 200, f"initialize: {status} {body}"
        self.session = headers.get("Mcp-Session-Id")
        self._rpc("notifications/initialized", {}, notification=True)

    def _rpc(self, method, params, notification=False):
        headers = {"Accept": "application/json, text/event-stream"}
        if self.session:
            headers["Mcp-Session-Id"] = self.session
            headers["MCP-Protocol-Version"] = self.VERSION
        payload = {"jsonrpc": "2.0", "method": method, "params": params}
        if not notification:
            payload["id"] = self.next_id
            self.next_id += 1
        return http("POST", self.ENDPOINT, payload, headers, user=self.user)

    def tool(self, name, arguments):
        """Calls an MCP tool; returns (is_error, decoded payload)."""
        status, body, _ = self._rpc("tools/call", {"name": name, "arguments": arguments})
        assert status == 200 and "result" in body, f"tools/call {name}: {status} {body}"
        result = body["result"]
        data = result.get("structuredContent")
        if data is None:
            text = result["content"][0]["text"]
            try:
                data = json.loads(text)
            except ValueError:
                data = text
        return bool(result.get("isError")), data

    def execute(self, ability, params):
        is_error, data = self.tool(
            "mcp-adapter-execute-ability", {"ability_name": ability, "parameters": params}
        )
        assert not is_error, f"{ability} failed: {data}"
        return data.get("data", data) if isinstance(data, dict) and "success" in data else data

    def ability_names(self):
        is_error, data = self.tool("mcp-adapter-discover-abilities", {})
        assert not is_error, data
        abilities = data.get("abilities", data.get("data", {}).get("abilities", [])) if isinstance(data, dict) else []
        return {a["name"] for a in abilities}


def test_full_flow_through_mcp_adapter():
    mcp = McpClient("author")
    data = make_png(32, 32, seed=11)
    slot = mcp.execute("exab/create-upload-slot", slot_params(data, filename="via-mcp.png", alt_text="via MCP"))
    status, uploaded, _ = put(slot, data)
    assert status == 201, f"{status} {uploaded}"
    st = mcp.execute("exab/get-upload-status", {"slot_id": slot["slot_id"]})
    assert st["status"] == "completed" and st["attachment_id"] == uploaded["attachment_id"], st


# --- milestone 2: media ------------------------------------------------------

STATE = {}


def upload(data, user="author", **extra):
    """Uploads through a slot and returns the attachment ID."""
    slot = create_slot(data, user=user, **extra)
    status, body, _ = put(slot, data)
    assert status == 201, f"upload: {status} {body}"
    return body["attachment_id"]


def big_attachment():
    """The real fixture, uploaded once (it has sub-sizes)."""
    if "big" not in STATE:
        STATE["big"] = upload(FIXTURE, filename="m2-big.png", alt_text="Big alt", caption="Big caption")
    return STATE["big"]


def test_m2_abilities_are_registered_and_public():
    names = McpClient("author").ability_names()
    for name in (
        "exab/get-media-item", "exab/update-media", "exab/set-featured-image",
        "exab/schedule-post", "exab/update-page", "exab/list-revisions", "exab/restore-revision",
    ):
        assert name in names, f"{name} missing from discover-abilities"


def test_get_media_item_with_sizes_and_block_markup():
    attachment_id = big_attachment()
    status, item, _ = run_ability("exab/get-media-item", {"attachment_id": attachment_id, "size": "medium"}, "author", readonly=True)
    assert status == 200, f"{status} {item}"
    assert item["attachment_id"] == attachment_id and item["mime_type"] == "image/png"
    assert item["alt_text"] == "Big alt" and item["caption"] == "Big caption"
    assert "full" in item["sizes"] and item["width"] == item["sizes"]["full"]["width"]
    if item["width"] > 300:
        assert "medium" in item["sizes"], item["sizes"].keys()
    markup = item["block_markup"]
    assert markup.startswith("<!-- wp:image {") and markup.rstrip().endswith("<!-- /wp:image -->")
    assert f'"id":{attachment_id}' in markup and f"wp-image-{attachment_id}" in markup
    assert 'alt="Big alt"' in markup and "Big caption" in markup
    assert item["sizes"].get("medium", item["sizes"]["full"])["url"] in markup


def test_get_media_item_unknown_size_falls_back_to_full():
    attachment_id = big_attachment()
    status, item, _ = run_ability("exab/get-media-item", {"attachment_id": attachment_id, "size": "nope"}, "author", readonly=True)
    assert status == 200 and "size-full" in item["block_markup"], item


def test_get_media_item_not_found():
    expect_error(
        run_ability("exab/get-media-item", {"attachment_id": 999999}, "author", readonly=True),
        "exab_attachment_not_found",
    )


def test_update_media_changes_only_given_fields():
    attachment_id = upload(make_png(16, 16, seed=20), alt_text="old alt", title="Old title", caption="old caption",
                           post_id=CREDS["posts"]["by_author"])
    status, item, _ = run_ability(
        "exab/update-media", {"attachment_id": attachment_id, "title": "New title", "alt_text": "new alt"}, "author"
    )
    assert status == 200, f"{status} {item}"
    assert item["title"] == "New title" and item["alt_text"] == "new alt", item
    assert item["caption"] == "old caption" and item["post_id"] == CREDS["posts"]["by_author"], item

    # Empty string clears, post_id 0 detaches.
    status, item, _ = run_ability(
        "exab/update-media", {"attachment_id": attachment_id, "alt_text": "", "caption": "", "post_id": 0}, "author"
    )
    assert status == 200, item
    assert item["alt_text"] == "" and item["caption"] == "" and item["post_id"] is None, item

    status, media, _ = http("GET", f"/wp-json/wp/v2/media/{attachment_id}?context=edit", user="author")
    assert media["alt_text"] == "" and media["post"] is None and media["title"]["raw"] == "New title", media


def test_update_media_forbidden_for_other_author():
    attachment_id = upload(make_png(16, 16, seed=21))
    expect_forbidden(run_ability("exab/update-media", {"attachment_id": attachment_id, "title": "x"}, "author2"))


def test_set_and_remove_featured_image():
    post_id = CREDS["posts"]["to_schedule"]
    attachment_id = upload(make_png(16, 16, seed=22))
    status, body, _ = run_ability("exab/set-featured-image", {"post_id": post_id, "attachment_id": attachment_id}, "author")
    assert status == 200 and body["featured_image_id"] == attachment_id, f"{status} {body}"
    # Setting the same image again is fine (idempotent).
    status, body, _ = run_ability("exab/set-featured-image", {"post_id": post_id, "attachment_id": attachment_id}, "author")
    assert status == 200, f"{status} {body}"
    assert http("GET", f"/wp-json/wp/v2/posts/{post_id}?context=edit", user="author")[1]["featured_media"] == attachment_id

    status, body, _ = run_ability("exab/set-featured-image", {"post_id": post_id, "attachment_id": 0}, "author")
    assert status == 200 and body["featured_image_id"] is None, body
    assert http("GET", f"/wp-json/wp/v2/posts/{post_id}?context=edit", user="author")[1]["featured_media"] == 0


def test_set_featured_image_forbidden_on_foreign_post():
    attachment_id = upload(make_png(16, 16, seed=23))
    expect_forbidden(run_ability(
        "exab/set-featured-image", {"post_id": CREDS["posts"]["by_admin"], "attachment_id": attachment_id}, "author"
    ))


# --- milestone 2: content ----------------------------------------------------


def test_schedule_post_with_offset():
    post_id = CREDS["posts"]["to_schedule"]
    status, body, _ = run_ability("exab/schedule-post", {"post_id": post_id, "date": "2030-01-15T10:00:00+02:00"}, "author")
    assert status == 200, f"{status} {body}"
    assert body["status"] == "future"
    assert body["date_gmt"] == "2030-01-15T08:00:00+00:00", body
    # Site time zone is Europe/Berlin (UTC+1 in January).
    assert body["date_local"] == "2030-01-15T09:00:00+01:00", body
    post = http("GET", f"/wp-json/wp/v2/posts/{post_id}?context=edit", user="author")[1]
    assert post["status"] == "future" and post["date_gmt"] == "2030-01-15T08:00:00"


def test_schedule_post_naive_date_uses_site_timezone_and_reschedules():
    post_id = CREDS["posts"]["to_schedule"]  # already scheduled by the previous test
    status, body, _ = run_ability("exab/schedule-post", {"post_id": post_id, "date": "2030-07-01 12:30"}, "author")
    assert status == 200, f"{status} {body}"
    # Europe/Berlin is UTC+2 in July.
    assert body["date_local"] == "2030-07-01T12:30:00+02:00" and body["date_gmt"] == "2030-07-01T10:30:00+00:00", body


def test_schedule_post_rejects_past_and_published_and_garbage():
    expect_error(
        run_ability("exab/schedule-post", {"post_id": CREDS["posts"]["to_schedule"], "date": "2001-01-01 00:00"}, "author"),
        "exab_date_in_past",
    )
    expect_error(
        run_ability("exab/schedule-post", {"post_id": CREDS["posts"]["published_by_author"], "date": "2030-01-01 00:00"}, "author"),
        "exab_not_schedulable",
    )
    expect_error(
        run_ability("exab/schedule-post", {"post_id": CREDS["posts"]["to_schedule"], "date": "next tuesday-ish!"}, "author"),
        "exab_invalid_date",
    )


def test_schedule_post_forbidden():
    expect_forbidden(run_ability("exab/schedule-post", {"post_id": CREDS["posts"]["by_admin"], "date": "2030-01-01 00:00"}, "author"))
    expect_forbidden(run_ability("exab/schedule-post", {"post_id": CREDS["posts"]["to_schedule"], "date": "2030-01-01 00:00"}, "subscriber"))


def test_update_page():
    page_id = CREDS["posts"]["child_page"]
    params = {
        "page_id": page_id,
        "title": "Updated child",
        "content": "<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->",
        "slug": "updated-child",
        "menu_order": 3,
        "status": "publish",
        "parent_id": 0,
    }
    status, body, _ = run_ability("exab/update-page", params, "admin")
    assert status == 200, f"{status} {body}"
    assert body["status"] == "publish" and body["slug"] == "updated-child" and body["parent_id"] == 0
    page = http("GET", f"/wp-json/wp/v2/pages/{page_id}?context=edit", user="admin")[1]
    assert page["title"]["raw"] == "Updated child" and page["menu_order"] == 3
    assert "<p>Hello</p>" in page["content"]["raw"]

    # Back under the parent, nothing else changes.
    status, body, _ = run_ability("exab/update-page", {"page_id": page_id, "parent_id": CREDS["posts"]["parent_page"]}, "admin")
    assert status == 200 and body["parent_id"] == CREDS["posts"]["parent_page"], body
    assert body["slug"] == "updated-child"


def test_update_page_prevents_parent_loop():
    expect_error(
        run_ability("exab/update-page", {"page_id": CREDS["posts"]["parent_page"], "parent_id": CREDS["posts"]["child_page"]}, "admin"),
        "exab_parent_loop",
    )
    expect_error(
        run_ability("exab/update-page", {"page_id": CREDS["posts"]["parent_page"], "parent_id": CREDS["posts"]["parent_page"]}, "admin"),
        "exab_parent_loop",
    )


def test_update_page_rejects_posts_and_authors():
    expect_error(
        run_ability("exab/update-page", {"page_id": CREDS["posts"]["by_author"], "title": "x"}, "admin"),
        "exab_page_not_found",
    )
    expect_forbidden(run_ability("exab/update-page", {"page_id": CREDS["posts"]["parent_page"], "title": "x"}, "author"))


def test_list_and_restore_revisions():
    post_id = CREDS["posts"]["with_revisions"]
    status, body, _ = run_ability("exab/list-revisions", {"post_id": post_id}, "author", readonly=True)
    assert status == 200, f"{status} {body}"
    revisions = body["revisions"]
    titles = [r["title"] for r in revisions]
    assert titles[:3] == ["Revisions v4", "Revisions v3", "Revisions v2"], titles
    assert not any(r["is_autosave"] for r in revisions)

    v2 = next(r for r in revisions if r["title"] == "Revisions v2")
    status, body, _ = run_ability("exab/restore-revision", {"revision_id": v2["revision_id"]}, "author")
    assert status == 200 and body["post_id"] == post_id, f"{status} {body}"
    post = http("GET", f"/wp-json/wp/v2/posts/{post_id}?context=edit", user="author")[1]
    assert post["title"]["raw"] == "Revisions v2" and "content version 2" in post["content"]["raw"], post["title"]

    # The restore is itself undoable: it added a new revision, and v4 is still there.
    status, body, _ = run_ability("exab/list-revisions", {"post_id": post_id, "limit": 100}, "author", readonly=True)
    titles = [r["title"] for r in body["revisions"]]
    assert titles[0] == "Revisions v2" and "Revisions v4" in titles, titles


def test_revisions_forbidden_for_other_author():
    post_id = CREDS["posts"]["with_revisions"]
    expect_forbidden(run_ability("exab/list-revisions", {"post_id": post_id}, "author2", readonly=True))
    revision_id = run_ability("exab/list-revisions", {"post_id": post_id}, "author", readonly=True)[1]["revisions"][0]["revision_id"]
    expect_forbidden(run_ability("exab/restore-revision", {"revision_id": revision_id}, "author2"))


# --- milestone 2: expose other plugins' abilities ----------------------------


def test_expose_setting_makes_hidden_ability_visible_to_mcp():
    hidden = "exab-e2e/hidden-echo"
    assert hidden not in McpClient("author").ability_names(), "test ability must start hidden"

    status, body, _ = http("POST", "/wp-json/exab-e2e/v1/expose", {"names": [hidden, "Not A Valid Name!"]}, user="admin")
    assert status == 200 and body["exposed"] == [hidden], body

    mcp = McpClient("author")
    assert hidden in mcp.ability_names()
    assert mcp.execute(hidden, {"text": "hi"}) == {"echo": "hi"}

    # Exposing does not bypass permissions: an author still cannot run an admin-only ability.
    http("POST", "/wp-json/exab-e2e/v1/expose", {"names": [hidden, "exab-e2e/hidden-wipe"]}, user="admin")
    is_error, data = McpClient("author").tool(
        "mcp-adapter-execute-ability", {"ability_name": "exab-e2e/hidden-wipe", "parameters": {}}
    )
    assert is_error, f"author must not run hidden-wipe: {data}"

    http("POST", "/wp-json/exab-e2e/v1/expose", {"names": []}, user="admin")
    assert hidden not in McpClient("author").ability_names()


def test_settings_page_renders():
    http("POST", "/wp-json/exab-e2e/v1/expose", {"names": ["exab-e2e/hidden-wipe"]}, user="admin")
    status, body, _ = http("GET", "/wp-json/exab-e2e/v1/render-settings", user="admin")
    http("POST", "/wp-json/exab-e2e/v1/expose", {"names": []}, user="admin")
    assert status == 200, body
    html = body["html"]
    assert "exab-e2e/hidden-echo" in html and "exab-e2e/hidden-wipe" in html
    assert "destructive" in html and "read-only" in html
    assert 'name="exab_expose_abilities[]"' in html
    assert 'name="exab_strip_gps"' in html and 'name="exab_allow_pdf"' in html
    strip = html[html.index('name="exab_strip_gps"'):][:120]
    assert "checked" in strip, "GPS removal is on by default"
    assert html.index("hidden-echo") < html.index("hidden-wipe"), "read-only abilities come first"
    # Abilities that are public on their own are not listed.
    assert "exab/create-upload-slot" not in html
    # The exposed one is checked.
    wipe_input = html[html.index('value="exab-e2e/hidden-wipe"'):][:200]
    assert "checked" in wipe_input


# --- milestone 3: helpers ----------------------------------------------------

FIXTURES_DIR = ""

LAT = (49, 1, 27, 1, 1234, 100)
LON = (11, 1, 4, 1, 5678, 100)

XMP_PACKET = (
    b'<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
    b'<rdf:Description xmlns:exif="http://ns.adobe.com/exif/1.0/" xmlns:tiff="http://ns.adobe.com/tiff/1.0/" '
    b"tiff:Make=\"TestCam\" exif:GPSLatitude=\"49,27.2057N\" exif:GPSLongitude='11,4.9463E'>"
    b"<exif:GPSAltitude>350/1</exif:GPSAltitude></rdf:Description></rdf:RDF></x:xmpmeta>"
)


def fixture(name):
    with open(f"{FIXTURES_DIR}/{CREDS['fixtures'][name]}", "rb") as fh:
        return fh.read()


def download(url):
    with urllib.request.urlopen(url, timeout=60) as resp:
        return resp.read()


def build_tiff(order="<", orientation=1):
    """EXIF block: IFD0 with Make, Orientation and a GPS IFD (lat/lon as out-of-line rationals)."""
    head = (b"II" if order == "<" else b"MM") + struct.pack(order + "HI", 42, 8)
    ifd0 = struct.pack(order + "H", 3)
    ifd0 += struct.pack(order + "HHII", 0x010F, 2, 8, 50)                         # Make -> offset 50
    ifd0 += struct.pack(order + "HHI", 0x0112, 3, 1) + struct.pack(order + "HH", orientation, 0)
    ifd0 += struct.pack(order + "HHII", 0x8825, 4, 1, 58)                         # GPS IFD at 58
    ifd0 += struct.pack(order + "I", 0)
    gps = struct.pack(order + "H", 4)
    gps += struct.pack(order + "HHI", 1, 2, 2) + b"N\0\0\0"
    gps += struct.pack(order + "HHII", 2, 5, 3, 112)                              # latitude -> 112
    gps += struct.pack(order + "HHI", 3, 2, 2) + b"E\0\0\0"
    gps += struct.pack(order + "HHII", 4, 5, 3, 136)                              # longitude -> 136
    gps += struct.pack(order + "I", 0)
    tiff = head + ifd0 + b"TestCam\0" + gps + struct.pack(order + "6I", *LAT) + struct.pack(order + "6I", *LON)
    assert len(tiff) == 160
    return tiff


def parse_tiff(tiff):
    order = "<" if tiff[:2] == b"II" else ">"
    ifd0 = struct.unpack(order + "I", tiff[4:8])[0]
    out = {"gps_count": None, "make": None, "orientation": None}
    for i in range(struct.unpack(order + "H", tiff[ifd0:ifd0 + 2])[0]):
        e = ifd0 + 2 + i * 12
        tag, _typ, count = struct.unpack(order + "HHI", tiff[e:e + 8])
        value = tiff[e + 8:e + 12]
        if tag == 0x010F:
            off = struct.unpack(order + "I", value)[0]
            out["make"] = tiff[off:off + count].rstrip(b"\0").decode()
        elif tag == 0x0112:
            out["orientation"] = struct.unpack(order + "H", value[:2])[0]
        elif tag == 0x8825:
            g = struct.unpack(order + "I", value)[0]
            out["gps_count"] = struct.unpack(order + "H", tiff[g:g + 2])[0]
    out["coordinates_present"] = (
        struct.pack(order + "6I", *LAT) in tiff or struct.pack(order + "6I", *LON) in tiff
    )
    return out


def assert_xmp_scrubbed(xmp):
    assert b'tiff:Make="TestCam"' in xmp, "non-GPS XMP must survive"
    assert b"49,27" not in xmp and b"11,4" not in xmp and b"350/1" not in xmp, xmp
    assert b"exif:GPSLatitude=" in xmp, "structure stays, only values are blanked"


def jpeg_segments(data):
    """(marker, payload) of all segments before the image data."""
    assert data[:2] == b"\xff\xd8"
    pos, out = 2, []
    while pos + 4 <= len(data) and data[pos] == 0xFF:
        marker = data[pos + 1]
        if marker == 0xDA:
            break
        length = struct.unpack(">H", data[pos + 2:pos + 4])[0]
        out.append((marker, data[pos + 4:pos + 2 + length]))
        pos += 2 + length
    return out


def jpeg_with_gps(jpeg, orientation=1):
    def app1(payload):
        return b"\xff\xe1" + struct.pack(">H", len(payload) + 2) + payload

    pos = 2
    if jpeg[2:4] == b"\xff\xe0":  # keep JFIF APP0 first
        pos = 4 + struct.unpack(">H", jpeg[4:6])[0]
    extra = app1(b"Exif\0\0" + build_tiff(orientation=orientation)) + app1(b"http://ns.adobe.com/xap/1.0/\0" + XMP_PACKET)
    return jpeg[:pos] + extra + jpeg[pos:]


def check_jpeg_scrubbed(data, orientation=1):
    exif_payload = next(p for m, p in jpeg_segments(data) if m == 0xE1 and p.startswith(b"Exif\0\0"))
    exif = parse_tiff(exif_payload[6:])
    assert exif["make"] == "TestCam" and exif["orientation"] == orientation, exif
    assert exif["gps_count"] == 0 and not exif["coordinates_present"], exif
    xmp = next(p for m, p in jpeg_segments(data) if m == 0xE1 and p.startswith(b"http://ns.adobe.com/xap"))
    assert_xmp_scrubbed(xmp)


def png_chunk(kind, data):
    return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data))


def png_chunks(data):
    pos, out = 8, []
    while pos + 12 <= len(data):
        length = struct.unpack(">I", data[pos:pos + 4])[0]
        kind, body = data[pos + 4:pos + 8], data[pos + 8:pos + 8 + length]
        crc = struct.unpack(">I", data[pos + 8 + length:pos + 12 + length])[0]
        out.append((kind, body, crc == zlib.crc32(kind + body)))
        pos += 12 + length
    return out


def png_with_gps(png):
    itxt = b"XML:com.adobe.xmp\0" + b"\0\0" + b"\0" + b"\0" + XMP_PACKET
    extra = png_chunk(b"eXIf", build_tiff(order=">")) + png_chunk(b"iTXt", itxt)
    return png[:33] + extra + png[33:]  # right after IHDR


def webp_chunks(data):
    pos, out = 12, []
    while pos + 8 <= len(data):
        kind, length = data[pos:pos + 4], struct.unpack("<I", data[pos + 4:pos + 8])[0]
        out.append((kind, data[pos + 8:pos + 8 + length]))
        pos += 8 + length + (length % 2)
    return out


def webp_with_gps(webp, width, height):
    """Turns a simple WebP into an extended one (VP8X) with EXIF and XMP chunks."""
    assert webp[:4] == b"RIFF" and webp[8:12] == b"WEBP"

    def chunk(kind, data):
        return kind + struct.pack("<I", len(data)) + data + (b"\0" if len(data) % 2 else b"")

    vp8x = chunk(b"VP8X", bytes([0x08 | 0x04, 0, 0, 0]) + (width - 1).to_bytes(3, "little") + (height - 1).to_bytes(3, "little"))
    payload = b"WEBP" + vp8x + webp[12:] + chunk(b"EXIF", build_tiff()) + chunk(b"XMP ", XMP_PACKET)
    return b"RIFF" + struct.pack("<I", len(payload)) + payload


def set_option(name, value):
    status, body, _ = http("POST", "/wp-json/exab-e2e/v1/option", {"name": name, "value": value}, user="admin")
    assert status == 200, body


def upload_file(data, filename, mime, user="author", **extra):
    slot = create_slot(data, user=user, filename=filename, mime_type=mime, **extra)
    status, body, _ = put(slot, data, content_type=mime)
    assert status == 201, f"upload {filename}: {status} {body}"
    return body


# --- milestone 3: GPS removal ------------------------------------------------


def test_gps_removed_from_jpeg_exif_and_xmp():
    original = jpeg_with_gps(fixture("jpeg"))
    body = upload_file(original, "gps.jpg", "image/jpeg")
    assert body["gps_removed"] is True, body
    stored = download(body["url"])
    assert len(stored) == len(original), "scrubbing must not change the file size"
    check_jpeg_scrubbed(stored)


def test_orientation_survives_so_wordpress_still_rotates():
    # Orientation 6 = rotate 90°. WordPress reads it after our scrub and creates a rotated copy.
    body = upload_file(jpeg_with_gps(fixture("jpeg"), orientation=6), "rotate.jpg", "image/jpeg")
    assert body["gps_removed"] is True
    item = run_ability("exab/get-media-item", {"attachment_id": body["attachment_id"]}, "author", readonly=True)[1]
    assert "-rotated" in item["url"] and item["width"] == 480 and item["height"] == 640, item


def test_gps_kept_when_disabled():
    set_option("exab_strip_gps", "0")
    try:
        original = jpeg_with_gps(fixture("jpeg"))
        body = upload_file(original, "keep-gps.jpg", "image/jpeg")
    finally:
        set_option("exab_strip_gps", "1")
    assert body["gps_removed"] is False
    assert download(body["url"]) == original


def test_jpeg_without_gps_is_untouched():
    original = fixture("jpeg")
    body = upload_file(original, "no-gps.jpg", "image/jpeg")
    assert body["gps_removed"] is False
    assert download(body["url"]) == original


def test_gps_removed_from_png_with_valid_crcs():
    original = png_with_gps(make_png(64, 48, seed=30))
    body = upload_file(original, "gps.png", "image/png")
    assert body["gps_removed"] is True, body
    chunks = png_chunks(download(body["url"]))
    assert all(ok for _, _, ok in chunks), "every chunk CRC must be valid after scrubbing"
    exif = parse_tiff(next(b for k, b, _ in chunks if k == b"eXIf"))
    assert exif["gps_count"] == 0 and not exif["coordinates_present"] and exif["make"] == "TestCam", exif
    assert_xmp_scrubbed(next(b for k, b, _ in chunks if k == b"iTXt"))


def test_gps_removed_from_webp():
    width, height = CREDS["fixtures"]["webp_size"]
    original = webp_with_gps(fixture("webp"), width, height)
    body = upload_file(original, "gps.webp", "image/webp")
    assert body["gps_removed"] is True, body
    chunks = dict(webp_chunks(download(body["url"])))
    exif = parse_tiff(chunks[b"EXIF"])
    assert exif["gps_count"] == 0 and not exif["coordinates_present"], exif
    assert_xmp_scrubbed(chunks[b"XMP "])


def test_scrubber_handles_big_endian_and_malformed_input():
    def scrub(data, mime):
        status, body, _ = http(
            "POST", "/wp-json/exab-e2e/v1/scrub",
            {"mime": mime, "data_base64": base64.b64encode(data).decode()}, user="admin",
        )
        assert status == 200, body
        return body["removed"], base64.b64decode(body["data_base64"])

    # Big-endian TIFF inside a JPEG.
    jpeg = b"\xff\xd8" + b"\xff\xe1" + struct.pack(">H", 2 + 6 + 160) + b"Exif\0\0" + build_tiff(order=">") + b"\xff\xd9"
    removed, out = scrub(jpeg, "image/jpeg")
    assert removed and parse_tiff(out[12:])["gps_count"] == 0  # SOI + APP1 marker + length + "Exif\0\0"

    # Malformed inputs: nothing removed, bytes unchanged, no PHP warnings (checked via debug log).
    good = build_tiff()
    # IFD0 starts at 8; its 3rd entry (GPS pointer) is at 8 + 2 + 2 * 12 = 34, value at 42..46.
    broken_gps_offset = good[:42] + struct.pack("<I", 9999) + good[46:]
    cases = [
        b"\xff\xd8\xff\xe1\x00\x10Exif\0\0II*\0",                                           # truncated TIFF
        b"\xff\xd8\xff\xe1\xff\xffExif\0\0",                                                 # segment longer than file
        b"\xff\xd8\xff\xe1" + struct.pack(">H", 168) + b"Exif\0\0" + broken_gps_offset,    # GPS IFD out of bounds
        b"\xff\xd8" + os.urandom(64),
        b"not an image at all",
    ]
    for case in cases:
        removed, out = scrub(case, "image/jpeg")
        assert not removed and out == case, case
    for mime in ("image/png", "image/webp"):
        removed, out = scrub(b"RIFF\x04\x00\x00\x00WEBPjunk" if mime == "image/webp" else b"\x89PNG\r\n\x1a\n" + b"\xff" * 20, mime)
        assert not removed


# --- milestone 3: chunked uploads --------------------------------------------


def put_chunk(slot, data, start, end=None, total=None, token=None, raw_range=None):
    end = start + len(data) - 1 if end is None else end
    total = slot["max_bytes"] if total is None else total
    headers = {
        "Content-Type": slot["headers"]["Content-Type"],
        "X-Exab-Upload-Token": token or slot["upload_token"],
        "Content-Range": raw_range or f"bytes {start}-{end}/{total}",
    }
    return http("PUT", slot["upload_url"], data, headers)


def chunks_of(data, size):
    return [(i, data[i:i + size]) for i in range(0, len(data), size)]


def chunk_files():
    return http("GET", "/wp-json/exab-e2e/v1/chunk-files", user="admin")[1]["files"]


def test_chunked_upload_in_order():
    data = FIXTURE
    slot = create_slot(data, filename="chunked.png")
    assert slot["max_chunk_bytes"] == 100000 and len(data) > 2 * 100000, "fixture must need 3+ chunks"
    parts = chunks_of(data, slot["max_chunk_bytes"])
    for i, (offset, part) in enumerate(parts):
        status, body, _ = put_chunk(slot, part, offset)
        if i < len(parts) - 1:
            assert status == 202 and body["status"] == "receiving", f"{status} {body}"
            assert body["received_bytes"] == offset + len(part)
        else:
            assert status == 201 and body["status"] == "completed", f"{status} {body}"
    assert download(body["url"]) == data, "reassembled file must be identical"
    assert not [f for f in chunk_files() if f.startswith(slot["slot_id"])], "chunk file must be gone"


def test_chunked_resume_after_wrong_offset():
    data = FIXTURE
    slot = create_slot(data, filename="resume.png")
    parts = chunks_of(data, 100000)
    assert put_chunk(slot, parts[0][1], 0)[0] == 202

    # Skipping a chunk is refused and tells the client where to continue.
    status, body, _ = put_chunk(slot, parts[2][1], parts[2][0])
    assert status == 409 and body["code"] == "exab_range_mismatch" and body["data"]["expected_offset"] == 100000, body
    # Re-sending an old chunk is refused the same way.
    assert put_chunk(slot, parts[0][1], 0)[1]["data"]["expected_offset"] == 100000

    st = status_of(slot)[1]
    assert st["status"] == "receiving" and st["received_bytes"] == 100000 and st["size_bytes"] == len(data), st
    for offset, part in parts[1:]:
        status, body, _ = put_chunk(slot, part, offset)
    assert status == 201 and download(body["url"]) == data, body


def test_chunked_rejects_bad_ranges_without_burning_the_slot():
    data = make_png(200, 200, seed=31)
    slot = create_slot(data, filename="ranges.png")
    half = len(data) // 2
    expect_error(put_chunk(slot, data[:half], 0, total=len(data) + 1), "exab_range_total_mismatch", 400)
    expect_error(put_chunk(slot, data[:half], 0, end=half + 10), "exab_range_length_mismatch", 400)
    expect_error(put_chunk(slot, data[:half], 0, raw_range="bytes=0-10"), "exab_invalid_range", 400)
    big = os.urandom(160000)  # only the size matters: the chunk is refused before any content check
    big_slot = create_slot(big, filename="big.png")
    expect_error(put_chunk(big_slot, big[:150000], 0), "exab_chunk_too_large", 413)
    expect_error(put_chunk(slot, data[:half], 0, token="0" * 64), "exab_invalid_token", 403)
    assert status_of(slot)[1]["status"] == "pending"
    assert put_chunk(slot, data[:half], 0)[0] == 202
    assert put_chunk(slot, data[half:], half)[0] == 201


def test_whole_file_put_rejected_during_chunked_upload():
    data = FIXTURE
    slot = create_slot(data, filename="mixed.png")
    assert put_chunk(slot, data[:100000], 0)[0] == 202
    expect_error(put(slot, data), "exab_chunked_in_progress", 409)


def test_parallel_chunks_for_same_offset_only_one_wins():
    data = FIXTURE
    slot = create_slot(data, filename="race.png")
    results = []

    def worker():
        results.append(put_chunk(slot, data[:100000], 0)[0])

    threads = [threading.Thread(target=worker) for _ in range(4)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    assert results.count(202) == 1 and all(code in (202, 409) for code in results), results
    assert status_of(slot)[1]["received_bytes"] == 100000


def test_chunked_checksum_failure_cleans_up():
    data = FIXTURE
    slot = create_slot(data, filename="bad-sum.png")
    corrupted = data[:-1] + bytes([data[-1] ^ 0xFF])
    for offset, part in chunks_of(corrupted, 100000):
        status, body, _ = put_chunk(slot, part, offset)
    assert status == 400 and body["code"] == "exab_checksum_mismatch", body
    assert status_of(slot)[1]["status"] == "failed"
    assert not [f for f in chunk_files() if f.startswith(slot["slot_id"])]


def test_curl_chunked_command_from_the_ability_works():
    import shlex
    import subprocess
    import tempfile

    data = FIXTURE
    slot = create_slot(data, filename="via-curl.png")
    with tempfile.NamedTemporaryFile(suffix=".png") as fh:
        fh.write(data)
        fh.flush()
        script = slot["curl_chunked"].replace("f=FILE;", f"f={shlex.quote(fh.name)};")
        # Resume support: send the first chunk ourselves, let the script continue at OFFSET.
        assert put_chunk(slot, data[:100000], 0)[0] == 202
        result = subprocess.run(
            ["bash", "-c", script], capture_output=True, text=True, timeout=120, env={"OFFSET": "100000", "PATH": "/usr/bin:/bin"}
        )
    lines = [line for line in result.stdout.splitlines() if line.strip()]
    assert result.returncode == 0 and lines, result
    final = json.loads(lines[-1])
    assert final["status"] == "completed" and download(final["url"]) == data, final


# --- milestone 3: inline uploads ---------------------------------------------


def inline(data, user="author", **params):
    payload = {"filename": "inline.png", "mime_type": "image/png", "data_base64": base64.b64encode(data).decode()}
    payload.update(params)
    return run_ability("exab/upload-media-inline", payload, user)


def test_inline_upload():
    data = make_png(24, 24, seed=40)
    status, body, _ = inline(data, alt_text="inline alt", post_id=CREDS["posts"]["by_author"], set_featured=True)
    assert status == 200 and body["set_as_featured"] is True, f"{status} {body}"
    assert download(body["url"]) == data
    item = run_ability("exab/get-media-item", {"attachment_id": body["attachment_id"]}, "author", readonly=True)[1]
    assert item["alt_text"] == "inline alt"


def test_inline_accepts_data_uri_and_strips_gps():
    data = jpeg_with_gps(fixture("small_jpeg"))
    assert len(data) < 64 * 1024
    uri = "data:image/jpeg;base64," + base64.b64encode(data).decode()
    status, body, _ = inline(b"", filename="inline.jpg", mime_type="image/jpeg", data_base64=uri)
    assert status == 200 and body["gps_removed"] is True, f"{status} {body}"
    check_jpeg_scrubbed(download(body["url"]))


def test_inline_rejects_large_invalid_and_fake_content():
    too_big = os.urandom(66000)
    expect_error(inline(too_big), "exab_inline_too_large")
    expect_error(inline(b"", data_base64="this is not base64!!"), "exab_invalid_base64")
    expect_error(inline(b"#!/bin/sh\necho no\n" * 3), "exab_content_mismatch")
    expect_forbidden(inline(make_png(8, 8), user="subscriber"))


def test_inline_through_mcp():
    data = make_png(12, 12, seed=41)
    result = McpClient("author").execute(
        "exab/upload-media-inline",
        {"filename": "mcp-inline.png", "mime_type": "image/png", "data_base64": base64.b64encode(data).decode()},
    )
    assert result["attachment_id"] > 0 and download(result["url"]) == data, result


# --- milestone 3: PDF --------------------------------------------------------

PDF = (
    b"%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
    b"3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n"
    b"trailer<</Root 1 0 R>>\n%%EOF\n"
)


def test_pdf_rejected_by_default():
    status, body, _ = run_ability(
        "exab/create-upload-slot", {"filename": "doc.pdf", "mime_type": "application/pdf", "size_bytes": len(PDF)}, "author"
    )
    assert status == 400, f"{status} {body}"


def test_pdf_upload_when_enabled():
    set_option("exab_allow_pdf", "1")
    try:
        body = upload_file(PDF, "doc.pdf", "application/pdf", title="A PDF")
        assert download(body["url"]) == PDF and body["gps_removed"] is False
        item = run_ability("exab/get-media-item", {"attachment_id": body["attachment_id"]}, "author", readonly=True)[1]
        assert item["mime_type"] == "application/pdf" and item["block_markup"] is None, item

        fake = b"MZ" + PDF[2:]
        slot = create_slot(fake, filename="fake.pdf", mime_type="application/pdf")
        expect_error(put(slot, fake, content_type="application/pdf"), "exab_content_mismatch", 415)

        expect_error(
            run_ability("exab/create-upload-slot", slot_params(PDF, filename="f.pdf", mime_type="application/pdf",
                                                               post_id=CREDS["posts"]["by_author"], set_featured=True), "author"),
            "exab_featured_needs_image",
        )
    finally:
        set_option("exab_allow_pdf", "0")


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
    global FIXTURES_DIR
    FIXTURES_DIR = os.path.join(os.path.dirname(os.path.abspath(args.creds)), "fixtures")
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
