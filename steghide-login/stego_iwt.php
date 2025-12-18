<?php
require __DIR__ . '/config.php';
if (empty($_SESSION['uid'])) { redirect('login.php'); }

/*
 * =============  IWT STEGO (Integer Haar, 1-level, channel B, subband HH)  =============
 * - Input: PNG/BMP/WebP (GD) + JPEG/TIFF (Imagick)
 * - Output: selalu PNG (lossless)
 * - Payload: 4 byte (u32le panjang) + bytes pesan, disisipkan ke LSB koefisien HH (B channel)
 */

// ---------- Util umum ----------
function ensure_dir(string $path): void { if (!is_dir($path)) { mkdir($path, 0775, true); } }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8').'">'; }
function clamp255(int $v): int { return $v < 0 ? 0 : ($v > 255 ? 255 : $v); }

// Deteksi MIME → format ringkas
function detect_image(string $tmpPath): array {
  $mime = null;
  if (function_exists('finfo_open')) {
    $f = @finfo_open(FILEINFO_MIME_TYPE);
    if ($f) { $mime = @finfo_file($f, $tmpPath); @finfo_close($f); }
  }
  if (!$mime) { $mime = @mime_content_type($tmpPath) ?: ''; }

  $map = [
    'image/png'       => 'png',
    'image/bmp'       => 'bmp',
    'image/x-ms-bmp'  => 'bmp',
    'image/webp'      => 'webp',
    'image/jpeg'      => 'jpeg',
    'image/tiff'      => 'tiff',
  ];
  return [$mime, $map[$mime] ?? null];
}

// ---------- Loader gambar (Imagick diutamakan, fallback ke GD) ----------
function can_use_imagick(): bool { return class_exists('Imagick'); }

function load_image_any(string $path, string $fmt) {
  if (can_use_imagick()) {
    try {
      $im = new Imagick();
      $im->readImage($path);
      $im->setImageColorspace(Imagick::COLORSPACE_RGB);
      $im->setImageType(Imagick::IMGTYPE_TRUECOLOR);
      return $im; // Imagick object
    } catch (Throwable $e) { /* fallback ke GD */ }
  }
  switch ($fmt) {
    case 'png':  return @imagecreatefrompng($path);
    case 'bmp':  return function_exists('imagecreatefrombmp')  ? @imagecreatefrombmp($path)  : false;
    case 'webp': return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
    default:     return false;
  }
}

function is_imagick_image($img): bool { return $img instanceof Imagick; }
function is_gd_image($img): bool { return is_resource($img) || (is_object($img) && get_class($img)==='GdImage'); }

// ---------- Konversi pixel ----------
function imagick_to_rgb_arrays(Imagick $im): array {
  $w = $im->getImageWidth(); $h = $im->getImageHeight();
  $pixels = $im->exportImagePixels(0, 0, $w, $h, 'RGB', Imagick::PIXEL_CHAR);
  $R = $G = $B = array_fill(0, $h, array_fill(0, $w, 0));
  $i = 0;
  for ($y=0;$y<$h;$y++){
    for ($x=0;$x<$w;$x++){
      $R[$y][$x] = $pixels[$i++];
      $G[$y][$x] = $pixels[$i++];
      $B[$y][$x] = $pixels[$i++];
    }
  }
  return [$R,$G,$B,$w,$h];
}
function gd_to_rgb_arrays($im): array {
  $w = imagesx($im); $h = imagesy($im);
  $R = $G = $B = array_fill(0, $h, array_fill(0, $w, 0));
  for ($y=0;$y<$h;$y++){
    for ($x=0;$x<$w;$x++){
      $rgb = imagecolorat($im, $x, $y);
      $R[$y][$x] = ($rgb >> 16) & 0xFF;
      $G[$y][$x] = ($rgb >>  8) & 0xFF;
      $B[$y][$x] = ($rgb      ) & 0xFF;
    }
  }
  return [$R,$G,$B,$w,$h];
}
function rgb_arrays_to_imagick(array $R, array $G, array $B, int $w, int $h): Imagick {
  $pixels = [];
  for ($y=0;$y<$h;$y++){
    for ($x=0;$x<$w;$x++){
      $pixels[] = clamp255((int)$R[$y][$x]);
      $pixels[] = clamp255((int)$G[$y][$x]);
      $pixels[] = clamp255((int)$B[$y][$x]);
    }
  }
  $im = new Imagick();
  $im->newImage($w, $h, new ImagickPixel('black'));
  $im->setImageColorspace(Imagick::COLORSPACE_RGB);
  $im->setImageType(Imagick::IMGTYPE_TRUECOLOR);
  $im->importImagePixels(0,0,$w,$h,'RGB', Imagick::PIXEL_CHAR, $pixels);
  return $im;
}
function rgb_arrays_to_gd(array $R, array $G, array $B, int $w, int $h) {
  $im = imagecreatetruecolor($w, $h);
  for ($y=0;$y<$h;$y++){
    for ($x=0;$x<$w;$x++){
      $col = imagecolorallocate($im, clamp255((int)$R[$y][$x]), clamp255((int)$G[$y][$x]), clamp255((int)$B[$y][$x]));
      imagesetpixel($im, $x, $y, $col);
    }
  }
  return $im;
}

