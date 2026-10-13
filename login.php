<?php
// dashboard/login.php
require_once __DIR__ . '/config/auth.php';

app_send_security_headers(false);
app_start_session();

$error = '';
$success = '';

// Si ya está logueado, redirigir al dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
  header('Location: index.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $cedula = trim($_POST['cedula'] ?? '');
  $password = $_POST['password'] ?? '';

  if (!app_csrf_valid($_POST['csrf_token'] ?? null)) {
    $error = 'La página estuvo abierta mucho tiempo. Inténtalo de nuevo.';
  } elseif ($cedula === '' || $password === '') {
    $error = 'Por favor complete todos los campos';
  } else {
    $result = loginUser($cedula, $password);
    if ($result['success']) {
      header('Location: index.php');
      exit;
    }
    $error = $result['error'];
  }
}

if (isset($_GET['registered']) && $_GET['registered'] === '1') {
  $success = 'Registro exitoso. Ya puedes iniciar sesión.';
}
$csrf = app_csrf_token();
?>
<!DOCTYPE html>
<html lang="es-CO">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="color-scheme" content="light dark">
  <title>Iniciar sesión · BeaTaN</title>
  <link rel="icon" type="image/png" href="img/Logo.png">
  <script>try { var t = localStorage.getItem('beatan_theme'); if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t); } catch (e) { }</script>
  <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="css/dashboard.css?v=<?= @filemtime(__DIR__ . '/css/dashboard.css') ?>">
</head>

<body class="auth-body">
  <main class="auth-container">
    <div class="auth-card">
      <div class="auth-header">
        <img src="img/Logo.png" alt="BeaTaN" class="auth-logo" width="56" height="56">
        <h1>Bienvenido de nuevo</h1>
        <p>Inicia sesión para ver tus finanzas</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span><?= htmlspecialchars($error) ?></span></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="alert alert-success" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><span><?= htmlspecialchars($success) ?></span></div>
      <?php endif; ?>

      <form method="POST" class="auth-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <div class="field">
          <label for="cedula">Cédula</label>
          <input class="input" type="text" id="cedula" name="cedula" inputmode="numeric" autocomplete="username" required
            value="<?= htmlspecialchars($_POST['cedula'] ?? '') ?>" <?= $error ? '' : 'autofocus' ?>>
        </div>
        <div class="field">
          <label for="password">Contraseña</label>
          <div class="password-input">
            <input class="input" type="password" id="password" name="password" autocomplete="current-password" required <?= $error ? 'autofocus' : '' ?>>
            <button type="button" class="toggle-password" data-toggle="password" aria-label="Mostrar contraseña" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
          </div>
        </div>
        <button type="submit" class="btn-primary btn-block">Iniciar sesión</button>
      </form>

      <div class="auth-footer">
        <p>¿No tienes cuenta? <a href="register.php">Regístrate</a></p>
      </div>
    </div>
    <p class="auth-legal">Tus datos financieros están protegidos y solo tú puedes verlos.</p>
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
  </script>
</body>

</html>
