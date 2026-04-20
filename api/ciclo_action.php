<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['usuario_id'])) { echo json_encode(['ok'=>false,'msg'=>'Não autenticado.']); exit; }
require_once '../config/db.php';

$uid  = (int)$_SESSION['usuario_id'];
$tipo = $_SESSION['tipo'];
$acao = trim($_POST['acao'] ?? '');

function resp(bool $ok, string $msg='', array $extra=[]): void {
    echo json_encode(array_merge(['ok'=>$ok,'msg'=>$msg],$extra)); exit;
}

function notificar(PDO $pdo, int $dest, string $tipo, string $titulo, string $msg, ?int $ciclo_id=null): void {
    $pdo->prepare("INSERT INTO notificacoes (destinatario,tipo,titulo,mensagem,ciclo_id) VALUES (?,?,?,?,?)")
        ->execute([$dest, $tipo, $titulo, $msg, $ciclo_id]);
}

function bloquearPaciente(PDO $pdo, int $paciente_usuario_id, int $ciclo_id, string $motivo): void {
    // Bloqueia por 30 dias
    $pdo->prepare("UPDATE pacientes SET bloqueado_ate = DATE_ADD(CURDATE(), INTERVAL 30 DAY) WHERE usuario_id=?")
        ->execute([$paciente_usuario_id]);
    // Cancela ciclo
    $pdo->prepare("UPDATE ciclos SET status='cancelado', motivo_encerramento=?, encerrado_em=NOW() WHERE id=?")
        ->execute([$motivo, $ciclo_id]);
    // Notifica paciente
    notificar($pdo, $paciente_usuario_id, 'bloqueio',
        'Afastamento temporário do ciclo',
        "Devido a ausências no seu ciclo de atendimento, você foi temporariamente afastado(a) por 30 dias, conforme política do NUPICS. Isso não depende do terapeuta — é uma regra automática do sistema para garantir equidade no atendimento. Você poderá agendar novamente após esse período.",
        $ciclo_id);
}

