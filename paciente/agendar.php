<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'paciente') {
    header('Location: ../index.php'); exit;
}
require_once '../config/db.php';

$uid         = (int)$_SESSION['usuario_id'];
$nome_paciente = $_SESSION['nome'];

// Busca o telefone do paciente para pré-preencher o campo
$tel_stmt = $pdo->prepare("SELECT telefone FROM usuarios WHERE id = ?");
$tel_stmt->execute([$uid]);
$telefone_cadastrado = $tel_stmt->fetchColumn() ?? '';

// Busca slots ativos com vagas calculadas para a próxima ocorrência de cada dia
$slots_raw = $pdo->query("
    SELECT
        s.id, s.dia_semana, s.hora_inicio, s.hora_fim,
        s.local, s.praticas, s.vagas_total,
        u.nome  AS terapeuta_nome,
        t.especialidade,
        s.vagas_total - IFNULL((
            SELECT COUNT(*)
            FROM reservas r
            WHERE r.slot_id   = s.id
              AND r.status    NOT IN ('cancelado')
              AND r.data_sessao = DATE_ADD(CURDATE(),
                    INTERVAL MOD(s.dia_semana - 1 - WEEKDAY(CURDATE()) + 7, 7) DAY)
        ), 0) AS vagas_disponiveis,
        DATE_FORMAT(
            DATE_ADD(CURDATE(),
                INTERVAL MOD(s.dia_semana - 1 - WEEKDAY(CURDATE()) + 7, 7) DAY),
            '%d/%m/%Y'
        ) AS data_exibicao
    FROM slots s
    JOIN   usuarios   u ON s.terapeuta_id = u.id
    LEFT JOIN terapeutas t ON t.usuario_id = u.id
    WHERE s.ativo = 1
    ORDER BY s.dia_semana, s.hora_inicio
")->fetchAll(PDO::FETCH_ASSOC);

// Agrupa por dia_semana
$dias_nomes = [1=>'Segunda',2=>'Terça',3=>'Quarta',4=>'Quinta',5=>'Sexta'];
$slots_por_dia = [];
foreach ($slots_raw as $s) {
    $slots_por_dia[(int)$s['dia_semana']][] = $s;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>NUPICS | Agendar Sessão</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script>
  tailwind.config = {
    darkMode:"class",
    theme:{extend:{
      colors:{
        "surface":"#fff7fc","on-surface":"#201923","outline-variant":"#d0c2d3",
        "surface-container-low":"#fdeffe","surface-container":"#f7eaf8",
        "surface-container-high":"#f2e4f2","surface-container-highest":"#ecdeed",
        "surface-container-lowest":"#ffffff","on-surface-variant":"#4d4351",
        "primary":"#4e0078","on-primary":"#ffffff","primary-container":"#6a1b9a",
        "secondary":"#b7004d","on-secondary":"#ffffff",
        "error":"#ba1a1a","error-container":"#ffdad6","on-error-container":"#93000a",
        "background":"#fff7fc","on-background":"#201923"
      },
      fontFamily:{"headline":["Plus Jakarta Sans"],"body":["Manrope"]}
    }}
  }
</script>
<style>
  body { font-family:"Manrope",sans-serif }
  h1,h2,h3,h4 { font-family:"Plus Jakarta Sans",sans-serif }
  .material-symbols-outlined { font-variation-settings:"FILL" 0,"wght" 400,"GRAD" 0,"opsz" 24 }
  .glass { background:rgba(255,255,255,.82); backdrop-filter:blur(20px) saturate(180%);
           -webkit-backdrop-filter:blur(20px) saturate(180%); border:1px solid rgba(255,255,255,.45); }
  .slot-btn.selected { border-color:#4e0078 !important; border-width:2px !important;
                       background:rgba(78,0,120,.06) !important; box-shadow:0 4px 18px rgba(78,0,120,.12); }
  .slot-btn.selected .slot-time { color:#4e0078 !important; font-weight:700; }
  .modal-wrap { display:none; }
  .modal-wrap.open { display:flex; animation:mfade .18s ease; }
  @keyframes mfade { from{opacity:0} to{opacity:1} }
  .modal-card { animation:mup .22s cubic-bezier(.22,1,.36,1); }
  @keyframes mup { from{opacity:0;transform:translateY(24px)} to{opacity:1;transform:translateY(0)} }
  textarea:focus,input:focus { outline:none; box-shadow:0 0 0 3px rgba(78,0,120,.15); }
</style>
</head>
<body class="bg-surface text-on-background min-h-screen flex flex-col">

<!-- Nav -->
<nav class="fixed top-0 w-full z-50 bg-white/60 backdrop-blur-md shadow-[0_4px_24px_rgba(32,25,35,.06)]">
  <div class="flex justify-between items-center px-8 h-20 max-w-7xl mx-auto">
    <span class="text-2xl font-bold bg-gradient-to-r from-purple-700 to-pink-600 bg-clip-text text-transparent font-['Plus_Jakarta_Sans']">NUPICS</span>
    <div class="hidden md:flex items-center space-x-8">
      <a href="dashboard.php" class="text-purple-700/70 hover:text-pink-600 transition-colors font-medium">Início</a>
      <a href="meus_agendamentos.php" class="text-purple-700/70 hover:text-pink-600 transition-colors font-medium">Meus Agendamentos</a>
      <a href="../logout.php" class="text-purple-800 font-semibold hover:text-pink-600 transition-colors">Sair</a>
    </div>
  </div>
</nav>

<main class="flex-grow pt-32 pb-20 relative overflow-hidden">
  <div class="absolute -top-40 -right-40 w-96 h-96 bg-primary/10 rounded-full blur-[100px] pointer-events-none"></div>
  <div class="absolute top-1/2 -left-40 w-96 h-96 bg-secondary/10 rounded-full blur-[100px] pointer-events-none"></div>

  <div class="max-w-7xl mx-auto px-6 relative z-10">

    <!-- Header -->
    <div class="mb-12">
      <h1 class="text-4xl md:text-5xl font-extrabold tracking-tight text-primary mb-4">Agendar sua Sessão</h1>
      <p class="text-on-surface-variant max-w-2xl text-lg leading-relaxed">
        Olá, <?= htmlspecialchars($nome_paciente) ?>! Escolha um horário disponível para sua próxima sessão.
      </p>
    </div>

    <!-- Stepper -->
    <div class="mb-12 grid grid-cols-1 md:grid-cols-3 gap-6">
      <div class="flex items-center gap-4 bg-surface-container-highest/50 p-5 rounded-xl border border-primary/10">
        <div class="w-10 h-10 rounded-full bg-primary flex items-center justify-center text-white font-bold shrink-0">1</div>
        <div><p class="text-xs font-bold uppercase tracking-widest text-primary/60 mb-0.5">Passo atual</p>
             <h3 class="font-headline font-bold text-on-surface">Dia e horário</h3></div>
      </div>
      <div class="flex items-center gap-4 bg-surface-container-low p-5 rounded-xl border border-outline-variant/10">
        <div class="w-10 h-10 rounded-full bg-surface-container-highest flex items-center justify-center text-on-surface-variant font-bold shrink-0">2</div>
        <div><p class="text-xs font-bold uppercase tracking-widest text-on-surface-variant/60 mb-0.5">Próximo</p>
             <h3 class="font-headline font-bold text-on-surface-variant">Revisão</h3></div>
      </div>
      <div class="flex items-center gap-4 bg-surface-container-low p-5 rounded-xl border border-outline-variant/10">
        <div class="w-10 h-10 rounded-full bg-surface-container-highest flex items-center justify-center text-on-surface-variant font-bold shrink-0">3</div>
        <div><p class="text-xs font-bold uppercase tracking-widest text-on-surface-variant/60 mb-0.5">Finalização</p>
             <h3 class="font-headline font-bold text-on-surface-variant">Confirmação</h3></div>
      </div>
    </div>

    <!-- Grade de horários -->
    <?php if (empty($slots_por_dia)): ?>
      <div class="text-center py-20 text-on-surface-variant">
        <span class="material-symbols-outlined text-5xl mb-3 block">event_busy</span>
        <p class="text-lg font-medium">Nenhum horário disponível no momento.</p>
      </div>
    <?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-5 mb-10">
      <?php foreach ([1,2,3,4,5] as $dia): ?>
      <div class="bg-surface-container-lowest/70 backdrop-blur-xl p-5 rounded-xl shadow-[0_8px_32px_rgba(32,25,35,.06)] border border-white/40 flex flex-col min-h-[160px]">
        <div class="mb-5 flex justify-between items-center">
          <h4 class="font-headline font-bold text-primary text-lg"><?= $dias_nomes[$dia] ?></h4>
          <span class="material-symbols-outlined text-secondary/50 text-sm">
            <?= isset($slots_por_dia[$dia]) ? 'event_available' : 'event_busy' ?>
          </span>
        </div>

        <?php if (!isset($slots_por_dia[$dia])): ?>
          <div class="flex-grow flex flex-col items-center justify-center py-6 text-center">
            <span class="material-symbols-outlined text-outline-variant text-3xl mb-1">remove_circle</span>
            <p class="text-xs text-on-surface-variant font-medium">Sem horários</p>
          </div>
        <?php else: ?>
          <div class="space-y-2.5 flex-grow">
            <?php foreach ($slots_por_dia[$dia] as $slot):
              $vagas = (int)$slot['vagas_disponiveis'];
              $lotado = $vagas <= 0;
              $praticasArr = array_map('trim', explode(',', $slot['praticas'] ?? ''));
            ?>
            <button
              class="slot-btn w-full text-left p-3.5 rounded-xl border transition-all
                     <?= $lotado
                         ? 'border-outline-variant/20 hover:border-amber-400/60 hover:bg-amber-50/50'
                         : 'border-outline-variant/20 hover:border-primary/40 hover:bg-primary/5' ?>"
              data-slot-id="<?= $slot['id'] ?>"
              data-dia="<?= $dias_nomes[$dia] ?>-feira"
              data-data="<?= htmlspecialchars($slot['data_exibicao']) ?>"
              data-hora="<?= substr($slot['hora_inicio'],0,5) ?> – <?= substr($slot['hora_fim'],0,5) ?>"
              data-terapeuta="<?= htmlspecialchars($slot['terapeuta_nome']) ?>"
              data-especialidade="<?= htmlspecialchars($slot['especialidade'] ?? 'Práticas Integrativas') ?>"
              data-local="<?= htmlspecialchars($slot['local'] ?? '') ?>"
              data-praticas="<?= htmlspecialchars($slot['praticas'] ?? '') ?>"
              data-vagas="<?= $vagas ?>"
              data-lotado="<?= $lotado ? '1' : '0' ?>">
              <div class="flex justify-between items-center">
                <span class="slot-time font-bold text-sm <?= $lotado ? 'text-on-surface-variant' : 'text-on-surface' ?>">
                  <?= substr($slot['hora_inicio'],0,5) ?> – <?= substr($slot['hora_fim'],0,5) ?>
                </span>
                <div class="flex items-center gap-1.5">
                  <?php if ($lotado): ?>
                    <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                    <span class="text-[9px] font-bold text-amber-600 uppercase tracking-wide">Lotado</span>
                  <?php else: ?>
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    <span class="text-[9px] font-bold text-emerald-600 uppercase tracking-wide"><?= $vagas ?> vaga<?= $vagas>1?'s':'' ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <?php if ($slot['terapeuta_nome']): ?>
              <p class="text-[11px] text-on-surface-variant mt-1 truncate"><?= htmlspecialchars($slot['terapeuta_nome']) ?></p>
              <?php endif; ?>
            </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Hint -->
    <p id="hint-bar" class="text-center text-on-surface-variant text-sm flex items-center justify-center gap-2">
      <span class="material-symbols-outlined text-lg">touch_app</span>
      Toque em um horário para iniciar o agendamento
    </p>

  </div>
</main>

<!-- ═══════════════════════════
     MODAL: Revisão
═══════════════════════════ -->
<div class="modal-wrap fixed inset-0 z-[100] items-end sm:items-center justify-center p-0 sm:p-4" id="modal-revisao">
  <div class="absolute inset-0 bg-primary/25 backdrop-blur-sm" id="overlay-rev"></div>
  <div class="glass modal-card relative z-10 w-full sm:max-w-xl rounded-t-[2rem] sm:rounded-[2rem] shadow-2xl flex flex-col max-h-[92vh] overflow-hidden">

    <div class="flex items-center justify-between px-7 pt-7 pb-4 shrink-0">
      <div>
        <p class="text-xs font-bold uppercase tracking-widest text-primary/50 mb-0.5">Passo 2 de 3</p>
        <h2 class="text-xl font-extrabold text-primary">Revisão do Agendamento</h2>
      </div>
      <button id="btn-fechar-rev" class="w-9 h-9 flex items-center justify-center rounded-full bg-surface-container-high hover:bg-surface-container-highest transition-colors">
        <span class="material-symbols-outlined text-lg text-on-surface-variant">close</span>
      </button>
    </div>

    <div class="overflow-y-auto px-7 pb-7 flex-1 space-y-4">

      <!-- Terapeuta -->
      <div class="flex items-center gap-4 bg-primary/5 rounded-2xl p-4 border border-primary/10">
        <div class="w-16 h-16 rounded-full bg-primary/20 flex items-center justify-center shrink-0">
          <span class="material-symbols-outlined text-primary text-2xl">person</span>
        </div>
        <div>
          <p class="text-xs font-bold uppercase tracking-widest text-primary/50 mb-0.5">Terapeuta</p>
          <p id="rev-terapeuta" class="font-bold text-on-surface text-base"></p>
          <p id="rev-especialidade" class="text-sm text-on-surface-variant"></p>
        </div>
      </div>

      <!-- Detalhes -->
      <div class="grid gap-2">
        <div class="flex items-center gap-3 bg-white/60 rounded-xl px-4 py-3 border border-outline-variant/20">
          <span class="material-symbols-outlined text-secondary shrink-0 text-lg">schedule</span>
          <div><p class="text-[11px] text-on-surface-variant font-bold uppercase tracking-wide">Horário</p>
               <p id="rev-hora" class="font-bold text-on-surface text-sm"></p></div>
        </div>
        <div class="flex items-center gap-3 bg-white/60 rounded-xl px-4 py-3 border border-outline-variant/20">
          <span class="material-symbols-outlined text-secondary shrink-0 text-lg">location_on</span>
          <div><p class="text-[11px] text-on-surface-variant font-bold uppercase tracking-wide">Local</p>
               <p id="rev-local" class="font-bold text-on-surface text-sm"></p></div>
        </div>
        <div class="flex items-start gap-3 bg-white/60 rounded-xl px-4 py-3 border border-outline-variant/20">
          <span class="material-symbols-outlined text-secondary shrink-0 text-lg mt-0.5">self_care</span>
          <div><p class="text-[11px] text-on-surface-variant font-bold uppercase tracking-wide mb-1.5">Práticas</p>
               <div id="rev-praticas" class="flex flex-wrap gap-1.5"></div></div>
        </div>
      </div>

      <!-- Aviso lotado -->
      <div id="aviso-lotado" class="hidden flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
        <span class="material-symbols-outlined text-amber-500 shrink-0 mt-0.5 text-lg">warning</span>
        <div>
          <p class="font-bold text-amber-800 text-sm">Este horário está lotado</p>
          <p class="text-amber-700 text-sm mt-0.5">Você entrará na fila de espera e será notificado quando surgir uma vaga.</p>
        </div>
      </div>

      <!-- Campo: queixas -->
      <div>
        <label class="block text-xs font-bold uppercase tracking-widest text-on-surface/60 mb-1.5" for="campo-queixas">
          Descreva suas queixas <span class="text-secondary normal-case tracking-normal font-normal">(obrigatório)</span>
        </label>
        <textarea id="campo-queixas" rows="3"
          placeholder="Ex: Dores lombares, estresse, dificuldade para dormir..."
          class="w-full rounded-2xl border border-outline-variant/30 bg-white/60 px-4 py-3 text-sm text-on-surface placeholder:text-on-surface-variant/40 focus:ring-2 focus:ring-primary focus:border-primary resize-none transition-all"></textarea>
      </div>

      <!-- Campo: telefone -->
      <div>
        <label class="block text-xs font-bold uppercase tracking-widest text-on-surface/60 mb-1.5" for="campo-telefone">
          Confirme seu telefone <span class="text-secondary normal-case tracking-normal font-normal">(obrigatório)</span>
        </label>
        <div class="relative">
          <span class="absolute left-4 top-1/2 -translate-y-1/2">
            <span class="material-symbols-outlined text-on-surface-variant text-lg">phone</span>
          </span>
          <input id="campo-telefone" type="tel"
            placeholder="(84) 99999-9999"
            class="w-full rounded-2xl border border-outline-variant/30 bg-white/60 pl-11 pr-4 py-3.5 text-sm text-on-surface placeholder:text-on-surface-variant/40 focus:ring-2 focus:ring-primary focus:border-primary transition-all"/>
        </div>
        <p class="text-xs text-on-surface-variant mt-1">Número cadastrado: <strong><?= htmlspecialchars($telefone_cadastrado ?: '(não informado)') ?></strong></p>
      </div>

      <!-- Erro -->
      <div id="rev-erro" class="hidden flex items-center gap-2 bg-error-container text-on-error-container rounded-xl px-4 py-3 text-sm font-medium">
        <span class="material-symbols-outlined text-base">error</span>
        <span id="rev-erro-msg"></span>
      </div>

      <!-- Botões -->
      <div class="flex flex-col sm:flex-row gap-3 pt-1">
        <button id="btn-confirmar"
          class="flex-grow py-4 rounded-full bg-gradient-to-r from-purple-700 to-pink-600 text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all shadow-lg flex items-center justify-center gap-2">
          <span id="btn-label">Confirmar Agendamento</span>
          <span class="material-symbols-outlined text-sm">check_circle</span>
        </button>
        <button id="btn-voltar"
          class="px-7 py-4 rounded-full border-2 border-outline-variant text-on-surface-variant font-bold text-sm hover:bg-surface-container-high transition-all">
          Voltar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════
     MODAL: Sucesso
═══════════════════════════ -->
<div class="modal-wrap fixed inset-0 z-[101] items-center justify-center p-4" id="modal-sucesso">
  <div class="absolute inset-0 bg-primary/25 backdrop-blur-sm"></div>
  <div class="glass modal-card relative z-10 w-full max-w-md rounded-[2rem] shadow-2xl p-10 text-center">
    <div id="suc-icon" class="w-20 h-20 bg-emerald-500/10 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-6">
      <span class="material-symbols-outlined text-5xl">verified</span>
    </div>
    <h2 id="suc-titulo" class="text-2xl font-extrabold text-primary mb-3">Solicitação enviada!</h2>
    <p id="suc-desc" class="text-on-surface-variant text-sm leading-relaxed mb-7"></p>
    <div id="suc-resumo" class="bg-primary/5 rounded-2xl px-5 py-4 text-left mb-7 text-sm space-y-1.5 border border-primary/10"></div>
    <button onclick="window.location.href='dashboard.php'"
      class="w-full py-4 rounded-full bg-primary text-white font-bold text-sm hover:opacity-90 transition-all shadow-xl">
      Ir para o início
    </button>
  </div>
</div>

<!-- ═══════════════════════════
     MODAL: Fila de espera
═══════════════════════════ -->
<div class="modal-wrap fixed inset-0 z-[101] items-center justify-center p-4" id="modal-fila">
  <div class="absolute inset-0 bg-primary/25 backdrop-blur-sm"></div>
  <div class="glass modal-card relative z-10 w-full max-w-md rounded-[2rem] shadow-2xl p-10 text-center">
    <div class="w-20 h-20 bg-amber-500/10 text-amber-500 rounded-full flex items-center justify-center mx-auto mb-6">
      <span class="material-symbols-outlined text-5xl">queue</span>
    </div>
    <h2 class="text-2xl font-extrabold text-primary mb-3">Você está na fila!</h2>
    <p id="fila-desc" class="text-on-surface-variant text-sm leading-relaxed mb-7"></p>
    <button onclick="window.location.href='dashboard.php'"
      class="w-full py-4 rounded-full bg-amber-500 text-white font-bold text-sm hover:opacity-90 transition-all shadow-xl">
      Entendido
    </button>
  </div>
</div>

<!-- Footer -->
<footer class="py-10 bg-purple-50/50 border-t border-purple-200/20">
  <div class="flex flex-col md:flex-row justify-between items-center px-10 max-w-7xl mx-auto text-sm text-purple-900">
    <div class="mb-4 md:mb-0">
      <span class="font-bold text-purple-800">NUPICS</span>
      <p class="mt-1 text-purple-700/60">© <?= date('Y') ?> NUPICS – UERN Caicó</p>
    </div>
    <div class="flex gap-6">
      <a href="#" class="text-purple-700 hover:text-pink-500 transition-colors">Privacidade</a>
      <a href="#" class="text-purple-700 hover:text-pink-500 transition-colors">Termos</a>
      <a href="#" class="text-purple-700 hover:text-pink-500 transition-colors">Contato</a>
    </div>
  </div>
</footer>

<script>
const TEL_CADASTRADO = <?= json_encode($telefone_cadastrado) ?>;
let slotAtual = null;

// ── Abrir modal ao clicar no slot ───────────────
document.querySelectorAll('.slot-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    slotAtual = {
      id:           btn.dataset.slotId,
      dia:          btn.dataset.dia,
      data:         btn.dataset.data,
      hora:         btn.dataset.hora,
      terapeuta:    btn.dataset.terapeuta,
      especialidade:btn.dataset.especialidade,
      local:        btn.dataset.local,
      praticas:     btn.dataset.praticas,
      lotado:       btn.dataset.lotado === '1'
    };

    // Preenche modal
    document.getElementById('rev-terapeuta').textContent    = slotAtual.terapeuta;
    document.getElementById('rev-especialidade').textContent = slotAtual.especialidade;
    document.getElementById('rev-hora').textContent = `${slotAtual.dia} ${slotAtual.data} • ${slotAtual.hora}`;
    document.getElementById('rev-local').textContent = slotAtual.local || 'A definir';

    const pillBox = document.getElementById('rev-praticas');
    pillBox.innerHTML = '';
    (slotAtual.praticas || '').split(',').forEach(p => {
      if (!p.trim()) return;
      const s = document.createElement('span');
      s.className = 'px-3 py-1 rounded-full bg-primary/10 text-primary text-xs font-bold';
      s.textContent = p.trim(); pillBox.appendChild(s);
    });

    document.getElementById('aviso-lotado').classList.toggle('hidden', !slotAtual.lotado);
    document.getElementById('btn-label').textContent = slotAtual.lotado
      ? 'Entrar na Fila de Espera' : 'Confirmar Agendamento';

    // Pré-preenche telefone
    document.getElementById('campo-telefone').value = TEL_CADASTRADO || '';
    document.getElementById('campo-queixas').value  = '';
    esconderErro();

    // Highlight no botão
    document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    document.getElementById('hint-bar').style.display = 'none';

    abrirModal('modal-revisao');
  });
});

