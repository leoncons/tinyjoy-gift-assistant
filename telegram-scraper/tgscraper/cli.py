"""CLI: scrape a Telegram channel into a Google Drive folder."""

from __future__ import annotations

import argparse
import asyncio
import csv
import json
import logging
import os
import sys
from pathlib import Path

from dotenv import load_dotenv

from .channel import ChannelReader, Post, normalize_channel
from .parser import parse

log = logging.getLogger("tgscraper")

METADATA_FILE = "posts.jsonl"
CSV_FILE = "posts.csv"
STATE_FILE = "_state.json"
CSV_FIELDS = [
    "message_id", "date", "url", "prompt", "negative_prompt", "tags",
    "params", "links", "images", "image_urls", "raw_text",
]


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        prog="tgscraper",
        description="Scrape prompts, images and tags from a Telegram channel into Google Drive.",
    )
    p.add_argument("--channel", default=os.getenv("TG_CHANNEL"),
                   help="@username, t.me link or numeric id")
    p.add_argument("--drive-path", default=os.getenv("DRIVE_PATH"),
                   help='Destination path, e.g. "My Drive/Prompts/Channel"')
    p.add_argument("--drive-folder-id", default=os.getenv("DRIVE_FOLDER_ID") or None,
                   help="Destination folder id (overrides --drive-path)")
    p.add_argument("--limit", type=int, default=None, help="Max posts this run")
    p.add_argument("--min-id", type=int, default=None,
                   help="Start after this message id (default: resume from state)")
    p.add_argument("--work-dir", default="downloads", help="Local scratch dir for media")
    p.add_argument("--state-file", default="state/state.json", help="Local resume state")
    p.add_argument("--no-media", action="store_true", help="Metadata only, skip images")
    p.add_argument("--overwrite", action="store_true", help="Replace same-name Drive files")
    p.add_argument("--keep-local", action="store_true", help="Keep downloaded media on disk")
    p.add_argument("--dry-run", action="store_true", help="No Drive writes, print records")
    p.add_argument("--flush-every", type=int, default=25,
                   help="Upload metadata every N posts")
    p.add_argument("-v", "--verbose", action="store_true")
    return p


def load_state(path: Path) -> dict:
    if path.exists():
        try:
            return json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError:
            log.warning("State file unreadable, starting fresh")
    return {}


def save_state(path: Path, state: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(state, indent=2), encoding="utf-8")


def record_from_post(post: Post, image_entries: list[dict]) -> dict:
    parsed = parse(post.text)
    return {
        "message_id": post.message_id,
        "message_ids": post.message_ids,
        "grouped_id": post.grouped_id,
        "date": post.date,
        "url": post.url,
        "views": post.views,
        "prompt": parsed.prompt,
        "negative_prompt": parsed.negative_prompt,
        "tags": parsed.tags,
        "params": parsed.params,
        "links": parsed.links,
        "images": [e["name"] for e in image_entries],
        "image_urls": [e.get("webViewLink", "") for e in image_entries],
        "raw_text": parsed.raw_text,
    }


def write_csv(records: list[dict], path: Path) -> None:
    with path.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=CSV_FIELDS, extrasaction="ignore")
        writer.writeheader()
        for rec in records:
            row = dict(rec)
            row["tags"] = ", ".join(rec["tags"])
            row["params"] = json.dumps(rec["params"], ensure_ascii=False)
            row["links"] = " ".join(rec["links"])
            row["images"] = " ".join(rec["images"])
            row["image_urls"] = " ".join(rec["image_urls"])
            writer.writerow(row)


