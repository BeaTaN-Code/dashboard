<?php
// dashboard/api/recover_password.php
// Recuperación de contraseña mediante clave temporal enviada por correo.
//
// Correcciones de seguridad:
//  - Antes, si el correo fallaba, la respuesta incluía la contraseña temporal (toma de cuenta).
//  - La contraseña solo cambia si el correo se envía; si falla, se revierte.
//  - Respuesta idéntica exista o no el usuario (evita enumerar cédulas/correos).
//  - Generador criptográfico (random_int) y límite de solicitudes.

require_once __DIR__ . '/../config/api_helper.php';

api_bootstrap(false);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  api_response(false, 'Método no permitido', 405);
}
rate_limit_or_fail('recover', 5, 3600);

$input = api_get_json_input();
$cedula = trim((string) ($input['cedula'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));

if ($cedula === '' || $email === '') {
  api_json(['success' => false, 'error' => 'Por favor complete todos los campos obligatorios'], 400);
}

$generic = 'Si los datos coinciden con una cuenta activa, recibirás un correo con una contraseña temporal en unos minutos.';
$pdo = api_db();

try {
  $stmt = $pdo->prepare("SELECT USRIDXXX, USRNMEXX, USRMAILX FROM BEATUSRS WHERE USRIDXXX = ? AND USRMAILX = ? AND REGESTXX = 'ACTIVO'");
  $stmt->execute([$cedula, $email]);
  $user = $stmt->fetch();

  if (!$user) {
    api_json(['success' => true, 'message' => $generic]);
  }

  $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $tempPass = '';
  for ($i = 0; $i < 12; $i++) {
    $tempPass .= $chars[random_int(0, strlen($chars) - 1)];
  }

  $pdo->beginTransaction();
  $update = $pdo->prepare("UPDATE BEATUSRS SET USRPASXX = ?, REGFECMX = ?, REGHORMX = ? WHERE USRIDXXX = ?");
  $update->execute([password_hash($tempPass, PASSWORD_DEFAULT), date('Y-m-d'), date('H:i:s'), $user['USRIDXXX']]);

  $subject = "Recuperacion de contrasena - BeaTaN Nexus";
  $message = "Hola " . preg_replace('/[\r\n]+/', ' ', (string) $user['USRNMEXX']) . ",\r\n\r\n";
  $message .= "Se ha solicitado una recuperacion de contrasena para tu cuenta de BeaTaN Nexus.\r\n";
  $message .= "Tu contrasena temporal es: " . $tempPass . "\r\n\r\n";
  $message .= "Inicia sesion con esta contrasena y cambiala de inmediato desde tu perfil.\r\n";
  $message .= "Si no solicitaste este cambio, contacta al administrador.\r\n\r\n";
  $message .= "Saludos,\r\nEl equipo de BeaTaN Nexus";
  $headers = "From: no-reply@beatancode.com\r\nReply-To: no-reply@beatancode.com\r\nContent-Type: text/plain; charset=UTF-8";

  // Se usa el correo guardado (no el escrito por el usuario) para evitar inyección de cabeceras.
  $mailSent = @mail($user['USRMAILX'], $subject, $message, $headers);

  if (!$mailSent) {
    $pdo->rollBack();
    error_log('recover_password: mail() falló; la contraseña no se modificó');
    api_json(['success' => false, 'error' => 'No pudimos enviar el correo en este momento. Inténtalo más tarde o contacta al administrador.'], 503);
  }

  $pdo->commit();
  api_json(['success' => true, 'message' => $generic]);

} catch (Exception $e) {
  if ($pdo->inTransaction())
    $pdo->rollBack();
  api_log_error('recover_password', $e);
  api_json(['success' => false, 'error' => 'No se pudo procesar la solicitud'], 500);
}
