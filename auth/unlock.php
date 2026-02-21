<?php
// Deprecated: old entry screen. Redirect directly to login so this URL doesn't appear to users.
header("Location: /mch_system/auth/login.php");
exit;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0f172a">
  <title>MCHTS</title>
  <script>document.documentElement.classList.add('js');</script>
  <style>
    :root {
      --bg0: #050a14;
      --bg1: #08122a;
      --c1: rgba(34, 211, 238, 0.95);
      --c2: rgba(14, 165, 233, 0.85);
      --c3: rgba(167, 139, 250, 0.55);
      --ink: #e2e8f0;
      --muted: rgba(226, 232, 240, 0.78);
    }

    html, body {
      height: 100%;
      margin: 0;
      font-family: system-ui, -apple-system, Segoe UI, sans-serif;
      background: var(--bg0);
      color: var(--ink);
    }

    body {
      background:
        radial-gradient(1100px 700px at 10% -10%, rgba(34, 211, 238, 0.20) 0%, transparent 60%),
        radial-gradient(900px 600px at 110% 0%, rgba(14, 165, 233, 0.18) 0%, transparent 55%),
        radial-gradient(900px 700px at 40% 120%, rgba(167, 139, 250, 0.12) 0%, transparent 50%),
        linear-gradient(180deg, var(--bg0) 0%, var(--bg1) 45%, var(--bg0) 100%);
      overflow: hidden;
    }

    .glow {
      position: fixed;
      inset: -40%;
      pointer-events: none;
      filter: blur(44px);
      opacity: 0.8;
      transform: translate3d(0,0,0);
      z-index: 0;
    }
    .glow.one {
      background:
        radial-gradient(closest-side, rgba(34, 211, 238, 0.22), transparent 70%),
        radial-gradient(closest-side, rgba(14, 165, 233, 0.20), transparent 72%);
      animation: drift1 10s ease-in-out infinite;
    }
    .glow.two {
      background:
        radial-gradient(closest-side, rgba(56, 189, 248, 0.18), transparent 68%),
        radial-gradient(closest-side, rgba(167, 139, 250, 0.14), transparent 70%);
      animation: drift2 14s ease-in-out infinite;
    }

    @keyframes drift1 {
      0%, 100% { transform: translate(-6%, -4%) scale(1.02); }
      50% { transform: translate(6%, 4%) scale(1.08); }
    }
    @keyframes drift2 {
      0%, 100% { transform: translate(8%, -2%) scale(1.04); }
      50% { transform: translate(-8%, 6%) scale(1.10); }
    }

    @media (prefers-reduced-motion: reduce) {
      .glow { animation: none !important; }
      .stage, .key, .lock { transition: none !important; }
      .skip { opacity: 1 !important; transform: none !important; pointer-events: auto !important; }
    }

    .wrap {
      position: relative;
      z-index: 1;
      min-height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      box-sizing: border-box;
    }

    .stage {
      width: min(520px, 92vw);
      border-radius: 28px;
      background: rgba(11, 18, 32, 0.62);
      border: 1px solid rgba(148, 163, 184, 0.20);
      box-shadow:
        0 26px 70px rgba(2, 6, 23, 0.70),
        inset 0 1px 0 rgba(255, 255, 255, 0.06);
      backdrop-filter: blur(18px);
      padding: 26px 22px;
      text-align: center;
      position: relative;
      overflow: hidden;
    }

    .title {
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      font-size: 12px;
      color: rgba(226, 232, 240, 0.84);
      margin-bottom: 10px;
    }

    .hint {
      color: var(--muted);
      font-size: 13px;
      margin-top: 10px;
      margin-bottom: 18px;
    }

    .scene {
      display: grid;
      place-items: center;
      padding: 14px 0 10px;
    }

    .lock-area {
      position: relative;
      width: 260px;
      height: 160px;
    }

    .key {
      position: absolute;
      left: -12px;
      top: 88px;
      width: 108px;
      height: 40px;
      transform-origin: 24px 20px;
      transform: translateX(0) rotate(0deg);
      transition: transform 0.9s cubic-bezier(.2,.8,.2,1), opacity 0.6s ease;
      opacity: 1;
      filter: drop-shadow(0 0 18px rgba(34, 211, 238, 0.30));
    }

    .lock {
      position: absolute;
      left: 92px;
      top: 14px;
      width: 140px;
      height: 140px;
      filter: drop-shadow(0 0 22px rgba(14, 165, 233, 0.22));
    }

    .lock-body {
      fill: rgba(2, 6, 23, 0.18);
      stroke: rgba(34, 211, 238, 0.70);
      stroke-width: 2.5;
    }

    .lock-hole {
      fill: rgba(226, 232, 240, 0.85);
      opacity: 0.75;
    }

    .lock-shackle {
      fill: none;
      stroke: rgba(34, 211, 238, 0.85);
      stroke-width: 9;
      stroke-linecap: round;
      transform-origin: 46px 54px;
      transition: transform 0.55s cubic-bezier(.2,.8,.2,1);
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      padding: 12px 16px;
      border-radius: 14px;
      border: 1px solid rgba(148, 163, 184, 0.22);
      background: linear-gradient(135deg, rgba(14, 165, 233, 0.95), rgba(34, 211, 238, 0.92));
      color: #03111d;
      font-weight: 800;
      cursor: pointer;
      user-select: none;
      box-shadow: 0 12px 28px rgba(14, 165, 233, 0.22);
      transition: transform 0.12s ease, box-shadow 0.12s ease, filter 0.12s ease;
    }
    .btn:hover { transform: translateY(-1px); box-shadow: 0 18px 36px rgba(34, 211, 238, 0.28); filter: saturate(1.05); }
    .btn:active { transform: translateY(0px); }

    .skip {
      display: block;
      margin-top: 14px;
      font-size: 12px;
      color: rgba(226, 232, 240, 0.72);
      text-decoration: none;
    }
    .skip:hover { text-decoration: underline; }

    html.js .skip {
      opacity: 0;
      transform: translateY(-2px);
      pointer-events: none;
      transition: opacity 0.18s ease, transform 0.18s ease;
    }

    html.js .skip-ready .skip {
      opacity: 1;
      transform: translateY(0);
      pointer-events: auto;
    }

    /* 3-step unlock: slide -> turn -> open */
    .stage.step-1 .key {
      transform: translate(140px, -10px) rotate(0deg);
      transition-duration: 0.55s;
    }
    .stage.step-2 .key {
      transform: translate(140px, -10px) rotate(84deg);
      transition-duration: 0.34s;
    }
    .stage.step-3 .key {
      transform: translate(148px, -10px) rotate(84deg);
      opacity: 0;
      transition: transform 0.22s ease, opacity 0.22s ease;
    }
    .stage.step-3 .lock-shackle {
      transform: rotate(-36deg) translate(-4px, -10px);
    }
    .fade-out {
      animation: fadeOut 0.38s ease forwards;
    }
    @keyframes fadeOut {
      to { opacity: 0; transform: scale(0.985); }
    }
  </style>
