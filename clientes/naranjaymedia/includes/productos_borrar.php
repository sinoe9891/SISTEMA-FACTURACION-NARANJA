<?php
require_once '../../../includes/db.php';
require_once '../../../includes/session.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id > 0) {
	$stmt = $pdo->prepare("DELETE FROM productos WHERE id = ?");
	$stmt->execute([$id]);
}

header("Location: ../productos");
exit;
