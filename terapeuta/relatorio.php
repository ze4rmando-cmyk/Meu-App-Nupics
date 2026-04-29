<?php
session_start();
if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['tipo'], ['terapeuta','coordenador'])) {
    header('Location: ../index.php'); exit;
}
require_once '../config/db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$ciclo_id = (int)($_GET['ciclo_id'] ?? 0);
if (!$ciclo_id) { echo "Ciclo não informado."; exit; }

// Dados do ciclo
$stmt = $pdo->prepare("
    SELECT c.id, c.total_sessoes, c.sessoes_realizadas, c.faltas, c.status,
           c.criado_em, c.encerrado_em, c.motivo_encerramento,
           u_pac.nome AS pac_nome, u_pac.email AS pac_email, u_pac.telefone AS pac_tel,
           p.data_nasc, p.sexo, p.vinculo, p.objetivos, p.observacao_clinica,
           u_ter.nome AS ter_nome, u_ter.email AS ter_email,
           t.especialidade, t.periodo,
           s.dia_semana, s.hora_inicio, s.hora_fim, s.local, s.praticas
    FROM ciclos c
    JOIN reservas r     ON c.reserva_id = r.id
    JOIN usuarios u_pac ON r.paciente_id = u_pac.id
    JOIN pacientes p    ON p.usuario_id  = u_pac.id
    JOIN slots s        ON r.slot_id     = s.id
    JOIN usuarios u_ter ON s.terapeuta_id= u_ter.id
    JOIN terapeutas t   ON t.usuario_id  = u_ter.id
    WHERE c.id = ?
");
$stmt->execute([$ciclo_id]);
$ciclo = $stmt->fetch();
if (!$ciclo) { echo "Ciclo não encontrado."; exit; }

// Verifica permissão: terapeuta só vê seus próprios ciclos
if ($_SESSION['tipo'] === 'terapeuta') {
    $meu = $pdo->prepare("SELECT id FROM terapeutas WHERE usuario_id=? LIMIT 1");
    $meu->execute([$_SESSION['usuario_id']]);
    $ter_row = $meu->fetch();
    // verifica se esse terapeuta é dono do ciclo via slot
    $chk = $pdo->prepare("SELECT 1 FROM ciclos c JOIN reservas r ON c.reserva_id=r.id JOIN slots s ON r.slot_id=s.id WHERE c.id=? AND s.terapeuta_id=?");
    $chk->execute([$ciclo_id, $_SESSION['usuario_id']]);
    if (!$chk->fetch()) { echo "Acesso negado."; exit; }
}

// Anamnese
$anm = $pdo->prepare("SELECT * FROM anamnese_inicial WHERE ciclo_id=? LIMIT 1");
$anm->execute([$ciclo_id]);
$anamnese = $anm->fetch();

// Registros de sessão
$reg = $pdo->prepare("
    SELECT * FROM registros_sessao WHERE ciclo_id=? ORDER BY data_sessao ASC
");
$reg->execute([$ciclo_id]);
$sessoes = $reg->fetchAll();

// Relatório final (se existir)
$rel = $pdo->prepare("SELECT * FROM relatorios_ciclo WHERE ciclo_id=? LIMIT 1");
$rel->execute([$ciclo_id]);
$relatorio = $rel->fetch();

$dias_full = [1=>'Segunda',2=>'Terça',3=>'Quarta',4=>'Quinta',5=>'Sexta'];
$idade = $ciclo['data_nasc'] ? floor((time()-strtotime($ciclo['data_nasc']))/31557600) : null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Relatório — Ciclo #<?= $ciclo_id ?> · NUPICS</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@400,0&display=swap" rel="stylesheet"/>
<script>tailwind.config={theme:{extend:{colors:{
  "primary":"#4e0078","secondary":"#b7004d","surface":"#fff7fc",
  "surface-container":"#f7eaf8","outline-variant":"#d0c2d3","on-surface-variant":"#4d4351"
},fontFamily:{"headline":["Plus Jakarta Sans"],"body":["Manrope"]}}}}</script>
<style>
  body{font-family:"Manrope",sans-serif;background:radial-gradient(135deg,#f4d9ff 0%,#fff7fc 45%,#fdeffe 100%)}
  h1,h2,h3{font-family:"Plus Jakarta Sans",sans-serif}
  .glass{background:rgba(255,255,255,.82);backdrop-filter:blur(18px);border:1px solid rgba(255,255,255,.5)}
  .sec-title{font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#7f7383;margin-bottom:.75rem;display:flex;align-items:center;gap:8px}
  .sec-title::after{content:'';flex:1;height:1px;background:#e8dce9}
  .campo-row{display:flex;gap:6px;align-items:flex-start;padding:6px 0;border-bottom:1px solid #f0e8f1}
  .campo-row:last-child{border-bottom:none}
  .campo-label{font-size:.72rem;font-weight:700;color:#7f7383;min-width:140px;padding-top:2px}
  .campo-val{font-size:.82rem;color:#201923;flex:1;line-height:1.5}
  @media print{
    .no-print{display:none!important}
    body{background:white}
    .glass{background:white;border:1px solid #ddd;box-shadow:none;backdrop-filter:none}
    main{padding-top:0!important}
  }
</style>
</head>
<body class="min-h-screen">

<!-- Header -->
<header class="no-print fixed top-0 w-full z-50 bg-white/70 backdrop-blur-md shadow-sm">
  <div class="max-w-4xl mx-auto px-4 md:px-6 h-14 flex items-center justify-between">
    <div class="flex items-center gap-3">
      <span class="text-lg font-extrabold bg-gradient-to-r from-purple-700 to-pink-600 bg-clip-text text-transparent">NUPICS</span>
      <span class="text-xs font-bold text-on-surface-variant border border-outline-variant rounded-full px-2 py-0.5 uppercase tracking-widest">Relatório</span>
    </div>
    <div class="flex items-center gap-2">
      <button onclick="window.print()" class="flex items-center gap-1.5 px-4 py-2 rounded-full bg-primary text-white text-xs font-bold hover:opacity-90">
        <span class="material-symbols-outlined text-sm">print</span>Imprimir / PDF
      </button>
      <a href="javascript:history.back()" class="text-xs font-bold text-on-surface-variant hover:text-primary">← Voltar</a>
    </div>
  </div>
</header>

<main class="pt-20 pb-16 px-4 md:px-6 max-w-4xl mx-auto">

  <!-- Cabeçalho do relatório -->
  <div class="glass rounded-3xl p-8 mb-6 border border-outline-variant/20 print:rounded-none print:shadow-none">
    <div class="flex items-start justify-between gap-4 mb-6">
      <div>
        <p class="text-xs font-bold text-primary/60 uppercase tracking-widest mb-1">Relatório de Ciclo Terapêutico</p>
        <h1 class="text-2xl font-extrabold text-primary">Ciclo #<?= $ciclo_id ?></h1>
        <p class="text-sm text-on-surface-variant mt-0.5">Núcleo de Práticas Integrativas — UERN Caicó</p>
      </div>
      <span class="text-xs font-bold px-3 py-1.5 rounded-full <?= $ciclo['status']==='concluido'?'bg-indigo-100 text-indigo-700':($ciclo['status']==='ativo'?'bg-primary/10 text-primary':'bg-red-100 text-red-700') ?>">
        <?= ucfirst($ciclo['status']) ?>
      </span>
    </div>

    <!-- Grid de info principal -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <div>
        <p class="sec-title"><span class="material-symbols-outlined text-sm">person</span>Paciente</p>
        <div>
          <div class="campo-row"><span class="campo-label">Nome</span><span class="campo-val font-bold"><?= htmlspecialchars($ciclo['pac_nome']) ?></span></div>
          <?php if ($ciclo['pac_email']): ?><div class="campo-row"><span class="campo-label">E-mail</span><span class="campo-val"><?= htmlspecialchars($ciclo['pac_email']) ?></span></div><?php endif; ?>
          <?php if ($ciclo['pac_tel']): ?><div class="campo-row"><span class="campo-label">Telefone</span><span class="campo-val"><?= htmlspecialchars($ciclo['pac_tel']) ?></span></div><?php endif; ?>
          <?php if ($idade): ?><div class="campo-row"><span class="campo-label">Idade</span><span class="campo-val"><?= $idade ?> anos</span></div><?php endif; ?>
          <?php if ($ciclo['sexo']): ?><div class="campo-row"><span class="campo-label">Sexo</span><span class="campo-val"><?= htmlspecialchars($ciclo['sexo']) ?></span></div><?php endif; ?>
          <?php if ($ciclo['vinculo']): ?><div class="campo-row"><span class="campo-label">Vínculo</span><span class="campo-val"><?= ucfirst($ciclo['vinculo']) ?></span></div><?php endif; ?>
        </div>
      </div>
      <div>
        <p class="sec-title"><span class="material-symbols-outlined text-sm">medical_services</span>Atendimento</p>
        <div>
          <div class="campo-row"><span class="campo-label">Terapeuta</span><span class="campo-val font-bold"><?= htmlspecialchars($ciclo['ter_nome']) ?></span></div>
          <div class="campo-row"><span class="campo-label">Especialidade</span><span class="campo-val"><?= htmlspecialchars($ciclo['especialidade']) ?></span></div>
          <div class="campo-row"><span class="campo-label">Dia / Horário</span><span class="campo-val"><?= $dias_full[(int)$ciclo['dia_semana']] ?> · <?= substr($ciclo['hora_inicio'],0,5) ?> – <?= substr($ciclo['hora_fim'],0,5) ?></span></div>
          <?php if ($ciclo['local']): ?><div class="campo-row"><span class="campo-label">Local</span><span class="campo-val"><?= htmlspecialchars($ciclo['local']) ?></span></div><?php endif; ?>
          <?php if ($ciclo['praticas']): ?><div class="campo-row"><span class="campo-label">Práticas</span><span class="campo-val"><?= htmlspecialchars($ciclo['praticas']) ?></span></div><?php endif; ?>
          <div class="campo-row"><span class="campo-label">Início do ciclo</span><span class="campo-val"><?= $ciclo['criado_em'] ? date('d/m/Y',strtotime($ciclo['criado_em'])) : '—' ?></span></div>
          <?php if ($ciclo['encerrado_em']): ?><div class="campo-row"><span class="campo-label">Encerramento</span><span class="campo-val"><?= date('d/m/Y',strtotime($ciclo['encerrado_em'])) ?></span></div><?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Progresso -->
    <div class="mt-6 flex flex-wrap gap-6">
      <?php foreach ([
        ['Sessões previstas', $ciclo['total_sessoes'],     'text-primary',  'bg-primary/8'],
        ['Realizadas',        $ciclo['sessoes_realizadas'],'text-emerald-700','bg-emerald-50'],
        ['Faltas',            $ciclo['faltas'],            'text-red-600',  'bg-red-50'],
      ] as [$l,$v,$tc,$bc]): ?>
      <div class="<?= $bc ?> rounded-2xl px-5 py-4 text-center">
        <p class="text-2xl font-extrabold <?= $tc ?>"><?= $v ?></p>
        <p class="text-xs text-on-surface-variant mt-0.5"><?= $l ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Anamnese -->
  <?php if ($anamnese): ?>
  <div class="glass rounded-3xl p-7 mb-6 border border-outline-variant/20">
    <p class="sec-title"><span class="material-symbols-outlined text-sm">assignment</span>Sessão 1 — Anamnese inicial</p>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8">
      <?php
      $campos_anm = [
        'motivo_procura'     => 'Motivo da procura',
        'historico_saude'    => 'Histórico de saúde',
        'medicamentos_uso'   => 'Medicamentos em uso',
        'alergias'           => 'Alergias',
        'habitos_vida'       => 'Hábitos de vida',
        'expectativas'       => 'Expectativas',
        'nivel_dor'          => 'Nível de dor (0-10)',
        'qualidade_sono'     => 'Qualidade do sono',
        'observacoes'        => 'Observações',
      ];
      foreach ($campos_anm as $col => $label):
        if (isset($anamnese[$col]) && $anamnese[$col] !== null && $anamnese[$col] !== ''): ?>
      <div class="campo-row"><span class="campo-label"><?= $label ?></span><span class="campo-val"><?= htmlspecialchars($anamnese[$col]) ?></span></div>
      <?php endif; endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Sessões de acompanhamento -->
  <?php if (!empty($sessoes)): ?>
  <div class="glass rounded-3xl p-7 mb-6 border border-outline-variant/20">
    <p class="sec-title"><span class="material-symbols-outlined text-sm">event_repeat</span>Acompanhamentos (<?= count($sessoes) ?>)</p>
    <div class="space-y-4">
      <?php foreach ($sessoes as $i => $s):
        $realizada = $s['status'] === 'realizado';
        $faltou    = $s['status'] === 'faltou';
      ?>
      <div class="rounded-2xl border <?= $realizada ? 'border-indigo-200/60 bg-indigo-50/40' : 'border-red-200/60 bg-red-50/40' ?> p-4">
        <div class="flex items-center justify-between mb-2">
          <p class="text-sm font-bold text-on-surface">
            Sessão <?= $i+2 ?> <?php if ($s['data_sessao']): ?>· <?= date('d/m/Y',strtotime($s['data_sessao'])) ?><?php endif; ?>
          </p>
          <span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?= $realizada ? 'bg-indigo-100 text-indigo-700' : 'bg-red-100 text-red-700' ?>">
            <?= $realizada ? 'Realizada' : 'Faltou' ?>
          </span>
        </div>
        <?php if ($realizada): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 text-xs text-on-surface-variant">
          <?php if ($s['evolucao']): ?><div class="campo-row"><span class="campo-label">Evolução</span><span class="campo-val"><?= htmlspecialchars($s['evolucao']) ?></span></div><?php endif; ?>
          <?php if ($s['praticas_realizadas']): ?><div class="campo-row"><span class="campo-label">Práticas</span><span class="campo-val"><?= htmlspecialchars($s['praticas_realizadas']) ?></span></div><?php endif; ?>
          <?php if ($s['nivel_dor_pos']): ?><div class="campo-row"><span class="campo-label">Dor (pós)</span><span class="campo-val"><?= $s['nivel_dor_pos'] ?>/10</span></div><?php endif; ?>
          <?php if ($s['observacoes']): ?><div class="campo-row"><span class="campo-label">Observações</span><span class="campo-val"><?= htmlspecialchars($s['observacoes']) ?></span></div><?php endif; ?>
        </div>
        <?php else: ?>
        <?php if (!empty($s['motivo_falta'])): ?><p class="text-xs text-red-700">Motivo: <?= htmlspecialchars($s['motivo_falta']) ?></p><?php endif; ?>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Relatório final -->
  <?php if ($relatorio): ?>
  <div class="glass rounded-3xl p-7 mb-6 border border-outline-variant/20">
    <p class="sec-title"><span class="material-symbols-outlined text-sm">summarize</span>Relatório final</p>
    <?php
    $campos_rel = [
      'sintese_evolucao'   => 'Síntese da evolução',
      'objetivos_atingidos'=> 'Objetivos atingidos',
      'intercorrencias'    => 'Intercorrências',
      'recomendacoes'      => 'Recomendações',
      'conclusao'          => 'Conclusão',
    ];
    foreach ($campos_rel as $col => $label):
      if (!empty($relatorio[$col])): ?>
    <div class="campo-row mb-2"><span class="campo-label"><?= $label ?></span><span class="campo-val"><?= nl2br(htmlspecialchars($relatorio[$col])) ?></span></div>
    <?php endif; endforeach; ?>
  </div>
  <?php else: ?>
  <!-- Form de relatório final (terapeuta preenche) -->
  <?php if ($_SESSION['tipo'] === 'terapeuta' && $ciclo['status'] !== 'ativo'): ?>
  <div class="glass rounded-3xl p-7 mb-6 border border-outline-variant/20">
    <p class="sec-title"><span class="material-symbols-outlined text-sm">edit_note</span>Relatório final — preencher</p>
    <form id="form-relatorio" class="space-y-4">
      <input type="hidden" name="ciclo_id" value="<?= $ciclo_id ?>">
      <?php
      $campos_rel = [
        'sintese_evolucao'    => ['Síntese da evolução',     4],
        'objetivos_atingidos' => ['Objetivos atingidos',     3],
        'intercorrencias'     => ['Intercorrências',         2],
        'recomendacoes'       => ['Recomendações',           3],
        'conclusao'           => ['Conclusão',               3],
      ];
      foreach ($campos_rel as $name => [$label, $rows]): ?>
      <div>
        <label class="block text-xs font-bold uppercase text-on-surface/60 mb-1.5"><?= $label ?></label>
        <textarea name="<?= $name ?>" rows="<?= $rows ?>" placeholder="..."
          class="w-full border border-outline-variant/60 rounded-2xl px-4 py-3 text-sm bg-white/70 focus:border-primary focus:ring-2 focus:ring-primary/20 resize-none font-body"></textarea>
      </div>
      <?php endforeach; ?>
      <button type="submit" class="px-6 py-3 rounded-full bg-primary text-white text-sm font-bold hover:opacity-90 active:scale-95 transition-all">
        Salvar relatório final
      </button>
    </form>
  </div>
  <script>
  document.getElementById('form-relatorio').addEventListener('submit', async function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('acao', 'salvar_relatorio');
    const r = await fetch('../api/ciclo_action.php', {method:'POST', body:fd});
    const d = await r.json();
    if (d.ok) { alert('Relatório salvo!'); location.reload(); }
    else alert(d.msg || 'Erro ao salvar.');
  });
  </script>
  <?php endif; ?>
  <?php endif; ?>

  <!-- Rodapé impresso -->
  <div class="text-center text-xs text-on-surface-variant mt-8 print:block">
    <p>NUPICS — Núcleo de Práticas Integrativas e Complementares em Saúde · UERN Caicó</p>
    <p>Relatório gerado em <?= date('d/m/Y \à\s H:i') ?></p>
  </div>

</main>
</body>
</html>