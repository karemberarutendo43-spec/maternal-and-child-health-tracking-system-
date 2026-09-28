<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
mch_session_start();

// Already signed in? Skip the landing page and go straight to the right portal.
if (!empty($_SESSION['user_id']) && !empty($_SESSION['role'])) {
    switch ((string)$_SESSION['role']) {
        case 'mother':
            header('Location: /mch_system/mother_portal/dashboard.php');
            exit;
        case 'nurse':
            header('Location: /mch_system/nurse_portal/dashboard.php');
            exit;
        case 'health_info':
            header('Location: /mch_system/health_info_portal/dashboard.php');
            exit;
        case 'admin':
            header('Location: /mch_system/admin_portal/dashboard.php');
            exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Maternal & Child Health Tracking System</title>
<meta name="description" content="A secure, role-based platform for tracking antenatal care, immunizations, growth and postnatal visits — built for mothers, nurses and clinics.">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#050a14">
<link rel="icon" href="/mch_system/public/assets/mchts-icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/mch_system/assets/output.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root {
    --mch-primary: #0ea5e9;
    --mch-accent: #22d3ee;
    --mch-ink: #0f172a;
    --mch-muted: #94a3b8;
    --mch-white: #e2e8f0;
  }
  html { scroll-behavior: smooth; }
  html, body {
    font-family: 'Plus Jakarta Sans', system-ui, -apple-system, Segoe UI, sans-serif;
    color: var(--mch-white);
    background: #060b17;
  }

  .mch-bg {
    position: relative;
    overflow-x: clip;
    background:
      radial-gradient(1200px 800px at 8% -10%, rgba(34, 211, 238, 0.16) 0%, transparent 60%),
      radial-gradient(900px 620px at 108% 4%, rgba(14, 165, 233, 0.16) 0%, transparent 55%),
      radial-gradient(1000px 700px at 45% 60%, rgba(167, 139, 250, 0.10) 0%, transparent 55%),
      linear-gradient(180deg, #050a14 0%, #08122a 35%, #060b17 70%, #050a14 100%);
  }
  .mch-bg::before, .mch-bg::after {
    content: '';
    position: absolute;
    inset: -20%;
    pointer-events: none;
    z-index: 0;
    filter: blur(60px);
    opacity: 0.55;
  }
  .mch-bg::before {
    background: radial-gradient(closest-side, rgba(34, 211, 238, 0.18), transparent 70%);
    animation: mch-drift-1 16s ease-in-out infinite;
  }
  .mch-bg::after {
    background: radial-gradient(closest-side, rgba(167, 139, 250, 0.14), transparent 70%);
    animation: mch-drift-2 20s ease-in-out infinite;
  }
  @keyframes mch-drift-1 { 0%,100% { transform: translate(-4%,-2%) scale(1); } 50% { transform: translate(4%,3%) scale(1.06); } }
  @keyframes mch-drift-2 { 0%,100% { transform: translate(3%,-3%) scale(1.02); } 50% { transform: translate(-4%,4%) scale(1.08); } }
  @media (prefers-reduced-motion: reduce) { .mch-bg::before, .mch-bg::after { animation: none; } }

  .mch-nav {
    background: rgba(6, 11, 23, 0.55);
    border-bottom: 1px solid rgba(148, 163, 184, 0.12);
    backdrop-filter: blur(14px);
  }

  .mch-pill {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    border: 1px solid rgba(34, 211, 238, 0.30);
    background: rgba(34, 211, 238, 0.08);
    color: rgba(224, 242, 254, 0.92);
    border-radius: 999px;
    padding: .4rem .9rem;
    font-size: .75rem;
    font-weight: 600;
    letter-spacing: .02em;
  }
  .mch-pill span.dot { width: .4rem; height: .4rem; border-radius: 999px; background: var(--mch-accent); box-shadow: 0 0 10px rgba(34,211,238,.8); }

  .mch-btn {
    background: linear-gradient(135deg, rgba(14, 165, 233, 0.95), rgba(34, 211, 238, 0.92));
    color: #03111d;
    border-radius: 14px;
    font-weight: 700;
    transition: transform .15s ease, box-shadow .15s ease, filter .15s ease;
    box-shadow: 0 12px 28px rgba(14, 165, 233, 0.22);
  }
  .mch-btn:hover { transform: translateY(-2px); box-shadow: 0 18px 36px rgba(34, 211, 238, 0.32); filter: saturate(1.08); }
  .mch-btn:active { transform: translateY(0); box-shadow: 0 8px 18px rgba(14,165,233,.24); }

  .mch-btn-outline {
    background: rgba(226, 232, 240, 0.04);
    border: 1px solid rgba(148, 163, 184, 0.35);
    color: var(--mch-white);
    border-radius: 14px;
    font-weight: 600;
    transition: transform .15s ease, background-color .15s ease, border-color .15s ease;
  }
  .mch-btn-outline:hover { background: rgba(34, 211, 238, 0.10); border-color: rgba(34, 211, 238, 0.45); transform: translateY(-2px); }
  .mch-btn-outline:active { transform: translateY(0); }

  .mch-card {
    background: rgba(11, 18, 32, 0.55);
    border: 1px solid rgba(148, 163, 184, 0.14);
    border-radius: 20px;
    backdrop-filter: blur(14px);
    transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
  }
  .mch-card:hover {
    transform: translateY(-4px);
    border-color: rgba(34, 211, 238, 0.35);
    box-shadow: 0 20px 45px rgba(2, 6, 23, 0.55);
  }

  .mch-icon-badge {
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 12px;
    display: grid;
    place-items: center;
    background: linear-gradient(135deg, rgba(14,165,233,.20), rgba(167,139,250,.18));
    border: 1px solid rgba(34, 211, 238, 0.25);
  }

  .mch-hero-frame {
    position: relative;
    border-radius: 28px;
    padding: 10px;
    background: linear-gradient(160deg, rgba(34,211,238,.35), rgba(167,139,250,.20) 45%, rgba(14,165,233,.30));
    box-shadow: 0 30px 80px rgba(2, 6, 23, .55);
  }
  .mch-hero-frame img {
    border-radius: 20px;
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .mch-float-card {
    position: absolute;
    background: rgba(8, 13, 26, 0.78);
    border: 1px solid rgba(148, 163, 184, 0.20);
    border-radius: 16px;
    backdrop-filter: blur(16px);
    box-shadow: 0 18px 40px rgba(2,6,23,.5);
  }

  .mch-portal-card { position: relative; overflow: hidden; border-radius: 20px; }
  .mch-portal-card img {
    position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover;
    filter: saturate(0.9) brightness(0.55);
    transition: transform .5s ease, filter .3s ease;
  }
  .mch-portal-card:hover img { transform: scale(1.06); filter: saturate(1) brightness(0.45); }
  .mch-portal-card .mch-portal-overlay {
    position: absolute; inset: 0;
    background: linear-gradient(180deg, rgba(5,10,20,0.15) 0%, rgba(5,10,20,0.55) 55%, rgba(5,10,20,0.92) 100%);
  }

  .mch-link-arrow { display: inline-flex; align-items: center; gap: .35rem; transition: gap .15s ease; }
  .mch-link-arrow:hover { gap: .6rem; }

  ::selection { background: rgba(34, 211, 238, .35); color: #03111d; }
</style>
</head>
<body class="mch-bg min-h-screen">

  <!-- Nav -->
  <header class="mch-nav sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-5 md:px-8 h-16 flex items-center justify-between">
      <a href="/mch_system/" class="flex items-center gap-2.5">
        <img src="/mch_system/public/assets/mchts-icon.svg" alt="MCHTS" class="w-8 h-8 rounded-lg">
        <span class="font-extrabold tracking-tight text-slate-100">MCHTS</span>
      </a>
      <nav class="hidden md:flex items-center gap-8 text-sm text-slate-300/85">
        <a href="#features" class="hover:text-cyan-300 transition-colors">Features</a>
        <a href="#portals" class="hover:text-cyan-300 transition-colors">Portals</a>
        <a href="/mch_system/help/user_manual.php" class="hover:text-cyan-300 transition-colors">Help</a>
      </nav>
      <div class="flex items-center gap-3">
        <a href="/mch_system/auth/login.php" class="mch-btn-outline px-4 py-2 text-sm">Sign In</a>
        <a href="/mch_system/auth/mother_signup.php" class="mch-btn px-4 py-2 text-sm hidden sm:inline-flex">Get Started</a>
      </div>
    </div>
  </header>

  <!-- Hero -->
  <section class="relative z-10 max-w-7xl mx-auto px-5 md:px-8 pt-14 md:pt-20 pb-20">
    <div class="grid lg:grid-cols-2 gap-14 items-center">

      <div>
        <div class="mch-pill mb-6"><span class="dot"></span> Antenatal &middot; Immunization &middot; Growth Tracking</div>
        <h1 class="text-4xl sm:text-5xl lg:text-[3.4rem] font-extrabold leading-[1.08] tracking-tight text-slate-50">
          Every mother.<br>
          Every child.<br>
          <span class="bg-clip-text text-transparent bg-gradient-to-r from-cyan-300 to-sky-400">Tracked with care.</span>
        </h1>
        <p class="mt-6 text-slate-300/85 text-lg leading-relaxed max-w-xl">
          One secure system for antenatal visits, immunizations, growth monitoring and postnatal care —
          built so mothers, nurses and clinic staff always see the same up-to-date record.
        </p>

        <div class="mt-8 flex flex-wrap items-center gap-4">
          <a href="/mch_system/auth/mother_signup.php" class="mch-btn px-6 py-3.5 text-[15px]">Get Started as a Mother</a>
          <a href="/mch_system/auth/login.php" class="mch-btn-outline px-6 py-3.5 text-[15px]">Staff &amp; Clinic Login</a>
        </div>

        <div class="mt-10 grid grid-cols-3 gap-5 max-w-md">
          <div>
            <div class="text-2xl font-extrabold text-slate-50">4</div>
            <div class="text-xs text-slate-400 mt-0.5">Dedicated portals</div>
          </div>
          <div>
            <div class="text-2xl font-extrabold text-slate-50">24/7</div>
            <div class="text-xs text-slate-400 mt-0.5">Access to records</div>
          </div>
          <div>
            <div class="text-2xl font-extrabold text-slate-50">Real&#8209;time</div>
            <div class="text-xs text-slate-400 mt-0.5">ANC &amp; visit reminders</div>
          </div>
        </div>
      </div>

      <div class="relative mx-auto w-full max-w-md">
        <div class="mch-hero-frame aspect-[4/5]">
          <img src="/mch_system/assets/images/mother_baby.jpg" alt="Mother holding her baby">
        </div>

        <div class="mch-float-card -left-6 top-8 px-4 py-3 hidden sm:flex items-center gap-3">
          <div class="mch-icon-badge">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-cyan-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
            </svg>
          </div>
          <div>
            <div class="text-sm font-semibold text-slate-100">ANC visit due</div>
            <div class="text-xs text-slate-400">Reminder sent to clinic</div>
          </div>
        </div>

        <div class="mch-float-card -right-5 -bottom-6 px-4 py-3 flex items-center gap-3">
          <div class="mch-icon-badge">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-cyan-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
            </svg>
          </div>
          <div>
            <div class="text-sm font-semibold text-slate-100">Growth on track</div>
            <div class="text-xs text-slate-400">Weight &amp; height logged</div>
          </div>
        </div>
      </div>

    </div>
  </section>

  <!-- Features -->
  <section id="features" class="relative z-10 max-w-7xl mx-auto px-5 md:px-8 py-16 md:py-20">
    <div class="max-w-2xl mb-12">
      <div class="mch-pill mb-4"><span class="dot"></span> Features</div>
      <h2 class="text-3xl sm:text-4xl font-extrabold tracking-tight text-slate-50">Everything a clinic needs, in one record</h2>
      <p class="mt-3 text-slate-300/80">From the first antenatal visit to a child's full immunization schedule — nothing gets lost between departments.</p>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">

      <div class="mch-card p-6">
        <div class="mch-icon-badge mb-4">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-cyan-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M4 11h16M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z"/></svg>
        </div>
        <h3 class="font-bold text-slate-100">Antenatal Care (ANC)</h3>
        <p class="mt-1.5 text-sm text-slate-400 leading-relaxed">Schedule and record every antenatal visit, with automatic reminders for upcoming check-ups.</p>
      </div>

      <div class="mch-card p-6">
        <div class="mch-icon-badge mb-4">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-cyan-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
        </div>
        <h3 class="font-bold text-slate-100">Immunization Schedules</h3>
        <p class="mt-1.5 text-sm text-slate-400 leading-relaxed">Track every vaccine dose against the national schedule, with alerts for missed or upcoming doses.</p>
      </div>

      <div class="mch-card p-6">
        <div class="mch-icon-badge mb-4">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-cyan-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 15l4-5 3 3 5-7"/></svg>
        </div>
        <h3 class="font-bold text-slate-100">Growth &amp; Nutrition</h3>
        <p class="mt-1.5 text-sm text-slate-400 leading-relaxed">Log weight, height and nutrition status at every visit, and see a child's growth trend at a glance.</p>
      </div>

      <div class="mch-card p-6">
        <div class="mch-icon-badge mb-4">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-cyan-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21c-4.97-3.36-8-6.94-8-10.5A5.5 5.5 0 0 1 12 6a5.5 5.5 0 0 1 8 4.5c0 3.56-3.03 7.14-8 10.5Z"/></svg>
        </div>
        <h3 class="font-bold text-slate-100">Delivery &amp; Postnatal</h3>
        <p class="mt-1.5 text-sm text-slate-400 leading-relaxed">Record delivery outcomes and follow up on mother and newborn through the postnatal period.</p>
      </div>

      <div class="mch-card p-6">
        <div class="mch-icon-badge mb-4">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-cyan-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75M3.75 21h16.5a1.5 1.5 0 0 0 1.5-1.5v-7.5a1.5 1.5 0 0 0-1.5-1.5H3.75a1.5 1.5 0 0 0-1.5 1.5v7.5a1.5 1.5 0 0 0 1.5 1.5Z"/></svg>
        </div>
        <h3 class="font-bold text-slate-100">Role-Based Access</h3>
        <p class="mt-1.5 text-sm text-slate-400 leading-relaxed">Separate, secure portals for mothers, nurses, administrators and health information staff.</p>
      </div>

      <div class="mch-card p-6">
        <div class="mch-icon-badge mb-4">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-cyan-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.5 8.5 8l3.5 3.5L21 3m0 0h-5.5M21 3v5.5M3 21h18"/></svg>
        </div>
        <h3 class="font-bold text-slate-100">Reports &amp; Analytics</h3>
        <p class="mt-1.5 text-sm text-slate-400 leading-relaxed">Clinics get a clear, real-time view of coverage, attendance and outcomes across every register.</p>
      </div>

    </div>
  </section>

  <!-- Portals -->
  <section id="portals" class="relative z-10 max-w-7xl mx-auto px-5 md:px-8 py-16 md:py-20">
    <div class="max-w-2xl mb-12">
      <div class="mch-pill mb-4"><span class="dot"></span> Portals</div>
      <h2 class="text-3xl sm:text-4xl font-extrabold tracking-tight text-slate-50">One system, the right view for everyone</h2>
      <p class="mt-3 text-slate-300/80">Choose your portal to sign in — each role sees exactly what it needs, nothing more.</p>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-5">

      <a href="/mch_system/auth/login.php" class="mch-portal-card mch-card group block h-72">
        <img src="/mch_system/assets/images/mother1.jpg" alt="">
        <div class="mch-portal-overlay"></div>
        <div class="relative z-10 h-full flex flex-col justify-end p-5">
          <h3 class="font-bold text-slate-50">Mother Portal</h3>
          <p class="text-xs text-slate-300/85 mt-1">View visits, immunizations &amp; growth records.</p>
          <span class="mch-link-arrow mt-3 text-sm font-semibold text-cyan-300">Sign in <span>&rarr;</span></span>
        </div>
      </a>

      <a href="/mch_system/auth/login.php" class="mch-portal-card mch-card group block h-72">
        <img src="/mch_system/assets/images/nurse1.jpg" alt="">
        <div class="mch-portal-overlay"></div>
        <div class="relative z-10 h-full flex flex-col justify-end p-5">
          <h3 class="font-bold text-slate-50">Nurse Portal</h3>
          <p class="text-xs text-slate-300/85 mt-1">Record visits, vaccines &amp; growth at the clinic.</p>
          <span class="mch-link-arrow mt-3 text-sm font-semibold text-cyan-300">Sign in <span>&rarr;</span></span>
        </div>
      </a>

      <a href="/mch_system/auth/login.php" class="mch-portal-card mch-card group block h-72">
        <img src="/mch_system/assets/images/admin1.jpg" alt="">
        <div class="mch-portal-overlay"></div>
        <div class="relative z-10 h-full flex flex-col justify-end p-5">
          <h3 class="font-bold text-slate-50">Admin Portal</h3>
          <p class="text-xs text-slate-300/85 mt-1">Manage users, clinics &amp; system-wide settings.</p>
          <span class="mch-link-arrow mt-3 text-sm font-semibold text-cyan-300">Sign in <span>&rarr;</span></span>
        </div>
      </a>

      <a href="/mch_system/auth/login.php" class="mch-portal-card mch-card group block h-72">
        <img src="/mch_system/assets/images/login.jpg" alt="">
        <div class="mch-portal-overlay"></div>
        <div class="relative z-10 h-full flex flex-col justify-end p-5">
          <h3 class="font-bold text-slate-50">Health Info Portal</h3>
          <p class="text-xs text-slate-300/85 mt-1">Coverage reports &amp; district-level analytics.</p>
          <span class="mch-link-arrow mt-3 text-sm font-semibold text-cyan-300">Sign in <span>&rarr;</span></span>
        </div>
      </a>

    </div>
  </section>

  <!-- CTA banner -->
  <section class="relative z-10 max-w-7xl mx-auto px-5 md:px-8 pb-20">
    <div class="mch-card p-10 md:p-14 flex flex-col md:flex-row items-center justify-between gap-8">
      <div class="max-w-xl text-center md:text-left">
        <h2 class="text-2xl sm:text-3xl font-extrabold text-slate-50">Ready to get started?</h2>
        <p class="mt-2 text-slate-300/80">Mothers can register in minutes. Clinic staff sign in with the account issued by their facility.</p>
      </div>
      <div class="flex flex-wrap items-center justify-center gap-4">
        <a href="/mch_system/auth/mother_signup.php" class="mch-btn px-6 py-3.5 text-[15px]">Get Started</a>
        <a href="/mch_system/auth/login.php" class="mch-btn-outline px-6 py-3.5 text-[15px]">Sign In</a>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer class="relative z-10 border-t border-slate-800/60">
    <div class="max-w-7xl mx-auto px-5 md:px-8 py-8 flex flex-col sm:flex-row items-center justify-between gap-4">
      <div class="flex items-center gap-2.5">
        <img src="/mch_system/public/assets/mchts-icon.svg" alt="MCHTS" class="w-6 h-6 rounded-md">
        <span class="text-sm font-semibold text-slate-200">Maternal &amp; Child Health Tracking System</span>
      </div>
      <div class="flex items-center gap-6 text-sm text-slate-400">
        <a href="/mch_system/help/user_manual.php" class="hover:text-cyan-300 transition-colors">Help</a>
        <a href="/mch_system/auth/login.php" class="hover:text-cyan-300 transition-colors">Sign In</a>
        <span>&copy; <?= date('Y') ?> District Hospital &ndash; MCHTS</span>
      </div>
    </div>
  </footer>

</body>
</html>
