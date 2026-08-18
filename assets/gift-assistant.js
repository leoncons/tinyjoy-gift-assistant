/* TinyJoy Gift Assistant — Frontend */
(function ($) {
  'use strict';

  const STEP_COUNT = 4;
  const PROGRESS   = [25, 50, 75, 100];

  // ─── Init ──────────────────────────────────────────────────────────────────

  $(function () {
    // Float panel open/close
    $('#tga-float-btn').on('click', function () {
      const $panel = $('#tga-panel-float');
      const isOpen = !$panel.attr('hidden');
      if (isOpen) {
        closeFloatPanel($panel);
      } else {
        openFloatPanel($panel);
      }
    });

    // Close button inside float panel
    $(document).on('click', '.tga-panel--float .tga-close-btn', function () {
      const $panel = $(this).closest('.tga-panel--float');
      closeFloatPanel($panel);
    });

    // Close on outside click
    $(document).on('click', function (e) {
      const $panel = $('#tga-panel-float');
      if ($panel.attr('hidden') !== undefined && $panel.attr('hidden') !== false) return;
      if (
        !$panel.is(e.target) && $panel.has(e.target).length === 0 &&
        !$('#tga-float-btn').is(e.target) && $('#tga-float-btn').has(e.target).length === 0
      ) {
        closeFloatPanel($panel);
      }
    });

    // Close on Escape
    $(document).on('keydown', function (e) {
      if (e.key === 'Escape') {
        const $panel = $('#tga-panel-float');
        if (!$panel.attr('hidden') || $panel.attr('hidden') === false) {
          closeFloatPanel($panel);
        }
      }
    });

    // Init inline wizard (always visible)
    initWizard('#tga-panel-inline');
  });

  function openFloatPanel($panel) {
    $panel.removeAttr('hidden');
    $('#tga-float-btn').attr('aria-expanded', 'true');
    initWizard('#tga-panel-float');
  }

  function closeFloatPanel($panel) {
    $panel.attr('hidden', '');
    $('#tga-float-btn').attr('aria-expanded', 'false');
  }

  // ─── Wizard ────────────────────────────────────────────────────────────────

  function initWizard(panelSelector) {
    const $panel = $(panelSelector);
    if (!$panel.length || $panel.data('tga-init')) return;
    $panel.data('tga-init', true);

    const state = { recipient: '', occasion: '', vibe: '', budget: '' };
    let currentStep = 1;

    // Option selection → auto-advance
    $panel.on('click', '.tga-option', function () {
      const $btn   = $(this);
      const field  = $btn.data('field');
      const value  = $btn.data('value');

      // Deselect siblings, select this
      $panel.find(`.tga-option[data-field="${field}"]`).removeClass('tga-selected');
      $btn.addClass('tga-selected');
      state[field] = value;

      // Short delay for visual feedback, then advance
      setTimeout(() => {
        if (currentStep < STEP_COUNT) {
          goToStep($panel, currentStep + 1, state);
          currentStep++;
        } else {
          // Last step — run search
          runSearch($panel, state);
        }
      }, 200);
    });

    // Restart
    $panel.on('click', '.tga-restart-btn', function () {
      resetWizard($panel, state);
      currentStep = 1;
    });

    showStep($panel, 1);
    setProgress($panel, 1);
  }

  function goToStep($panel, step) {
    showStep($panel, step);
    setProgress($panel, step);
  }

  function showStep($panel, step) {
    $panel.find('.tga-step').attr('hidden', '');
    $panel.find(`.tga-step[data-step="${step}"]`).removeAttr('hidden');
    hideExtras($panel);
  }

  function hideExtras($panel) {
    $panel.find('.tga-loading').attr('hidden', '');
    $panel.find('.tga-results').attr('hidden', '');
    $panel.find('.tga-empty').attr('hidden', '');
  }

  function setProgress($panel, step) {
    const pct = PROGRESS[step - 1] || 100;
    $panel.find('.tga-progress-bar').css('width', pct + '%');
  }

  function resetWizard($panel, state) {
    state.recipient = '';
    state.occasion  = '';
    state.vibe      = '';
    state.budget    = '';
    $panel.find('.tga-option').removeClass('tga-selected');
    $panel.find('.tga-results').attr('hidden', '');
    $panel.find('.tga-empty').attr('hidden', '');
    $panel.find('.tga-loading').attr('hidden', '');
    showStep($panel, 1);
    setProgress($panel, 1);
  }

  // ─── AJAX Search ───────────────────────────────────────────────────────────

  function runSearch($panel, state) {
    // Hide steps, show spinner
    $panel.find('.tga-step').attr('hidden', '');
    $panel.find('.tga-loading').removeAttr('hidden');
    $panel.find('.tga-progress-bar').css('width', '100%');

    $.ajax({
      url:    TGA.ajaxurl,
      method: 'POST',
      data: {
        action:    'tga_find_gifts',
        nonce:     TGA.nonce,
        recipient: state.recipient,
        occasion:  state.occasion,
        vibe:      state.vibe,
        budget:    state.budget,
      },
      success: function (res) {
        $panel.find('.tga-loading').attr('hidden', '');

        if (!res.success || !res.data.products || res.data.products.length === 0) {
          $panel.find('.tga-empty').removeAttr('hidden');
          return;
        }

        renderResults($panel, res.data.products, res.data.message);
        $panel.find('.tga-results').removeAttr('hidden');
      },
      error: function () {
        $panel.find('.tga-loading').attr('hidden', '');
        $panel.find('.tga-empty').removeAttr('hidden');
      },
    });
  }

  // ─── Render Results ────────────────────────────────────────────────────────

  function renderResults($panel, products, message) {
    // AI message
    const $msg = $panel.find('.tga-ai-message');
    $msg.text(message || '');

    // Product cards
    const $grid = $panel.find('.tga-products-grid').empty();

    products.forEach(function (p) {
      const $card = $('<a>')
        .addClass('tga-product-card')
        .attr('href', p.url)
        .attr('target', '_blank')
        .attr('rel', 'noopener noreferrer');

      const $img = $('<img>')
        .addClass('tga-product-img')
        .attr('src', p.image)
        .attr('alt', p.name)
        .attr('loading', 'lazy');

      const $info = $('<div>').addClass('tga-product-info');

      $('<p>').addClass('tga-product-name').text(p.name).appendTo($info);

      if (p.short_desc) {
        $('<p>').addClass('tga-product-desc').text(p.short_desc).appendTo($info);
      }

      $('<p>').addClass('tga-product-price').html(p.price_html).appendTo($info);

      $card.append($img, $info, $('<span>').addClass('tga-product-arrow').text('→'));
      $grid.append($card);
    });
  }

})(jQuery);
