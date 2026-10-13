<?php
// dashboard/register.php
require_once __DIR__ . '/config/auth.php';

app_send_security_headers(false);
app_start_session();

$error = '';
$formData = [];

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
  header('Location: index.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $formData = [
    'cedula' => trim($_POST['cedula'] ?? ''),
    'nombre' => trim($_POST['nombre'] ?? ''),
    'email' => trim($_POST['email'] ?? ''),
    'celular' => trim($_POST['celular'] ?? ''),
    'password' => $_POST['password'] ?? '',
    'confirm_password' => $_POST['confirm_password'] ?? ''
  ];

  if (!app_csrf_valid($_POST['csrf_token'] ?? null)) {
    $error = 'La página estuvo abierta mucho tiempo. Inténtalo de nuevo.';
  } elseif ($e = validateRegistration($formData)) {
    $error = $e;
  } elseif ($formData['password'] !== $formData['confirm_password']) {
    $error = 'Las contraseñas no coinciden';
  } else {
    $result = registerUser($formData);
    if ($result['success']) {
      header('Location: login.php?registered=1');
      exit;
    }
    $error = $result['error'];
  }
}
$csrf = app_csrf_token();
$v = fn($k) => htmlspecialchars($formData[$k] ?? '');
?>
<!DOCTYPE html>
<html lang="es-CO">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light dark">
  <title>Crear cuenta · BeaTaN</title>
  <link rel="icon" type="image/png" href="img/Logo.png">
  <script>try { var t = localStorage.getItem('beatan_theme'); if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t); } catch (e) { }</script>
  <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="css/dashboard.css?v=<?= @filemtime(__DIR__ . '/css/dashboard.css') ?>">
</head>

<body class="auth-body">
  <main class="auth-container wide">
    <div class="auth-card">
      <div class="auth-header">
        <img src="img/Logo.png" alt="BeaTaN" class="auth-logo" width="56" height="56">
        <h1>Crea tu cuenta</h1>
        <p>Empieza a ordenar tus finanzas en minutos</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span><?= htmlspecialchars($error) ?></span></div>
      <?php endif; ?>

      <form method="POST" class="auth-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <div class="form-row">
          <div class="field">
            <label for="cedula">Cédula *</label>
            <input class="input" type="text" id="cedula" name="cedula" inputmode="numeric" maxlength="15" autocomplete="username" required value="<?= $v('cedula') ?>">
          </div>
          <div class="field">
            <label for="nombre">Nombre *</label>
            <input class="input" type="text" id="nombre" name="nombre" maxlength="50" autocomplete="name" required value="<?= $v('nombre') ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="field">
            <label for="email">Email *</label>
            <input class="input" type="email" id="email" name="email" maxlength="150" autocomplete="email" required value="<?= $v('email') ?>">
          </div>
          <div class="field">
            <label for="celular">Celular</label>
            <input class="input" type="tel" id="celular" name="celular" inputmode="tel" maxlength="20" autocomplete="tel" placeholder="+57 300 0000000" value="<?= $v('celular') ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="field">
            <label for="password">Contraseña *</label>
            <div class="password-input">
              <input class="input" type="password" id="password" name="password" minlength="8" autocomplete="new-password" required aria-describedby="pwHint">
              <button type="button" class="toggle-password" data-toggle="password" aria-label="Mostrar contraseña" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
            <span class="hint" id="pwHint">Mínimo 8 caracteres.</span>
          </div>
          <div class="field">
            <label for="confirm_password">Confirmar contraseña *</label>
            <div class="password-input">
              <input class="input" type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required>
              <button type="button" class="toggle-password" data-toggle="confirm_password" aria-label="Mostrar contraseña" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
          </div>
        </div>
        <button type="submit" class="btn-primary btn-block">Crear cuenta</button>
      </form>

      <div class="auth-footer">
        <p>¿Ya tienes cuenta? <a href="login.php">Inicia sesión</a></p>
      </div>
    </div>
  </main>
  <script>
    document.querySelectorAll('[data-toggle]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = document.getElementById(btn.dataset.toggle);
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', String(show));
        btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
        btn.querySelector('i').className = 'bi ' + (show ? 'bi-eye-slash' : 'bi-eye');
      });
    });
    var cel = document.getElementById('celular');
    cel.addEventListener('input', function () {
      var value = cel.value.replace(/\D/g, '');
      if (value.startsWith('57')) value = value.substring(2);
      var f = '+57 ';
      if (value.length > 0) f += value.substring(0, 3);
      if (value.length >= 4) f += ' ' + value.substring(3, 10);
      cel.value = f;
    });
  </script>
</body>

</html>
