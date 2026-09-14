#!/usr/bin/env python3
"""Build the importable n8n workflow JSON from the Code-node sources.

Run after editing src/*.js:  python3 src/build_workflows.py
"""

from __future__ import annotations

import json
from pathlib import Path

HERE = Path(__file__).resolve().parent
OUT = HERE.parent

RL_FOLDER = {"__rl": True, "value": "root", "mode": "list", "cachedResultName": "/ (Root folder)"}
RL_DRIVE = {"__rl": True, "value": "My Drive", "mode": "list", "cachedResultName": "My Drive"}
SHEET_DOC = {"__rl": True, "value": "PUT_YOUR_SPREADSHEET_ID_HERE", "mode": "id"}
SHEET_NAME = {"__rl": True, "value": "Sheet1", "mode": "name"}

SHEET_COLUMNS = [
    ("message_id", "number"), ("date", "string"), ("channel", "string"), ("url", "string"),
    ("prompt", "string"), ("negative_prompt", "string"), ("tags", "string"),
    ("params", "string"), ("links", "string"), ("images", "string"),
    ("image_links", "string"), ("raw_text", "string"),
]


def sheet_schema() -> list[dict]:
    return [
        {
            "id": name,
            "displayName": name,
            "required": False,
            "defaultMatch": False,
            "display": True,
            "type": kind,
            "canBeUsedToMatch": True,
        }
        for name, kind in SHEET_COLUMNS
    ]


def node(name, kind, type_version, position, parameters, **extra):
    data = {
        "parameters": parameters,
        "id": name.lower().replace(" ", "-").replace("?", "").replace("&", "and"),
        "name": name,
        "type": kind,
        "typeVersion": type_version,
        "position": position,
    }
    data.update(extra)
    return data


def sticky(name, position, width, height, content):
    return node(
        name,
        "n8n-nodes-base.stickyNote",
        1,
        position,
        {"width": width, "height": height, "content": content},
    )


def sheets_node(name, position, values):
    return node(
        name,
        "n8n-nodes-base.googleSheets",
        4.5,
        position,
        {
            "operation": "append",
            "documentId": SHEET_DOC,
            "sheetName": SHEET_NAME,
            "columns": {
                "mappingMode": "defineBelow",
                "value": values,
                "matchingColumns": ["message_id"],
                "schema": sheet_schema(),
            },
            "options": {},
        },
        credentials={"googleSheetsOAuth2Api": {"id": "REPLACE_ME", "name": "Google Sheets account"}},
    )


def drive_upload(name, position, file_name_expr, input_field="data"):
    return node(
        name,
        "n8n-nodes-base.googleDrive",
        3,
        position,
        {
            "name": file_name_expr,
            "driveId": RL_DRIVE,
            "folderId": RL_FOLDER,
            "inputDataFieldName": input_field,
            "options": {},
        },
        credentials={"googleDriveOAuth2Api": {"id": "REPLACE_ME", "name": "Google Drive account"}},
    )


def wrap(name, nodes, connections, tags):
    return {
        "name": name,
        "nodes": nodes,
        "connections": connections,
        "active": False,
        "settings": {"executionOrder": "v1"},
        "pinData": {},
        "tags": tags,
        "meta": {"instanceId": "telegram-prompt-scraper"},
    }


def main_chain(pairs: dict[str, list[list[dict]]]) -> dict:
    """pairs: {node name: [[{node, type, index}, ...]]}"""
    return {src: {"main": targets} for src, targets in pairs.items()}


def to(target, index=0):
    return {"node": target, "type": "main", "index": index}


# --------------------------------------------------------------------------
# Workflow 1 — live capture via the Telegram Trigger (bot in the channel)
# --------------------------------------------------------------------------
live_code = (HERE / "parse_live.js").read_text(encoding="utf-8")

live_nodes = [
    sticky("Setup notes", [-460, -180], 420, 520,
           "## Telegram → Google Drive (live)\n\n"
           "Captures **new** channel posts as they are published.\n\n"
           "1. Create a bot with @BotFather, add it to the channel as an "
           "**admin** (it cannot read posts otherwise).\n"
           "2. Add Telegram, Google Drive and Google Sheets credentials to the "
           "three nodes below.\n"
           "3. Pick the destination folder in **Upload image** and the "
           "spreadsheet in **Append row**.\n"
           "4. Activate the workflow.\n\n"
           "Albums (multi-image posts) arrive as separate updates; the caption "
           "is cached in workflow static data and reused.\n\n"
           "For **past** posts use the backfill workflow or the Python "
           "scraper in `telegram-scraper/`."),
    node("Channel post", "n8n-nodes-base.telegramTrigger", 1.1, [0, 0],
         {"updates": ["channel_post"], "additionalFields": {}},
         credentials={"telegramApi": {"id": "REPLACE_ME", "name": "Telegram account"}},
         webhookId="00000000-0000-4000-8000-000000000001"),
    node("Parse post", "n8n-nodes-base.code", 2, [220, 0], {"jsCode": live_code}),
    node("Has image?", "n8n-nodes-base.if", 2, [440, 0],
         {"conditions": {
             "options": {"caseSensitive": True, "leftValue": "", "typeValidation": "loose"},
             "conditions": [{
                 "id": "has-image",
                 "leftValue": "={{ $json.has_image }}",
                 "rightValue": "",
                 "operator": {"type": "boolean", "operation": "true", "singleValue": True},
             }],
             "combinator": "and",
         },
          "looseTypeValidation": True,
          "options": {}}),
    node("Download image", "n8n-nodes-base.telegram", 1.2, [660, -100],
         {"resource": "file", "fileId": "={{ $json.file_id }}", "download": True},
         credentials={"telegramApi": {"id": "REPLACE_ME", "name": "Telegram account"}}),
    drive_upload("Upload image", [880, -100],
                 "={{ $('Parse post').item.json.file_name }}"),
    sheets_node("Append row", [1120, 0], {
        "message_id": "={{ $('Parse post').item.json.message_id }}",
        "date": "={{ $('Parse post').item.json.date }}",
        "channel": "={{ $('Parse post').item.json.channel }}",
        "url": "={{ $('Parse post').item.json.url }}",
        "prompt": "={{ $('Parse post').item.json.prompt }}",
        "negative_prompt": "={{ $('Parse post').item.json.negative_prompt }}",
        "tags": "={{ $('Parse post').item.json.tags_text }}",
        "params": "={{ $('Parse post').item.json.params_text }}",
        "links": "={{ $('Parse post').item.json.links_text }}",
        "images": "={{ $('Parse post').item.json.file_name || '' }}",
        "image_links": "={{ $json.webViewLink || '' }}",
        "raw_text": "={{ $('Parse post').item.json.raw_text }}",
    }),
]

