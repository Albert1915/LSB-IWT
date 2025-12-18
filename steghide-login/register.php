<?php
require __DIR__ . '/config.php';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_validate($_POST['csrf'] ?? '')) {
    $errors[] = 'Sesi tidak valid. Muat ulang halaman dan coba lagi.';
  }

  $email = trim($_POST['email'] ?? '');
  $password = $_POST['password'] ?? '';

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Alamat email tidak valid.';
  }
  if (strlen($password) < 6) {
    $errors[] = 'Password minimal 6 karakter.';
  }

  if (!$errors) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);

    if ($stmt->fetch()) {
      $errors[] = 'Email sudah terdaftar.';
    } else {
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("INSERT INTO users (email, password_hash) VALUES (?, ?)");
      $stmt->execute([$email, $hash]);

      $uid = $pdo->lastInsertId();
      session_regenerate_id(true);
      $_SESSION['uid'] = (int)$uid;
      $_SESSION['email'] = $email;

      redirect('dashboard.php');
    }
  }
}

$csrf = csrf_token();
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <title>Register | StegApp</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">

  <style>
    :root{
      --bg1:#12002a;
      --bg2:#070013;
      --card: rgba(18, 20, 34, .78);
      --border: rgba(255,255,255,.10);
      --text:#eef1ff;
      --muted:#a9b1c7;
      --field: rgba(8,10,18,.65);
      --fieldBorder: rgba(255,255,255,.10);
      --focus: rgba(167, 108, 255, .35);
      --primary1:#7c3aed;
      --primary2:#3b82f6;
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

    .wrap{
      width: min(92vw, 420px);
      position:relative;
    }

    .card{
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 18px;
      padding: 26px 24px;
      box-shadow: 0 18px 60px rgba(0,0,0,.45);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
    }

    .title{
      margin:0 0 6px;
      font-size: 1.9rem;
      letter-spacing: .2px;
    }

    .subtitle{
      margin:0 0 18px;
      color: var(--muted);
      font-size: .95rem;
      line-height: 1.35;
    }

    .alert{
      border-radius: 12px;
      padding: 10px 12px;
      margin: 0 0 12px;
      border: 1px solid;
      font-size: .95rem;
      line-height: 1.35;
    }
    .alert.error{
      background: rgba(180, 28, 43, .16);
      border-color: rgba(255, 77, 92, .35);
      color: #ffd1d6;
    }
    .alert.notice{
      background: rgba(16, 185, 129, .12);
      border-color: rgba(16, 185, 129, .35);
      color: #c9ffe7;
    }
    .alert div + div{ margin-top: 6px; }

    form{ display:grid; gap: 14px; margin-top: 10px; }

    .field{ display:grid; gap: 6px; }
    label{
      font-size: .9rem;
      color: #dbe2ff;
    }

    input[type="email"], input[type="password"]{
      width:100%;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid var(--fieldBorder);
      background: var(--field);
      color: var(--text);
      outline: none;
      transition: border-color .15s ease, box-shadow .15s ease;
    }
    input::placeholder{ color: rgba(238,241,255,.45); }
    input:focus{
      border-color: rgba(167,108,255,.55);
      box-shadow: 0 0 0 4px var(--focus);
    }

    .btn{
      width:100%;
      padding: 12px 14px;
      border: 0;
      border-radius: 12px;
      cursor: pointer;
      font-weight: 700;
      color: #0b0b14;
      background: linear-gradient(90deg, var(--primary1), var(--primary2));
      box-shadow: 0 12px 26px rgba(124,58,237,.25);
      transition: transform .08s ease, filter .15s ease;
    }
    .btn:hover{ filter: brightness(1.05); }
    .btn:active{ transform: translateY(1px); }

    .row{
      display:flex;
      align-items:center;
      justify-content:space-between;
      gap: 12px;
      padding-top: 4px;
    }
    .muted{
      color: var(--muted);
      font-size: .92rem;
    }
    a{
      color: #c9b6ff;
      text-decoration: none;
      font-weight: 600;
    }
    a:hover{ text-decoration: underline; }

    @media (max-width: 360px){
      .card{ padding: 22px 18px; }
      .title{ font-size: 1.6rem; }
    }
  </style>
</head>

<body>
  <div class="wrap">
    <main class="card" role="main" aria-labelledby="title">
      <h1 class="title" id="title">Buat Akun</h1>

      <?php if (!empty($errors)): ?>
        <div class="alert error" role="alert">
          <?php foreach ($errors as $e): ?>
            <div><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert notice" role="status">
          <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <form method="post" action="register.php" novalidate>
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

        <div class="field">
          <label for="email">Email</label>
          <input
            id="email"
            type="email"
            name="email"
            placeholder="nama@domain.com"
            required
            autocomplete="email"
            value="<?= isset($email) ? htmlspecialchars($email, ENT_QUOTES, 'UTF-8') : '' ?>"
          >
        </div>

        <div class="field">
          <label for="password">Password</label>
          <input
            id="password"
            type="password"
            name="password"
            placeholder="minimal 6 karakter"
            required
            autocomplete="new-password"
          >
        </div>

        <button class="btn" type="submit">Daftar</button>

        <div class="row">
          <span class="muted">Sudah punya akun?</span>
          <a href="login.php">Login</a>
        </div>
      </form>
    </main>
  </div>
</body>
</html>