async def run(args: argparse.Namespace) -> int:
    if not args.channel:
        log.error("No channel given (--channel or TG_CHANNEL)")
        return 2
    if not args.dry_run and not (args.drive_path or args.drive_folder_id):
        log.error("No Drive destination (--drive-path/--drive-folder-id or DRIVE_PATH)")
        return 2

    api_id = os.getenv("TG_API_ID")
    api_hash = os.getenv("TG_API_HASH")
    if not api_id or not api_hash:
        log.error("TG_API_ID and TG_API_HASH must be set (see .env.example)")
        return 2

    channel = normalize_channel(args.channel)
    work_dir = Path(args.work_dir).expanduser()
    state_path = Path(args.state_file).expanduser()
    state = load_state(state_path)
    channel_key = str(channel)
    min_id = args.min_id if args.min_id is not None else int(
        state.get(channel_key, {}).get("last_message_id", 0)
    )

    drive = folder_id = images_id = None
    if not args.dry_run:
        from .drive import DriveClient  # imported late so --dry-run needs no Google deps

        drive = DriveClient(
            credentials_file=os.getenv("GOOGLE_CREDENTIALS", "credentials.json"),
            token_file=os.getenv("GOOGLE_TOKEN", "token.json"),
            auth_mode=os.getenv("GOOGLE_AUTH_MODE", "oauth"),
        )
        folder_id = args.drive_folder_id or drive.resolve_path(args.drive_path)
        images_id = drive.ensure_subfolder(folder_id, "images")
        log.info("Drive folder: %s", folder_id)

    records: list[dict] = []
    existing = work_dir / METADATA_FILE
    if existing.exists():
        records = [json.loads(line) for line in existing.read_text(encoding="utf-8").splitlines() if line.strip()]
    seen = {r["message_id"] for r in records}

    def flush() -> None:
        work_dir.mkdir(parents=True, exist_ok=True)
        jsonl = work_dir / METADATA_FILE
        jsonl.write_text(
            "\n".join(json.dumps(r, ensure_ascii=False) for r in records) + "\n",
            encoding="utf-8",
        )
        csv_path = work_dir / CSV_FILE
        write_csv(records, csv_path)
        if drive:
            drive.upload(jsonl, folder_id, METADATA_FILE, overwrite=True)
            drive.upload(csv_path, folder_id, CSV_FILE, overwrite=True)
            state_blob = work_dir / STATE_FILE
            state_blob.write_text(json.dumps(state, indent=2), encoding="utf-8")
            drive.upload(state_blob, folder_id, STATE_FILE, overwrite=True)
        save_state(state_path, state)

    processed = 0
    async with ChannelReader(int(api_id), api_hash, os.getenv("TG_SESSION", "tgscraper")) as reader:
        async for post in reader.iter_posts(
            channel,
            download_dir=work_dir / "media",
            min_id=min_id,
            limit=args.limit,
            download_media=not args.no_media,
        ):
            if post.message_id in seen:
                continue

            uploaded: list[dict] = []
            for path in post.media_paths:
                if drive:
                    uploaded.append(drive.upload(path, images_id, path.name,
                                                 overwrite=args.overwrite))
                else:
                    uploaded.append({"name": path.name, "webViewLink": str(path)})
                if not args.keep_local and drive:
                    path.unlink(missing_ok=True)

            record = record_from_post(post, uploaded)
            records.append(record)
            seen.add(post.message_id)
            processed += 1
            state[channel_key] = {
                "last_message_id": max(post.message_ids),
                "last_date": post.date,
                "posts": len(records),
            }

            log.info("#%s %s tag(s), %s image(s)", post.message_id,
                     len(record["tags"]), len(record["images"]))
            if args.dry_run:
                print(json.dumps(record, ensure_ascii=False, indent=2))
            elif processed % max(args.flush_every, 1) == 0:
                flush()

    if args.dry_run:
        log.info("Dry run: %s post(s), nothing written to Drive", processed)
        return 0

    flush()
    log.info("Done: %s new post(s), %s total in %s", processed, len(records), CSV_FILE)
    return 0


def main(argv: list[str] | None = None) -> int:
    load_dotenv()
    args = build_parser().parse_args(argv)
    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(asctime)s %(levelname)s %(message)s",
        datefmt="%H:%M:%S",
    )
    try:
        return asyncio.run(run(args))
    except KeyboardInterrupt:
        log.warning("Interrupted; rerun to resume from the saved state")
        return 130


if __name__ == "__main__":
    sys.exit(main())