// ---------- Bit helpers ----------
function bytes_to_bits(string $bytes): array {
  $bits = [];
  $n = strlen($bytes);
  for ($i=0;$i<$n;$i++){
    $byte = ord($bytes[$i]);
    for ($b=0;$b<8;$b++) { $bits[] = ($byte >> $b) & 1; }
  }
  return $bits;
}
function bits_to_bytes(array $bits): string {
  $out = ''; $byte=0; $cnt=0;
  foreach ($bits as $bit){
    $byte |= (($bit?1:0) << ($cnt%8));
    $cnt++;
    if ($cnt%8===0){ $out .= chr($byte); $byte=0; }
  }
  if ($cnt%8!==0) $out .= chr($byte);
  return $out;
}
function u32le_pack(int $v): string { return chr($v&0xFF).chr(($v>>8)&0xFF).chr(($v>>16)&0xFF).chr(($v>>24)&0xFF); }
function u32le_unpack(string $s): int { return (ord($s[0]) | (ord($s[1])<<8) | (ord($s[2])<<16) | (ord($s[3])<<24)); }

// ---------- IWT (Haar integer, 1-level) ----------
function iwt_forward(array $M, int $w, int $h): array {
  for ($y=0;$y<$h;$y++){
    $row = $M[$y];
    $s=[]; $d=[];
    for ($x=0; $x+1<$w; $x+=2){
      $a=(int)$row[$x]; $b=(int)$row[$x+1];
      $s[] = ($a + $b) >> 1;
      $d[] = $a - $b;
    }
    if ($w%2===1){ $s[] = (int)$row[$w-1]; }
    $M[$y] = array_merge($s, $d);
  }
  for ($x=0;$x<$w;$x++){
    $col=[];
    for ($y=0;$y<$h;$y++){ $col[] = $M[$y][$x]; }
    $s=[]; $d=[];
    for ($y=0; $y+1<$h; $y+=2){
      $a=(int)$col[$y]; $b=(int)$col[$y+1];
      $s[] = ($a + $b) >> 1;
      $d[] = $a - $b;
    }
    if ($h%2===1){ $s[] = (int)$col[$h-1]; }
    $merged = array_merge($s, $d);
    for ($y=0;$y<$h;$y++){ $M[$y][$x] = $merged[$y]; }
  }
  return $M;
}
function iwt_inverse(array $M, int $w, int $h): array {
  $halfH = intdiv($h,2) + ($h%2);
  for ($x=0;$x<$w;$x++){
    $col=[];
    for ($y=0;$y<$h;$y++){ $col[] = $M[$y][$x]; }
    $s = array_slice($col, 0, $halfH);
    $d = array_slice($col, $halfH);
    $rec=[];
    $pairs = min(count($d), count($s));
    for ($i=0;$i<$pairs;$i++){
      $si=(int)$s[$i]; $di=(int)$d[$i];
      $a  = $si + ((($di + 1) >> 1));
      $b  = $a - $di;
      $rec[]=$a; $rec[]=$b;
    }
    if ($h%2===1){ $rec[] = (int)$s[count($s)-1]; }
    for ($y=0;$y<$h;$y++){ $M[$y][$x] = $rec[$y]; }
  }

  $halfW = intdiv($w,2) + ($w%2);
  for ($y=0;$y<$h;$y++){
    $row = $M[$y];
    $s = array_slice($row, 0, $halfW);
    $d = array_slice($row, $halfW);
    $rec=[];
    $pairs = min(count($d), count($s));
    for ($i=0;$i<$pairs;$i++){
      $si=(int)$s[$i]; $di=(int)$d[$i];
      $a  = $si + ((($di + 1) >> 1));
      $b  = $a - $di;
      $rec[]=$a; $rec[]=$b;
    }
    if ($w%2===1){ $rec[] = (int)$s[count($s)-1]; }
    $M[$y] = $rec;
  }
  return $M;
}

