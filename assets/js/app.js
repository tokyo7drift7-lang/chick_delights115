/* =========================================================
   Shared chrome - loaded on every page.
   Mobile nav, saved-recipes drawer, newsletter, toast, year.
   All user-facing strings come from #ch-i18n via T().
   ========================================================= */

const el = {
  overlay: document.getElementById('overlay'),
  drawer: document.getElementById('savedDrawer'),
  btn: document.getElementById('savedBtn'),
  count: document.getElementById('savedCount'),
  drawerCount: document.getElementById('drawerCount'),
  items: document.getElementById('savedItems'),
  close: document.getElementById('closeSaved'),
  menuBtn: document.getElementById('menuBtn'),
  nav: document.getElementById('mainNav'),
  toast: document.getElementById('toast')
};

let saved = loadSaved();

function loadSaved() {
  try {
    const raw = JSON.parse(localStorage.getItem(SAVED_KEY) || '[]');
    if (!Array.isArray(raw)) return [];
    return [...new Set(raw.filter((slug) => RECIPES.some((r) => r.slug === slug)))];
  } catch {
    return [];
  }
}

function persistSaved() {
  try { localStorage.setItem(SAVED_KEY, JSON.stringify(saved)); } catch { /* private mode */ }
}

function renderSaved() {
  const total = saved.length;

  if (el.count) {
    el.count.textContent = total;
    el.count.hidden = total === 0;
  }
  if (el.btn) {
    el.btn.setAttribute('aria-label', total ? T('open_saved_n', { n: total }) : T('open_saved'));
  }
  if (el.drawerCount) el.drawerCount.textContent = '(' + total + ')';

  document.querySelectorAll('[data-save]').forEach((btn) => {
    const isSaved = saved.includes(btn.dataset.save);
    btn.setAttribute('aria-pressed', String(isSaved));

    if (btn.classList.contains('save-recipe') || btn.classList.contains('btn')) {
      btn.textContent = isSaved
        ? T('state_saved')
        : (btn.classList.contains('save-recipe') ? T('btn_save_recipe') : T('btn_save_later'));
    }

    if (btn.classList.contains('save-btn')) {
      const r = RECIPES.find((x) => x.slug === btn.dataset.save) || {};
      const name = r.name || '';
      btn.setAttribute('aria-label', isSaved ? T('aria_remove', { name }) : T('aria_save', { name }));
    }
  });

  if (!el.items) return;

  if (!total) {
    el.items.innerHTML = '<div class="drawer-empty"><p>' + T('empty_title') + '</p>' +
      '<p>' + T('empty_hint') + '</p></div>';
    return;
  }

  el.items.innerHTML = saved.map((slug) => {
    const r = RECIPES.find((x) => x.slug === slug);
    if (!r) return '';
    return '<article class="saved-item">' +
      '<img src="' + r.img + '" alt="' + r.name + '" width="74" height="74">' +
      '<div class="saved-item-info">' +
        '<h3><a href="' + recipeUrl(r.slug) + '">' + r.name + '</a></h3>' +
        '<p>' + r.meta + '</p>' +
        '<div class="saved-item-foot">' +
          '<a href="' + recipeUrl(r.slug) + '">' + T('view') + '</a>' +
          '<button class="saved-remove" data-unsave="' + r.slug + '">' + T('remove') + '</button>' +
        '</div>' +
      '</div>' +
    '</article>';
  }).join('');
}

function toggleSave(slug) {
  const r = RECIPES.find((x) => x.slug === slug);
  if (!r) return;
  const index = saved.indexOf(slug);

  if (index === -1) {
    saved.push(slug);
    persistSaved();
    renderSaved();
    toast(T('saved_toast', { name: r.name }));
  } else {
    saved.splice(index, 1);
    persistSaved();
    renderSaved();
    toast(T('removed_toast'));
  }
}

