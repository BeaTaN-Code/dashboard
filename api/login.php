<?php
// dashboard/api/login.php
// API para login de la aplicación móvil y clientes API

require_once __DIR__ . '/../config/api_helper.php';
require_once __DIR__ . '/../config/auth.php';

api_bootstrap(false); // pre-autenticación: aún no existe token CSRF

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  api_response(false, 'Método no permitido', 405);
}

// Permitir tanto JSON como datos de formulario POST tradicionales
$input = api_get_json_input();
$cedula = trim((string) ($input['cedula'] ?? $_POST['cedula'] ?? ''));
$password = (string) ($input['password'] ?? $_POST['password'] ?? '');

if ($cedula === '' || $password === '') {
  api_json(['success' => false, 'error' => 'Por favor, ingrese cédula y contraseña'], 400);
}

$result = loginUser($cedula, $password);

if ($result['success']) {
  $user = $result['user'];
  api_json([
    'success' => true,
    'session_id' => session_id(),
    'csrf_token' => $_SESSION['csrf_token'],
    'user' => [
      'id' => $user['USRIDXXX'],
      'name' => $user['USRNMEXX'],
      'email' => $user['USRMAILX'],
      'celular' => $user['USRCELUL'],
      'is_admin' => ($user['ISADMINX'] === 'SI')
    ]
  ]);
}

api_json(['success' => false, 'error' => $result['error']], $result['status'] ?? 401);
