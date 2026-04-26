<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Captura qualquer erro PHP e retorna como JSON
set_error_handler(function($errno, $errstr) {
    echo json_encode(['ok'=>false, 'msg'=>"Erro PHP: {$errstr}"]);
    exit;
});

if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'terapeuta') {
    echo json_encode(['ok'=>false,'msg'=>'Acesso negado.']); exit;
}

require_once '../config/db.php';

// Ativa exceções no PDO para capturar erros de SQL
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$uid  = (int)$_SESSION['usuario_id'];
$acao = trim($_POST['acao'] ?? $_GET['acao'] ?? '');

function resp(bool $ok, string $msg='', array $extra=[]): void {
    echo json_encode(array_merge(['ok'=>$ok,'msg'=>$msg],$extra)); exit;
}

try {

switch ($acao) {

    // ── Listar slots ───────────────────────────────────────────────────────────
    case 'listar_slots':
        $rows = $pdo->prepare("
            SELECT s.*,
              (SELECT COUNT(*) FROM reservas r
               WHERE r.slot_id=s.id AND r.status NOT IN ('cancelado')
               AND r.data_sessao >= CURDATE()) AS reservas_ativas
            FROM slots s WHERE s.terapeuta_id=? ORDER BY s.dia_semana, s.hora_inicio
        ");
        $rows->execute([$uid]);
        resp(true, '', ['slots' => $rows->fetchAll(PDO::FETCH_ASSOC)]);

    // ── Criar slot ─────────────────────────────────────────────────────────────
    case 'criar_slot':
        $dia    = (int)($_POST['dia_semana']  ?? 0);
        $hi     = trim($_POST['hora_inicio']  ?? '');
        $hf     = trim($_POST['hora_fim']     ?? '');
        $vagas  = max(1, min(10, (int)($_POST['vagas_total'] ?? 1)));
        $local  = trim($_POST['local']        ?? '');
        $ai     = isset($_POST['aceita_interno']) ? 1 : 0;
        $ae     = isset($_POST['aceita_externo']) ? 1 : 0;
        $prat_raw = $_POST['praticas'] ?? [];
        $praticas = is_array($prat_raw)
            ? implode(', ', array_filter(array_map('trim', $prat_raw)))
            : trim($prat_raw);

        if (!$dia || !$hi || !$hf) resp(false, 'Preencha dia, hora de início e hora de fim.');

        $pdo->prepare("INSERT INTO slots
                (terapeuta_id, dia_semana, hora_inicio, hora_fim, vagas_total,
                 local, praticas, aceita_interno, aceita_externo, ativo)
               VALUES (?,?,?,?,?,?,?,?,?,1)")
            ->execute([$uid, $dia, $hi, $hf, $vagas, $local, $praticas, $ai, $ae]);
        resp(true, 'Horário criado!', ['id' => (int)$pdo->lastInsertId()]);

    // ── Editar slot ────────────────────────────────────────────────────────────
    case 'editar_slot':
        $sid   = (int)($_POST['slot_id']    ?? 0);
        $vagas = max(1, min(10, (int)($_POST['vagas_total'] ?? 1)));
        $local = trim($_POST['local']       ?? '');
        $ativo = (int)($_POST['ativo']      ?? 1);
        $ai    = isset($_POST['aceita_interno']) ? 1 : 0;
        $ae    = isset($_POST['aceita_externo']) ? 1 : 0;
        $prat_raw = $_POST['praticas'] ?? [];
        $praticas = is_array($prat_raw)
            ? implode(', ', array_filter(array_map('trim', $prat_raw)))
            : trim($prat_raw);

        $chk = $pdo->prepare("SELECT id FROM slots WHERE id=? AND terapeuta_id=?");
        $chk->execute([$sid, $uid]);
        if (!$chk->fetch()) resp(false, 'Slot não encontrado.');

        $pdo->prepare("UPDATE slots SET vagas_total=?, local=?, praticas=?,
                       ativo=?, aceita_interno=?, aceita_externo=? WHERE id=?")
            ->execute([$vagas, $local, $praticas, $ativo, $ai, $ae, $sid]);
        resp(true, 'Atualizado!');

    // ── Excluir slot ───────────────────────────────────────────────────────────
    case 'excluir_slot':
        $sid = (int)($_POST['slot_id'] ?? 0);
        $chk = $pdo->prepare("SELECT COUNT(*) FROM reservas WHERE slot_id=? AND status NOT IN ('cancelado')");
        $chk->execute([$sid]);
        if ((int)$chk->fetchColumn() > 0)
            resp(false, 'Não é possível excluir: há reservas ativas neste horário.');
        $pdo->prepare("DELETE FROM slots WHERE id=? AND terapeuta_id=?")->execute([$sid, $uid]);
        resp(true, 'Horário removido.');

    // ── Abrir plantão ──────────────────────────────────────────────────────────
    case 'abrir_plantao':
        $data = trim($_POST['data'] ?? date('Y-m-d'));
        $hi   = trim($_POST['hora_inicio'] ?? '');
        $hf   = trim($_POST['hora_fim']    ?? '');
        $local= trim($_POST['local']       ?? '');
        $max  = max(1, min(20, (int)($_POST['max_pacientes'] ?? 4)));
        $prat_raw = $_POST['praticas'] ?? [];
        $prat = is_array($prat_raw)
            ? implode(', ', array_filter(array_map('trim', $prat_raw)))
            : trim($prat_raw);

        if (!$hi || !$hf) resp(false, 'Informe os horários do plantão.');

        $pdo->prepare("INSERT INTO plantoes
                (terapeuta_id, data, hora_inicio, hora_fim, local, max_pacientes, praticas)
               VALUES (?,?,?,?,?,?,?)")
            ->execute([$uid, $data, $hi, $hf, $local, $max, $prat]);
        resp(true, 'Plantão aberto!', ['plantao_id' => (int)$pdo->lastInsertId()]);

    // ── Registrar atendimento no plantão ───────────────────────────────────────
    case 'registrar_plantao':
        $plantao_id = (int)($_POST['plantao_id'] ?? 0);
        $pnome      = trim($_POST['paciente_nome']  ?? '');
        $email_pac  = trim($_POST['email_paciente'] ?? '');

        $prat_raw = $_POST['tipo_pratica'] ?? [];
        $pratica  = is_array($prat_raw)
            ? implode(', ', array_filter(array_map('trim', $prat_raw)))
            : trim($prat_raw);

        $queixa  = trim($_POST['queixa']               ?? '');
        $dor_raw = trim($_POST['dor_intensidade']       ?? '');
        $dor     = ($dor_raw !== '' && is_numeric($dor_raw)) ? (int)$dor_raw : null;
        $dor_loc = trim($_POST['dor_localizacao']       ?? '');
        $alerg   = trim($_POST['alergias_medicamentos'] ?? '');
        $orient  = trim($_POST['orientacoes']           ?? '');

        if (!$pnome)      resp(false, 'Informe o nome do paciente.');
        if (!$pratica)    resp(false, 'Selecione ao menos uma prática.');
        if (!$plantao_id) resp(false, 'Plantão inválido. Inicie um plantão primeiro.');

        // Busca o terapeutas.id correspondente ao usuarios.id logado
        // (sessoes_plantao.terapeuta_id → terapeutas.id, NÃO usuarios.id)
        $tid_q = $pdo->prepare("SELECT id FROM terapeutas WHERE usuario_id=?");
        $tid_q->execute([$uid]);
        $tid_real = $tid_q->fetchColumn();
        if (!$tid_real) resp(false, 'Seu cadastro de terapeuta não foi encontrado. Contate a coordenação.');
        $tid_real = (int)$tid_real;

        // Vincula paciente cadastrado pelo e-mail
        $pacId = null;
        if ($email_pac) {
            $pk = $pdo->prepare("SELECT id FROM usuarios WHERE email=? AND tipo='paciente'");
            $pk->execute([$email_pac]);
            $found = $pk->fetchColumn();
            if ($found) $pacId = (int)$found;
        }

        // Verifica plantão aberto (plantoes.terapeuta_id = usuarios.id)
        $plt = $pdo->prepare("
            SELECT p.max_pacientes, COUNT(sp.id) AS atual
            FROM plantoes p
            LEFT JOIN sessoes_plantao sp ON sp.plantao_id = p.id
            WHERE p.id=? AND p.terapeuta_id=? AND p.status='aberto'
            GROUP BY p.id
        ");
        $plt->execute([$plantao_id, $uid]);
        $lim = $plt->fetch(PDO::FETCH_ASSOC);

        if (!$lim)
            resp(false, 'Plantão não encontrado ou já encerrado.');
        if ((int)$lim['atual'] >= (int)$lim['max_pacientes'])
            resp(false, 'Limite de pacientes atingido neste plantão.');

        // Descobre as colunas que existem em sessoes_plantao
        $cols_stmt = $pdo->query("SHOW COLUMNS FROM sessoes_plantao");
        $cols = array_column($cols_stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');

        // Monta INSERT dinâmico usando terapeutas.id (não usuarios.id)
        $insert_cols = ['terapeuta_id', 'data', 'hora_inicio', 'paciente_nome', 'tipo_pratica', 'status'];
        $insert_vals = [$tid_real, date('Y-m-d'), date('H:i:s'), $pnome, $pratica, 'realizado'];

        $optional = [
            'plantao_id'           => $plantao_id,
            'paciente_id'          => $pacId,
            'queixa'               => $queixa ?: null,
            'dor_intensidade'      => $dor,
            'dor_localizacao'      => $dor_loc ?: null,
            'alergias_medicamentos'=> $alerg ?: null,
            'orientacoes'          => $orient ?: null,
        ];
        foreach ($optional as $col => $val) {
            if (in_array($col, $cols)) {
                $insert_cols[] = $col;
                $insert_vals[] = $val;
            }
        }

        $placeholders = implode(',', array_fill(0, count($insert_cols), '?'));
        $col_list     = implode(',', $insert_cols);
        $pdo->prepare("INSERT INTO sessoes_plantao ({$col_list}) VALUES ({$placeholders})")
            ->execute($insert_vals);

        resp(true, 'Atendimento registrado!', [
            'total' => (int)$lim['atual'] + 1,
            'max'   => (int)$lim['max_pacientes'],
        ]);

    // ── Encerrar plantão ───────────────────────────────────────────────────────
    case 'encerrar_plantao':
        $plantao_id = (int)($_POST['plantao_id'] ?? 0);
        $pdo->prepare("UPDATE plantoes SET status='encerrado' WHERE id=? AND terapeuta_id=?")
            ->execute([$plantao_id, $uid]);
        resp(true, 'Plantão encerrado.');

    // ── Plantão aberto hoje ────────────────────────────────────────────────────
    case 'plantao_hoje':
        $p = $pdo->prepare("
            SELECT p.*, COUNT(sp.id) AS total_atendidos
            FROM plantoes p
            LEFT JOIN sessoes_plantao sp ON sp.plantao_id = p.id
            WHERE p.terapeuta_id=? AND p.data=CURDATE() AND p.status='aberto'
            GROUP BY p.id ORDER BY p.hora_inicio LIMIT 1
        ");
        $p->execute([$uid]);
        $plt = $p->fetch(PDO::FETCH_ASSOC);
        if (!$plt) resp(false, 'Nenhum plantão aberto hoje.');
        $sp = $pdo->prepare("
            SELECT sp.id, sp.paciente_nome, sp.tipo_pratica, sp.criado_em
            FROM sessoes_plantao sp
            WHERE sp.plantao_id=? ORDER BY sp.criado_em ASC
        ");
        $sp->execute([$plt['id']]);
        $plt['pacientes'] = $sp->fetchAll(PDO::FETCH_ASSOC);
        resp(true, '', ['plantao' => $plt]);

    default:
        resp(false, 'Ação inválida: ' . htmlspecialchars($acao));
}

} catch (PDOException $e) {
    // Retorna o erro SQL real como JSON para depuração
    resp(false, 'Erro no banco de dados: ' . $e->getMessage());
} catch (Exception $e) {
    resp(false, 'Erro: ' . $e->getMessage());
}