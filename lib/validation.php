<?php
// dashboard/lib/validation.php
// Validaciones de negocio centralizadas (usadas por todos los endpoints financieros).

require_once __DIR__ . '/money.php';

const ACCOUNTS = ['PERSONAL', 'BEATAN'];
const DEBT_TYPES = ['A FAVOR', 'EN CONTRA'];

/** Fecha estricta YYYY-MM-DD existente en el calendario (rechaza 2025-02-30). */
function valid_date($value): ?string
{
  if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m))
    return null;
  [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
  if ($y < 1970 || $y > 2100 || !checkdate($mo, $d, $y))
    return null;
  return $value;
}

/** Mes YYYY-MM. */
function valid_month($value): ?string
{
  if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})$/', $value, $m))
    return null;
  $y = (int) $m[1];
  $mo = (int) $m[2];
  if ($y < 1970 || $y > 2100 || $mo < 1 || $mo > 12)
    return null;
  return $value;
}

/** Primer y último día de un mes YYYY-MM (respeta meses de 28-31 días). */
function month_bounds(string $month): array
{
  $start = $month . '-01';
  $end = date('Y-m-t', strtotime($start));
  return [$start, $end];
}

function valid_enum($value, array $allowed): ?string
{
  if (!is_string($value))
    return null;
  $v = strtoupper(trim($value));
  return in_array($v, $allowed, true) ? $v : null;
}

/** Texto limpio: sin caracteres de control y recortado a la longitud de la columna. */
function clean_text($value, int $maxLen): string
{
  if (!is_scalar($value))
    return '';
  $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '';
  $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');
  return mb_substr($v, 0, $maxLen);
}

function valid_int_range($value, int $min, int $max): ?int
{
  if (is_int($value))
    $n = $value;
  elseif (is_string($value) && preg_match('/^-?\d+$/', trim($value)))
    $n = (int) trim($value);
  elseif (is_float($value) && floor($value) == $value)
    $n = (int) $value;
  else
    return null;
  return ($n >= $min && $n <= $max) ? $n : null;
}

/**
 * Valida un movimiento financiero. Devuelve [datos normalizados, lista de errores].
 * $monto llega siempre positivo desde la interfaz; el signo lo determina el tipo.
 */
function validate_transaction_input(array $in, bool $requireAccount = true): array
{
  $errors = [];

  $account = valid_enum($in['tipgasxx'] ?? '', ACCOUNTS);
  if ($requireAccount && !$account)
    $errors[] = 'Tipo de cuenta inválido';

  $tipo = valid_enum($in['tipo'] ?? '', ['INGRESO', 'GASTO']);
  if (!$tipo)
    $errors[] = 'Indica si es un ingreso o un gasto';

  $cents = money_to_cents($in['monto'] ?? null);
  if ($cents === null || $cents === 0)
    $errors[] = 'El monto debe ser un número mayor que cero (máximo 2 decimales)';
  else
    $cents = abs($cents);

  $categoria = clean_text($in['categoria'] ?? '', 30);
  if ($categoria === '')
    $errors[] = 'La categoría es obligatoria';

  $fecha = valid_date($in['fecha'] ?? '');
  if (!$fecha)
    $errors[] = 'La fecha no es válida';

  $descripcion = clean_text($in['descripcion'] ?? '', 150);

  $deuda = $in['deudidxx'] ?? null;
  $deudaId = ($deuda === null || $deuda === '' || $deuda === 0 || $deuda === '0') ? null : valid_int_range($deuda, 1, PHP_INT_MAX);
  if ($deuda !== null && $deuda !== '' && $deuda !== 0 && $deuda !== '0' && $deudaId === null)
    $errors[] = 'Deuda asociada inválida';

  $signed = ($cents !== null && $tipo === 'GASTO') ? -$cents : $cents;

  return [[
    'tipgasxx' => $account,
    'tipo' => $tipo,
    'cents' => $signed,
    'categoria' => $categoria,
    'descripcion' => $descripcion,
    'fecha' => $fecha,
    'deudidxx' => $deudaId,
  ], $errors];
}
