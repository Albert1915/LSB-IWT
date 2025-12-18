<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['uid'])) { redirect('login.php'); }
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Tentang & Bantuan | StegApp</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <style>
    :root{
      --bgTop:#0b001b;
      --bgBot:#050010;

      --card: rgba(18, 20, 34, .88); /* dibuat sedikit lebih solid agar tetap “glass” tanpa blur */
      --border: rgba(255,255,255,.10);

      --text:#eef1ff;
      --muted:#a9b1c7;

      --surface: rgba(8,10,18,.45);
      --surface2: rgba(8,10,18,.32);
      --surfaceBorder: rgba(255,255,255,.10);

      --focus: rgba(167,108,255,.35);

      --primary1:#7c3aed;
      --primary2:#3b82f6;

      --ok:#a9f0c0;
      --warn:#ffd28a;
      --danger:#ffb4b9;
    }

    *{ box-sizing:border-box; }
    html,body{ height:100%; }

    body{
      margin:0;
      font-family: system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,Arial,sans-serif;
      color: var(--text);
      padding: 22px 16px 40px;

      /* background gradient yang lebih halus */
      background:
        radial-gradient(1200px 700px at 18% 14%, rgba(124,58,237,.38), transparent 58%),
        radial-gradient(1100px 650px at 86% 22%, rgba(59,130,246,.22), transparent 60%),
        radial-gradient(900px 600px at 50% 115%, rgba(124,58,237,.16), transparent 65%),
        linear-gradient(180deg, var(--bgTop), var(--bgBot));

      background-attachment: fixed; /* bikin transisi lebih mulus */
    }

    /* overlay glow halus (tanpa blur) */
    body::before{
      content:"";
      position:fixed; inset:0;
      pointer-events:none;
      background:
        radial-gradient(700px 240px at 50% 0%, rgba(255,255,255,.05), transparent 70%),
        radial-gradient(1000px 700px at 50% 100%, rgba(0,0,0,.40), transparent 60%);
      opacity:.9;
    }

    .container{
      width: min(92vw, 980px);
      margin: 0 auto;
      position:relative;
    }

    .topbar{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 14px;
    }

    .left{
      display:flex;
      align-items:center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .title{
      margin:0;
      font-size: 1.35rem;
      letter-spacing:.2px;
    }

    .subtitle{
      margin: 6px 0 0;
      color: var(--muted);
      font-size: .95rem;
      line-height: 1.35;
    }

    .chip{
      padding: 8px 10px;
      border-radius: 999px;
      border: 1px solid var(--surfaceBorder);
      background: var(--surface);
      color: rgba(238,241,255,.92);
      font-size: .9rem;
      white-space: nowrap;
    }

    a{ color:#c9b6ff; text-decoration:none; font-weight:700; }
    a:hover{ text-decoration: underline; }

    .btnlink{
      display:inline-flex;
      align-items:center;
      gap: 8px;
      padding: 8px 10px;
      border-radius: 10px;
      border: 1px solid var(--surfaceBorder);
      background: rgba(8,10,18,.35);
      text-decoration:none;
      font-weight:800;
    }
    .btnlink:hover{ filter: brightness(1.05); text-decoration:none; }

    .grid{
      display:grid;
      grid-template-columns: 1.15fr .85fr;
      gap: 12px;
    }

    .card{
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 18px 18px;
      box-shadow: 0 18px 60px rgba(0,0,0,.35);
      /* HAPUS blur supaya tidak jadi kotak-kotak */
      backdrop-filter: none;
      -webkit-backdrop-filter: none;
    }

    h2,h3{ margin:0 0 10px; letter-spacing:.2px; }
    h3{ font-size: 1.05rem; }
    p{ margin: 8px 0; line-height: 1.55; }
    ul,ol{ margin: 8px 0 0 18px; color: var(--muted); line-height: 1.55; }
    li{ margin: 6px 0; }

    .muted{ color: var(--muted); }

    .badge{
      display:inline-flex;
      align-items:center;
      gap: 6px;
      padding: 5px 9px;
      border-radius: 999px;
      border: 1px solid var(--surfaceBorder);
      background: rgba(8,10,18,.35);
      font-size: .82rem;
      font-weight: 800;
      letter-spacing: .2px;
      color: rgba(238,241,255,.9);
      margin-right: 8px;
      margin-bottom: 6px;
    }

    .kbd{
      padding:2px 7px;
      border:1px solid var(--surfaceBorder);
      border-bottom-width:2px;
      border-radius:8px;
      background: rgba(8,10,18,.55);
      color: rgba(238,241,255,.95);
      font-weight: 700;
      font-size: .9rem;
    }

    code{
      background: rgba(8,10,18,.55);
      border:1px solid var(--surfaceBorder);
      padding:2px 7px;
      border-radius:8px;
      color: rgba(238,241,255,.95);
      font-size: .9rem;
    }

    .tbl{
      width:100%;
      border-collapse: collapse;
      margin-top: 10px;
      overflow:hidden;
      border-radius: 14px;
    }
    .tbl th,.tbl td{
      border:1px solid rgba(255,255,255,.10);
      padding: 10px 10px;
      text-align:left;
      vertical-align: top;
      color: rgba(238,241,255,.92);
      font-size: .93rem;
    }
    .tbl th{ background: rgba(8,10,18,.35); color: rgba(238,241,255,.95); }
    .ok{ color: var(--ok); font-weight:700; }
    .warn{ color: var(--warn); font-weight:700; }
    .danger{ color: var(--danger); font-weight:700; }

    .stack{ display:grid; gap: 12px; }

    /* FAQ accordion */
    details{
      border: 1px solid var(--surfaceBorder);
      background: rgba(8,10,18,.30);
      border-radius: 14px;
      padding: 10px 12px;
    }
    summary{
      cursor: pointer;
      font-weight: 900;
      color: rgba(238,241,255,.95);
      list-style: none;
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 12px;
    }
    summary::-webkit-details-marker{ display:none; }

    .caret{
      width: 22px; height: 22px;
      border-radius: 10px;
      display:grid; place-items:center;
      background: linear-gradient(90deg, rgba(124,58,237,.80), rgba(59,130,246,.80));
      color: #0b0b14;
      flex: 0 0 auto;
      font-weight: 900;
      transition: transform .12s ease;
    }
    details[open] .caret{ transform: rotate(180deg); }
    details > div{ margin-top: 10px; color: var(--muted); line-height: 1.55; }

    .note{
      border: 1px solid var(--surfaceBorder);
      background: rgba(8,10,18,.30);
      border-radius: 14px;
      padding: 12px 12px;
      color: var(--muted);
    }

    .footer-actions{
      margin-top: 12px;
      display:flex;
      justify-content:space-between;
      align-items:center;
      flex-wrap: wrap;
      gap: 10px;
    }

    .cta{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      gap: 10px;
      padding: 10px 12px;
      border-radius: 12px;
      text-decoration:none;
      font-weight: 900;
      color: #0b0b14;
      background: linear-gradient(90deg, var(--primary1), var(--primary2));
      box-shadow: 0 12px 26px rgba(124,58,237,.25);
      border:0;
    }
    .cta:hover{ filter: brightness(1.05); text-decoration:none; }

    @media (max-width: 900px){
      .grid{ grid-template-columns: 1fr; }
    }
    @media (max-width: 360px){
      .title{ font-size: 1.2rem; }
      .card{ padding: 16px; }
    }
  </style>
</head>

<body>
  <div class="container">
    <header class="topbar">
      <div class="left">
        <a class="btnlink" href="dashboard.php" aria-label="Kembali ke Dashboard">← Dashboard</a>
        <div>
          <h1 class="title">Tentang & Bantuan</h1>
          <p class="subtitle">Panduan singkat penggunaan StegApp (LSB & IWT) + troubleshooting.</p>
        </div>
      </div>

      <div class="left">
        <span class="chip">StegApp</span>
        <a class="btnlink" href="logout.php">Logout</a>
      </div>
    </header>

    <div class="grid">
      <main class="stack" role="main">
        <section class="card">
          <h3>Ringkasan Aplikasi</h3>
          <p class="muted">StegApp menyediakan dua metode penyembunyian teks di gambar:</p>

          <p>
            <span class="badge">LSB</span>
            Menulis bit pada <em>Least Significant Bit</em> tiap kanal warna (spasial). Cepat & kapasitas besar, cocok untuk <strong>PNG/BMP/WebP (lossless)</strong>.
          </p>

          <p>
            <span class="badge">IWT</span>
            Mengubah gambar ke domain wavelet (Integer Haar), menyisipkan pada koefisien subband <strong>HH</strong> kanal <strong>B</strong>.
            Lebih tahan perubahan moderat. Input bisa <strong>PNG/BMP/WebP/JPEG/TIFF</strong> (JPEG/TIFF butuh Imagick). Output tetap <strong>PNG</strong>.
          </p>

          <div class="note">
            <strong>Catatan:</strong> Semua keluaran disimpan sebagai PNG agar payload tidak rusak oleh kompresi lossy.
          </div>
        </section>

        <section class="card">
          <h3>Dukungan Format</h3>
          <table class="tbl" aria-label="Tabel dukungan format">
            <tr><th>Fitur</th><th>Input</th><th>Output</th><th>Keterangan</th></tr>
            <tr>
              <td><strong>LSB</strong></td>
              <td>PNG, BMP, WebP (lossless)</td>
              <td>PNG</td>
              <td><span class="ok">Hindari JPEG/GIF</span> — kompresi & palet dapat merusak bit.</td>
            </tr>
            <tr>
              <td><strong>IWT</strong></td>
              <td>PNG, BMP, WebP, <strong>JPEG</strong>, <strong>TIFF</strong> <span class="muted">(butuh Imagick)</span></td>
              <td>PNG</td>
              <td><span class="warn">Lebih robust</span> karena embed pada subband HH kanal B.</td>
            </tr>
          </table>
        </section>

        <section class="card">
          <h3>Kapasitas Perkiraan</h3>
          <ul>
            <li><strong>LSB</strong>: <code>(lebar × tinggi × 3 / 8) − 4</code> byte (4 byte untuk header).</li>
            <li><strong>IWT</strong>: <code>(⌊lebar/2⌋ × ⌊tinggi/2⌋ / 8) − 4</code> byte (1 bit/koef HH, 1 level, 1 kanal).</li>
          </ul>
          <p class="muted">Jika pesan terlalu panjang, sistem sebaiknya menolak dan menampilkan batas maksimal kapasitas.</p>
        </section>

        <section class="card">
          <h3>Langkah Cepat</h3>
          <ol>
            <li>Buka menu <strong>LSB</strong> atau <strong>IWT</strong> dari Dashboard.</li>
            <li>Upload gambar <span class="ok">lossless</span> untuk hasil terbaik (LSB) atau format yang didukung (IWT).</li>
            <li>Tulis pesan, klik <span class="kbd">Sisipkan</span>, lalu unduh hasil PNG.</li>
            <li>Untuk membaca, unggah file hasil tersebut ke panel <span class="kbd">Ekstrak</span>.</li>
          </ol>

          <div class="footer-actions">
            <a class="cta" href="stego_iwt.php">Mulai dengan IWT (Rekomendasi)</a>
            <a class="btnlink" href="stego_lsb.php">Buka LSB</a>
          </div>
        </section>

        <section class="card">
          <h3>Instalasi Ekstensi</h3>
          <ul>
            <li><strong>GD</strong> — wajib (PNG/BMP/WebP). Aktifkan <code>extension=gd</code> di <code>php.ini</code>.</li>
            <li><strong>Imagick</strong> — opsional (JPEG/TIFF). Aktifkan <code>extension=imagick</code>.</li>
          </ul>
          <p class="muted">Cek status dengan <code>phpinfo()</code> (mis. file <code>info.php</code>).</p>
        </section>

        <section class="card">
          <h3>Keamanan & Praktik Baik</h3>
          <ul>
            <li>Jangan kirim stego PNG lewat layanan yang mengompres otomatis — kirim sebagai file asli.</li>
            <li>Untuk kerahasiaan ekstra, enkripsi pesan dulu (mis. ZIP + password) sebelum embed.</li>
            <li>Hapus file sementara di folder <code>uploads</code> / <code>outputs</code> bila tidak diperlukan.</li>
          </ul>
        </section>

        <section class="card">
          <h3>Privasi</h3>
          <p class="muted">Aplikasi memproses data pada server yang kamu jalankan (mis. localhost). Tidak ada pengiriman data ke pihak ketiga.</p>
        </section>
      </main>

      <aside class="stack" aria-label="FAQ dan Troubleshooting">
        <section class="card">
          <h3>FAQ & Troubleshooting</h3>

          <details>
            <summary>Ekstensi GD/Imagick belum aktif <span class="caret">⌄</span></summary>
            <div>
              <span class="danger"><strong>Solusi:</strong></span> aktifkan di <code>php.ini</code> lalu restart Apache.
              <ul>
                <li>GD: <code>extension=gd</code></li>
                <li>Imagick: <code>extension=imagick</code></li>
              </ul>
            </div>
          </details>

          <details>
            <summary>“Data tidak lengkap (header/pesan)” <span class="caret">⌄</span></summary>
            <div>Biasanya file bukan hasil modul StegApp, atau gambar sudah rusak/terkompres. Pastikan ekstrak memakai PNG output aplikasi.</div>
          </details>

          <details>
            <summary>Pesan tidak muncul di LSB <span class="caret">⌄</span></summary>
            <div>Pastikan gambar sumber <strong>lossless</strong> (PNG/BMP/WebP lossless) dan jangan di-resave dengan tool yang mengubah piksel.</div>
          </details>

          <details>
            <summary>Kapasitas kurang / pesan terlalu panjang <span class="caret">⌄</span></summary>
            <div>Gunakan gambar lebih besar atau pindah ke IWT.</div>
          </details>

          <details>
            <summary>Kenapa output selalu PNG? <span class="caret">⌄</span></summary>
            <div>PNG lossless sehingga bit payload tidak hilang. JPEG lossy dan dapat merusak data tersembunyi.</div>
          </details>
        </section>

        <section class="card">
          <h3>Checklist Cepat</h3>
          <div class="note">
            <ul style="margin:0;padding-left:18px;">
              <li><span class="ok"><strong>LSB:</strong></span> gunakan PNG/BMP/WebP lossless.</li>
              <li><span class="warn"><strong>IWT:</strong></span> lebih tahan perubahan kecil.</li>
              <li>Hindari platform yang auto-kompres.</li>
            </ul>
          </div>
        </section>
      </aside>
    </div>
  </div>
</body>
</html>
