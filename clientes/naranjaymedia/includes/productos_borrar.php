<?php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

// Solo POST: evita borrados disparados por un simple enlace
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	exit('Método no permitido.');
}

$cliente_id = (int)(USUARIO_ROL === 'superadmin'
	? ($_SESSION['cliente_seleccionado'] ?? 0)
	: CLIENTE_ID);
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;

if ($id > 0 && $cliente_id) {
	$stmt = $pdo->prepare("DELETE FROM productos WHERE id = ? AND cliente_id = ?");
	$stmt->execute([$id, $cliente_id]);
}

header("Location: ../productos");
exit;
