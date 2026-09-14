// Smoke tests for the Code-node JavaScript, run outside n8n with small shims.
import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';

const live = readFileSync(new URL('../src/parse_live.js', import.meta.url), 'utf8');
const backfill = readFileSync(new URL('../src/parse_backfill.js', import.meta.url), 'utf8');

// --- live workflow -------------------------------------------------------
const staticData = {};
const runLive = new Function('$input', '$getWorkflowStaticData', live);
const post = (extra) => ({
  json: {
    channel_post: {
      message_id: 42,
      date: 1767225600,
      chat: { id: -1001234567890, username: 'promptchan' },
      caption: 'Prompt: a tiny red fox in neon rain --ar 16:9\nNegative prompt: blurry\n#midjourney #fox',
      photo: [{ file_id: 'small' }, { file_id: 'big' }],
      ...extra,
    },
  },
});

let out = runLive({ all: () => [post()] }, () => staticData);
assert.equal(out.length, 1);
assert.equal(out[0].json.prompt, 'a tiny red fox in neon rain --ar 16:9');
assert.equal(out[0].json.negative_prompt, 'blurry');
assert.deepEqual(out[0].json.tags, ['midjourney', 'fox']);
assert.deepEqual(out[0].json.params, { ar: '16:9' });
assert.equal(out[0].json.file_id, 'big');
assert.equal(out[0].json.file_name, 'promptchan_0000042.jpg');
assert.equal(out[0].json.url, 'https://t.me/promptchan/42');
assert.equal(out[0].json.has_image, true);

// album: caption on the first member is reused by the rest
out = runLive({ all: () => [post({ media_group_id: '777' })] }, () => staticData);
assert.equal(out[0].json.tags_text, 'midjourney, fox');
out = runLive(
  { all: () => [post({ media_group_id: '777', message_id: 43, caption: undefined })] },
  () => staticData
);
assert.equal(out[0].json.prompt, 'a tiny red fox in neon rain --ar 16:9', 'album caption reused');

// text-only post
out = runLive(
  { all: () => [{ json: { channel_post: { message_id: 5, chat: { id: 1 }, text: 'just words #x' } } }] },
  () => staticData
);
assert.equal(out[0].json.has_image, false);
assert.equal(out[0].json.prompt, 'just words');

// --- backfill workflow ---------------------------------------------------
const SAMPLE = `
<div class="tgme_widget_message text_not_supported_wrap js-widget_message" data-post="promptchan/101">
  <a class="tgme_widget_message_photo_wrap" style="width:100%;background-image:url('https://cdn.example/photo101a.jpg')"></a>
  <a class="tgme_widget_message_photo_wrap" style="background-image:url('https://cdn.example/photo101b.jpg')"></a>
  <div class="tgme_widget_message_text js-message_text" dir="auto">Prompt: glass owl on a rooftop<br/>#nanobanana &amp; #owl</div>
  <span class="tgme_widget_message_views">1.2K</span>
  <time class="time" datetime="2026-01-02T10:00:00+00:00">10:00</time>
</div>
<div class="tgme_widget_message js-widget_message" data-post="promptchan/100">
  <div class="tgme_widget_message_text js-message_text" dir="auto">no media here</div>
  <time class="time" datetime="2026-01-01T10:00:00+00:00">10:00</time>
</div>`;

const pages = [];
const httpRequest = async ({ url }) => {
  pages.push(url);
  return pages.length === 1 ? SAMPLE : '';
};
const runBackfill = new Function(
  '$json',
  `return (async function () { ${backfill} }).call({ helpers: { httpRequest: arguments[1] } });`
);
const items = await runBackfill({ channel: '@promptchan', max_pages: 3 }, httpRequest);

assert.equal(items.length, 2);
assert.equal(items[0].json.message_id, 100);
assert.equal(items[1].json.message_id, 101);
assert.equal(items[1].json.prompt, 'glass owl on a rooftop');
assert.deepEqual(items[1].json.tags, ['nanobanana', 'owl']);
assert.deepEqual(items[1].json.image_urls, [
  'https://cdn.example/photo101a.jpg',
  'https://cdn.example/photo101b.jpg',
]);
assert.equal(items[1].json.url, 'https://t.me/promptchan/101');
assert.deepEqual(items[1].json.images, [
  { url: 'https://cdn.example/photo101a.jpg', name: 'promptchan_0000101_0.jpg' },
  { url: 'https://cdn.example/photo101b.jpg', name: 'promptchan_0000101_1.jpg' },
]);
assert.equal(items[0].json.has_image, false);
assert.equal(pages[0], 'https://t.me/s/promptchan');
assert.equal(pages[1], 'https://t.me/s/promptchan?before=100');

// resume marker skips what we already have
const again = await runBackfill(
  { channel: 'promptchan', max_pages: 3, stop_after_id: 100 },
  async () => SAMPLE
);
assert.equal(again.length, 1);
assert.equal(again[0].json.message_id, 101);

console.log('n8n code-node tests: all passed');
