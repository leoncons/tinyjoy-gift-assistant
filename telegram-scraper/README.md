# Telegram Prompt Scraper → Google Drive

Collects every post from a Telegram channel — prompt text, images, tags — and stores
them in a Google Drive folder you choose.

Output in the Drive folder:

```
<your Drive path>/
├── images/          one file per image, named <channel>_<message id>_<n>.jpg
├── posts.jsonl      one JSON record per post (full fidelity)
├── posts.csv        spreadsheet view
└── _state.json      resume marker (last message id per channel)
```

Record fields: `message_id`, `date`, `url`, `prompt`, `negative_prompt`, `tags`,
`params` (`--ar 16:9` style flags), `links`, `images`, `image_urls`, `raw_text`.

## Setup

```bash
python3 -m venv venv && source venv/bin/activate
pip install -r requirements.txt
cp .env.example .env
```

**1. Telegram API** — create an app at https://my.telegram.org/apps, put `TG_API_ID`
and `TG_API_HASH` in `.env`. First run asks for your phone and login code, then
caches a `.session` file. Uses your own account, so any channel you can read works,
private ones included.

**2. Google Drive** — Google Cloud Console → enable the Drive API → create an OAuth
client of type *Desktop app* → download as `credentials.json` next to `scrape.py`.
First run opens a browser once and writes `token.json`.

Service accounts also work: set `GOOGLE_AUTH_MODE=service_account`, point
`GOOGLE_CREDENTIALS` at the key file, and share the destination folder with the
service account email.

**3. Destination** — set `DRIVE_PATH`, e.g. `My Drive/Prompts/NanoBanana`. Missing
folders are created. Shared Drives work by using the drive name as the first
segment. A folder ID in `DRIVE_FOLDER_ID` skips path resolution.

## Usage

```bash
# preview 5 posts, nothing written to Drive
python scrape.py --channel @some_channel --limit 5 --dry-run

# full run
python scrape.py --channel @some_channel --drive-path "My Drive/Prompts/Channel"

# later runs pick up only new posts
python scrape.py --channel @some_channel
```

| Flag | Purpose |
|---|---|
| `--channel` | `@name`, `https://t.me/name`, `https://t.me/c/<id>/<msg>` or numeric id |
| `--drive-path` / `--drive-folder-id` | Destination folder |
| `--limit N` | Stop after N posts |
| `--min-id N` | Start after message id N, ignoring saved state |
| `--no-media` | Metadata only |
| `--overwrite` | Replace same-name Drive files instead of skipping |
| `--keep-local` | Keep downloaded images on disk after upload |
| `--dry-run` | Print records, no Drive writes |
| `--flush-every N` | Upload metadata every N posts (default 25) |

## Behaviour notes

- **Albums**: a multi-image post is one record, images listed together.
- **Resume**: `state/state.json` locally, mirrored to `_state.json` in Drive. Rerun
  anytime — already-seen message ids are skipped and existing Drive files are not
  re-uploaded.
- **Prompt extraction**: fenced code block → `Prompt:` label → quoted block → whole
  caption. `raw_text` always keeps the original.
- **Rate limits**: Telegram `FloodWait` is waited out; connection errors retry with
  backoff.
- Ctrl-C is safe; progress up to the last flush is kept.

## Tests

```bash
python -m unittest discover -s tests
```

## n8n version

Prefer a no-code pipeline? `../n8n/` holds two importable workflows: a live one
(Telegram Trigger → Drive + Sheets) and a backfill one for public channels. See
[`../n8n/README.md`](../n8n/README.md). Private-channel history still needs this
scraper — the Bot API cannot read it.

## Legal

Scrape only channels you have access to, and respect the channel's terms and the
authors' rights when reusing prompts or images.
