<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['uid'])) { redirect('login.php'); }

/* =======================
   UTIL
======================= */
function ensure_dir(string $path): void {
  if (!is_dir($path)) { mkdir($path, 0775, true); }
}
function csrf_field(): string {
  return '<input type="hidden" name="csrf" value="'.htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8').'">';
}

/* =======================
   DETEKSI & LOAD GAMBAR
======================= */
function detect_image(string $tmpPath): array {
  $mime = null;
  if (function_exists('finfo_open')) {
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
      $mime = @finfo_file($finfo, $tmpPath);
      @finfo_close($finfo);
    }
  }
  if (!$mime) { $mime = @mime_content_type($tmpPath) ?: ''; }

  $map = [
    'image/png'        => 'png',
    'image/bmp'        => 'bmp',
    'image/x-ms-bmp'   => 'bmp',
    'image/webp'       => 'webp',
  ];
  return [$mime, $map[$mime] ?? null];
}

function gd_load_image(string $path, string $fmt) {
  switch ($fmt) {
    case 'png':  return @imagecreatefrompng($path);
    case 'bmp':  return function_exists('imagecreatefrombmp')  ? @imagecreatefrombmp($path)  : false;
    case 'webp': return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
    default:     return false;
  }
}

/* =======================
   BIT/BYTE HELPERS
   (LSB-first per byte)
======================= */
function bytes_to_bits(string $bytes): array {
  $bits = [];
  for ($i=0,$n=strlen($bytes); $i<$n; $i++){
    $byte = ord($bytes[$i]);
    for ($b=0;$b<8;$b++){ $bits[] = ($byte >> $b) & 1; }
  }
  return $bits;
}
function bits_to_bytes(array $bits): string {
  $out = ''; $byte = 0; $count = 0;
  foreach ($bits as $bit) {
    $byte |= (($bit ? 1 : 0) << ($count % 8));
    $count++;
    if ($count % 8 === 0) { $out .= chr($byte); $byte = 0; }
  }
  if ($count % 8 !== 0) { $out .= chr($byte); }
  return $out;
}
function u32le_pack(int $v): string {
  return chr($v&0xFF).chr(($v>>8)&0xFF).chr(($v>>16)&0xFF).chr(($v>>24)&0xFF);
}
function u32le_unpack(string $s): int {
  return (ord($s[0]) | (ord($s[1])<<8) | (ord($s[2])<<16) | (ord($s[3])<<24));
}

/* =======================
   LSB EMBED (OUTPUT PNG)
   - lebih cepat & aman:
     pakai truecolor integer, bukan allocate per-pixel
   - menjaga alpha
======================= */
function lsb_embed_gd($img, string $message, string $outPath): array {
  if (!extension_loaded('gd')) return [false, 'Ekstensi GD belum aktif.'];

  $w = imagesx($img); $h = imagesy($img);

  // pastikan truecolor
  if (!imageistruecolor($img)) {
    $true = imagecreatetruecolor($w, $h);
    imagealphablending($true, false);
    imagesavealpha($true, true);
    imagecopy($true, $img, 0,0,0,0, $w,$h);
    imagedestroy($img);
    $img = $true;
  }

  // jaga alpha saat nulis pixel
  imagealphablending($img, false);
  imagesavealpha($img, true);

  $payload = u32le_pack(strlen($message)) . $message; // 4B header + msg
  $bits = bytes_to_bits($payload);

  $capacity_bits = $w * $h * 3;
  if (count($bits) > $capacity_bits) {
    $cap_bytes = intdiv($capacity_bits, 8) - 4;
    return [false, "Pesan terlalu panjang. Kapasitas max ≈ {$cap_bytes} byte untuk {$w}x{$h}."];
  }

  $idx = 0;
  $total = count($bits);

  for ($y=0; $y<$h && $idx<$total; $y++){
    for ($x=0; $x<$w && $idx<$total; $x++){
      $rgba = imagecolorat($img, $x, $y);

      // GD truecolor: A(7bit) di bit 24..30, RGB 16..23/8..15/0..7
      $a = ($rgba & 0x7F000000) >> 24;  // 0 (opaque) .. 127 (transparent)
      $r = ($rgba >> 16) & 0xFF;
      $g = ($rgba >> 8)  & 0xFF;
      $b = $rgba & 0xFF;

      if ($idx < $total) $r = ($r & 0xFE) | $bits[$idx++];
      if ($idx < $total) $g = ($g & 0xFE) | $bits[$idx++];
      if ($idx < $total) $b = ($b & 0xFE) | $bits[$idx++];

      // set pixel pakai integer RGBA, tanpa imagecolorallocate()
      $new = ($a << 24) | ($r << 16) | ($g << 8) | $b;
      imagesetpixel($img, $x, $y, $new);
    }
  }

  ensure_dir(dirname($outPath));
  $ok = imagepng($img, $outPath, 9);
  imagedestroy($img);

  return [$ok, $ok ? null : 'Gagal menyimpan gambar hasil.'];
}

