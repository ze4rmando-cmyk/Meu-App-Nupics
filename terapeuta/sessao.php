<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'terapeuta') {
    header('Location: ../index.php'); exit;
}
require_once '../config/db.php';

$uid      = (int)$_SESSION['usuario_id'];
$ciclo_id = (int)($_GET['ciclo_id'] ?? 0);

if (!$ciclo_id) { header('Location: dashboard.php'); exit; }

// ── Carrega ciclo + paciente + slot ─────────────────────────────────────────
$ciclo = $pdo->prepare("
    SELECT c.id, c.total_sessoes, c.sessoes_realizadas, c.faltas,
           c.status, c.terapeuta_id as terapeutas_id,
           r.id as reserva_id, r.paciente_id as pac_uid,
           r.queixas as queixas_iniciais, r.data_sessao,
           u.nome as paciente_nome, u.email as paciente_email, u.telefone,
           p.id as paciente_pid, p.data_nasc, p.sexo, p.cpf,
           p.ocupacao, p.observacao_clinica,
           s.hora_inicio, s.hora_fim, s.dia_semana, s.local, s.praticas,
           -- Sessão atual
           (SELECT COUNT(*) FROM anamnese_inicial WHERE ciclo_id=c.id) as tem_anamnese,
           (SELECT COUNT(*) FROM registros_sessao WHERE ciclo_id=c.id AND status='realizado') as followups_ok
    FROM ciclos c
    JOIN reservas r ON c.reserva_id = r.id
    JOIN usuarios u ON r.paciente_id = u.id
    JOIN pacientes p ON p.usuario_id = u.id
    JOIN slots s ON r.slot_id = s.id
    WHERE c.id = ? AND c.status = 'ativo'
");
$ciclo->execute([$ciclo_id]);
$c = $ciclo->fetch(PDO::FETCH_ASSOC);
if (!$c) { header('Location: dashboard.php?erro=ciclo_nao_encontrado'); exit; }

// Calcula número da sessão atual
$sessao_num = ($c['tem_anamnese'] == 0) ? 1 : ($c['tem_anamnese'] + $c['followups_ok'] + 1);
$tipo_form  = ($sessao_num === 1) ? 'anamnese' : 'seguimento';

// ── Salva ────────────────────────────────────────────────────────────────────
$erro = $sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($tipo_form === 'anamnese') {
        $pdo->prepare("
            INSERT INTO anamnese_inicial (
                ciclo_id, terapeuta_id,
                motivo_procura, tempo_problema,
                inicio_quadro, frequencia, intensidade_inicio,
                fatores_piora, fatores_melhora, tratamento_anterior, tratamento_qual, medicacao_relacionada,
                doencas_diagnosticadas, alergias, cirurgias, internacoes, medicamentos_continuos,
                qualidade_sono, qualidade_alimentacao, pratica_atividade, uso_alcool_tabaco,
                doencas_familia, nivel_estresse, rede_apoio, situacao_emocional,
                dor_presente, dor_intensidade, dor_localizacao, limitacao_funcional,
                impacto_rotina, estado_emocional, expectativa,
                objetivos, procedimentos, orientacoes, plano_terapeutico
            ) VALUES (?,?, ?,?, ?,?,?,?,?,?,?,?, ?,?,?,?,?, ?,?,?,?, ?,?,?,?, ?,?,?,?,?,?,?, ?,?,?,?)
        ")->execute([
            $ciclo_id, $uid,
            $_POST['motivo_procura'] ?? '', $_POST['tempo_problema'] ?? '',
            $_POST['inicio_quadro'] ?? '', $_POST['frequencia'] ?? '',
            $_POST['intensidade_inicio'] !== '' ? (int)$_POST['intensidade_inicio'] : null,
            $_POST['fatores_piora'] ?? '', $_POST['fatores_melhora'] ?? '',
            isset($_POST['tratamento_anterior']) ? 1 : 0,
            $_POST['tratamento_qual'] ?? '', $_POST['medicacao_relacionada'] ?? '',
            $_POST['doencas_diagnosticadas'] ?? '', $_POST['alergias'] ?? '',
            $_POST['cirurgias'] ?? '', $_POST['internacoes'] ?? '', $_POST['medicamentos_continuos'] ?? '',
            $_POST['qualidade_sono'] ?: null, $_POST['qualidade_alimentacao'] ?: null,
            isset($_POST['pratica_atividade']) ? 1 : 0, $_POST['uso_alcool_tabaco'] ?? '',
            $_POST['doencas_familia'] ?? '', $_POST['nivel_estresse'] ?: null,
            $_POST['rede_apoio'] ?: null, $_POST['situacao_emocional'] ?? '',
            isset($_POST['dor_presente']) ? 1 : 0,
            $_POST['dor_intensidade'] !== '' ? (int)$_POST['dor_intensidade'] : null,
            $_POST['dor_localizacao'] ?? '', $_POST['limitacao_funcional'] ?? '',
            $_POST['impacto_rotina'] ?? '', $_POST['estado_emocional'] ?? '', $_POST['expectativa'] ?? '',
            implode(',', (array)($_POST['objetivos'] ?? [])),
            $_POST['procedimentos'] ?? '', $_POST['orientacoes'] ?? '', $_POST['plano_terapeutico'] ?? '',
        ]);
        $pdo->prepare("UPDATE ciclos SET sessoes_realizadas=sessoes_realizadas+1 WHERE id=?")->execute([$ciclo_id]);

    } else {
        $pdo->prepare("
            INSERT INTO registros_sessao (
                ciclo_id, terapeuta_id, numero_sessao, data_sessao, status,
                evolucao, evolucao_descricao,
                dor_antes, dor_depois, desconforto, outros_sintomas,
                adesao, dificuldades_adesao,
                melhora_rotina, sono_rotina,
                comportamento, cooperacao, ansiedade_resistencia,
                resposta_procedimento, mudanca_funcional,
                procedimentos, ajustes_plano, orientacoes, plano_proxima
            ) VALUES (?,?,?,CURDATE(),'realizado', ?,?, ?,?,?,?, ?,?, ?,?, ?,?,?, ?,?, ?,?,?,?)
        ")->execute([
            $ciclo_id, $uid, $sessao_num,
            $_POST['evolucao'] ?: null, $_POST['evolucao_descricao'] ?? '',
            $_POST['dor_antes'] !== '' ? (int)$_POST['dor_antes'] : null,
            $_POST['dor_depois'] !== '' ? (int)$_POST['dor_depois'] : null,
            $_POST['desconforto'] ?? '', $_POST['outros_sintomas'] ?? '',
            $_POST['adesao'] ?: null, $_POST['dificuldades_adesao'] ?? '',
            $_POST['melhora_rotina'] ?? '', $_POST['sono_rotina'] ?? '',
            $_POST['comportamento'] ?? '', $_POST['cooperacao'] ?? '',
            $_POST['ansiedade_resistencia'] ?? '',
            $_POST['resposta_procedimento'] ?? '', $_POST['mudanca_funcional'] ?? '',
            $_POST['procedimentos'] ?? '', $_POST['ajustes_plano'] ?? '',
            $_POST['orientacoes'] ?? '', $_POST['plano_proxima'] ?? '',
        ]);
        $pdo->prepare("UPDATE ciclos SET sessoes_realizadas=sessoes_realizadas+1 WHERE id=?")->execute([$ciclo_id]);
    }

    // Se completou todas as sessões, redireciona para relatório final
    $nova_contagem = (int)$c['sessoes_realizadas'] + 1;
    if ($nova_contagem >= (int)$c['total_sessoes']) {
        header("Location: relatorio.php?ciclo_id={$ciclo_id}&concluir=1"); exit;
    }
    header('Location: dashboard.php?ok=sessao_salva'); exit;
}

