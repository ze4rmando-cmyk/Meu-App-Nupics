<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
set_error_handler(function($no, $str) { echo json_encode(['ok'=>false,'msg'=>$str]); exit; });

if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'coordenador') {
    echo json_encode(['ok'=>false,'msg'=>'Acesso negado.']); exit;
}
require_once '../config/db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$uid  = (int)$_SESSION['usuario_id'];
$acao = trim($_POST['acao'] ?? $_GET['acao'] ?? '');

function resp(bool $ok, string $msg='', array $extra=[]): void {
    echo json_encode(array_merge(['ok'=>$ok,'msg'=>$msg],$extra)); exit;
}

try {
switch ($acao) {

    // ── Ativar / Desativar terapeuta ────────────────────────────────────────────
    case 'toggle_terapeuta':
        $tid   = (int)($_POST['terapeuta_id'] ?? 0);
        $ativo = (int)($_POST['ativo'] ?? 1);
        if (!$tid) resp(false, 'ID inválido.');
        $pdo->prepare("UPDATE terapeutas SET ativo=? WHERE id=?")->execute([$ativo, $tid]);
        resp(true, $ativo ? 'Terapeuta ativado.' : 'Terapeuta desativado.');

    // ── Cadastrar terapeuta ─────────────────────────────────────────────────────
    case 'criar_terapeuta':
        $nome          = trim($_POST['nome']          ?? '');
        $email         = trim($_POST['email']         ?? '');
        $telefone      = trim($_POST['telefone']      ?? '');
        $especialidade = trim($_POST['especialidade'] ?? '');
        $periodo       = trim($_POST['periodo']       ?? '');
        $senha_raw     = trim($_POST['senha']         ?? '');

        if (!$nome || !$email || !$especialidade || !$senha_raw)
            resp(false, 'Preencha nome, e-mail, especialidade e senha.');
        if (strlen($senha_raw) < 6)
            resp(false, 'A senha deve ter pelo menos 6 caracteres.');

        $chk = $pdo->prepare("SELECT id FROM usuarios WHERE email=? LIMIT 1");
        $chk->execute([$email]);
        if ($chk->fetch()) resp(false, 'Este e-mail já está cadastrado.');

        $hash = password_hash($senha_raw, PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO usuarios (nome, email, senha, tipo, telefone) VALUES (?,?,?,?,?)")
            ->execute([$nome, $email, $hash, 'terapeuta', $telefone ?: null]);
        $novo_uid = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO terapeutas (usuario_id, especialidade, periodo, ativo) VALUES (?,?,?,1)")
            ->execute([$novo_uid, $especialidade, $periodo ?: null]);

        resp(true, "Terapeuta {$nome} cadastrado com sucesso!", ['usuario_id' => $novo_uid]);

    // ── Editar perfil coordenador ────────────────────────────────────────────────
    case 'editar_perfil':
        $nome     = trim($_POST['nome']     ?? '');
        $telefone = trim($_POST['telefone'] ?? '');
        if (!$nome) resp(false, 'O nome não pode ficar vazio.');
        $pdo->prepare("UPDATE usuarios SET nome=?, telefone=? WHERE id=?")
            ->execute([$nome, $telefone ?: null, $uid]);
        $_SESSION['nome'] = $nome;
        resp(true, 'Perfil atualizado!');

    // ── Alterar senha ────────────────────────────────────────────────────────────
    case 'alterar_senha':
        $atual = $_POST['senha_atual'] ?? '';
        $nova  = $_POST['senha_nova']  ?? '';
        $conf  = $_POST['confirmar']   ?? '';
        if (!$atual || !$nova) resp(false, 'Preencha todos os campos.');
        if (strlen($nova) < 6) resp(false, 'Nova senha deve ter no mínimo 6 caracteres.');
        if ($nova !== $conf) resp(false, 'As senhas não coincidem.');

        $stmt = $pdo->prepare("SELECT senha FROM usuarios WHERE id=?");
        $stmt->execute([$uid]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($atual, $row['senha']))
            resp(false, 'Senha atual incorreta.');

        $pdo->prepare("UPDATE usuarios SET senha=? WHERE id=?")
            ->execute([password_hash($nova, PASSWORD_DEFAULT), $uid]);
        resp(true, 'Senha alterada com sucesso!');

    // ── Enviar aviso / mensagem ──────────────────────────────────────────────────
    case 'enviar_aviso':
        $titulo   = trim($_POST['titulo']  ?? '');
        $texto    = trim($_POST['texto']   ?? '');
        $tipo     = trim($_POST['tipo']    ?? 'info');
        $dest     = trim($_POST['destino'] ?? 'todos');
        $uid_dest = (int)($_POST['usuario_id'] ?? 0);

        if (!$titulo || !$texto) resp(false, 'Preencha título e mensagem.');

        $tipos_ok = ['evento','urgente','manutencao','info'];
        $dests_ok = ['todos','paciente','terapeuta','coordenador'];
        if (!in_array($tipo, $tipos_ok)) $tipo = 'info';
        if (!in_array($dest, $dests_ok)) $dest = 'todos';

        $pdo->prepare("INSERT INTO avisos (tipo, titulo, texto, ativo, destino) VALUES (?,?,?,1,?)")
            ->execute([$tipo, $titulo, $texto, $dest]);
        $aviso_id = $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO mensagens_coord (remetente_id, destinatario, usuario_id, titulo, mensagem, tipo)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$uid,
                $dest === 'todos' ? 'todos' : ($dest === 'terapeuta' ? 'terapeutas' : 'pacientes'),
                $uid_dest ?: null, $titulo, $texto, $tipo]);

        resp(true, 'Mensagem enviada!', ['aviso_id' => (int)$aviso_id]);

    // ── Excluir aviso ───────────────────────────────────────────────────────────
    case 'excluir_aviso':
        $aid = (int)($_POST['aviso_id'] ?? 0);
        if (!$aid) resp(false, 'ID inválido.');
        $pdo->prepare("UPDATE avisos SET ativo=0 WHERE id=?")->execute([$aid]);
        resp(true);

    // ── Aprovar visita ──────────────────────────────────────────────────────────
    case 'aprovar_visita':
        $vid     = (int)($_POST['visita_id'] ?? 0);
        $obs     = trim($_POST['obs']        ?? '');
        $ter_ids = (array)($_POST['terapeutas'] ?? []);
        if (!$vid) resp(false, 'Visita inválida.');

        $pdo->prepare("UPDATE visitas_externas SET status='aprovada', coord_obs=? WHERE id=?")
            ->execute([$obs, $vid]);

        $pdo->prepare("DELETE FROM visita_terapeutas WHERE visita_id=?")->execute([$vid]);
        foreach ($ter_ids as $tid) {
            $tid = (int)$tid;
            if (!$tid) continue;
            $pdo->prepare("INSERT IGNORE INTO visita_terapeutas (visita_id, terapeuta_id) VALUES (?,?)")
                ->execute([$vid, $tid]);
            $pdo->prepare("INSERT INTO notificacoes (destinatario, tipo, titulo, mensagem) VALUES (?,?,?,?)")
                ->execute([$tid, 'geral', 'Você foi escalado para uma visita externa',
                           'Você foi selecionado para participar de uma visita externa. Confira os detalhes no seu painel.']);
        }
        resp(true, 'Visita aprovada!');

    // ── Recusar visita ──────────────────────────────────────────────────────────
    case 'recusar_visita':
        $vid    = (int)($_POST['visita_id'] ?? 0);
        $motivo = trim($_POST['motivo']     ?? '');
        if (!$vid) resp(false, 'Visita inválida.');
        try {
            $pdo->prepare("UPDATE visitas_externas SET status='recusada', motivo_recusa=? WHERE id=?")
                ->execute([$motivo, $vid]);
        } catch (\PDOException $e) {
            $pdo->prepare("UPDATE visitas_externas SET status='cancelada', motivo_recusa=? WHERE id=?")
                ->execute([$motivo, $vid]);
        }
        resp(true, 'Visita recusada.');

    // ── Registrar ação realizada ────────────────────────────────────────────────
    case 'registrar_visita':
        $vid      = (int)($_POST['visita_id']           ?? 0);
        $data_r   = trim($_POST['data_realizada']       ?? date('Y-m-d'));
        $hora_i   = trim($_POST['hora_inicio']          ?? '') ?: null;
        $hora_f   = trim($_POST['hora_fim']             ?? '') ?: null;
        $local    = trim($_POST['local_confirmado']     ?? '');
        $resumo   = trim($_POST['resumo_sessao']        ?? '');
        $praticas = trim($_POST['praticas_realizadas']  ?? '');
        $parts    = (array)($_POST['participantes']     ?? []);
        if (!$vid) resp(false, 'Visita inválida.');

        $total  = count(array_filter($parts, fn($p) => !empty($p['nome'])));
        $exists = $pdo->prepare("SELECT id FROM visita_registros WHERE visita_id=?");
        $exists->execute([$vid]);
        $reg_id = $exists->fetchColumn();

        if ($reg_id) {
            $pdo->prepare("UPDATE visita_registros SET data_realizada=?, hora_inicio=?, hora_fim=?,
                           local_confirmado=?, resumo_sessao=?, praticas_realizadas=?, total_participantes=?
                           WHERE id=?")->execute([$data_r,$hora_i,$hora_f,$local,$resumo,$praticas,$total,$reg_id]);
        } else {
            $pdo->prepare("INSERT INTO visita_registros
                           (visita_id,data_realizada,hora_inicio,hora_fim,local_confirmado,
                            resumo_sessao,praticas_realizadas,total_participantes) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$vid,$data_r,$hora_i,$hora_f,$local,$resumo,$praticas,$total]);
        }

        $pdo->prepare("DELETE FROM visita_participantes WHERE visita_id=?")->execute([$vid]);
        foreach ($parts as $p) {
            if (empty($p['nome'])) continue;
            $pdo->prepare("INSERT INTO visita_participantes (visita_id,nome,idade,sexo,pratica,observacao)
                           VALUES (?,?,?,?,?,?)")
                ->execute([$vid,$p['nome'],$p['idade']??null,$p['sexo']??null,$p['pratica']??null,$p['obs']??null]);
        }

        $pdo->prepare("UPDATE visitas_externas SET status='realizada' WHERE id=?")->execute([$vid]);
        resp(true, 'Ação registrada com sucesso!');

    // ── Nova visita ──────────────────────────────────────────────────────────────
    case 'nova_visita':
        $local_nome  = trim($_POST['local_nome']           ?? '');
        $local_tipo  = trim($_POST['local_tipo']           ?? 'outro');
        $local_end   = trim($_POST['local_endereco']       ?? '');
        $data_sug    = trim($_POST['data_sugerida']        ?? '') ?: null;
        $hora_sug    = trim($_POST['hora_sugerida']        ?? '') ?: null;
        $num_pessoas = (int)($_POST['num_pessoas']         ?? 0) ?: null;
        $cont_nome   = trim($_POST['contato_nome']         ?? '');
        $cont_tel    = trim($_POST['contato_telefone']     ?? '');
        $descricao   = trim($_POST['descricao']            ?? '');
        $praticas    = trim($_POST['praticas_solicitadas'] ?? '');

        if (!$local_nome) resp(false, 'Informe o nome do local.');
        $tipos_ok = ['ubs','hospital','clinica','empresa','outro'];
        if (!in_array($local_tipo, $tipos_ok)) $local_tipo = 'outro';

        $pdo->prepare("INSERT INTO visitas_externas
                        (solicitante_id,local_nome,local_tipo,local_endereco,data_sugerida,
                         hora_sugerida,num_pessoas,contato_nome,contato_telefone,
                         descricao,praticas_solicitadas,status)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,'pendente')")
            ->execute([$uid,$local_nome,$local_tipo,$local_end??null,$data_sug,
                       $hora_sug,$num_pessoas,$cont_nome??null,$cont_tel??null,
                       $descricao??null,$praticas??null]);
        resp(true, 'Solicitação registrada!', ['id' => (int)$pdo->lastInsertId()]);

    // ── Bloquear paciente ────────────────────────────────────────────────────────
    case 'bloquear_paciente':
        $pac_uid = (int)($_POST['paciente_id']  ?? 0);
        $ate     = trim($_POST['bloqueado_ate'] ?? '');
        if (!$pac_uid) resp(false, 'Paciente inválido.');
        if (!$ate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate))
            resp(false, 'Data de bloqueio inválida.');
        $pdo->prepare("UPDATE pacientes SET bloqueado_ate=? WHERE usuario_id=?")
            ->execute([$ate, $pac_uid]);
        resp(true, 'Paciente bloqueado até ' . date('d/m/Y', strtotime($ate)) . '.');

    // ── Desbloquear paciente ─────────────────────────────────────────────────────
    case 'desbloquear_paciente':
        $pac_uid = (int)($_POST['paciente_id'] ?? 0);
        if (!$pac_uid) resp(false, 'Paciente inválido.');
        $pdo->prepare("UPDATE pacientes SET bloqueado_ate=NULL WHERE usuario_id=?")
            ->execute([$pac_uid]);
        resp(true, 'Paciente desbloqueado.');

    // ── Criar slot ───────────────────────────────────────────────────────────────
    case 'criar_slot':
        $ter_uid   = (int)($_POST['terapeuta_id']  ?? 0);
        $dia       = (int)($_POST['dia_semana']    ?? 0);
        $hora_i    = trim($_POST['hora_inicio']    ?? '');
        $hora_f    = trim($_POST['hora_fim']       ?? '');
        $vagas     = max(1,(int)($_POST['vagas_total'] ?? 1));
        $local     = trim($_POST['local']          ?? '');
        $praticas  = trim($_POST['praticas']       ?? '');
        $acei_i    = isset($_POST['aceita_interno'])  ? 1 : 0;
        $acei_e    = isset($_POST['aceita_externo'])  ? 1 : 0;
        $desc      = trim($_POST['descricao']      ?? '');

        if (!$ter_uid || !$dia || !$hora_i || !$hora_f)
            resp(false, 'Preencha terapeuta, dia e horários.');
        if ($dia < 1 || $dia > 5) resp(false, 'Dia inválido (1=Seg ... 5=Sex).');

        $pdo->prepare("INSERT INTO slots (terapeuta_id,dia_semana,hora_inicio,hora_fim,
                        vagas_total,local,praticas,ativo,aceita_interno,aceita_externo,descricao)
                       VALUES (?,?,?,?,?,?,?,1,?,?,?)")
            ->execute([$ter_uid,$dia,$hora_i,$hora_f,$vagas,
                       $local??null,$praticas??null,$acei_i,$acei_e,$desc??null]);
        resp(true, 'Slot criado!', ['id' => (int)$pdo->lastInsertId()]);

    // ── Editar slot ──────────────────────────────────────────────────────────────
    case 'editar_slot':
        $sid      = (int)($_POST['slot_id']      ?? 0);
        $vagas    = max(1,(int)($_POST['vagas_total'] ?? 1));
        $local    = trim($_POST['local']         ?? '');
        $praticas = trim($_POST['praticas']      ?? '');
        $hora_i   = trim($_POST['hora_inicio']   ?? '');
        $hora_f   = trim($_POST['hora_fim']      ?? '');
        $acei_i   = isset($_POST['aceita_interno'])  ? 1 : 0;
        $acei_e   = isset($_POST['aceita_externo'])  ? 1 : 0;
        $desc     = trim($_POST['descricao']     ?? '');
        if (!$sid) resp(false, 'Slot inválido.');

        $pdo->prepare("UPDATE slots SET hora_inicio=?,hora_fim=?,vagas_total=?,local=?,
                        praticas=?,aceita_interno=?,aceita_externo=?,descricao=? WHERE id=?")
            ->execute([$hora_i,$hora_f,$vagas,$local??null,
                       $praticas??null,$acei_i,$acei_e,$desc??null,$sid]);
        resp(true, 'Slot atualizado!');

    // ── Desativar / Reativar slot ────────────────────────────────────────────────
    case 'desativar_slot':
        $sid = (int)($_POST['slot_id'] ?? 0);
        if (!$sid) resp(false, 'Slot inválido.');
        $pdo->prepare("UPDATE slots SET ativo=0 WHERE id=?")->execute([$sid]);
        resp(true, 'Slot desativado.');

    case 'reativar_slot':
        $sid = (int)($_POST['slot_id'] ?? 0);
        if (!$sid) resp(false, 'Slot inválido.');
        $pdo->prepare("UPDATE slots SET ativo=1 WHERE id=?")->execute([$sid]);
        resp(true, 'Slot reativado.');

    // ── Monitoramento em tempo real ──────────────────────────────────────────────
    case 'monitoramento':
        $hoje = date('Y-m-d');
        $terapeutas = $pdo->query("
            SELECT u.id, u.nome, t.ativo,
                   p.id AS plantao_id, p.hora_inicio, p.hora_fim, p.local, p.max_pacientes,
                   COUNT(sp.id) AS atendidos_hoje
            FROM terapeutas t JOIN usuarios u ON t.usuario_id=u.id
            LEFT JOIN plantoes p ON p.terapeuta_id=u.id AND p.data='{$hoje}' AND p.status='aberto'
            LEFT JOIN sessoes_plantao sp ON sp.plantao_id=p.id
            WHERE t.ativo=1
            GROUP BY u.id,t.ativo,p.id,p.hora_inicio,p.hora_fim,p.local,p.max_pacientes
            ORDER BY u.nome
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($terapeutas as &$ter) {
            $ult = $pdo->prepare("
                SELECT sp.paciente_nome, sp.criado_em FROM sessoes_plantao sp
                WHERE sp.terapeuta_id=(SELECT id FROM terapeutas WHERE usuario_id=?)
                  AND DATE(sp.criado_em)=CURDATE()
                ORDER BY sp.criado_em DESC LIMIT 1
            ");
            $ult->execute([$ter['id']]);
            $ter['ultimo_atendimento'] = $ult->fetch(PDO::FETCH_ASSOC);
        } unset($ter);
        resp(true, '', ['terapeutas' => $terapeutas]);

    // ── Stats gerais ─────────────────────────────────────────────────────────────
    case 'stats':
        $hoje = date('Y-m-d'); $mes = date('Y-m-01');
        $data = $pdo->query("SELECT
          (SELECT COUNT(*) FROM sessoes_plantao WHERE DATE(criado_em)='{$hoje}' AND status='realizado') AS atend_hoje,
          (SELECT COUNT(*) FROM sessoes_plantao WHERE DATE(criado_em)>='{$mes}' AND status='realizado') +
          (SELECT COUNT(*) FROM registros_sessao WHERE DATE(data_sessao)>='{$mes}' AND status='realizado') AS atend_mes,
          (SELECT COUNT(*) FROM ciclos WHERE status='ativo') AS ciclos_ativos,
          (SELECT COUNT(*) FROM terapeutas WHERE ativo=1) AS ter_ativos,
          (SELECT COUNT(*) FROM plantoes WHERE data='{$hoje}' AND status='aberto') AS plantoes_agora,
          (SELECT COUNT(*) FROM registros_sessao WHERE status='faltou') AS total_faltas,
          (SELECT COUNT(*) FROM registros_sessao) AS total_sessoes,
          (SELECT COUNT(*) FROM visitas_externas WHERE status='pendente') AS visitas_pendentes,
          (SELECT COUNT(*) FROM sugestoes WHERE lida=0) AS sugestoes_nao_lidas,
          (SELECT COUNT(*) FROM usuarios WHERE tipo='paciente') AS total_pacientes
        ")->fetch(PDO::FETCH_ASSOC);
        $data['taxa_faltas'] = $data['total_sessoes'] > 0
            ? round(($data['total_faltas']/$data['total_sessoes'])*100, 1) : 0;
        resp(true, '', ['stats' => $data]);

    default:
        resp(false, 'Ação inválida.');
}
} catch (PDOException $e) {
    resp(false, 'Erro BD: ' . $e->getMessage());
} catch (Exception $e) {
    resp(false, 'Erro: ' . $e->getMessage());
}