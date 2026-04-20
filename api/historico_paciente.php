<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['tipo'], ['terapeuta','coordenador'])) {
    echo json_encode(['ok'=>false,'msg'=>'Acesso negado.']); exit;
}
require_once '../config/db.php';
$pac_uid = (int)($_GET['pac_uid'] ?? 0);
if (!$pac_uid) { echo json_encode(['ok'=>false,'msg'=>'Paciente inválido.']); exit; }

$stmt = $pdo->prepare("
    SELECT c.id, c.status, c.sessoes_realizadas, c.criado_em, c.encerrado_em,
           (SELECT ai.motivo_procura FROM anamnese_inicial ai WHERE ai.ciclo_id=c.id LIMIT 1) AS anamnese,
           s.praticas,
           u.nome AS terapeuta
    FROM ciclos c
    JOIN reservas r ON c.reserva_id=r.id
    JOIN slots s ON r.slot_id=s.id
    JOIN usuarios u ON s.terapeuta_id=u.id
    WHERE r.paciente_id=?
    ORDER BY c.criado_em DESC LIMIT 20
");
$stmt->execute([$pac_uid]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ciclos = array_map(function($r) {
    $inicio = $r['criado_em'] ? date('d/m/Y', strtotime($r['criado_em'])) : '?';
    $fim    = $r['encerrado_em'] ? date('d/m/Y', strtotime($r['encerrado_em'])) : 'em andamento';
    return [
        'id'                => $r['id'],
        'status'            => $r['status'],
        'sessoes_realizadas'=> $r['sessoes_realizadas'],
        'periodo'           => "{$inicio} → {$fim}",
        'anamnese'          => $r['anamnese'],
        'praticas'          => $r['praticas'],
        'terapeuta'         => $r['terapeuta'],
    ];
}, $rows);

echo json_encode(['ok'=>true,'ciclos'=>$ciclos]);