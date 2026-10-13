<?php
// dashboard/lib/money.php
// Representación monetaria exacta: todos los cálculos internos se hacen en centavos (int).
// La BD guarda DECIMAL(12,2), cuyo máximo es 9.999.999.999,99.

const MONEY_MAX_CENTS = 999999999999;

/**
 * Convierte un valor de entrada (int, float o string decimal con punto) a centavos.
 * Devuelve null si el valor no es un importe válido.
 */
function money_to_cents($value): ?int
{
  if (is_int($value)) {
    $cents = $value * 100;
  } elseif (is_float($value)) {
    if (!is_finite($value))
      return null;
    $cents = (int) round($value * 100);
  } elseif (is_string($value)) {
    $v = str_replace([' ', "\u{00A0}"], '', trim($value));
    if (!preg_match('/^(-)?(\d{1,13})(?:\.(\d{1,2}))?$/', $v, $m))
      return null;
    $cents = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '0', 2, '0');
    if ($m[1] === '-')
      $cents = -$cents;
  } else {
    return null;
  }
  if (abs($cents) > MONEY_MAX_CENTS)
    return null;
  return $cents;
}

/** Valor leído de la BD (DECIMAL como string, o número en SQLite) a centavos. Nulo => 0. */
function db_money_to_cents($value): int
{
  if ($value === null || $value === '')
    return 0;
  if (is_string($value) && preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', trim($value), $m)) {
    $frac = substr(str_pad($m[3] ?? '0', 3, '0'), 0, 3);
    // Redondeo half-up del tercer decimal (DECIMAL(12,2) nunca lo tiene, SUM sí puede en otros motores)
    $cents = ((int) $m[2]) * 100 + intdiv((int) $frac + 5, 10);
    return $m[1] === '-' ? -$cents : $cents;
  }
  return (int) round(((float) $value) * 100);
}

/** Centavos a string decimal apto para la BD: 123456 => "1234.56". */
function cents_to_decimal(int $cents): string
{
  $sign = $cents < 0 ? '-' : '';
  $abs = abs($cents);
  return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
}

/** Centavos a float para mantener el contrato JSON histórico (solo presentación). */
function cents_to_float(int $cents): float
{
  return round($cents / 100, 2);
}

/** Formato COP legible: 1234567 centavos => "$12.346" (sin decimales, como la interfaz). */
function cents_format_cop(int $cents, bool $withDecimals = false): string
{
  $value = $cents / 100;
  $formatted = number_format(abs($value), $withDecimals ? 2 : 0, ',', '.');
  return ($cents < 0 ? '-' : '') . '$' . $formatted;
}

/** Porcentaje de variación seguro: null cuando la base es 0 (evita porcentajes engañosos). */
function pct_change(int $current, int $previous): ?float
{
  if ($previous === 0)
    return null;
  return round((($current - $previous) / abs($previous)) * 100, 1);
}
