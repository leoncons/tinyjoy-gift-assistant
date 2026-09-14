// Backfill: walk a PUBLIC channel's web preview (https://t.me/s/<channel>)
// page by page and emit one item per post. No Telegram API credentials needed;
// works only for public channels. Use the Python scraper for private ones.

const CHANNEL = ($json.channel || '').replace(/^@/, '').replace(/^https?:\/\/t\.me\/(s\/)?/, '');
const MAX_PAGES = Number($json.max_pages || 20);
const STOP_AFTER_ID = Number($json.stop_after_id || 0); // resume marker: ignore ids <= this

const HASHTAG = /(?<!\w)#([^\s#,.;:!?()\[\]{}"']+)/g;
const CODE_BLOCK = /```(?:\w+)?\n?([\s\S]+?)```/g;
const LABEL = /^\s*(?:\*\*|__)?\s*(?:prompt|промпт|prompt\s*\d+)\s*(?:\*\*|__)?\s*[:\-–—]\s*/im;
const NEGATIVE = /^\s*(?:negative\s*prompt|negative)\s*[:\-–—]\s*(.+)$/im;
const QUOTE = /^>\s?(.+)$/gm;
const PARAM = /--(\w+)\s+([^\s-][^\s]*)/g;
const URL = /https?:\/\/\S+/g;

const ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', '#39': "'", nbsp: ' ', '#036': '$' };

function decode(text) {
  return text
    .replace(/&#(\d+);/g, (_, code) => String.fromCharCode(Number(code)))
    .replace(/&([a-z#0-9]+);/gi, (match, name) => ENTITIES[name.toLowerCase()] ?? match);
}

function htmlToText(html) {
  return decode(
    html
      .replace(/<br\s*\/?>/gi, '\n')
      .replace(/<\/(p|div)>/gi, '\n')
      .replace(/<[^>]+>/g, '')
  ).replace(/\n{3,}/g, '\n\n').trim();
}

function tagsOf(text) {
  const out = [];
  for (const m of (text || '').matchAll(HASHTAG)) {
    const tag = m[1].replace(/^[_-]+|[_-]+$/g, '').toLowerCase();
    if (tag && !out.includes(tag)) out.push(tag);
  }
  return out;
}

function clean(text) {
  return (text || '')
    .replace(HASHTAG, '')
    .replace(URL, '')
    .split('\n')
    .map((line) =>
      line
        .replace(/[ \t]{2,}/g, ' ')
        .trim()
        .replace(/^[-–—|&,·•*\s]+|[-–—|&,·•*\s]+$/g, '')
    )
    .filter((line) => /[\p{L}\p{N}]/u.test(line))
    .join('\n')
    .trim();
}

function promptOf(text) {
  if (!text) return '';
  const blocks = [...text.matchAll(CODE_BLOCK)].map((m) => m[1].trim()).filter(Boolean);
  if (blocks.length) return blocks.sort((a, b) => b.length - a.length)[0];
  const label = text.match(LABEL);
  if (label) {
    const tail = text.slice(label.index + label[0].length);
    const stop = tail.match(/\n\s*\w[\w ]{0,20}\s*[:\-–—]\s/);
    const body = clean(stop ? tail.slice(0, stop.index) : tail);
    if (body) return body;
  }
  const quotes = [...text.matchAll(QUOTE)].map((m) => m[1]);
  if (quotes.length) return clean(quotes.join('\n'));
  return clean(text);
}

function parse(text) {
  text = text || '';
  let negative = '';
  let body = text;
  const neg = text.match(NEGATIVE);
  if (neg) {
    negative = clean(neg[1]);
    body = text.slice(0, neg.index) + text.slice(neg.index + neg[0].length);
  }
  const params = {};
  for (const m of text.matchAll(PARAM)) params[m[1].toLowerCase()] = m[2];
  return {
    prompt: promptOf(body),
    negative_prompt: negative,
    tags: tagsOf(text),
    params,
    links: text.match(URL) || [],
    raw_text: text,
  };
}

function parsePage(html) {
  const posts = [];
  // Each post starts at a data-post="channel/<id>" marker.
  const chunks = html.split(/<div class="tgme_widget_message[^"]*"[^>]*data-post="/).slice(1);
  for (const chunk of chunks) {
    const idMatch = chunk.match(/^[^/]+\/(\d+)"/);
    if (!idMatch) continue;
    const messageId = Number(idMatch[1]);

    const textMatch = chunk.match(
      /<div class="tgme_widget_message_text[^"]*"[^>]*>([\s\S]*?)<\/div>/
    );
    const text = textMatch ? htmlToText(textMatch[1]) : '';

    const images = [];
    for (const m of chunk.matchAll(/background-image:url\('([^']+)'\)/g)) {
      if (!m[1].includes('emoji') && !images.includes(m[1])) images.push(m[1]);
    }

    const timeMatch = chunk.match(/<time[^>]*datetime="([^"]+)"/);
    const viewsMatch = chunk.match(/tgme_widget_message_views">([^<]+)</);

    posts.push({
      message_id: messageId,
      date: timeMatch ? timeMatch[1] : '',
      views: viewsMatch ? viewsMatch[1] : null,
      url: `https://t.me/${CHANNEL}/${messageId}`,
      channel: `@${CHANNEL}`,
      image_urls: images,
      ...parse(text),
    });
  }
  return posts;
}

if (!CHANNEL) throw new Error('Set "channel" in the Config node, e.g. @some_channel');

const collected = new Map();
let before = null;

for (let page = 0; page < MAX_PAGES; page++) {
  const url = `https://t.me/s/${CHANNEL}${before ? `?before=${before}` : ''}`;
  const html = await this.helpers.httpRequest({
    url,
    method: 'GET',
    headers: { 'User-Agent': 'Mozilla/5.0 (compatible; n8n-telegram-scraper/1.0)' },
    json: false,
  });

  const posts = parsePage(html);
  if (!posts.length) break;

  for (const post of posts) {
    if (post.message_id > STOP_AFTER_ID) collected.set(post.message_id, post);
  }

  const oldest = Math.min(...posts.map((p) => p.message_id));
  if (oldest <= STOP_AFTER_ID + 1) break; // reached what we already have
  before = oldest;
}

return [...collected.values()]
  .sort((a, b) => a.message_id - b.message_id)
  .map((post) => ({
    json: {
      ...post,
      // {url, name} pairs so the download + upload nodes need no extra logic
      images: post.image_urls.map((url, index) => ({
        url,
        name: `${CHANNEL}_${String(post.message_id).padStart(7, '0')}_${index}.jpg`,
      })),
      has_image: post.image_urls.length > 0,
      image_count: post.image_urls.length,
      tags_text: post.tags.join(', '),
      params_text: JSON.stringify(post.params),
      links_text: post.links.join(' '),
      image_urls_text: post.image_urls.join(' '),
    },
  }));
