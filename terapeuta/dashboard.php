<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'terapeuta') {
    header('Location: ../index.php'); exit;
}
require_once '../config/db.php';

$uid  = (int)$_SESSION['usuario_id'];
$nome = $_SESSION['nome'];
$pri  = explode(' ', trim($nome))[0];

// ── Reservas pendentes ──────────────────────────────────────────────────────
$pend_stmt = $pdo->prepare("
    SELECT r.id, r.queixas, r.telefone_contato, r.data_sessao, r.criado_em,
           u.nome AS pnome, s.hora_inicio, s.hora_fim, s.dia_semana, s.local, s.praticas
    FROM reservas r JOIN slots s ON r.slot_id=s.id JOIN usuarios u ON r.paciente_id=u.id
    WHERE s.terapeuta_id=? AND r.status='pendente'
    ORDER BY r.data_sessao, s.hora_inicio
");
$pend_stmt->execute([$uid]); $pendentes = $pend_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Ciclos ativos ───────────────────────────────────────────────────────────
$ciclos_stmt = $pdo->prepare("
    SELECT
        c.id AS ciclo_id,
        c.total_sessoes, c.sessoes_realizadas, c.faltas, c.status AS ciclo_status,
        r.id AS reserva_id, r.paciente_id AS pac_uid,
        r.data_sessao, r.queixas AS queixas_ini,
        u.nome AS pnome, u.telefone,
        p.id AS pac_pid,
        s.hora_inicio, s.hora_fim, s.dia_semana, s.local, s.praticas,
        (SELECT COUNT(*) FROM anamnese_inicial ai WHERE ai.ciclo_id=c.id) AS tem_anamnese,
        (SELECT COUNT(*) FROM registros_sessao rs WHERE rs.ciclo_id=c.id AND rs.status='realizado') AS followups_ok,
        (SELECT rs2.justificativa FROM registros_sessao rs2 WHERE rs2.ciclo_id=c.id AND rs2.status='adiado' ORDER BY rs2.criado_em DESC LIMIT 1) AS ultimo_adiamento
    FROM ciclos c
    JOIN reservas r ON c.reserva_id=r.id
    JOIN usuarios u ON r.paciente_id=u.id
    JOIN pacientes p ON p.usuario_id=u.id
    JOIN slots s ON r.slot_id=s.id
    WHERE s.terapeuta_id=? AND c.status='ativo'
    ORDER BY s.dia_semana, s.hora_inicio
");
$ciclos_stmt->execute([$uid]); $ciclos = $ciclos_stmt->fetchAll(PDO::FETCH_ASSOC);

// Adiciona calculo de proxima_sessao e progresso a cada ciclo
foreach ($ciclos as &$cic) {
    $cic['proxima_sessao'] = ($cic['tem_anamnese'] == 0) ? 1
        : ((int)$cic['tem_anamnese'] + (int)$cic['followups_ok'] + 1);
    $cic['sessoes_feitas'] = (int)$cic['tem_anamnese'] + (int)$cic['followups_ok'];
}
unset($cic);

// ── Fila de espera ──────────────────────────────────────────────────────────
$fila_stmt = $pdo->prepare("
    SELECT fe.id, fe.queixas, fe.telefone_contato, fe.posicao, fe.data_sessao, fe.status,
           u.nome AS pnome, s.hora_inicio, s.hora_fim, s.dia_semana, s.local, s.praticas
    FROM fila_espera fe JOIN slots s ON fe.slot_id=s.id JOIN usuarios u ON fe.paciente_id=u.id
    WHERE s.terapeuta_id=? AND fe.status='aguardando'
    ORDER BY fe.posicao
");
$fila_stmt->execute([$uid]); $fila = $fila_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Ciclos concluídos recentes ──────────────────────────────────────────────
$conc_stmt = $pdo->prepare("
    SELECT c.id AS ciclo_id, c.sessoes_realizadas, c.encerrado_em,
           u.nome AS pnome, s.praticas
    FROM ciclos c JOIN reservas r ON c.reserva_id=r.id
    JOIN usuarios u ON r.paciente_id=u.id JOIN slots s ON r.slot_id=s.id
    WHERE s.terapeuta_id=? AND c.status IN ('concluido','cancelado')
    ORDER BY c.encerrado_em DESC LIMIT 10
");
$conc_stmt->execute([$uid]); $concluidos = $conc_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Stats ───────────────────────────────────────────────────────────────────
$qtd_ciclos   = count($ciclos);
$qtd_pendentes= count($pendentes);
$qtd_fila     = count($fila);
$qtd_conc_mes = count(array_filter($concluidos, fn($c) => $c['encerrado_em'] && substr($c['encerrado_em'],0,7) === date('Y-m')));

$dias_nomes = [1=>'Segunda',2=>'Terça',3=>'Quarta',4=>'Quinta',5=>'Sexta'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>NUPICS | Painel do Terapeuta</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script>tailwind.config={darkMode:"class",theme:{extend:{colors:{
  "surface":"#fff7fc","on-surface":"#201923","outline-variant":"#d0c2d3",
  "surface-container-low":"#fdeffe","surface-container":"#f7eaf8",
  "surface-container-high":"#f2e4f2","surface-container-highest":"#ecdeed",
  "on-surface-variant":"#4d4351","outline":"#7f7383",
  "primary":"#4e0078","on-primary":"#ffffff","secondary":"#b7004d",
  "error":"#ba1a1a","error-container":"#ffdad6","on-error-container":"#93000a",
  "background":"#fff7fc","on-background":"#201923"
},fontFamily:{"headline":["Plus Jakarta Sans"],"body":["Manrope"]}}}}</script>
<style>
  body{font-family:"Manrope",sans-serif}
  h1,h2,h3,h4{font-family:"Plus Jakarta Sans",sans-serif}
  .material-symbols-outlined{font-variation-settings:"FILL" 0,"wght" 400,"GRAD" 0,"opsz" 24}
  .glass{background:rgba(255,255,255,.82);backdrop-filter:blur(18px) saturate(180%);
         -webkit-backdrop-filter:blur(18px) saturate(180%);border:1px solid rgba(255,255,255,.4)}
  .modal-wrap{display:none}.modal-wrap.open{display:flex;animation:mfade .18s ease}
  @keyframes mfade{from{opacity:0}to{opacity:1}}
  .modal-card{animation:mup .22s cubic-bezier(.22,1,.36,1)}
  @keyframes mup{from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:translateY(0)}}
  .card-item{transition:box-shadow .15s,transform .15s}
  .card-item:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(78,0,120,.10)}
  .tab-btn{border-bottom:2px solid transparent;transition:color .15s,border-color .15s}
  .tab-btn.active{color:#4e0078;border-bottom-color:#4e0078}
  textarea:focus,input:focus,select:focus{outline:none;box-shadow:0 0 0 3px rgba(78,0,120,.15)}
  /* Barra de progresso do ciclo */
  .prog-dot{width:1.6rem;height:1.6rem;border-radius:50%;display:flex;align-items:center;
            justify-content:center;font-size:.65rem;font-weight:700;transition:.3s}
  .prog-dot.done{background:#4e0078;color:#fff}
  .prog-dot.curr{background:#4e0078;color:#fff;box-shadow:0 0 0 3px rgba(78,0,120,.2)}
  .prog-dot.idle{background:#ecdeed;color:#7f7383}
  .prog-line{flex:1;height:2px;border-radius:1px}
  .prog-line.done{background:#4e0078}.prog-line.idle{background:#ecdeed}
  /* Falta badge */
  .falta-badge{display:inline-flex;align-items:center;gap:3px;font-size:.65rem;font-weight:700;
               padding:2px 7px;border-radius:99px}
</style>
</head>
<body class="bg-surface text-on-background min-h-screen flex flex-col">

<!-- Nav -->
<nav class="fixed top-0 w-full z-50 bg-white/60 backdrop-blur-md shadow-[0_4px_24px_rgba(32,25,35,.06)]">
  <div class="flex justify-between items-center px-6 md:px-10 py-4 max-w-7xl mx-auto">
    <div class="flex items-center gap-3">
      <span class="text-xl font-bold bg-gradient-to-r from-purple-700 to-pink-600 bg-clip-text text-transparent font-['Plus_Jakarta_Sans']">NUPICS</span>
      <span class="hidden sm:inline text-xs font-bold uppercase tracking-widest text-outline px-2 py-0.5 rounded-full border border-outline-variant">Terapeuta</span>
    </div>
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-full bg-primary/10 flex items-center justify-center">
        <span class="material-symbols-outlined text-primary text-lg">person</span>
      </div>
      <span class="hidden md:block text-sm font-semibold text-on-surface"><?= htmlspecialchars($nome) ?></span>
      <a href="../logout.php" class="text-sm font-semibold text-on-surface-variant hover:text-secondary transition-colors">Sair</a>
    </div>
  </div>
</nav>

<main class="flex-grow pt-24 pb-20">
  <div class="max-w-7xl mx-auto px-4 md:px-8">

    <div class="mb-8">
      <h1 class="text-3xl md:text-4xl font-extrabold text-primary tracking-tight mb-1">Olá, <?= htmlspecialchars($pri) ?>!</h1>
      <p class="text-on-surface-variant text-sm">Gerencie seus ciclos, sessões e plantões.</p>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-10">
      <div class="bg-primary/5 border border-primary/10 rounded-2xl p-5">
        <p class="text-xs font-bold uppercase tracking-widest text-primary/60 mb-1">Ciclos ativos</p>
        <p class="text-3xl font-extrabold text-primary"><?= $qtd_ciclos ?></p>
      </div>
      <div class="bg-amber-50 border border-amber-100 rounded-2xl p-5">
        <p class="text-xs font-bold uppercase tracking-widest text-amber-700/60 mb-1">Pendentes</p>
        <p class="text-3xl font-extrabold text-amber-700"><?= $qtd_pendentes ?></p>
      </div>
      <div class="bg-yellow-50 border border-yellow-100 rounded-2xl p-5">
        <p class="text-xs font-bold uppercase tracking-widest text-yellow-700/60 mb-1">Fila de espera</p>
        <p class="text-3xl font-extrabold text-yellow-700"><?= $qtd_fila ?></p>
      </div>
      <div class="bg-indigo-50 border border-indigo-100 rounded-2xl p-5">
        <p class="text-xs font-bold uppercase tracking-widest text-indigo-700/60 mb-1">Concluídos/mês</p>
        <p class="text-3xl font-extrabold text-indigo-700"><?= $qtd_conc_mes ?></p>
      </div>
    </div>

    <!-- Tabs -->
    <div class="flex gap-5 border-b border-outline-variant mb-7 overflow-x-auto">
      <?php
      $tabs = ['ciclos'=>"Ciclos ({$qtd_ciclos})","pendentes"=>"Pendentes ({$qtd_pendentes})",
               'fila'=>"Fila ({$qtd_fila})",'concluidos'=>'Histórico'];
      foreach ($tabs as $k=>$l):
      ?>
      <button class="tab-btn pb-3 text-sm font-bold text-on-surface-variant whitespace-nowrap <?= $k==='ciclos'?'active':'' ?>"
              data-tab="<?= $k ?>"><?= $l ?></button>
      <?php endforeach; ?>
    </div>

    <!-- ══ TAB: CICLOS ATIVOS ══ -->
    <div id="tab-ciclos" class="tab-content grid gap-4">
      <?php if (empty($ciclos)): ?>
      <div class="text-center py-14 text-on-surface-variant">
        <span class="material-symbols-outlined text-5xl mb-3 block">event_repeat</span>
        <p class="text-sm">Nenhum ciclo ativo no momento.</p>
      </div>
      <?php else: foreach ($ciclos as $cic):
        $prox = (int)$cic['proxima_sessao'];
        $total = (int)$cic['total_sessoes'];
        $feitas = (int)$cic['sessoes_feitas'];
        $faltas = (int)$cic['faltas'];
        $dia = $dias_nomes[(int)$cic['dia_semana']] ?? '?';
        $hora = substr($cic['hora_inicio'],0,5).'–'.substr($cic['hora_fim'],0,5);
      ?>
      <div class="card-item glass rounded-2xl p-5 border border-outline-variant/20">
        <div class="flex flex-col md:flex-row md:items-start gap-4">

          <!-- Avatar + nome -->
          <div class="flex items-center gap-3 shrink-0">
            <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center">
              <span class="material-symbols-outlined text-primary">person</span>
            </div>
            <div>
              <h3 class="font-headline font-bold text-on-surface"><?= htmlspecialchars($cic['pnome']) ?></h3>
              <p class="text-xs text-on-surface-variant"><?= $dia ?> · <?= $hora ?> · <?= htmlspecialchars($cic['local'] ?? '—') ?></p>
              <?php if ($cic['telefone']): ?>
              <p class="text-xs text-on-surface-variant flex items-center gap-1 mt-0.5">
                <span class="material-symbols-outlined text-xs">phone</span><?= htmlspecialchars($cic['telefone']) ?>
              </p>
              <?php endif; ?>
            </div>
          </div>

          <!-- Progresso -->
          <div class="flex-grow">
            <div class="flex items-center gap-1.5 mb-3">
              <?php for ($i=1; $i<=$total; $i++):
                $done = $i <= $feitas;
                $curr = $i === $prox;
              ?>
              <div class="prog-dot <?= $done?'done':($curr?'curr':'idle') ?>" title="Sessão <?= $i ?>">
                <?= $done ? '✓' : $i ?>
              </div>
              <?php if ($i < $total): ?>
              <div class="prog-line <?= $done?'done':'idle' ?>"></div>
              <?php endif; ?>
              <?php endfor; ?>
              <span class="ml-2 text-xs text-on-surface-variant font-medium"><?= $feitas ?>/<?= $total ?> sessões</span>
            </div>

            <!-- Faltas -->
            <?php if ($faltas > 0): ?>
            <div class="flex items-center gap-1.5 mb-3">
              <span class="falta-badge <?= $faltas>=2 ? 'bg-red-100 text-red-700':'bg-amber-100 text-amber-700' ?>">
                <span class="material-symbols-outlined" style="font-size:12px">warning</span>
                <?= $faltas ?> falta<?= $faltas>1?'s':'' ?>
                <?= $faltas>=2 ? '— próxima falta = BLOQUEIO' : '— mais 1 falta resulta em bloqueio' ?>
              </span>
            </div>
            <?php endif; ?>

            <!-- Adiamento recente -->
            <?php if ($cic['ultimo_adiamento']): ?>
            <div class="text-xs text-amber-700 bg-amber-50 border border-amber-100 rounded-lg px-3 py-1.5 mb-3">
              <span class="font-bold">Último adiamento:</span> <?= htmlspecialchars($cic['ultimo_adiamento']) ?>
            </div>
            <?php endif; ?>

            <!-- Práticas -->
            <div class="flex flex-wrap gap-1.5">
              <?php foreach (array_filter(array_map('trim', explode(',', $cic['praticas']??''))) as $p): ?>
              <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-primary/8 text-primary"><?= htmlspecialchars($p) ?></span>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Ações -->
          <div class="flex flex-col sm:flex-row md:flex-col gap-2 shrink-0 min-w-[140px]">
            <?php if ($prox <= $total): ?>
            <a href="sessao.php?ciclo_id=<?= $cic['ciclo_id'] ?>"
               class="flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-full bg-primary text-white text-xs font-bold hover:opacity-90 active:scale-95 transition-all">
              <span class="material-symbols-outlined text-sm">edit_note</span>
              <?= $prox === 1 ? 'Iniciar S1 (anamnese)' : "Registrar S{$prox}" ?>
            </a>
            <?php elseif ($feitas >= $total): ?>
            <a href="relatorio.php?ciclo_id=<?= $cic['ciclo_id'] ?>"
               class="flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-full bg-indigo-600 text-white text-xs font-bold hover:opacity-90 transition-all">
              <span class="material-symbols-outlined text-sm">summarize</span>
              Relatório final
            </a>
            <?php endif; ?>

            <button onclick="abrirAdiar(<?= $cic['ciclo_id'] ?>, <?= $prox ?>, '<?= htmlspecialchars($cic['pnome'], ENT_QUOTES) ?>')"
              class="flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-full border-2 border-amber-300 text-amber-700 text-xs font-bold hover:bg-amber-50 transition-all">
              <span class="material-symbols-outlined text-sm">event_busy</span>Adiar sessão
            </button>

            <button onclick="abrirFaltou(<?= $cic['ciclo_id'] ?>, <?= $prox ?>, <?= $faltas ?>, '<?= htmlspecialchars($cic['pnome'], ENT_QUOTES) ?>')"
              class="flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-full border-2 border-red-200 text-red-600 text-xs font-bold hover:bg-red-50 transition-all">
              <span class="material-symbols-outlined text-sm">person_off</span>Faltou
            </button>

            <?php if ($feitas >= $total): ?>
            <button onclick="concluirCiclo(<?= $cic['ciclo_id'] ?>)"
              class="flex items-center justify-center gap-1.5 px-4 py-2.5 rounded-full bg-emerald-600 text-white text-xs font-bold hover:opacity-90 transition-all">
              <span class="material-symbols-outlined text-sm">check_circle</span>Concluir ciclo
            </button>
            <?php endif; ?>

            <button onclick="abrirHistorico(<?= $cic['pac_uid'] ?>, '<?= htmlspecialchars($cic['pnome'], ENT_QUOTES) ?>')"
              class="flex items-center justify-center gap-1.5 px-4 py-2 rounded-full border border-outline-variant text-on-surface-variant text-xs font-medium hover:bg-surface-container-high transition-all">
              <span class="material-symbols-outlined text-sm">history</span>Histórico
            </button>
          </div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- ══ TAB: PENDENTES ══ -->
    <div id="tab-pendentes" class="tab-content hidden grid gap-3">
      <?php if (empty($pendentes)): ?>
      <p class="text-center py-12 text-on-surface-variant text-sm">Nenhuma solicitação pendente.</p>
      <?php else: foreach ($pendentes as $r):
        $dia = $dias_nomes[(int)$r['dia_semana']] ?? '?';
        $dt  = date('d/m', strtotime($r['data_sessao']));
      ?>
      <div class="glass rounded-2xl p-5 flex flex-col md:flex-row md:items-center gap-4">
        <div class="flex-grow min-w-0">
          <div class="flex flex-wrap items-center gap-2 mb-1">
            <h3 class="font-headline font-bold text-on-surface"><?= htmlspecialchars($r['pnome']) ?></h3>
            <span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-primary/10 text-primary">Pendente</span>
          </div>
          <p class="text-xs text-on-surface-variant"><?= $dia ?> <?= $dt ?> · <?= substr($r['hora_inicio'],0,5) ?>–<?= substr($r['hora_fim'],0,5) ?> · <?= htmlspecialchars($r['telefone_contato'] ?? '') ?></p>
          <?php if ($r['queixas']): ?><p class="text-xs text-on-surface-variant mt-1 truncate">"<?= htmlspecialchars($r['queixas']) ?>"</p><?php endif; ?>
        </div>
        <div class="flex gap-2 shrink-0" onclick="event.stopPropagation()">
          <button onclick="confirmarReserva(<?= $r['id'] ?>, this)"
            class="px-4 py-2 rounded-full bg-primary text-white text-xs font-bold hover:opacity-90 transition-all">Confirmar</button>
          <button onclick="recusarReserva(<?= $r['id'] ?>, this)"
            class="px-4 py-2 rounded-full border-2 border-outline-variant text-on-surface-variant text-xs font-bold hover:bg-surface-container-high transition-all">Recusar</button>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- ══ TAB: FILA ══ -->
    <div id="tab-fila" class="tab-content hidden grid gap-3">
      <?php if (empty($fila)): ?>
      <p class="text-center py-12 text-on-surface-variant text-sm">Nenhum paciente na fila.</p>
      <?php else: foreach ($fila as $f):
        $dia = $dias_nomes[(int)$f['dia_semana']] ?? '?';
      ?>
      <div class="glass rounded-2xl p-5 flex flex-col md:flex-row md:items-center gap-4">
        <div class="flex-grow">
          <div class="flex items-center gap-2 mb-1">
            <h3 class="font-headline font-bold text-on-surface"><?= htmlspecialchars($f['pnome']) ?></h3>
            <span class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-700">Fila · Pos. <?= $f['posicao'] ?></span>
          </div>
          <p class="text-xs text-on-surface-variant"><?= $dia ?> · <?= substr($f['hora_inicio'],0,5) ?>–<?= substr($f['hora_fim'],0,5) ?></p>
        </div>
        <button onclick="chamarFila(<?= $f['id'] ?>, this)"
          class="px-4 py-2 rounded-full bg-amber-500 text-white text-xs font-bold hover:opacity-90 transition-all flex items-center gap-1 shrink-0">
          <span class="material-symbols-outlined text-sm">call</span>Chamar da fila
        </button>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- ══ TAB: HISTÓRICO ══ -->
    <div id="tab-concluidos" class="tab-content hidden grid gap-3">
      <?php if (empty($concluidos)): ?>
      <p class="text-center py-12 text-on-surface-variant text-sm">Nenhum ciclo concluído ainda.</p>
      <?php else: foreach ($concluidos as $c): ?>
      <div class="glass rounded-2xl p-5 flex items-center gap-4 opacity-75">
        <div class="w-10 h-10 rounded-full <?= $c['ciclo_status']==='concluido'?'bg-indigo-100 text-indigo-600':'bg-red-50 text-red-400' ?> flex items-center justify-center shrink-0">
          <span class="material-symbols-outlined text-base"><?= $c['ciclo_status']==='concluido'?'verified':'cancel' ?></span>
        </div>
        <div class="flex-grow">
          <h3 class="font-headline font-bold text-on-surface text-sm"><?= htmlspecialchars($c['pnome']) ?></h3>
          <p class="text-xs text-on-surface-variant"><?= $c['sessoes_realizadas'] ?> sessões · Encerrado <?= $c['encerrado_em'] ? date('d/m/Y', strtotime($c['encerrado_em'])) : '—' ?></p>
        </div>
        <?php if ($c['ciclo_status']==='concluido'): ?>
        <a href="relatorio.php?ciclo_id=<?= $c['ciclo_id'] ?>" class="text-xs font-bold text-primary hover:underline shrink-0">Ver relatório</a>
        <?php endif; ?>
      </div>
      <?php endforeach; endif; ?>
    </div>

  </div>
</main>

<!-- ══ MODAL: Adiar sessão ══ -->
<div class="modal-wrap fixed inset-0 z-[100] items-center justify-center p-4" id="modal-adiar">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-adiar')"></div>
  <div class="glass modal-card relative z-10 w-full max-w-md rounded-[2rem] shadow-2xl p-8">
    <h2 class="text-xl font-extrabold text-primary mb-1">Adiar sessão</h2>
    <p id="adiar-sub" class="text-sm text-on-surface-variant mb-5"></p>
    <label class="block text-xs font-bold uppercase tracking-widest text-on-surface/60 mb-1.5">Motivo do adiamento <span class="text-secondary font-normal normal-case tracking-normal">(obrigatório — ficará visível ao paciente e coordenação)</span></label>
    <textarea id="adiar-motivo" rows="3" placeholder="Ex: Terapeuta ficará ausente. Entraremos em contato para reagendar."
      class="w-full rounded-2xl border border-outline-variant/30 bg-white/60 px-4 py-3 text-sm text-on-surface resize-none transition-all mb-5 focus:ring-2 focus:ring-primary focus:border-primary"></textarea>
    <div id="adiar-erro" class="hidden text-xs text-error font-medium mb-3 flex items-center gap-1">
      <span class="material-symbols-outlined text-sm">error</span><span></span>
    </div>
    <div class="flex gap-3">
      <button id="btn-adiar-ok" onclick="confirmarAdiar()"
        class="flex-grow py-4 rounded-full bg-amber-500 text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all flex items-center justify-center gap-2">
        <span class="material-symbols-outlined text-sm">event_busy</span>Confirmar adiamento
      </button>
      <button onclick="fecharModal('modal-adiar')" class="px-6 py-4 rounded-full border-2 border-outline-variant text-on-surface-variant font-bold text-sm hover:bg-surface-container-high transition-all">Cancelar</button>
    </div>
  </div>
</div>

<!-- ══ MODAL: Faltou ══ -->
<div class="modal-wrap fixed inset-0 z-[100] items-center justify-center p-4" id="modal-faltou">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-faltou')"></div>
  <div class="glass modal-card relative z-10 w-full max-w-md rounded-[2rem] shadow-2xl p-8">
    <div id="falta-aviso-2" class="hidden mb-4 bg-red-50 border border-red-200 rounded-xl px-4 py-3 flex items-start gap-3">
      <span class="material-symbols-outlined text-red-500 shrink-0">warning</span>
      <div>
        <p class="font-bold text-red-800 text-sm">⚠️ Segunda falta — paciente será bloqueado</p>
        <p class="text-red-700 text-sm mt-0.5">Ao confirmar, o paciente será automaticamente afastado por 30 dias e receberá uma notificação explicando que é uma regra do sistema.</p>
      </div>
    </div>
    <h2 class="text-xl font-extrabold text-primary mb-1">Registrar falta</h2>
    <p id="faltou-sub" class="text-sm text-on-surface-variant mb-5"></p>
    <label class="block text-xs font-bold uppercase tracking-widest text-on-surface/60 mb-1.5">Justificativa (opcional)</label>
    <textarea id="faltou-just" rows="2" placeholder="Informe caso o paciente tenha justificado..."
      class="w-full rounded-2xl border border-outline-variant/30 bg-white/60 px-4 py-3 text-sm text-on-surface resize-none transition-all mb-5 focus:ring-2 focus:ring-primary focus:border-primary"></textarea>
    <div class="flex gap-3">
      <button id="btn-faltou-ok" onclick="confirmarFaltou()"
        class="flex-grow py-4 rounded-full bg-red-500 text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all flex items-center justify-center gap-2">
        <span class="material-symbols-outlined text-sm">person_off</span>Confirmar falta
      </button>
      <button onclick="fecharModal('modal-faltou')" class="px-6 py-4 rounded-full border-2 border-outline-variant text-on-surface-variant font-bold text-sm hover:bg-surface-container-high transition-all">Cancelar</button>
    </div>
  </div>
</div>

<!-- ══ MODAL: Histórico do paciente ══ -->
<div class="modal-wrap fixed inset-0 z-[100] items-end sm:items-center justify-center p-0 sm:p-4" id="modal-historico">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-historico')"></div>
  <div class="glass modal-card relative z-10 w-full sm:max-w-2xl rounded-t-[2rem] sm:rounded-[2rem] shadow-2xl flex flex-col max-h-[90vh] overflow-hidden">
    <div class="flex items-center justify-between px-6 pt-6 pb-3 shrink-0">
      <h2 class="text-lg font-extrabold text-primary">Histórico do Paciente</h2>
      <button onclick="fecharModal('modal-historico')" class="w-9 h-9 flex items-center justify-center rounded-full bg-surface-container-high hover:bg-surface-container-highest transition-colors">
        <span class="material-symbols-outlined text-base text-on-surface-variant">close</span>
      </button>
    </div>
    <div id="historico-body" class="overflow-y-auto px-6 pb-6 flex-1 space-y-3">
      <div class="text-center py-8 text-on-surface-variant text-sm">Carregando...</div>
    </div>
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
// ── Tabs ───────────────────────────────────────
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));
    btn.classList.add('active');
    document.getElementById('tab-'+btn.dataset.tab).classList.remove('hidden');
  });
});

// ── Helpers ────────────────────────────────────
function abrirModal(id)  { document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function fecharModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
function toast(msg, icon='check_circle', cor='text-emerald-600') {
  const t=document.getElementById('toast');
  document.getElementById('toast-msg').textContent=msg;
  const ic=document.getElementById('toast-icon'); ic.textContent=icon; ic.className='material-symbols-outlined text-base '+cor;
  t.classList.remove('hidden'); setTimeout(()=>t.classList.add('hidden'),3500);
}
async function api(dados) {
  const form = new FormData();
  Object.entries(dados).forEach(([k,v])=>form.append(k,v));
  const res = await fetch('../api/ciclo_action.php',{method:'POST',body:form});
  return res.json();
}

// ── Confirmar reserva (cria ciclo) ──────────────
async function confirmarReserva(rid, btn) {
  btn.disabled=true; btn.textContent='...';
  const data = await api({acao:'confirmar_reserva', reserva_id:rid});
  if (data.ok) { toast('Confirmado! Ciclo criado.','check_circle','text-emerald-600'); setTimeout(()=>location.reload(),1000); }
  else { toast(data.msg||'Erro.','error','text-red-500'); btn.disabled=false; btn.textContent='Confirmar'; }
}
async function recusarReserva(rid, btn) {
  btn.disabled=true; btn.textContent='...';
  const form=new FormData(); form.append('acao','cancelar'); form.append('reserva_id',rid);
  const r = await fetch('../api/reserva_action.php',{method:'POST',body:form});
  const data = await r.json();
  if (data.ok) { toast('Recusado.','cancel','text-red-500'); setTimeout(()=>location.reload(),1000); }
  else { toast(data.msg||'Erro.','error','text-red-500'); btn.disabled=false; btn.textContent='Recusar'; }
}

// ── Adiar ──────────────────────────────────────
let _adiarCiclo=null, _adiarSessao=null;
function abrirAdiar(cicloId, sessaoNum, pnome) {
  _adiarCiclo=cicloId; _adiarSessao=sessaoNum;
  document.getElementById('adiar-sub').textContent = `Paciente: ${pnome} · Sessão ${sessaoNum}`;
  document.getElementById('adiar-motivo').value='';
  document.getElementById('adiar-erro').classList.add('hidden');
  abrirModal('modal-adiar');
}
async function confirmarAdiar() {
  const motivo = document.getElementById('adiar-motivo').value.trim();
  if (!motivo) { document.getElementById('adiar-erro').classList.remove('hidden'); document.querySelector('#adiar-erro span:last-child').textContent='Informe o motivo.'; return; }
  document.getElementById('btn-adiar-ok').disabled=true;
  const data = await api({acao:'adiar', ciclo_id:_adiarCiclo, sessao_num:_adiarSessao, motivo});
  fecharModal('modal-adiar');
  if (data.ok) { toast('Adiamento registrado e paciente notificado.','event_busy','text-amber-600'); setTimeout(()=>location.reload(),1200); }
  else toast(data.msg||'Erro.','error','text-red-500');
}

// ── Faltou ─────────────────────────────────────
let _faltaCiclo=null, _faltaSessao=null;
function abrirFaltou(cicloId, sessaoNum, faltasAtuais, pnome) {
  _faltaCiclo=cicloId; _faltaSessao=sessaoNum;
  document.getElementById('faltou-sub').textContent = `Paciente: ${pnome} · Sessão ${sessaoNum} · Faltas atuais: ${faltasAtuais}`;
  document.getElementById('faltou-just').value='';
  document.getElementById('falta-aviso-2').classList.toggle('hidden', faltasAtuais < 1);
  abrirModal('modal-faltou');
}
async function confirmarFaltou() {
  const just = document.getElementById('faltou-just').value.trim();
  document.getElementById('btn-faltou-ok').disabled=true;
  const data = await api({acao:'faltou', ciclo_id:_faltaCiclo, sessao_num:_faltaSessao, justificativa:just});
  fecharModal('modal-faltou');
  if (data.ok) {
    if (data.acao==='bloqueado') toast('Segunda falta! Paciente bloqueado por 30 dias.','block','text-red-600');
    else toast(`Falta registrada. Total: ${data.faltas}.`,'person_off','text-amber-600');
    setTimeout(()=>location.reload(),1200);
  } else toast(data.msg||'Erro.','error','text-red-500');
}

// ── Concluir ciclo ─────────────────────────────
async function concluirCiclo(cicloId) {
  if (!confirm('Concluir este ciclo? O paciente ficará bloqueado por 30 dias para dar espaço a outros.')) return;
  const data = await api({acao:'concluir_ciclo', ciclo_id:cicloId});
  if (data.ok) { toast('Ciclo concluído!','verified','text-indigo-600'); setTimeout(()=>location.reload(),1200); }
  else toast(data.msg||'Erro.','error','text-red-500');
}

// ── Fila ────────────────────────────────────────
async function chamarFila(fid, btn) {
  btn.disabled=true;
  const form=new FormData(); form.append('acao','chamar_fila'); form.append('fila_id',fid);
  const r = await fetch('../api/reserva_action.php',{method:'POST',body:form});
  const data = await r.json();
  if (data.ok) { toast('Paciente notificado!','notifications_active','text-amber-600'); setTimeout(()=>location.reload(),1200); }
  else { toast(data.msg||'Erro.','error','text-red-500'); btn.disabled=false; }
}

// ── Histórico do paciente ──────────────────────
async function abrirHistorico(pacUid, pnome) {
  abrirModal('modal-historico');
  document.querySelector('#modal-historico h2').textContent = 'Histórico — '+pnome;
  const body = document.getElementById('historico-body');
  body.innerHTML='<div class="text-center py-8 text-on-surface-variant text-sm">Carregando...</div>';
  try {
    const r = await fetch(`../api/historico_paciente.php?pac_uid=${pacUid}`);
    const data = await r.json();
    if (!data.ok || !data.ciclos.length) {
      body.innerHTML='<p class="text-sm text-on-surface-variant text-center py-8">Nenhum histórico anterior.</p>'; return;
    }
    body.innerHTML = data.ciclos.map(c => `
      <div class="bg-surface-container-low rounded-2xl p-4 border border-outline-variant/20">
        <div class="flex items-center justify-between mb-2">
          <span class="text-xs font-bold text-primary uppercase tracking-widest">${c.status==='concluido'?'✅ Concluído':'❌ Cancelado'} · ${c.sessoes_realizadas} sessões</span>
          <span class="text-xs text-outline">${c.periodo}</span>
        </div>
        ${c.anamnese ? `<p class="text-xs text-on-surface-variant mb-1"><strong>Queixa:</strong> ${c.anamnese}</p>` : ''}
        ${c.praticas ? `<p class="text-xs text-on-surface-variant"><strong>Práticas:</strong> ${c.praticas}</p>` : ''}
        ${c.terapeuta ? `<p class="text-xs text-on-surface-variant mt-1"><strong>Terapeuta:</strong> ${c.terapeuta}</p>` : ''}
      </div>
    `).join('');
  } catch(e) {
    body.innerHTML='<p class="text-sm text-error text-center py-8">Erro ao carregar histórico.</p>';
  }
}
</script>
</body>
</html>