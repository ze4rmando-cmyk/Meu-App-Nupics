<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['ok' => false, 'msg' => 'Não autenticado.']); exit;
}

require_once '../config/db.php';

$acao       = trim($_POST['acao'] ?? '');
$uid        = (int)$_SESSION['usuario_id'];
$tipo       = $_SESSION['tipo'];

// ── helpers ────────────────────────────────────────────────────────────────
function resp(bool $ok, string $msg = '', array $extra = []): void {
    echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $extra)); exit;
}

// Calcula a data da próxima ocorrência de um dia_semana (1=Seg … 5=Sex)
// WEEKDAY(): 0=Seg … 6=Dom  |  dia_semana: 1=Seg … 5=Sex
function proxima_data(int $dia_semana): string {
    $dias = ($dia_semana - 1 - (int)date('N') % 7 + 7) % 7;
    // date('N'): 1=Mon…7=Sun → mesma convenção que dia_semana-1
    $dias = ($dia_semana - (int)date('N') + 7) % 7;
    return date('Y-m-d', strtotime("+{$dias} days"));
}

// ── ações ───────────────────────────────────────────────────────────────────
switch ($acao) {

    // ── PACIENTE: criar reserva ─────────────────────────────────────────────
    case 'reservar':
        if ($tipo !== 'paciente') resp(false, 'Acesso negado.');

        $slot_id  = (int)($_POST['slot_id']  ?? 0);
        $queixas  = trim($_POST['queixas']   ?? '');
        $telefone = trim($_POST['telefone']  ?? '');

        if (!$slot_id || !$queixas || !$telefone)
            resp(false, 'Preencha todos os campos.');

        // Valida slot ativo
        $slot = $pdo->prepare("SELECT * FROM slots WHERE id = ? AND ativo = 1");
        $slot->execute([$slot_id]);
        $slot = $slot->fetch(PDO::FETCH_ASSOC);
        if (!$slot) resp(false, 'Horário indisponível.');

        // Verifica se paciente já tem reserva ativa neste slot/data
        $data = proxima_data((int)$slot['dia_semana']);
        $dup = $pdo->prepare(
            "SELECT id FROM reservas WHERE slot_id=? AND paciente_id=? AND data_sessao=? AND status NOT IN ('cancelado')"
        );
        $dup->execute([$slot_id, $uid, $data]);
        if ($dup->fetch()) resp(false, 'Você já tem um agendamento neste horário.');

        // Conta vagas ocupadas
        $usadas = $pdo->prepare(
            "SELECT COUNT(*) FROM reservas WHERE slot_id=? AND data_sessao=? AND status NOT IN ('cancelado')"
        );
        $usadas->execute([$slot_id, $data]);
        $usadas = (int)$usadas->fetchColumn();

        if ($usadas >= (int)$slot['vagas_total']) {
            // Coloca na fila
            $jaFila = $pdo->prepare(
                "SELECT id FROM fila_espera WHERE slot_id=? AND paciente_id=? AND data_sessao=? AND status='aguardando'"
            );
            $jaFila->execute([$slot_id, $uid, $data]);
            if ($jaFila->fetch()) resp(false, 'Você já está na fila para este horário.');

            $pos = $pdo->prepare(
                "SELECT COALESCE(MAX(posicao),0)+1 FROM fila_espera WHERE slot_id=? AND data_sessao=? AND status='aguardando'"
            );
            $pos->execute([$slot_id, $data]);
            $posicao = (int)$pos->fetchColumn();

            $pdo->prepare(
                "INSERT INTO fila_espera (slot_id,paciente_id,data_sessao,queixas,telefone_contato,posicao) VALUES (?,?,?,?,?,?)"
            )->execute([$slot_id, $uid, $data, $queixas, $telefone, $posicao]);

            resp(true, '', ['tipo' => 'fila', 'posicao' => $posicao, 'data' => $data]);
        }

        // Cria reserva
        $pdo->prepare(
            "INSERT INTO reservas (slot_id,paciente_id,data_sessao,queixas,telefone_contato) VALUES (?,?,?,?,?)"
        )->execute([$slot_id, $uid, $data, $queixas, $telefone]);

        resp(true, '', ['tipo' => 'reserva', 'data' => $data]);

    // ── TERAPEUTA: confirmar ────────────────────────────────────────────────
    case 'confirmar':
        if ($tipo !== 'terapeuta') resp(false, 'Acesso negado.');
        $rid = (int)($_POST['reserva_id'] ?? 0);
        $pdo->prepare(
            "UPDATE reservas r JOIN slots s ON r.slot_id=s.id
             SET r.status='confirmado' WHERE r.id=? AND s.terapeuta_id=?"
        )->execute([$rid, $uid]);
        resp(true);

    // ── TERAPEUTA ou PACIENTE: cancelar ────────────────────────────────────
    case 'cancelar':
        $rid = (int)($_POST['reserva_id'] ?? 0);
        if ($tipo === 'terapeuta') {
            $pdo->prepare(
                "UPDATE reservas r JOIN slots s ON r.slot_id=s.id
                 SET r.status='cancelado' WHERE r.id=? AND s.terapeuta_id=?"
            )->execute([$rid, $uid]);
        } elseif ($tipo === 'paciente') {
            // Paciente só cancela a própria reserva ainda não concluída
            $pdo->prepare(
                "UPDATE reservas SET status='cancelado'
                 WHERE id=? AND paciente_id=? AND status IN ('pendente','confirmado')"
            )->execute([$rid, $uid]);
        } else resp(false, 'Acesso negado.');
        resp(true);

    // ── PACIENTE: enviar sugestão / reclamação ──────────────────────────────
    case 'sugestao':
        if ($tipo !== 'paciente') resp(false, 'Acesso negado.');
        $tipo_sug = trim($_POST['tipo']     ?? 'sugestao');
        $mensagem = trim($_POST['mensagem'] ?? '');
        $tipos_ok = ['sugestao','reclamacao','elogio','duvida'];
        if (!$mensagem || strlen($mensagem) < 10) resp(false, 'Mensagem muito curta.');
        if (!in_array($tipo_sug, $tipos_ok)) $tipo_sug = 'sugestao';
        $pdo->prepare(
            "INSERT INTO sugestoes (paciente_id, tipo, mensagem) VALUES (?,?,?)"
        )->execute([$uid, $tipo_sug, $mensagem]);
        resp(true);

    // ── TERAPEUTA: concluir ─────────────────────────────────────────────────
    case 'concluir':
        if ($tipo !== 'terapeuta') resp(false, 'Acesso negado.');
        $rid = (int)($_POST['reserva_id'] ?? 0);
        $obs = trim($_POST['observacao']  ?? '');
        $pdo->prepare(
            "UPDATE reservas r JOIN slots s ON r.slot_id=s.id
             SET r.status='concluido', r.observacao=? WHERE r.id=? AND s.terapeuta_id=?"
        )->execute([$obs, $rid, $uid]);
        resp(true);

    // ── TERAPEUTA: chamar da fila ───────────────────────────────────────────
    case 'chamar_fila':
        if ($tipo !== 'terapeuta') resp(false, 'Acesso negado.');
        $fid = (int)($_POST['fila_id'] ?? 0);
        $pdo->prepare(
            "UPDATE fila_espera fe JOIN slots s ON fe.slot_id=s.id
             SET fe.status='notificado' WHERE fe.id=? AND s.terapeuta_id=?"
        )->execute([$fid, $uid]);
        resp(true);

    default:
        resp(false, 'Ação inválida.');
}