/* ---------------- Drawer ---------------- */
function openDrawer() {
  if (!el.drawer) return;
  el.drawer.classList.add('is-open');
  el.drawer.removeAttribute('inert');
  el.overlay.hidden = false;
  requestAnimationFrame(() => el.overlay.classList.add('is-visible'));
  document.body.classList.add('no-scroll');
  el.close.focus();
}

function closeDrawer() {
  if (!el.drawer) return;
  const hadFocus = el.drawer.contains(document.activeElement);
  el.drawer.classList.remove('is-open');
  el.drawer.setAttribute('inert', '');
  el.overlay.classList.remove('is-visible');
  document.body.classList.remove('no-scroll');
  if (hadFocus && el.btn) el.btn.focus();
  setTimeout(() => { el.overlay.hidden = true; }, 300);
}

if (el.btn) el.btn.addEventListener('click', openDrawer);
if (el.close) el.close.addEventListener('click', closeDrawer);
if (el.overlay) el.overlay.addEventListener('click', closeDrawer);

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && el.drawer && el.drawer.classList.contains('is-open')) closeDrawer();
});

/* Save / unsave anywhere on the page */
document.addEventListener('click', (e) => {
  const saveBtn = e.target.closest('[data-save]');
  if (saveBtn) {
    e.preventDefault();
    toggleSave(saveBtn.dataset.save);
    return;
  }
  const removeBtn = e.target.closest('[data-unsave]');
  if (removeBtn) {
    e.preventDefault();
    toggleSave(removeBtn.dataset.unsave);
  }
});

/* ---------------- Mobile nav ---------------- */
if (el.menuBtn && el.nav) {
  el.menuBtn.addEventListener('click', () => {
    const open = el.nav.classList.toggle('is-open');
    el.menuBtn.setAttribute('aria-expanded', String(open));
  });
  el.nav.addEventListener('click', (e) => {
    if (e.target.tagName === 'A') {
      el.nav.classList.remove('is-open');
      el.menuBtn.setAttribute('aria-expanded', 'false');
    }
  });
}

/* ---------------- Newsletter ---------------- */
const newsletterForm = document.getElementById('newsletterForm');
if (newsletterForm) {
  newsletterForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const input = document.getElementById('newsletterEmail');
    const value = input.value.trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(value)) {
      toast(T('invalid_email'));
      input.focus();
      return;
    }
    input.value = '';
    toast(T('newsletter_ok'));
  });
}

/* ---------------- Contact form ---------------- */
const contactForm = document.getElementById('contactForm');
if (contactForm) {
  const rules = [
    { id: 'contactName', message: T('name_required') },
    { id: 'contactEmail', message: T('email_invalid'), test: (v) => /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v) },
    { id: 'contactMessage', message: T('msg_required') }
  ];

  const setError = (field, message) => {
    const wrap = field.closest('.field');
    if (!wrap) return;
    let node = wrap.querySelector('.field-error');
    if (message) {
      if (!node) {
        node = document.createElement('p');
        node.className = 'field-error';
        node.setAttribute('role', 'alert');
        wrap.appendChild(node);
      }
      node.textContent = message;
      field.setAttribute('aria-invalid', 'true');
    } else {
      if (node) node.remove();
      field.removeAttribute('aria-invalid');
    }
  };

  contactForm.addEventListener('submit', (e) => {
    e.preventDefault();
    let firstInvalid = null;
    rules.forEach((rule) => {
      const field = document.getElementById(rule.id);
      if (!field) return;
      const value = field.value.trim();
      const valid = value.length > 0 && (!rule.test || rule.test(value));
      setError(field, valid ? '' : rule.message);
      if (!valid && !firstInvalid) firstInvalid = field;
    });
    if (firstInvalid) {
      firstInvalid.focus();
      toast(T('check_fields'));
      return;
    }
    contactForm.reset();
    toast(T('contact_ok'));
  });

  contactForm.addEventListener('input', (e) => {
    if (e.target.matches('input, textarea')) setError(e.target, '');
  });
}

/* ---------------- Boot ---------------- */
const yearEl = document.getElementById('year');
if (yearEl) yearEl.textContent = new Date().getFullYear();

renderSaved();