function iwt_embed_bits_HH(array $B, int $w, int $h, array $bits): array {
  $halfW = intdiv($w,2) + ($w%2);
  $halfH = intdiv($h,2) + ($h%2);
  $hh_w = intdiv($w,2); $hh_h = intdiv($h,2);
  $idx=0; $bitsN=count($bits);
  for ($y=0;$y<$hh_h;$y++){
    for ($x=0;$x<$hh_w;$x++){
      if ($idx >= $bitsN) return [$B, $idx];
      $yy = $halfH + $y; $xx = $halfW + $x;
      $coef = (int)$B[$yy][$xx];
      $coef = ($coef & ~1) | ($bits[$idx++] ? 1 : 0);
      $B[$yy][$xx] = $coef;
    }
  }
  return [$B, $idx];
}
function iwt_extract_bits_HH(array $B, int $w, int $h, int $needBits): array {
  $halfW = intdiv($w,2) + ($w%2);
  $halfH = intdiv($h,2) + ($h%2);
  $hh_w = intdiv($w,2); $hh_h = intdiv($h,2);
  $bits=[]; $collected=0;
  for ($y=0;$y<$hh_h && $collected<$needBits;$y++){
    for ($x=0;$x<$hh_w && $collected<$needBits;$x++){
      $yy = $halfH + $y; $xx = $halfW + $x;
      $coef = (int)$B[$yy][$xx];
      $bits[] = $coef & 1;
      $collected++;
    }
  }
  return [$bits, $collected];
}

// ---------- Proses embed ----------
function iwt_embed_image($img, string $message, string $outPath): array {
  if (is_imagick_image($img)) { [$R,$G,$B,$w,$h] = imagick_to_rgb_arrays($img); }
  elseif (is_gd_image($img))  { [$R,$G,$B,$w,$h] = gd_to_rgb_arrays($img); }
  else { return [false, 'Tipe gambar tidak dikenal']; }

  $hh_w = intdiv($w,2); $hh_h = intdiv($h,2);
  $capacity_bits = $hh_w * $hh_h;

  $payload = u32le_pack(strlen($message)) . $message;
  $bits = bytes_to_bits($payload);

  if (count($bits) > $capacity_bits) {
    $cap_bytes = intdiv($capacity_bits, 8) - 4;
    return [false, "Pesan terlalu panjang untuk IWT-HH (max ≈ {$cap_bytes} byte pada {$w}x{$h})."];
  }

  $B_tr = iwt_forward($B, $w, $h);
  [$B_tr, $used] = iwt_embed_bits_HH($B_tr, $w, $h, $bits);
  if ($used < count($bits)) { return [false, 'Kapasitas tidak cukup.']; }
  $B_rec = iwt_inverse($B_tr, $w, $h);

  for ($y=0;$y<$h;$y++){
    for ($x=0;$x<$w;$x++){
      $B_rec[$y][$x] = clamp255((int)$B_rec[$y][$x]);
    }
  }

  ensure_dir(dirname($outPath));
  if (is_imagick_image($img)) {
    $out = rgb_arrays_to_imagick($R,$G,$B_rec,$w,$h);
    $out->setImageFormat('png');
    $ok = $out->writeImage($outPath);
    $out->clear(); $out->destroy();
  } else {
    $gd = rgb_arrays_to_gd($R,$G,$B_rec,$w,$h);
    imagesavealpha($gd, false);
    $ok = imagepng($gd, $outPath, 9);
    imagedestroy($gd);
  }
  return [$ok, $ok ? null : 'Gagal menyimpan file keluaran.'];
}

