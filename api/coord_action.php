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
        $tid = (int)($_POST['terapeuta_id'] ?? 0);
        $ativo = (int)($_POST['ativo'] ?? 1);
        $pdo->prepare("UPDATE terapeutas SET ativo=? WHERE id=?")->execute([$ativo, $tid]);
        resp(true, $ativo ? 'Terapeuta ativado.' : 'Terapeuta desativado.');

    // ── Enviar aviso / mensagem ──────────────────────────────────────────────────
    case 'enviar_aviso':
        $titulo = trim($_POST['titulo']       ?? '');
        $texto  = trim($_POST['texto']        ?? '');
        $tipo   = trim($_POST['tipo']         ?? 'info');
        $dest   = trim($_POST['destino']      ?? 'todos');
        $uid_dest = (int)($_POST['usuario_id'] ?? 0);

        if (!$titulo || !$texto) resp(false, 'Preencha título e mensagem.');

        $tipos_ok  = ['evento','urgente','manutencao','info'];
        $dests_ok  = ['todos','paciente','terapeuta','coordenador'];
        if (!in_array($tipo, $tipos_ok))  $tipo = 'info';
        if (!in_array($dest, $dests_ok))  $dest = 'todos';

        // Insere no quadro de avisos (visível nos dashboards)
        $pdo->prepare("INSERT INTO avisos (tipo, titulo, texto, ativo, destino) VALUES (?,?,?,1,?)")
            ->execute([$tipo, $titulo, $texto, $dest]);
        $aviso_id = $pdo->lastInsertId();

        // Também insere em mensagens_coord para histórico
        $pdo->prepare("INSERT INTO mensagens_coord (remetente_id, destinatario, usuario_id, titulo, mensagem, tipo)
                       VALUES (?,?,?,?,?,?)")
            ->execute([$uid, $dest === 'todos' ? 'todos' : ($dest === 'terapeuta' ? 'terapeutas' : 'pacientes'),
                       $uid_dest ?: null, $titulo, $texto, $tipo]);

        resp(true, 'Mensagem enviada!', ['aviso_id' => (int)$aviso_id]);

    // ── Excluir aviso ───────────────────────────────────────────────────────────
    case 'excluir_aviso':
        $aid = (int)($_POST['aviso_id'] ?? 0);
        $pdo->prepare("UPDATE avisos SET ativo=0 WHERE id=?")->execute([$aid]);
        resp(true);

    // ── Aprovar visita ──────────────────────────────────────────────────────────
    case 'aprovar_visita':
        $vid      = (int)($_POST['visita_id'] ?? 0);
        $obs      = trim($_POST['obs']        ?? '');
        $ter_ids  = (array)($_POST['terapeutas'] ?? []);

        $pdo->prepare("UPDATE visitas_externas SET status='aprovada', coord_obs=? WHERE id=?")
            ->execute([$obs, $vid]);

        // Escala os terapeutas selecionados
        $pdo->prepare("DELETE FROM visita_terapeutas WHERE visita_id=?")->execute([$vid]);
        foreach ($ter_ids as $tid) {
            $tid = (int)$tid;
            if (!$tid) continue;
            $pdo->prepare("INSERT IGNORE INTO visita_terapeutas (visita_id, terapeuta_id) VALUES (?,?)")
                ->execute([$vid, $tid]);
            // Notifica o terapeuta
            $pdo->prepare("INSERT INTO notificacoes (destinatario, tipo, titulo, mensagem)
                           VALUES (?,?,?,?)")
                ->execute([$tid, 'geral', 'Você foi escalado para uma visita externa',
                           "Você foi selecionado para participar de uma visita externa. Confira os detalhes no seu painel."]);
        }
        resp(true, 'Visita aprovada!');

    // ── Recusar visita ──────────────────────────────────────────────────────────
    case 'recusar_visita':
        $vid    = (int)($_POST['visita_id']  ?? 0);
        $motivo = trim($_POST['motivo']      ?? '');
        $pdo->prepare("UPDATE visitas_externas SET status='recusada', motivo_recusa=? WHERE id=?")
            ->execute([$motivo, $vid]);
        resp(true, 'Visita recusada.');

    // ── Registrar ação realizada ────────────────────────────────────────────────
    case 'registrar_visita':
        $vid         = (int)($_POST['visita_id']        ?? 0);
        $data_real   = trim($_POST['data_realizada']    ?? date('Y-m-d'));
        $hora_ini    = trim($_POST['hora_inicio']       ?? '');
        $hora_fim    = trim($_POST['hora_fim']          ?? '');
        $local       = trim($_POST['local_confirmado']  ?? '');
        $resumo      = trim($_POST['resumo_sessao']     ?? '');
        $praticas    = trim($_POST['praticas_realizadas'] ?? '');
        $participantes = (array)($_POST['participantes']  ?? []);

        // Upsert registro
        $exists = $pdo->prepare("SELECT id FROM visita_registros WHERE visita_id=?");
        $exists->execute([$vid]); $reg_id = $exists->fetchColumn();

        if ($reg_id) {
            $pdo->prepare("UPDATE visita_registros SET data_realizada=?, hora_inicio=?, hora_fim=?,
                           local_confirmado=?, resumo_sessao=?, praticas_realizadas=?, total_participantes=?
                           WHERE id=?")
                ->execute([$data_real, $hora_ini ?: null, $hora_fim ?: null, $local, $resumo, $praticas,
                           count($participantes), $reg_id]);
        } else {
            $pdo->prepare("INSERT INTO visita_registros
                           (visita_id, data_realizada, hora_inicio, hora_fim, local_confirmado,
                            resumo_sessao, praticas_realizadas, total_participantes)
                           VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$vid, $data_real, $hora_ini ?: null, $hora_fim ?: null, $local,
                           $resumo, $praticas, count($participantes)]);
        }

        // Insere participantes
        $pdo->prepare("DELETE FROM visita_participantes WHERE visita_id=?")->execute([$vid]);
        foreach ($participantes as $p) {
            if (empty($p['nome'])) continue;
            $pdo->prepare("INSERT INTO visita_participantes (visita_id, nome, idade, sexo, pratica, observacao)
                           VALUES (?,?,?,?,?,?)")
                ->execute([$vid, $p['nome'], $p['idade'] ?: null, $p['sexo'] ?: null,
                           $p['pratica'] ?: null, $p['obs'] ?: null]);
        }

        // Marca visita como realizada
        $pdo->prepare("UPDATE visitas_externas SET status='realizada' WHERE id=?")->execute([$vid]);

        resp(true, 'Ação registrada com sucesso!');

    // ── Dados para monitoramento em tempo real ──────────────────────────────────
    case 'monitoramento':
        $terapeutas = $pdo->query("
            SELECT u.id, u.nome,
                   t.ativo,
                   p.id AS plantao_id,
                   p.hora_inicio, p.hora_fim, p.local,
                   COUNT(sp.id) AS atendidos_hoje,
                   p.max_pacientes
            FROM terapeutas t
            JOIN usuarios u ON t.usuario_id = u.id
            LEFT JOIN plantoes p ON p.terapeuta_id = u.id
                AND p.data = CURDATE() AND p.status = 'aberto'
            LEFT JOIN sessoes_plantao sp ON sp.plantao_id = p.id
            WHERE t.ativo = 1
            GROUP BY u.id, t.ativo, p.id, p.hora_inicio, p.hora_fim, p.local, p.max_pacientes
            ORDER BY u.nome
        ")->fetchAll(PDO::FETCH_ASSOC);

        // Último paciente atendido por cada terapeuta
        foreach ($terapeutas as &$ter) {
            $ult = $pdo->prepare("
                SELECT sp.paciente_nome, sp.criado_em
                FROM sessoes_plantao sp
                WHERE sp.terapeuta_id = (SELECT id FROM terapeutas WHERE usuario_id=?)
                  AND DATE(sp.criado_em) = CURDATE()
                ORDER BY sp.criado_em DESC LIMIT 1
            ");
            $ult->execute([$ter['id']]); $ter['ultimo_atendimento'] = $ult->fetch(PDO::FETCH_ASSOC);
        } unset($ter);

        resp(true, '', ['terapeutas' => $terapeutas]);

    // ── Stats gerais completos ──────────────────────────────────────────────────
    case 'stats':
        $hoje    = date('Y-m-d');
        $semana  = date('Y-m-d', strtotime('-7 days'));
        $mes_ini = date('Y-m-01');

        $data = $pdo->query("SELECT
          (SELECT COUNT(*) FROM sessoes_plantao WHERE DATE(criado_em)='{$hoje}' AND status='realizado') AS atend_hoje,
          (SELECT COUNT(*) FROM sessoes_plantao WHERE DATE(criado_em)>='{$semana}' AND status='realizado') AS atend_semana,
          (SELECT COUNT(*) FROM sessoes_plantao WHERE DATE(criado_em)>='{$mes_ini}' AND status='realizado') AS atend_mes,
          (SELECT COUNT(*) FROM registros_sessao WHERE DATE(data_sessao)>='{$mes_ini}' AND status='realizado') AS ciclo_sessoes_mes,
          (SELECT COUNT(*) FROM ciclos WHERE status='ativo') AS ciclos_ativos,
          (SELECT COUNT(DISTINCT r.paciente_id) FROM ciclos c JOIN reservas r ON c.reserva_id=r.id WHERE c.status='ativo') AS pac_ativos,
          (SELECT COUNT(*) FROM terapeutas WHERE ativo=1) AS ter_ativos,
          (SELECT COUNT(*) FROM plantoes WHERE data='{$hoje}' AND status='aberto') AS plantoes_agora,
          (SELECT COUNT(*) FROM registros_sessao WHERE status='faltou') AS total_faltas,
          (SELECT COUNT(*) FROM registros_sessao) AS total_sessoes_ciclo,
          (SELECT COUNT(*) FROM visitas_externas WHERE status='pendente') AS visitas_pendentes,
          (SELECT COUNT(*) FROM sugestoes WHERE lida=0) AS sugestoes_nao_lidas,
          (SELECT COUNT(*) FROM usuarios WHERE tipo='paciente') AS total_pacientes
        ")->fetch(PDO::FETCH_ASSOC);

        $taxa_faltas = $data['total_sessoes_ciclo'] > 0
            ? round(($data['total_faltas'] / $data['total_sessoes_ciclo']) * 100, 1)
            : 0;
        $data['taxa_faltas'] = $taxa_faltas;

        resp(true, '', ['stats' => $data]);

    default:
        resp(false, 'Ação inválida.');
}
} catch (PDOException $e) {
    resp(false, 'Erro BD: ' . $e->getMessage());
} catch (Exception $e) {
    resp(false, 'Erro: ' . $e->getMessage());
}