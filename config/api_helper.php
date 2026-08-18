<?php
// dashboard/config/api_helper.php
// Helper centralizado para estandarización de respuestas API y utilidades de seguridad

if (!function_exists('api_response')) {
    /**
     * Emite una respuesta JSON estructurada y finaliza la ejecución.
     */
    function api_response(bool $success, $dataOrError = null, int $statusCode = 200, array $extra = []): void {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($statusCode);
        }
        
        $response = ['success' => $success];
        
        if ($success) {
            $response['data'] = $dataOrError;
        } else {
            $response['error'] = is_string($dataOrError) ? $dataOrError : 'Ocurrió un error inesperado';
        }

        if (!empty($extra)) {
            $response = array_merge($response, $extra);
        }

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('api_require_auth')) {
    /**
     * Verifica que exista una sesión activa. Si no, retorna error 401.
     */
    function api_require_auth(): array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            api_response(false, 'No autorizado - Inicie sesión para continuar', 401);
        }
        
        return [
            'user_id' => $_SESSION['user_id'] ?? null,
            'user_name' => $_SESSION['user_name'] ?? 'Usuario',
            'is_admin' => $_SESSION['is_admin'] ?? false
        ];
    }
}

if (!function_exists('api_get_json_input')) {
    /**
     * Decodifica el cuerpo de la petición JSON entrante de forma segura.
     */
    function api_get_json_input(): array {
        $raw = file_get_contents('php://input');
        if (empty($raw)) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