/* =======================
   LSB EXTRACT
======================= */
function lsb_extract_gd($img): array {
  if (!extension_loaded('gd')) return [false, null, 'Ekstensi GD belum aktif.'];

  $w = imagesx($img); $h = imagesy($img);

  $headerBits = [];
  $msgBits = [];
  $haveHeader = false;
  $needBits = 0;
  $msgLen = 0;

  for ($y=0; $y<$h; $y++){
    for ($x=0; $x<$w; $x++){
      $rgba = imagecolorat($img, $x, $y);
      $channels = [ ($rgba>>16)&0xFF, ($rgba>>8)&0xFF, $rgba&0xFF ]; // R,G,B

      foreach ($channels as $ch) {
        $bit = $ch & 1;

        if (!$haveHeader) {
          $headerBits[] = $bit;
          if (count($headerBits) === 32) {
            $header = bits_to_bytes($headerBits);
            $msgLen = u32le_unpack(substr($header,0,4));

            $capacityBytes = intdiv($w*$h*3, 8);
            if ($msgLen <= 0 || ($msgLen + 4) > $capacityBytes) {
              return [false, null, 'Tidak ada payload LSB yang valid pada gambar ini.'];
            }
            $needBits = $msgLen * 8;
            $haveHeader = true;
          }
        } else {
          if (count($msgBits) < $needBits) {
            $msgBits[] = $bit;
            if (count($msgBits) === $needBits) break 3;
          }
        }
      }
    }
  }

  if (!$haveHeader) return [false, null, 'Data tidak lengkap (header).'];
  if (count($msgBits) < $needBits) return [false, null, 'Data tidak lengkap (pesan).'];

  $msg = bits_to_bytes($msgBits);
  $msg = preg_replace('/[\x00-\x1F\x7F]+$/', '', $msg);

  $hex = strtoupper(bin2hex($msg));
  $hex = trim(chunk_split($hex, 2, ' '));

  return [true, ['len'=>$msgLen, 'text'=>$msg, 'hex'=>$hex], null];
}

