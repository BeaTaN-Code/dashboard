<?php
// dashboard/api/login.php
// API para login de la aplicación móvil y clientes API

header('Content-Type: application/json');
require_once __DIR__ . '/../config/auth.php';

// Permitir tanto JSON como datos de formulario POST tradicionales
$input = json_decode(file_get_contents('php://input'), true);
$cedula = trim($input['cedula'] ?? $_POST['cedula'] ?? '');
$password = $input['password'] ?? $_POST['password'] ?? '';

if (empty($cedula) || empty($password)) {
  http_response_code(400);
  echo json_encode([
    'success' => false,
    'error' => 'Por favor, ingrese cédula y contraseña'
  ]);
  exit;
}

$result = loginUser($cedula, $password);

if ($result['success']) {
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }
  
  $sessionId = session_id();
  $user = $result['user'];
  
  echo json_encode([
    'success' => true,
    'session_id' => $sessionId,
    'user' => [
      'id' => $user['USRIDXXX'],
      'name' => $user['USRNMEXX'],
      'email' => $user['USRMAILX'],
      'celular' => $user['USRCELUL'],
      'is_admin' => ($user['ISADMINX'] === 'SI')
    ]
  ]);
} else {
  http_response_code(401);
  echo json_encode([
    'success' => false,
    'error' => $result['error']
  ]);
}
