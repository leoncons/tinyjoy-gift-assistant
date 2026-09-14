"""Turn raw Telegram post text into prompt + tags."""

from __future__ import annotations

import re
from dataclasses import dataclass, field

HASHTAG_RE = re.compile(r"(?<!\w)#([^\s#,.;:!?()\[\]{}\"']+)")
CODE_BLOCK_RE = re.compile(r"```(?:\w+)?\n?(.+?)```", re.DOTALL)
QUOTE_BLOCK_RE = re.compile(r"^>\s?(.+)$", re.MULTILINE)
# "Prompt:", "Prompt —", "PROMPT:", "Промпт:", "Prompt 1:"
LABEL_RE = re.compile(
    r"^\s*(?:\*\*|__)?\s*(?:prompt|промпт|prompt\s*\d+)\s*(?:\*\*|__)?\s*[:\-–—]\s*",
    re.IGNORECASE | re.MULTILINE,
)
NEGATIVE_RE = re.compile(
    r"^\s*(?:negative\s*prompt|negative)\s*[:\-–—]\s*(.+)$",
    re.IGNORECASE | re.MULTILINE,
)
PARAM_RE = re.compile(r"--(\w+)\s+([^\s-][^\s]*)")
URL_RE = re.compile(r"https?://\S+")


@dataclass
class ParsedPost:
    raw_text: str = ""
    prompt: str = ""
    negative_prompt: str = ""
    tags: list[str] = field(default_factory=list)
    params: dict[str, str] = field(default_factory=dict)
    links: list[str] = field(default_factory=list)


def extract_tags(text: str) -> list[str]:
    """Hashtags, lowercased, order preserved, deduped."""
    seen: dict[str, None] = {}
    for tag in HASHTAG_RE.findall(text or ""):
        seen.setdefault(tag.strip("_-").lower(), None)
    return [t for t in seen if t]


TRIM_CHARS = " \t-–—|&,·•*"


def _strip_noise(text: str) -> str:
    """Drop hashtags and URLs, then tidy the leftovers line by line."""
    text = URL_RE.sub("", HASHTAG_RE.sub("", text or ""))
    lines = []
    for line in text.split("\n"):
        line = re.sub(r"[ \t]{2,}", " ", line).strip().strip(TRIM_CHARS)
        if any(ch.isalnum() for ch in line):
            lines.append(line)
    return "\n".join(lines).strip()


def extract_prompt(text: str) -> str:
    """Best-effort prompt body.

    Priority: fenced code block > "Prompt:" label > quote block > whole text.
    """
    if not text:
        return ""

    blocks = CODE_BLOCK_RE.findall(text)
    if blocks:
        return max((b.strip() for b in blocks), key=len)

    match = LABEL_RE.search(text)
    if match:
        tail = text[match.end():]
        # stop at the next labelled section (Negative prompt:, Tags:, ...)
        stop = re.search(r"\n\s*\w[\w ]{0,20}\s*[:\-–—]\s", tail)
        body = tail[: stop.start()] if stop else tail
        cleaned = _strip_noise(body)
        if cleaned:
            return cleaned

    quotes = QUOTE_BLOCK_RE.findall(text)
    if quotes:
        return _strip_noise("\n".join(quotes))

    return _strip_noise(text)


def parse(text: str) -> ParsedPost:
    text = text or ""
    negative = ""
    neg_match = NEGATIVE_RE.search(text)
    if neg_match:
        negative = _strip_noise(neg_match.group(1))
        text_wo_neg = text[: neg_match.start()] + text[neg_match.end():]
    else:
        text_wo_neg = text

    prompt = extract_prompt(text_wo_neg)
    return ParsedPost(
        raw_text=text,
        prompt=prompt,
        negative_prompt=negative,
        tags=extract_tags(text),
        params={k.lower(): v for k, v in PARAM_RE.findall(text)},
        links=URL_RE.findall(text),
    )
