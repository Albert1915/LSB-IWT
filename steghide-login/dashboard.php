<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['uid'])) { redirect('login.php'); }

$email = $_SESSION['email'] ?? 'User';
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Dashboard | StegApp</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <style>
    :root{
      --bg1:#12002a;
      --bg2:#070013;

      --card: rgba(18, 20, 34, .78);
      --border: rgba(255,255,255,.10);

      --text:#eef1ff;
      --muted:#a9b1c7;

      --surface: rgba(8,10,18,.45);
      --surface2: rgba(8,10,18,.30);
      --surfaceBorder: rgba(255,255,255,.10);

      --focus: rgba(167,108,255,.35);

      --primary1:#7c3aed; /* ungu */
      --primary2:#3b82f6; /* biru */

      --badgeBg: rgba(124,58,237,.20);
      --badgeBorder: rgba(167,108,255,.35);
      --badgeText: #e9dbff;
    }

    *{ box-sizing:border-box; }
    html,body{ height:100%; }

    body{
      margin:0;
      font-family: system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,Arial,sans-serif;
      color: var(--text);
      display:flex;
      align-items:center;
      justify-content:center;
      padding: 28px 16px;

      background:
        radial-gradient(900px 500px at 20% 20%, rgba(124,58,237,.35), transparent 60%),
        radial-gradient(800px 480px at 85% 30%, rgba(59,130,246,.20), transparent 60%),
        radial-gradient(900px 520px at 50% 110%, rgba(124,58,237,.18), transparent 60%),
        linear-gradient(180deg, var(--bg1), var(--bg2));
    }

    body::before{
      content:"";
      position:fixed; inset:0;
      pointer-events:none;
      background:
        radial-gradient(600px 220px at 50% 0%, rgba(255,255,255,.06), transparent 70%),
        radial-gradient(900px 600px at 50% 100%, rgba(0,0,0,.35), transparent 60%);
      opacity:.9;
    }

    .wrap{ width: min(92vw, 900px); position:relative; }

    .panel{
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 26px 24px;
      box-shadow: 0 18px 60px rgba(0,0,0,.45);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }

    .top{
      display:flex;
      align-items:flex-start;
      justify-content:space-between;
      gap: 14px;
      flex-wrap: wrap;
      margin-bottom: 14px;
    }

    .title{
      margin:0;
      font-size: 1.35rem;
      letter-spacing: .2px;
      line-height: 1.25;
    }

    .subtitle{
      margin:6px 0 0;
      color: var(--muted);
      font-size: .95rem;
      line-height: 1.35;
    }

    .chip{
      max-width: 100%;
      padding: 8px 10px;
      border-radius: 999px;
      border: 1px solid var(--surfaceBorder);
      background: var(--surface);
      color: rgba(238,241,255,.92);
      font-size: .9rem;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .grid{
      margin-top: 16px;
      display:grid;
      grid-template-columns: repeat(2, minmax(0,1fr));
      gap: 12px;
    }

    .module{
      position: relative;
      display:flex;
      gap: 14px;
      align-items:flex-start;
      text-decoration:none;
      padding: 16px;
      border-radius: 16px;
      border: 1px solid var(--surfaceBorder);
      background: rgba(8,10,18,.35);
      box-shadow: 0 10px 24px rgba(0,0,0,.25);
      transition: transform .10s ease, filter .15s ease, border-color .15s ease, box-shadow .15s ease;
      outline: none;
      overflow: hidden;
    }

    .module::after{
      content:"";
      position:absolute;
      inset:-1px;
      background: radial-gradient(400px 120px at 25% 10%, rgba(124,58,237,.20), transparent 60%);
      opacity:.9;
      pointer-events:none;
    }

    .module:hover{
      filter: brightness(1.05);
      transform: translateY(-1px);
      border-color: rgba(167,108,255,.35);
      box-shadow: 0 16px 40px rgba(0,0,0,.35);
    }

    .module:focus-visible{
      box-shadow: 0 0 0 4px var(--focus), 0 16px 40px rgba(0,0,0,.35);
    }

    .icon{
      position:relative;
      z-index:1;
      flex: 0 0 auto;
      width: 46px;
      height: 46px;
      border-radius: 14px;
      display:grid;
      place-items:center;
      background: linear-gradient(90deg, rgba(124,58,237,.85), rgba(59,130,246,.85));
      box-shadow: 0 12px 26px rgba(124,58,237,.25);
    }
    .icon svg{ width: 24px; height: 24px; fill: #0b0b14; }

    .content{
      position:relative;
      z-index:1;
      min-width: 0;
    }

    .module h3{
      margin: 0;
      font-size: 1.05rem;
      letter-spacing: .2px;
      display:flex;
      align-items:center;
      gap: 10px;
      flex-wrap: wrap;
    }
    .module p{
      margin: 6px 0 0;
      color: var(--muted);
      font-size: .92rem;
      line-height: 1.35;
    }

    .badge{
      display:inline-flex;
      align-items:center;
      gap: 6px;
      padding: 5px 9px;
      border-radius: 999px;
      border: 1px solid var(--badgeBorder);
      background: var(--badgeBg);
      color: var(--badgeText);
      font-size: .78rem;
      font-weight: 800;
      letter-spacing: .2px;
      white-space: nowrap;
    }
    .badge svg{ width: 14px; height: 14px; fill: var(--badgeText); }

    .tips{
      margin-top: 12px;
      padding: 16px;
      border-radius: 16px;
      border: 1px solid var(--surfaceBorder);
      background: var(--surface2);
    }

    .tips h4{
      margin:0 0 10px;
      font-size: 1rem;
      letter-spacing:.2px;
    }

    .tips ul{
      margin:0;
      padding-left: 18px;
      color: var(--muted);
      line-height: 1.5;
      font-size: .92rem;
    }
    .tips li{ margin: 6px 0; }

    .meta{
      margin-top: 18px;
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .link{
      color:#c9b6ff;
      text-decoration:none;
      font-weight:700;
      padding: 8px 10px;
      border-radius: 10px;
      border: 1px solid transparent;
    }
    .link:hover{ text-decoration: underline; }

    .link.ghost{
      border-color: var(--surfaceBorder);
      background: rgba(8,10,18,.35);
      text-decoration:none;
    }
    .link.ghost:hover{ filter: brightness(1.05); text-decoration:none; }

    @media (max-width: 640px){
      .grid{ grid-template-columns: 1fr; }
    }
    @media (max-width: 360px){
      .panel{ padding: 22px 18px; }
      .title{ font-size: 1.2rem; }
    }
  </style>
</head>

<body>
  <div class="wrap">
    <main class="panel" role="main" aria-labelledby="title">
      <div class="top">
        <div>
          <h1 class="title" id="title">Halo, <?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?></h1>
          <p class="subtitle">Pilih modul steganografi untuk menyisipkan / mengekstrak pesan pada gambar.</p>
        </div>

        <div class="chip" title="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>
        </div>
      </div>

      <section class="grid" aria-label="Modul StegApp">
        <a class="module" href="stego_lsb.php" aria-label="Buka modul LSB Steganografi">
          <div class="icon" aria-hidden="true">
            <!-- Icon: Pixels -->
            <svg viewBox="0 0 24 24">
              <path d="M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 4h6v2h-6v-2zm0-4h2v2h-2v-2zm4 0h2v2h-2v-2z"/>
            </svg>
          </div>
          <div class="content">
            <h3>LSB Steganografi</h3>
            <p>Sisipkan teks pada bit paling rendah piksel. Cepat dan simpel untuk eksperimen dasar.</p>
          </div>
        </a>

        <a class="module" href="stego_iwt.php" aria-label="Buka modul IWT Steganografi (Rekomendasi)">
          <div class="icon" aria-hidden="true">
            <!-- Icon: Wave -->
            <svg viewBox="0 0 24 24">
              <path d="M3 12c2.5 0 2.5-6 5-6s2.5 12 5 12 2.5-12 5-12 2.5 6 6 6v2c-2.5 0-2.5-6-6-6s-2.5 12-5 12-2.5-12-5-12-2.5 6-5 6V12z"/>
            </svg>
          </div>
          <div class="content">
            <h3>
              IWT Steganografi
              <span class="badge" title="Lebih robust untuk perubahan ringan">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M12 2l2.9 6 6.6.6-5 4.3 1.6 6.5L12 16.9 5.9 19.4 7.5 13 2.5 8.6 9.1 8z"/>
                </svg>
                Rekomendasi
              </span>
            </h3>
            <p>Sisipkan pesan pada domain transform (wavelet). Cenderung lebih tahan terhadap kompresi/perubahan kecil.</p>
          </div>
        </a>
      </section>

      <section class="tips" aria-label="Tips Cepat">
        <h4>Tips Cepat</h4>
        <ul>
          <li>Pakai gambar resolusi lebih besar agar kapasitas pesan lebih longgar.</li>
          <li>Untuk hasil stabil, hindari upload ulang ke platform yang otomatis kompres gambar.</li>
          <li>Jika pesan panjang, pertimbangkan IWT karena biasanya lebih robust.</li>
        </ul>
      </section>

      <div class="meta">
        <a class="link ghost" href="help.php">Help / Tentang</a>
        <a class="link" href="logout.php">Logout</a>
      </div>
    </main>
  </div>
</body>
</html>
