# TinyJoy Gift Assistant

A WordPress plugin that adds an AI-powered gift finder to [tinyjoygifts.com](https://tinyjoygifts.com). Customers answer 4 quick questions — recipient, occasion, vibe, and budget — and get personalized product recommendations with an optional AI-written message powered by Claude.

---

## Features

- **4-step wizard** — recipient → occasion → vibe → budget
- **Floating widget** — persistent 🎁 button on every page, configurable label
- **Shortcode** — embed the full finder on any page with `[tinyjoy_gift_finder]`
- **Progressive matching** — if no exact match, relaxes filters automatically so customers always see results
- **AI message** — Claude Haiku writes a 2-sentence personalized recommendation (optional, requires Anthropic API key)
- **Admin settings** — API key, widget toggle, button label, max results

## Requirements

- WordPress 6.0+
- PHP 8.0+
- WooCommerce (active)

## Installation

1. Download the latest release zip from the [Releases](https://github.com/leoncons/tinyjoy-gift-assistant/releases) page
2. WordPress Admin → Plugins → Add New → Upload Plugin → choose the zip
3. Activate

**Auto-updates:** Install [GitHub Updater](https://github.com/afragen/github-updater) on your WordPress site. It will detect new releases from this repo and surface them through the standard WordPress update screen.

## Configuration

Go to **Settings → Gift Assistant**:

| Setting | Default | Description |
|---|---|---|
| Anthropic API Key | _(blank)_ | From [console.anthropic.com](https://console.anthropic.com) — enables AI gift messages |
| Floating Widget | On | Toggle the 🎁 button sitewide |
| Widget Button Label | `Find a Gift` | Text shown on the floating button |
| Max Products Shown | `3` | Products returned per search (1–6) |

## Shortcode

Embed the full wizard on a dedicated page (e.g. `/gift-finder`):

```
[tinyjoy_gift_finder]
```

The floating widget and shortcode are independent — both can be active at the same time.

## Product Tagging

The Gift Assistant matches customer filters to WooCommerce products using a custom `occasion` taxonomy and standard product tags.

### Step 1 — Register the Occasion Taxonomy

Add this to `functions.php` or a custom plugin (only needed once):

```php
add_action( 'init', function() {
    register_taxonomy( 'occasion', 'product', [
        'label'        => 'Occasions',
        'hierarchical' => false,
        'show_ui'      => true,
        'show_in_menu' => true,
        'rewrite'      => [ 'slug' => 'occasion' ],
    ]);
});
```

### Step 2 — Tag Each Product

Edit each WooCommerce product and assign the appropriate slugs:

**Occasion** (custom taxonomy)

| Slug | Label |
|---|---|
| `birthday` | Birthday |
| `anniversary` | Anniversary |
| `christmas` | Christmas / Holiday |
| `thank-you` | Thank You |
| `graduation` | Graduation |
| `housewarming` | Housewarming |
| `valentines-day` | Valentine's Day |
| `mothers-day` | Mother's Day |
| `fathers-day` | Father's Day |
| `baby-shower` | Baby Shower |
| `just-because` | Just Because |
| `wedding` | Wedding |

**Recipient** (standard product tag)

`for-mom` · `for-dad` · `for-partner` · `for-friend` · `for-coworker` · `for-teacher` · `for-teen` · `for-kids`

**Vibe** (standard product tag)

`sentimental` · `funny` · `cute` · `practical` · `creative` · `personalized`

### Recommended Tags by Product

| Product | Recipient Tags | Vibe Tags | Occasions |
|---|---|---|---|
| Acrylic Photo Keychain ($17.99) | for-mom, for-dad, for-partner, for-friend, for-teen | sentimental, personalized | birthday, anniversary, mothers-day, fathers-day, graduation, just-because, christmas |
| Mini Canvas Print ($27.99) | for-mom, for-dad, for-partner, for-friend | sentimental, personalized, creative | birthday, anniversary, christmas, mothers-day, fathers-day, housewarming |
| Greeting Card ($9.99) | for-mom, for-dad, for-partner, for-friend, for-coworker, for-teacher | cute, sentimental | birthday, thank-you, just-because, christmas, valentines-day, mothers-day, fathers-day |
| Mini Photo Book ($39.99) | for-mom, for-dad, for-partner, for-friend | sentimental, personalized, creative | anniversary, christmas, mothers-day, fathers-day, graduation, birthday |
| Magnet Set ($21.99) | for-mom, for-dad, for-partner, for-friend | cute, sentimental, personalized | birthday, christmas, housewarming, just-because, mothers-day, fathers-day |
| Enamel Pin ($16.99) | for-friend, for-teen, for-coworker | funny, cute | birthday, just-because, christmas |
| Custom Notepad ($18.99) | for-mom, for-coworker, for-teacher | practical, personalized | birthday, thank-you, christmas, graduation, just-because |
| Luggage / Bag Tag ($16.99) | for-partner, for-friend, for-dad | practical, personalized | birthday, christmas, graduation, just-because |
| Laminated Bookmark ($8.99) | for-mom, for-friend, for-teacher, for-coworker | sentimental, cute | birthday, thank-you, christmas, graduation, just-because |
| Mini Puzzle ($26.99) | for-mom, for-dad, for-partner, for-friend, for-kids, for-teen | funny, creative, sentimental | birthday, christmas, anniversary, just-because |

## How It Works

1. Customer answers 4 questions in the wizard
2. Plugin queries WooCommerce for products matching all selected tags + budget
3. If no match, progressively relaxes: drops vibe → drops occasion → drops recipient → budget only
4. Claude Haiku writes a 2-sentence personalized message (if API key is set)
5. Product cards shown with image, name, price, and link to the product page

## Releasing Updates

1. Make your changes
2. Bump `Version:` in `tinyjoy-gift-assistant.php` and `TGA_VERSION`
3. Commit and push to `main`
4. Create a GitHub Release — WordPress sites with GitHub Updater will be notified automatically

## License

Proprietary — © TinyJoy. All rights reserved.
