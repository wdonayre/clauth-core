// Clauth hosted sign-in pages — small enhancements. Every page works without this file.
(() => {
  // 6-digit code boxes: one real input ([data-otp], the one the form submits) drives six visible boxes.
  document.querySelectorAll('input[data-otp]').forEach((src) => {
    const n = parseInt(src.dataset.otp, 10) || 6;
    const wrap = document.createElement('div');
    wrap.className = 'otp';
    wrap.setAttribute('role', 'group');
    wrap.setAttribute('aria-label', src.dataset.otpLabel || 'Code');
    const boxes = [];
    for (let i = 0; i < n; i++) {
      const b = document.createElement('input');
      b.type = 'text';
      b.inputMode = 'numeric';
      b.maxLength = 1;
      b.autocomplete = i === 0 ? 'one-time-code' : 'off';
      b.setAttribute('aria-label', (src.dataset.otpDigit || 'Digit') + ' ' + (i + 1));
      boxes.push(b);
      wrap.appendChild(b);
    }
    const sync = () => { src.value = boxes.map((b) => b.value).join(''); };
    const fill = (from, text) => {
      const digits = text.replace(/\D/g, '').split('');
      let i = from;
      digits.forEach((d) => { if (i < n) boxes[i++].value = d; });
      sync();
      boxes[Math.min(i, n - 1)].focus();
    };
    boxes.forEach((b, i) => {
      b.addEventListener('input', () => {
        const v = b.value.replace(/\D/g, '');
        if (v.length > 1) { b.value = ''; fill(i, v); return; }
        b.value = v;
        sync();
        if (v && i < n - 1) boxes[i + 1].focus();
        if (v && i === n - 1 && src.value.length === n && src.form && src.dataset.otpAutosubmit !== undefined) src.form.requestSubmit();
      });
      b.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !b.value && i > 0) { boxes[i - 1].value = ''; boxes[i - 1].focus(); sync(); e.preventDefault(); }
        if (e.key === 'ArrowLeft' && i > 0) boxes[i - 1].focus();
        if (e.key === 'ArrowRight' && i < n - 1) boxes[i + 1].focus();
      });
      b.addEventListener('paste', (e) => {
        const t = (e.clipboardData || window.clipboardData).getData('text');
        if (t) { e.preventDefault(); fill(i, t); }
      });
    });
    if (src.value) fill(0, src.value);
    src.classList.add('otp-src', 'enhanced');
    src.tabIndex = -1;
    src.setAttribute('aria-hidden', 'true');
    src.removeAttribute('autofocus');
    src.removeAttribute('id');
    src.after(wrap);
    const lbl = src.dataset.otpFor && document.getElementById(src.dataset.otpFor);
    if (lbl) lbl.addEventListener('click', () => boxes[0].focus());
    if (src.hasAttribute('data-otp-focus')) boxes[0].focus();
  });

  // Password meter colour (clauth.js sets the width) and the live "At least N characters" rule.
  document.querySelectorAll('input[data-meter]').forEach((input) => {
    const bar = document.querySelector(input.dataset.meter);
    const meter = bar && bar.parentElement;
    const rule = input.dataset.minRule && document.querySelector(input.dataset.minRule);
    const min = parseInt(input.dataset.min || '0', 10);
    input.addEventListener('input', () => {
      const v = input.value;
      let s = Math.min(v.length / 16, 1) * 60;
      s += [/[a-z]/, /[A-Z]/, /\d/, /[^\w\s]/, /\s/].filter((r) => r.test(v)).length * 8;
      if (meter) meter.dataset.level = v ? String(Math.max(1, Math.min(4, Math.ceil(Math.min(s, 100) / 25)))) : '0';
      if (rule) rule.classList.toggle('ok', v.length >= min);
    });
  });

  // Recovery codes: Print.
  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-print]')) { e.preventDefault(); window.print(); }
  });

  // Locked page: count down the person's real time left (the server sends the seconds; a reload gets a fresh number).
  const timer = document.querySelector('[data-countdown-seconds]');
  if (timer) {
    const until = Date.now() + (parseInt(timer.dataset.countdownSeconds, 10) || 0) * 1000;
    const done = timer.dataset.done && document.querySelector(timer.dataset.done);
    const wait = document.getElementById('timer-wait');
    const tick = () => {
      const left = Math.max(0, Math.round((until - Date.now()) / 1000));
      timer.textContent = Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0');
      if (left > 0) { setTimeout(tick, 1000); return; }
      if (done) done.hidden = false;
      if (wait) wait.hidden = true;
    };
    tick();
  }
})();
