/**
 * SEO field input. This file is the source — there is no compile step.
 * It lives under dist/ because that is Craft's AssetBundle convention.
 */
(function () {
  'use strict';

  /**
   * Must match TitleFormatter::DEFAULT_FORMAT. Locked by
   * SeoFieldContractsTest::testJsTitleFormatDefaultMatchesPhp.
   */
  var DEFAULT_TITLE_FORMAT = '{title} - {siteName}';

  /**
   * Applies the site title format to a meta/fallback title. Mirrors
   * TitleFormatter::format() in PHP — keep the two in lockstep.
   */
  function formatSeoTitle(fieldTitle, fallbackTitle, siteName, format) {
    var base = String(fieldTitle || fallbackTitle || '').trim();
    siteName = String(siteName || '');
    format = String(format || DEFAULT_TITLE_FORMAT);

    if (base === '') {
      return siteName;
    }
    if (siteName !== '' && format.indexOf('{siteName}') !== -1 && base.indexOf(siteName) !== -1) {
      return base;
    }
    return format.split('{title}').join(base).split('{siteName}').join(siteName).trim();
  }

  if (typeof module !== 'undefined' && module.exports) {
    module.exports = { formatSeoTitle: formatSeoTitle, DEFAULT_TITLE_FORMAT: DEFAULT_TITLE_FORMAT };
    return;
  }

  if (window.SimpleSeoField) {
    return;
  }
  window.SimpleSeoField = { formatSeoTitle: formatSeoTitle };

  /**
   * Applies a character count to a counter: number, thresholds, and the
   * limit-crossing announcement. Thresholds (limit, limit * nearRatio) are
   * also rendered server-side in input.twig — keep the two in lockstep.
   * nearRatio comes from SeoField::LIMIT_NEAR_RATIO via data-near-ratio.
   */
  function applyCount(counter, length) {
    var limit = parseInt(counter.getAttribute('data-limit'), 10) || 0;
    var nearRatio = parseFloat(counter.getAttribute('data-near-ratio')) || 0.9;
    var count = counter.querySelector('[data-count]');
    if (count) {
      count.textContent = String(length);
    }

    var over = length > limit;
    var wasOver = counter.classList.contains('simple-seo-counter--over');
    counter.classList.toggle('simple-seo-counter--over', over);
    counter.classList.toggle('simple-seo-counter--near', !over && limit > 0 && length >= limit * nearRatio);

    if (over !== wasOver) {
      var container = counter.closest('[data-simple-seo-field]');
      var messages = container ? container.dataset : {};
      var text = over
        ? String(messages.overMessage || '').replace('{count}', String(length)).replace('{limit}', String(limit))
        : String(messages.okMessage || '');
      if (text !== '') {
        var status = counter.querySelector('[data-counter-status]');
        if (!status) {
          status = document.createElement('span');
          status.setAttribute('data-counter-status', '');
          status.setAttribute('role', 'status');
          status.setAttribute('aria-live', 'polite');
          status.className = 'visually-hidden';
          counter.appendChild(status);
        }
        status.textContent = text;
      }
    }
  }

  /**
   * Counts an input's raw value. The title counter carries
   * data-counter-formatted and is skipped here: it measures the formatted
   * title, which updatePreview() owns.
   */
  function updateCounter(counter, input) {
    if (counter.hasAttribute('data-counter-formatted')) {
      return;
    }
    applyCount(counter, Array.from(input.value).length);
  }

  function updatePreview(container) {
    var data = container.dataset;
    var titleInput = container.querySelector('input[id$="-title"]');
    var descInput = container.querySelector('textarea[id$="-description"]');
    var chipImage = container.querySelector('.elementselect img');

    var titleVal = titleInput ? titleInput.value : '';
    var descVal = String(descInput ? descInput.value : '').trim();

    var formattedTitle = formatSeoTitle(titleVal, data.fallbackTitle, data.siteName, data.titleFormat);

    var titleNode = container.querySelector('[data-preview-title]');
    if (titleNode) {
      titleNode.textContent = formattedTitle;
    }

    // The title counter measures what the SERP shows — the formatted title,
    // not the raw input. The marker only renders when preview data exists,
    // so there is always a format to apply here.
    var titleCounter = container.querySelector('[data-counter-formatted]');
    if (titleCounter) {
      applyCount(titleCounter, Array.from(formattedTitle).length);
    }

    var socialTitleNode = container.querySelector('[data-preview-social-title]');
    if (socialTitleNode) {
      var socialTitle = String(titleVal || data.fallbackTitle || '').trim();
      socialTitleNode.textContent = socialTitle !== '' ? socialTitle : String(data.siteName || '');
    }

    var resolvedDesc = descVal || data.defaultDescription || '';
    container.querySelectorAll('[data-preview-description]').forEach(function (node) {
      node.textContent = resolvedDesc || data.descriptionPlaceholder || '';
      node.classList.toggle('simple-seo-preview__desc--empty', resolvedDesc === '');
    });

    var imageNode = container.querySelector('[data-preview-image]');
    if (imageNode) {
      var src = chipImage ? chipImage.getAttribute('src') || '' : '';
      if (src === '') {
        src = data.defaultSocialImageUrl || '';
      }
      imageNode.style.backgroundImage = src ? 'url("' + src.replace(/"/g, '\\"') + '")' : '';
      imageNode.classList.toggle('simple-seo-preview__image--empty', src === '');
    }
  }

  var pending = new Set();
  var scheduled = false;

  function schedule(container) {
    pending.add(container);
    if (scheduled) {
      return;
    }
    scheduled = true;
    requestAnimationFrame(function () {
      scheduled = false;
      pending.forEach(updatePreview);
      pending.clear();
    });
  }

  document.addEventListener('input', function (event) {
    var target = event.target;
    if (!target || typeof target.value !== 'string') {
      return;
    }

    if (target.id) {
      var counter = document.querySelector('[data-counter-for="' + CSS.escape(target.id) + '"]');
      if (counter) {
        updateCounter(counter, target);
      }
    }

    var container = target.closest('[data-simple-seo-field]');
    if (container) {
      schedule(container);
    } else if (target.id === 'title') {
      document.querySelectorAll('[data-simple-seo-field]').forEach(function (c) {
        c.dataset.fallbackTitle = target.value;
        schedule(c);
      });
    }
  });

  function observe() {
    new MutationObserver(function (mutations) {
      for (var i = 0; i < mutations.length; i++) {
        var node = mutations[i].target;
        if (!node || !node.closest || node.closest('.simple-seo-preview')) {
          continue;
        }
        var container = node.closest('[data-simple-seo-field]');
        if (container) {
          schedule(container);
        }
      }
    }).observe(document.body, {
      subtree: true,
      childList: true,
      attributes: true,
      attributeFilter: ['src'],
    });
  }

  if (document.body) {
    observe();
  } else {
    document.addEventListener('DOMContentLoaded', observe);
  }

  // Preview tabs: ARIA tabs pattern with roving tabindex. The tabs are real
  // <button>s (click fires on Enter/Space natively), so only arrow-key
  // navigation needs wiring — delegated through Garnish, using its key
  // constants, so dynamically-injected fields need no init here either.
  function selectPreviewTab(tab) {
    var root = tab.closest('.simple-seo-preview');
    if (!root) {
      return;
    }
    root.querySelectorAll('[data-preview-tab]').forEach(function (btn) {
      var selected = btn === tab;
      btn.setAttribute('aria-selected', selected ? 'true' : 'false');
      btn.tabIndex = selected ? 0 : -1;
      btn.classList.toggle('sel', selected);
    });
    root.querySelectorAll('[data-preview-pane]').forEach(function (pane) {
      pane.classList.toggle('hidden', pane.dataset.previewPane !== tab.dataset.previewTab);
    });
  }

  if (typeof Garnish !== 'undefined' && typeof $ !== 'undefined') {
    Garnish.$doc.on('click', '[data-preview-tab]', function () {
      selectPreviewTab(this);
    });

    Garnish.$doc.on('keydown', '[data-preview-tab]', function (ev) {
      var horizontal = ev.keyCode === Garnish.LEFT_KEY || ev.keyCode === Garnish.RIGHT_KEY;
      var jump = ev.keyCode === Garnish.HOME_KEY || ev.keyCode === Garnish.END_KEY;
      if (!horizontal && !jump) {
        return;
      }
      ev.preventDefault();
      var tabs = Array.from(this.closest('[role="tablist"]').querySelectorAll('[data-preview-tab]'));
      var index = tabs.indexOf(this);
      var next;
      if (ev.keyCode === Garnish.HOME_KEY) {
        next = 0;
      } else if (ev.keyCode === Garnish.END_KEY) {
        next = tabs.length - 1;
      } else {
        var delta = ev.keyCode === Garnish.RIGHT_KEY ? 1 : -1;
        next = (index + delta + tabs.length) % tabs.length;
      }
      tabs[next].focus();
      selectPreviewTab(tabs[next]);
    });
  }
})();
