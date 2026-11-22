/* =========================================================
   Recipe pages only: serving scaler, ingredient ticks, print.
   Strings and decimal separator come from #ch-i18n.
   ========================================================= */

(function () {
  const list = document.getElementById('ingredients');
  const scaler = document.getElementById('scaler');
  const printBtn = document.getElementById('printRecipe');

  if (printBtn) {
    printBtn.addEventListener('click', () => window.print());
  }

  if (!list) return;

  const base = Number(list.dataset.base) || 4;

  function formatAmount(value) {
    if (value >= 10) return String(Math.round(value));

    const rounded = Math.round(value * 100) / 100;
    if (Number.isInteger(rounded)) return String(rounded);

    const whole = Math.floor(rounded);
    const frac = Math.round((rounded - whole) * 100) / 100;
    const glyphs = { 0.25: '\u00BC', 0.33: '\u2153', 0.5: '\u00BD', 0.67: '\u2154', 0.75: '\u00BE' };

    if (glyphs[frac]) return (whole ? whole + ' ' : '') + glyphs[frac];

    let text = String(rounded);
    if (typeof CH_DECIMAL === 'string' && CH_DECIMAL === ',') text = text.replace('.', ',');
    return text;
  }

  function scaleTo(servings) {
    const factor = servings / base;

    list.querySelectorAll('li[data-qty]').forEach((li) => {
      const amount = li.querySelector('.amt');
      if (!amount) return;
      const qty = Number(li.dataset.qty);
      if (!Number.isFinite(qty) || qty <= 0) return;
      const shown = formatAmount(qty * factor);
      let unitLabel = li.dataset.unit || '';
      if (shown === '1' && li.dataset.unitOne) unitLabel = li.dataset.unitOne;
      amount.textContent = shown + (unitLabel ? ' ' + unitLabel : '');
    });

    if (scaler) {
      scaler.querySelectorAll('button').forEach((btn) => {
        btn.setAttribute('aria-pressed', String(Number(btn.dataset.serves) === servings));
      });
    }

    const note = document.getElementById('scalerNote');
    if (note) note.textContent = T('scaled', { n: servings });
  }

  if (scaler) {
    scaler.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-serves]');
      if (!btn) return;
      scaleTo(Number(btn.dataset.serves));
    });
  }

  list.addEventListener('change', (e) => {
    if (e.target.matches('input[type="checkbox"]')) {
      e.target.closest('li').classList.toggle('is-checked', e.target.checked);
    }
  });
})();
