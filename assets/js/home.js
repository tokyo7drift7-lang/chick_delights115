/* =========================================================
   Home page only: chip filters + live search over the
   static recipe cards. Cards are server-rendered, so the
   archive works even with JavaScript disabled.
   ========================================================= */

const homeEl = {
  grid: document.getElementById('recipeGrid'),
  chips: document.getElementById('chips'),
  search: document.getElementById('searchInput'),
  empty: document.getElementById('emptyState'),
  reset: document.getElementById('resetFilters')
};

let activeFilter = 'all';
let query = '';

const cards = homeEl.grid
  ? Array.from(homeEl.grid.querySelectorAll('.card'))
  : [];

function applyFilters() {
  if (!homeEl.grid) return;

  const q = query.trim().toLowerCase();
  let visible = 0;

  cards.forEach((card) => {
    const inCat = activeFilter === 'all' || card.dataset.cat === activeFilter;
    const haystack = (card.dataset.search || '').toLowerCase();
    const inQuery = !q || haystack.includes(q);
    const show = inCat && inQuery;

    card.hidden = !show;
    if (show) visible += 1;
  });

  if (homeEl.empty) homeEl.empty.hidden = visible > 0;
}

function setFilter(filter, scroll = false) {
  activeFilter = filter;

  if (homeEl.chips) {
    homeEl.chips.querySelectorAll('.chip').forEach((chip) => {
      const on = chip.dataset.filter === filter;
      chip.classList.toggle('is-active', on);
      chip.setAttribute('aria-pressed', String(on));
    });
  }

  applyFilters();

  if (scroll && document.getElementById('recipes')) {
    document.getElementById('recipes').scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
}

if (homeEl.chips) {
  homeEl.chips.addEventListener('click', (e) => {
    const chip = e.target.closest('.chip');
    if (chip) setFilter(chip.dataset.filter);
  });
}

/* Category circles behave like chips */
const catGrid = document.getElementById('categoriesGrid');
if (catGrid) {
  catGrid.addEventListener('click', (e) => {
    const btn = e.target.closest('.cat');
    if (btn) setFilter(btn.dataset.filter, true);
  });
}

if (homeEl.search) {
  homeEl.search.addEventListener('input', (e) => {
    query = e.target.value;
    applyFilters();
  });

  const form = homeEl.search.closest('form');
  if (form) {
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      document.getElementById('recipes').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }
}

if (homeEl.reset) {
  homeEl.reset.addEventListener('click', () => {
    query = '';
    if (homeEl.search) homeEl.search.value = '';
    setFilter('all');
    if (homeEl.search) homeEl.search.focus();
  });
}

document.querySelectorAll('[data-filter-link]').forEach((link) => {
  link.addEventListener('click', (e) => {
    e.preventDefault();
    setFilter(link.dataset.filterLink, true);
  });
});

applyFilters();

/* Deep link: ?cat=curry#recipes */
const catParam = new URLSearchParams(window.location.search).get('cat');
if (catParam) {
  const known = Array.from(document.querySelectorAll('.chip')).some((c) => c.dataset.filter === catParam);
  if (known) setFilter(catParam);
}
