/* Industry hero mockup: turns the chat into a WhatsApp-style conversation with three-dot typing, looping. */
(function () {
  var box = document.querySelector('.ind-mockup');
  if (!box) return;
  var chat = box.querySelector('.ind-mockup-chat');
  if (!chat) return;
  var rows = Array.prototype.slice.call(chat.querySelectorAll('.ind-chat-row'));
  var notif = box.querySelector('.ind-mockup-notif');
  var ticks = '<svg width="16" height="11" viewBox="0 0 16 11" fill="none" stroke="#53bdeb" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 6 4 9 10 2"/><polyline points="6 8 7.5 9 14 2"/></svg>';

  // header: back arrow, avatar, name + online, icons
  var brand = box.querySelector('.ind-mockup-brand');
  var header = box.querySelector('.ind-mockup-header');
  if (brand && header) {
    var dot = brand.querySelector('.ind-mockup-dot');
    var name = brand.textContent.replace(/\s+/g, ' ').trim();
    brand.innerHTML = '<span class="iw-back"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg></span>';
    if (dot) brand.appendChild(dot);
    var nm = document.createElement('span');
    nm.className = 'iw-name';
    nm.innerHTML = '<b></b><i>online</i>';
    nm.firstChild.textContent = name;
    brand.appendChild(nm);
    var ic = document.createElement('div');
    ic.className = 'iw-icons';
    ic.setAttribute('aria-hidden', 'true');
    ic.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg><svg width="19" height="19" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>';
    header.appendChild(ic);
  }

  // date chip + timestamps + ticks
  var label = chat.querySelector('.ind-chat-label');
  var date = document.createElement('div');
  date.className = 'iw-date';
  date.textContent = 'TODAY';
  if (label && label.nextSibling) chat.insertBefore(date, label.nextSibling); else chat.insertBefore(date, chat.firstChild);
  rows.forEach(function (r, i) {
    var b = r.querySelector('.ind-chat-bubble');
    if (!b) return;
    var t = document.createElement('span');
    t.className = 'iw-t';
    t.innerHTML = '10:' + (41 + Math.floor(i / 2)) + (r.classList.contains('ind-chat-out') ? ' ' + ticks : '');
    b.appendChild(t);
  });

  // input bar
  var bar = document.createElement('div');
  bar.className = 'iw-input';
  bar.setAttribute('aria-hidden', 'true');
  bar.innerHTML = '<div class="iw-field"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" x2="9.01" y1="9" y2="9"/><line x1="15" x2="15.01" y1="9" y2="9"/></svg>Message</div><span class="iw-mic"><svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" x2="12" y1="19" y2="22"/></svg></span>';
  chat.parentNode.insertBefore(bar, chat.nextSibling);

  if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  // animation: typing dots, then message; keep the latest 4; loop
  chat.classList.add('iw-js');
  var MAX = 4, TYPE = 1100, GAP = 800, HOLD = 3200, shown = [], idx = 0;
  if (notif) notif.classList.add('iw-hide');
  function reveal(el) {
    el.classList.add('iw-show');
    shown.push(el);
    if (shown.length > MAX) shown.shift().classList.remove('iw-show');
  }
  function next() {
    if (idx >= rows.length) {
      if (notif) notif.classList.remove('iw-hide');
      setTimeout(function () {
        shown.forEach(function (r) { r.classList.remove('iw-show'); });
        shown = []; idx = 0;
        if (notif) notif.classList.add('iw-hide');
        setTimeout(next, 700);
      }, HOLD);
      return;
    }
    var row = rows[idx++];
    var t = document.createElement('div');
    t.className = 'ind-chat-row iw-typing iw-show' + (row.classList.contains('ind-chat-out') ? ' ind-chat-out' : '');
    t.innerHTML = '<div class="ind-chat-bubble' + (row.classList.contains('ind-chat-out') ? ' ind-chat-bubble-out' : '') + '"><span></span><span></span><span></span></div>';
    chat.insertBefore(t, row);
    setTimeout(function () {
      chat.removeChild(t);
      reveal(row);
      setTimeout(next, GAP);
    }, TYPE);
  }
  next();
})();
