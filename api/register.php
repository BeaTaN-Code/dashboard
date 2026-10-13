<?php
// dashboard/api/register.php
// API para registro de usuarios desde la aplicación móvil

require_once __DIR__ . '/../config/api_helper.php';
require_once __DIR__ . '/../config/auth.php';

api_bootstrap(false);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  api_response(false, 'Método no permitido', 405);
}

$input = api_get_json_input();
$data = [
  'cedula' => trim((string) ($input['cedula'] ?? '')),
  'nombre' => trim((string) ($input['nombre'] ?? '')),
  'email' => trim((string) ($input['email'] ?? '')),
  'celular' => trim((string) ($input['celular'] ?? '')),
  'password' => (string) ($input['password'] ?? ''),
];

$error = validateRegistration($data);
if ($error) {
  api_json(['success' => false, 'error' => $error], 400);
}

$result = registerUser($data);

if ($result['success']) {
  api_json(['success' => true, 'message' => 'Usuario registrado exitosamente']);
}
api_json(['success' => false, 'error' => $result['error']], 400);
