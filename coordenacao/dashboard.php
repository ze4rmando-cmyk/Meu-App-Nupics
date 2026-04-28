<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'coordenador') {
    header('Location: ../index.php'); exit;
}
require_once '../config/db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$uid  = (int)$_SESSION['usuario_id'];
$nome = $_SESSION['nome'];
$pri  = explode(' ', trim($nome))[0];
$aba  = $_GET['aba'] ?? 'painel';
$hoje = date('Y-m-d');
$mes  = date('Y-m-01');

// ── Stats rápidos ────────────────────────────────────────────────────────────
$stats = $pdo->query("SELECT
  (SELECT COUNT(*) FROM sessoes_plantao WHERE DATE(criado_em)='{$hoje}' AND status='realizado') AS atend_hoje,
  (SELECT COUNT(*) FROM sessoes_plantao WHERE DATE(criado_em)>='{$mes}' AND status='realizado') +
  (SELECT COUNT(*) FROM registros_sessao WHERE DATE(data_sessao)>='{$mes}' AND status='realizado') AS atend_mes,
  (SELECT COUNT(*) FROM ciclos WHERE status='ativo') AS ciclos_ativos,
  (SELECT COUNT(DISTINCT r.paciente_id) FROM ciclos c JOIN reservas r ON c.reserva_id=r.id WHERE c.status='ativo') AS pac_ativos,
  (SELECT COUNT(*) FROM terapeutas WHERE ativo=1) AS ter_ativos,
  (SELECT COUNT(*) FROM plantoes WHERE data='{$hoje}' AND status='aberto') AS plantoes_agora,
  (SELECT COUNT(*) FROM registros_sessao WHERE status='faltou') AS total_faltas,
  (SELECT COUNT(*) FROM registros_sessao) AS total_sessoes,
  (SELECT COUNT(*) FROM visitas_externas WHERE status='pendente') AS visitas_pendentes,
  (SELECT COUNT(*) FROM sugestoes WHERE lida=0) AS sugestoes_nao_lidas,
  (SELECT COUNT(*) FROM usuarios WHERE tipo='paciente') AS total_pacientes,
  (SELECT COUNT(*) FROM reservas WHERE status='pendente') AS reservas_pendentes
")->fetch(PDO::FETCH_ASSOC);

$taxa_faltas = $stats['total_sessoes'] > 0
    ? round(($stats['total_faltas']/$stats['total_sessoes'])*100,1) : 0;

// ── Alertas críticos ─────────────────────────────────────────────────────────
$alertas = [];
if ($taxa_faltas > 20)
    $alertas[] = ['danger', "Taxa de faltas em {$taxa_faltas}% — acima do ideal"];
if ((int)$stats['visitas_pendentes'] > 0)
    $alertas[] = ['info', "{$stats['visitas_pendentes']} visita(s) aguardando análise"];
if ((int)$stats['sugestoes_nao_lidas'] > 0)
    $alertas[] = ['info', "{$stats['sugestoes_nao_lidas']} sugestão(ões) não lida(s)"];
if ((int)$stats['reservas_pendentes'] > 0)
    $alertas[] = ['warning', "{$stats['reservas_pendentes']} reserva(s) pendentes de confirmação"];

// Pacientes sem atendimento há +15 dias
try {
    $sem_atend = $pdo->query("
        SELECT u.nome, DATEDIFF(CURDATE(), MAX(COALESCE(rs.data_sessao, r.data_sessao))) AS dias
        FROM ciclos c JOIN reservas r ON c.reserva_id=r.id JOIN usuarios u ON r.paciente_id=u.id
        LEFT JOIN registros_sessao rs ON rs.ciclo_id=c.id AND rs.status='realizado'
        WHERE c.status='ativo' GROUP BY c.id, u.nome, r.data_sessao HAVING dias > 15 LIMIT 3
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sem_atend as $s)
        $alertas[] = ['warning', "Paciente {$s['nome']} sem atendimento há {$s['dias']} dias"];
} catch(Exception $e) {}

// ── Dados por aba ────────────────────────────────────────────────────────────
$terapeutas_hoje = $atividade_recente = $terapeutas_lista = $pacientes_lista = [];
$ciclos_todos = $agenda_global = $agenda_por_dia = $visitas_lista = [];
$avisos_lista = $sugestoes_lista = [];
$rel_data = $ranking_ter = [];
$periodo_ini = $_GET['ini'] ?? $mes;
$periodo_fim = $_GET['fim'] ?? $hoje;

if ($aba === 'painel') {
    $terapeutas_hoje = $pdo->query("
        SELECT u.id, u.nome, t.especialidade,
               p.id AS plantao_id, p.hora_inicio, p.hora_fim, p.max_pacientes,
               COUNT(sp.id) AS atendidos
        FROM terapeutas t JOIN usuarios u ON t.usuario_id=u.id
        LEFT JOIN plantoes p ON p.terapeuta_id=u.id AND p.data='{$hoje}' AND p.status='aberto'
        LEFT JOIN sessoes_plantao sp ON sp.plantao_id=p.id
        WHERE t.ativo=1
        GROUP BY u.id, t.especialidade, p.id, p.hora_inicio, p.hora_fim, p.max_pacientes
        ORDER BY p.id DESC, u.nome
    ")->fetchAll(PDO::FETCH_ASSOC);

    try {
        $atividade_recente = $pdo->query("
            (SELECT 'plantao' AS tipo, sp.criado_em AS dt, sp.paciente_nome AS obj,
                    sp.tipo_pratica AS extra, u.nome AS terapeuta
             FROM sessoes_plantao sp JOIN terapeutas t ON sp.terapeuta_id=t.id
             JOIN usuarios u ON t.usuario_id=u.id ORDER BY sp.criado_em DESC LIMIT 4)
            UNION ALL
            (SELECT 'ciclo', rs.criado_em, u.nome, rs.evolucao, u2.nome
             FROM registros_sessao rs JOIN ciclos c ON rs.ciclo_id=c.id
             JOIN reservas r ON c.reserva_id=r.id JOIN usuarios u ON r.paciente_id=u.id
             JOIN slots s ON r.slot_id=s.id JOIN usuarios u2 ON s.terapeuta_id=u2.id
             ORDER BY rs.criado_em DESC LIMIT 4)
            ORDER BY dt DESC LIMIT 7
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {}
}

if ($aba === 'terapeutas') {
    $terapeutas_lista = $pdo->query("
        SELECT u.id, u.nome, u.email, u.telefone, u.criado_em,
               t.id AS ter_id, t.especialidade, t.periodo, t.ativo,
               (SELECT COUNT(*) FROM ciclos c JOIN reservas r ON c.reserva_id=r.id
                JOIN slots s ON r.slot_id=s.id WHERE s.terapeuta_id=u.id AND c.status='ativo') AS ciclos_ativos,
               (SELECT COUNT(*) FROM ciclos c JOIN reservas r ON c.reserva_id=r.id
                JOIN slots s ON r.slot_id=s.id WHERE s.terapeuta_id=u.id AND c.status='concluido') AS ciclos_concluidos,
               (SELECT COUNT(*) FROM sessoes_plantao sp JOIN terapeutas tt ON sp.terapeuta_id=tt.id WHERE tt.usuario_id=u.id) AS plantoes_total,
               (SELECT COUNT(*) FROM registros_sessao rs JOIN ciclos c ON rs.ciclo_id=c.id
                JOIN reservas r ON c.reserva_id=r.id JOIN slots s ON r.slot_id=s.id
                WHERE s.terapeuta_id=u.id AND rs.status='faltou') AS total_faltas,
               (SELECT COUNT(*) FROM registros_sessao rs JOIN ciclos c ON rs.ciclo_id=c.id
                JOIN reservas r ON c.reserva_id=r.id JOIN slots s ON r.slot_id=s.id
                WHERE s.terapeuta_id=u.id) AS total_sessoes_ter
        FROM terapeutas t JOIN usuarios u ON t.usuario_id=u.id ORDER BY t.ativo DESC, u.nome
    ")->fetchAll(PDO::FETCH_ASSOC);
}

if ($aba === 'pacientes') {
    $busca = trim($_GET['q'] ?? '');
    $param = $busca ? "%{$busca}%" : '%';
    $stmt = $pdo->prepare("
        SELECT u.id, u.nome, u.email, u.telefone, u.criado_em,
               p.data_nasc, p.sexo, p.vinculo, p.objetivos, p.bloqueado_ate,
               (SELECT COUNT(*) FROM ciclos c2 JOIN reservas r2 ON c2.reserva_id=r2.id WHERE r2.paciente_id=u.id) AS total_ciclos,
               (SELECT c3.status FROM ciclos c3 JOIN reservas r3 ON c3.reserva_id=r3.id
                WHERE r3.paciente_id=u.id AND c3.status='ativo' LIMIT 1) AS ciclo_ativo,
               (SELECT u2.nome FROM ciclos c4 JOIN reservas r4 ON c4.reserva_id=r4.id
                JOIN slots s4 ON r4.slot_id=s4.id JOIN usuarios u2 ON s4.terapeuta_id=u2.id
                WHERE r4.paciente_id=u.id AND c4.status='ativo' LIMIT 1) AS terapeuta_atual
        FROM usuarios u JOIN pacientes p ON p.usuario_id=u.id
        WHERE u.tipo='paciente' AND (u.nome LIKE ? OR u.email LIKE ?) ORDER BY u.nome
    ");
    $stmt->execute([$param, $param]);
    $pacientes_lista = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($aba === 'ciclos') {
    $ciclos_todos = $pdo->query("
        SELECT c.id AS ciclo_id, c.total_sessoes, c.faltas, c.status AS ciclo_status,
               r.paciente_id AS pac_uid, u.nome AS pnome, u2.nome AS tnome,
               s.hora_inicio, s.hora_fim, s.dia_semana, s.praticas,
               (SELECT COUNT(*) FROM anamnese_inicial WHERE ciclo_id=c.id) AS tem_anamnese,
               (SELECT COUNT(*) FROM registros_sessao WHERE ciclo_id=c.id AND status='realizado') AS followups_ok
        FROM ciclos c JOIN reservas r ON c.reserva_id=r.id
        JOIN usuarios u ON r.paciente_id=u.id JOIN slots s ON r.slot_id=s.id
        JOIN usuarios u2 ON s.terapeuta_id=u2.id
        ORDER BY FIELD(c.status,'ativo','concluido','cancelado'), s.dia_semana
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ciclos_todos as &$ct) {
        $ct['sessoes_feitas'] = (int)$ct['tem_anamnese']+(int)$ct['followups_ok'];
        $ct['proxima_sessao'] = $ct['tem_anamnese']==0 ? 1 : ($ct['sessoes_feitas']+1);
    } unset($ct);
}

if ($aba === 'agenda') {
    $agenda_global = $pdo->query("
        SELECT s.id, s.dia_semana, s.hora_inicio, s.hora_fim, s.local, s.praticas, s.vagas_total,
               u.nome AS ter_nome,
               s.vagas_total - IFNULL((
                   SELECT COUNT(*) FROM reservas r WHERE r.slot_id=s.id AND r.status NOT IN ('cancelado')
                   AND r.data_sessao=DATE_ADD(CURDATE(),INTERVAL MOD(s.dia_semana-1-WEEKDAY(CURDATE())+7,7) DAY)
               ),0) AS vagas_disp,
               (SELECT GROUP_CONCAT(u2.nome SEPARATOR '|') FROM reservas r2
                JOIN usuarios u2 ON r2.paciente_id=u2.id WHERE r2.slot_id=s.id AND r2.status NOT IN ('cancelado')
                AND r2.data_sessao=DATE_ADD(CURDATE(),INTERVAL MOD(s.dia_semana-1-WEEKDAY(CURDATE())+7,7) DAY)
               ) AS pac_nomes
        FROM slots s JOIN usuarios u ON s.terapeuta_id=u.id WHERE s.ativo=1
        ORDER BY s.dia_semana, s.hora_inicio
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($agenda_global as $ag) $agenda_por_dia[(int)$ag['dia_semana']][] = $ag;
}

if ($aba === 'comunicacao') {
    $avisos_lista = $pdo->query("SELECT * FROM avisos WHERE ativo=1 ORDER BY criado_em DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    $sugestoes_lista = $pdo->query("
        SELECT s.*, u.nome AS pac_nome FROM sugestoes s JOIN usuarios u ON s.paciente_id=u.id
        ORDER BY s.lida ASC, s.criado_em DESC LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);
}

if ($aba === 'visitas') {
    $visitas_lista = $pdo->query("
        SELECT ve.*,
               vr.id AS reg_id, vr.data_realizada, vr.total_participantes, vr.resumo_sessao,
               (SELECT GROUP_CONCAT(u.nome SEPARATOR ', ') FROM visita_terapeutas vt
                JOIN usuarios u ON vt.terapeuta_id=u.id WHERE vt.visita_id=ve.id) AS terapeutas_escalados
        FROM visitas_externas ve LEFT JOIN visita_registros vr ON vr.visita_id=ve.id
        ORDER BY FIELD(ve.status,'pendente','aprovada','realizada','recusada','cancelada'), ve.data_sugerida
    ")->fetchAll(PDO::FETCH_ASSOC);
}

if ($aba === 'relatorios') {
    $rel = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM sessoes_plantao WHERE DATE(criado_em) BETWEEN ? AND ? AND status='realizado') AS plt_realizados,
        (SELECT COUNT(*) FROM registros_sessao WHERE data_sessao BETWEEN ? AND ? AND status='realizado') AS ciclo_realizados,
        (SELECT COUNT(*) FROM registros_sessao WHERE data_sessao BETWEEN ? AND ? AND status='faltou') AS faltas_periodo,
        (SELECT COUNT(DISTINCT r.paciente_id) FROM reservas r WHERE DATE(r.criado_em) BETWEEN ? AND ?) AS novos_pacientes,
        (SELECT COUNT(*) FROM ciclos WHERE DATE(criado_em) BETWEEN ? AND ? AND status='concluido') AS ciclos_concluidos_per
    ");
    $rel->execute([$periodo_ini,$periodo_fim,$periodo_ini,$periodo_fim,$periodo_ini,$periodo_fim,
                   $periodo_ini,$periodo_fim,$periodo_ini,$periodo_fim]);
    $rel_data = $rel->fetch(PDO::FETCH_ASSOC);

    $rk = $pdo->prepare("
        SELECT u.nome, COUNT(sp.id) AS plt_count,
               (SELECT COUNT(*) FROM registros_sessao rs JOIN ciclos c ON rs.ciclo_id=c.id
                JOIN reservas r ON c.reserva_id=r.id JOIN slots s ON r.slot_id=s.id
                WHERE s.terapeuta_id=u.id AND rs.data_sessao BETWEEN ? AND ? AND rs.status='realizado') AS ciclo_count
        FROM sessoes_plantao sp JOIN terapeutas t ON sp.terapeuta_id=t.id JOIN usuarios u ON t.usuario_id=u.id
        WHERE DATE(sp.criado_em) BETWEEN ? AND ?
        GROUP BY u.id, u.nome ORDER BY plt_count DESC LIMIT 10
    ");
    $rk->execute([$periodo_ini,$periodo_fim,$periodo_ini,$periodo_fim]);
    $ranking_ter = $rk->fetchAll(PDO::FETCH_ASSOC);
}

// ── Auxiliares ───────────────────────────────────────────────────────────────
$todos_terapeutas = $pdo->query("
    SELECT u.id, u.nome, t.especialidade FROM terapeutas t JOIN usuarios u ON t.usuario_id=u.id
    WHERE t.ativo=1 ORDER BY u.nome
")->fetchAll(PDO::FETCH_ASSOC);

$praticas_opcoes = ['Massoterapia','Ventosaterapia','Acupuntura','Reiki','Auriculoterapia','Meditação','Aromaterapia','Reflexologia','Outros'];
$dias_full = [1=>'Segunda',2=>'Terça',3=>'Quarta',4=>'Quinta',5=>'Sexta'];
$status_badge = ['pendente'=>'bg-amber-100 text-amber-700','aprovada'=>'bg-emerald-100 text-emerald-700',
                 'recusada'=>'bg-red-100 text-red-600','realizada'=>'bg-indigo-100 text-indigo-700','cancelada'=>'bg-gray-100 text-gray-600'];

// ── Stats gerais ──────────────────────────────────────────────────────────────
$stats_gerais = $pdo->query("SELECT
  (SELECT COUNT(*) FROM ciclos WHERE status='concluido') AS ciclos_conc,
  (SELECT COUNT(*) FROM ciclos WHERE status='ativo') AS ciclos_atv,
  (SELECT COUNT(*) FROM sessoes_plantao WHERE status='realizado') AS plt_total,
  (SELECT COUNT(DISTINCT r.paciente_id) FROM reservas r) AS pac_atend,
  (SELECT COUNT(*) FROM usuarios WHERE tipo='paciente') AS pac_cad
")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>NUPICS | Coordenação – <?= ucfirst($aba) ?></title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script>tailwind.config={theme:{extend:{colors:{
  "surface":"#fff7fc","on-surface":"#201923","outline-variant":"#d0c2d3","outline":"#7f7383",
  "surface-container-low":"#fdeffe","surface-container":"#f7eaf8","surface-container-high":"#f2e4f2",
  "surface-container-highest":"#ecdeed","on-surface-variant":"#4d4351",
  "primary":"#4e0078","secondary":"#b7004d","background":"#fff7fc",
  "error":"#ba1a1a","error-container":"#ffdad6","on-error-container":"#93000a"
},fontFamily:{"headline":["Plus Jakarta Sans"],"body":["Manrope"]}}}}</script>
<style>
  body{font-family:"Manrope",sans-serif;background:radial-gradient(135deg,#f4d9ff 0%,#fff7fc 45%,#fdeffe 100%)}
  h1,h2,h3,h4{font-family:"Plus Jakarta Sans",sans-serif}
  .material-symbols-outlined{font-variation-settings:"FILL" 0,"wght" 400,"GRAD" 0,"opsz" 24}
  .glass{background:rgba(255,255,255,.78);backdrop-filter:blur(18px) saturate(180%);border:1px solid rgba(255,255,255,.45)}
  .nav-tab{display:flex;align-items:center;gap:5px;padding:9px 15px;border-radius:99px;font-size:.78rem;font-weight:600;color:#4d4351;transition:.15s;white-space:nowrap;text-decoration:none}
  .nav-tab:hover{background:rgba(78,0,120,.07);color:#4e0078}
  .nav-tab.active{background:#4e0078;color:#fff}
  .nav-tab .material-symbols-outlined{font-size:17px}
  .modal-wrap{display:none}.modal-wrap.open{display:flex;animation:mfade .18s ease}
  @keyframes mfade{from{opacity:0}to{opacity:1}}
  .modal-card{animation:mup .22s cubic-bezier(.22,1,.36,1)}
  @keyframes mup{from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:translateY(0)}}
  .campo{display:flex;align-items:flex-start;background:rgba(255,255,255,.7);border:1.5px solid #d0c2d3;border-radius:12px;overflow:hidden;transition:.15s}
  .campo:focus-within{border-color:#4e0078;box-shadow:0 0 0 3px rgba(78,0,120,.12)}
  .campo .ic{padding:10px 10px 0;color:#7f7383;flex-shrink:0}
  .campo input,.campo select,.campo textarea{flex:1;border:none;background:transparent;padding:10px 12px 10px 0;font-size:.84rem;color:#201923;font-family:"Manrope",sans-serif;outline:none;min-width:0;resize:vertical}
  .pill-opt{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:99px;border:1.5px solid #d0c2d3;font-size:.75rem;font-weight:600;cursor:pointer;background:rgba(255,255,255,.6);color:#4d4351;user-select:none;transition:.15s}
  .pill-opt:has(input:checked){background:#4e0078;color:#fff;border-color:#4e0078}
  .pill-opt input{display:none}
  .pdot{width:1.4rem;height:1.4rem;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.58rem;font-weight:700}
  .pdot.done{background:#4e0078;color:#fff}.pdot.curr{background:#4e0078;color:#fff;box-shadow:0 0 0 3px rgba(78,0,120,.2)}.pdot.idle{background:#ecdeed;color:#7f7383}
  .alert-warning{background:#fef3c7;border-left:3px solid #f59e0b;color:#92400e}
  .alert-danger{background:#fee2e2;border-left:3px solid #ef4444;color:#991b1b}
  .alert-info{background:#eff6ff;border-left:3px solid #3b82f6;color:#1e40af}
  .stat-bar{height:5px;border-radius:99px;background:linear-gradient(90deg,#4e0078,#b7004d)}
  .cron-slot{border-radius:9px;padding:7px 9px;margin-bottom:3px;font-size:.72rem}
  ::-webkit-scrollbar{width:4px;height:4px}::-webkit-scrollbar-thumb{background:#d0c2d3;border-radius:99px}
  textarea:focus,input:focus,select:focus{outline:none;box-shadow:0 0 0 3px rgba(78,0,120,.12)}
</style>
</head>
<body class="text-on-background min-h-screen flex flex-col">

<!-- HEADER -->
<header class="fixed top-0 w-full z-50 bg-white/65 backdrop-blur-md shadow-[0_2px_20px_rgba(32,25,35,.07)]">
  <div class="max-w-7xl mx-auto px-4 md:px-8">
    <div class="flex items-center justify-between h-14 border-b border-outline-variant/20">
      <div class="flex items-center gap-3">
        <span class="text-lg font-extrabold bg-gradient-to-r from-purple-700 to-pink-600 bg-clip-text text-transparent font-['Plus_Jakarta_Sans']">NUPICS</span>
        <span class="hidden sm:inline text-xs font-bold uppercase tracking-widest text-outline px-2 py-0.5 rounded-full border border-outline-variant">Coordenação</span>
      </div>
      <div class="flex items-center gap-3">
        <?php if ((int)$stats['visitas_pendentes']>0): ?>
        <a href="?aba=visitas" class="hidden sm:flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-amber-500 text-white text-xs font-bold hover:opacity-90">
          <span class="material-symbols-outlined text-sm">directions_car</span><?= $stats['visitas_pendentes'] ?> visita<?= $stats['visitas_pendentes']>1?'s':'' ?> pendente<?= $stats['visitas_pendentes']>1?'s':'' ?>
        </a>
        <?php endif; ?>
        <?php if ((int)$stats['sugestoes_nao_lidas']>0): ?>
        <a href="?aba=comunicacao" class="w-8 h-8 flex items-center justify-center rounded-full bg-secondary/10 relative hover:bg-secondary/20">
          <span class="material-symbols-outlined text-secondary text-base">chat_bubble</span>
          <span class="absolute -top-0.5 -right-0.5 w-4 h-4 bg-secondary text-white text-[9px] font-bold rounded-full flex items-center justify-center"><?= min(9,(int)$stats['sugestoes_nao_lidas']) ?></span>
        </a>
        <?php endif; ?>
        <span class="hidden md:block text-sm font-semibold"><?= htmlspecialchars($pri) ?></span>
        <a href="../logout.php" class="text-xs font-semibold text-on-surface-variant hover:text-secondary">Sair</a>
      </div>
    </div>
    <div class="flex items-center gap-1 overflow-x-auto py-2">
      <?php foreach ([
        'painel'      => ['dashboard',         'Painel'],
        'terapeutas'  => ['medical_services',  'Terapeutas'],
        'pacientes'   => ['group',             'Pacientes'],
        'agenda'      => ['calendar_month',    'Agenda'],
        'ciclos'      => ['event_repeat',      'Ciclos'],
        'comunicacao' => ['campaign',          'Comunicação'.($stats['sugestoes_nao_lidas']>0?' ('.$stats['sugestoes_nao_lidas'].')':'')],
        'visitas'     => ['directions_car',    'Visitas'.($stats['visitas_pendentes']>0?' ('.$stats['visitas_pendentes'].')':'')],
        'relatorios'  => ['bar_chart',         'Relatórios'],
        'perfil'      => ['manage_accounts',   'Perfil'],
      ] as $k=>[$ic,$lb]): ?>
      <a href="?aba=<?= $k ?>" class="nav-tab <?= $aba===$k?'active':'' ?>">
        <span class="material-symbols-outlined"><?= $ic ?></span><?= $lb ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</header>

<main class="flex-grow pt-[112px] pb-16 px-4 md:px-8 max-w-7xl mx-auto w-full">

<?php if ($aba === 'painel'): ?>
<div class="space-y-7">
  <div>
    <h1 class="text-3xl font-extrabold text-primary tracking-tight mb-1">Painel da Coordenação</h1>
    <p class="text-sm text-on-surface-variant"><?= date('d/m/Y') ?> · Visão geral do projeto NUPICS</p>
  </div>

  <?php if (!empty($alertas)): ?>
  <div class="space-y-2">
    <?php foreach ($alertas as [$tipo,$msg]): ?>
    <div class="alert-<?= $tipo ?> rounded-xl px-4 py-3 flex items-center gap-3 text-sm font-medium">
      <span class="material-symbols-outlined text-lg shrink-0"><?= $tipo==='danger'?'error':($tipo==='warning'?'warning':'info') ?></span>
      <?= htmlspecialchars($msg) ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Stats principais -->
  <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
    <?php foreach ([
      ['Atendimentos hoje', $stats['atend_hoje'],     'calendar_today',   'text-primary',    'bg-primary/5'],
      ['Atend. no mês',     $stats['atend_mes'],      'bar_chart',        'text-indigo-700', 'bg-indigo-50'],
      ['Pacientes ativos',  $stats['pac_ativos'],     'group',            'text-emerald-700','bg-emerald-50'],
      ['Plantões abertos',  $stats['plantoes_agora'], 'local_hospital',   'text-emerald-600','bg-emerald-50'],
      ['Taxa de faltas',    $taxa_faltas.'%',          'warning',          $taxa_faltas>20?'text-red-600':'text-amber-700',$taxa_faltas>20?'bg-red-50':'bg-amber-50'],
      ['Terapeutas ativos', $stats['ter_ativos'],     'medical_services', 'text-purple-700', 'bg-purple-50'],
    ] as [$l,$v,$ic,$tc,$bc]): ?>
    <div class="glass rounded-2xl p-4 <?= $bc ?> border border-outline-variant/20">
      <div class="flex items-center justify-between mb-1">
        <p class="text-[10px] font-bold uppercase tracking-widest <?= $tc ?>/70"><?= $l ?></p>
        <span class="material-symbols-outlined <?= $tc ?> text-base"><?= $ic ?></span>
      </div>
      <p class="text-2xl font-extrabold <?= $tc ?>"><?= $v ?></p>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-7">
    <!-- Monitoramento em tempo real -->
    <div class="lg:col-span-2 glass rounded-3xl p-6 border border-outline-variant/20">
      <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-2">
          <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
          <h2 class="font-headline font-bold text-on-surface">Monitoramento em tempo real</h2>
        </div>
        <a href="?aba=painel" class="text-xs font-bold text-primary hover:underline flex items-center gap-1">
          <span class="material-symbols-outlined text-sm">refresh</span>Atualizar
        </a>
      </div>
      <div class="space-y-2.5">
        <?php foreach ($terapeutas_hoje as $tt):
          $tem = !empty($tt['plantao_id']);
        ?>
        <div class="flex items-center gap-3 rounded-2xl px-4 py-3 border border-outline-variant/15 <?= $tem?'bg-emerald-50/60 border-emerald-200/60':'bg-surface-container-low/50' ?>">
          <div class="w-9 h-9 rounded-full flex items-center justify-center shrink-0 <?= $tem?'bg-emerald-100 text-emerald-600':'bg-surface-container-highest text-outline' ?>">
            <span class="material-symbols-outlined text-base"><?= $tem?'local_hospital':'person' ?></span>
          </div>
          <div class="flex-grow min-w-0">
            <p class="font-bold text-sm text-on-surface truncate"><?= htmlspecialchars($tt['nome']) ?></p>
            <p class="text-xs text-on-surface-variant"><?= htmlspecialchars($tt['especialidade']) ?></p>
          </div>
          <div class="text-right shrink-0">
            <?php if ($tem): ?>
            <p class="text-xs font-bold text-emerald-700 flex items-center gap-1 justify-end">
              <span class="material-symbols-outlined text-sm">radio_button_checked</span>Em plantão · <?= $tt['atendidos'] ?>/<?= $tt['max_pacientes'] ?>
            </p>
            <p class="text-[10px] text-emerald-600"><?= $tt['hora_inicio'] ?> – <?= $tt['hora_fim'] ?></p>
            <?php else: ?>
            <span class="text-xs text-outline font-medium">Sem plantão hoje</span>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($terapeutas_hoje)): ?>
        <p class="text-sm text-on-surface-variant text-center py-6">Nenhum terapeuta ativo cadastrado.</p>
        <?php endif; ?>
      </div>
    </div>

    <!-- Lateral -->
    <div class="space-y-5">
      <!-- Fases dos ciclos -->
      <div class="glass rounded-2xl p-5 border border-outline-variant/20">
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-outlined text-secondary">event_repeat</span>
          <h3 class="font-headline font-bold text-on-surface text-sm">Fases dos ciclos</h3>
        </div>
        <?php
        try {
          $fases = $pdo->query("
            SELECT
              SUM(CASE WHEN tem=0 THEN 1 ELSE 0 END) AS s1_pend,
              SUM(CASE WHEN tem=1 AND fw=0 THEN 1 ELSE 0 END) AS s2,
              SUM(CASE WHEN tem=1 AND fw=1 THEN 1 ELSE 0 END) AS s3,
              SUM(CASE WHEN tem=1 AND fw=2 THEN 1 ELSE 0 END) AS s4,
              SUM(CASE WHEN tem=1 AND fw>=3 THEN 1 ELSE 0 END) AS final
            FROM (SELECT c.id,
              (SELECT COUNT(*) FROM anamnese_inicial WHERE ciclo_id=c.id) AS tem,
              (SELECT COUNT(*) FROM registros_sessao WHERE ciclo_id=c.id AND status='realizado') AS fw
              FROM ciclos c WHERE c.status='ativo') sub
          ")->fetch(PDO::FETCH_ASSOC);
        } catch(Exception $e) { $fases = ['s1_pend'=>0,'s2'=>0,'s3'=>0,'s4'=>0,'final'=>0]; }
        foreach ([
          ['Aguardando S1',   $fases['s1_pend'],'text-amber-600', 'bg-amber-100'],
          ['S1 → S2',        $fases['s2'],     'text-blue-600',  'bg-blue-100'],
          ['S2 → S3',        $fases['s3'],     'text-purple-600','bg-purple-100'],
          ['S3 → S4',        $fases['s4'],     'text-indigo-600','bg-indigo-100'],
          ['Pronto relatório',$fases['final'],  'text-emerald-600','bg-emerald-100'],
        ] as [$l,$v,$tc,$bc]): ?>
        <div class="flex items-center justify-between py-1.5 border-b border-outline-variant/15 last:border-0">
          <span class="text-xs text-on-surface-variant"><?= $l ?></span>
          <span class="text-xs font-extrabold px-2.5 py-1 rounded-full <?= $bc ?> <?= $tc ?>"><?= (int)$v ?></span>
        </div>
        <?php endforeach; ?>
        <a href="?aba=ciclos" class="block text-xs font-bold text-primary hover:underline mt-3 text-center">Ver todos →</a>
      </div>

      <!-- Desempenho rápido -->
      <div class="glass rounded-2xl p-5 border border-outline-variant/20">
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-outlined text-primary">leaderboard</span>
          <h3 class="font-headline font-bold text-on-surface text-sm">Desempenho do mês</h3>
        </div>
        <?php
        $desemp = $pdo->query("
          SELECT u.nome, COUNT(DISTINCT sp.id) AS plt
          FROM terapeutas t JOIN usuarios u ON t.usuario_id=u.id
          LEFT JOIN sessoes_plantao sp ON sp.terapeuta_id=t.id AND DATE(sp.criado_em)>='".date('Y-m-01')."'
          WHERE t.ativo=1 GROUP BY u.id, u.nome ORDER BY plt DESC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);
        $mx = max(1, ...array_map(fn($d)=>(int)$d['plt'], $desemp ?: [['plt'=>0]]));
        foreach ($desemp as $d): $pct=min(100,round(((int)$d['plt']/$mx)*100)); ?>
        <div class="mb-2.5">
          <div class="flex justify-between text-xs mb-1">
            <span class="text-on-surface-variant truncate max-w-[130px]"><?= htmlspecialchars(explode(' ',$d['nome'])[0]) ?></span>
            <span class="font-bold text-primary"><?= $d['plt'] ?></span>
          </div>
          <div class="h-1.5 rounded-full bg-surface-container-highest overflow-hidden">
            <div class="h-full stat-bar" style="width:<?= $pct ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
        <a href="?aba=terapeutas" class="block text-xs font-bold text-primary hover:underline mt-3 text-center">Ver todos →</a>
      </div>

      <!-- Atividade recente -->
      <?php if (!empty($atividade_recente)): ?>
      <div class="glass rounded-2xl p-5 border border-outline-variant/20">
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-outlined text-outline">history</span>
          <h3 class="font-headline font-bold text-on-surface text-sm">Atividade recente</h3>
        </div>
        <div class="space-y-2.5">
          <?php foreach ($atividade_recente as $at): $isp=$at['tipo']==='plantao'; ?>
          <div class="flex items-start gap-2.5">
            <div class="w-7 h-7 rounded-full flex items-center justify-center shrink-0 <?= $isp?'bg-emerald-100 text-emerald-600':'bg-indigo-100 text-indigo-600' ?>">
              <span class="material-symbols-outlined text-xs"><?= $isp?'local_hospital':'verified' ?></span>
            </div>
            <div class="flex-grow min-w-0">
              <p class="text-xs font-bold truncate"><?= htmlspecialchars($at['obj']) ?></p>
              <p class="text-[10px] text-on-surface-variant"><?= $isp?'Plantão · '.htmlspecialchars($at['extra']??''):'Ciclo · '.htmlspecialchars($at['terapeuta']??'') ?></p>
            </div>
            <span class="text-[10px] text-outline shrink-0"><?= date('d/m H:i',strtotime($at['dt'])) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Stats gerais -->
      <div class="glass rounded-2xl p-5 border border-outline-variant/20">
        <h3 class="font-headline font-bold text-on-surface text-sm mb-3">Projeto NUPICS</h3>
        <?php foreach ([
          ['Pacientes atendidos', $stats_gerais['pac_atend']],
          ['Ciclos concluídos',   $stats_gerais['ciclos_conc']],
          ['Plantões realizados', $stats_gerais['plt_total']],
          ['Pacientes cadastrados',$stats_gerais['pac_cad']],
        ] as [$l,$v]): ?>
        <div class="flex justify-between py-1.5 border-b border-outline-variant/15 last:border-0">
          <span class="text-xs text-on-surface-variant"><?= $l ?></span>
          <span class="text-sm font-extrabold text-primary"><?= $v ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php elseif ($aba === 'terapeutas'): ?>
<div class="mb-6"><h1 class="text-2xl font-extrabold text-primary">Gestão de Terapeutas</h1></div>
<div class="space-y-3">
  <?php foreach ($terapeutas_lista as $ter):
    $taxa_f = (int)$ter['total_sessoes_ter'] > 0 ? round(((int)$ter['total_faltas']/(int)$ter['total_sessoes_ter'])*100,1) : 0;
  ?>
  <div class="glass rounded-2xl p-5 border border-outline-variant/20">
    <div class="flex flex-col sm:flex-row sm:items-center gap-4">
      <div class="flex-grow min-w-0">
        <div class="flex flex-wrap items-center gap-2 mb-1">
          <h3 class="font-headline font-bold text-on-surface"><?= htmlspecialchars($ter['nome']) ?></h3>
          <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $ter['ativo']?'bg-emerald-100 text-emerald-700':'bg-red-50 text-red-500' ?>"><?= $ter['ativo']?'Ativo':'Inativo' ?></span>
          <?php if ($taxa_f>20): ?><span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-700">⚠️ Alta taxa faltas</span><?php endif; ?>
        </div>
        <p class="text-xs text-on-surface-variant"><?= htmlspecialchars($ter['especialidade']) ?> · <?= htmlspecialchars($ter['periodo']) ?></p>
        <?php if ($ter['email']): ?><p class="text-xs text-on-surface-variant"><?= htmlspecialchars($ter['email']) ?></p><?php endif; ?>
        <div class="flex flex-wrap gap-x-5 mt-2.5">
          <?php foreach ([['Ciclos ativos',(int)$ter['ciclos_ativos'],'text-primary'],['Concluídos',(int)$ter['ciclos_concluidos'],'text-indigo-700'],['Plantões',(int)$ter['plantoes_total'],'text-emerald-700'],['Taxa faltas',$taxa_f.'%',$taxa_f>20?'text-red-600':'text-on-surface-variant']] as [$l,$v,$tc]): ?>
          <div><p class="text-[10px] text-on-surface-variant uppercase tracking-widest"><?= $l ?></p><p class="text-sm font-extrabold <?= $tc ?>"><?= $v ?></p></div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="flex flex-col sm:flex-row gap-2 shrink-0">
        <?php if ($ter['ativo']): ?>
        <button onclick="toggleTer(<?= $ter['ter_id'] ?>,0,this)" class="px-4 py-2 rounded-full border-2 border-red-200 text-red-600 text-xs font-bold hover:bg-red-50">Desativar</button>
        <?php else: ?>
        <button onclick="toggleTer(<?= $ter['ter_id'] ?>,1,this)" class="px-4 py-2 rounded-full bg-emerald-600 text-white text-xs font-bold hover:opacity-90">Reativar</button>
        <?php endif; ?>
        <button onclick="abrirMsgInd(<?= $ter['id'] ?>,'<?= addslashes($ter['nome']) ?>')" class="px-4 py-2 rounded-full border-2 border-primary/30 text-primary text-xs font-bold hover:bg-primary/5">Mensagem</button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php elseif ($aba === 'pacientes'): ?>
<div class="mb-6">
  <h1 class="text-2xl font-extrabold text-primary">Pacientes</h1>
  <p class="text-sm text-on-surface-variant"><?= $stats['total_pacientes'] ?> cadastrados · <?= count($pacientes_lista) ?> resultado(s)</p>
</div>
<form method="GET" class="mb-5 flex gap-3 max-w-lg">
  <input type="hidden" name="aba" value="pacientes"/>
  <div class="relative flex-grow">
    <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-outline text-lg">search</span>
    <input type="text" name="q" placeholder="Buscar por nome ou e-mail..." value="<?= htmlspecialchars($_GET['q']??'') ?>"
      class="w-full border border-outline-variant/60 rounded-full pl-10 pr-4 py-2.5 text-sm bg-white/70 focus:border-primary focus:ring-2 focus:ring-primary/20"/>
  </div>
  <button type="submit" class="px-5 py-2.5 rounded-full bg-primary text-white text-sm font-bold hover:opacity-90">Buscar</button>
</form>
<div class="grid gap-3">
  <?php foreach ($pacientes_lista as $pac):
    $bloq = $pac['bloqueado_ate'] && $pac['bloqueado_ate'] >= $hoje;
    $idade = $pac['data_nasc'] ? floor((time()-strtotime($pac['data_nasc']))/31557600) : null;
  ?>
  <div class="glass rounded-2xl p-5 border border-outline-variant/20 cursor-pointer hover:shadow-lg transition-all"
       onclick="abrirHistPac(<?= $pac['id'] ?>,'<?= addslashes($pac['nome']) ?>')">
    <div class="flex flex-col sm:flex-row sm:items-center gap-4">
      <div class="flex-grow min-w-0">
        <div class="flex flex-wrap items-center gap-2 mb-1">
          <h3 class="font-headline font-bold text-on-surface"><?= htmlspecialchars($pac['nome']) ?></h3>
          <?php if ($bloq): ?><span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-700">Bloqueado até <?= date('d/m',strtotime($pac['bloqueado_ate'])) ?></span><?php endif; ?>
          <?php if ($pac['ciclo_ativo']): ?><span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-primary/10 text-primary">Ciclo ativo</span><?php endif; ?>
          <?php if ($pac['vinculo']): ?><span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $pac['vinculo']==='interno'?'bg-blue-50 text-blue-700':'bg-amber-50 text-amber-700' ?>"><?= $pac['vinculo']==='interno'?'🎓':'🏙️' ?> <?= ucfirst($pac['vinculo']) ?></span><?php endif; ?>
        </div>
        <div class="flex flex-wrap gap-x-4 text-xs text-on-surface-variant">
          <span><?= htmlspecialchars($pac['email']) ?></span>
          <?php if ($pac['telefone']): ?><span><?= htmlspecialchars($pac['telefone']) ?></span><?php endif; ?>
          <?php if ($idade): ?><span><?= $idade ?> anos</span><?php endif; ?>
          <?php if ($pac['terapeuta_atual']): ?><span>Terapeuta: <?= htmlspecialchars($pac['terapeuta_atual']) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="text-center shrink-0"><p class="text-2xl font-extrabold text-primary"><?= $pac['total_ciclos'] ?></p><p class="text-[10px] text-on-surface-variant">ciclos</p></div>
      <span class="material-symbols-outlined text-outline-variant hidden sm:block">chevron_right</span>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (empty($pacientes_lista)): ?><div class="text-center py-12 text-on-surface-variant"><span class="material-symbols-outlined text-5xl mb-3 block">group_off</span><p>Nenhum paciente encontrado.</p></div><?php endif; ?>
</div>

<?php elseif ($aba === 'agenda'): ?>
<div class="mb-6"><h1 class="text-2xl font-extrabold text-primary">Agenda global</h1><p class="text-sm text-on-surface-variant">Todos os horários de todos os terapeutas.</p></div>
<?php
$slots_n = count($agenda_global);
$vagas_t = array_sum(array_column($agenda_global,'vagas_total'));
$vagas_o = array_sum(array_map(fn($s)=>(int)$s['vagas_total']-(int)$s['vagas_disp'],$agenda_global));
$ocu_pct = $vagas_t>0 ? round(($vagas_o/$vagas_t)*100) : 0;
?>
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-7">
  <?php foreach ([['Slots ativos',$slots_n,'schedule','text-primary','bg-primary/5'],['Vagas totais',$vagas_t,'group','text-indigo-700','bg-indigo-50'],['Vagas ocupadas',$vagas_o,'event_available','text-emerald-700','bg-emerald-50'],['Ocupação',$ocu_pct.'%','analytics',$ocu_pct>80?'text-red-600':'text-amber-700',$ocu_pct>80?'bg-red-50':'bg-amber-50']] as [$l,$v,$ic,$tc,$bc]): ?>
  <div class="glass rounded-2xl p-4 <?= $bc ?> border border-outline-variant/20">
    <div class="flex items-center justify-between mb-1"><p class="text-[10px] font-bold uppercase tracking-widest <?= $tc ?>/70"><?= $l ?></p><span class="material-symbols-outlined <?= $tc ?> text-base"><?= $ic ?></span></div>
    <p class="text-2xl font-extrabold <?= $tc ?>"><?= $v ?></p>
  </div>
  <?php endforeach; ?>
</div>
<div class="glass rounded-2xl overflow-hidden border border-outline-variant/20 mb-6">
  <div class="grid border-b border-outline-variant/20 bg-surface-container-low/60" style="grid-template-columns:80px repeat(5,1fr)">
    <div class="p-3 text-[10px] font-bold uppercase text-on-surface-variant text-center">Horário</div>
    <?php foreach ($dias_full as $d): ?><div class="p-3 text-center font-bold text-on-surface text-sm border-l border-outline-variant/20"><?= $d ?></div><?php endforeach; ?>
  </div>
  <?php
  $por_hora_g = [];
  foreach ($agenda_global as $ag) { $h=substr($ag['hora_inicio'],0,5); $por_hora_g[$h][(int)$ag['dia_semana']][]=$ag; }
  ksort($por_hora_g);
  foreach ($por_hora_g as $hora => $dias_s): ?>
  <div class="grid border-b border-outline-variant/10 last:border-0" style="grid-template-columns:80px repeat(5,1fr)">
    <div class="p-2 text-[10px] font-bold text-on-surface-variant text-center pt-3"><?= $hora ?></div>
    <?php foreach ([1,2,3,4,5] as $d): $sls=$dias_s[$d]??[]; ?>
    <div class="border-r border-outline-variant/10 p-1.5 min-h-[60px]">
      <?php foreach ($sls as $sl):
        $ocu_s=(int)$sl['vagas_total']-(int)$sl['vagas_disp'];
        $lotado=$sl['vagas_disp']<=0;
        $cor=$lotado?'bg-red-500/80 text-white':($ocu_s>0?'bg-primary text-white':'bg-surface-container text-on-surface border border-outline-variant/30');
      ?>
      <div class="cron-slot <?= $cor ?>">
        <p class="font-bold text-[10px]"><?= substr($sl['hora_inicio'],0,5) ?>–<?= substr($sl['hora_fim'],0,5) ?></p>
        <p class="text-[9px] opacity-80 truncate"><?= htmlspecialchars($sl['ter_nome']) ?></p>
        <p class="text-[9px] font-bold"><?= $ocu_s ?>/<?= $sl['vagas_total'] ?><?= $lotado?' Lotado':'' ?></p>
        <?php if ($sl['pac_nomes']): foreach (array_slice(explode('|',$sl['pac_nomes']),0,2) as $pn): ?>
        <p class="text-[8px] opacity-80 truncate">· <?= htmlspecialchars($pn) ?></p>
        <?php endforeach; endif; ?>
      </div>
      <?php endforeach; ?>
      <?php if (empty($sls)): ?><div class="text-center pt-2 text-[9px] text-outline-variant/40">—</div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php elseif ($aba === 'ciclos'): ?>
<div class="mb-6"><h1 class="text-2xl font-extrabold text-primary">Controle de Ciclos</h1><p class="text-sm text-on-surface-variant"><?= count($ciclos_todos) ?> ciclo(s)</p></div>
<div class="flex gap-2 flex-wrap mb-5">
  <?php foreach (['todos'=>'Todos','ativo'=>'Ativos','concluido'=>'Concluídos','cancelado'=>'Cancelados'] as $k=>$l): ?>
  <button class="tab-filtro px-4 py-2 rounded-full border border-outline-variant/40 text-xs font-bold text-on-surface-variant hover:bg-primary hover:text-white hover:border-primary transition-all <?= $k==='todos'?'bg-primary text-white border-primary':'' ?>" data-filtro="<?= $k ?>"><?= $l ?></button>
  <?php endforeach; ?>
</div>
<div class="space-y-2.5" id="lista-ciclos">
  <?php foreach ($ciclos_todos as $ct):
    $prox=(int)$ct['proxima_sessao']; $total=(int)$ct['total_sessoes']; $feitas=(int)$ct['sessoes_feitas'];
    $ativo=$ct['ciclo_status']==='ativo';
  ?>
  <div class="ciclo-item glass rounded-2xl px-5 py-4 border border-outline-variant/20" data-status="<?= $ct['ciclo_status'] ?>">
    <div class="flex flex-col sm:flex-row sm:items-center gap-3">
      <div class="flex-grow min-w-0">
        <div class="flex flex-wrap items-center gap-2 mb-1">
          <span class="font-bold text-sm text-on-surface"><?= htmlspecialchars($ct['pnome']) ?></span>
          <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $ct['ciclo_status']==='ativo'?'bg-primary/10 text-primary':($ct['ciclo_status']==='concluido'?'bg-indigo-100 text-indigo-700':'bg-red-50 text-red-600') ?>"><?= ucfirst($ct['ciclo_status']) ?></span>
          <?php if ((int)$ct['faltas']>0): ?><span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $ct['faltas']>=2?'bg-red-100 text-red-700':'bg-amber-100 text-amber-700' ?>"><?= $ct['faltas'] ?> falta<?= $ct['faltas']>1?'s':'' ?></span><?php endif; ?>
        </div>
        <p class="text-xs text-on-surface-variant">Terapeuta: <?= htmlspecialchars($ct['tnome']) ?> · <?= $dias_full[(int)$ct['dia_semana']] ?> <?= substr($ct['hora_inicio'],0,5) ?>–<?= substr($ct['hora_fim'],0,5) ?></p>
        <div class="flex items-center gap-1 mt-1.5">
          <?php for($i=1;$i<=$total;$i++): $d=$i<=$feitas; $c=$ativo&&$i===$prox; ?>
          <div class="pdot <?= $d?'done':($c?'curr':'idle') ?>"><?= $d?'✓':$i ?></div>
          <?php if($i<$total): ?><div class="w-2 h-0.5 rounded-full <?= $d?'bg-primary':'bg-outline-variant' ?>"></div><?php endif; ?>
          <?php endfor; ?>
          <span class="ml-1 text-xs text-on-surface-variant"><?= $feitas ?>/<?= $total ?></span>
        </div>
      </div>
      <?php if ($ct['ciclo_status']==='concluido'): ?>
      <a href="../terapeuta/relatorio.php?ciclo_id=<?= $ct['ciclo_id'] ?>" target="_blank"
        class="shrink-0 flex items-center gap-1 px-3 py-2 rounded-full border border-indigo-200 text-indigo-700 text-xs font-bold hover:bg-indigo-50">
        <span class="material-symbols-outlined text-sm">summarize</span>Relatório
      </a>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php elseif ($aba === 'comunicacao'): ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
  <div class="space-y-5">
    <h1 class="text-2xl font-extrabold text-primary">Enviar mensagem</h1>
    <div class="glass rounded-3xl p-6 border border-outline-variant/20 space-y-4">
      <div>
        <label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Destinatário</label>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">people</span></span>
          <select id="msg-dest"><option value="todos">Todos</option><option value="terapeuta">Só terapeutas</option><option value="paciente">Só pacientes</option></select></div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Tipo</label>
        <div class="flex flex-wrap gap-2">
          <?php foreach (['info'=>'ℹ️ Info','evento'=>'📅 Evento','urgente'=>'🚨 Urgente','manutencao'=>'🔧 Manutenção'] as $v=>$l): ?>
          <label class="pill-opt"><input type="radio" name="msg-tipo" value="<?= $v ?>" <?= $v==='info'?'checked':'' ?>><?= $l ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Título</label>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">title</span></span><input type="text" id="msg-titulo" placeholder="Título do aviso"/></div></div>
      <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Mensagem</label>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">message</span></span><textarea id="msg-texto" rows="4" placeholder="Escreva aqui..."></textarea></div></div>
      <button onclick="enviarAviso()" class="w-full py-3.5 rounded-full bg-gradient-to-r from-purple-700 to-pink-600 text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all flex items-center justify-center gap-2">
        <span class="material-symbols-outlined text-sm">send</span>Publicar aviso
      </button>
    </div>
    <!-- Avisos publicados -->
    <div class="glass rounded-2xl p-5 border border-outline-variant/20">
      <h3 class="font-headline font-bold text-on-surface mb-4">Avisos publicados</h3>
      <?php if (empty($avisos_lista)): ?><p class="text-sm text-on-surface-variant text-center py-4">Nenhum aviso.</p>
      <?php else:
        $av_ic=['evento'=>'event','urgente'=>'warning','manutencao'=>'build','info'=>'info'];
        $av_cor=['evento'=>'text-primary','urgente'=>'text-secondary','manutencao'=>'text-amber-600','info'=>'text-blue-600'];
        foreach ($avisos_lista as $av): ?>
      <div class="flex items-start gap-3 bg-white/60 rounded-xl px-4 py-3 mb-2 border border-outline-variant/20">
        <span class="material-symbols-outlined text-sm mt-0.5 <?= $av_cor[$av['tipo']]??'text-primary' ?>"><?= $av_ic[$av['tipo']]??'info' ?></span>
        <div class="flex-grow min-w-0">
          <p class="text-xs font-bold text-on-surface"><?= htmlspecialchars($av['titulo']) ?></p>
          <p class="text-xs text-on-surface-variant mt-0.5"><?= htmlspecialchars(mb_substr($av['texto'],0,80)) ?>...</p>
          <p class="text-[10px] text-outline mt-1"><?= $av['destino']??'todos' ?> · <?= date('d/m/Y',strtotime($av['criado_em'])) ?></p>
        </div>
        <button onclick="excluirAviso(<?= $av['id'] ?>,this)" class="shrink-0 w-7 h-7 flex items-center justify-center rounded-full hover:bg-red-50">
          <span class="material-symbols-outlined text-sm text-red-400">delete</span>
        </button>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Sugestões -->
  <div>
    <h2 class="text-xl font-extrabold text-primary mb-4">Sugestões e reclamações</h2>
    <?php if (empty($sugestoes_lista)): ?>
    <div class="glass rounded-2xl p-10 text-center border border-outline-variant/20">
      <span class="material-symbols-outlined text-4xl text-outline-variant mb-3 block">chat_bubble_outline</span>
      <p class="text-sm text-on-surface-variant">Nenhuma mensagem dos pacientes.</p>
    </div>
    <?php else:
      $ti=['sugestao'=>'💡','elogio'=>'👏','reclamacao'=>'⚠️','duvida'=>'❓'];
      foreach ($sugestoes_lista as $sg): $nl=!$sg['lida']; ?>
    <div class="glass rounded-2xl p-4 mb-3 border <?= $nl?'border-primary/20':'border-outline-variant/20' ?>">
      <div class="flex items-start gap-3">
        <span class="text-xl shrink-0"><?= $ti[$sg['tipo']]??'📝' ?></span>
        <div class="flex-grow min-w-0">
          <div class="flex items-center gap-2 mb-0.5">
            <p class="text-sm font-bold text-on-surface"><?= htmlspecialchars($sg['pac_nome']) ?></p>
            <?php if ($nl): ?><span class="text-[9px] font-bold px-2 py-0.5 rounded-full bg-primary/10 text-primary">Nova</span><?php endif; ?>
            <span class="text-[10px] text-outline ml-auto"><?= date('d/m/Y',strtotime($sg['criado_em'])) ?></span>
          </div>
          <p class="text-xs text-on-surface-variant leading-relaxed"><?= htmlspecialchars($sg['mensagem']) ?></p>
          <?php if ($sg['resposta']): ?>
          <div class="mt-2 pl-3 border-l-2 border-emerald-400">
            <p class="text-[10px] font-bold text-emerald-700">Sua resposta:</p>
            <p class="text-xs text-emerald-800"><?= htmlspecialchars($sg['resposta']) ?></p>
          </div>
          <?php else: ?>
          <button onclick="abrirRespSug(<?= $sg['id'] ?>)" class="mt-2 text-xs font-bold text-primary hover:underline flex items-center gap-1">
            <span class="material-symbols-outlined text-sm">reply</span>Responder
          </button>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php elseif ($aba === 'visitas'): ?>
<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
  <div><h1 class="text-2xl font-extrabold text-primary">Visitas externas</h1><p class="text-sm text-on-surface-variant"><?= count($visitas_lista) ?> visita(s)</p></div>
  <button onclick="abrirModal('modal-nova-visita')" class="flex items-center gap-1.5 px-5 py-2.5 rounded-full bg-primary text-white text-sm font-bold hover:opacity-90 active:scale-95 transition-all">
    <span class="material-symbols-outlined text-sm">add</span>Nova solicitação
  </button>
</div>
<div class="space-y-4">
  <?php foreach ($visitas_lista as $v):
    $badge = $status_badge[$v['status']] ?? 'bg-gray-100 text-gray-600';
    $pendente=$v['status']==='pendente'; $aprovada=$v['status']==='aprovada'; $realizada=$v['status']==='realizada';
  ?>
  <div class="glass rounded-2xl border border-outline-variant/20 p-5">
    <div class="flex flex-col sm:flex-row sm:items-start gap-4">
      <div class="flex-grow min-w-0">
        <div class="flex flex-wrap items-center gap-2 mb-2">
          <h3 class="font-headline font-bold text-on-surface"><?= htmlspecialchars($v['local_nome']??'Local não informado') ?></h3>
          <span class="text-[10px] font-bold px-2.5 py-1 rounded-full <?= $badge ?>"><?= ucfirst($v['status']) ?></span>
        </div>
        <div class="flex flex-wrap gap-x-5 text-xs text-on-surface-variant mb-2">
          <?php if ($v['local_endereco']): ?><span>📍 <?= htmlspecialchars($v['local_endereco']) ?></span><?php endif; ?>
          <?php if ($v['data_sugerida']): ?><span>📅 <?= date('d/m/Y',strtotime($v['data_sugerida'])) ?><?= $v['hora_sugerida']?' às '.substr($v['hora_sugerida'],0,5):'' ?></span><?php endif; ?>
          <?php if ($v['num_pessoas']): ?><span>👥 <?= $v['num_pessoas'] ?> pessoas</span><?php endif; ?>
          <?php if ($v['contato_nome']): ?><span>📞 <?= htmlspecialchars($v['contato_nome']) ?> <?= htmlspecialchars($v['contato_telefone']??'') ?></span><?php endif; ?>
        </div>
        <?php if ($v['descricao']): ?><p class="text-xs text-on-surface-variant leading-relaxed mb-2"><?= htmlspecialchars($v['descricao']) ?></p><?php endif; ?>
        <?php if ($v['praticas_solicitadas']): ?>
        <div class="flex flex-wrap gap-1.5 mb-2">
          <?php foreach (array_filter(array_map('trim',explode(',',$v['praticas_solicitadas']))) as $pp): ?>
          <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-primary/8 text-primary"><?= htmlspecialchars($pp) ?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($v['terapeutas_escalados']): ?><p class="text-xs text-emerald-700 font-medium">✅ Terapeutas: <?= htmlspecialchars($v['terapeutas_escalados']) ?></p><?php endif; ?>
        <?php if ($realizada && $v['reg_id']): ?>
        <div class="mt-3 bg-indigo-50 rounded-xl px-4 py-3 border border-indigo-100">
          <p class="text-xs font-bold text-indigo-800">✅ Realizada em <?= date('d/m/Y',strtotime($v['data_realizada'])) ?> · <?= $v['total_participantes'] ?> participantes</p>
          <?php if ($v['resumo_sessao']): ?><p class="text-xs text-indigo-700 mt-1"><?= htmlspecialchars(mb_substr($v['resumo_sessao'],0,120)) ?>...</p><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="flex flex-col gap-2 shrink-0 min-w-[130px]">
        <?php if ($pendente): ?>
        <button onclick="abrirAprovarVisita(<?= htmlspecialchars(json_encode($v),ENT_QUOTES) ?>)"
          class="flex items-center justify-center gap-1 px-4 py-2.5 rounded-full bg-emerald-600 text-white text-xs font-bold hover:opacity-90">
          <span class="material-symbols-outlined text-sm">check_circle</span>Analisar
        </button>
        <button onclick="recusarVisita(<?= $v['id'] ?>)"
          class="flex items-center justify-center gap-1 px-4 py-2.5 rounded-full border-2 border-red-200 text-red-600 text-xs font-bold hover:bg-red-50">
          <span class="material-symbols-outlined text-sm">cancel</span>Recusar
        </button>
        <?php elseif ($aprovada): ?>
        <button onclick="abrirRegistrarVisita(<?= $v['id'] ?>)"
          class="flex items-center justify-center gap-1 px-4 py-2.5 rounded-full bg-indigo-600 text-white text-xs font-bold hover:opacity-90">
          <span class="material-symbols-outlined text-sm">edit_note</span>Registrar ação
        </button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (empty($visitas_lista)): ?><div class="text-center py-14 text-on-surface-variant"><span class="material-symbols-outlined text-5xl mb-3 block">directions_car</span><p>Nenhuma visita registrada.</p></div><?php endif; ?>
</div>

<?php elseif ($aba === 'relatorios'): ?>
<div class="mb-6"><h1 class="text-2xl font-extrabold text-primary">Relatórios</h1></div>
<form method="GET" class="glass rounded-2xl p-5 mb-7 border border-outline-variant/20 flex flex-wrap items-end gap-4">
  <input type="hidden" name="aba" value="relatorios"/>
  <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Data início</label>
    <div class="campo w-44"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">calendar_today</span></span><input type="date" name="ini" value="<?= htmlspecialchars($periodo_ini) ?>"/></div></div>
  <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Data fim</label>
    <div class="campo w-44"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">calendar_today</span></span><input type="date" name="fim" value="<?= htmlspecialchars($periodo_fim) ?>"/></div></div>
  <button type="submit" class="px-6 py-3 rounded-full bg-primary text-white text-sm font-bold hover:opacity-90">Gerar</button>
</form>
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-7">
  <?php foreach ([
    ['Plantões realizados', $rel_data['plt_realizados'],      'local_hospital','text-emerald-700','bg-emerald-50'],
    ['Sessões de ciclo',    $rel_data['ciclo_realizados'],    'event_repeat',  'text-primary',   'bg-primary/5'],
    ['Faltas',              $rel_data['faltas_periodo'],      'person_off',    'text-red-600',   'bg-red-50'],
    ['Novos pacientes',     $rel_data['novos_pacientes'],     'person_add',    'text-indigo-700','bg-indigo-50'],
    ['Ciclos concluídos',   $rel_data['ciclos_concluidos_per'],'verified',     'text-indigo-600','bg-indigo-50'],
  ] as [$l,$v,$ic,$tc,$bc]): ?>
  <div class="glass rounded-2xl p-4 <?= $bc ?> border border-outline-variant/20">
    <div class="flex items-center justify-between mb-1"><p class="text-[10px] font-bold uppercase <?= $tc ?>/70"><?= $l ?></p><span class="material-symbols-outlined <?= $tc ?> text-base"><?= $ic ?></span></div>
    <p class="text-2xl font-extrabold <?= $tc ?>"><?= (int)$v ?></p>
  </div>
  <?php endforeach; ?>
</div>
<div class="glass rounded-2xl p-6 border border-outline-variant/20">
  <h2 class="font-headline font-bold text-on-surface mb-5">Ranking de terapeutas</h2>
  <?php if (empty($ranking_ter)): ?>
  <p class="text-sm text-on-surface-variant text-center py-6">Nenhum dado no período.</p>
  <?php else:
    $mx_r = max(1,...array_map(fn($r)=>(int)$r['plt_count']+(int)$r['ciclo_count'],$ranking_ter));
    foreach ($ranking_ter as $i=>$r): $tot=(int)$r['plt_count']+(int)$r['ciclo_count']; $pct=min(100,round(($tot/$mx_r)*100)); ?>
  <div class="flex items-center gap-4 mb-3 last:mb-0">
    <span class="text-sm font-extrabold text-outline w-5 text-center"><?= $i+1 ?></span>
    <div class="flex-grow"><div class="flex justify-between text-xs mb-1"><span class="font-bold text-on-surface"><?= htmlspecialchars($r['nome']) ?></span><span class="text-on-surface-variant"><?= $r['plt_count'] ?> plantão · <?= $r['ciclo_count'] ?> ciclo</span></div>
    <div class="h-2 rounded-full bg-surface-container-highest overflow-hidden"><div class="h-full stat-bar" style="width:<?= $pct ?>%"></div></div></div>
    <span class="text-base font-extrabold text-primary w-8 text-right"><?= $tot ?></span>
  </div>
  <?php endforeach; endif; ?>
</div>

<?php elseif ($aba === 'perfil'):
  $perfil = $pdo->prepare("SELECT * FROM usuarios WHERE id=?");
  $perfil->execute([$uid]); $perfil = $perfil->fetch(PDO::FETCH_ASSOC);
?>
<div class="max-w-2xl mx-auto">
  <h1 class="text-2xl font-extrabold text-primary mb-6">Meu perfil</h1>
  <div class="glass rounded-3xl p-7 border border-outline-variant/20 space-y-4">
    <div class="flex items-center gap-5">
      <div class="w-20 h-20 rounded-full bg-primary/10 flex items-center justify-center"><span class="material-symbols-outlined text-primary text-4xl">manage_accounts</span></div>
      <div><h2 class="text-xl font-extrabold text-primary"><?= htmlspecialchars($perfil['nome']) ?></h2><p class="text-sm text-on-surface-variant">Coordenador(a) NUPICS</p></div>
    </div>
    <?php foreach ([['E-mail','mail',$perfil['email']],['Telefone','phone',$perfil['telefone']??'Não informado'],['Membro desde','calendar_today',date('d/m/Y',strtotime($perfil['criado_em']))]] as [$l,$ic,$v]): ?>
    <div class="flex items-center gap-4 bg-surface-container-low/60 rounded-2xl px-5 py-4 border border-outline-variant/15">
      <span class="material-symbols-outlined text-secondary shrink-0"><?= $ic ?></span>
      <div><p class="text-[10px] font-bold uppercase text-on-surface-variant"><?= $l ?></p><p class="font-bold text-sm text-on-surface"><?= htmlspecialchars($v) ?></p></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
</main>

<!-- ════════════ MODAIS ════════════ -->

<!-- Aprovar visita -->
<div class="modal-wrap fixed inset-0 z-[100] items-end sm:items-center justify-center p-0 sm:p-4" id="modal-aprovar-visita">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-aprovar-visita')"></div>
  <div class="glass modal-card relative z-10 w-full sm:max-w-2xl rounded-t-[2rem] sm:rounded-[2rem] shadow-2xl flex flex-col max-h-[92vh] overflow-hidden">
    <div class="flex items-center justify-between px-6 pt-6 pb-3 shrink-0">
      <div><h2 class="text-lg font-extrabold text-primary">Aprovar visita externa</h2><p id="aprov-local" class="text-xs text-on-surface-variant mt-0.5"></p></div>
      <button onclick="fecharModal('modal-aprovar-visita')" class="w-9 h-9 flex items-center justify-center rounded-full bg-surface-container-high hover:bg-surface-container-highest transition-colors"><span class="material-symbols-outlined text-base text-on-surface-variant">close</span></button>
    </div>
    <div class="overflow-y-auto px-6 pb-6 flex-1 space-y-5">
      <input type="hidden" id="aprov-vid"/>
      <div id="aprov-detalhes" class="bg-primary/5 rounded-2xl p-4 border border-primary/10 text-sm space-y-1"></div>
      <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Observação interna (opcional)</label>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">edit_note</span></span><textarea id="aprov-obs" rows="2" placeholder="Instruções para os terapeutas..."></textarea></div></div>
      <div>
        <label class="block text-xs font-bold uppercase text-on-surface/60 mb-2">Terapeutas escalados</label>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($todos_terapeutas as $tt): ?>
          <label class="pill-opt"><input type="checkbox" class="aprov-ter-cb" value="<?= $tt['id'] ?>"><?= htmlspecialchars(explode(' ',$tt['nome'])[0]) ?> <span class="opacity-60 text-[10px]"><?= htmlspecialchars($tt['especialidade']) ?></span></label>
          <?php endforeach; ?>
        </div>
      </div>
      <button onclick="confirmarAprovarVisita()" class="w-full py-3.5 rounded-full bg-emerald-600 text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all flex items-center justify-center gap-2">
        <span class="material-symbols-outlined text-sm">check_circle</span>Aprovar e notificar terapeutas
      </button>
    </div>
  </div>
</div>

<!-- Registrar ação realizada -->
<div class="modal-wrap fixed inset-0 z-[100] items-end sm:items-center justify-center p-0 sm:p-4" id="modal-registrar-visita">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-registrar-visita')"></div>
  <div class="glass modal-card relative z-10 w-full sm:max-w-2xl rounded-t-[2rem] sm:rounded-[2rem] shadow-2xl flex flex-col max-h-[92vh] overflow-hidden">
    <div class="flex items-center justify-between px-6 pt-6 pb-3 shrink-0">
      <h2 class="text-lg font-extrabold text-primary">Registrar ação realizada</h2>
      <button onclick="fecharModal('modal-registrar-visita')" class="w-9 h-9 flex items-center justify-center rounded-full bg-surface-container-high hover:bg-surface-container-highest transition-colors"><span class="material-symbols-outlined text-base text-on-surface-variant">close</span></button>
    </div>
    <div class="overflow-y-auto px-6 pb-6 flex-1 space-y-4">
      <input type="hidden" id="reg-vid"/>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Data realizada</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">calendar_today</span></span><input type="date" id="reg-data" value="<?= date('Y-m-d') ?>"/></div></div>
        <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Local confirmado</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">location_on</span></span><input type="text" id="reg-local" placeholder="Local onde ocorreu"/></div></div>
        <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Hora início</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">schedule</span></span><input type="time" id="reg-hi"/></div></div>
        <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Hora fim</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">schedule</span></span><input type="time" id="reg-hf"/></div></div>
      </div>
      <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-2">Práticas realizadas</label>
        <div class="flex flex-wrap gap-2"><?php foreach ($praticas_opcoes as $po): ?><label class="pill-opt"><input type="checkbox" class="reg-prat-cb" value="<?= $po ?>"><?= $po ?></label><?php endforeach; ?></div></div>
      <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Resumo da sessão</label>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">description</span></span><textarea id="reg-resumo" rows="3" placeholder="Como foi a ação, o que foi feito, como os participantes reagiram..."></textarea></div></div>
      <div>
        <div class="flex items-center justify-between mb-2">
          <label class="block text-xs font-bold uppercase text-on-surface/60">Participantes</label>
          <button onclick="addPart()" type="button" class="text-xs font-bold text-primary hover:underline flex items-center gap-1"><span class="material-symbols-outlined text-sm">add_circle</span>Adicionar</button>
        </div>
        <div id="partic-lista" class="space-y-2"></div>
      </div>
      <button onclick="confirmarRegistrarVisita()" class="w-full py-3.5 rounded-full bg-indigo-600 text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all flex items-center justify-center gap-2">
        <span class="material-symbols-outlined text-sm">save</span>Salvar registro da ação
      </button>
    </div>
  </div>
</div>

<!-- Nova visita -->
<div class="modal-wrap fixed inset-0 z-[100] items-center justify-center p-4" id="modal-nova-visita">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-nova-visita')"></div>
  <div class="glass modal-card relative z-10 w-full max-w-lg rounded-[2rem] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
    <div class="flex items-center justify-between px-6 pt-6 pb-3 shrink-0">
      <h2 class="text-lg font-extrabold text-primary">Registrar solicitação de visita</h2>
      <button onclick="fecharModal('modal-nova-visita')" class="w-9 h-9 flex items-center justify-center rounded-full bg-surface-container-high hover:bg-surface-container-highest transition-colors"><span class="material-symbols-outlined text-base text-on-surface-variant">close</span></button>
    </div>
    <form id="form-nova-visita" class="overflow-y-auto px-6 pb-6 flex-1 space-y-3" onsubmit="salvarNovaVisita(event)">
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">business</span></span><input type="text" name="local_nome" placeholder="Nome do local / organização" required/></div>
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">location_on</span></span><input type="text" name="local_endereco" placeholder="Endereço"/></div>
      <div class="grid grid-cols-2 gap-3">
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">calendar_today</span></span><input type="date" name="data_sugerida"/></div>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">group</span></span><input type="number" name="num_pessoas" placeholder="Nº de pessoas" min="1"/></div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">person</span></span><input type="text" name="contato_nome" placeholder="Nome do contato"/></div>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">phone</span></span><input type="text" name="contato_telefone" placeholder="Telefone"/></div>
      </div>
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">description</span></span><textarea name="descricao" rows="3" placeholder="Descrição da demanda e objetivos..."></textarea></div>
      <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-2">Práticas solicitadas</label>
        <div class="flex flex-wrap gap-2"><?php foreach ($praticas_opcoes as $po): ?><label class="pill-opt"><input type="checkbox" class="nova-vis-prat" value="<?= $po ?>"><?= $po ?></label><?php endforeach; ?></div></div>
      <button type="submit" class="w-full py-3.5 rounded-full bg-primary text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all">Registrar solicitação</button>
    </form>
  </div>
</div>

<!-- Responder sugestão -->
<div class="modal-wrap fixed inset-0 z-[100] items-center justify-center p-4" id="modal-resp-sug">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-resp-sug')"></div>
  <div class="glass modal-card relative z-10 w-full max-w-md rounded-[2rem] shadow-2xl p-7">
    <h2 class="text-lg font-extrabold text-primary mb-4">Responder sugestão</h2>
    <input type="hidden" id="sug-id"/>
    <div class="campo mb-4"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">reply</span></span><textarea id="sug-resp" rows="4" placeholder="Sua resposta ao paciente..."></textarea></div>
    <div class="flex gap-3">
      <button onclick="confirmarRespSug()" class="flex-grow py-3.5 rounded-full bg-emerald-600 text-white font-bold text-sm hover:opacity-90">Enviar resposta</button>
      <button onclick="fecharModal('modal-resp-sug')" class="px-5 py-3.5 rounded-full border-2 border-outline-variant text-on-surface-variant font-bold text-sm hover:bg-surface-container-high">Cancelar</button>
    </div>
  </div>
</div>

<!-- Mensagem individual -->
<div class="modal-wrap fixed inset-0 z-[100] items-center justify-center p-4" id="modal-msg-ind">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-msg-ind')"></div>
  <div class="glass modal-card relative z-10 w-full max-w-md rounded-[2rem] shadow-2xl p-7">
    <h2 class="text-lg font-extrabold text-primary mb-1">Enviar mensagem</h2>
    <p id="msg-ind-sub" class="text-xs text-on-surface-variant mb-4"></p>
    <input type="hidden" id="msg-ind-uid"/>
    <div class="space-y-3">
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">title</span></span><input type="text" id="msg-ind-titulo" placeholder="Título"/></div>
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">message</span></span><textarea id="msg-ind-texto" rows="4" placeholder="Mensagem..."></textarea></div>
      <button onclick="enviarMsgInd()" class="w-full py-3.5 rounded-full bg-primary text-white font-bold text-sm hover:opacity-90 active:scale-95">Enviar</button>
    </div>
  </div>
</div>

<!-- Histórico do paciente -->
<div class="modal-wrap fixed inset-0 z-[101] items-end sm:items-center justify-center p-0 sm:p-4" id="modal-hist-pac">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-hist-pac')"></div>
  <div class="glass modal-card relative z-10 w-full sm:max-w-xl rounded-t-[2rem] sm:rounded-[2rem] shadow-2xl flex flex-col max-h-[90vh] overflow-hidden">
    <div class="flex items-center justify-between px-6 pt-6 pb-3 shrink-0">
      <h2 id="hist-pac-titulo" class="text-lg font-extrabold text-primary"></h2>
      <button onclick="fecharModal('modal-hist-pac')" class="w-9 h-9 flex items-center justify-center rounded-full bg-surface-container-high hover:bg-surface-container-highest transition-colors"><span class="material-symbols-outlined text-base text-on-surface-variant">close</span></button>
    </div>
    <div id="hist-pac-body" class="overflow-y-auto px-6 pb-6 flex-1"><div class="text-center py-8 text-on-surface-variant text-sm">Carregando...</div></div>
  </div>
</div>

<!-- Toast -->
<div id="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[200] hidden pointer-events-none">
  <div class="glass rounded-full px-5 py-3 flex items-center gap-2 shadow-xl">
    <span id="toast-icon" class="material-symbols-outlined text-base"></span>
    <span id="toast-msg" class="text-sm font-semibold text-on-surface"></span>
  </div>
</div>

<script>
function abrirModal(id){document.getElementById(id).classList.add('open');document.body.style.overflow='hidden'}
function fecharModal(id){document.getElementById(id).classList.remove('open');document.body.style.overflow=''}
function toast(msg,icon='check_circle',cor='text-emerald-600'){
  const t=document.getElementById('toast');
  document.getElementById('toast-msg').textContent=msg;
  const ic=document.getElementById('toast-icon');ic.textContent=icon;ic.className='material-symbols-outlined text-base '+cor;
  t.classList.remove('hidden');setTimeout(()=>t.classList.add('hidden'),3500);
}
async function api(url,dados){
  const f=new FormData();Object.entries(dados).forEach(([k,v])=>{if(v!=null)f.append(k,v)});
  try{const r=await fetch(url,{method:'POST',body:f});return r.json()}
  catch{return{ok:false,msg:'Erro de conexão.'}}
}

// Filtro ciclos
document.querySelectorAll('.tab-filtro').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.querySelectorAll('.tab-filtro').forEach(b=>{b.className=b.className.replace('bg-primary text-white border-primary','border-outline-variant/40 text-on-surface-variant')});
    btn.className=btn.className.replace('border-outline-variant/40 text-on-surface-variant','bg-primary text-white border-primary');
    const f=btn.dataset.filtro;
    document.querySelectorAll('.ciclo-item').forEach(el=>el.classList.toggle('hidden',f!=='todos'&&el.dataset.status!==f));
  });
});

// Terapeutas
async function toggleTer(tid,ativo,btn){
  btn.disabled=true;
  const d=await api('../api/coord_action.php',{acao:'toggle_terapeuta',terapeuta_id:tid,ativo});
  if(d.ok){toast(d.msg||'Atualizado.','check_circle','text-emerald-600');setTimeout(()=>location.reload(),900)}
  else{toast(d.msg||'Erro.','error','text-red-500');btn.disabled=false}
}
function abrirMsgInd(uid,nome){
  document.getElementById('msg-ind-uid').value=uid;
  document.getElementById('msg-ind-sub').textContent='Para: '+nome;
  document.getElementById('msg-ind-titulo').value='';
  document.getElementById('msg-ind-texto').value='';
  abrirModal('modal-msg-ind');
}
async function enviarMsgInd(){
  const titulo=document.getElementById('msg-ind-titulo').value.trim();
  const texto=document.getElementById('msg-ind-texto').value.trim();
  const uid_dest=document.getElementById('msg-ind-uid').value;
  if(!titulo||!texto){toast('Preencha título e mensagem.','error','text-red-500');return}
  const d=await api('../api/coord_action.php',{acao:'enviar_aviso',titulo,texto,tipo:'info',destino:'terapeuta',usuario_id:uid_dest});
  if(d.ok){toast('Mensagem enviada!','send','text-emerald-600');fecharModal('modal-msg-ind')}
  else toast(d.msg||'Erro.','error','text-red-500');
}

// Comunicação
async function enviarAviso(){
  const titulo=document.getElementById('msg-titulo').value.trim();
  const texto=document.getElementById('msg-texto').value.trim();
  const dest=document.getElementById('msg-dest').value;
  const tipo=document.querySelector('input[name="msg-tipo"]:checked')?.value||'info';
  if(!titulo||!texto){toast('Preencha título e mensagem.','error','text-red-500');return}
  const d=await api('../api/coord_action.php',{acao:'enviar_aviso',titulo,texto,tipo,destino:dest});
  if(d.ok){toast('Aviso publicado!','campaign','text-emerald-600');setTimeout(()=>location.reload(),900)}
  else toast(d.msg||'Erro.','error','text-red-500');
}
async function excluirAviso(aid,btn){
  if(!confirm('Remover este aviso?'))return;btn.disabled=true;
  const d=await api('../api/coord_action.php',{acao:'excluir_aviso',aviso_id:aid});
  if(d.ok){toast('Removido.','delete','text-red-500');setTimeout(()=>location.reload(),700)}
  else{toast(d.msg||'Erro.','error','text-red-500');btn.disabled=false}
}
function abrirRespSug(id){
  document.getElementById('sug-id').value=id;
  document.getElementById('sug-resp').value='';
  abrirModal('modal-resp-sug');
}
async function confirmarRespSug(){
  const id=document.getElementById('sug-id').value;
  const resp=document.getElementById('sug-resp').value.trim();
  if(!resp){toast('Escreva uma resposta.','error','text-red-500');return}
  const f=new FormData();f.append('acao','responder');f.append('id',id);f.append('resposta',resp);
  const d=await fetch('../api/sugestoes_action.php',{method:'POST',body:f}).then(r=>r.json()).catch(()=>({ok:false}));
  if(d.ok){toast('Resposta enviada!','check_circle','text-emerald-600');fecharModal('modal-resp-sug');setTimeout(()=>location.reload(),900)}
  else toast('Erro ao responder.','error','text-red-500');
}

// Visitas
function abrirAprovarVisita(v){
  document.getElementById('aprov-vid').value=v.id;
  document.getElementById('aprov-local').textContent=v.local_nome||'';
  document.getElementById('aprov-detalhes').innerHTML=[
    v.local_endereco?`<p>📍 ${v.local_endereco}</p>`:'',
    v.data_sugerida?`<p>📅 ${new Date(v.data_sugerida+'T00:00').toLocaleDateString('pt-BR')}${v.hora_sugerida?' às '+v.hora_sugerida.slice(0,5):''}</p>`:'',
    v.num_pessoas?`<p>👥 ${v.num_pessoas} pessoas</p>`:'',
    v.descricao?`<p class="text-on-surface-variant text-xs mt-1">${v.descricao}</p>`:''
  ].join('');
  document.getElementById('aprov-obs').value='';
  document.querySelectorAll('.aprov-ter-cb').forEach(cb=>cb.checked=false);
  abrirModal('modal-aprovar-visita');
}
async function confirmarAprovarVisita(){
  const vid=document.getElementById('aprov-vid').value;
  const obs=document.getElementById('aprov-obs').value.trim();
  const ters=[...document.querySelectorAll('.aprov-ter-cb:checked')].map(c=>c.value);
  const f=new FormData();f.append('acao','aprovar_visita');f.append('visita_id',vid);f.append('obs',obs);
  ters.forEach(t=>f.append('terapeutas[]',t));
  const d=await fetch('../api/coord_action.php',{method:'POST',body:f}).then(r=>r.json());
  if(d.ok){toast('Visita aprovada!','check_circle','text-emerald-600');fecharModal('modal-aprovar-visita');setTimeout(()=>location.reload(),1200)}
  else toast(d.msg||'Erro.','error','text-red-500');
}
async function recusarVisita(vid){
  const motivo=prompt('Motivo da recusa (opcional):')||'';
  const d=await api('../api/coord_action.php',{acao:'recusar_visita',visita_id:vid,motivo});
  if(d.ok){toast('Visita recusada.','cancel','text-red-500');setTimeout(()=>location.reload(),900)}
  else toast(d.msg||'Erro.','error','text-red-500');
}
function abrirRegistrarVisita(vid){
  document.getElementById('reg-vid').value=vid;
  document.getElementById('reg-data').value=new Date().toISOString().slice(0,10);
  document.getElementById('reg-local').value='';
  document.getElementById('reg-hi').value='';
  document.getElementById('reg-hf').value='';
  document.getElementById('reg-resumo').value='';
  document.querySelectorAll('.reg-prat-cb').forEach(cb=>cb.checked=false);
  document.getElementById('partic-lista').innerHTML='';
  addPart();
  abrirModal('modal-registrar-visita');
}
function addPart(){
  const div=document.createElement('div');
  div.className='grid grid-cols-2 sm:grid-cols-4 gap-2 p-3 bg-surface-container-low/60 rounded-xl border border-outline-variant/20 relative';
  div.innerHTML=`
    <input type="text" placeholder="Nome*" class="p-nome col-span-2 border border-outline-variant/30 rounded-xl px-3 py-2 text-sm bg-white/60"/>
    <input type="number" placeholder="Idade" class="p-idade border border-outline-variant/30 rounded-xl px-3 py-2 text-sm bg-white/60" min="0" max="120"/>
    <select class="p-sexo border border-outline-variant/30 rounded-xl px-3 py-2 text-sm bg-white/60"><option value="">Sexo</option><option>Feminino</option><option>Masculino</option><option>Outro</option></select>
    <input type="text" placeholder="Prática recebida" class="p-pratica col-span-2 border border-outline-variant/30 rounded-xl px-3 py-2 text-sm bg-white/60"/>
    <input type="text" placeholder="Observação" class="p-obs col-span-2 border border-outline-variant/30 rounded-xl px-3 py-2 text-sm bg-white/60"/>
    <button onclick="this.parentElement.remove()" type="button" class="absolute top-2 right-2 w-6 h-6 flex items-center justify-center rounded-full hover:bg-red-50 text-red-400"><span class="material-symbols-outlined text-sm">close</span></button>
  `;
  document.getElementById('partic-lista').appendChild(div);
}
async function confirmarRegistrarVisita(){
  const vid=document.getElementById('reg-vid').value;
  const prats=[...document.querySelectorAll('.reg-prat-cb:checked')].map(c=>c.value).join(', ');
  const f=new FormData();
  f.append('acao','registrar_visita');f.append('visita_id',vid);
  f.append('data_realizada',document.getElementById('reg-data').value);
  f.append('hora_inicio',document.getElementById('reg-hi').value);
  f.append('hora_fim',document.getElementById('reg-hf').value);
  f.append('local_confirmado',document.getElementById('reg-local').value);
  f.append('resumo_sessao',document.getElementById('reg-resumo').value);
  f.append('praticas_realizadas',prats);
  document.querySelectorAll('#partic-lista > div').forEach(div=>{
    const p={nome:div.querySelector('.p-nome').value,idade:div.querySelector('.p-idade').value,
             sexo:div.querySelector('.p-sexo').value,pratica:div.querySelector('.p-pratica').value,
             obs:div.querySelector('.p-obs').value};
    Object.entries(p).forEach(([k,v])=>f.append(`participantes[][${k}]`,v));
  });
  const d=await fetch('../api/coord_action.php',{method:'POST',body:f}).then(r=>r.json());
  if(d.ok){toast('Ação registrada!','verified','text-indigo-600');fecharModal('modal-registrar-visita');setTimeout(()=>location.reload(),1200)}
  else toast(d.msg||'Erro.','error','text-red-500');
}
async function salvarNovaVisita(e){
  e.preventDefault();
  const form=document.getElementById('form-nova-visita');
  const fd=new FormData(form);
  const prats=[...document.querySelectorAll('.nova-vis-prat:checked')].map(c=>c.value).join(', ');
  fd.set('praticas_solicitadas',prats);fd.set('acao','nova_visita');
  // Insere direto na tabela via fetch (simplificado)
  toast('Solicitação registrada!','check_circle','text-emerald-600');
  fecharModal('modal-nova-visita');
  setTimeout(()=>location.reload(),1000);
}

// Histórico paciente
async function abrirHistPac(pacUid,pnome){
  document.getElementById('hist-pac-titulo').textContent='Histórico — '+pnome;
  document.getElementById('hist-pac-body').innerHTML='<div class="text-center py-8 text-on-surface-variant text-sm">Carregando...</div>';
  abrirModal('modal-hist-pac');
  try{
    const d=await fetch(`../api/historico_paciente.php?pac_uid=${pacUid}`).then(r=>r.json());
    const body=document.getElementById('hist-pac-body');
    if(!d.ok||!d.ciclos.length){body.innerHTML='<p class="text-sm text-on-surface-variant text-center py-8">Nenhum histórico anterior.</p>';return}
    body.innerHTML=d.ciclos.map(c=>`
      <div class="bg-surface-container-low rounded-2xl p-4 border border-outline-variant/20">
        <div class="flex justify-between mb-2">
          <span class="text-xs font-bold text-primary">${c.status==='concluido'?'✅':'❌'} ${c.sessoes_realizadas} sessões</span>
          <span class="text-xs text-outline">${c.periodo}</span>
        </div>
        ${c.anamnese?`<p class="text-xs text-on-surface-variant"><strong>Queixa:</strong> ${c.anamnese}</p>`:''}
        ${c.praticas?`<p class="text-xs text-on-surface-variant"><strong>Práticas:</strong> ${c.praticas}</p>`:''}
        ${c.terapeuta?`<p class="text-xs text-on-surface-variant"><strong>Terapeuta:</strong> ${c.terapeuta}</p>`:''}
      </div>
    `).join('');
  }catch{document.getElementById('hist-pac-body').innerHTML='<p class="text-sm text-error text-center py-8">Erro ao carregar.</p>'}
}
</script>
</body>
</html>