/* =======================
   HANDLER
======================= */
$embedResult = null;
$extract = null;
$errors = [];
$keepMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate($_POST['csrf'] ?? '')) {
    $errors[] = 'Sesi tidak valid. Muat ulang halaman.';
  } else {

    // ===== EMBED =====
    if (($_POST['action'] ?? '') === 'embed') {
      $keepMessage = (string)($_POST['message'] ?? '');
      if (!isset($_FILES['cover']) || $_FILES['cover']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload gambar terlebih dahulu.';
      } else {
        [$mime, $fmt] = detect_image($_FILES['cover']['tmp_name']);
        if (!in_array($fmt, ['png','bmp','webp'], true)) {
          $errors[] = 'Format tidak didukung. Gunakan PNG, BMP, atau WebP (lossless).';
        } else {
          $img = gd_load_image($_FILES['cover']['tmp_name'], $fmt);
          if (!$img) {
            $errors[] = 'Gagal memuat gambar (pastikan GD mendukung format ini).';
          } else {
            $message = trim((string)($_POST['message'] ?? ''));
            if ($message === '') {
              $errors[] = 'Teks pesan tidak boleh kosong.';
            } else {
              ensure_dir(__DIR__.'/outputs');
              $outName = 'stego_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.png';
              $outPath = __DIR__.'/outputs/'.$outName;

              [$ok, $err] = lsb_embed_gd($img, $message, $outPath);
              if ($ok) {
                $embedResult = 'outputs/'.$outName;
                $keepMessage = '';
              } else {
                $errors[] = $err ?: 'Gagal menyisipkan pesan.';
              }
            }
          }
        }
      }
    }

    // ===== EXTRACT =====
    if (($_POST['action'] ?? '') === 'extract') {
      if (!isset($_FILES['stego']) || $_FILES['stego']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload gambar yang berisi pesan.';
      } else {
        [$mime, $fmt] = detect_image($_FILES['stego']['tmp_name']);
        if (!in_array($fmt, ['png','bmp','webp'], true)) {
          $errors[] = 'Format tidak didukung. Gunakan PNG, BMP, atau WebP.';
        } else {
          $img = gd_load_image($_FILES['stego']['tmp_name'], $fmt);
          if (!$img) {
            $errors[] = 'Gagal memuat gambar (pastikan GD mendukung format ini).';
          } else {
            [$ok, $data, $err] = lsb_extract_gd($img);
            imagedestroy($img);
            if ($ok) $extract = $data;
            else $errors[] = $err ?: 'Gagal mengekstrak pesan.';
          }
        }
      }
    }
  }
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>LSB Steganografi | StegApp</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <style>
    :root{
      --bgTop:#0b001b;
      --bgBot:#050010;

      --card: rgba(18, 20, 34, .88);
      --border: rgba(255,255,255,.10);

      --text:#eef1ff;
      --muted:#a9b1c7;

      --surface: rgba(8,10,18,.45);
      --surface2: rgba(8,10,18,.30);
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

      background:
        radial-gradient(1200px 700px at 18% 14%, rgba(124,58,237,.38), transparent 58%),
        radial-gradient(1100px 650px at 86% 22%, rgba(59,130,246,.22), transparent 60%),
        radial-gradient(900px 600px at 50% 115%, rgba(124,58,237,.16), transparent 65%),
        linear-gradient(180deg, var(--bgTop), var(--bgBot));
      background-attachment: fixed;
    }

    body::before{
      content:"";
      position:fixed; inset:0;
      pointer-events:none;
      background:
        radial-gradient(700px 240px at 50% 0%, rgba(255,255,255,.05), transparent 70%),
        radial-gradient(1000px 700px at 50% 100%, rgba(0,0,0,.40), transparent 60%);
      opacity:.9;
    }

    .container{ width: min(92vw, 1100px); margin:0 auto; position:relative; }

    .topbar{
      display:flex; justify-content:space-between; align-items:center;
      gap:12px; flex-wrap:wrap; margin-bottom: 14px;
    }

    .left{
      display:flex; align-items:center; gap: 12px; flex-wrap:wrap;
    }

    .title{ margin:0; font-size: 1.35rem; letter-spacing:.2px; }

    .btnlink{
      display:inline-flex; align-items:center; gap:8px;
      padding: 8px 10px;
      border-radius: 10px;
      border: 1px solid var(--surfaceBorder);
      background: rgba(8,10,18,.35);
      color:#c9b6ff;
      text-decoration:none;
      font-weight:800;
    }
    .btnlink:hover{ filter: brightness(1.05); }

    .chip{
      padding: 8px 10px;
      border-radius: 999px;
      border: 1px solid var(--surfaceBorder);
      background: var(--surface);
      color: rgba(238,241,255,.92);
      font-size: .9rem;
      white-space: nowrap;
    }

    .banner{
      background: rgba(8,10,18,.30);
      border: 1px solid var(--surfaceBorder);
      border-radius: 16px;
      padding: 14px 14px;
      color: rgba(238,241,255,.92);
      margin-bottom: 12px;
      line-height: 1.45;
    }
    .banner b{ color: rgba(238,241,255,.98); }
    .ok{ color: var(--ok); font-weight:800; }
    .warn{ color: var(--warn); font-weight:800; }
    .danger{ color: var(--danger); font-weight:800; }

    .grid{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }

    .card{
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 18px 18px;
      box-shadow: 0 18px 60px rgba(0,0,0,.35);
    }

    h3{ margin:0 0 12px; letter-spacing:.2px; }

    label{ display:block; font-weight:800; margin: 10px 0 6px; color: rgba(238,241,255,.92); }

    input[type="file"], textarea{
      width:100%;
      background: rgba(8,10,18,.55);
      border: 1px solid var(--surfaceBorder);
      border-radius: 12px;
      color: var(--text);
      padding: 10px 12px;
      outline: none;
    }
    textarea{ min-height: 160px; resize: vertical; }
    input[type="file"]:focus, textarea:focus{
      border-color: rgba(167,108,255,.55);
      box-shadow: 0 0 0 4px var(--focus);
    }
    textarea::placeholder{ color: rgba(238,241,255,.45); }

    .muted{ color: var(--muted); font-size: .93rem; line-height: 1.45; margin: 8px 0 0; }

    .btn{
      margin-top: 12px;
      padding: 12px 14px;
      border: 0;
      border-radius: 12px;
      cursor: pointer;
      font-weight: 900;
      color: #0b0b14;
      background: linear-gradient(90deg, var(--primary1), var(--primary2));
      box-shadow: 0 12px 26px rgba(124,58,237,.25);
      transition: transform .08s ease, filter .15s ease;
    }
    .btn:hover{ filter: brightness(1.05); }
    .btn:active{ transform: translateY(1px); }

    .alert{
      border-radius: 14px;
      padding: 10px 12px;
      border: 1px solid;
      margin: 0 0 12px;
      line-height: 1.45;
    }
    .alert.error{
      background: rgba(180, 28, 43, .16);
      border-color: rgba(255, 77, 92, .35);
      color: #ffd1d6;
    }
    .alert.ok{
      background: rgba(16, 185, 129, .12);
      border-color: rgba(16, 185, 129, .35);
      color: #c9ffe7;
    }

    .preview{
      margin-top: 12px;
      width:100%;
      border-radius: 14px;
      border: 1px solid var(--surfaceBorder);
      background: rgba(8,10,18,.25);
    }

    .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }

    details{
      margin-top: 10px;
      border: 1px solid var(--surfaceBorder);
      background: rgba(8,10,18,.30);
      border-radius: 14px;
      padding: 10px 12px;
    }
    summary{
      cursor:pointer;
      font-weight: 900;
      color: rgba(238,241,255,.95);
      list-style:none;
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap: 10px;
    }
    summary::-webkit-details-marker{ display:none; }
    .caret{
      width: 22px; height: 22px;
      border-radius: 10px;
      display:grid; place-items:center;
      background: linear-gradient(90deg, rgba(124,58,237,.80), rgba(59,130,246,.80));
      color:#0b0b14;
      font-weight:900;
      transition: transform .12s ease;
      flex: 0 0 auto;
    }
    details[open] .caret{ transform: rotate(180deg); }

    .foot{
      margin-top: 14px;
      color: var(--muted);
      font-size: .93rem;
      line-height: 1.45;
    }
    code{
      background: rgba(8,10,18,.55);
      border: 1px solid var(--surfaceBorder);
      padding: 2px 7px;
      border-radius: 8px;
      color: rgba(238,241,255,.95);
      font-size: .92rem;
    }

    @media (max-width: 900px){
      .grid{ grid-template-columns: 1fr; }
    }
  </style>