live = wrap(
    "Telegram prompts → Google Drive (live)",
    live_nodes,
    main_chain({
        "Channel post": [[to("Parse post")]],
        "Parse post": [[to("Has image?")]],
        "Has image?": [[to("Download image")], [to("Append row")]],
        "Download image": [[to("Upload image")]],
        "Upload image": [[to("Append row")]],
    }),
    [{"name": "telegram"}, {"name": "google-drive"}],
)

# --------------------------------------------------------------------------
# Workflow 2 — backfill a public channel from its web preview
# --------------------------------------------------------------------------
backfill_code = (HERE / "parse_backfill.js").read_text(encoding="utf-8")

backfill_nodes = [
    sticky("Setup notes", [-460, -200], 420, 560,
           "## Telegram → Google Drive (backfill)\n\n"
           "Walks the **public** web preview `https://t.me/s/<channel>` page by "
           "page, so it needs no Telegram credentials — but it only works for "
           "public channels. Private ones: use the Python scraper in "
           "`telegram-scraper/`.\n\n"
           "1. Set `channel`, `max_pages` and `stop_after_id` in **Config**.\n"
           "2. Add Google Drive and Google Sheets credentials, then pick the "
           "destination folder and spreadsheet.\n"
           "3. Run once. Note the highest `message_id` written and put it in "
           "`stop_after_id` for the next run.\n\n"
           "Schedule Trigger is disabled by default — enable it for unattended "
           "top-ups."),
    node("Run manually", "n8n-nodes-base.manualTrigger", 1, [0, 0], {}),
    node("Every 6 hours", "n8n-nodes-base.scheduleTrigger", 1.2, [0, 180],
         {"rule": {"interval": [{"field": "hours", "hoursInterval": 6}]}},
         disabled=True),
    node("Config", "n8n-nodes-base.set", 3.4, [220, 0], {
        "assignments": {"assignments": [
            {"id": "channel", "name": "channel", "value": "@some_prompt_channel", "type": "string"},
            {"id": "max_pages", "name": "max_pages", "value": 20, "type": "number"},
            {"id": "stop_after_id", "name": "stop_after_id", "value": 0, "type": "number"},
        ]},
        "options": {},
    }),
    node("Fetch and parse channel", "n8n-nodes-base.code", 2, [440, 0], {"jsCode": backfill_code}),
    sheets_node("Append posts", [660, -120], {
        "message_id": "={{ $json.message_id }}",
        "date": "={{ $json.date }}",
        "channel": "={{ $json.channel }}",
        "url": "={{ $json.url }}",
        "prompt": "={{ $json.prompt }}",
        "negative_prompt": "={{ $json.negative_prompt }}",
        "tags": "={{ $json.tags_text }}",
        "params": "={{ $json.params_text }}",
        "links": "={{ $json.links_text }}",
        "images": "={{ $json.images.map(i => i.name).join(' ') }}",
        "image_links": "={{ $json.image_urls_text }}",
        "raw_text": "={{ $json.raw_text }}",
    }),
    node("Split images", "n8n-nodes-base.splitOut", 1, [660, 120], {
        "fieldToSplitOut": "images",
        "include": "selectedOtherFields",
        "fieldsToInclude": "message_id,channel,url",
        "options": {},
    }),
    node("Download image", "n8n-nodes-base.httpRequest", 4.2, [880, 120], {
        "url": "={{ $json.images.url }}",
        "options": {"response": {"response": {"responseFormat": "file", "outputPropertyName": "data"}}},
    }),
    drive_upload("Upload image", [1100, 120], "={{ $('Split images').item.json.images.name }}"),
]

backfill = wrap(
    "Telegram prompts → Google Drive (backfill)",
    backfill_nodes,
    main_chain({
        "Run manually": [[to("Config")]],
        "Every 6 hours": [[to("Config")]],
        "Config": [[to("Fetch and parse channel")]],
        "Fetch and parse channel": [[to("Append posts"), to("Split images")]],
        "Split images": [[to("Download image")]],
        "Download image": [[to("Upload image")]],
    }),
    [{"name": "telegram"}, {"name": "google-drive"}],
)

for filename, workflow in (
    ("telegram-prompts-live.json", live),
    ("telegram-prompts-backfill.json", backfill),
):
    path = OUT / filename
    path.write_text(json.dumps(workflow, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"wrote {path.relative_to(OUT.parent)} ({len(workflow['nodes'])} nodes)")
