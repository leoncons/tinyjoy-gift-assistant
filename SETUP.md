# TinyJoy Gift Assistant — Setup Guide

## Installation

1. Zip the `tinyjoy-gift-assistant/` folder
2. WordPress Admin → Plugins → Add New → Upload Plugin
3. Activate

## Admin Settings

Go to **Settings → Gift Assistant**:

| Setting | Description |
|---|---|
| Anthropic API Key | Paste your key from console.anthropic.com — powers the personalized message |
| Floating Widget | Toggle the 🎁 button on/off across all pages |
| Widget Button Label | Customize the button text (default: "Find a Gift") |
| Max Products Shown | How many products to show per search (default: 3) |

## Shortcode

Embed on any page (e.g. /gift-finder):

```
[tinyjoy_gift_finder]
```

The floating widget is separate — both can coexist.

---

## Tagging Products in WooCommerce

The Gift Assistant matches filters to your WooCommerce products using:
- A custom taxonomy called `occasion`
- Standard WooCommerce product tags

### Step 1 — Add the Occasion Taxonomy

If `occasion` isn't already registered, add this to your theme's `functions.php` or a custom plugin:

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

After saving, you'll see "Occasions" in the WooCommerce product sidebar.

### Step 2 — Tag Each Product

Go to **Products → All Products**, edit each product, and assign:

#### Occasion (custom taxonomy)

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

#### Recipient (standard product tag)

`for-mom` · `for-dad` · `for-partner` · `for-friend` · `for-coworker` · `for-teacher` · `for-teen` · `for-kids`

#### Vibe (standard product tag)

`sentimental` · `funny` · `cute` · `practical` · `creative` · `personalized`

---

## Recommended Tags by Product

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

---

## How Matching Works

1. Customer selects recipient → occasion → vibe → budget
2. Plugin queries WooCommerce products matching ALL selected tags
3. If no products match, it progressively relaxes: drops vibe → drops occasion → drops recipient → filters only by budget
4. Claude AI writes a 2-sentence personalized message (if API key set)
5. Product cards shown with image, name, price, and link to product page

## Fallback Behavior

If products aren't tagged yet, the assistant will still show products that match the budget filter. Tag as many products as possible for best results.
