"""Telegram side: read a channel, group albums, download images."""

from __future__ import annotations

import asyncio
import logging
import re
from dataclasses import dataclass, field
from pathlib import Path

from telethon import TelegramClient
from telethon.errors import FloodWaitError
from telethon.tl.types import (
    DocumentAttributeFilename,
    MessageMediaDocument,
    MessageMediaPhoto,
)

log = logging.getLogger(__name__)

IMAGE_MIME_PREFIX = "image/"


@dataclass
class Post:
    """One logical post: a single message or a whole album."""

    message_id: int          # lowest message id of the group
    grouped_id: int | None
    date: str
    text: str
    views: int | None
    url: str
    message_ids: list[int] = field(default_factory=list)
    media_paths: list[Path] = field(default_factory=list)


def normalize_channel(value: str) -> str | int:
    """Accept @name, t.me links, private t.me/c/<id> links or a raw id."""
    value = (value or "").strip()
    if not value:
        raise ValueError("channel is required")
    private = re.search(r"t\.me/c/(\d+)", value)
    if private:
        return int(f"-100{private.group(1)}")
    link = re.search(r"t\.me/(?:s/)?([A-Za-z0-9_]+)", value)
    if link:
        return link.group(1)
    if re.fullmatch(r"-?\d+", value):
        return int(value)
    return value.lstrip("@")


def _is_image(message) -> bool:
    if isinstance(message.media, MessageMediaPhoto):
        return True
    if isinstance(message.media, MessageMediaDocument):
        mime = getattr(message.media.document, "mime_type", "") or ""
        return mime.startswith(IMAGE_MIME_PREFIX)
    return False


def _media_name(message, index: int) -> str:
    ext = ".jpg"
    if isinstance(message.media, MessageMediaDocument):
        for attr in message.media.document.attributes:
            if isinstance(attr, DocumentAttributeFilename):
                ext = Path(attr.file_name).suffix or ext
                break
        else:
            mime = getattr(message.media.document, "mime_type", "") or ""
            ext = "." + mime.split("/")[-1].replace("jpeg", "jpg") if "/" in mime else ext
    return f"{message.id:07d}_{index}{ext}"


class ChannelReader:
    def __init__(self, api_id: int, api_hash: str, session: str = "tgscraper") -> None:
        self.client = TelegramClient(session, api_id, api_hash)

    async def __aenter__(self) -> "ChannelReader":
        await self.client.start()
        return self

    async def __aexit__(self, *exc) -> None:
        await self.client.disconnect()

    async def _retry(self, coro_factory, attempts: int = 5):
        for attempt in range(attempts):
            try:
                return await coro_factory()
            except FloodWaitError as err:
                wait = err.seconds + 2
                log.warning("Flood wait: sleeping %ss", wait)
                await asyncio.sleep(wait)
            except (ConnectionError, asyncio.TimeoutError):
                wait = 2 ** attempt
                log.warning("Connection issue, retry in %ss", wait)
                await asyncio.sleep(wait)
        raise RuntimeError("Telegram request failed after retries")

    async def iter_posts(
        self,
        channel: str | int,
        download_dir: Path,
        min_id: int = 0,
        limit: int | None = None,
        download_media: bool = True,
    ):
        """Yield Post objects oldest-first, albums merged into one post."""
        entity = await self._retry(lambda: self.client.get_entity(channel))
        username = getattr(entity, "username", None)
        download_dir.mkdir(parents=True, exist_ok=True)

        buffer: list = []
        count = 0

        async def flush(group: list):
            nonlocal count
            if not group:
                return None
            if limit is not None and count >= limit:
                return None
            post = await self._build_post(group, entity, username, download_dir, download_media)
            count += 1
            return post

        async for message in self.client.iter_messages(
            entity, reverse=True, min_id=min_id
        ):
            gid = message.grouped_id
            if buffer and (gid is None or buffer[0].grouped_id != gid):
                post = await flush(buffer)
                buffer = []
                if post:
                    yield post
                elif limit is not None and count >= limit:
                    return
            buffer.append(message)
            if gid is None:
                post = await flush(buffer)
                buffer = []
                if post:
                    yield post
                elif limit is not None and count >= limit:
                    return

        post = await flush(buffer)
        if post:
            yield post

    async def _build_post(
        self, group: list, entity, username: str | None, download_dir: Path,
        download_media: bool,
    ) -> Post:
        group = sorted(group, key=lambda m: m.id)
        head = group[0]
        text = next((m.message for m in group if (m.message or "").strip()), "") or ""
        base_id = head.id
        slug = username or str(getattr(entity, "id", "channel"))
        url = (
            f"https://t.me/{username}/{base_id}"
            if username
            else f"https://t.me/c/{str(getattr(entity, 'id', '')).lstrip('-100')}/{base_id}"
        )

        paths: list[Path] = []
        if download_media:
            index = 0
            for message in group:
                if not _is_image(message):
                    continue
                target = download_dir / f"{slug}_{_media_name(message, index)}"
                index += 1
                if target.exists() and target.stat().st_size > 0:
                    paths.append(target)
                    continue
                saved = await self._retry(
                    lambda m=message, t=target: self.client.download_media(m, file=str(t))
                )
                if saved:
                    paths.append(Path(saved))

        return Post(
            message_id=base_id,
            grouped_id=head.grouped_id,
            date=head.date.isoformat() if head.date else "",
            text=text,
            views=getattr(head, "views", None),
            url=url,
            message_ids=[m.id for m in group],
            media_paths=paths,
        )