// ── Fechar ──────────────────────────────────────
document.getElementById('btn-fechar-rev').addEventListener('click', () => fecharModal('modal-revisao'));
document.getElementById('btn-voltar').addEventListener('click',    () => fecharModal('modal-revisao'));
document.getElementById('overlay-rev').addEventListener('click',   () => fecharModal('modal-revisao'));

// ── Confirmar ───────────────────────────────────
document.getElementById('btn-confirmar').addEventListener('click', async () => {
  const queixas  = document.getElementById('campo-queixas').value.trim();
  const telefone = document.getElementById('campo-telefone').value.trim();

  if (!queixas)                  return mostrarErro('Descreva suas queixas antes de continuar.');
  if (telefone.length < 8)       return mostrarErro('Informe um telefone válido.');

  const btn = document.getElementById('btn-confirmar');
  btn.disabled = true;
  btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-sm">progress_activity</span>';

  const form = new FormData();
  form.append('acao',     'reservar');
  form.append('slot_id',  slotAtual.id);
  form.append('queixas',  queixas);
  form.append('telefone', telefone);

  try {
    const res  = await fetch('../api/reserva_action.php', { method:'POST', body: form });
    const data = await res.json();

    fecharModal('modal-revisao');

    if (!data.ok) {
      setTimeout(() => { abrirModal('modal-revisao'); mostrarErro(data.msg); }, 200);
      return;
    }

    if (data.tipo === 'fila') {
      document.getElementById('fila-desc').textContent =
        `Você está na posição ${data.posicao} da fila para ${slotAtual.dia} (${slotAtual.data}) às ${slotAtual.hora.split('–')[0].trim()}. Você será avisado(a) por e-mail quando surgir uma vaga.`;
      setTimeout(() => abrirModal('modal-fila'), 200);
    } else {
      document.getElementById('suc-desc').textContent =
        'Sua solicitação foi enviada! O terapeuta receberá e confirmará em breve.';
      document.getElementById('suc-resumo').innerHTML = `
        <p class="flex items-center gap-2 text-on-surface-variant text-sm">
          <span class="material-symbols-outlined text-secondary text-sm">schedule</span>
          <span class="font-medium text-on-surface">${slotAtual.dia} ${slotAtual.data} • ${slotAtual.hora}</span>
        </p>
        <p class="flex items-center gap-2 text-on-surface-variant text-sm">
          <span class="material-symbols-outlined text-secondary text-sm">person</span>
          <span class="font-medium text-on-surface">${slotAtual.terapeuta}</span>
        </p>
        <p class="flex items-center gap-2 text-on-surface-variant text-sm">
          <span class="material-symbols-outlined text-secondary text-sm">location_on</span>
          <span class="font-medium text-on-surface">${slotAtual.local || 'A definir'}</span>
        </p>`;
      setTimeout(() => abrirModal('modal-sucesso'), 200);
    }
  } catch(e) {
    mostrarErro('Erro de conexão. Tente novamente.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = `<span id="btn-label">${slotAtual?.lotado ? 'Entrar na Fila' : 'Confirmar Agendamento'}</span><span class="material-symbols-outlined text-sm">check_circle</span>`;
  }
});

// ── Helpers ─────────────────────────────────────
function abrirModal(id)  { document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function fecharModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
function mostrarErro(msg) {
  const el = document.getElementById('rev-erro');
  document.getElementById('rev-erro-msg').textContent = msg;
  el.classList.remove('hidden');
  el.scrollIntoView({behavior:'smooth',block:'nearest'});
}
function esconderErro() { document.getElementById('rev-erro').classList.add('hidden'); }
</script>
</body>
</html>