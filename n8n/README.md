# n8n workflows — Telegram prompts → Google Drive

Two importable workflows, same job as the Python scraper in
[`../telegram-scraper`](../telegram-scraper), no server or cron of your own.

| File | Covers | Needs |
|---|---|---|
| `telegram-prompts-live.json` | **New** posts, as they are published | Telegram bot (admin in the channel), Drive, Sheets |
| `telegram-prompts-backfill.json` | **Past** posts of a **public** channel | Drive, Sheets |

Neither can read the history of a *private* channel — the Telegram Bot API has
no history endpoint and the web preview only exists for public channels. Use the
Python scraper for that; it logs in as your own user account.

## Import

n8n → **Workflows** → **Import from File** → pick the JSON. Then open each node
with a key icon and select your credentials (they import as `REPLACE_ME`
placeholders).

## Live workflow

```
Channel post (trigger) → Parse post → Has image? ─ yes → Download image → Upload image ─┐
                                              └─ no ───────────────────────────────────→ Append row
```

1. Create a bot with [@BotFather](https://t.me/BotFather).
2. Add the bot to the channel **as an admin** — a non-admin bot receives nothing.
3. Credentials: Telegram (trigger + Download image), Google Drive, Google Sheets.
4. In **Upload image**, pick the destination Drive folder. In **Append row**,
   pick the spreadsheet and tab.
5. Activate.

Albums arrive as several updates sharing `media_group_id`, with the caption on
one member only. **Parse post** caches captions in workflow static data for an
hour and reuses them, so every image of an album gets the right prompt.

## Backfill workflow

```
Run manually ┐
             ├→ Config → Fetch and parse channel ─┬→ Append posts
Every 6 h ───┘                                    └→ Split images → Download image → Upload image
```

1. **Config**: set `channel` (`@name`), `max_pages` (20 ≈ 400 posts) and
   `stop_after_id` (0 on the first run).
2. Pick the Drive folder and spreadsheet as above.
3. Run once, note the highest `message_id` in the sheet, put it in
   `stop_after_id` — the next run only picks up what is newer.
4. Optional: enable the **Every 6 hours** trigger for unattended top-ups.

**Fetch and parse channel** pages through `https://t.me/s/<channel>?before=<id>`
with `this.helpers.httpRequest` and parses the HTML directly, so there is no
credential and no rate-limit juggling — just keep `max_pages` sane.

## Sheet columns

Create a sheet with this header row before the first run:

```
message_id | date | channel | url | prompt | negative_prompt | tags | params | links | images | image_links | raw_text
```

`params` holds `--ar 16:9` style flags as JSON, `tags` is a comma-separated
hashtag list, `raw_text` keeps the original caption.

## Prompt extraction

Same precedence as the Python version: fenced code block → `Prompt:` label →
quoted block → whole caption. Hashtags and URLs are stripped from the prompt but
kept in their own columns.

## Editing the Code nodes

The JavaScript lives in `src/` so it can be tested outside n8n; the workflow
JSON is generated from it.

```bash
node tests/run.mjs             # smoke tests for both Code nodes
python3 src/build_workflows.py # regenerate the two workflow JSON files
```

Edit `src/parse_live.js` or `src/parse_backfill.js`, rerun both commands, and
re-import the workflow.
