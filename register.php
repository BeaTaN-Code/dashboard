<?php
// dashboard/register.php
require_once __DIR__ . '/config/auth.php';
initSecureSession();
setSecurityHeaders();

$error = '';
$formData = [];

// Si ya esta logueado, redirigir al dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
  header('Location: index.php');
  exit;
}

$csrfToken = generateCsrfToken();

// Procesar registro
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Validate CSRF
  $submittedToken = $_POST['csrf_token'] ?? '';
  if (!validateCsrfToken($submittedToken)) {
    $error = 'Token de seguridad invalido. Recarga la pagina.';
  } else {
    $formData = [
      'cedula' => trim($_POST['cedula'] ?? ''),
      'nombre' => trim($_POST['nombre'] ?? ''),
      'email' => trim($_POST['email'] ?? ''),
      'celular' => trim($_POST['celular'] ?? ''),
      'password' => $_POST['password'] ?? '',
      'confirm_password' => $_POST['confirm_password'] ?? ''
    ];

    // Validaciones
    if (empty($formData['cedula']) || empty($formData['nombre']) || empty($formData['email']) || empty($formData['password'])) {
      $error = 'Por favor complete todos los campos obligatorios';
    } elseif (!preg_match('/^\d{5,15}$/', $formData['cedula'])) {
      $error = 'La cedula debe contener solo numeros (5-15 digitos)';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
      $error = 'Por favor ingrese un email valido';
    } elseif (strlen($formData['password']) < 8) {
      $error = 'La contrasena debe tener al menos 8 caracteres';
    } elseif (!preg_match('/[A-Z]/', $formData['password']) || !preg_match('/[0-9]/', $formData['password'])) {
      $error = 'La contrasena debe contener al menos una mayuscula y un numero';
    } elseif ($formData['password'] !== $formData['confirm_password']) {
      $error = 'Las contrasenas no coinciden';
    } else {
      $result = registerUser($formData);

      if ($result['success']) {
        header('Location: login.php?registered=1');
        exit;
      } else {
        $error = $result['error'];
      }
    }
  }
  $csrfToken = generateCsrfToken();
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Registro - BeaTaNCode Dashboard</title>
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
        <h1>Crear Cuenta</h1>
        <p>Únete a BeaTaNCode</p>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-error">
          <i class="bi bi-exclamation-triangle"></i>
          <?php echo htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>

      <form method="POST" class="auth-form" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <div class="form-row">
          <div class="form-group">
            <label for="cedula">
              <i class="bi bi-person-badge"></i>
              Cedula *
            </label>
            <div class="password-input">
              <input type="text" id="cedula" name="cedula" placeholder="Tu número de cédula"
                value="<?php echo htmlspecialchars($formData['cedula'] ?? ''); ?>" required>
            </div>
          </div>

          <div class="form-group">
            <label for="nombre">
              <i class="bi bi-person"></i>
              Nombre *
            </label>
            <div class="password-input">
              <input type="text" id="nombre" name="nombre" placeholder="Tu nombre completo"
                value="<?php echo htmlspecialchars($formData['nombre'] ?? ''); ?>" required>
            </div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="email">
              <i class="bi bi-envelope"></i>
              Email *
            </label>
            <div class="password-input">
              <input type="email" id="email" name="email" placeholder="tu@email.com"
                value="<?php echo htmlspecialchars($formData['email'] ?? ''); ?>" required>
            </div>
          </div>

          <div class="form-group">
            <label for="celular">
              <i class="bi bi-phone"></i>
              Celular
            </label>
            <div class="password-input">
              <input type="tel" id="celular" name="celular" placeholder="+57 999 9999999"
                value="<?php echo htmlspecialchars($formData['celular'] ?? ''); ?>">
            </div>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="password">
              <i class="bi bi-lock"></i>
              Contraseña *
            </label>
            <div class="password-input">
              <input type="password" id="password" name="password" placeholder="Mínimo 6 caracteres" required>
              <button type="button" class="toggle-password" onclick="togglePassword('password')">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>

          <div class="form-group">
            <label for="confirm_password">
              <i class="bi bi-lock-fill"></i>
              Confirmar Contraseña *
            </label>
            <div class="password-input">
              <input type="password" id="confirm_password" name="confirm_password" placeholder="Repite tu contraseña"
                required>
              <button type="button" class="toggle-password" onclick="togglePassword('confirm_password')">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>
        </div>

        <button type="submit" class="btn-primary">
          <i class="bi bi-person-plus"></i>
          Crear Cuenta
        </button>
      </form>

      <div class="auth-footer">
        <p>¿Ya tienes cuenta? <a href="login.php">Inicia sesión</a></p>
      </div>
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
<script>
  const input = document.getElementById("celular");

  input.addEventListener("input", function (e) {
    let value = input.value.replace(/\D/g, ""); // solo números

    if (value.startsWith("57")) {
      value = value.substring(2);
    }

    let formatted = "+57 ";

    if (value.length > 0) {
      formatted += value.substring(0, 3);
    }
    if (value.length >= 4) {
      formatted += " " + value.substring(3, 10);
    }

    input.value = formatted;
  });
</script>

</html>
