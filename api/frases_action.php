<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'coordenador') {
    echo json_encode(['ok'=>false,'msg'=>'Acesso negado.']); exit;
}
require_once '../config/db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$acao = trim($_POST['acao'] ?? $_GET['acao'] ?? '');

function resp(bool $ok, string $msg='', array $extra=[]): void {
    echo json_encode(array_merge(['ok'=>$ok,'msg'=>$msg],$extra)); exit;
}

try {
    switch ($acao) {

        case 'listar':
            $tipo = trim($_GET['tipo'] ?? '');
            $sql  = "SELECT * FROM frases" . ($tipo ? " WHERE tipo=?" : "") . " ORDER BY tipo, id DESC";
            $s = $pdo->prepare($sql);
            $tipo ? $s->execute([$tipo]) : $s->execute();
            resp(true, '', ['frases' => $s->fetchAll(PDO::FETCH_ASSOC)]);

        case 'criar':
            $texto = trim($_POST['texto'] ?? '');
            $tipo  = trim($_POST['tipo']  ?? '');
            $autor = trim($_POST['autor'] ?? '') ?: null;
            if (!$texto) resp(false, 'O texto da frase não pode ficar vazio.');
            if (!in_array($tipo, ['paciente','terapeuta'])) resp(false, 'Tipo inválido.');
            $pdo->prepare("INSERT INTO frases (tipo, texto, autor, ativo) VALUES (?,?,?,1)")
                ->execute([$tipo, $texto, $autor]);
            resp(true, 'Frase adicionada!', ['id' => (int)$pdo->lastInsertId()]);

        case 'editar':
            $id    = (int)($_POST['id']    ?? 0);
            $texto = trim($_POST['texto']  ?? '');
            $autor = trim($_POST['autor']  ?? '') ?: null;
            if (!$id || !$texto) resp(false, 'Dados inválidos.');
            $pdo->prepare("UPDATE frases SET texto=?, autor=? WHERE id=?")
                ->execute([$texto, $autor, $id]);
            resp(true, 'Frase atualizada!');

        case 'toggle':
            $id    = (int)($_POST['id']    ?? 0);
            $ativo = (int)($_POST['ativo'] ?? 1);
            if (!$id) resp(false, 'ID inválido.');
            $pdo->prepare("UPDATE frases SET ativo=? WHERE id=?")->execute([$ativo, $id]);
            resp(true, $ativo ? 'Frase ativada.' : 'Frase desativada.');

        case 'excluir':
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) resp(false, 'ID inválido.');
            $pdo->prepare("DELETE FROM frases WHERE id=?")->execute([$id]);
            resp(true, 'Frase excluída.');

        default:
            resp(false, 'Ação inválida.');
    }
} catch (PDOException $e) {
    resp(false, 'Erro BD: ' . $e->getMessage());
}