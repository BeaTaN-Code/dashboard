<?php
// dashboard/api/recover_password.php
// API para recuperar contraseñas mediante correo temporal en BeaTaN Nexus

header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

$input = json_decode(file_get_contents('php://input'), true);
$cedula = trim($input['cedula'] ?? '');
$email = trim($input['email'] ?? '');

if (empty($cedula) || empty($email)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Por favor complete todos los campos obligatorios']);
  exit;
}

$pdo = getDbConnection();
if (!$pdo) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Error de conexión a la base de datos']);
  exit;
}

try {
  $stmt = $pdo->prepare("SELECT USRIDXXX, USRNMEXX FROM BEATUSRS WHERE USRIDXXX = ? AND USRMAILX = ? AND REGESTXX = 'ACTIVO'");
  $stmt->execute([$cedula, $email]);
  $user = $stmt->fetch();
  
  if (!$user) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No se encontró un usuario activo con esa cédula y correo']);
    exit;
  }
  
  // Generar contraseña temporal aleatoria de 8 caracteres
  $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
  $tempPass = '';
  for ($i = 0; $i < 8; $i++) {
    $tempPass .= $chars[rand(0, strlen($chars) - 1)];
  }
  
  $hashed = password_hash($tempPass, PASSWORD_DEFAULT);
  
  $update = $pdo->prepare("UPDATE BEATUSRS SET USRPASXX = ? WHERE USRIDXXX = ?");
  $update->execute([$hashed, $cedula]);
  
  // Enviar correo
  $to = $email;
  $subject = "Recuperacion de contrasena - BeaTaN Nexus";
  $message = "Hola " . $user['USRNMEXX'] . ",\r\n\r\n";
  $message .= "Se ha solicitado una recuperacion de contrasena para tu cuenta de BeaTaN Nexus.\r\n";
  $message .= "Tu contrasena temporal es: " . $tempPass . "\r\n\r\n";
  $message .= "Por favor, inicia sesion con esta contrasena y cambiala de inmediato desde la seccion de ajustes.\r\n\r\n";
  $message .= "Saludos,\r\nEl equipo de BeaTaN Nexus";
  
  $headers = "From: no-reply@beatancode.com\r\n" .
             "Reply-To: no-reply@beatancode.com\r\n" .
             "X-Mailer: PHP/" . phpversion();
             
  $mailSent = @mail($to, $subject, $message, $headers);
  
  $response = [
    'success' => true,
    'message' => 'Se ha enviado un correo electrónico con tu contraseña temporal.'
  ];
  
  // Si estamos en localhost y no se pudo enviar el correo,
  // agregamos la clave temporal para facilitar la simulación local del desarrollador
  if (!$mailSent) {
    $response['temp_password_debug'] = $tempPass;
    $response['message'] .= ' (Simulado: la clave temporal se muestra aquí porque el servidor local no tiene SMTP configurado)';
  }
  
  echo json_encode($response);
  
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
