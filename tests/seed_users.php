<?php
// dashboard/tests/seed_users.php
// Credenciales SOLO para la base SQLite local de pruebas (tests/tmp/test.sqlite).
// No existen en la base de datos real.

const TEST_USERS = [
  'admin' => ['cedula' => '1001', 'password' => 'PruebaAdmin#2026', 'nombre' => 'Admin Demo', 'email' => 'admin@example.test', 'admin' => true],
  'user' => ['cedula' => '2002', 'password' => 'PruebaUsuario#2026', 'nombre' => 'Laura Demo', 'email' => 'laura@example.test', 'admin' => false],
  'other' => ['cedula' => '3003', 'password' => 'PruebaOtro#2026', 'nombre' => 'Otro Demo', 'email' => 'otro@example.test', 'admin' => false],
];
