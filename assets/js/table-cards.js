/* Phones: data tables become a stack of cards. This script copies each column heading onto its cells
 * (data-label) so the CSS in assets/css/mobile.css can show "Email · rahul@…" on every card.
 * Runs on every table with a header row of 3+ columns inside the admin area, including rows added later.
 * Opt out with <table class="no-cards">. */
(function () {
  var root = document.querySelector('.admin-main');
  if (!root) return;

  function txt(el) { return (el.textContent || '').replace(/\s+/g, ' ').trim(); }
  function onlyCheckbox(td) {
    var inp = td.querySelectorAll('input,button,a,select,img,svg');
    return inp.length === 1 && inp[0].matches('input[type="checkbox"]') && txt(td) === '';
  }

  function prepare(table) {
    if (table.classList.contains('no-cards')) return;
    var ths = table.querySelectorAll('thead th');
    if (!ths.length) { var first = table.querySelector('tr'); if (first && first.querySelector('th')) ths = first.querySelectorAll('th'); else return; }
    if (ths.length < 3) return;
    var heads = Array.prototype.map.call(ths, txt);
    table.classList.add('wm-cards');
    var rows = table.tBodies.length ? table.querySelectorAll('tbody tr') : table.querySelectorAll('tr');
    Array.prototype.forEach.call(rows, function (tr) {
      if (tr.__wmCard === tr.cells.length || tr.querySelector('th')) return;
      tr.__wmCard = tr.cells.length;
      var col = 0, headDone = false;
      Array.prototype.forEach.call(tr.cells, function (td) {
        var span = parseInt(td.getAttribute('colspan') || '1', 10) || 1;
        if (span > 1 || tr.cells.length === 1) { td.classList.add('wm-full'); col += span; return; }
        if (onlyCheckbox(td)) { td.classList.add('wm-check'); col += span; return; }
        // a placeholder such as "—" where other rows have a checkbox: not a title
        if (!headDone && /^[\-\u2013\u2014]?$/.test(txt(td)) && !td.querySelector('button,a,input,select,img,svg')) { td.classList.add('wm-empty'); col += span; return; }
        td.setAttribute('data-label', heads[col] || '');
        if (!headDone) { td.classList.add('wm-head'); headDone = true; }   // first real cell is the card title
        if (txt(td) === '' && !td.querySelector('button,a,input,select,img,svg')) td.classList.add('wm-empty');
        col += span;
      });
    });
  }

  function all() { Array.prototype.forEach.call(root.querySelectorAll('table'), prepare); }
  all();
  if (window.MutationObserver) {
    var t;
    new MutationObserver(function () { clearTimeout(t); t = setTimeout(all, 120); }).observe(root, { childList: true, subtree: true });
  }
})();