// ---------- Proses ekstrak ----------
function iwt_extract_image($img): array {
  if (is_imagick_image($img)) { [,,$B,$w,$h] = imagick_to_rgb_arrays($img); }
  elseif (is_gd_image($img))  { [,,$B,$w,$h] = gd_to_rgb_arrays($img); }
  else { return [false, null, 'Tipe gambar tidak dikenal']; }

  $B_tr = iwt_forward($B, $w, $h);

  [$bitsHeader, $got] = iwt_extract_bits_HH($B_tr, $w, $h, 32);
  if ($got < 32) return [false, null, 'Data tidak lengkap (header).'];

  $header = bits_to_bytes($bitsHeader);
  $msg_len = u32le_unpack(substr($header,0,4));
  $needBits = $msg_len * 8;

  [$bitsMsg, $got2] = iwt_extract_bits_HH($B_tr, $w, $h, 32 + $needBits);
  if ($got2 < 32 + $needBits) return [false, null, 'Data tidak lengkap (pesan).'];

  $bitsPayload = array_slice($bitsMsg, 32);
  $msg = bits_to_bytes($bitsPayload);
  $msg = preg_replace('/[\x00-\x1F\x7F]+$/', '', $msg);

  $hex = strtoupper(bin2hex($msg));
  $hex = trim(chunk_split($hex, 2, ' '));

  return [true, ['len'=>$msg_len,'text'=>$msg,'hex'=>$hex], null];
}

