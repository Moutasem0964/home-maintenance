#!/usr/bin/env python3
"""
Sync docs/postman/HomeMaintenance.postman_collection.json to the Postman cloud
collection WITHOUT destroying example responses saved in the cloud.

The repo file is the source of truth for STRUCTURE (folders, requests, bodies).
Saved example responses, however, are created in the Postman app (the frontend
uses them for the MCP) and never live in the repo. A plain PUT would overwrite
them. So before pushing we fetch the live collection, carry every saved
`response` over onto the matching request (keyed by method + path), and PUT the
merged result.

Behaviour is deliberately conservative:
  - Only `response` arrays are carried over; structure always comes from the repo.
  - A request the repo already ships with responses keeps them only if the cloud
    has none for it (cloud wins, since that's where they're authored).
  - If the cloud can't be read (first run, network, etc.) we push the repo as-is
    rather than failing — no responses to preserve yet.

Env: POSTMAN_API_KEY, POSTMAN_COLLECTION_UID. Skips cleanly if either is unset.
"""

import json
import os
import re
import sys
import urllib.error
import urllib.request

API = "https://api.getpostman.com/collections/"
COLLECTION_PATH = "docs/postman/HomeMaintenance.postman_collection.json"


def norm_path(raw: str) -> str:
    """Endpoint identity: strip the base-url var + query, keep path (with vars)."""
    raw = (raw or "").split("?")[0]
    raw = raw.replace("{{base_url}}", "")
    raw = re.sub(r"^https?://[^/]+", "", raw)
    return "/" + raw.strip("/")


def request_key(item: dict):
    req = item.get("request") or {}
    method = (req.get("method") or "").upper()
    url = req.get("url") or {}
    raw = url.get("raw", "") if isinstance(url, dict) else (url or "")
    return (method, norm_path(raw))


def walk_requests(items):
    """Yield every leaf request item in a (possibly nested) collection item tree."""
    for it in items or []:
        if "item" in it:
            yield from walk_requests(it["item"])
        elif "request" in it:
            yield it


def collect_saved_responses(cloud_items) -> dict:
    """Map endpoint key -> saved `response` array (only non-empty ones)."""
    saved = {}
    for it in walk_requests(cloud_items):
        responses = it.get("response") or []
        if responses:
            saved.setdefault(request_key(it), responses)
    return saved


def http_json(method: str, url: str, api_key: str, body: bytes | None = None):
    req = urllib.request.Request(url, data=body, method=method)
    req.add_header("X-Api-Key", api_key)
    if body is not None:
        req.add_header("Content-Type", "application/json")
    with urllib.request.urlopen(req, timeout=30) as resp:
        return resp.status, json.loads(resp.read().decode("utf-8"))


def main() -> int:
    api_key = os.environ.get("POSTMAN_API_KEY", "")
    uid = os.environ.get("POSTMAN_COLLECTION_UID", "")
    if not api_key or not uid:
        print("Postman secrets not set — skipping sync.")
        return 0

    with open(COLLECTION_PATH, encoding="utf-8") as fh:
        collection = json.load(fh)

    # 1) Fetch the live cloud collection and harvest saved responses (best effort).
    saved = {}
    try:
        _, data = http_json("GET", API + uid, api_key)
        saved = collect_saved_responses(data.get("collection", {}).get("item", []))
        print(f"Found saved responses on {len(saved)} endpoint(s) in the cloud.")
    except (urllib.error.URLError, ValueError, KeyError) as exc:
        print(f"Could not read the cloud collection ({exc}); pushing repo as-is.")

    # 2) Carry saved responses onto the matching repo requests (cloud wins).
    carried = 0
    for it in walk_requests(collection.get("item", [])):
        responses = saved.get(request_key(it))
        if responses:
            it["response"] = responses
            carried += 1
    print(f"Preserved responses on {carried} request(s).")

    # 3) Push the merged collection.
    payload = json.dumps({"collection": collection}).encode("utf-8")
    try:
        status, data = http_json("PUT", API + uid, api_key, payload)
    except urllib.error.HTTPError as exc:
        print(f"Postman sync failed: HTTP {exc.code} {exc.read().decode('utf-8', 'ignore')}")
        return 1
    except urllib.error.URLError as exc:
        print(f"Postman sync failed: {exc}")
        return 1

    print(f"HTTP {status}: {data.get('collection', {}).get('uid', data)}")
    return 0 if status == 200 else 1


if __name__ == "__main__":
    sys.exit(main())
