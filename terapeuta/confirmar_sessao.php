<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['tipo'], ['terapeuta','coordenador'])) {
    header('Location: ../index.php');
    exit;
}

$agendamento_id = (int) ($_POST['agendamento_id'] ?? 0);
$acao           = $_POST['acao'] ?? '';

if ($agendamento_id && in_array($acao, ['realizar','cancelar'])) {
    $novo_status = $acao === 'realizar' ? 'realizado' : 'cancelado';

    // Atualiza status
    $stmt = $pdo->prepare('UPDATE agendamentos SET status = ? WHERE id = ?');
    $stmt->execute([$novo_status, $agendamento_id]);

    // Se cancelado: verifica total de faltas do paciente
    if ($acao === 'cancelar') {
        // Busca paciente
        $stmt = $pdo->prepare('
            SELECT c.paciente_id FROM agendamentos a
            JOIN ciclos c ON c.id = a.ciclo_id
            WHERE a.id = ?
        ');
        $stmt->execute([$agendamento_id]);
        $row = $stmt->fetch();

        if ($row) {
            $stmt = $pdo->prepare('
                SELECT COUNT(*) AS faltas FROM agendamentos a
                JOIN ciclos c ON c.id = a.ciclo_id
                WHERE c.paciente_id = ? AND a.status = "cancelado"
            ');
            $stmt->execute([$row['paciente_id']]);
            $faltas = $stmt->fetch()['faltas'];

            // 2 ou mais faltas: cancela ciclo ativo
            if ($faltas >= 2) {
                $pdo->prepare('
                    UPDATE ciclos SET status = "cancelado"
                    WHERE paciente_id = ? AND status = "ativo"
                ')->execute([$row['paciente_id']]);

                // Cancela agendamentos futuros
                $pdo->prepare('
                    UPDATE agendamentos a
                    JOIN ciclos c ON c.id = a.ciclo_id
                    SET a.status = "cancelado"
                    WHERE c.paciente_id = ? AND a.data > CURDATE() AND a.status = "agendado"
                ')->execute([$row['paciente_id']]);
            }
        }
    }
}

header('Location: dashboard.php');
exit;