<?php
// dashboard/api/register.php
// API para registro de usuarios desde la aplicación móvil

header('Content-Type: application/json');
date_default_timezone_set('America/Bogota');
require_once __DIR__ . '/../config/auth.php';

$input = json_decode(file_get_contents('php://input'), true);

$cedula = trim($input['cedula'] ?? '');
$nombre = trim($input['nombre'] ?? '');
$email = trim($input['email'] ?? '');
$celular = trim($input['celular'] ?? '');
$password = $input['password'] ?? '';

if (empty($cedula) || empty($nombre) || empty($email) || empty($password)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Por favor complete todos los campos obligatorios']);
  exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Por favor ingrese un email válido']);
  exit;
}

if (strlen($password) < 6) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'La contraseña debe tener al menos 6 caracteres']);
  exit;
}

$result = registerUser([
  'cedula' => $cedula,
  'nombre' => $nombre,
  'email' => $email,
  'celular' => $celular,
  'password' => $password
]);

if ($result['success']) {
  echo json_encode(['success' => true, 'message' => 'Usuario registrado exitosamente']);
} else {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => $result['error']]);
}
?>
