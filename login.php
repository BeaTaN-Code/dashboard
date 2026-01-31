<?php
// dashboard/login.php
session_start();
require_once __DIR__ . '/config/auth.php';

$error = '';
$success = '';

// Si ya está logueado, redirigir al dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
  header('Location: index.php');
  exit;
}

// Procesar login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $cedula = trim($_POST['cedula'] ?? '');
  $password = $_POST['password'] ?? '';

  if (empty($cedula) || empty($password)) {
    $error = 'Por favor complete todos los campos';
  } else {
    $result = loginUser($cedula, $password);

    if ($result['success']) {
      header('Location: index.php');
      exit;
    } else {
      $error = $result['error'];
    }
  }
}

// Mensaje de registro exitoso
if (isset($_GET['registered']) && $_GET['registered'] === '1') {
  $success = 'Registro exitoso. Por favor inicie sesión.';
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Iniciar Sesión - BeaTaNCode Dashboard</title>
  <link rel="stylesheet" href="css/dashboard.css">
  <link rel="icon" type="image/png" href="img/Logo.png">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>

<body class="auth-body">
  <div class="auth-container">
    <div class="auth-card">
      <div class="auth-header">
        <img src="img/Logo.png" alt="Logo BeaTaNCode" class="auth-logo">
        <h1>Iniciar Sesión</h1>
        <p>Accede a tu panel de control</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-error">
          <i class="bi bi-exclamation-triangle"></i>
          <?php echo htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert alert-success">
          <i class="bi bi-check-circle"></i>
          <?php echo htmlspecialchars($success); ?>
        </div>
      <?php endif; ?>

      <form method="POST" class="auth-form">
        <div class="form-group">
          <label for="cedula">
            <i class="bi bi-person-badge"></i>
            Cédula
          </label>
          <input type="text" id="cedula" name="cedula" placeholder="Ingresa tu cédula"
            value="<?php echo htmlspecialchars($_POST['cedula'] ?? ''); ?>" required>
        </div>

        <div class="form-group">
          <label for="password">
            <i class="bi bi-lock"></i>
            Contraseña
          </label>
          <div class="password-input">
            <input type="password" id="password" name="password" placeholder="Ingresa tu contraseña" required>
            <button type="button" class="toggle-password" onclick="togglePassword('password')">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-primary">
          <i class="bi bi-box-arrow-in-right"></i>
          Iniciar Sesión
        </button>
      </form>

    </div>
  </div>

  <script>
    function togglePassword(inputId) {
      const input = document.getElementById(inputId);
      const icon = input.parentElement.querySelector('.toggle-password i');

      if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
      } else {
        input.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
      }
    }
  </script>
</body>

</html>