// ---------- Handler ----------
$embedResult = null;
$extractResult = null;
$errors = [];
$keepMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate($_POST['csrf'] ?? '')) {
    $errors[] = 'Sesi tidak valid. Muat ulang halaman.';
  } else {
    if (($_POST['action'] ?? '') === 'embed') {
      $keepMessage = (string)($_POST['message'] ?? '');
      if (!isset($_FILES['cover']) || $_FILES['cover']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload gambar terlebih dahulu.';
      } else {
        [$mime, $fmt] = detect_image($_FILES['cover']['tmp_name']);
        $okFmt = in_array($fmt, ['png','bmp','webp','jpeg','tiff'], true);
        if (!$okFmt) {
          $errors[] = 'Format tidak didukung. Gunakan PNG, BMP, WebP, JPEG, atau TIFF.';
        } else {
          $img = load_image_any($_FILES['cover']['tmp_name'], $fmt);
          if (!$img) {
            $errors[] = 'Gagal memuat gambar. Aktifkan Imagick untuk JPEG/TIFF atau GD untuk PNG/BMP/WebP.';
          } else {
            $message = trim((string)($_POST['message'] ?? ''));
            if ($message === '') {
              $errors[] = 'Teks pesan tidak boleh kosong.';
            } else {
              ensure_dir(__DIR__.'/outputs');
              $outName = 'iwt_'.date('Ymd_His').'_'.bin2hex(random_bytes(3)).'.png';
              $outPath = __DIR__.'/outputs/'.$outName;

              [$ok, $err] = iwt_embed_image($img, $message, $outPath);
              if ($ok) { $embedResult = 'outputs/'.$outName; $keepMessage=''; }
              else { $errors[] = $err ?: 'Gagal menyisipkan pesan (IWT).'; }
            }
            if (is_gd_image($img)) { imagedestroy($img); }
          }
        }
      }
    }

    if (($_POST['action'] ?? '') === 'extract') {
      if (!isset($_FILES['stego']) || $_FILES['stego']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload gambar yang berisi pesan.';
      } else {
        [$mime, $fmt] = detect_image($_FILES['stego']['tmp_name']);
        $okFmt = in_array($fmt, ['png','bmp','webp','jpeg','tiff'], true);
        if (!$okFmt) {
          $errors[] = 'Format tidak didukung. Gunakan PNG, BMP, WebP, JPEG, atau TIFF.';
        } else {
          $img = load_image_any($_FILES['stego']['tmp_name'], $fmt);
          if (!$img) {
            $errors[] = 'Gagal memuat gambar untuk ekstraksi.';
          } else {
            [$ok, $data, $err] = iwt_extract_image($img);
            if ($ok) $extractResult = $data;
            else $errors[] = $err ?: 'Gagal mengekstrak pesan (IWT).';
            if (is_gd_image($img)) { imagedestroy($img); }
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
  <title>IWT Steganografi | StegApp</title>
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

    .left{ display:flex; align-items:center; gap: 12px; flex-wrap:wrap; }

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

    .grid{ display:grid; grid-template-columns: 1fr 1fr; gap: 12px; }

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
      outline:none;
    }
    textarea{ min-height: 160px; resize: vertical; }
    input[type="file"]:focus, textarea:focus{
      border-color: rgba(167,108,255,.55);
      box-shadow: 0 0 0 4px var(--focus);
    }
    textarea::placeholder{ color: rgba(238,241,255,.45); }

    .muted{ color: var(--muted); font-size: .93rem; line-height: 1.45; margin: 8px 0 0; }
    code{
      background: rgba(8,10,18,.55);
      border: 1px solid var(--surfaceBorder);
      padding: 2px 7px;
      border-radius: 8px;
      color: rgba(238,241,255,.95);
      font-size: .92rem;
    }

    .btn{
      margin-top: 12px;
      padding: 12px 14px;
      border:0;
      border-radius: 12px;
      cursor:pointer;
      font-weight:900;
      color:#0b0b14;
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
        <h1 class="title">IWT Steganografi</h1>
        <span class="chip">Haar • 1-Level • HH(B)</span>
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
      <strong>Dukungan format:</strong>
      Input <b>PNG</b>, <b>BMP</b>, <b>WebP</b> (GD) + <b>JPEG</b>, <b>TIFF</b> (butuh <code>Imagick</code>).
      Output selalu <b>PNG</b> agar payload aman dari kompresi lossy.<br>
      <span class="muted">Payload disisipkan pada subband <b>HH</b> kanal <b>B</b> (lebih robust dibanding spatial LSB).</span>
    </div>

    <div class="grid">
      <!-- EMBED -->
      <section class="card">
        <h3>Sisip Teks (IWT)</h3>

        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="embed">

          <label>Gambar sumber (PNG / BMP / WebP / JPEG / TIFF)</label>
          <input type="file" name="cover" accept="image/png,image/bmp,image/x-ms-bmp,image/webp,image/jpeg,image/tiff" required>

          <label>Teks yang disisipkan</label>
          <textarea name="message" placeholder="Tulis pesan rahasia..." required><?= htmlspecialchars($keepMessage, ENT_QUOTES, 'UTF-8') ?></textarea>

          <p class="muted">
            Kapasitas kira-kira: <code>⌊lebar/2⌋ × ⌊tinggi/2⌋</code> bit (HH, 1 channel) − 32 bit header.
          </p>

          <button class="btn" type="submit">Sisipkan</button>
        </form>

        <?php if ($embedResult): ?>
          <div class="alert ok" role="status" style="margin-top:12px;">
            <div><strong>Berhasil!</strong> Stego PNG siap diunduh.</div>
            <div style="margin-top:8px;">
              <a class="btnlink" href="<?= htmlspecialchars($embedResult, ENT_QUOTES, 'UTF-8') ?>" download>Unduh stego PNG</a>
              <span class="chip" style="margin-left:8px;"><?= htmlspecialchars($embedResult, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="muted" style="margin-top:8px;">
              Gunakan file hasil ini untuk ekstrak di panel kanan.
            </div>
          </div>
          <img class="preview" src="<?= htmlspecialchars($embedResult, ENT_QUOTES, 'UTF-8') ?>" alt="Hasil stego IWT">
        <?php endif; ?>
      </section>

      <!-- EXTRACT -->
      <section class="card">
        <h3>Ekstrak Teks (IWT)</h3>

        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="extract">

          <label>Gambar stego (PNG / BMP / WebP / JPEG / TIFF)</label>
          <input type="file" name="stego" accept="image/png,image/bmp,image/x-ms-bmp,image/webp,image/jpeg,image/tiff" required>

          <button class="btn" type="submit">Ekstrak</button>
        </form>

        <?php if ($extractResult !== null): ?>
          <div class="alert ok" role="status" style="margin-top:12px;">
            <div><strong>Pesan ditemukan (<?= (int)$extractResult['len'] ?> byte)</strong></div>
            <pre class="mono" style="white-space:pre-wrap;word-wrap:break-word;margin:10px 0 0"><?= htmlspecialchars($extractResult['text'], ENT_QUOTES, 'UTF-8') ?></pre>

            <details>
              <summary>Detail hexdump <span class="caret">⌄</span></summary>
              <div class="mono" style="margin-top:10px;line-height:1.55;">
                <?= htmlspecialchars($extractResult['hex'], ENT_QUOTES, 'UTF-8') ?>
              </div>
            </details>
          </div>
        <?php endif; ?>
      </section>
    </div>

    <p class="foot">
      Rumus kasar kapasitas: <code>(⌊w/2⌋ × ⌊h/2⌋ / 8) − 4</code> byte (4 byte header panjang pesan).
    </p>
  </div>
</body>
</html>