$dias_nomes = [1=>'Segunda',2=>'Terça',3=>'Quarta',4=>'Quinta',5=>'Sexta'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>NUPICS | <?= $tipo_form==='anamnese' ? 'Anamnese Inicial' : "Sessão {$sessao_num}" ?></title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script>tailwind.config={theme:{extend:{colors:{
  "primary":"#4e0078","secondary":"#b7004d","surface":"#fff7fc","on-surface":"#201923",
  "surface-container-low":"#fdeffe","surface-container":"#f7eaf8","surface-container-high":"#f2e4f2",
  "surface-container-highest":"#ecdeed","on-surface-variant":"#4d4351",
  "outline-variant":"#d0c2d3","outline":"#7f7383","background":"#fff7fc"
},fontFamily:{"headline":["Plus Jakarta Sans"],"body":["Manrope"]}}}}</script>
<style>
  body{font-family:"Manrope",sans-serif;background:radial-gradient(135deg,#f4d9ff,#fff7fc 50%,#ffd9de)}
  h1,h2,h3,h4{font-family:"Plus Jakarta Sans",sans-serif}
  .material-symbols-outlined{font-variation-settings:"FILL" 0,"wght" 400,"GRAD" 0,"opsz" 24}
  .glass{background:rgba(255,255,255,.82);backdrop-filter:blur(16px) saturate(180%);border:1px solid rgba(255,255,255,.45)}
  .campo{display:flex;align-items:flex-start;background:rgba(255,255,255,.7);border:1.5px solid #d0c2d3;
         border-radius:12px;overflow:hidden;transition:.15s}
  .campo:focus-within{border-color:#4e0078;box-shadow:0 0 0 3px rgba(78,0,120,.12)}
  .campo .ic{padding:10px 10px 0;color:#7f7383;display:flex;align-items:flex-start;flex-shrink:0}
  .campo input,.campo select,.campo textarea{flex:1;border:none;background:transparent;padding:10px 12px 10px 0;
    font-size:.85rem;color:#201923;font-family:"Manrope",sans-serif;outline:none;min-width:0;resize:vertical}
  .pill-check,.pill-radio{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:99px;
    border:1.5px solid #d0c2d3;font-size:.75rem;font-weight:600;cursor:pointer;transition:.15s;
    background:rgba(255,255,255,.6);color:#4d4351;user-select:none}
  .pill-check:has(input:checked),.pill-radio:has(input:checked){background:#4e0078;color:#fff;border-color:#4e0078}
  .pill-check input,.pill-radio input{display:none}
  .be-btn{width:2rem;height:2rem;border-radius:50%;display:flex;align-items:center;justify-content:center;
          font-size:.72rem;font-weight:700;border:1.5px solid #d0c2d3;cursor:pointer;
          background:rgba(255,255,255,.6);color:#4d4351;transition:.15s}
  .be-btn:has(input:checked){background:#4e0078;color:#fff;border-color:#4e0078}
  .be-btn input{display:none}
  .sec{margin-bottom:1.5rem}
  .sec-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#7f7383;
             margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem}
  .sec-title::after{content:'';flex:1;height:1px;background:#ecdeed}
  .label-sm{font-size:.78rem;font-weight:600;color:#201923;margin-bottom:.35rem;display:block}
  .step{display:none}.step.active{display:block}
  .step-dot{width:2rem;height:2rem;border-radius:50%;display:flex;align-items:center;justify-content:center;
            font-size:.72rem;font-weight:700;transition:.3s}
  .step-dot.done{background:#4e0078;color:#fff}
  .step-dot.active{background:#4e0078;color:#fff;box-shadow:0 0 0 4px rgba(78,0,120,.18)}
  .step-dot.idle{background:#ecdeed;color:#7f7383}
</style>
</head>
<body class="min-h-screen">

<!-- Nav -->
<nav class="fixed top-0 w-full z-50 bg-white/60 backdrop-blur-md shadow-[0_4px_24px_rgba(32,25,35,.06)]">
  <div class="flex items-center justify-between px-6 py-3.5 max-w-4xl mx-auto">
    <div class="flex items-center gap-3">
      <a href="dashboard.php" class="flex items-center gap-1.5 text-on-surface-variant hover:text-primary transition-colors">
        <span class="material-symbols-outlined text-lg">arrow_back</span>
        <span class="text-sm font-medium">Dashboard</span>
      </a>
      <span class="text-outline-variant">·</span>
      <span class="text-sm font-bold text-primary">
        <?= $tipo_form==='anamnese' ? 'Anamnese Inicial' : "Sessão {$sessao_num} – Acompanhamento" ?>
      </span>
    </div>
    <span class="text-xs text-on-surface-variant font-medium"><?= htmlspecialchars($c['paciente_nome']) ?></span>
  </div>
</nav>

<main class="pt-20 pb-16 px-4 max-w-4xl mx-auto">

  <!-- Cabeçalho do paciente -->
  <div class="glass rounded-2xl p-5 mb-8 mt-4 flex flex-col md:flex-row md:items-center gap-4">
    <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
      <span class="material-symbols-outlined text-primary text-xl">person</span>
    </div>
    <div class="flex-grow">
      <h2 class="text-lg font-extrabold text-primary"><?= htmlspecialchars($c['paciente_nome']) ?></h2>
      <div class="flex flex-wrap gap-x-5 gap-y-1 text-xs text-on-surface-variant mt-1">
        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-xs">phone</span><?= htmlspecialchars($c['telefone'] ?? '—') ?></span>
        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-xs">schedule</span>
          <?= $dias_nomes[(int)$c['dia_semana']] ?> <?= date('d/m', strtotime($c['data_sessao'])) ?> · <?= substr($c['hora_inicio'],0,5) ?>–<?= substr($c['hora_fim'],0,5) ?>
        </span>
        <span class="flex items-center gap-1"><span class="material-symbols-outlined text-xs">self_care</span><?= htmlspecialchars($c['praticas'] ?? '—') ?></span>
      </div>
    </div>
    <!-- Progresso do ciclo -->
    <div class="flex items-center gap-1.5 shrink-0">
      <?php for ($i=1; $i<=(int)$c['total_sessoes']; $i++):
        $done = $i <= (int)$c['sessoes_realizadas'];
        $curr = $i === $sessao_num;
      ?>
      <div class="step-dot <?= $done ? 'done' : ($curr ? 'active' : 'idle') ?>"
           title="Sessão <?= $i ?>">
        <?= $done ? '✓' : $i ?>
      </div>
      <?php if ($i < (int)$c['total_sessoes']): ?>
      <div class="w-5 h-0.5 rounded-full <?= $done ? 'bg-primary' : 'bg-outline-variant' ?>"></div>
      <?php endif; ?>
      <?php endfor; ?>
    </div>
  </div>

  <!-- Queixas do cadastro (contexto) -->
  <?php if ($c['queixas_iniciais']): ?>
  <div class="bg-primary/5 border border-primary/15 rounded-xl px-5 py-3 mb-6 text-sm text-on-surface">
    <span class="font-bold text-primary text-xs uppercase tracking-widest">Queixas no agendamento: </span>
    <?= htmlspecialchars($c['queixas_iniciais']) ?>
  </div>
  <?php endif; ?>

  <form method="POST" id="form-sessao">

  <?php if ($tipo_form === 'anamnese'): ?>
  <!-- ═══════════════════════════════════════════════
       FORMULÁRIO DE ANAMNESE INICIAL (SESSÃO 1)
  ═══════════════════════════════════════════════ -->

  <!-- Stepper -->
  <div id="stepper" class="flex items-center gap-3 mb-8">
    <?php foreach (['Queixa e Histórico','Saúde e Hábitos','Avaliação e Conduta'] as $i=>$st): ?>
    <div class="flex items-center gap-2">
      <div class="step-dot <?= $i===0?'active':'idle' ?>" id="sdot-<?= $i ?>"><?= $i+1 ?></div>
      <span class="text-xs font-medium hidden sm:block <?= $i===0?'text-primary':'text-outline' ?>" id="slabel-<?= $i ?>"><?= $st ?></span>
    </div>
    <?php if ($i < 2): ?>
    <div class="flex-1 h-0.5 rounded-full bg-outline-variant" id="sline-<?= $i ?>"></div>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <!-- STEP 1: Queixa principal + Histórico do problema -->
  <div class="step active" id="step-0">
    <div class="glass rounded-3xl p-7 mb-4">
      <h3 class="text-base font-extrabold text-primary mb-5">Queixa principal</h3>

      <div class="sec">
        <label class="label-sm">Motivo da procura <span class="text-secondary font-normal">(obrigatório)</span></label>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">help</span></span>
          <textarea name="motivo_procura" rows="3" placeholder="Qual é o motivo da procura? O que mais incomoda no momento?" required></textarea></div>
      </div>
      <div class="sec">
        <label class="label-sm">Tempo de duração do problema</label>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">schedule</span></span>
          <input type="text" name="tempo_problema" placeholder="Ex: 3 meses, 1 ano..."/></div>
      </div>

      <h3 class="text-base font-extrabold text-primary mb-5 mt-6">Histórico do problema atual</h3>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="sec"><label class="label-sm">Quando começou</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">calendar_month</span></span>
            <input type="text" name="inicio_quadro" placeholder="Data ou período aproximado"/></div></div>
        <div class="sec"><label class="label-sm">Frequência</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">repeat</span></span>
            <input type="text" name="frequencia" placeholder="Ex: diária, semanal, esporádica"/></div></div>
      </div>

      <div class="sec">
        <label class="label-sm">Intensidade inicial (0 = mínima · 10 = máxima)</label>
        <div class="flex gap-1.5 flex-wrap">
          <?php for ($i=0;$i<=10;$i++): ?><label class="be-btn"><input type="radio" name="intensidade_inicio" value="<?= $i ?>"><?= $i ?></label><?php endfor; ?>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="sec"><label class="label-sm">Fatores que pioram</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">trending_up</span></span>
            <textarea name="fatores_piora" rows="2" placeholder="O que piora o problema?"></textarea></div></div>
        <div class="sec"><label class="label-sm">Fatores que aliviam</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">trending_down</span></span>
            <textarea name="fatores_melhora" rows="2" placeholder="O que alivia ou melhora?"></textarea></div></div>
      </div>

      <div class="sec">
        <label class="label-sm">Já realizou tratamento anterior?</label>
        <div class="flex gap-2 mb-2">
          <label class="pill-radio"><input type="checkbox" name="tratamento_anterior">Sim</label>
        </div>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">medical_services</span></span>
          <input type="text" name="tratamento_qual" placeholder="Qual tratamento? Medicação relacionada?"/></div>
      </div>
    </div>
    <div class="flex justify-end"><button type="button" onclick="irStep(1)" class="px-8 py-3.5 rounded-full bg-primary text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all flex items-center gap-2">Continuar <span class="material-symbols-outlined text-sm">arrow_forward</span></button></div>
  </div>

  <!-- STEP 2: Saúde geral + Hábitos + Psicossocial -->
  <div class="step" id="step-1">
    <div class="glass rounded-3xl p-7 mb-4">
      <h3 class="text-base font-extrabold text-primary mb-5">Histórico de saúde geral</h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <?php foreach (['doencas_diagnosticadas'=>'Doenças pré-existentes','alergias'=>'Alergias','cirurgias'=>'Cirurgias anteriores','internacoes'=>'Internações','medicamentos_continuos'=>'Medicamentos de uso contínuo'] as $n=>$l): ?>
        <div class="sec <?= $n==='medicamentos_continuos'?'sm:col-span-2':'' ?>">
          <label class="label-sm"><?= $l ?></label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">medical_information</span></span>
            <input type="text" name="<?= $n ?>" placeholder="Descreva ou escreva 'Nenhum'"/></div></div>
        <?php endforeach; ?>
      </div>

      <h3 class="text-base font-extrabold text-primary mb-5 mt-6">Hábitos de vida</h3>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-4">
        <div><label class="label-sm">Qualidade do sono</label>
          <div class="flex flex-col gap-1.5">
            <?php foreach (['bom'=>'😴 Bom','regular'=>'😐 Regular','ruim'=>'😩 Ruim'] as $v=>$l): ?>
            <label class="pill-radio text-xs"><input type="radio" name="qualidade_sono" value="<?= $v ?>"><?= $l ?></label>
            <?php endforeach; ?>
          </div></div>
        <div><label class="label-sm">Alimentação</label>
          <div class="flex flex-col gap-1.5">
            <?php foreach (['boa'=>'✅ Boa','regular'=>'🟡 Regular','ruim'=>'❌ Ruim'] as $v=>$l): ?>
            <label class="pill-radio text-xs"><input type="radio" name="qualidade_alimentacao" value="<?= $v ?>"><?= $l ?></label>
            <?php endforeach; ?>
          </div></div>
        <div><label class="label-sm">Atividade física</label>
          <div class="flex gap-2 mb-2"><label class="pill-check"><input type="checkbox" name="pratica_atividade">Pratica</label></div>
          <div class="campo mt-2"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">fitness_center</span></span>
            <input type="text" name="uso_alcool_tabaco" placeholder="Álcool / tabaco?"/></div></div>
      </div>
      <div class="sec"><label class="label-sm">Doenças na família</label>
        <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">family_restroom</span></span>
          <input type="text" name="doencas_familia" placeholder="Ex: diabetes, hipertensão..."/></div></div>

      <h3 class="text-base font-extrabold text-primary mb-5 mt-6">Aspectos psicossociais</h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
        <div><label class="label-sm">Nível de estresse</label>
          <div class="flex gap-2 flex-wrap">
            <?php foreach (['baixo'=>'🟢 Baixo','medio'=>'🟡 Médio','alto'=>'🔴 Alto'] as $v=>$l): ?>
            <label class="pill-radio"><input type="radio" name="nivel_estresse" value="<?= $v ?>"><?= $l ?></label>
            <?php endforeach; ?>
          </div></div>
        <div><label class="label-sm">Rede de apoio</label>
          <div class="flex gap-2 flex-wrap">
            <?php foreach (['boa'=>'✅ Boa','regular'=>'🟡 Regular','fraca'=>'❌ Fraca'] as $v=>$l): ?>
            <label class="pill-radio"><input type="radio" name="rede_apoio" value="<?= $v ?>"><?= $l ?></label>
            <?php endforeach; ?>
          </div></div>
        <div class="sm:col-span-2"><label class="label-sm">Situação emocional observada</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">psychology</span></span>
            <textarea name="situacao_emocional" rows="2" placeholder="Observações sobre o estado emocional..."></textarea></div></div>
      </div>
    </div>
    <div class="flex gap-3 justify-between">
      <button type="button" onclick="irStep(0)" class="px-7 py-3.5 rounded-full border-2 border-outline-variant text-on-surface-variant font-bold text-sm hover:bg-surface-container-high transition-all flex items-center gap-2"><span class="material-symbols-outlined text-sm">arrow_back</span>Voltar</button>
      <button type="button" onclick="irStep(2)" class="px-8 py-3.5 rounded-full bg-primary text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all flex items-center gap-2">Continuar <span class="material-symbols-outlined text-sm">arrow_forward</span></button>
    </div>
  </div>

  <!-- STEP 3: Avaliação + Conduta -->
  <div class="step" id="step-2">
    <div class="glass rounded-3xl p-7 mb-4">
      <h3 class="text-base font-extrabold text-primary mb-5">Avaliação inicial</h3>

      <div class="sec">
        <label class="label-sm">Dor presente?</label>
        <label class="pill-check mb-3 inline-flex"><input type="checkbox" name="dor_presente" onchange="document.getElementById('dor-wrap').style.display=this.checked?'block':'none'">Sim, há dor</label>
        <div id="dor-wrap" style="display:none">
          <label class="label-sm mt-3">Intensidade da dor (0–10)</label>
          <div class="flex gap-1.5 flex-wrap mb-3">
            <?php for ($i=0;$i<=10;$i++): ?><label class="be-btn"><input type="radio" name="dor_intensidade" value="<?= $i ?>"><?= $i ?></label><?php endfor; ?>
          </div>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">location_on</span></span>
            <input type="text" name="dor_localizacao" placeholder="Localização da dor"/></div>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="sec"><label class="label-sm">Limitação funcional</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">accessibility</span></span>
            <textarea name="limitacao_funcional" rows="2" placeholder="Como o problema afeta as atividades do dia a dia?"></textarea></div></div>
        <div class="sec"><label class="label-sm">Estado emocional percebido</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">mood</span></span>
            <textarea name="estado_emocional" rows="2" placeholder="Como o paciente demonstra estar emocionalmente?"></textarea></div></div>
        <div class="sec sm:col-span-2"><label class="label-sm">Expectativa com o atendimento</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">flag</span></span>
            <input type="text" name="expectativa" placeholder="O que o paciente espera alcançar?"/></div></div>
      </div>

      <div class="sec">
        <label class="label-sm">Objetivos do atendimento</label>
        <div class="flex flex-wrap gap-2">
          <?php foreach (['Redução da dor','Melhora funcional','Acompanhamento','Redução do estresse','Qualidade do sono','Equilíbrio emocional','Autoconhecimento','Autonomia'] as $ob): ?>
          <label class="pill-check"><input type="checkbox" name="objetivos[]" value="<?= $ob ?>"><?= $ob ?></label>
          <?php endforeach; ?>
        </div>
      </div>

      <h3 class="text-base font-extrabold text-primary mb-5 mt-6">Conduta da sessão 1</h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="sec"><label class="label-sm">Procedimentos realizados <span class="text-secondary font-normal">(obrigatório)</span></label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">healing</span></span>
            <textarea name="procedimentos" rows="3" placeholder="Descreva as técnicas utilizadas..." required></textarea></div></div>
        <div class="sec"><label class="label-sm">Orientações dadas ao paciente</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">assignment</span></span>
            <textarea name="orientacoes" rows="3" placeholder="O que foi orientado para fazer em casa?"></textarea></div></div>
        <div class="sec sm:col-span-2"><label class="label-sm">Plano terapêutico</label>
          <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">route</span></span>
            <textarea name="plano_terapeutico" rows="2" placeholder="Planejamento para as próximas sessões..."></textarea></div></div>
      </div>
    </div>
    <div class="flex gap-3 justify-between">
      <button type="button" onclick="irStep(1)" class="px-7 py-3.5 rounded-full border-2 border-outline-variant text-on-surface-variant font-bold text-sm hover:bg-surface-container-high transition-all flex items-center gap-2"><span class="material-symbols-outlined text-sm">arrow_back</span>Voltar</button>
      <button type="submit" class="px-8 py-3.5 rounded-full bg-gradient-to-r from-purple-700 to-pink-600 text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all flex items-center gap-2"><span class="material-symbols-outlined text-sm">save</span>Salvar anamnese</button>
    </div>
  </div>

  <?php else: ?>
  <!-- ═══════════════════════════════════════════════
       FORMULÁRIO DE ACOMPANHAMENTO (SESSÕES 2-4)
  ═══════════════════════════════════════════════ -->
  <div class="glass rounded-3xl p-7 mb-6">
    <h3 class="text-base font-extrabold text-primary mb-6">Sessão <?= $sessao_num ?> – Acompanhamento</h3>

    <div class="sec-title"><span class="material-symbols-outlined" style="font-size:14px">trending_up</span>Evolução desde a última sessão</div>
    <div class="flex gap-2 flex-wrap mb-3">
      <?php foreach (['melhorou'=>'✅ Melhorou','piorou'=>'❌ Piorou','igual'=>'➡️ Igual'] as $v=>$l): ?>
      <label class="pill-radio"><input type="radio" name="evolucao" value="<?= $v ?>" required><?= $l ?></label>
      <?php endforeach; ?>
    </div>
    <div class="campo mb-4"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">edit_note</span></span>
      <textarea name="evolucao_descricao" rows="2" placeholder="Descreva as mudanças relatadas pelo paciente..."></textarea></div>

    <div class="sec-title"><span class="material-symbols-outlined" style="font-size:14px">monitor_heart</span>Registro objetivo de dor</div>
    <div class="grid grid-cols-2 gap-5 mb-4">
      <div><label class="label-sm">Dor antes (0–10)</label>
        <div class="flex gap-1 flex-wrap">
          <?php for ($i=0;$i<=10;$i++): ?><label class="be-btn"><input type="radio" name="dor_antes" value="<?= $i ?>"><?= $i ?></label><?php endfor; ?>
        </div></div>
      <div><label class="label-sm">Dor depois (0–10)</label>
        <div class="flex gap-1 flex-wrap">
          <?php for ($i=0;$i<=10;$i++): ?><label class="be-btn"><input type="radio" name="dor_depois" value="<?= $i ?>"><?= $i ?></label><?php endfor; ?>
        </div></div>
    </div>

    <div class="sec-title"><span class="material-symbols-outlined" style="font-size:14px">health_and_safety</span>Sintomas atuais</div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">sick</span></span>
        <input type="text" name="desconforto" placeholder="Desconfortos relatados"/></div>
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">report</span></span>
        <input type="text" name="outros_sintomas" placeholder="Outros sintomas"/></div>
    </div>

    <div class="sec-title"><span class="material-symbols-outlined" style="font-size:14px">task_alt</span>Adesão às orientações</div>
    <div class="flex gap-2 flex-wrap mb-3">
      <?php foreach (['sim'=>'✅ Sim','parcial'=>'🟡 Parcial','nao'=>'❌ Não'] as $v=>$l): ?>
      <label class="pill-radio"><input type="radio" name="adesao" value="<?= $v ?>"><?= $l ?></label>
      <?php endforeach; ?>
    </div>
    <div class="campo mb-4"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">edit_note</span></span>
      <input type="text" name="dificuldades_adesao" placeholder="Dificuldades relatadas para cumprir as orientações"/></div>

    <div class="sec-title"><span class="material-symbols-outlined" style="font-size:14px">home</span>Impacto na rotina</div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">check_circle</span></span>
        <textarea name="melhora_rotina" rows="2" placeholder="Mudanças nas atividades diárias?"></textarea></div>
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">bedtime</span></span>
        <textarea name="sono_rotina" rows="2" placeholder="Sono e rotina alterados?"></textarea></div>
    </div>

    <div class="sec-title"><span class="material-symbols-outlined" style="font-size:14px">psychology</span>Observações clínicas</div>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">face</span></span>
        <input type="text" name="comportamento" placeholder="Comportamento"/></div>
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">handshake</span></span>
        <input type="text" name="cooperacao" placeholder="Cooperação"/></div>
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">warning</span></span>
        <input type="text" name="ansiedade_resistencia" placeholder="Ansiedade / resistência"/></div>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">healing</span></span>
        <textarea name="resposta_procedimento" rows="2" placeholder="Resposta ao procedimento desta sessão"></textarea></div>
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">accessibility_new</span></span>
        <textarea name="mudanca_funcional" rows="2" placeholder="Mudança funcional percebida"></textarea></div>
    </div>

    <div class="sec-title"><span class="material-symbols-outlined" style="font-size:14px">clinical_notes</span>Conduta da sessão <?= $sessao_num ?></div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">medical_services</span></span>
        <textarea name="procedimentos" rows="3" placeholder="Procedimentos realizados" required></textarea></div>
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">tune</span></span>
        <textarea name="ajustes_plano" rows="3" placeholder="Ajustes no plano terapêutico"></textarea></div>
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">assignment</span></span>
        <textarea name="orientacoes" rows="2" placeholder="Orientações para casa"></textarea></div>
      <div class="campo"><span class="ic"><span class="material-symbols-outlined" style="font-size:18px">event_upcoming</span></span>
        <textarea name="plano_proxima" rows="2" placeholder="Plano para a próxima sessão"></textarea></div>
    </div>
  </div>

  <div class="flex justify-end">
    <button type="submit" class="px-8 py-3.5 rounded-full bg-gradient-to-r from-purple-700 to-pink-600 text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all flex items-center gap-2">
      <span class="material-symbols-outlined text-sm">save</span>
      Salvar sessão <?= $sessao_num ?>
    </button>
  </div>
  <?php endif; ?>

  </form>
</main>

<script>
var stepAtual = 0;
var totalSteps = 3;

function irStep(n) {
  document.getElementById('step-'+stepAtual).classList.remove('active');
  document.getElementById('step-'+n).classList.add('active');

  // Atualiza dots
  for (var i=0; i<totalSteps; i++) {
    var dot = document.getElementById('sdot-'+i);
    var lbl = document.getElementById('slabel-'+i);
    if (!dot) continue;
    dot.className = 'step-dot ' + (i < n ? 'done' : (i===n ? 'active' : 'idle'));
    if (lbl) lbl.className = 'text-xs font-medium hidden sm:block ' + (i<=n ? 'text-primary' : 'text-outline');
    var line = document.getElementById('sline-'+i);
    if (line) line.className = 'flex-1 h-0.5 rounded-full ' + (i < n ? 'bg-primary' : 'bg-outline-variant');
  }
  stepAtual = n;
  window.scrollTo({top:0, behavior:'smooth'});
}
</script>
</body>
</html>