</head>

<body>
  <div class="container">
    <header class="topbar">
      <div class="left">
        <a class="btnlink" href="dashboard.php">← Dashboard</a>
        <h1 class="title">LSB Steganografi</h1>
        <span class="chip">Output: PNG</span>
      </div>

      <div class="left">
        <a class="btnlink" href="help.php">Help</a>
        <a class="btnlink" href="logout.php">Logout</a>
      </div>
    </header>

    <?php if ($errors): ?>
      <div class="alert error" role="alert">
        <?php foreach ($errors as $e): ?>
          <div><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="banner">
      <b>Dukungan format (LSB):</b> <span class="ok">PNG / BMP / WebP (lossless)</span> → output selalu <b>PNG</b>.<br>
      <b>Tidak disarankan:</b> <span class="danger">JPEG</span> (lossy), <span class="danger">GIF</span> (palet) karena bisa merusak bit.
    </div>

    <div class="grid">
      <!-- EMBED -->
      <section class="card">
        <h3>Sisip Teks ke Gambar</h3>

        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="embed">

          <label>Gambar sampul (PNG / BMP / WebP)</label>
          <input type="file" name="cover" accept="image/png,image/bmp,image/x-ms-bmp,image/webp" required>
          <p class="muted">Gunakan format <b>lossless</b>. Untuk WebP, pastikan mode <i>lossless</i>.</p>

          <label>Teks yang disisipkan</label>
          <textarea name="message" placeholder="Tulis pesan rahasia..." required><?= htmlspecialchars($keepMessage, ENT_QUOTES, 'UTF-8') ?></textarea>

          <button class="btn" type="submit">Sisipkan</button>
        </form>

        <?php if ($embedResult): ?>
          <div class="alert ok" role="status" style="margin-top:12px;">
            <div><strong>Berhasil!</strong> Stego PNG siap diunduh.</div>
            <div class="muted" style="margin-top:6px;">
              Gunakan file hasil ini untuk ekstrak di panel kanan.
            </div>
            <div style="margin-top:8px;">
              <a class="btnlink" href="<?= htmlspecialchars($embedResult, ENT_QUOTES, 'UTF-8') ?>" download>Unduh stego PNG</a>
              <span class="chip" style="margin-left:8px;"><?= htmlspecialchars($embedResult, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
          </div>

          <img class="preview" src="<?= htmlspecialchars($embedResult, ENT_QUOTES, 'UTF-8') ?>" alt="Hasil stego">
        <?php endif; ?>
      </section>

      <!-- EXTRACT -->
      <section class="card">
        <h3>Ekstrak Teks dari Gambar</h3>

        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="extract">

          <label>Gambar stego (PNG / BMP / WebP)</label>
          <input type="file" name="stego" accept="image/png,image/bmp,image/x-ms-bmp,image/webp" required>

          <button class="btn" type="submit">Ekstrak</button>
        </form>

        <?php if ($extract !== null): ?>
          <div class="alert ok" role="status" style="margin-top:12px;">
            <div><strong>Pesan ditemukan (<?= (int)$extract['len'] ?> byte)</strong></div>
            <pre class="mono" style="white-space:pre-wrap;word-wrap:break-word;margin:10px 0 0"><?= htmlspecialchars($extract['text'], ENT_QUOTES, 'UTF-8') ?></pre>

            <details>
              <summary>Detail hexdump <span class="caret">⌄</span></summary>
              <div class="mono" style="margin-top:10px;line-height:1.55;">
                <?= htmlspecialchars($extract['hex'], ENT_QUOTES, 'UTF-8') ?>
              </div>
            </details>
          </div>
        <?php endif; ?>
      </section>
    </div>

    <p class="foot">
      Kapasitas kira-kira: <code>(lebar × tinggi × 3 / 8) − 4</code> byte (4 byte untuk header panjang pesan).
    </p>
  </div>
</body>
</html>
