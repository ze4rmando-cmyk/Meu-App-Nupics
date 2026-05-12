<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'terapeuta') {
    header('Location: ../login.php'); exit;
}
require_once '../config/db.php';

$uid  = (int)$_SESSION['usuario_id'];
$nome = $_SESSION['nome'];
$pri  = explode(' ',$nome)[0];
$aba  = $_GET['aba'] ?? 'painel';

// ── Dados comuns ──────────────────────────────────────────────────────────────
$terapeuta = $pdo->prepare("SELECT id, especialidade, periodo FROM terapeutas WHERE usuario_id=? AND ativo=1");
$terapeuta->execute([$uid]); $ter = $terapeuta->fetch(PDO::FETCH_ASSOC);

$frase_row = $pdo->query("SELECT texto, autor FROM frases WHERE tipo='terapeuta' AND ativo=1 ORDER BY RAND() LIMIT 1")->fetch();
$frase = $frase_row['texto'] ?? '"Presença plena é o maior presente que um terapeuta pode oferecer."';
$frase_autor = $frase_row['autor'] ?? null;

$avisos = $pdo->query("SELECT * FROM avisos WHERE ativo=1 AND destino IN ('todos','terapeuta') ORDER BY criado_em DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$playlists = $pdo->query("SELECT id, emoji, nome, url FROM playlists WHERE ativo=1 ORDER BY ordem")->fetchAll(PDO::FETCH_ASSOC);

// ── Stats ─────────────────────────────────────────────────────────────────────
$stats = $pdo->prepare("SELECT
  (SELECT COUNT(*) FROM ciclos c JOIN reservas r ON c.reserva_id=r.id JOIN slots s ON r.slot_id=s.id WHERE s.terapeuta_id=? AND c.status='concluido') AS total_concluidos,
  (SELECT COUNT(*) FROM ciclos c JOIN reservas r ON c.reserva_id=r.id JOIN slots s ON r.slot_id=s.id WHERE s.terapeuta_id=? AND c.status='ativo') AS ciclos_ativos,
  (SELECT COUNT(*) FROM sessoes_plantao WHERE terapeuta_id=?) AS plantoes_total,
  (SELECT COUNT(*) FROM reservas r JOIN slots s ON r.slot_id=s.id WHERE s.terapeuta_id=? AND r.status='pendente') AS pendentes_hoje,
  (SELECT COUNT(*) FROM slots s WHERE s.terapeuta_id=? AND s.ativo=1) AS total_slots
");
$stats->execute([$uid,$uid,$uid,$uid,$uid]); $stats = $stats->fetch(PDO::FETCH_ASSOC);

// ── Plantão aberto hoje ───────────────────────────────────────────────────────
$plt_stmt = $pdo->prepare("SELECT p.*, COUNT(sp.id) AS total_atendidos
    FROM plantoes p LEFT JOIN sessoes_plantao sp ON sp.plantao_id=p.id
    WHERE p.terapeuta_id=? AND p.data=? AND p.status='aberto'
    GROUP BY p.id LIMIT 1");
$hoje_php = date('Y-m-d');
$plt_stmt->execute([$uid, $hoje_php]); $plantao_aberto = $plt_stmt->fetch(PDO::FETCH_ASSOC);

// ── Dados específicos por aba ─────────────────────────────────────────────────
$ciclos = $pendentes = $meus_slots = $pacientes_lista = $terapeutas_lista = $atividade = [];

if ($aba === 'painel') {
    $ciclos = $pdo->prepare("
        SELECT c.id AS ciclo_id, c.total_sessoes, c.faltas, c.status AS ciclo_status,
               r.id AS reserva_id, r.paciente_id AS pac_uid, r.data_sessao,
               u.nome AS pnome, u.telefone,
               s.hora_inicio, s.hora_fim, s.dia_semana, s.local, s.praticas,
               (SELECT COUNT(*) FROM anamnese_inicial WHERE ciclo_id=c.id) AS tem_anamnese,
               (SELECT COUNT(*) FROM registros_sessao WHERE ciclo_id=c.id AND status='realizado') AS followups_ok
        FROM ciclos c JOIN reservas r ON c.reserva_id=r.id
        JOIN usuarios u ON r.paciente_id=u.id JOIN slots s ON r.slot_id=s.id
        WHERE s.terapeuta_id=? AND c.status='ativo' ORDER BY s.dia_semana, s.hora_inicio LIMIT 5
    ")->execute([$uid]) ? true : false;
    // re-fetch properly
    $cq = $pdo->prepare("
        SELECT c.id AS ciclo_id, c.total_sessoes, c.faltas,
               r.paciente_id AS pac_uid, r.data_sessao,
               u.nome AS pnome, u.telefone,
               s.hora_inicio, s.hora_fim, s.dia_semana, s.praticas,
               (SELECT COUNT(*) FROM anamnese_inicial WHERE ciclo_id=c.id) AS tem_anamnese,
               (SELECT COUNT(*) FROM registros_sessao WHERE ciclo_id=c.id AND status='realizado') AS followups_ok
        FROM ciclos c JOIN reservas r ON c.reserva_id=r.id
        JOIN usuarios u ON r.paciente_id=u.id JOIN slots s ON r.slot_id=s.id
        WHERE s.terapeuta_id=? AND c.status='ativo' ORDER BY s.dia_semana, s.hora_inicio LIMIT 5
    ");
    $cq->execute([$uid]); $ciclos = $cq->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ciclos as &$cic) {
        $cic['sessoes_feitas'] = (int)$cic['tem_anamnese'] + (int)$cic['followups_ok'];
        $cic['proxima_sessao'] = $cic['tem_anamnese']==0 ? 1 : ((int)$cic['tem_anamnese']+(int)$cic['followups_ok']+1);
    } unset($cic);
    $pend_q = $pdo->prepare("SELECT r.id, r.queixas, r.data_sessao, u.nome AS pnome, s.hora_inicio, s.hora_fim, s.dia_semana
        FROM reservas r JOIN slots s ON r.slot_id=s.id JOIN usuarios u ON r.paciente_id=u.id
        WHERE s.terapeuta_id=? AND r.status='pendente' LIMIT 5");
    $pend_q->execute([$uid]); $pendentes = $pend_q->fetchAll(PDO::FETCH_ASSOC);
    $atividade = $pdo->query("
        (SELECT 'ciclo' AS tipo, c.encerrado_em AS dt, u.nome AS envolvido, s.praticas AS extra
         FROM ciclos c JOIN reservas r ON c.reserva_id=r.id JOIN usuarios u ON r.paciente_id=u.id JOIN slots s ON r.slot_id=s.id
         WHERE c.status='concluido' AND c.encerrado_em IS NOT NULL ORDER BY c.encerrado_em DESC LIMIT 4)
        UNION ALL
        (SELECT 'plantao', sp.criado_em, sp.paciente_nome, sp.tipo_pratica FROM sessoes_plantao sp WHERE sp.terapeuta_id={$uid} ORDER BY sp.criado_em DESC LIMIT 4)
        ORDER BY dt DESC LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
}

if ($aba === 'cronograma') {
    // Meus slots
    $meus_slots = $pdo->prepare("
        SELECT s.*,
          (SELECT COUNT(*) FROM reservas r WHERE r.slot_id=s.id AND r.status NOT IN ('cancelado') AND r.data_sessao>=CURDATE()) AS reservas_ativas,
          DATE_ADD(CURDATE(), INTERVAL MOD(s.dia_semana-1-WEEKDAY(CURDATE())+7,7) DAY) AS prox_data
        FROM slots s WHERE s.terapeuta_id=? ORDER BY s.dia_semana, s.hora_inicio
    ");
    $meus_slots->execute([$uid]); $meus_slots = $meus_slots->fetchAll(PDO::FETCH_ASSOC);
    foreach ($meus_slots as &$sl) {
        $pac = $pdo->prepare("SELECT u.nome FROM reservas r JOIN usuarios u ON r.paciente_id=u.id
            WHERE r.slot_id=? AND r.status NOT IN ('cancelado') AND r.data_sessao=?");
        $pac->execute([$sl['id'], $sl['prox_data']]);
        $sl['pac_nomes'] = $pac->fetchAll(PDO::FETCH_COLUMN);
    } unset($sl);

    // TODOS os slots de todos os terapeutas (para o painel de macas)
    $todos_slots_raw = $pdo->query("
        SELECT s.*, u.nome AS ter_nome, u.id AS ter_uid,
          (SELECT COUNT(*) FROM reservas r WHERE r.slot_id=s.id AND r.status NOT IN ('cancelado') AND r.data_sessao>=CURDATE()) AS reservas_ativas,
          DATE_ADD(CURDATE(), INTERVAL MOD(s.dia_semana-1-WEEKDAY(CURDATE())+7,7) DAY) AS prox_data
        FROM slots s
        JOIN usuarios u ON s.terapeuta_id=u.id
        JOIN terapeutas t ON t.usuario_id=u.id
        WHERE s.ativo=1 AND t.ativo=1
        ORDER BY s.dia_semana, s.hora_inicio
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Agrupa por (dia, hora_inicio) para detectar conflitos de maca
    // Um horário entra em conflito com outro se os intervalos se sobrepõem
    // Regra: máx 2 macas disponíveis — conflito quando >=3 terapeutas têm slots sobrepostos
    // Também marca como ocupado quando >=2 (aviso amarelo) e >=3 (vermelho)
    $slots_por_dia_hora = [];
    foreach ($todos_slots_raw as &$sl) {
        $pac = $pdo->prepare("SELECT u.nome FROM reservas r JOIN usuarios u ON r.paciente_id=u.id
            WHERE r.slot_id=? AND r.status NOT IN ('cancelado') AND r.data_sessao=?");
        $pac->execute([$sl['id'], $sl['prox_data']]);
        $sl['pac_nomes'] = $pac->fetchAll(PDO::FETCH_COLUMN);
        $sl['e_meu'] = ($sl['ter_uid'] == $uid);
    } unset($sl);

    // Detecta sobreposição de horários: para cada par de slots no mesmo dia, verifica se os intervalos se cruzam
    // Monta índice: dia -> lista de slots com hora_inicio/hora_fim
    $conflitos = []; // [dia][hora_inicio_str] => lista de ter_nomes
    foreach ($todos_slots_raw as $sl) {
        $d  = (int)$sl['dia_semana'];
        $hi = $sl['hora_inicio'];
        $hf = $sl['hora_fim'];
        // Para cada outro slot no mesmo dia, verifica sobreposição
        foreach ($todos_slots_raw as $sl2) {
            if ($sl2['id'] === $sl['id']) continue;
            if ((int)$sl2['dia_semana'] !== $d) continue;
            // Verifica sobreposição: (hi1 < hf2) && (hi2 < hf1)
            if ($hi < $sl2['hora_fim'] && $sl2['hora_inicio'] < $hf) {
                $key = $d.'_'.$hi.'_'.$hf;
                if (!isset($conflitos[$key])) $conflitos[$key] = ['slots'=>[],'ter'=>[]];
                $conflitos[$key]['slots'][] = $sl['id'];
                $conflitos[$key]['ter'][] = $sl['ter_nome'];
            }
        }
    }
    // Conta quantos terapeutas únicos por janela de tempo por dia
    // Simplificado: para cada slot, conta quantos outros slots do mesmo dia se sobrepõem
    foreach ($todos_slots_raw as &$sl) {
        $count = 0;
        $d  = (int)$sl['dia_semana'];
        $hi = $sl['hora_inicio'];
        $hf = $sl['hora_fim'];
        $nomes_conflit = [];
        foreach ($todos_slots_raw as $sl2) {
            if ($sl2['id'] === $sl['id']) continue;
            if ((int)$sl2['dia_semana'] !== $d) continue;
            if ($hi < $sl2['hora_fim'] && $sl2['hora_inicio'] < $hf) {
                $count++;
                $nomes_conflit[] = $sl2['ter_nome'];
            }
        }
        $sl['sobrepostos']       = $count;          // quantos outros slots se sobrepõem
        $sl['nomes_sobrepostos'] = array_unique($nomes_conflit);
        $sl['total_no_horario']  = $count + 1;       // inclui ele mesmo
    } unset($sl);
}

if ($aba === 'pacientes') {
    $pacientes_lista = $pdo->prepare("
        SELECT DISTINCT u.id, u.nome, u.email, u.telefone, u.criado_em,
               p.sexo, p.data_nasc, p.vinculo, p.objetivos, p.bloqueado_ate,
               (SELECT COUNT(*) FROM ciclos c2 JOIN reservas r2 ON c2.reserva_id=r2.id
                JOIN slots s2 ON r2.slot_id=s2.id WHERE r2.paciente_id=u.id AND s2.terapeuta_id=?) AS meus_ciclos,
               (SELECT c3.status FROM ciclos c3 JOIN reservas r3 ON c3.reserva_id=r3.id
                JOIN slots s3 ON r3.slot_id=s3.id WHERE r3.paciente_id=u.id AND s3.terapeuta_id=? AND c3.status='ativo' LIMIT 1) AS status_ciclo_ativo
        FROM reservas r JOIN slots s ON r.slot_id=s.id
        JOIN usuarios u ON r.paciente_id=u.id JOIN pacientes p ON p.usuario_id=u.id
        WHERE s.terapeuta_id=? ORDER BY u.nome
    ");
    $pacientes_lista->execute([$uid,$uid,$uid]); $pacientes_lista = $pacientes_lista->fetchAll(PDO::FETCH_ASSOC);
}

if ($aba === 'terapeutas') {
    $terapeutas_lista = $pdo->query("
        SELECT u.id, u.nome, u.email, u.telefone, t.especialidade, t.periodo, t.ativo,
               (SELECT COUNT(*) FROM ciclos c JOIN reservas r ON c.reserva_id=r.id JOIN slots s ON r.slot_id=s.id WHERE s.terapeuta_id=u.id AND c.status='ativo') AS ciclos_ativos,
               (SELECT COUNT(*) FROM ciclos c JOIN reservas r ON c.reserva_id=r.id JOIN slots s ON r.slot_id=s.id WHERE s.terapeuta_id=u.id AND c.status='concluido') AS ciclos_concluidos
        FROM terapeutas t JOIN usuarios u ON t.usuario_id=u.id ORDER BY u.nome
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$dias = [1=>'Seg',2=>'Ter',3=>'Qua',4=>'Qui',5=>'Sex'];
$dias_full = [1=>'Segunda',2=>'Terça',3=>'Quarta',4=>'Quinta',5=>'Sexta'];
$praticas_opcoes = ['Massoterapia','Ventosaterapia','Acupuntura','Reiki','Auriculoterapia','Meditação','Aromaterapia','Reflexologia','Outros'];
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
<title>NUPICS | <?= ucfirst($aba) ?></title>
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

  /* NAV HORIZONTAL */
  .nav-tab{display:flex;align-items:center;gap:6px;padding:10px 18px;border-radius:99px;
           font-size:.8rem;font-weight:600;color:#4d4351;transition:.15s;white-space:nowrap;cursor:pointer;
           text-decoration:none}
  .nav-tab:hover{background:rgba(78,0,120,.07);color:#4e0078}
  .nav-tab.active{background:#4e0078;color:#fff}
  .nav-tab .material-symbols-outlined{font-size:18px}

  /* Modal */
  .modal-wrap{display:none}.modal-wrap.open{display:flex;animation:mfade .18s ease}
  @keyframes mfade{from{opacity:0}to{opacity:1}}
  .modal-card{animation:mup .22s cubic-bezier(.22,1,.36,1)}
  @keyframes mup{from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:translateY(0)}}

  /* Cards */
  .card-h{transition:box-shadow .15s,transform .15s}
  .card-h:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(78,0,120,.10)}

  /* Campo */
  .campo{display:flex;align-items:flex-start;background:rgba(255,255,255,.7);border:1.5px solid #d0c2d3;border-radius:12px;overflow:hidden;transition:.15s}
  .campo:focus-within{border-color:#4e0078;box-shadow:0 0 0 3px rgba(78,0,120,.12)}
  .campo .ic{padding:10px 10px 0;color:#7f7383;flex-shrink:0}
  .campo input,.campo select,.campo textarea{flex:1;border:none;background:transparent;padding:10px 12px 10px 0;font-size:.85rem;color:#201923;font-family:"Manrope",sans-serif;outline:none;min-width:0;resize:vertical}

  /* Pills checkbox */
  .pill-opt{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:99px;
            border:1.5px solid #d0c2d3;font-size:.75rem;font-weight:600;cursor:pointer;
            background:rgba(255,255,255,.6);color:#4d4351;user-select:none;transition:.15s}
  .pill-opt:has(input:checked){background:#4e0078;color:#fff;border-color:#4e0078}
  .pill-opt input{display:none}

  /* Toggle */
  .tog{position:relative;width:36px;height:20px;background:#d0c2d3;border-radius:10px;transition:.2s;cursor:pointer;flex-shrink:0}
  .tog:has(input:checked){background:#4e0078}
  .tog input{position:absolute;opacity:0;width:100%;height:100%;cursor:pointer;margin:0}
  .tog::after{content:'';position:absolute;top:2px;left:2px;width:16px;height:16px;background:#fff;border-radius:50%;transition:.2s;pointer-events:none}
  .tog:has(input:checked)::after{transform:translateX(16px)}

  /* Progresso ciclo */
  .pdot{width:1.5rem;height:1.5rem;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.62rem;font-weight:700;transition:.3s}
  .pdot.done{background:#4e0078;color:#fff}.pdot.curr{background:#4e0078;color:#fff;box-shadow:0 0 0 3px rgba(78,0,120,.2)}.pdot.idle{background:#ecdeed;color:#7f7383}

  /* Cronograma grid */
  .cron-cell{min-height:88px;border-right:1px solid #ecdeed;border-bottom:1px solid #ecdeed;padding:5px;vertical-align:top}
  .cron-slot{border-radius:10px;padding:7px 9px;margin-bottom:3px;font-size:.72rem;cursor:pointer;transition:.15s}
  .cron-slot:hover{filter:brightness(.94)}
  /* Meu slot */
  .cron-slot.meu-slot{background:#4e0078;color:#fff}
  .cron-slot.meu-slot.aviso{background:linear-gradient(135deg,#4e0078,#d97706);color:#fff}
  .cron-slot.meu-slot.conflito{background:linear-gradient(135deg,#4e0078,#dc2626);color:#fff}
  /* Slot de outro terapeuta */
  .cron-slot.outro-slot{background:#e0d6f7;color:#3b0066;border:1.5px solid #c4b1eb}
  .cron-slot.outro-slot.aviso{background:#fef3c7;color:#92400e;border-color:#fbbf24}
  .cron-slot.outro-slot.conflito{background:#fee2e2;color:#7f1d1d;border-color:#f87171}
  /* Livre */
  .cron-slot.tipo-livre{border:2px dashed #d0c2d3;background:transparent;color:#7f7383;text-align:center}
  .cron-slot.tipo-livre:hover{background:rgba(78,0,120,.05);border-color:#4e0078;color:#4e0078}
  /* Badge de maca */
  .maca-badge{display:inline-flex;align-items:center;justify-content:center;font-size:.6rem;font-weight:800;
              width:16px;height:16px;border-radius:50%;flex-shrink:0}
  .maca-ok{background:#22c55e;color:#fff}
  .maca-aviso{background:#f59e0b;color:#fff}
  .maca-cheia{background:#ef4444;color:#fff}
  /* Pulse animation para conflito */
  @keyframes pulse-red{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.3)}50%{box-shadow:0 0 0 6px rgba(239,68,68,0)}}
  .cron-slot.conflito.meu-slot{animation:pulse-red 2s infinite}

  /* Aviso tipo */
  .av-evento{border-left:3px solid #4e0078}.av-urgente{border-left:3px solid #b7004d}
  .av-manutencao{border-left:3px solid #92400e}.av-info{border-left:3px solid #1d4ed8}

  /* Stat bar */
  .stat-bar{height:5px;border-radius:99px;background:linear-gradient(90deg,#4e0078,#b7004d)}

  /* Search */
  #busca-paciente,#busca-terapeuta{border:1.5px solid #d0c2d3;border-radius:99px;padding:8px 16px 8px 40px;font-size:.85rem;width:100%;font-family:"Manrope",sans-serif;background:#fff;outline:none;transition:.15s}
  #busca-paciente:focus,#busca-terapeuta:focus{border-color:#4e0078;box-shadow:0 0 0 3px rgba(78,0,120,.12)}

  textarea:focus,input:focus,select:focus{outline:none}
  ::-webkit-scrollbar{width:4px;height:4px}::-webkit-scrollbar-thumb{background:#d0c2d3;border-radius:99px}
</style>
</head>
<body class="text-on-background min-h-screen flex flex-col">

<!-- ══════════════════════════════════════
     HEADER + NAV HORIZONTAL
══════════════════════════════════════ -->
<header class="fixed top-0 w-full z-50 bg-white/65 backdrop-blur-md shadow-[0_2px_20px_rgba(32,25,35,.07)]">
  <div class="max-w-7xl mx-auto px-4 md:px-8">
    <!-- Top row -->
    <div class="flex items-center justify-between h-14 border-b border-outline-variant/20">
      <span class="text-lg font-extrabold bg-gradient-to-r from-purple-700 to-pink-600 bg-clip-text text-transparent font-['Plus_Jakarta_Sans']">NUPICS</span>
      <div class="flex items-center gap-3">
        <!-- Plantão badge -->
        <?php if ($plantao_aberto): ?>
        <a href="?aba=painel#plantao"
           class="hidden sm:flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-emerald-600 text-white text-xs font-bold hover:opacity-90 transition-all">
          <span class="material-symbols-outlined text-sm">local_hospital</span>
          Plantão aberto · <?= (int)$plantao_aberto['total_atendidos'] ?>/<?= (int)$plantao_aberto['max_pacientes'] ?>
        </a>
        <?php endif; ?>
        <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
          <span class="material-symbols-outlined text-primary text-base">person</span>
        </div>
        <span class="hidden md:block text-sm font-semibold text-on-surface"><?= htmlspecialchars($pri) ?></span>
        <a href="../api/trocar_senha.php" class="text-xs font-semibold text-on-surface-variant hover:text-primary transition-colors flex items-center gap-0.5">
          <span class="material-symbols-outlined" style="font-size:14px">key</span>Senha
        </a>
        <a href="../logout.php" class="text-xs font-semibold text-on-surface-variant hover:text-secondary transition-colors">Sair</a>
      </div>
    </div>
    <!-- Nav tabs row -->
    <div class="flex items-center gap-1 overflow-x-auto py-2 no-scrollbar">
      <?php
      $nav_tabs = [
        'painel'     => ['icon'=>'dashboard',     'label'=>'Painel'],
        'cronograma' => ['icon'=>'calendar_month', 'label'=>'Cronograma'],
        'pacientes'  => ['icon'=>'group',          'label'=>'Pacientes'],
        'terapeutas' => ['icon'=>'medical_services','label'=>'Terapeutas'],
        'protocolos' => ['icon'=>'description',    'label'=>'Protocolos'],
        'perfil'     => ['icon'=>'manage_accounts','label'=>'Perfil'],
      ];
      foreach ($nav_tabs as $k=>$t): ?>
      <a href="?aba=<?= $k ?>" class="nav-tab <?= $aba===$k?'active':'' ?>">
        <span class="material-symbols-outlined"><?= $t['icon'] ?></span>
        <?= $t['label'] ?>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</header>

<!-- ══════════════════════════════════════
     CONTEÚDO
══════════════════════════════════════ -->
<main class="flex-grow pt-[112px] pb-16 px-4 md:px-8 max-w-7xl mx-auto w-full">

<?php if ($aba === 'painel'): ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

  <!-- COLUNA ESQUERDA (2/3) -->
  <div class="lg:col-span-2 space-y-7">

    <!-- Hero -->
    <div>
      <p class="text-xs font-bold uppercase tracking-widest text-primary/50 mb-1"><?= strftime('%A, %d de %B', time()) ?></p>
      <h1 class="text-3xl md:text-4xl font-extrabold text-primary tracking-tight mb-1">Olá, <?= htmlspecialchars($pri) ?>! 🌿</h1>
      <p class="text-sm text-on-surface-variant italic max-w-xl leading-relaxed">
        <?= htmlspecialchars(strip_tags($frase)) ?>
        <?php if ($frase_autor): ?><span class="not-italic text-outline"> — <?= htmlspecialchars($frase_autor) ?></span><?php endif; ?>
      </p>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <?php
      $st_cards = [
        ['label'=>'Ciclos ativos','val'=>$stats['ciclos_ativos'],'cor'=>'border-l-primary','txt'=>'text-primary'],
        ['label'=>'Concluídos','val'=>$stats['total_concluidos'],'cor'=>'border-l-emerald-500','txt'=>'text-emerald-700'],
        ['label'=>'Pendentes','val'=>$stats['pendentes_hoje'],'cor'=>'border-l-amber-500','txt'=>'text-amber-700'],
        ['label'=>'Meus slots','val'=>$stats['total_slots'],'cor'=>'border-l-indigo-500','txt'=>'text-indigo-700'],
      ];
      foreach ($st_cards as $sc): ?>
      <div class="glass rounded-2xl p-4 border-l-4 <?= $sc['cor'] ?>">
        <p class="text-[10px] font-bold uppercase tracking-widest <?= $sc['txt'] ?>/60 mb-0.5"><?= $sc['label'] ?></p>
        <p class="text-3xl font-extrabold <?= $sc['txt'] ?>"><?= $sc['val'] ?></p>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Plantão hoje -->
    <div class="glass rounded-2xl p-5 border border-outline-variant/20">
      <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
          <span class="material-symbols-outlined text-emerald-600">local_hospital</span>
          <h2 class="font-headline font-bold text-on-surface">Plantão de hoje</h2>
        </div>
        <?php if (!$plantao_aberto): ?>
        <button onclick="abrirModal('modal-plantao-novo')"
          class="flex items-center gap-1.5 px-4 py-2 rounded-full bg-primary text-white text-xs font-bold hover:opacity-90 active:scale-95 transition-all">
          <span class="material-symbols-outlined text-sm">add_circle</span>Iniciar plantão
        </button>
        <?php endif; ?>
      </div>

      <?php if ($plantao_aberto):
        $pct = $plantao_aberto['max_pacientes'] > 0
          ? round(($plantao_aberto['total_atendidos'] / $plantao_aberto['max_pacientes']) * 100) : 0;
      ?>
      <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 mb-4">
        <div class="flex items-center gap-4 mb-3">
          <div class="text-center"><p class="text-2xl font-extrabold text-emerald-700"><?= (int)$plantao_aberto['total_atendidos'] ?></p><p class="text-xs text-emerald-600">atendidos</p></div>
          <div class="text-center"><p class="text-2xl font-extrabold text-emerald-700"><?= (int)$plantao_aberto['max_pacientes'] ?></p><p class="text-xs text-emerald-600">limite</p></div>
          <div class="flex-grow h-2 rounded-full bg-emerald-200 overflow-hidden">
            <div class="h-full bg-emerald-500 rounded-full transition-all" style="width:<?= $pct ?>%"></div>
          </div>
          <span class="text-xs font-bold text-emerald-700"><?= $plantao_aberto['hora_inicio'] ?> – <?= $plantao_aberto['hora_fim'] ?></span>
        </div>
        <div class="flex gap-2">
          <button onclick="abrirModal('modal-plantao-ativo')"
            class="flex-grow flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-full bg-emerald-600 text-white text-sm font-bold hover:opacity-90 transition-all">
            <span class="material-symbols-outlined text-sm">person_add</span>Registrar atendimento
          </button>
          <button onclick="encerrarPlantao(<?= (int)$plantao_aberto['id'] ?>)"
            class="px-4 py-2.5 rounded-full border-2 border-emerald-300 text-emerald-700 text-sm font-bold hover:bg-emerald-50 transition-all">Encerrar</button>
        </div>
      </div>
      <?php else: ?>
      <p class="text-sm text-on-surface-variant text-center py-4">Nenhum plantão aberto. Clique em "Iniciar plantão" quando for atender.</p>
      <?php endif; ?>

      <!-- Últimos do plantão -->
      <?php
      $ult_plt = $pdo->prepare("SELECT paciente_nome, tipo_pratica, queixa, criado_em FROM sessoes_plantao WHERE terapeuta_id=? ORDER BY criado_em DESC LIMIT 5");
      $ult_plt->execute([$uid]); $ult_plt = $ult_plt->fetchAll(PDO::FETCH_ASSOC);
      if (!empty($ult_plt)): ?>
      <div class="border-t border-outline-variant/20 pt-4 space-y-2">
        <p class="text-xs font-bold uppercase tracking-widest text-on-surface-variant mb-2">Últimos atendimentos</p>
        <?php foreach ($ult_plt as $u): ?>
        <div class="flex items-center justify-between bg-surface-container-low/60 rounded-xl px-3 py-2 border border-outline-variant/15">
          <div class="min-w-0">
            <p class="text-xs font-bold text-on-surface truncate"><?= htmlspecialchars($u['paciente_nome']) ?></p>
            <p class="text-[10px] text-on-surface-variant truncate"><?= htmlspecialchars($u['tipo_pratica']) ?></p>
          </div>
          <span class="text-[10px] text-outline shrink-0 ml-3"><?= date('d/m H:i', strtotime($u['criado_em'])) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Ciclos ativos (resumo) -->
    <div class="glass rounded-2xl p-5 border border-outline-variant/20">
      <div class="flex items-center justify-between mb-4">
        <h2 class="font-headline font-bold text-on-surface">Meus ciclos ativos</h2>
        <a href="?aba=cronograma" class="text-xs font-bold text-primary hover:underline">Ver cronograma →</a>
      </div>
      <?php if (empty($ciclos)): ?>
      <p class="text-sm text-on-surface-variant text-center py-6">Nenhum ciclo ativo no momento.</p>
      <?php else: foreach ($ciclos as $cic):
        $prox=(int)$cic['proxima_sessao']; $total=(int)$cic['total_sessoes']; $feitas=(int)$cic['sessoes_feitas'];
      ?>
      <div class="card-h rounded-2xl p-4 mb-3 border border-outline-variant/20 bg-surface-container-low/50">
        <div class="flex flex-col sm:flex-row sm:items-center gap-3">
          <div class="flex-grow min-w-0">
            <div class="flex flex-wrap items-center gap-2 mb-1.5">
              <span class="font-bold text-sm text-on-surface"><?= htmlspecialchars($cic['pnome']) ?></span>
              <?php if ((int)$cic['faltas']>0): ?>
              <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $cic['faltas']>=2?'bg-red-100 text-red-700':'bg-amber-100 text-amber-700' ?>"><?= $cic['faltas'] ?> falta<?= $cic['faltas']>1?'s':'' ?></span>
              <?php endif; ?>
            </div>
            <p class="text-xs text-on-surface-variant mb-2"><?= $dias_full[(int)$cic['dia_semana']] ?> · <?= substr($cic['hora_inicio'],0,5) ?>–<?= substr($cic['hora_fim'],0,5) ?></p>
            <div class="flex items-center gap-1.5">
              <?php for($i=1;$i<=$total;$i++): $d=$i<=$feitas; $c=$i===$prox; ?>
              <div class="pdot <?= $d?'done':($c?'curr':'idle') ?>"><?= $d?'✓':$i ?></div>
              <?php if($i<$total): ?><div class="w-3 h-0.5 rounded-full <?= $d?'bg-primary':'bg-outline-variant' ?>"></div><?php endif; ?>
              <?php endfor; ?>
              <span class="ml-1.5 text-xs text-on-surface-variant"><?= $feitas ?>/<?= $total ?></span>
            </div>
          </div>
          <div class="flex flex-col gap-1.5 shrink-0">
            <?php if ($prox<=$total): ?>
            <a href="sessao.php?ciclo_id=<?= $cic['ciclo_id'] ?>"
              class="flex items-center justify-center gap-1 px-3 py-2 rounded-full bg-primary text-white text-xs font-bold hover:opacity-90">
              <span class="material-symbols-outlined text-sm">edit_note</span>
              <?= $prox===1?'Anamnese':("S{$prox}") ?>
            </a>
            <?php endif; ?>
            <button onclick="abrirAdiar(<?= $cic['ciclo_id'] ?>,<?= $prox ?>,'<?= addslashes($cic['pnome']) ?>')"
              class="flex items-center justify-center gap-1 px-3 py-2 rounded-full border border-amber-200 text-amber-700 text-xs font-bold hover:bg-amber-50">
              <span class="material-symbols-outlined text-sm">event_busy</span>Adiar
            </button>
            <button onclick="abrirFaltou(<?= $cic['ciclo_id'] ?>,<?= $prox ?>,<?= $cic['faltas'] ?>,'<?= addslashes($cic['pnome']) ?>')"
              class="flex items-center justify-center gap-1 px-3 py-2 rounded-full border border-red-200 text-red-600 text-xs font-bold hover:bg-red-50">
              <span class="material-symbols-outlined text-sm">person_off</span>Faltou
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; endif; ?>
      <?php if (count($ciclos)>=5): ?>
      <a href="?aba=cronograma" class="block text-center text-xs font-bold text-primary hover:underline mt-2">Ver todos os ciclos →</a>
      <?php endif; ?>
    </div>

    <!-- Pendentes -->
    <?php if (!empty($pendentes)): ?>
    <div class="glass rounded-2xl p-5 border border-amber-200/50">
      <div class="flex items-center gap-2 mb-4">
        <span class="material-symbols-outlined text-amber-500">pending_actions</span>
        <h2 class="font-headline font-bold text-on-surface">Confirmações pendentes (<?= count($pendentes) ?>)</h2>
      </div>
      <div class="space-y-2.5">
        <?php foreach ($pendentes as $r): ?>
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 bg-amber-50/60 rounded-xl px-4 py-3 border border-amber-100">
          <div class="flex-grow min-w-0">
            <p class="font-bold text-sm text-on-surface"><?= htmlspecialchars($r['pnome']) ?></p>
            <p class="text-xs text-on-surface-variant"><?= $dias_full[(int)$r['dia_semana']] ?> · <?= substr($r['hora_inicio'],0,5) ?>–<?= substr($r['hora_fim'],0,5) ?></p>
            <?php if ($r['queixas']): ?><p class="text-xs text-on-surface-variant mt-0.5 truncate">"<?= htmlspecialchars($r['queixas']) ?>"</p><?php endif; ?>
          </div>
          <div class="flex gap-2 shrink-0">
            <button onclick="confirmarRes(<?= $r['id'] ?>,this)" class="px-3 py-2 rounded-full bg-primary text-white text-xs font-bold hover:opacity-90">Confirmar</button>
            <button onclick="recusarRes(<?= $r['id'] ?>,this)" class="px-3 py-2 rounded-full border-2 border-outline-variant text-on-surface-variant text-xs font-bold hover:bg-surface-container-high">Recusar</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Estatísticas -->
    <div class="glass rounded-2xl p-5 border border-outline-variant/20">
      <div class="flex items-center gap-2 mb-5">
        <span class="material-symbols-outlined text-primary">bar_chart</span>
        <h2 class="font-headline font-bold text-on-surface">Estatísticas</h2>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
        <div>
          <p class="text-xs font-bold uppercase tracking-widest text-primary/50 mb-3">Meu desempenho</p>
          <?php foreach ([['Ciclos concluídos',$stats['total_concluidos'],max(1,(int)$stats['total_concluidos'])],['Ciclos ativos',$stats['ciclos_ativos'],max(1,(int)$stats['ciclos_ativos'])],['Atendimentos plantão',$stats['plantoes_total'],max(1,(int)$stats['plantoes_total'])]] as [$l,$v,$m]): $p=min(100,round(($v/$m)*100)); ?>
          <div class="mb-2.5"><div class="flex justify-between text-xs mb-1"><span class="text-on-surface-variant"><?= $l ?></span><span class="font-bold text-primary"><?= $v ?></span></div>
          <div class="h-1.5 rounded-full bg-surface-container-highest overflow-hidden"><div class="h-full stat-bar" style="width:<?= $p ?>%"></div></div></div>
          <?php endforeach; ?>
        </div>
        <div>
          <p class="text-xs font-bold uppercase tracking-widest text-primary/50 mb-3">Projeto NUPICS</p>
          <?php foreach ([['Pacientes atendidos',$stats_gerais['pac_atend']],['Ciclos concluídos',$stats_gerais['ciclos_conc']],['Ciclos ativos',$stats_gerais['ciclos_atv']],['Plantões realizados',$stats_gerais['plt_total']],['Pacientes cadastrados',$stats_gerais['pac_cad']]] as [$l,$v]): ?>
          <div class="flex justify-between items-center py-1.5 border-b border-outline-variant/15 last:border-0">
            <span class="text-xs text-on-surface-variant"><?= $l ?></span>
            <span class="text-sm font-extrabold text-primary"><?= $v ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

  </div><!-- /col left -->

  <!-- COLUNA DIREITA (1/3) -->
  <div class="space-y-5">

    <!-- Avisos -->
    <div class="glass rounded-2xl p-5 border border-primary/10">
      <div class="flex items-center gap-2 mb-4"><span class="material-symbols-outlined text-primary">campaign</span><h3 class="font-headline font-bold text-on-surface">Avisos</h3></div>
      <?php if (empty($avisos)): ?>
      <p class="text-sm text-on-surface-variant text-center py-3">Nenhum aviso.</p>
      <?php else:
        $av_ic=['evento'=>'event','urgente'=>'warning','manutencao'=>'build','info'=>'info'];
        $av_cor=['evento'=>'text-primary','urgente'=>'text-secondary','manutencao'=>'text-amber-600','info'=>'text-blue-600'];
        foreach ($avisos as $av): ?>
      <div class="av-<?= $av['tipo'] ?> bg-white/60 rounded-xl px-4 py-3 mb-2 last:mb-0">
        <div class="flex items-start gap-2">
          <span class="material-symbols-outlined text-sm mt-0.5 shrink-0 <?= $av_cor[$av['tipo']]??'text-primary' ?>"><?= $av_ic[$av['tipo']]??'info' ?></span>
          <div><p class="text-xs font-bold text-on-surface"><?= htmlspecialchars($av['titulo']) ?></p>
          <p class="text-xs text-on-surface-variant mt-0.5 leading-relaxed"><?= htmlspecialchars($av['texto']) ?></p>
          <p class="text-[10px] text-outline mt-1"><?= date('d/m/Y', strtotime($av['criado_em'])) ?></p></div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Playlists -->
    <div class="glass rounded-2xl p-5 border border-outline-variant/20">
      <div class="flex items-center gap-2 mb-4"><div class="p-1.5 bg-primary rounded-lg text-white"><span class="material-symbols-outlined text-sm">graphic_eq</span></div><h3 class="font-headline font-bold text-on-surface text-sm">Ambiente terapêutico</h3></div>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($playlists as $pl):
          preg_match('/(?:v=|youtu\.be\/)([A-Za-z0-9_\-]{11})/', $pl['url'], $m2); $ytid=$m2[1]??'';
        ?>
        <button onclick="abrirPlaylist('<?= htmlspecialchars($ytid) ?>','<?= htmlspecialchars($pl['nome'],ENT_QUOTES) ?>')"
          class="flex items-center gap-1.5 px-3 py-2 rounded-full glass border border-outline-variant/30 hover:border-primary/40 hover:bg-primary/5 transition-all text-xs font-medium">
          <?= $pl['emoji'] ?> <?= htmlspecialchars($pl['nome']) ?>
          <span class="material-symbols-outlined text-xs text-primary/60">play_circle</span>
        </button>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Atividade recente -->
    <?php if (!empty($atividade)): ?>
    <div class="glass rounded-2xl p-5 border border-outline-variant/20">
      <div class="flex items-center gap-2 mb-4"><span class="material-symbols-outlined text-secondary">history</span><h3 class="font-headline font-bold text-on-surface">Atividade recente</h3></div>
      <div class="space-y-2.5">
        <?php foreach ($atividade as $at): $isCiclo=$at['tipo']==='ciclo'; ?>
        <div class="flex items-start gap-2.5">
          <div class="w-7 h-7 rounded-full flex items-center justify-center shrink-0 <?= $isCiclo?'bg-indigo-100 text-indigo-600':'bg-emerald-100 text-emerald-600' ?>">
            <span class="material-symbols-outlined text-xs"><?= $isCiclo?'verified':'local_hospital' ?></span>
          </div>
          <div class="flex-grow min-w-0">
            <p class="text-xs font-bold text-on-surface truncate"><?= htmlspecialchars($at['envolvido']) ?></p>
            <p class="text-[10px] text-on-surface-variant"><?= $isCiclo?'Ciclo concluído':'Plantão: '.htmlspecialchars($at['extra']??'') ?></p>
          </div>
          <span class="text-[10px] text-outline shrink-0"><?= date('d/m', strtotime($at['dt'])) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- NUPICS info -->
    <div class="bg-gradient-to-br from-primary to-secondary p-5 rounded-2xl text-white relative overflow-hidden">
      <div class="relative z-10 space-y-2">
        <h3 class="text-base font-extrabold">NUPICS Caicó</h3>
        <p class="text-purple-100 text-xs leading-relaxed">Práticas integrativas gratuitas para a comunidade. Campus UERN, Caicó/RN.</p>
      </div>
      <span class="material-symbols-outlined absolute -bottom-2 -right-2 text-[72px] text-white/10">eco</span>
    </div>

  </div><!-- /col right -->
</div>

<?php elseif ($aba === 'cronograma'): ?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
  <div>
    <h1 class="text-2xl font-extrabold text-primary">Cronograma semanal</h1>
    <p class="text-sm text-on-surface-variant">Gerencie seus horários e acompanhe seus ciclos.</p>
  </div>
  <div class="flex gap-3">
    <?php if ($plantao_aberto): ?>
    <button onclick="abrirModal('modal-plantao-ativo')"
      class="flex items-center gap-1.5 px-5 py-2.5 rounded-full bg-emerald-600 text-white text-sm font-bold hover:opacity-90 transition-all">
      <span class="material-symbols-outlined text-sm">local_hospital</span>Registrar atendimento
    </button>
    <?php else: ?>
    <button onclick="abrirModal('modal-plantao-novo')"
      class="flex items-center gap-1.5 px-5 py-2.5 rounded-full border-2 border-emerald-400 text-emerald-700 text-sm font-bold hover:bg-emerald-50 transition-all">
      <span class="material-symbols-outlined text-sm">local_hospital</span>Iniciar plantão
    </button>
    <?php endif; ?>
    <button onclick="abrirModal('modal-novo-slot')"
      class="flex items-center gap-1.5 px-5 py-2.5 rounded-full bg-primary text-white text-sm font-bold hover:opacity-90 active:scale-95 transition-all">
      <span class="material-symbols-outlined text-sm">add</span>Novo horário
    </button>
  </div>
</div>

<!-- Stats rápidos do cronograma -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-7">
  <?php
  $hoje_count = $pdo->prepare("SELECT COUNT(*) FROM reservas r JOIN slots s ON r.slot_id=s.id WHERE s.terapeuta_id=? AND r.status='confirmado' AND r.data_sessao=CURDATE()");
  $hoje_count->execute([$uid]); $hoje_n = $hoje_count->fetchColumn();
  $semana_count = $pdo->prepare("SELECT COUNT(*) FROM reservas r JOIN slots s ON r.slot_id=s.id WHERE s.terapeuta_id=? AND r.status='confirmado' AND r.data_sessao BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)");
  $semana_count->execute([$uid]); $semana_n = $semana_count->fetchColumn();
  $fila_count = $pdo->prepare("SELECT COUNT(*) FROM fila_espera fe JOIN slots s ON fe.slot_id=s.id WHERE s.terapeuta_id=? AND fe.status='aguardando'");
  $fila_count->execute([$uid]); $fila_n = $fila_count->fetchColumn();
  $cards_cron=[
    ['Atendimentos hoje',$hoje_n,'calendar_today','text-primary','bg-primary/5'],
    ['Próximos 7 dias',$semana_n,'date_range','text-indigo-700','bg-indigo-50'],
    ['Na fila de espera',$fila_n,'queue','text-amber-700','bg-amber-50'],
    ['Horários ativos',count($meus_slots),'schedule','text-emerald-700','bg-emerald-50'],
  ];
  foreach ($cards_cron as [$l,$v,$ic,$tc,$bc]): ?>
  <div class="glass rounded-2xl p-4 <?= $bc ?> border border-outline-variant/20">
    <div class="flex items-center justify-between mb-1">
      <p class="text-xs font-bold uppercase tracking-widest <?= $tc ?>/60"><?= $l ?></p>
      <span class="material-symbols-outlined <?= $tc ?> text-lg"><?= $ic ?></span>
    </div>
    <p class="text-3xl font-extrabold <?= $tc ?>"><?= $v ?></p>
  </div>
  <?php endforeach; ?>
</div>

<?php
// ── Agrupa todos os slots (meus + outros) por dia/hora para a grade ──────────
$por_hora_todos = [];
foreach ($todos_slots_raw as $sl) {
    $h = substr($sl['hora_inicio'],0,5);
    // Cada célula pode ter múltiplos slots (vários terapeutas)
    $por_hora_todos[$h][(int)$sl['dia_semana']][] = $sl;
}
// Meus slots também indexados separado para clique de edição
$meus_por_hora = [];
foreach ($meus_slots as $sl) {
    $meus_por_hora[substr($sl['hora_inicio'],0,5)][(int)$sl['dia_semana']] = $sl;
}
$horas_exib = [];
for ($h=7;$h<=18;$h+=2) $horas_exib[]=sprintf('%02d:00',$h);
$horas_todas = array_unique(array_merge($horas_exib, array_keys($por_hora_todos)));
sort($horas_todas);
?>

<!-- Legenda de macas -->
<div class="flex flex-wrap items-center gap-4 mb-4 px-1">
  <p class="text-xs font-bold text-on-surface-variant uppercase tracking-widest">Ocupação das macas:</p>
  <div class="flex items-center gap-1.5"><span class="maca-badge maca-ok">1</span><span class="text-xs text-on-surface-variant">1 terapeuta — livre</span></div>
  <div class="flex items-center gap-1.5"><span class="maca-badge maca-aviso">2</span><span class="text-xs text-on-surface-variant">2 terapeutas — macas cheias</span></div>
  <div class="flex items-center gap-1.5"><span class="maca-badge maca-cheia">3+</span><span class="text-xs text-on-surface-variant">3+ terapeutas — <strong>conflito!</strong></span></div>
  <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded bg-[#4e0078]"></div><span class="text-xs text-on-surface-variant">Meu horário</span></div>
  <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded bg-[#e0d6f7] border border-[#c4b1eb]"></div><span class="text-xs text-on-surface-variant">Outro terapeuta</span></div>
</div>

<!-- Grade do cronograma compartilhado -->
<div class="glass rounded-2xl overflow-hidden border border-outline-variant/20 mb-7">
  <!-- Header da grade -->
  <div class="grid border-b border-outline-variant/20 bg-surface-container-low/60" style="grid-template-columns:72px repeat(5,1fr)">
    <div class="p-3 text-[10px] font-bold uppercase tracking-widest text-on-surface-variant text-center">Horário</div>
    <?php foreach ($dias_full as $didx=>$d):
      // Conta conflitos nesse dia
      $conf_dia = 0;
      foreach ($todos_slots_raw as $sl) {
          if ((int)$sl['dia_semana']===$didx && $sl['total_no_horario']>=3) $conf_dia++;
      }
    ?>
    <div class="p-3 text-center font-bold text-on-surface text-sm border-l border-outline-variant/20 <?= $conf_dia>0?'bg-red-50/50':'' ?>">
      <?= $d ?>
      <?php if ($conf_dia>0): ?>
      <span class="block text-[9px] text-red-600 font-bold">⚠ conflito</span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <?php foreach ($horas_todas as $hora): ?>
  <div class="grid border-b border-outline-variant/10 last:border-0" style="grid-template-columns:72px repeat(5,1fr)">
    <div class="p-2 text-[10px] font-bold text-on-surface-variant text-center pt-3"><?= $hora ?></div>

    <?php foreach ([1,2,3,4,5] as $d):
      $slots_celula = $por_hora_todos[$hora][$d] ?? [];
      $meu_slot     = $meus_por_hora[$hora][$d]  ?? null;
      $total_ter    = count($slots_celula); // quantos terapeutas nessa célula exata
      // Conta sobreposições (qualquer slot que se sobreponha, não só mesma hora exata)
      $max_sobrep = 0;
      if (!empty($slots_celula)) {
          $max_sobrep = max(array_column($slots_celula, 'total_no_horario'));
      }
      // Classe de maca
      $maca_cls = $max_sobrep >= 3 ? 'maca-cheia' : ($max_sobrep == 2 ? 'maca-aviso' : ($max_sobrep == 1 ? 'maca-ok' : ''));
      $maca_num = $max_sobrep;
    ?>
    <div class="cron-cell relative">

      <?php if (empty($slots_celula)): ?>
      <!-- Célula vazia — clicável para adicionar meu slot -->
      <div class="cron-slot tipo-livre h-full min-h-[60px] flex flex-col items-center justify-center gap-1"
           onclick="preencherNovoSlot(<?= $d ?>, '<?= $hora ?>'); abrirModal('modal-novo-slot')">
        <span class="text-[9px]">+ Adicionar</span>
        <?php if ($maca_num > 0): ?><span class="maca-badge <?= $maca_cls ?>"><?= $maca_num ?></span><?php endif; ?>
      </div>

      <?php else: ?>
      <!-- Badge de maca no canto superior direito da célula -->
      <?php if ($maca_num > 0): ?>
      <span class="maca-badge <?= $maca_cls ?> absolute top-1 right-1 z-10" title="<?= $maca_num ?> terapeuta(s) neste horário"><?= $maca_num ?></span>
      <?php endif; ?>

      <?php foreach ($slots_celula as $sl):
        $e_meu    = $sl['e_meu'];
        $sobrep   = (int)$sl['total_no_horario'];
        $conf_cls = $sobrep >= 3 ? 'conflito' : ($sobrep == 2 ? 'aviso' : '');
        $base_cls = $e_meu ? 'meu-slot' : 'outro-slot';
        $prac_arr = array_slice(array_filter(array_map('trim',explode(',',$sl['praticas']??''))),0,1);
        $vagas_ocu= (int)$sl['reservas_ativas'];
        $vagas_tot= (int)$sl['vagas_total'];
      ?>
      <?php
        $data_slot    = htmlspecialchars(json_encode($sl), ENT_QUOTES);
        $data_detalhe = htmlspecialchars(json_encode([
          'ter'       => $sl['ter_nome'],
          'hi'        => substr($sl['hora_inicio'],0,5),
          'hf'        => substr($sl['hora_fim'],0,5),
          'praticas'  => $sl['praticas'] ?? '',
          'vagas_ocu' => $vagas_ocu,
          'vagas_tot' => $vagas_tot,
          'sobrep'    => $sobrep,
          'nomes'     => $sl['nomes_sobrepostos'],
        ]), ENT_QUOTES);
      ?>
      <div class="cron-slot <?= $base_cls ?> <?= $conf_cls ?>"
           <?php if ($e_meu): ?>
             onclick="abrirModal('modal-editar-slot'); preencherEditSlot(JSON.parse(this.dataset.sl))"
             data-sl="<?= $data_slot ?>"
           <?php else: ?>
             onclick="abrirDetalheSlot(JSON.parse(this.dataset.det))"
             data-det="<?= $data_detalhe ?>"
           <?php endif; ?>>

        <div class="flex items-center justify-between gap-1 mb-0.5">
          <span class="font-bold text-[10px] leading-tight"><?= substr($sl['hora_inicio'],0,5) ?>–<?= substr($sl['hora_fim'],0,5) ?></span>
          <?php if (!$e_meu): ?>
          <span class="text-[8px] opacity-70 shrink-0"><?= $vagas_ocu ?>/<?= $vagas_tot ?></span>
          <?php else: ?>
          <span class="text-[8px] font-bold px-1 py-0.5 rounded <?= $vagas_ocu>=$vagas_tot?'bg-red-500/40':'bg-white/20' ?>">
            <?= $vagas_ocu ?>/<?= $vagas_tot ?>
          </span>
          <?php endif; ?>
        </div>

        <!-- Nome do terapeuta (se não for meu) -->
        <?php if (!$e_meu): ?>
        <p class="text-[9px] font-bold truncate opacity-90"><?= htmlspecialchars(explode(' ',$sl['ter_nome'])[0]) ?></p>
        <?php endif; ?>

        <!-- Prática -->
        <?php foreach ($prac_arr as $pp): ?>
        <p class="text-[8px] opacity-70 truncate"><?= htmlspecialchars($pp) ?></p>
        <?php endforeach; ?>

        <!-- Pacientes (só no meu slot) -->
        <?php if ($e_meu && !empty($sl['pac_nomes'])): ?>
        <div class="mt-1 pt-1 border-t border-white/25">
          <?php foreach (array_slice($sl['pac_nomes'],0,2) as $pn): ?>
          <p class="text-[8px] opacity-90 truncate">· <?= htmlspecialchars($pn) ?></p>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Aviso de conflito -->
        <?php if ($conf_cls === 'conflito' && $e_meu): ?>
        <div class="mt-1 flex items-center gap-0.5">
          <span style="font-family:'Material Symbols Outlined';font-size:10px">warning</span>
          <span class="text-[8px] font-bold">Sem maca!</span>
        </div>
        <?php elseif ($conf_cls === 'aviso' && $e_meu): ?>
        <div class="mt-1 flex items-center gap-0.5 opacity-80">
          <span style="font-family:'Material Symbols Outlined';font-size:9px">info</span>
          <span class="text-[8px]">Macas cheias</span>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>

    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- Modal: Detalhe de slot de outro terapeuta -->
<div class="modal-wrap fixed inset-0 z-[100] items-center justify-center p-4" id="modal-detalhe-slot">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-detalhe-slot')"></div>
  <div class="glass modal-card relative z-10 w-full max-w-sm rounded-[2rem] shadow-2xl p-7">
    <div class="flex items-center justify-between mb-4">
      <h2 class="text-base font-extrabold text-primary">Horário de outro terapeuta</h2>
      <button onclick="fecharModal('modal-detalhe-slot')" class="w-8 h-8 flex items-center justify-center rounded-full bg-surface-container-high"><span class="material-symbols-outlined text-sm text-on-surface-variant">close</span></button>
    </div>
    <div id="detalhe-slot-body" class="space-y-3 text-sm"></div>
  </div>
</div>

<!-- Lista de todos os ciclos -->
<?php
$todos_ciclos = $pdo->prepare("
    SELECT c.id AS ciclo_id, c.total_sessoes, c.faltas, c.status AS ciclo_status,
           r.paciente_id AS pac_uid, r.data_sessao,
           u.nome AS pnome, u.telefone,
           s.hora_inicio, s.hora_fim, s.dia_semana, s.praticas,
           (SELECT COUNT(*) FROM anamnese_inicial WHERE ciclo_id=c.id) AS tem_anamnese,
           (SELECT COUNT(*) FROM registros_sessao WHERE ciclo_id=c.id AND status='realizado') AS followups_ok
    FROM ciclos c JOIN reservas r ON c.reserva_id=r.id
    JOIN usuarios u ON r.paciente_id=u.id JOIN slots s ON r.slot_id=s.id
    WHERE s.terapeuta_id=? AND c.status IN ('ativo','concluido','cancelado')
    ORDER BY FIELD(c.status,'ativo','concluido','cancelado'), s.dia_semana, s.hora_inicio
");
$todos_ciclos->execute([$uid]); $todos_ciclos = $todos_ciclos->fetchAll(PDO::FETCH_ASSOC);
foreach ($todos_ciclos as &$tc) {
    $tc['sessoes_feitas'] = (int)$tc['tem_anamnese']+(int)$tc['followups_ok'];
    $tc['proxima_sessao'] = $tc['tem_anamnese']==0 ? 1 : ($tc['sessoes_feitas']+1);
} unset($tc);
?>
<div class="glass rounded-2xl p-5 border border-outline-variant/20">
  <h2 class="font-headline font-bold text-on-surface mb-4">Todos os ciclos (<?= count($todos_ciclos) ?>)</h2>
  <?php if (empty($todos_ciclos)): ?>
  <p class="text-sm text-on-surface-variant text-center py-8">Nenhum ciclo ainda. Confirme reservas pendentes para iniciar ciclos.</p>
  <?php else: ?>
  <div class="space-y-2.5">
    <?php foreach ($todos_ciclos as $tc):
      $prox=(int)$tc['proxima_sessao']; $total=(int)$tc['total_sessoes']; $feitas=(int)$tc['sessoes_feitas'];
      $ativo=$tc['ciclo_status']==='ativo';
    ?>
    <div class="flex flex-col sm:flex-row sm:items-center gap-3 rounded-2xl px-4 py-3.5 border border-outline-variant/20 <?= $ativo?'bg-surface-container-low/60':'opacity-60 bg-white/40' ?>">
      <div class="flex-grow min-w-0">
        <div class="flex flex-wrap items-center gap-2 mb-1">
          <span class="font-bold text-sm text-on-surface"><?= htmlspecialchars($tc['pnome']) ?></span>
          <span class="text-[10px] font-bold px-2 py-0.5 rounded-full
            <?= $tc['ciclo_status']==='ativo'?'bg-primary/10 text-primary':($tc['ciclo_status']==='concluido'?'bg-indigo-100 text-indigo-700':'bg-red-50 text-red-600') ?>">
            <?= ucfirst($tc['ciclo_status']) ?>
          </span>
          <?php if ((int)$tc['faltas']>0): ?>
          <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $tc['faltas']>=2?'bg-red-100 text-red-700':'bg-amber-100 text-amber-700' ?>"><?= $tc['faltas'] ?> falta<?= $tc['faltas']>1?'s':'' ?></span>
          <?php endif; ?>
        </div>
        <p class="text-xs text-on-surface-variant"><?= $dias_full[(int)$tc['dia_semana']] ?> · <?= substr($tc['hora_inicio'],0,5) ?>–<?= substr($tc['hora_fim'],0,5) ?></p>
        <div class="flex items-center gap-1 mt-2">
          <?php for($i=1;$i<=$total;$i++): $d=$i<=$feitas; $c=$ativo&&$i===$prox; ?>
          <div class="pdot <?= $d?'done':($c?'curr':'idle') ?>" style="width:1.2rem;height:1.2rem;font-size:.55rem"><?= $d?'✓':$i ?></div>
          <?php if($i<$total): ?><div class="w-2.5 h-0.5 rounded-full <?= $d?'bg-primary':'bg-outline-variant' ?>"></div><?php endif; ?>
          <?php endfor; ?>
          <span class="ml-1 text-xs text-on-surface-variant"><?= $feitas ?>/<?= $total ?></span>
        </div>
      </div>
      <?php if ($ativo && $prox<=$total): ?>
      <a href="sessao.php?ciclo_id=<?= $tc['ciclo_id'] ?>"
        class="shrink-0 flex items-center justify-center gap-1 px-3 py-2 rounded-full bg-primary text-white text-xs font-bold hover:opacity-90">
        <span class="material-symbols-outlined text-sm">edit_note</span>
        <?= $prox===1?'Anamnese':("Sessão {$prox}") ?>
      </a>
      <?php elseif ($ativo && $feitas>=$total): ?>
      <a href="relatorio.php?ciclo_id=<?= $tc['ciclo_id'] ?>"
        class="shrink-0 flex items-center justify-center gap-1 px-3 py-2 rounded-full bg-indigo-600 text-white text-xs font-bold hover:opacity-90">
        <span class="material-symbols-outlined text-sm">summarize</span>Relatório
      </a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($aba === 'pacientes'): ?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
  <div>
    <h1 class="text-2xl font-extrabold text-primary">Meus pacientes</h1>
    <p class="text-sm text-on-surface-variant"><?= count($pacientes_lista) ?> paciente<?= count($pacientes_lista)!=1?'s':'' ?> atendido<?= count($pacientes_lista)!=1?'s':'' ?> por mim</p>
  </div>
</div>

<!-- Busca -->
<div class="relative mb-5 max-w-md">
  <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-outline text-lg">search</span>
  <input type="text" id="busca-paciente" placeholder="Buscar por nome ou e-mail..." oninput="filtrarPacientes()"/>
</div>

<div id="lista-pacientes" class="grid gap-3">
  <?php foreach ($pacientes_lista as $pac):
    $bloqueado = $pac['bloqueado_ate'] && $pac['bloqueado_ate'] >= date('Y-m-d');
    $idade = $pac['data_nasc'] ? floor((time()-strtotime($pac['data_nasc']))/31557600) : null;
  ?>
  <div class="pac-item card-h glass rounded-2xl p-5 border border-outline-variant/20 cursor-pointer"
       data-nome="<?= strtolower($pac['nome']) ?>" data-email="<?= strtolower($pac['email']) ?>"
       onclick="abrirHistPaciente(<?= $pac['id'] ?>, '<?= addslashes($pac['nome']) ?>')">
    <div class="flex flex-col sm:flex-row sm:items-center gap-4">
      <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-primary">person</span>
      </div>
      <div class="flex-grow min-w-0">
        <div class="flex flex-wrap items-center gap-2 mb-1">
          <h3 class="font-headline font-bold text-on-surface"><?= htmlspecialchars($pac['nome']) ?></h3>
          <?php if ($bloqueado): ?>
          <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-700">Bloqueado até <?= date('d/m',strtotime($pac['bloqueado_ate'])) ?></span>
          <?php endif; ?>
          <?php if ($pac['status_ciclo_ativo']): ?>
          <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-primary/10 text-primary">Ciclo ativo</span>
          <?php endif; ?>
          <?php if ($pac['vinculo']): ?>
          <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $pac['vinculo']==='interno'?'bg-blue-50 text-blue-700':'bg-amber-50 text-amber-700' ?>"><?= $pac['vinculo']==='interno'?'🎓 Interno':'🏙️ Externo' ?></span>
          <?php endif; ?>
        </div>
        <div class="flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-on-surface-variant">
          <span><?= htmlspecialchars($pac['email']) ?></span>
          <?php if ($pac['telefone']): ?><span><?= htmlspecialchars($pac['telefone']) ?></span><?php endif; ?>
          <?php if ($idade): ?><span><?= $idade ?> anos</span><?php endif; ?>
          <?php if ($pac['sexo']): ?><span><?= htmlspecialchars($pac['sexo']) ?></span><?php endif; ?>
        </div>
        <?php if ($pac['objetivos']): ?>
        <p class="text-xs text-on-surface-variant mt-1">Objetivos: <?= htmlspecialchars(mb_substr($pac['objetivos'],0,80)) ?></p>
        <?php endif; ?>
      </div>
      <div class="flex items-center gap-3 shrink-0">
        <div class="text-center">
          <p class="text-xl font-extrabold text-primary"><?= (int)$pac['meus_ciclos'] ?></p>
          <p class="text-[10px] text-on-surface-variant">ciclos comigo</p>
        </div>
        <span class="material-symbols-outlined text-outline-variant">chevron_right</span>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (empty($pacientes_lista)): ?>
  <div class="text-center py-14 text-on-surface-variant">
    <span class="material-symbols-outlined text-5xl mb-3 block">group</span>
    <p class="text-sm">Nenhum paciente atendido ainda.</p>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($aba === 'terapeutas'): ?>

<div class="mb-6">
  <h1 class="text-2xl font-extrabold text-primary mb-1">Equipe NUPICS</h1>
  <p class="text-sm text-on-surface-variant"><?= count($terapeutas_lista) ?> terapeuta<?= count($terapeutas_lista)!=1?'s':'' ?> cadastrado<?= count($terapeutas_lista)!=1?'s':'' ?></p>
</div>
<div class="relative mb-5 max-w-md">
  <span class="absolute left-3 top-1/2 -translate-y-1/2 material-symbols-outlined text-outline text-lg">search</span>
  <input type="text" id="busca-terapeuta" placeholder="Buscar terapeuta..." oninput="filtrarTerapeutas()"/>
</div>
<div id="lista-terapeutas" class="grid sm:grid-cols-2 gap-4">
  <?php foreach ($terapeutas_lista as $ter): ?>
  <div class="ter-item card-h glass rounded-2xl p-5 border border-outline-variant/20"
       data-nome="<?= strtolower($ter['nome']) ?>" data-email="<?= strtolower($ter['email']) ?>">
    <div class="flex items-start gap-4">
      <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
        <span class="material-symbols-outlined text-primary">person</span>
      </div>
      <div class="flex-grow min-w-0">
        <div class="flex flex-wrap items-center gap-2 mb-1">
          <h3 class="font-headline font-bold text-on-surface"><?= htmlspecialchars($ter['nome']) ?></h3>
          <?php if ($ter['id']==$uid): ?>
          <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-primary/10 text-primary">Você</span>
          <?php endif; ?>
          <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $ter['ativo']?'bg-emerald-100 text-emerald-700':'bg-red-50 text-red-500' ?>"><?= $ter['ativo']?'Ativo':'Inativo' ?></span>
        </div>
        <p class="text-xs text-on-surface-variant"><?= htmlspecialchars($ter['especialidade']) ?> · <?= htmlspecialchars($ter['periodo']) ?></p>
        <?php if ($ter['email']): ?><p class="text-xs text-on-surface-variant mt-0.5"><?= htmlspecialchars($ter['email']) ?></p><?php endif; ?>
        <?php if ($ter['telefone']): ?><p class="text-xs text-on-surface-variant"><?= htmlspecialchars($ter['telefone']) ?></p><?php endif; ?>
        <div class="flex gap-4 mt-2.5">
          <div class="text-center"><p class="text-lg font-extrabold text-primary"><?= (int)$ter['ciclos_ativos'] ?></p><p class="text-[10px] text-on-surface-variant">ciclos ativos</p></div>
          <div class="text-center"><p class="text-lg font-extrabold text-indigo-700"><?= (int)$ter['ciclos_concluidos'] ?></p><p class="text-[10px] text-on-surface-variant">concluídos</p></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php elseif ($aba === 'protocolos'): ?>
<div class="flex flex-col items-center justify-center py-24 text-center">
  <span class="material-symbols-outlined text-6xl text-outline-variant mb-5">construction</span>
  <h1 class="text-2xl font-extrabold text-primary mb-3">Protocolos</h1>
  <p class="text-on-surface-variant max-w-sm leading-relaxed">Esta seção está em construção. Em breve, você poderá acessar e gerenciar os protocolos de atendimento do NUPICS aqui.</p>
  <div class="mt-6 px-5 py-2 rounded-full bg-primary/10 text-primary text-sm font-bold">Em breve 🚧</div>
</div>

<?php elseif ($aba === 'perfil'):
    $perfil = $pdo->prepare("SELECT u.*, t.especialidade, t.periodo FROM usuarios u LEFT JOIN terapeutas t ON t.usuario_id=u.id WHERE u.id=?");
    $perfil->execute([$uid]); $perfil = $perfil->fetch(PDO::FETCH_ASSOC);
?>
<div class="max-w-2xl mx-auto">
  <h1 class="text-2xl font-extrabold text-primary mb-6">Meu perfil</h1>
  <div class="glass rounded-3xl p-7 border border-outline-variant/20 space-y-5">
    <!-- Avatar com upload -->
    <div class="flex items-center gap-5">
      <div class="avatar-upload" title="Clique para trocar a foto">
        <?php $foto_url = !empty($perfil['foto']) ? '../'.htmlspecialchars($perfil['foto']).'?v='.time() : ''; ?>
        <?php if ($foto_url): ?>
          <img id="img-perfil-ter" src="<?= $foto_url ?>" alt="Foto" class="w-20 h-20" style="border-radius:9999px;object-fit:cover;"/>
        <?php else: ?>
          <div id="img-perfil-ter-placeholder" class="w-20 h-20 rounded-full bg-primary/10 flex items-center justify-center">
            <span class="material-symbols-outlined text-primary text-4xl">person</span>
          </div>
        <?php endif; ?>
        <div class="avatar-overlay">
          <span class="material-symbols-outlined" style="font-size:22px">photo_camera</span>
          <span style="font-size:10px;font-weight:700">Trocar foto</span>
        </div>
        <input type="file" accept="image/jpeg,image/png,image/webp"
          onchange="uploadImagem(this,'perfil',{},function(url){
            let img=document.getElementById('img-perfil-ter');
            let ph=document.getElementById('img-perfil-ter-placeholder');
            if(!img){img=document.createElement('img');img.id='img-perfil-ter';img.className='w-20 h-20';img.style.cssText='border-radius:9999px;object-fit:cover;';this.closest('.avatar-upload').prepend(img);if(ph)ph.remove();}
            img.src=url;
          }.bind(this))"/>
      </div>
      <div>
        <h2 class="text-xl font-extrabold text-primary"><?= htmlspecialchars($perfil['nome']) ?></h2>
        <p class="text-sm text-on-surface-variant"><?= htmlspecialchars($perfil['especialidade']??'Terapeuta') ?> · <?= htmlspecialchars($perfil['periodo']??'') ?></p>
        <p class="text-[10px] text-on-surface-variant mt-1 opacity-70">Clique na foto para alterar · máx. 2MB</p>
      </div>
    </div>
    <!-- Campos -->
    <?php foreach ([
      ['E-mail','mail',$perfil['email']],
      ['Telefone','phone',$perfil['telefone']??'Não informado'],
      ['Especialidade','self_care',$perfil['especialidade']??'—'],
      ['Período / vínculo','badge',$perfil['periodo']??'—'],
      ['Membro desde','calendar_today',date('d/m/Y',strtotime($perfil['criado_em']))],
    ] as [$l,$ic,$v]): ?>
    <div class="flex items-center gap-4 bg-surface-container-low/60 rounded-2xl px-5 py-4 border border-outline-variant/15">
      <span class="material-symbols-outlined text-secondary text-lg shrink-0"><?= $ic ?></span>
      <div><p class="text-[10px] font-bold uppercase tracking-widest text-on-surface-variant"><?= $l ?></p>
      <p class="font-bold text-sm text-on-surface mt-0.5"><?= htmlspecialchars($v) ?></p></div>
    </div>
    <?php endforeach; ?>
    <div class="pt-2">
      <a href="?aba=painel" class="block w-full text-center py-3.5 rounded-full bg-gradient-to-r from-purple-700 to-pink-600 text-white font-bold text-sm hover:opacity-90 transition-all">Voltar ao painel</a>
    </div>
  </div>
</div>
<?php endif; ?>

</main>

<!-- ═══════════════════════════════════════════
     MODAIS (presentes em todas as abas)
═══════════════════════════════════════════ -->

<!-- Modal: Novo slot -->
<div class="modal-wrap fixed inset-0 z-[100] items-end sm:items-center justify-center p-0 sm:p-4" id="modal-novo-slot">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-novo-slot')"></div>
  <div class="glass modal-card relative z-10 w-full sm:max-w-lg rounded-t-[2rem] sm:rounded-[2rem] shadow-2xl overflow-hidden flex flex-col max-h-[90vh]">
    <div class="flex items-center justify-between px-6 pt-6 pb-3 shrink-0">
      <h2 class="text-lg font-extrabold text-primary">Novo horário de atendimento</h2>
      <button onclick="fecharModal('modal-novo-slot')" class="w-9 h-9 flex items-center justify-center rounded-full bg-surface-container-high hover:bg-surface-container-highest transition-colors"><span class="material-symbols-outlined text-base text-on-surface-variant">close</span></button>
    </div>
    <form id="form-novo-slot" class="overflow-y-auto px-6 pb-6 flex-1 space-y-4" onsubmit="salvarSlot(event)">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Dia da semana</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">calendar_today</span></span>
            <select name="dia_semana" id="novo-slot-dia" required>
              <option value="">Selecione</option>
              <?php foreach ($dias_full as $n=>$d): ?><option value="<?= $n ?>"><?= $d ?></option><?php endforeach; ?>
            </select></div>
        </div>
        <div>
          <label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Vagas</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">group</span></span>
            <input type="number" name="vagas_total" min="1" max="10" value="2"/></div>
        </div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Hora início</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">schedule</span></span>
            <input type="time" name="hora_inicio" id="novo-slot-hi" required/></div>
        </div>
        <div>
          <label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Hora fim</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">schedule</span></span>
            <input type="time" name="hora_fim" id="novo-slot-hf" required/></div>
        </div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Local / sala</label>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">location_on</span></span>
          <input type="text" name="local" placeholder="Ex: Sala 04 – Bloco A"/></div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase text-on-surface/60 mb-2">Práticas oferecidas</label>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($praticas_opcoes as $po): ?>
          <label class="pill-opt"><input type="checkbox" class="novo-slot-prat" value="<?= $po ?>"><?= $po ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="flex gap-4">
        <label class="flex items-center gap-2 text-sm font-medium cursor-pointer">
          <span class="tog"><input type="checkbox" name="aceita_interno" checked></span>🎓 Interno
        </label>
        <label class="flex items-center gap-2 text-sm font-medium cursor-pointer">
          <span class="tog"><input type="checkbox" name="aceita_externo" checked></span>🏙️ Externo
        </label>
      </div>
      <div id="slot-erro" class="hidden text-xs text-error font-medium flex items-center gap-1">
        <span class="material-symbols-outlined text-sm">error</span><span></span>
      </div>
      <button type="submit" class="w-full py-3.5 rounded-full bg-gradient-to-r from-purple-700 to-pink-600 text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all flex items-center justify-center gap-2">
        <span class="material-symbols-outlined text-sm">save</span>Criar horário
      </button>
    </form>
  </div>
</div>

<!-- Modal: Editar slot -->
<div class="modal-wrap fixed inset-0 z-[100] items-center justify-center p-4" id="modal-editar-slot">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-editar-slot')"></div>
  <div class="glass modal-card relative z-10 w-full max-w-md rounded-[2rem] shadow-2xl p-7">
    <h2 class="text-lg font-extrabold text-primary mb-5">Editar horário</h2>
    <input type="hidden" id="edit-slot-id"/>
    <div class="space-y-4">
      <div class="grid grid-cols-2 gap-3">
        <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Vagas</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">group</span></span>
            <input type="number" id="edit-vagas" min="1" max="10"/></div></div>
        <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Status</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">toggle_on</span></span>
            <select id="edit-ativo"><option value="1">Ativo</option><option value="0">Inativo</option></select></div></div>
      </div>
      <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Local</label>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">location_on</span></span>
          <input type="text" id="edit-local"/></div></div>
      <div>
        <label class="block text-xs font-bold uppercase text-on-surface/60 mb-2">Práticas</label>
        <div class="flex flex-wrap gap-2" id="edit-prat-wrap">
          <?php foreach ($praticas_opcoes as $po): ?>
          <label class="pill-opt"><input type="checkbox" class="edit-prat-cb" value="<?= $po ?>"><?= $po ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="flex gap-4">
        <label class="flex items-center gap-2 text-sm cursor-pointer"><span class="tog"><input type="checkbox" id="edit-interno" checked></span>Interno</label>
        <label class="flex items-center gap-2 text-sm cursor-pointer"><span class="tog"><input type="checkbox" id="edit-externo" checked></span>Externo</label>
      </div>
      <div class="flex gap-3 pt-1">
        <button onclick="salvarEdicaoSlot()" class="flex-grow py-3.5 rounded-full bg-primary text-white font-bold text-sm hover:opacity-90 transition-all">Salvar</button>
        <button onclick="excluirSlot()" class="px-5 py-3.5 rounded-full border-2 border-red-200 text-red-600 font-bold text-sm hover:bg-red-50 transition-all">Excluir</button>
        <button onclick="fecharModal('modal-editar-slot')" class="px-5 py-3.5 rounded-full border-2 border-outline-variant text-on-surface-variant font-bold text-sm hover:bg-surface-container-high transition-all">Cancelar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Novo plantão -->
<div class="modal-wrap fixed inset-0 z-[100] items-center justify-center p-4" id="modal-plantao-novo">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-plantao-novo')"></div>
  <div class="glass modal-card relative z-10 w-full max-w-md rounded-[2rem] shadow-2xl p-7">
    <h2 class="text-lg font-extrabold text-primary mb-5">Iniciar plantão</h2>
    <div class="space-y-4">
      <div class="grid grid-cols-2 gap-3">
        <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Hora início</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">schedule</span></span>
            <input type="time" id="plt-hi" value="<?= date('H:i') ?>"/></div></div>
        <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Hora fim</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">schedule</span></span>
            <input type="time" id="plt-hf" value="<?= date('H:i', strtotime('+2 hours')) ?>"/></div></div>
      </div>
      <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Local</label>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">location_on</span></span>
          <input type="text" id="plt-local" placeholder="Ex: Sala de espera – Bloco A"/></div></div>
      <div><label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Máx. de pacientes</label>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">group</span></span>
          <input type="number" id="plt-max" min="1" max="20" value="4"/></div></div>
      <div>
        <label class="block text-xs font-bold uppercase text-on-surface/60 mb-2">Práticas do plantão</label>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($praticas_opcoes as $po): ?>
          <label class="pill-opt"><input type="checkbox" class="plt-prat-check" value="<?= $po ?>"><?= $po ?></label>
          <?php endforeach; ?>
        </div>
      </div>
      <button onclick="abrirPlantao()" class="w-full py-3.5 rounded-full bg-emerald-600 text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all flex items-center justify-center gap-2">
        <span class="material-symbols-outlined text-sm">local_hospital</span>Abrir plantão
      </button>
    </div>
  </div>
</div>

<!-- Modal: Registrar atendimento no plantão -->
<div class="modal-wrap fixed inset-0 z-[100] items-end sm:items-center justify-center p-0 sm:p-4" id="modal-plantao-ativo">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-plantao-ativo')"></div>
  <div class="glass modal-card relative z-10 w-full sm:max-w-lg rounded-t-[2rem] sm:rounded-[2rem] shadow-2xl overflow-hidden flex flex-col max-h-[92vh]">
    <div class="flex items-center justify-between px-6 pt-6 pb-3 shrink-0">
      <div>
        <h2 class="text-lg font-extrabold text-primary">Registrar atendimento</h2>
        <p class="text-xs text-on-surface-variant" id="plt-ativo-sub">
          <?php if ($plantao_aberto): ?>Plantão · <?= (int)$plantao_aberto['total_atendidos'] ?>/<?= (int)$plantao_aberto['max_pacientes'] ?> atendidos<?php endif; ?>
        </p>
      </div>
      <button onclick="fecharModal('modal-plantao-ativo')" class="w-9 h-9 flex items-center justify-center rounded-full bg-surface-container-high hover:bg-surface-container-highest transition-colors"><span class="material-symbols-outlined text-base text-on-surface-variant">close</span></button>
    </div>
    <div class="overflow-y-auto px-6 pb-6 flex-1 space-y-4">
      <?php if (!$plantao_aberto): ?>
      <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-800 font-medium flex items-center gap-2">
        <span class="material-symbols-outlined text-amber-500">warning</span>
        Nenhum plantão aberto. <a href="#" onclick="fecharModal('modal-plantao-ativo');abrirModal('modal-plantao-novo')" class="underline font-bold">Iniciar plantão primeiro</a>
      </div>
      <?php else: ?>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Nome do paciente <span class="text-secondary font-normal normal-case tracking-normal">(obrigatório)</span></label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">person</span></span>
            <input type="text" id="sp-nome" placeholder="Nome completo"/></div>
        </div>
        <div>
          <label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">E-mail (se cadastrado)</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">mail</span></span>
            <input type="email" id="sp-email" placeholder="Para vincular ao histórico"/></div>
        </div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase text-on-surface/60 mb-2">Prática(s) realizada(s) <span class="text-secondary font-normal normal-case tracking-normal">(obrigatório — marque quantas quiser)</span></label>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($praticas_opcoes as $po): ?>
          <label class="pill-opt"><input type="checkbox" class="sp-prat-check" value="<?= $po ?>"><?= $po ?></label>
          <?php endforeach; ?>
        </div>
        <div id="sp-prat-erro" class="hidden text-xs text-error font-medium mt-1 flex items-center gap-1">
          <span class="material-symbols-outlined text-sm">error</span>Selecione ao menos uma prática.
        </div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Queixa principal</label>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">help</span></span>
          <textarea id="sp-queixa" rows="2" placeholder="Motivo do atendimento..."></textarea></div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Dor (0–10)</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">monitor_heart</span></span>
            <input type="number" id="sp-dor" min="0" max="10" placeholder="0–10"/></div>
        </div>
        <div>
          <label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Localização</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">location_on</span></span>
            <input type="text" id="sp-dor-loc" placeholder="Ex: lombar"/></div>
        </div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Alergias / medicamentos</label>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">medication</span></span>
          <input type="text" id="sp-alergias" placeholder="Alergias ou medicamentos em uso?"/></div>
      </div>
      <div>
        <label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Orientações dadas</label>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">assignment</span></span>
          <textarea id="sp-orient" rows="2" placeholder="O que foi orientado?"></textarea></div>
      </div>
      <div id="sp-erro" class="hidden text-xs text-error font-medium flex items-center gap-1">
        <span class="material-symbols-outlined text-sm">error</span><span id="sp-erro-msg"></span>
      </div>
      <button onclick="registrarAtendimento()" class="w-full py-3.5 rounded-full bg-emerald-600 text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all flex items-center justify-center gap-2">
        <span class="material-symbols-outlined text-sm">check_circle</span>Registrar atendimento
      </button>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal: Adiar -->
<div class="modal-wrap fixed inset-0 z-[100] items-center justify-center p-4" id="modal-adiar">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-adiar')"></div>
  <div class="glass modal-card relative z-10 w-full max-w-md rounded-[2rem] shadow-2xl p-8">
    <h2 class="text-xl font-extrabold text-primary mb-1">Adiar sessão</h2>
    <p id="adiar-sub" class="text-sm text-on-surface-variant mb-5"></p>
    <label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Motivo <span class="text-secondary font-normal normal-case">(visível ao paciente e coordenação)</span></label>
    <textarea id="adiar-motivo" rows="3" placeholder="Ex: Terapeuta ficará ausente. Entraremos em contato para reagendar."
      class="w-full rounded-2xl border border-outline-variant/30 bg-white/60 px-4 py-3 text-sm resize-none transition-all mb-5 focus:ring-2 focus:ring-primary focus:border-primary"></textarea>
    <div class="flex gap-3">
      <button id="btn-adiar-ok" onclick="confirmarAdiar()"
        class="flex-grow py-4 rounded-full bg-amber-500 text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all flex items-center justify-center gap-2">
        <span class="material-symbols-outlined text-sm">event_busy</span>Confirmar adiamento
      </button>
      <button onclick="fecharModal('modal-adiar')" class="px-6 py-4 rounded-full border-2 border-outline-variant text-on-surface-variant font-bold text-sm hover:bg-surface-container-high transition-all">Cancelar</button>
    </div>
  </div>
</div>

<!-- Modal: Faltou -->
<div class="modal-wrap fixed inset-0 z-[100] items-center justify-center p-4" id="modal-faltou">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-faltou')"></div>
  <div class="glass modal-card relative z-10 w-full max-w-md rounded-[2rem] shadow-2xl p-8">
    <div id="falta-aviso-2" class="hidden mb-4 bg-red-50 border border-red-200 rounded-xl px-4 py-3 flex items-start gap-3">
      <span class="material-symbols-outlined text-red-500 shrink-0">warning</span>
      <div><p class="font-bold text-red-800 text-sm">Segunda falta — paciente bloqueado por 30 dias</p>
      <p class="text-red-700 text-sm mt-0.5">Regra automática do sistema. O paciente receberá notificação explicando a política do NUPICS.</p></div>
    </div>
    <h2 class="text-xl font-extrabold text-primary mb-1">Registrar falta</h2>
    <p id="faltou-sub" class="text-sm text-on-surface-variant mb-5"></p>
    <label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5">Justificativa (se houver)</label>
    <textarea id="faltou-just" rows="2" placeholder="Informe caso o paciente tenha justificado..."
      class="w-full rounded-2xl border border-outline-variant/30 bg-white/60 px-4 py-3 text-sm resize-none transition-all mb-5 focus:ring-2 focus:ring-primary focus:border-primary"></textarea>
    <div class="flex gap-3">
      <button id="btn-faltou-ok" onclick="confirmarFaltou()"
        class="flex-grow py-4 rounded-full bg-red-500 text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all flex items-center justify-center gap-2">
        <span class="material-symbols-outlined text-sm">person_off</span>Confirmar falta
      </button>
      <button onclick="fecharModal('modal-faltou')" class="px-6 py-4 rounded-full border-2 border-outline-variant text-on-surface-variant font-bold text-sm hover:bg-surface-container-high transition-all">Cancelar</button>
    </div>
  </div>
</div>

<!-- Modal: Histórico do paciente -->
<div class="modal-wrap fixed inset-0 z-[101] items-end sm:items-center justify-center p-0 sm:p-4" id="modal-hist-pac">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-hist-pac')"></div>
  <div class="glass modal-card relative z-10 w-full sm:max-w-xl rounded-t-[2rem] sm:rounded-[2rem] shadow-2xl flex flex-col max-h-[90vh] overflow-hidden">
    <div class="flex items-center justify-between px-6 pt-6 pb-3 shrink-0">
      <h2 id="hist-pac-titulo" class="text-lg font-extrabold text-primary">Histórico do paciente</h2>
      <button onclick="fecharModal('modal-hist-pac')" class="w-9 h-9 flex items-center justify-center rounded-full bg-surface-container-high hover:bg-surface-container-highest transition-colors"><span class="material-symbols-outlined text-base text-on-surface-variant">close</span></button>
    </div>
    <div id="hist-pac-body" class="overflow-y-auto px-6 pb-6 flex-1 space-y-3">
      <div class="text-center py-8 text-on-surface-variant text-sm">Carregando...</div>
    </div>
  </div>
</div>

<!-- Modal: Playlist -->
<div class="modal-wrap fixed inset-0 z-[101] items-center justify-center p-4" id="modal-playlist">
  <div class="absolute inset-0 bg-primary/25 backdrop-blur-sm" onclick="fecharModalPlaylist()"></div>
  <div class="glass modal-card relative z-10 w-full max-w-2xl rounded-[2rem] shadow-2xl overflow-hidden">
    <div class="flex items-center justify-between px-6 pt-5 pb-3">
      <h2 id="playlist-titulo" class="text-base font-extrabold text-primary"></h2>
      <button onclick="fecharModalPlaylist()" class="w-9 h-9 flex items-center justify-center rounded-full bg-surface-container-high hover:bg-surface-container-highest transition-colors"><span class="material-symbols-outlined text-base text-on-surface-variant">close</span></button>
    </div>
    <div class="aspect-video"><iframe id="playlist-frame" width="100%" height="100%" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen class="w-full h-full"></iframe></div>
    <p class="px-6 py-3 text-xs text-on-surface-variant">Feche para pausar.</p>
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
const PLANTAO_ID = <?= $plantao_aberto ? (int)$plantao_aberto['id'] : 'null' ?>;

// ── Helpers ───────────────────────────────────────────────────────────────────
function abrirModal(id)  { document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function fecharModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
function fecharModalPlaylist() { document.getElementById('playlist-frame').src=''; fecharModal('modal-playlist'); }
function toast(msg, icon='check_circle', cor='text-emerald-600') {
  const t=document.getElementById('toast');
  document.getElementById('toast-msg').textContent=msg;
  const ic=document.getElementById('toast-icon'); ic.textContent=icon; ic.className='material-symbols-outlined text-base '+cor;
  t.classList.remove('hidden'); setTimeout(()=>t.classList.add('hidden'),3500);
}
async function api(url, dados) {
  const f=new FormData(); Object.entries(dados).forEach(([k,v])=>{ if(v!=null) f.append(k,v); });
  const r=await fetch(url,{method:'POST',body:f}); return r.json();
}

// ── Playlist ──────────────────────────────────────────────────────────────────
function abrirPlaylist(ytId, nome) {
  document.getElementById('playlist-titulo').textContent=nome;
  document.getElementById('playlist-frame').src=`https://www.youtube.com/embed/${ytId}?autoplay=1`;
  abrirModal('modal-playlist');
}

// ── Cronograma: preenche novo slot com dia/hora do clique na grade ─────────────
function preencherNovoSlot(dia, hora) {
  const sel = document.getElementById('novo-slot-dia');
  if (sel) sel.value = dia;
  const hi = document.getElementById('novo-slot-hi');
  if (hi) hi.value = hora;
}
function preencherEditSlot(slot) { abrirEditarSlot(slot); }
function abrirEditarSlot(slot) {
  document.getElementById('edit-slot-id').value = slot.id;
  document.getElementById('edit-vagas').value   = slot.vagas_total;
  document.getElementById('edit-ativo').value   = slot.ativo;
  document.getElementById('edit-local').value   = slot.local||'';
  // Práticas checkboxes
  const prats = (slot.praticas||'').split(',').map(s=>s.trim());
  document.querySelectorAll('.edit-prat-cb').forEach(cb => { cb.checked = prats.includes(cb.value); });
  document.getElementById('edit-interno').checked = !!+slot.aceita_interno;
  document.getElementById('edit-externo').checked = !!+slot.aceita_externo;
}
async function salvarSlot(e) {
  e.preventDefault();
  const form = document.getElementById('form-novo-slot');
  const fd = new FormData(form);
  const prats = [...document.querySelectorAll('.novo-slot-prat:checked')].map(c=>c.value);
  fd.set('praticas', prats.join(', '));
  fd.set('acao', 'criar_slot');
  const r = await fetch('../api/agenda_action.php',{method:'POST',body:fd});
  const res = await r.json();
  if (res.ok) { toast('Horário criado!','schedule','text-emerald-600'); fecharModal('modal-novo-slot'); setTimeout(()=>location.reload(),900); }
  else { const e=document.getElementById('slot-erro'); e.classList.remove('hidden'); e.querySelector('span:last-child').textContent=res.msg; }
}
async function salvarEdicaoSlot() {
  const prats = [...document.querySelectorAll('.edit-prat-cb:checked')].map(c=>c.value);
  const dados = {
    acao:'editar_slot', slot_id:document.getElementById('edit-slot-id').value,
    vagas_total:document.getElementById('edit-vagas').value,
    ativo:document.getElementById('edit-ativo').value,
    local:document.getElementById('edit-local').value,
    praticas:prats.join(', '),
  };
  if (document.getElementById('edit-interno').checked) dados.aceita_interno='1';
  if (document.getElementById('edit-externo').checked) dados.aceita_externo='1';
  const res = await api('../api/agenda_action.php', dados);
  if (res.ok) { toast('Atualizado!','check_circle','text-emerald-600'); fecharModal('modal-editar-slot'); setTimeout(()=>location.reload(),900); }
  else toast(res.msg||'Erro.','error','text-red-500');
}
async function excluirSlot() {
  const sid = document.getElementById('edit-slot-id').value;
  if (!confirm('Remover este horário?')) return;
  const res = await api('../api/agenda_action.php', {acao:'excluir_slot', slot_id:sid});
  if (res.ok) { toast('Removido.','delete','text-red-500'); fecharModal('modal-editar-slot'); setTimeout(()=>location.reload(),900); }
  else toast(res.msg||'Erro.','error','text-red-500');
}

// ── Plantão ───────────────────────────────────────────────────────────────────
async function abrirPlantao() {
  const hi = document.getElementById('plt-hi').value;
  const hf = document.getElementById('plt-hf').value;
  if (!hi || !hf) { toast('Informe os horários do plantão.','error','text-red-500'); return; }
  if (hi >= hf)   { toast('A hora de fim deve ser depois do início.','error','text-red-500'); return; }
  const btn = document.querySelector('#modal-plantao-novo button[onclick="abrirPlantao()"]');
  if (btn) { btn.disabled=true; btn.textContent='Abrindo...'; }
  const prats = [...document.querySelectorAll('.plt-prat-check:checked')].map(c=>c.value).join(', ');
  try {
    const res = await api('../api/agenda_action.php', {
      acao:'abrir_plantao',
      hora_inicio:   hi,
      hora_fim:      hf,
      local:         document.getElementById('plt-local').value,
      max_pacientes: document.getElementById('plt-max').value,
      praticas:      prats,
    });
    if (res.ok) {
      toast('Plantão aberto com sucesso!','local_hospital','text-emerald-600');
      fecharModal('modal-plantao-novo');
      setTimeout(()=>location.reload(), 900);
    } else {
      toast(res.msg||'Erro ao abrir plantão.','error','text-red-500');
      if (btn) { btn.disabled=false; btn.textContent='Abrir plantão'; }
    }
  } catch(e) {
    toast('Erro de conexão. Verifique o servidor.','error','text-red-500');
    if (btn) { btn.disabled=false; btn.textContent='Abrir plantão'; }
  }
}

async function registrarAtendimento() {
  const nome = document.getElementById('sp-nome').value.trim();
  const prats = [...document.querySelectorAll('.sp-prat-check:checked')].map(c=>c.value);
  document.getElementById('sp-erro').classList.add('hidden');
  document.getElementById('sp-prat-erro').classList.add('hidden');

  if (!nome) { document.getElementById('sp-erro-msg').textContent='Informe o nome do paciente.'; document.getElementById('sp-erro').classList.remove('hidden'); return; }
  if (!prats.length) { document.getElementById('sp-prat-erro').classList.remove('hidden'); return; }
  if (!PLANTAO_ID) { document.getElementById('sp-erro-msg').textContent='Nenhum plantão aberto. Inicie um plantão primeiro.'; document.getElementById('sp-erro').classList.remove('hidden'); return; }

  const form = new FormData();
  form.append('acao',           'registrar_plantao');
  form.append('plantao_id',     PLANTAO_ID);
  form.append('paciente_nome',  nome);
  form.append('email_paciente', document.getElementById('sp-email').value);
  form.append('tipo_pratica',   prats.join(', '));
  form.append('queixa',         document.getElementById('sp-queixa').value);
  form.append('dor_intensidade',document.getElementById('sp-dor').value);
  form.append('dor_localizacao',document.getElementById('sp-dor-loc').value);
  form.append('alergias_medicamentos', document.getElementById('sp-alergias').value);
  form.append('orientacoes',    document.getElementById('sp-orient').value);

  try {
    const r = await fetch('../api/agenda_action.php',{method:'POST',body:form});
    const res = await r.json();
    if (res.ok) {
      toast(`Atendimento registrado!`,'check_circle','text-emerald-600');
      ['sp-nome','sp-email','sp-queixa','sp-dor','sp-dor-loc','sp-alergias','sp-orient'].forEach(id=>{
        const el=document.getElementById(id); if(el) el.value='';
      });
      document.querySelectorAll('.sp-prat-check').forEach(c=>c.checked=false);
      setTimeout(()=>location.reload(),1500);
    } else {
      document.getElementById('sp-erro-msg').textContent=res.msg||'Erro ao registrar.';
      document.getElementById('sp-erro').classList.remove('hidden');
    }
  } catch {
    document.getElementById('sp-erro-msg').textContent='Erro de conexão. Verifique o servidor.';
    document.getElementById('sp-erro').classList.remove('hidden');
  }
}

async function encerrarPlantao(pid) {
  if (!confirm('Encerrar o plantão de hoje? Esta ação não pode ser desfeita.')) return;
  try {
    const res = await api('../api/agenda_action.php', {acao:'encerrar_plantao', plantao_id:pid});
    if (res.ok) { toast('Plantão encerrado.','stop_circle','text-indigo-600'); setTimeout(()=>location.reload(),900); }
    else toast(res.msg||'Erro ao encerrar.','error','text-red-500');
  } catch(e) { toast('Erro de conexão.','error','text-red-500'); }
}

// ── Reservas ──────────────────────────────────────────────────────────────────
async function confirmarRes(rid, btn) {
  btn.disabled=true; btn.textContent='...';
  const f=new FormData(); f.append('acao','confirmar_reserva'); f.append('reserva_id',rid);
  const d=await fetch('../api/ciclo_action.php',{method:'POST',body:f}).then(r=>r.json());
  if (d.ok) { toast('Confirmado! Ciclo criado.','check_circle','text-emerald-600'); setTimeout(()=>location.reload(),1000); }
  else { toast(d.msg||'Erro.','error','text-red-500'); btn.disabled=false; btn.textContent='Confirmar'; }
}
async function recusarRes(rid, btn) {
  btn.disabled=true; btn.textContent='...';
  const f=new FormData(); f.append('acao','cancelar'); f.append('reserva_id',rid);
  const d=await fetch('../api/reserva_action.php',{method:'POST',body:f}).then(r=>r.json());
  if (d.ok) { toast('Recusado.','cancel','text-red-500'); setTimeout(()=>location.reload(),1000); }
  else { toast(d.msg||'Erro.','error','text-red-500'); btn.disabled=false; btn.textContent='Recusar'; }
}

// ── Ciclos ────────────────────────────────────────────────────────────────────
let _adiarCiclo=null, _adiarSessao=null;
function abrirAdiar(cid, snum, pnome) {
  _adiarCiclo=cid; _adiarSessao=snum;
  document.getElementById('adiar-sub').textContent=`Paciente: ${pnome} · Sessão ${snum}`;
  document.getElementById('adiar-motivo').value='';
  abrirModal('modal-adiar');
}
async function confirmarAdiar() {
  const motivo=document.getElementById('adiar-motivo').value.trim();
  if (!motivo) { toast('Informe o motivo.','error','text-red-500'); return; }
  document.getElementById('btn-adiar-ok').disabled=true;
  const f=new FormData(); f.append('acao','adiar'); f.append('ciclo_id',_adiarCiclo); f.append('sessao_num',_adiarSessao); f.append('motivo',motivo);
  const d=await fetch('../api/ciclo_action.php',{method:'POST',body:f}).then(r=>r.json());
  fecharModal('modal-adiar');
  if (d.ok) { toast('Adiamento notificado.','event_busy','text-amber-600'); setTimeout(()=>location.reload(),1200); }
  else toast(d.msg||'Erro.','error','text-red-500');
  document.getElementById('btn-adiar-ok').disabled=false;
}

let _faltaCiclo=null, _faltaSessao=null;
function abrirFaltou(cid, snum, faltas, pnome) {
  _faltaCiclo=cid; _faltaSessao=snum;
  document.getElementById('faltou-sub').textContent=`Paciente: ${pnome} · Sessão ${snum} · Faltas: ${faltas}`;
  document.getElementById('faltou-just').value='';
  document.getElementById('falta-aviso-2').classList.toggle('hidden', faltas<1);
  abrirModal('modal-faltou');
}
async function confirmarFaltou() {
  const just=document.getElementById('faltou-just').value.trim();
  document.getElementById('btn-faltou-ok').disabled=true;
  const f=new FormData(); f.append('acao','faltou'); f.append('ciclo_id',_faltaCiclo); f.append('sessao_num',_faltaSessao); f.append('justificativa',just);
  const d=await fetch('../api/ciclo_action.php',{method:'POST',body:f}).then(r=>r.json());
  fecharModal('modal-faltou');
  if (d.ok) {
    if (d.acao==='bloqueado') toast('2ª falta! Paciente bloqueado 30 dias.','block','text-red-600');
    else toast(`Falta ${d.faltas} registrada.`,'person_off','text-amber-600');
    setTimeout(()=>location.reload(),1200);
  } else toast(d.msg||'Erro.','error','text-red-500');
  document.getElementById('btn-faltou-ok').disabled=false;
}

// ── Busca pacientes / terapeutas ──────────────────────────────────────────────
function filtrarPacientes() {
  const q = document.getElementById('busca-paciente').value.toLowerCase();
  document.querySelectorAll('.pac-item').forEach(el => {
    const match = el.dataset.nome.includes(q) || el.dataset.email.includes(q);
    el.classList.toggle('hidden', !match);
  });
}
function filtrarTerapeutas() {
  const q = document.getElementById('busca-terapeuta')?.value.toLowerCase()||'';
  document.querySelectorAll('.ter-item').forEach(el => {
    const match = el.dataset.nome.includes(q) || el.dataset.email.includes(q);
    el.classList.toggle('hidden', !match);
  });
}

// ── Detalhe de slot de outro terapeuta ───────────────────────────────────────
function abrirDetalheSlot(dados) {
  const macaInfo = dados.sobrep >= 3
    ? `<div class="flex items-center gap-2 bg-red-50 border border-red-200 rounded-xl px-3 py-2.5">
         <span class="material-symbols-outlined text-red-500 text-base">warning</span>
         <div><p class="text-xs font-bold text-red-800">Conflito de macas!</p>
         <p class="text-xs text-red-700">${dados.sobrep} terapeutas neste horário — sem macas disponíveis.</p></div></div>`
    : dados.sobrep == 2
    ? `<div class="flex items-center gap-2 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2.5">
         <span class="material-symbols-outlined text-amber-500 text-base">info</span>
         <div><p class="text-xs font-bold text-amber-800">Macas cheias</p>
         <p class="text-xs text-amber-700">As 2 macas estão ocupadas neste horário. Converse com a coordenação se precisar de uma.</p></div></div>`
    : '';

  const sobrepostosHtml = dados.nomes && dados.nomes.length
    ? `<div><p class="text-[10px] font-bold uppercase text-on-surface-variant mb-1">Terapeutas nesse horário</p>
       <p class="text-sm font-medium text-on-surface">${dados.nomes.join(', ')}</p></div>` : '';

  document.getElementById('detalhe-slot-body').innerHTML = `
    ${macaInfo}
    <div><p class="text-[10px] font-bold uppercase text-on-surface-variant mb-0.5">Terapeuta</p>
    <p class="font-bold text-on-surface">${dados.ter}</p></div>
    <div><p class="text-[10px] font-bold uppercase text-on-surface-variant mb-0.5">Horário</p>
    <p class="text-on-surface">${dados.hi} – ${dados.hf}</p></div>
    ${dados.praticas ? `<div><p class="text-[10px] font-bold uppercase text-on-surface-variant mb-0.5">Práticas</p>
    <p class="text-on-surface">${dados.praticas}</p></div>` : ''}
    <div><p class="text-[10px] font-bold uppercase text-on-surface-variant mb-0.5">Vagas</p>
    <p class="text-on-surface">${dados.vagas_ocu} de ${dados.vagas_tot} ocupadas</p></div>
    ${sobrepostosHtml}
  `;
  abrirModal('modal-detalhe-slot');
}

// ── Histórico do paciente ─────────────────────────────────────────────────────
async function abrirHistPaciente(pacUid, pnome) {
  document.getElementById('hist-pac-titulo').textContent = 'Histórico — ' + pnome;
  document.getElementById('hist-pac-body').innerHTML = '<div class="text-center py-8 text-on-surface-variant text-sm">Carregando...</div>';
  abrirModal('modal-hist-pac');
  try {
    const d = await fetch(`../api/historico_paciente.php?pac_uid=${pacUid}`).then(r=>r.json());
    const body = document.getElementById('hist-pac-body');
    if (!d.ok || !d.ciclos.length) { body.innerHTML='<p class="text-sm text-on-surface-variant text-center py-8">Nenhum histórico anterior.</p>'; return; }
    body.innerHTML = d.ciclos.map(c=>`
      <div class="bg-surface-container-low rounded-2xl p-4 border border-outline-variant/20">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-bold text-primary uppercase tracking-widest">${c.status==='concluido'?'✅ Concluído':'❌ Cancelado'} · ${c.sessoes_realizadas} sessões</span>
          <span class="text-xs text-outline">${c.periodo}</span>
        </div>
        ${c.anamnese?`<p class="text-xs text-on-surface-variant mb-1"><strong>Queixa:</strong> ${c.anamnese}</p>`:''}
        ${c.praticas?`<p class="text-xs text-on-surface-variant"><strong>Práticas:</strong> ${c.praticas}</p>`:''}
        ${c.terapeuta?`<p class="text-xs text-on-surface-variant mt-1"><strong>Terapeuta:</strong> ${c.terapeuta}</p>`:''}
      </div>
    `).join('');
  } catch { document.getElementById('hist-pac-body').innerHTML='<p class="text-sm text-error text-center py-8">Erro ao carregar.</p>'; }
}
</script>
<?php include '../includes/upload_component.php'; ?>
</body>
</html>