</head>
<body>
  <div class="glow one" aria-hidden="true"></div>
  <div class="glow two" aria-hidden="true"></div>

  <div class="wrap">
    <div class="stage" id="stage">
      <div class="title">MCHTS</div>
      <div class="hint">Tap to unlock</div>

      <div class="scene" aria-hidden="true">
        <div class="lock-area">
          <svg class="key" viewBox="0 0 120 44" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M38 22a14 14 0 1 1-28 0 14 14 0 0 1 28 0Z" stroke="rgba(34,211,238,0.9)" stroke-width="3"/>
            <circle cx="24" cy="22" r="4.5" fill="rgba(226,232,240,0.85)"/>
            <path d="M37 22h72c2.5 0 4.5 2 4.5 4.5S111.5 31 109 31h-10v5h-10v-5H79v5H69v-5H37" stroke="rgba(34,211,238,0.85)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>

          <svg class="lock" viewBox="0 0 180 180" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path class="lock-shackle" d="M60 78V54c0-22 16-38 30-38s30 16 30 38v24" />
            <rect x="45" y="78" width="90" height="78" rx="18" class="lock-body"/>
            <path class="lock-hole" d="M90 112c-8.5 0-15.5 7-15.5 15.5 0 6.1 3.7 11.4 9 13.8V152h13v-10.7c5.3-2.4 9-7.7 9-13.8C105.5 119 98.5 112 90 112Z"/>
          </svg>
        </div>
      </div>

      <button class="btn" id="unlockBtn" type="button">Unlock</button>
      <a class="skip" href="/mch_system/auth/login.php" id="skipLink">Skip</a>

      <noscript>
        <p style="margin-top:12px;color:rgba(226,232,240,0.78);font-size:12px">
          JavaScript is required for the unlock animation. Continue to login.
        </p>
      </noscript>
    </div>
  </div>

  <script>
    (function () {
      const DEFAULT_NEXT = '/mch_system/auth/login.php';
      const key = 'mch_unlocked';
      const stage = document.getElementById('stage');
      const btn = document.getElementById('unlockBtn');
      const skip = document.getElementById('skipLink');

      function getParam(name) {
        try {
          const v = new URLSearchParams(window.location.search).get(name);
          return v === null ? null : v;
        } catch (e) {
          return null;
        }
      }

      function safeNext(raw) {
        if (!raw) return DEFAULT_NEXT;
        const s = String(raw).trim();
        if (!s) return DEFAULT_NEXT;

        // Only allow same-app paths to avoid open redirect.
        // Accept absolute paths like "/mch_system/..." or relative paths like "auth/login.php".
        if (s.startsWith('/mch_system/')) return s;
        if (s.startsWith('auth/')) return '/mch_system/' + s;
        if (s === 'login.php' || s.endsWith('/login.php')) return DEFAULT_NEXT;

        return DEFAULT_NEXT;
      }

      const NEXT = safeNext(getParam('next'));
      const FORCE = getParam('force') === '1' || getParam('force') === 'true';

      function goNext() {
        try { window.location.replace(NEXT); } catch (e) { window.location.href = NEXT; }
      }

      try {
        if (FORCE) {
          sessionStorage.removeItem(key);
        }
        if (!FORCE && sessionStorage.getItem(key) === '1') {
          goNext();
          return;
        }
      } catch (e) {}

      try {
        if (skip) skip.setAttribute('href', NEXT);
      } catch (e) {}

      let started = false;
      function start() {
        if (started) return;
        started = true;

        try { sessionStorage.setItem(key, '1'); } catch (e) {}

        // Step 1: slide into the keyhole
        stage.classList.add('step-1');

        // Step 2: turn the key (small pause feels more "physical")
        window.setTimeout(() => {
          stage.classList.add('step-2');
        }, 520);

        // Step 3: shackle pops open + key fades
        window.setTimeout(() => {
          stage.classList.add('step-3');
          try {
            if (navigator.vibrate) navigator.vibrate(40);
          } catch (e) {}
        }, 860);

        window.setTimeout(() => {
          stage.classList.add('fade-out');
        }, 980);

        window.setTimeout(goNext, 1320);
      }

      // Reveal Skip after a short moment so the primary action is clear.
      window.setTimeout(() => {
        if (!started) stage && stage.classList.add('skip-ready');
      }, 500);

      btn && btn.addEventListener('click', start);
      stage && stage.addEventListener('click', (e) => {
        const t = e.target;
        if (t === skip) return;
        if (t === btn) return;
        start();
      });
      skip && skip.addEventListener('click', () => {
        try { sessionStorage.setItem(key, '1'); } catch (e) {}
      });
    })();
  </script>
</body>
</html>
