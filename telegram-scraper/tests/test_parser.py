import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from tgscraper.channel import Post, normalize_channel
from tgscraper.cli import record_from_post, write_csv
from tgscraper.parser import extract_prompt, extract_tags, parse


class TestParser(unittest.TestCase):
    def test_label_prompt(self):
        text = "Prompt: a red fox in neon rain\n#midjourney #fox"
        self.assertEqual(parse(text).prompt, "a red fox in neon rain")

    def test_code_block_wins(self):
        text = "Prompt: short\n```\nlong detailed prompt body\n```"
        self.assertEqual(extract_prompt(text), "long detailed prompt body")

    def test_quote_block(self):
        self.assertEqual(extract_prompt("> quoted prompt here"), "quoted prompt here")

    def test_plain_text_fallback(self):
        self.assertEqual(extract_prompt("just a caption #tag"), "just a caption")

    def test_tags_normalized(self):
        self.assertEqual(
            extract_tags("#AI #ai #Nano_Banana, text #3d."),
            ["ai", "nano_banana", "3d"],
        )

    def test_negative_prompt_and_params(self):
        parsed = parse("Prompt: cat --ar 16:9 --v 6\nNegative prompt: blurry, text")
        self.assertEqual(parsed.negative_prompt, "blurry, text")
        self.assertEqual(parsed.params, {"ar": "16:9", "v": "6"})
        self.assertNotIn("Negative", parsed.prompt)

    def test_empty(self):
        parsed = parse("")
        self.assertEqual(parsed.prompt, "")
        self.assertEqual(parsed.tags, [])


class TestChannel(unittest.TestCase):
    def test_normalize(self):
        self.assertEqual(normalize_channel("@name"), "name")
        self.assertEqual(normalize_channel("https://t.me/s/name"), "name")
        self.assertEqual(normalize_channel("https://t.me/c/999/12"), -100999)
        self.assertEqual(normalize_channel("-100999"), -100999)

    def test_requires_value(self):
        with self.assertRaises(ValueError):
            normalize_channel("")


class TestRecords(unittest.TestCase):
    def _post(self):
        return Post(
            message_id=42,
            grouped_id=7,
            date="2026-01-01T10:00:00+00:00",
            text="Prompt: a tiny fox\n#fox #ai",
            views=100,
            url="https://t.me/chan/42",
            message_ids=[42, 43],
            media_paths=[Path("a.jpg")],
        )

    def test_record(self):
        rec = record_from_post(self._post(), [{"name": "a.jpg", "webViewLink": "http://d/1"}])
        self.assertEqual(rec["prompt"], "a tiny fox")
        self.assertEqual(rec["tags"], ["fox", "ai"])
        self.assertEqual(rec["images"], ["a.jpg"])
        self.assertEqual(rec["image_urls"], ["http://d/1"])
        json.dumps(rec)

    def test_csv(self):
        rec = record_from_post(self._post(), [{"name": "a.jpg", "webViewLink": "http://d/1"}])
        with tempfile.TemporaryDirectory() as tmp:
            out = Path(tmp) / "posts.csv"
            write_csv([rec], out)
            body = out.read_text(encoding="utf-8")
        self.assertIn("fox, ai", body)
        self.assertIn("https://t.me/chan/42", body)


if __name__ == "__main__":
    unittest.main()