switch ($acao) {

    // ── CONFIRMAR reserva → cria ciclo ──────────────────────────────────────
    case 'confirmar_reserva':
        if ($tipo !== 'terapeuta') resp(false, 'Acesso negado.');
        $rid = (int)($_POST['reserva_id'] ?? 0);

        // Valida que a reserva pertence a um slot do terapeuta
        $r = $pdo->prepare("
            SELECT r.*, r.paciente_id as pac_uid
            FROM reservas r JOIN slots s ON r.slot_id=s.id
            WHERE r.id=? AND s.terapeuta_id=? AND r.status='pendente'
        ");
        $r->execute([$rid, $uid]);
        $reserva = $r->fetch(PDO::FETCH_ASSOC);
        if (!$reserva) resp(false, 'Reserva não encontrada.');

        // Verifica bloqueio do paciente
        $blk = $pdo->prepare("SELECT bloqueado_ate FROM pacientes WHERE usuario_id=?");
        $blk->execute([$reserva['pac_uid']]);
        $blk = $blk->fetchColumn();
        if ($blk && $blk >= date('Y-m-d')) resp(false, "Paciente bloqueado até {$blk}. Não é possível confirmar.");

        // Pega o paciente.id
        $pid = $pdo->prepare("SELECT id FROM pacientes WHERE usuario_id=?");
        $pid->execute([$reserva['pac_uid']]);
        $pid = $pid->fetchColumn();

        // Pega terapeutas.id do usuário logado
        $tid = $pdo->prepare("SELECT id FROM terapeutas WHERE usuario_id=?");
        $tid->execute([$uid]);
        $tid = $tid->fetchColumn();

        $pdo->beginTransaction();
        // Confirma reserva
        $pdo->prepare("UPDATE reservas SET status='confirmado' WHERE id=?")->execute([$rid]);

        // Verifica se já existe ciclo para esta reserva
        $existeCiclo = $pdo->prepare("SELECT id FROM ciclos WHERE reserva_id=?");
        $existeCiclo->execute([$rid]);
        if (!$existeCiclo->fetchColumn()) {
            // Cria ciclo
            $pdo->prepare("INSERT INTO ciclos (paciente_id, terapeuta_id, reserva_id, total_sessoes, status) VALUES (?,?,?,4,'ativo')")
                ->execute([$pid, $tid ?: null, $rid]);
        }
        $pdo->commit();
        resp(true, '', ['tipo'=>'confirmado']);

    // ── REGISTRAR FALTA ──────────────────────────────────────────────────────
    case 'faltou':
        if ($tipo !== 'terapeuta') resp(false, 'Acesso negado.');
        $ciclo_id   = (int)($_POST['ciclo_id'] ?? 0);
        $sessao_num = (int)($_POST['sessao_num'] ?? 2);
        $just       = trim($_POST['justificativa'] ?? '');

        // Carrega ciclo + paciente
        $ciclo = $pdo->prepare("
            SELECT c.*, r.paciente_id as pac_uid, r.data_sessao
            FROM ciclos c JOIN reservas r ON c.reserva_id=r.id
            WHERE c.id=? AND c.status='ativo'
        ");
        $ciclo->execute([$ciclo_id]);
        $ciclo = $ciclo->fetch(PDO::FETCH_ASSOC);
        if (!$ciclo) resp(false, 'Ciclo não encontrado.');

        // Insere registro de falta
        $pdo->prepare("
            INSERT INTO registros_sessao (ciclo_id, terapeuta_id, numero_sessao, data_sessao, status, justificativa)
            VALUES (?,?,?,CURDATE(),'faltou',?)
        ")->execute([$ciclo_id, $uid, $sessao_num, $just ?: 'Sem justificativa informada']);

        $novas_faltas = (int)$ciclo['faltas'] + 1;
        $pdo->prepare("UPDATE ciclos SET faltas=? WHERE id=?")->execute([$novas_faltas, $ciclo_id]);

        if ($novas_faltas >= 2) {
            // Segunda falta: bloqueia
            bloquearPaciente($pdo, (int)$ciclo['pac_uid'], $ciclo_id,
                "Duas faltas registradas no ciclo. Paciente afastado automaticamente.");
            resp(true, '', ['acao'=>'bloqueado', 'faltas'=>$novas_faltas]);
        }

        // Primeira falta: apenas aviso
        notificar($pdo, (int)$ciclo['pac_uid'], 'falta',
            'Falta registrada na sua sessão',
            "Uma falta foi registrada no seu ciclo de atendimento. Atenção: uma segunda falta resultará no afastamento automático por 30 dias, conforme as regras do NUPICS.",
            $ciclo_id);
        resp(true, '', ['acao'=>'falta_registrada', 'faltas'=>$novas_faltas]);

    // ── ADIAR SESSÃO ─────────────────────────────────────────────────────────
    case 'adiar':
        if ($tipo !== 'terapeuta') resp(false, 'Acesso negado.');
        $ciclo_id   = (int)($_POST['ciclo_id'] ?? 0);
        $sessao_num = (int)($_POST['sessao_num'] ?? 2);
        $motivo     = trim($_POST['motivo'] ?? '');
        if (!$motivo) resp(false, 'Informe o motivo do adiamento.');

        $ciclo = $pdo->prepare("
            SELECT c.*, r.paciente_id as pac_uid
            FROM ciclos c JOIN reservas r ON c.reserva_id=r.id
            WHERE c.id=? AND c.status='ativo'
        ");
        $ciclo->execute([$ciclo_id]);
        $ciclo = $ciclo->fetch(PDO::FETCH_ASSOC);
        if (!$ciclo) resp(false, 'Ciclo não encontrado.');

        $pdo->prepare("
            INSERT INTO registros_sessao (ciclo_id, terapeuta_id, numero_sessao, data_sessao, status, justificativa)
            VALUES (?,?,?,CURDATE(),'adiado',?)
        ")->execute([$ciclo_id, $uid, $sessao_num, $motivo]);

        // Notifica paciente e coordenação
        notificar($pdo, (int)$ciclo['pac_uid'], 'adiamento',
            'Sua sessão foi adiada',
            "O terapeuta responsável informou o seguinte motivo: \"{$motivo}\". Entraremos em contato para reagendar.",
            $ciclo_id);

        // Notifica coordenadores
        $coords = $pdo->query("SELECT id FROM usuarios WHERE tipo='coordenador'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($coords as $cid) {
            notificar($pdo, $cid, 'adiamento',
                'Sessão adiada',
                "Sessão {$sessao_num} do ciclo #{$ciclo_id} foi adiada. Motivo: {$motivo}",
                $ciclo_id);
        }
        resp(true);

    // ── CONCLUIR CICLO ────────────────────────────────────────────────────────
    case 'concluir_ciclo':
        if ($tipo !== 'terapeuta') resp(false, 'Acesso negado.');
        $ciclo_id = (int)($_POST['ciclo_id'] ?? 0);

        $ciclo = $pdo->prepare("
            SELECT c.*, r.paciente_id as pac_uid
            FROM ciclos c JOIN reservas r ON c.reserva_id=r.id
            WHERE c.id=? AND c.status='ativo'
        ");
        $ciclo->execute([$ciclo_id]);
        $ciclo = $ciclo->fetch(PDO::FETCH_ASSOC);
        if (!$ciclo) resp(false, 'Ciclo não encontrado.');

        $pdo->prepare("UPDATE ciclos SET status='concluido', encerrado_em=NOW() WHERE id=?")
            ->execute([$ciclo_id]);

        // Bloqueia por 30 dias para dar espaço a outros pacientes
        $pdo->prepare("UPDATE pacientes SET bloqueado_ate=DATE_ADD(CURDATE(), INTERVAL 30 DAY) WHERE usuario_id=?")
            ->execute([(int)$ciclo['pac_uid']]);

        notificar($pdo, (int)$ciclo['pac_uid'], 'geral',
            'Ciclo de atendimento concluído!',
            'Seu ciclo de atendimento no NUPICS foi concluído. Você receberá em breve o relatório final. Após 30 dias, poderá solicitar um novo ciclo.',
            $ciclo_id);
        resp(true);

    // ── ESTENDER CICLO (coordenador ou terapeuta) ─────────────────────────────
    case 'estender':
        if (!in_array($tipo, ['terapeuta','coordenador'])) resp(false, 'Acesso negado.');
        $ciclo_id  = (int)($_POST['ciclo_id'] ?? 0);
        $adicionar = (int)($_POST['sessoes'] ?? 1);
        if ($adicionar < 1 || $adicionar > 4) resp(false, 'Informe entre 1 e 4 sessões adicionais.');
        $pdo->prepare("UPDATE ciclos SET total_sessoes = total_sessoes + ? WHERE id=? AND status='ativo'")
            ->execute([$adicionar, $ciclo_id]);
        resp(true);

    default:
        resp(false, 'Ação inválida.');
}