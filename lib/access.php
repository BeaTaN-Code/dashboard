<?php
// dashboard/lib/access.php
// Reglas de autorización por cuenta, compartidas por los endpoints financieros.

require_once __DIR__ . '/validation.php';

/**
 * Resuelve la cuenta solicitada. La cuenta BEATAN (empresa) solo es accesible para administradores.
 */
function require_account(array $auth, $requested, string $default = 'PERSONAL'): string
{
  $account = valid_enum($requested === null || $requested === '' ? $default : $requested, ACCOUNTS);
  if (!$account) {
    api_response(false, 'Tipo de cuenta inválido', 400);
  }
  if ($account === 'BEATAN' && !$auth['is_admin']) {
    api_response(false, 'No tienes permiso para la cuenta de la empresa', 403);
  }
  return $account;
}

/** ¿Puede el usuario modificar un registro con este dueño y cuenta? */
function can_modify_record(array $auth, string $ownerId, string $account): bool
{
  return $ownerId === $auth['user_id'] || ($auth['is_admin'] && $account === 'BEATAN');
}

/** Verifica que una deuda exista, esté activa y pertenezca al alcance del usuario. */
function debt_belongs_to(PDO $pdo, int $debtId, array $auth, string $account): bool
{
  $stmt = $pdo->prepare("SELECT USRIDXXX, TIPPERXX FROM DEUDASIX WHERE DEUDIDXX = :id AND REGESTXX = 'ACTIVO'");
  $stmt->execute([':id' => $debtId]);
  $d = $stmt->fetch();
  if (!$d || $d['TIPPERXX'] !== $account)
    return false;
  return can_modify_record($auth, (string) $d['USRIDXXX'], (string) $d['TIPPERXX']);
}

/** Campos de auditoría con la fecha/hora de la aplicación (America/Bogota). */
function audit_now(): array
{
  return [date('Y-m-d'), date('H:i:s')];
}
