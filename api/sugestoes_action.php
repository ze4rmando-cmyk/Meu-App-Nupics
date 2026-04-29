<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['tipo'], ['coordenador','terapeuta'])) {
    echo json_encode(['ok'=>false,'msg'=>'Acesso negado.']); exit;
}
require_once '../config/db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$acao = trim($_POST['acao'] ?? '');

function resp(bool $ok, string $msg='', array $extra=[]): void {
    echo json_encode(array_merge(['ok'=>$ok,'msg'=>$msg],$extra)); exit;
}

try {
    switch ($acao) {

        case 'responder':
            $id      = (int)($_POST['id']       ?? 0);
            $resposta = trim($_POST['resposta'] ?? '');
            if (!$id)      resp(false, 'Sugestão inválida.');
            if (!$resposta) resp(false, 'Escreva uma resposta.');

            $pdo->prepare("UPDATE sugestoes SET resposta=?, respondido_em=NOW(), lida=1 WHERE id=?")
                ->execute([$resposta, $id]);
            resp(true, 'Resposta enviada!');

        case 'marcar_lida':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) resp(false, 'Sugestão inválida.');
            $pdo->prepare("UPDATE sugestoes SET lida=1 WHERE id=?")->execute([$id]);
            resp(true);

        case 'excluir':
            if ($_SESSION['tipo'] !== 'coordenador') resp(false, 'Acesso negado.');
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) resp(false, 'Sugestão inválida.');
            $pdo->prepare("DELETE FROM sugestoes WHERE id=?")->execute([$id]);
            resp(true, 'Sugestão removida.');

        default:
            resp(false, 'Ação inválida.');
    }
} catch (PDOException $e) {
    resp(false, 'Erro BD: ' . $e->getMessage());
}