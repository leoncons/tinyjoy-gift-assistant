// Parse one Telegram channel post into prompt / tags / image references.
// Albums arrive as separate updates that share media_group_id and carry the
// caption on one member only, so captions are cached in workflow static data.

const HASHTAG = /(?<!\w)#([^\s#,.;:!?()\[\]{}"']+)/g;
const CODE_BLOCK = /```(?:\w+)?\n?([\s\S]+?)```/g;
const LABEL = /^\s*(?:\*\*|__)?\s*(?:prompt|промпт|prompt\s*\d+)\s*(?:\*\*|__)?\s*[:\-–—]\s*/im;
const NEGATIVE = /^\s*(?:negative\s*prompt|negative)\s*[:\-–—]\s*(.+)$/im;
const QUOTE = /^>\s?(.+)$/gm;
const PARAM = /--(\w+)\s+([^\s-][^\s]*)/g;
const URL = /https?:\/\/\S+/g;

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
  const neg = text.match(NEGATIVE);
  let body = text;
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

const store = $getWorkflowStaticData('global');
store.albums = store.albums || {};
const out = [];

for (const item of $input.all()) {
  const post = item.json.channel_post || item.json.message || item.json;
  const chat = post.chat || {};
  const groupId = post.media_group_id || null;
  let text = post.text || post.caption || '';

  // Album: remember the caption, reuse it for the other members.
  if (groupId) {
    if (text) store.albums[groupId] = { text, at: Date.now() };
    else text = (store.albums[groupId] || {}).text || '';
    // drop cached captions older than an hour
    for (const [key, value] of Object.entries(store.albums)) {
      if (Date.now() - value.at > 3600000) delete store.albums[key];
    }
  }

  // Largest photo size, or an image document.
  let fileId = null;
  let fileName = null;
  if (Array.isArray(post.photo) && post.photo.length) {
    fileId = post.photo[post.photo.length - 1].file_id;
    fileName = `${chat.username || chat.id}_${String(post.message_id).padStart(7, '0')}.jpg`;
  } else if (post.document && String(post.document.mime_type || '').startsWith('image/')) {
    fileId = post.document.file_id;
    const ext = (post.document.file_name || '').split('.').pop() || 'jpg';
    fileName = `${chat.username || chat.id}_${String(post.message_id).padStart(7, '0')}.${ext}`;
  }

  const parsed = parse(text);
  out.push({
    json: {
      message_id: post.message_id,
      media_group_id: groupId,
      date: post.date ? new Date(post.date * 1000).toISOString() : new Date().toISOString(),
      channel: chat.username ? `@${chat.username}` : String(chat.id || ''),
      url: chat.username
        ? `https://t.me/${chat.username}/${post.message_id}`
        : `https://t.me/c/${String(chat.id || '').replace('-100', '')}/${post.message_id}`,
      has_image: Boolean(fileId),
      file_id: fileId,
      file_name: fileName,
      ...parsed,
      tags_text: parsed.tags.join(', '),
      params_text: JSON.stringify(parsed.params),
      links_text: (parsed.links || []).join(' '),
    },
  });
}

return out;
