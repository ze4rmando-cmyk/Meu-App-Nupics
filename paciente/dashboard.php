<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'paciente') {
    header('Location: ../login.php'); exit;
}
require_once '../config/db.php';

$uid          = (int)$_SESSION['usuario_id'];
$nome         = $_SESSION['nome'];
$primeiro     = explode(' ', trim($nome))[0];

// ── Dados do paciente ────────────────────────────────────────────────────────
$pac = $pdo->prepare("
    SELECT p.*, u.telefone, u.email
    FROM pacientes p JOIN usuarios u ON p.usuario_id = u.id
    WHERE p.usuario_id = ?
");
$pac->execute([$uid]); $paciente = $pac->fetch(PDO::FETCH_ASSOC);

$bem_estar = null;
if ($paciente && preg_match('/Bem-estar atual: (\d+)\/10/', $paciente['observacao_clinica'] ?? '', $m))
    $bem_estar = (int)$m[1];

// ── Reservas ─────────────────────────────────────────────────────────────────
$res = $pdo->prepare("
    SELECT r.id, r.status, r.data_sessao, r.queixas,
           r.telefone_contato, r.observacao, r.criado_em,
           s.hora_inicio, s.hora_fim, s.local, s.praticas, s.dia_semana,
           u.nome AS terapeuta_nome, t.especialidade
    FROM reservas r
    JOIN slots s    ON r.slot_id      = s.id
    JOIN usuarios u ON s.terapeuta_id = u.id
    LEFT JOIN terapeutas t ON t.usuario_id = u.id
    WHERE r.paciente_id = ?
    ORDER BY r.data_sessao DESC, s.hora_inicio DESC
");
$res->execute([$uid]); $reservas = $res->fetchAll(PDO::FETCH_ASSOC);

$total      = count($reservas);
$pendentes  = count(array_filter($reservas, fn($r) => $r['status'] === 'pendente'));
$proximas   = count(array_filter($reservas, fn($r) => $r['status'] === 'confirmado' && $r['data_sessao'] >= date('Y-m-d')));
$concluidas = count(array_filter($reservas, fn($r) => $r['status'] === 'concluido'));

$proxima = null;
foreach ($reservas as $r) {
    if ($r['status'] === 'confirmado' && $r['data_sessao'] >= date('Y-m-d')) { $proxima = $r; break; }
}

// ── Fila de espera ────────────────────────────────────────────────────────────
$filas = $pdo->prepare("
    SELECT fe.posicao, fe.data_sessao, fe.status,
           s.hora_inicio, s.hora_fim, s.dia_semana, s.local,
           u.nome AS terapeuta_nome
    FROM fila_espera fe
    JOIN slots s    ON fe.slot_id     = s.id
    JOIN usuarios u ON s.terapeuta_id = u.id
    WHERE fe.paciente_id = ? AND fe.status IN ('aguardando','notificado')
    ORDER BY fe.criado_em ASC
");
$filas->execute([$uid]); $filas = $filas->fetchAll(PDO::FETCH_ASSOC);

// ── Frase do dia (aleatória para pacientes) ───────────────────────────────────
$frase_row = $pdo->query("
    SELECT texto, autor FROM frases WHERE tipo='paciente' AND ativo=1 ORDER BY RAND() LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
$frase = $frase_row ? $frase_row['texto'] : '"Cuidar de você também é prioridade."';
$frase_autor = $frase_row['autor'] ?? null;

// ── Playlists ─────────────────────────────────────────────────────────────────
$playlists = $pdo->query("
    SELECT id, emoji, nome, url FROM playlists WHERE ativo=1 ORDER BY ordem ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Avisos da coordenação (ativos) ────────────────────────────────────────────
$avisos = $pdo->query("
    SELECT id, tipo, titulo, texto, criado_em
    FROM avisos WHERE ativo=1 ORDER BY criado_em DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ── Minhas sugestões (últimas 3) ──────────────────────────────────────────────
$minhas_sug = $pdo->prepare("
    SELECT id, tipo, mensagem, lida, resposta, respondido_em, criado_em
    FROM sugestoes WHERE paciente_id = ? ORDER BY criado_em DESC LIMIT 3
");
$minhas_sug->execute([$uid]); $minhas_sug = $minhas_sug->fetchAll(PDO::FETCH_ASSOC);

$dias_nomes   = [1=>'Segunda',2=>'Terça',3=>'Quarta',4=>'Quinta',5=>'Sexta'];
$status_label = ['pendente'=>'Aguardando','confirmado'=>'Confirmado','cancelado'=>'Cancelado','concluido'=>'Concluído'];

// ── Recomendações por humor ───────────────────────────────────────────────────
$recomendacoes = [
    'bem'           => ['titulo'=>'Meditação da Presença','desc'=>'Aproveite este momento positivo com 10 min de atenção plena.','icon'=>'self_improvement','cor'=>'text-emerald-600','bg'=>'bg-emerald-50'],
    'neutro'        => ['titulo'=>'Respiração Guiada','desc'=>'Equilibre sua energia com uma técnica simples de respiração.','icon'=>'air','cor'=>'text-blue-600','bg'=>'bg-blue-50'],
    'cansado'       => ['titulo'=>'Escalda-pés Relaxante','desc'=>'Um ritual ancestral para descarregar tensões e preparar o sono.','icon'=>'spa','cor'=>'text-purple-600','bg'=>'bg-purple-50'],
    'ansioso'       => ['titulo'=>'Respiração 4-7-8','desc'=>'Para acalmar a mente ansiosa. Inspire 4s, segure 7s, expire 8s.','icon'=>'wind_power','cor'=>'text-pink-600','bg'=>'bg-pink-50'],
    'sobrecarregado'=> ['titulo'=>'Pausa Restauradora','desc'=>'5 minutos de silêncio consciente podem mudar seu dia.','icon'=>'battery_charging_full','cor'=>'text-amber-600','bg'=>'bg-amber-50'],
    'triste'        => ['titulo'=>'Automassagem nos Pés','desc'=>'Técnica simples que estimula pontos de bem-estar emocional.','icon'=>'favorite','cor'=>'text-rose-600','bg'=>'bg-rose-50'],
];

// Guias de cada prática (texto + playlist sugerida)
$guias = [
    'bem'           => 'Sente-se confortavelmente. Feche os olhos. Observe sua respiração natural por 10 minutos, sem tentar controlá-la. Simplesmente observe. Quando a mente dispersar, gentilmente retorne à respiração.',
    'neutro'        => 'Inspire pelo nariz contando até 4. Segure o ar contando até 2. Expire pela boca contando até 6. Repita 8 vezes. Faça isso 3 vezes ao dia para manter o equilíbrio.',
    'cansado'       => 'Coloque os pés numa bacia com água morna (38–40°C). Adicione 2 col. de sal grosso e algumas gotas de lavanda. Fique 20 minutos com os olhos fechados. Seque bem ao finalizar.',
    'ansioso'       => 'Inspire pelo nariz contando até 4. Segure o ar contando até 7. Expire pela boca contando até 8. Repita 4 vezes. Esta técnica ativa o sistema nervoso parassimpático em minutos.',
    'sobrecarregado'=> 'Pare o que está fazendo. Olhe ao redor e nomeie 5 coisas que vê, 4 que pode tocar, 3 que ouve, 2 que cheira, 1 que saboreia. Esta técnica de grounding traz você de volta ao presente.',
    'triste'        => 'Com as mãos aquecidas, massageie a sola dos pés com movimentos circulares do calcanhar até os dedos. Aplique pressão nos pontos centrais. Faça por 5 minutos em cada pé. Respire profundamente.',
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>NUPICS | Meu Painel</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script>
  tailwind.config = {
    darkMode:"class",
    theme:{extend:{
      colors:{
        "surface":"#fff7fc","on-surface":"#201923",
        "surface-container-low":"#fdeffe","surface-container":"#f7eaf8",
        "surface-container-high":"#f2e4f2","surface-container-highest":"#ecdeed",
        "surface-container-lowest":"#ffffff","on-surface-variant":"#4d4351",
        "outline-variant":"#d0c2d3","outline":"#7f7383",
        "primary":"#4e0078","on-primary":"#ffffff","primary-container":"#6a1b9a",
        "secondary":"#b7004d","on-secondary":"#ffffff","secondary-fixed":"#ffd9de",
        "tertiary-fixed":"#f4dce4","on-tertiary-fixed":"#25181e",
        "error":"#ba1a1a","error-container":"#ffdad6","on-error-container":"#93000a",
        "background":"#fff7fc","on-background":"#201923"
      },
      fontFamily:{"headline":["Plus Jakarta Sans"],"body":["Manrope"]}
    }}
  }
</script>
<style>
  body { font-family:"Manrope",sans-serif; background:radial-gradient(circle at top left,#f7eaf8,#fff7fc); }
  h1,h2,h3,h4 { font-family:"Plus Jakarta Sans",sans-serif }
  .material-symbols-outlined { font-variation-settings:"FILL" 0,"wght" 400,"GRAD" 0,"opsz" 24 }
  .glass { background:rgba(255,255,255,.72); backdrop-filter:blur(16px) saturate(180%);
           -webkit-backdrop-filter:blur(16px) saturate(180%); border:1px solid rgba(255,255,255,.45); }
  .modal-wrap { display:none; }
  .modal-wrap.open { display:flex; animation:mfade .18s ease; }
  @keyframes mfade { from{opacity:0} to{opacity:1} }
  .modal-card { animation:mup .22s cubic-bezier(.22,1,.36,1); }
  @keyframes mup { from{opacity:0;transform:translateY(22px)} to{opacity:1;transform:translateY(0)} }
  .card-item { transition:box-shadow .15s,transform .15s; cursor:pointer; }
  .card-item:hover { transform:translateY(-2px); box-shadow:0 8px 28px rgba(78,0,120,.10); }
  .tab-btn { border-bottom:2px solid transparent; transition:color .15s,border-color .15s; }
  .tab-btn.active { color:#4e0078; border-bottom-color:#4e0078; }
  .humor-btn .ring-sel { display:none; }
  .humor-btn.selecionado .ring-sel { display:block; }
  .humor-btn.selecionado .ic-wrap { background:rgba(78,0,120,.12); }
  .s-pendente   { background:#f4d9ff; color:#4e0078; }
  .s-confirmado { background:#d1fae5; color:#065f46; }
  .s-cancelado  { background:#ffdad6; color:#93000a; }
  .s-concluido  { background:#e0e7ff; color:#3730a3; }
  .aviso-evento    { border-left:3px solid #4e0078; }
  .aviso-urgente   { border-left:3px solid #b7004d; }
  .aviso-manutencao{ border-left:3px solid #92400e; }
  .aviso-info      { border-left:3px solid #1d4ed8; }
  textarea:focus,input:focus,select:focus { outline:none; box-shadow:0 0 0 3px rgba(78,0,120,.15); }
  @keyframes softpulse { 0%,100%{box-shadow:0 0 0 0 rgba(78,0,120,.15)} 50%{box-shadow:0 0 0 8px rgba(78,0,120,0)} }
  .proxima-pulse { animation:softpulse 3s ease-in-out infinite; }
</style>
</head>
<body class="text-on-background min-h-screen">

<!-- Nav -->
<nav class="fixed top-0 w-full z-50 bg-white/60 backdrop-blur-md shadow-[0_4px_24px_rgba(32,25,35,.06)]">
  <div class="flex justify-between items-center px-6 md:px-10 py-4 max-w-7xl mx-auto">
    <span class="text-xl font-bold bg-gradient-to-r from-purple-700 to-pink-600 bg-clip-text text-transparent font-['Plus_Jakarta_Sans']">NUPICS</span>
    <div class="hidden md:flex items-center gap-8 font-['Plus_Jakarta_Sans'] font-medium text-sm">
      <span class="text-primary border-b-2 border-primary pb-0.5">Início</span>
      <a href="agendar.php" class="text-on-surface-variant hover:text-primary transition-colors">Agendar Sessão</a>
      <a href="../api/trocar_senha.php" class="text-on-surface-variant hover:text-primary transition-colors font-semibold flex items-center gap-1">
        <span class="material-symbols-outlined" style="font-size:16px">key</span>Senha
      </a>
      <a href="../logout.php" class="text-on-surface-variant hover:text-secondary transition-colors font-semibold">Sair</a>
    </div>
    <button id="mob-btn" class="md:hidden text-primary">
      <span class="material-symbols-outlined">menu</span>
    </button>
  </div>
  <div id="mob-menu" class="hidden md:hidden bg-white/90 backdrop-blur-md border-t border-outline-variant/20 px-6 py-4 space-y-3">
    <a href="agendar.php" class="block text-sm font-medium text-on-surface-variant hover:text-primary">Agendar Sessão</a>
    <a href="../logout.php" class="block text-sm font-semibold text-secondary">Sair</a>
  </div>
</nav>

<main class="pt-28 pb-20 px-4 md:px-8 max-w-7xl mx-auto space-y-12">

  <!-- ── Cabeçalho ── -->
  <section class="grid grid-cols-1 md:grid-cols-12 gap-6 items-end">
    <div class="md:col-span-8 space-y-3">
      <div class="flex items-center gap-4 mb-2">
        <?php
          $stmt_foto = $pdo->prepare("SELECT foto FROM usuarios WHERE id=? LIMIT 1");
          $stmt_foto->execute([$uid]);
          $foto_pac = $stmt_foto->fetchColumn();
        ?>
        <div class="avatar-upload shrink-0" title="Clique para trocar sua foto">
          <?php if ($foto_pac): ?>
            <img id="img-pac-av" src="../<?= htmlspecialchars($foto_pac) ?>?v=<?= time() ?>" alt="Foto" class="w-14 h-14" style="border-radius:9999px;object-fit:cover;"/>
          <?php else: ?>
            <div id="img-pac-ph" class="w-14 h-14 rounded-full bg-primary/10 flex items-center justify-center text-2xl font-extrabold text-primary">
              <?= mb_substr($primeiro, 0, 1) ?>
            </div>
          <?php endif; ?>
          <div class="avatar-overlay">
            <span class="material-symbols-outlined" style="font-size:18px">photo_camera</span>
          </div>
          <input type="file" accept="image/jpeg,image/png,image/webp"
            onchange="uploadImagem(this,'perfil',{},function(url){
              let img=document.getElementById('img-pac-av');
              let ph=document.getElementById('img-pac-ph');
              if(!img){img=document.createElement('img');img.id='img-pac-av';img.className='w-14 h-14';img.style.cssText='border-radius:9999px;object-fit:cover;';this.closest('.avatar-upload').prepend(img);if(ph)ph.remove();}
              img.src=url;
            }.bind(this))"/>
        </div>
        <h1 class="text-4xl md:text-5xl font-extrabold text-primary tracking-tight">
          Olá, <?= htmlspecialchars($primeiro) ?> 👋
        </h1>
      </div>
      <p class="text-lg text-on-surface-variant max-w-xl">
        <?= htmlspecialchars(strip_tags($frase)) ?>
        <?php if ($frase_autor): ?><span class="text-sm text-outline"> — <?= htmlspecialchars($frase_autor) ?></span><?php endif; ?>
      </p>
    </div>
    <!-- Próxima sessão resumida no header (só se existir) -->
    <?php if ($proxima):
      $phi = substr($proxima['hora_inicio'],0,5);
      $diasAte = (int)floor((strtotime($proxima['data_sessao']) - strtotime(date('Y-m-d'))) / 86400);
      $quando = $diasAte === 0 ? 'Hoje' : ($diasAte === 1 ? 'Amanhã' : "Em {$diasAte} dias");
    ?>
    <div class="md:col-span-4">
      <div class="glass p-5 rounded-2xl flex items-center gap-4 border border-primary/10">
        <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center shrink-0">
          <span class="material-symbols-outlined text-primary">calendar_today</span>
        </div>
        <div>
          <p class="text-xs font-bold uppercase tracking-widest text-on-surface-variant mb-0.5">Próximo atendimento</p>
          <p class="font-bold text-primary text-sm"><?= htmlspecialchars($proxima['terapeuta_nome']) ?></p>
          <p class="text-xs text-secondary font-semibold"><?= $quando ?> às <?= $phi ?></p>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </section>

  <!-- ── Stats ── -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <div class="glass rounded-2xl p-5 border-l-4 border-l-primary">
      <p class="text-xs font-bold uppercase tracking-widest text-primary/60 mb-1">Total</p>
      <p class="text-3xl font-extrabold text-primary"><?= $total ?></p>
      <p class="text-xs text-on-surface-variant mt-0.5">sessões</p>
    </div>
    <div class="glass rounded-2xl p-5 border-l-4 border-l-emerald-500">
      <p class="text-xs font-bold uppercase tracking-widest text-emerald-700/60 mb-1">Confirmadas</p>
      <p class="text-3xl font-extrabold text-emerald-700"><?= $proximas ?></p>
      <p class="text-xs text-on-surface-variant mt-0.5">aguardando</p>
    </div>
    <div class="glass rounded-2xl p-5 border-l-4 border-l-purple-400">
      <p class="text-xs font-bold uppercase tracking-widest text-purple-700/60 mb-1">Pendentes</p>
      <p class="text-3xl font-extrabold text-purple-700"><?= $pendentes ?></p>
      <p class="text-xs text-on-surface-variant mt-0.5">em análise</p>
    </div>
    <div class="glass rounded-2xl p-5 border-l-4 border-l-indigo-400">
      <p class="text-xs font-bold uppercase tracking-widest text-indigo-700/60 mb-1">Concluídas</p>
      <p class="text-3xl font-extrabold text-indigo-700"><?= $concluidas ?></p>
      <p class="text-xs text-on-surface-variant mt-0.5">realizadas</p>
    </div>
  </div>

  <!-- ── Grid principal ── -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

    <!-- COLUNA ESQUERDA + CENTRO (2/3) -->
    <div class="lg:col-span-2 space-y-8">

      <!-- Próxima sessão detalhada -->
      <?php if ($proxima):
        $d    = (int)$proxima['dia_semana'];
        $hi   = substr($proxima['hora_inicio'],0,5);
        $hf   = substr($proxima['hora_fim'],0,5);
        $dt   = date('d/m/Y', strtotime($proxima['data_sessao']));
        $diasAte = (int)floor((strtotime($proxima['data_sessao']) - strtotime(date('Y-m-d'))) / 86400);
        $praticasArr = array_map('trim', explode(',', $proxima['praticas'] ?? ''));
      ?>
      <div class="proxima-pulse glass rounded-3xl p-6 bg-gradient-to-br from-primary/5 to-secondary/5 border-2 border-primary/15 relative overflow-hidden">
        <div class="absolute -right-8 -top-8 w-40 h-40 bg-primary/8 rounded-full blur-2xl pointer-events-none"></div>
        <div class="relative z-10">
          <div class="flex items-center justify-between mb-5">
            <div>
              <p class="text-xs font-bold uppercase tracking-widest text-primary/50 mb-1">Próxima sessão</p>
              <h2 class="text-xl font-extrabold text-primary"><?= $dias_nomes[$d] ?>, <?= $dt ?></h2>
            </div>
            <?php
            if ($diasAte===0)     echo '<span class="px-3 py-1.5 rounded-full bg-emerald-100 text-emerald-700 text-xs font-bold">Hoje!</span>';
            elseif ($diasAte===1) echo '<span class="px-3 py-1.5 rounded-full bg-amber-100 text-amber-700 text-xs font-bold">Amanhã</span>';
            else                  echo "<span class='px-3 py-1.5 rounded-full bg-primary/10 text-primary text-xs font-bold'>Em {$diasAte} dias</span>";
            ?>
          </div>
          <div class="grid grid-cols-2 gap-3 mb-4">
            <div class="flex items-center gap-2 bg-white/50 rounded-xl px-3 py-2.5 border border-white/60">
              <span class="material-symbols-outlined text-secondary text-lg shrink-0">schedule</span>
              <div><p class="text-[10px] font-bold uppercase text-on-surface-variant">Horário</p>
                   <p class="font-bold text-sm text-on-surface"><?= $hi ?> – <?= $hf ?></p></div>
            </div>
            <div class="flex items-center gap-2 bg-white/50 rounded-xl px-3 py-2.5 border border-white/60">
              <span class="material-symbols-outlined text-secondary text-lg shrink-0">person</span>
              <div><p class="text-[10px] font-bold uppercase text-on-surface-variant">Terapeuta</p>
                   <p class="font-bold text-sm text-on-surface truncate"><?= htmlspecialchars($proxima['terapeuta_nome']) ?></p></div>
            </div>
            <div class="flex items-center gap-2 bg-white/50 rounded-xl px-3 py-2.5 border border-white/60 col-span-2">
              <span class="material-symbols-outlined text-secondary text-lg shrink-0">location_on</span>
              <div><p class="text-[10px] font-bold uppercase text-on-surface-variant">Local</p>
                   <p class="font-bold text-sm text-on-surface"><?= htmlspecialchars($proxima['local'] ?? 'A definir') ?></p></div>
            </div>
          </div>
          <div class="flex flex-wrap gap-2 mb-4">
            <?php foreach ($praticasArr as $p): if (!$p) continue; ?>
            <span class="px-3 py-1 rounded-full bg-primary/10 text-primary text-xs font-bold"><?= htmlspecialchars($p) ?></span>
            <?php endforeach; ?>
          </div>
          <button onclick="abrirDetalhe(<?= htmlspecialchars(json_encode($proxima), ENT_QUOTES) ?>)"
            class="w-full py-3 rounded-full border-2 border-primary/30 text-primary font-bold text-sm hover:bg-primary/5 transition-all flex items-center justify-center gap-2">
            <span class="material-symbols-outlined text-sm">info</span>Ver detalhes / cancelar
          </button>
        </div>
      </div>
      <?php else: ?>
      <div class="glass rounded-3xl p-10 text-center border border-outline-variant/20">
        <span class="material-symbols-outlined text-5xl text-outline-variant mb-3 block">event_note</span>
        <h3 class="font-headline font-bold text-on-surface text-lg mb-2">Nenhuma sessão agendada</h3>
        <p class="text-sm text-on-surface-variant mb-5">Agende sua próxima sessão de práticas integrativas.</p>
        <a href="agendar.php" class="inline-flex items-center gap-2 px-6 py-3 rounded-full bg-primary text-white font-bold text-sm hover:opacity-90 transition-all shadow-lg shadow-primary/25">
          <span class="material-symbols-outlined text-sm">add_circle</span>Agendar agora
        </a>
      </div>
      <?php endif; ?>

      <!-- Fila de espera -->
      <?php if (!empty($filas)): ?>
      <div class="glass rounded-2xl p-5 border border-amber-200/60">
        <div class="flex items-center gap-2 mb-3">
          <span class="material-symbols-outlined text-amber-500">queue</span>
          <h3 class="font-headline font-bold text-on-surface text-sm">Na fila de espera</h3>
        </div>
        <div class="space-y-2">
          <?php foreach ($filas as $f): ?>
          <div class="flex items-center justify-between bg-amber-50/70 rounded-xl px-4 py-3 border border-amber-100">
            <div>
              <p class="text-xs font-bold text-amber-800"><?= $dias_nomes[(int)$f['dia_semana']] ?> • <?= substr($f['hora_inicio'],0,5) ?> – <?= substr($f['hora_fim'],0,5) ?></p>
              <p class="text-xs text-amber-700 mt-0.5"><?= htmlspecialchars($f['terapeuta_nome']) ?></p>
            </div>
            <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-amber-100 text-amber-700">
              <?= $f['status'] === 'notificado' ? '🔔 Vaga disponível!' : "Posição {$f['posicao']}" ?>
            </span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Check-in emocional -->
      <div class="glass rounded-3xl p-7 border border-outline-variant/20">
        <div class="flex items-center justify-between mb-6">
          <div>
            <h2 class="text-xl font-extrabold text-on-surface">Como você está hoje?</h2>
            <p class="text-sm text-on-surface-variant mt-0.5">Escolha e receba uma sugestão personalizada</p>
          </div>
          <span class="material-symbols-outlined text-primary/40 text-3xl">mood</span>
        </div>

        <div class="grid grid-cols-3 sm:grid-cols-6 gap-3 mb-6">
          <?php
          $humores = [
            'bem'           => ['icon'=>'sentiment_very_satisfied','label'=>'Bem','cor'=>'text-emerald-600'],
            'neutro'        => ['icon'=>'sentiment_neutral',       'label'=>'Neutro','cor'=>'text-blue-600'],
            'cansado'       => ['icon'=>'battery_low',             'label'=>'Cansado','cor'=>'text-amber-600'],
            'ansioso'       => ['icon'=>'psychology',              'label'=>'Ansioso','cor'=>'text-pink-600'],
            'sobrecarregado'=> ['icon'=>'layers_clear',            'label'=>'Sobrec.','cor'=>'text-orange-600'],
            'triste'        => ['icon'=>'sentiment_dissatisfied',  'label'=>'Triste','cor'=>'text-rose-600'],
          ];
          foreach ($humores as $key => $h): ?>
          <button class="humor-btn flex flex-col items-center gap-2 relative group" data-humor="<?= $key ?>">
            <div class="ic-wrap w-14 h-14 rounded-full bg-surface-container-high flex items-center justify-center group-hover:bg-primary/10 transition-all relative">
              <span class="material-symbols-outlined text-2xl <?= $h['cor'] ?>"><?= $h['icon'] ?></span>
              <span class="ring-sel absolute inset-0 rounded-full border-2 border-primary pointer-events-none"></span>
            </div>
            <span class="text-xs font-medium text-on-surface-variant"><?= $h['label'] ?></span>
          </button>
          <?php endforeach; ?>
        </div>

        <!-- Recomendação dinâmica (escondida até selecionar humor) -->
        <div id="recomendacao-box" class="hidden rounded-2xl p-5 flex items-center gap-5 transition-all">
          <div id="rec-icon-wrap" class="p-3 rounded-full shrink-0">
            <span id="rec-icon" class="material-symbols-outlined text-2xl"></span>
          </div>
          <div class="flex-grow">
            <p id="rec-titulo" class="font-bold text-on-surface"></p>
            <p id="rec-desc" class="text-sm text-on-surface-variant mt-0.5"></p>
          </div>
          <button id="rec-btn"
            class="shrink-0 px-5 py-2.5 rounded-full bg-primary text-white text-sm font-bold hover:opacity-90 transition-all"
            onclick="abrirGuia()">
            Começar
          </button>
        </div>
      </div>

      <!-- Ambiente / Playlists -->
      <div class="glass rounded-3xl p-7 border border-outline-variant/20">
        <div class="flex items-center gap-3 mb-5">
          <div class="p-2.5 bg-primary rounded-xl text-white">
            <span class="material-symbols-outlined">graphic_eq</span>
          </div>
          <div>
            <h2 class="text-xl font-extrabold text-on-surface">Ambiente Terapêutico</h2>
            <p class="text-xs text-on-surface-variant">Playlists para harmonizar seu espaço</p>
          </div>
        </div>
        <div class="flex flex-wrap gap-3">
          <?php foreach ($playlists as $pl):
            $yt_id = '';
            if (preg_match('/(?:v=|youtu\.be\/)([A-Za-z0-9_\-]{11})/', $pl['url'], $m2)) $yt_id = $m2[1];
          ?>
          <button onclick="abrirPlaylist('<?= htmlspecialchars($yt_id) ?>','<?= htmlspecialchars($pl['nome'], ENT_QUOTES) ?>')"
            class="flex items-center gap-2 px-4 py-2.5 rounded-full glass border border-outline-variant/30 hover:border-primary/40 hover:bg-primary/5 transition-all text-sm font-medium text-on-surface">
            <span><?= $pl['emoji'] ?></span>
            <?= htmlspecialchars($pl['nome']) ?>
            <span class="material-symbols-outlined text-sm text-primary/60">play_circle</span>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Histórico -->
      <div class="glass rounded-3xl p-7 border border-outline-variant/20">
        <div class="flex items-center justify-between mb-5">
          <h2 class="text-xl font-extrabold text-primary">Histórico de sessões</h2>
          <span class="text-xs text-on-surface-variant"><?= $total ?> no total</span>
        </div>
        <!-- Tabs -->
        <div class="flex gap-5 border-b border-outline-variant mb-5 overflow-x-auto">
          <?php foreach (['todas'=>'Todas','confirmado'=>'Confirmadas','pendente'=>'Pendentes','concluido'=>'Concluídas','cancelado'=>'Canceladas'] as $k=>$l): ?>
          <button class="tab-btn pb-3 text-sm font-bold text-on-surface-variant whitespace-nowrap <?= $k==='todas'?'active':'' ?>"
                  data-tab-h="<?= $k ?>"><?= $l ?></button>
          <?php endforeach; ?>
        </div>
        <?php if (empty($reservas)): ?>
        <div class="text-center py-12">
          <span class="material-symbols-outlined text-4xl text-outline-variant mb-3 block">event_note</span>
          <p class="text-sm text-on-surface-variant mb-4">Nenhuma sessão ainda.</p>
          <a href="agendar.php" class="inline-flex items-center gap-2 text-sm font-bold text-primary hover:underline">
            <span class="material-symbols-outlined text-sm">add_circle</span>Agendar a primeira
          </a>
        </div>
        <?php else: ?>
        <div id="hist-lista" class="space-y-2.5">
          <?php foreach ($reservas as $r):
            $hi = substr($r['hora_inicio'],0,5); $hf = substr($r['hora_fim'],0,5);
            $dia = $dias_nomes[(int)$r['dia_semana']] ?? '?';
            $dt2 = date('d/m/Y', strtotime($r['data_sessao']));
            $st  = $r['status'];
          ?>
          <div class="card-item hist-item flex flex-col sm:flex-row sm:items-center gap-3 bg-surface-container-low/70 rounded-2xl px-5 py-4 border border-outline-variant/15 hover:border-primary/20"
               data-status="<?= $st ?>"
               onclick="abrirDetalhe(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)">
            <div class="shrink-0 w-9 h-9 rounded-full flex items-center justify-center
              <?= $st==='confirmado'?'bg-emerald-100 text-emerald-600':($st==='concluido'?'bg-indigo-100 text-indigo-600':($st==='cancelado'?'bg-red-100 text-red-500':'bg-primary/10 text-primary')) ?>">
              <span class="material-symbols-outlined text-base">
                <?= $st==='confirmado'?'event_available':($st==='concluido'?'verified':($st==='cancelado'?'event_busy':'pending')) ?>
              </span>
            </div>
            <div class="flex-grow min-w-0">
              <div class="flex flex-wrap items-center gap-2 mb-0.5">
                <p class="font-bold text-sm text-on-surface"><?= $dia ?>, <?= $dt2 ?> • <?= $hi ?> – <?= $hf ?></p>
                <span class="text-[10px] font-bold px-2 py-0.5 rounded-full s-<?= $st ?>"><?= $status_label[$st] ?? $st ?></span>
              </div>
              <p class="text-xs text-on-surface-variant truncate"><?= htmlspecialchars($r['terapeuta_nome']) ?><?= $r['local'] ? ' · '.htmlspecialchars($r['local']) : '' ?></p>
            </div>
            <span class="material-symbols-outlined text-outline-variant hidden sm:block shrink-0">chevron_right</span>
          </div>
          <?php endforeach; ?>
        </div>
        <div id="hist-vazio" class="hidden text-center py-8 text-sm text-on-surface-variant">Nenhuma sessão nesta categoria.</div>
        <?php endif; ?>
      </div>

    </div><!-- /coluna esquerda -->

    <!-- COLUNA DIREITA (1/3) -->
    <div class="space-y-6">

      <!-- Avisos da coordenação -->
      <div class="glass rounded-2xl p-5 border border-primary/10">
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-outlined text-primary text-xl">campaign</span>
          <h3 class="font-headline font-bold text-on-surface">Avisos da coordenação</h3>
        </div>
        <?php if (empty($avisos)): ?>
        <p class="text-sm text-on-surface-variant text-center py-3">Nenhum aviso no momento.</p>
        <?php else: ?>
        <div class="space-y-3">
          <?php foreach ($avisos as $av):
            $icones = ['evento'=>'event','urgente'=>'warning','manutencao'=>'build','info'=>'info'];
            $cores  = ['evento'=>'text-primary','urgente'=>'text-secondary','manutencao'=>'text-amber-600','info'=>'text-blue-600'];
          ?>
          <div class="aviso-<?= $av['tipo'] ?> bg-white/60 rounded-xl px-4 py-3">
            <div class="flex items-start gap-2">
              <span class="material-symbols-outlined text-sm mt-0.5 <?= $cores[$av['tipo']] ?? 'text-primary' ?> shrink-0">
                <?= $icones[$av['tipo']] ?? 'info' ?>
              </span>
              <div class="min-w-0">
                <p class="text-xs font-bold text-on-surface"><?= htmlspecialchars($av['titulo']) ?></p>
                <p class="text-xs text-on-surface-variant mt-0.5 leading-relaxed"><?= htmlspecialchars($av['texto']) ?></p>
                <p class="text-[10px] text-outline mt-1"><?= date('d/m/Y', strtotime($av['criado_em'])) ?></p>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Bem-estar -->
      <div class="glass rounded-2xl p-5 border border-outline-variant/20">
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-outlined text-secondary">favorite</span>
          <h3 class="font-headline font-bold text-on-surface">Bem-estar</h3>
        </div>
        <?php if ($bem_estar !== null): ?>
        <div class="text-center mb-3">
          <p class="text-5xl font-extrabold text-primary"><?= $bem_estar ?></p>
          <p class="text-xs text-on-surface-variant mt-1">de 10 no cadastro</p>
        </div>
        <div class="w-full bg-surface-container-highest rounded-full h-2.5 mb-2">
          <div class="h-2.5 rounded-full" style="width:<?= ($bem_estar/10)*100 ?>%;background:linear-gradient(90deg,#10b981,#a855f7,#ec4899)"></div>
        </div>
        <p class="text-xs text-center text-on-surface-variant">
          <?php echo $bem_estar<=3?'😔 Vamos cuidar de você':($bem_estar<=6?'😐 Espaço para melhorar':($bem_estar<=8?'🙂 Indo bem!':'😊 Ótimo estado!')); ?>
        </p>
        <?php else: ?>
        <p class="text-sm text-on-surface-variant text-center py-3">Não informado.</p>
        <?php endif; ?>
      </div>

      <!-- Ações rápidas -->
      <div class="glass rounded-2xl p-5 border border-outline-variant/20">
        <h3 class="font-headline font-bold text-on-surface mb-4">Ações rápidas</h3>
        <div class="grid grid-cols-2 gap-3">
          <?php
          $acoes = [
            ['icon'=>'add_circle',   'label'=>'Agendar',     'href'=>'agendar.php',   'cor'=>'text-primary'],
            ['icon'=>'history',      'label'=>'Histórico',   'href'=>'#hist-lista',   'cor'=>'text-indigo-600'],
            ['icon'=>'campaign',     'label'=>'Avisos',      'href'=>'#avisos',       'cor'=>'text-primary'],
            ['icon'=>'rate_review',  'label'=>'Sugestão',    'href'=>'#sugestao',     'cor'=>'text-emerald-600'],
          ];
          foreach ($acoes as $a): ?>
          <a href="<?= $a['href'] ?>"
            class="glass flex flex-col items-center gap-2 p-4 rounded-2xl border border-outline-variant/20 hover:border-primary/30 hover:bg-primary/4 transition-all group">
            <span class="material-symbols-outlined text-2xl <?= $a['cor'] ?> group-hover:scale-110 transition-transform"><?= $a['icon'] ?></span>
            <span class="text-xs font-medium text-on-surface"><?= $a['label'] ?></span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Caixa de sugestões / reclamações -->
      <div id="sugestao" class="glass rounded-2xl p-5 border border-outline-variant/20">
        <div class="flex items-center gap-2 mb-4">
          <span class="material-symbols-outlined text-emerald-600">rate_review</span>
          <h3 class="font-headline font-bold text-on-surface">Fale com a coordenação</h3>
        </div>

        <form id="form-sugestao" class="space-y-3" onsubmit="enviarSugestao(event)">
          <select id="sug-tipo" class="w-full rounded-xl border border-outline-variant/30 bg-white/60 px-3 py-2.5 text-sm text-on-surface focus:ring-2 focus:ring-primary focus:border-primary transition-all">
            <option value="sugestao">💡 Sugestão</option>
            <option value="elogio">👏 Elogio</option>
            <option value="reclamacao">⚠️ Reclamação</option>
            <option value="duvida">❓ Dúvida</option>
          </select>
          <textarea id="sug-msg" rows="3" maxlength="600"
            placeholder="Escreva sua mensagem..."
            class="w-full rounded-xl border border-outline-variant/30 bg-white/60 px-3 py-2.5 text-sm text-on-surface placeholder:text-on-surface-variant/40 focus:ring-2 focus:ring-primary focus:border-primary resize-none transition-all"></textarea>
          <div id="sug-erro" class="hidden text-xs text-error font-medium flex items-center gap-1">
            <span class="material-symbols-outlined text-sm">error</span><span id="sug-erro-msg"></span>
          </div>
          <button type="submit"
            class="w-full py-3 rounded-full bg-emerald-600 text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all flex items-center justify-center gap-2">
            <span class="material-symbols-outlined text-sm">send</span>
            <span id="sug-btn-label">Enviar mensagem</span>
          </button>
        </form>

        <!-- Minhas últimas mensagens -->
        <?php if (!empty($minhas_sug)): ?>
        <div class="mt-5 pt-4 border-t border-outline-variant/20 space-y-2.5">
          <p class="text-xs font-bold uppercase tracking-widest text-on-surface-variant">Minhas mensagens</p>
          <?php foreach ($minhas_sug as $sg):
            $tipo_icon = ['sugestao'=>'💡','elogio'=>'👏','reclamacao'=>'⚠️','duvida'=>'❓'][$sg['tipo']] ?? '📝';
          ?>
          <div class="bg-white/60 rounded-xl p-3 border border-outline-variant/20">
            <div class="flex items-center justify-between mb-1">
              <span class="text-xs font-bold text-on-surface"><?= $tipo_icon ?> <?= ucfirst($sg['tipo']) ?></span>
              <span class="text-[10px] text-outline"><?= date('d/m/Y', strtotime($sg['criado_em'])) ?></span>
            </div>
            <p class="text-xs text-on-surface-variant leading-relaxed truncate"><?= htmlspecialchars($sg['mensagem']) ?></p>
            <?php if ($sg['resposta']): ?>
            <div class="mt-2 pl-3 border-l-2 border-emerald-400">
              <p class="text-xs font-bold text-emerald-700">Resposta da coordenação:</p>
              <p class="text-xs text-emerald-800 mt-0.5"><?= htmlspecialchars($sg['resposta']) ?></p>
            </div>
            <?php elseif (!$sg['lida']): ?>
            <span class="inline-block mt-1.5 text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-50 text-amber-700">Aguardando leitura</span>
            <?php else: ?>
            <span class="inline-block mt-1.5 text-[10px] font-bold px-2 py-0.5 rounded-full bg-blue-50 text-blue-700">Lida</span>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Projeto NUPICS -->
      <div class="bg-gradient-to-br from-primary to-secondary p-7 rounded-2xl text-white relative overflow-hidden">
        <div class="relative z-10 space-y-4">
          <h3 class="text-xl font-extrabold">NUPICS Caicó</h3>
          <p class="text-purple-100 text-sm leading-relaxed">
            Projeto de extensão da UERN, oferecendo atendimentos integrativos gratuitos para toda a comunidade.
          </p>
          <p class="text-purple-200 text-xs">Campus UERN, Caicó/RN</p>
        </div>
        <span class="material-symbols-outlined absolute -bottom-4 -right-4 text-[100px] text-white/10">eco</span>
      </div>

    </div><!-- /coluna direita -->
  </div><!-- /grid principal -->

</main>

<!-- ═══════════════════════════════
     MODAL: Detalhe da sessão
═══════════════════════════════ -->
<div class="modal-wrap fixed inset-0 z-[100] items-end sm:items-center justify-center p-0 sm:p-4" id="modal-detalhe">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-detalhe')"></div>
  <div class="glass modal-card relative z-10 w-full sm:max-w-lg rounded-t-[2rem] sm:rounded-[2rem] shadow-2xl flex flex-col max-h-[90vh] overflow-hidden">
    <div class="flex items-center justify-between px-6 pt-6 pb-3 shrink-0">
      <h2 class="text-lg font-extrabold text-primary">Detalhes da Sessão</h2>
      <button onclick="fecharModal('modal-detalhe')" class="w-9 h-9 flex items-center justify-center rounded-full bg-surface-container-high hover:bg-surface-container-highest transition-colors">
        <span class="material-symbols-outlined text-base text-on-surface-variant">close</span>
      </button>
    </div>
    <div class="overflow-y-auto px-6 pb-6 flex-1 space-y-4">
      <div id="det-banner" class="rounded-2xl px-4 py-3 flex items-center gap-3"></div>
      <div class="grid gap-2.5">
        <div class="flex items-center gap-3 bg-white/60 rounded-xl px-4 py-3 border border-outline-variant/20">
          <span class="material-symbols-outlined text-secondary shrink-0">schedule</span>
          <div><p class="text-[10px] font-bold uppercase text-on-surface-variant">Horário</p>
               <p id="det-hora" class="font-bold text-sm text-on-surface"></p></div>
        </div>
        <div class="flex items-center gap-3 bg-white/60 rounded-xl px-4 py-3 border border-outline-variant/20">
          <span class="material-symbols-outlined text-secondary shrink-0">person</span>
          <div><p class="text-[10px] font-bold uppercase text-on-surface-variant">Terapeuta</p>
               <p id="det-terapeuta" class="font-bold text-sm text-on-surface"></p></div>
        </div>
        <div class="flex items-center gap-3 bg-white/60 rounded-xl px-4 py-3 border border-outline-variant/20">
          <span class="material-symbols-outlined text-secondary shrink-0">location_on</span>
          <div><p class="text-[10px] font-bold uppercase text-on-surface-variant">Local</p>
               <p id="det-local" class="font-bold text-sm text-on-surface"></p></div>
        </div>
        <div class="flex items-start gap-3 bg-white/60 rounded-xl px-4 py-3 border border-outline-variant/20">
          <span class="material-symbols-outlined text-secondary shrink-0 mt-0.5">self_care</span>
          <div><p class="text-[10px] font-bold uppercase text-on-surface-variant mb-1.5">Práticas</p>
               <div id="det-praticas" class="flex flex-wrap gap-1.5"></div></div>
        </div>
      </div>
      <div class="bg-surface-container-low rounded-2xl p-4 border border-outline-variant/15">
        <p class="text-[10px] font-bold uppercase text-on-surface-variant mb-1.5">Suas queixas</p>
        <p id="det-queixas" class="text-sm text-on-surface leading-relaxed"></p>
      </div>
      <div id="det-obs-wrap" class="hidden bg-indigo-50 rounded-2xl p-4 border border-indigo-100">
        <p class="text-[10px] font-bold uppercase text-indigo-700 mb-1.5">Observação do terapeuta</p>
        <p id="det-obs" class="text-sm text-indigo-900 leading-relaxed"></p>
      </div>
      <div id="det-cancelar-wrap" class="hidden">
        <button id="btn-cancelar"
          class="w-full py-3.5 rounded-full border-2 border-red-200 text-red-600 font-bold text-sm hover:bg-red-50 transition-all flex items-center justify-center gap-2">
          <span class="material-symbols-outlined text-sm">cancel</span>Cancelar este agendamento
        </button>
      </div>
    </div>
  </div>
</div>

<!-- MODAL: Confirmar cancelamento -->
<div class="modal-wrap fixed inset-0 z-[101] items-center justify-center p-4" id="modal-cancelar">
  <div class="absolute inset-0 bg-primary/20 backdrop-blur-sm" onclick="fecharModal('modal-cancelar')"></div>
  <div class="glass modal-card relative z-10 w-full max-w-md rounded-[2rem] shadow-2xl p-8 text-center">
    <div class="w-16 h-16 bg-red-50 text-red-500 rounded-full flex items-center justify-center mx-auto mb-5">
      <span class="material-symbols-outlined text-4xl">event_busy</span>
    </div>
    <h2 class="text-xl font-extrabold text-primary mb-2">Cancelar agendamento?</h2>
    <p class="text-sm text-on-surface-variant mb-6 leading-relaxed">Esta ação não pode ser desfeita. Se mudar de ideia, você precisará agendar novamente.</p>
    <div class="flex gap-3">
      <button id="btn-cancelar-ok" class="flex-grow py-4 rounded-full bg-red-500 text-white font-bold text-sm hover:opacity-90 active:scale-95 transition-all">Sim, cancelar</button>
      <button onclick="fecharModal('modal-cancelar')" class="px-6 py-4 rounded-full border-2 border-outline-variant text-on-surface-variant font-bold text-sm hover:bg-surface-container-high transition-all">Voltar</button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════
     MODAL: Guia de prática + YouTube
═══════════════════════════════ -->
<div class="modal-wrap fixed inset-0 z-[101] items-end sm:items-center justify-center p-0 sm:p-4" id="modal-guia">
  <div class="absolute inset-0 bg-primary/25 backdrop-blur-sm" onclick="fecharModalGuia()"></div>
  <div class="glass modal-card relative z-10 w-full sm:max-w-2xl rounded-t-[2rem] sm:rounded-[2rem] shadow-2xl flex flex-col max-h-[92vh] overflow-hidden">
    <div class="flex items-center justify-between px-6 pt-6 pb-3 shrink-0">
      <div>
        <p class="text-xs font-bold uppercase tracking-widest text-primary/50 mb-0.5">Sua prática</p>
        <h2 id="guia-titulo" class="text-lg font-extrabold text-primary"></h2>
      </div>
      <button onclick="fecharModalGuia()" class="w-9 h-9 flex items-center justify-center rounded-full bg-surface-container-high hover:bg-surface-container-highest transition-colors">
        <span class="material-symbols-outlined text-base text-on-surface-variant">close</span>
      </button>
    </div>
    <div class="overflow-y-auto px-6 pb-6 flex-1 space-y-5">
      <!-- Instruções -->
      <div class="bg-primary/5 rounded-2xl p-5 border border-primary/10">
        <p class="text-xs font-bold uppercase tracking-widest text-primary/50 mb-2">Passo a passo</p>
        <p id="guia-texto" class="text-sm text-on-surface leading-relaxed"></p>
      </div>
      <!-- YouTube embed -->
      <div>
        <p class="text-xs font-bold uppercase tracking-widest text-on-surface-variant mb-3">Música para acompanhar</p>
        <div class="flex flex-wrap gap-2 mb-3" id="guia-playlists"></div>
        <div id="yt-container" class="hidden rounded-2xl overflow-hidden border border-outline-variant/20 aspect-video">
          <iframe id="yt-frame" width="100%" height="100%" frameborder="0"
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
            allowfullscreen class="w-full h-full"></iframe>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- MODAL: Playlist direto -->
<div class="modal-wrap fixed inset-0 z-[101] items-center justify-center p-4" id="modal-playlist">
  <div class="absolute inset-0 bg-primary/25 backdrop-blur-sm" onclick="fecharModal('modal-playlist')"></div>
  <div class="glass modal-card relative z-10 w-full max-w-2xl rounded-[2rem] shadow-2xl overflow-hidden">
    <div class="flex items-center justify-between px-6 pt-6 pb-3">
      <h2 id="playlist-titulo" class="text-lg font-extrabold text-primary"></h2>
      <button onclick="fecharModal('modal-playlist')" class="w-9 h-9 flex items-center justify-center rounded-full bg-surface-container-high hover:bg-surface-container-highest transition-colors">
        <span class="material-symbols-outlined text-base text-on-surface-variant">close</span>
      </button>
    </div>
    <div class="aspect-video">
      <iframe id="playlist-frame" width="100%" height="100%" frameborder="0"
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
        allowfullscreen class="w-full h-full"></iframe>
    </div>
    <div class="px-6 pb-5 pt-3">
      <p class="text-xs text-on-surface-variant">Feche este modal para pausar a música.</p>
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

<!-- Footer -->
<footer class="py-10 bg-purple-50/50 border-t border-purple-200/20">
  <div class="flex flex-col md:flex-row justify-between items-center px-8 max-w-7xl mx-auto text-sm text-purple-900">
    <div class="mb-4 md:mb-0">
      <span class="font-bold text-purple-800">NUPICS</span>
      <p class="mt-1 text-purple-700/60">© <?= date('Y') ?> NUPICS – UERN Caicó</p>
    </div>
    <div class="flex gap-6">
      <a href="#" class="text-purple-700 hover:text-pink-500 transition-colors">Privacidade</a>
      <a href="#" class="text-purple-700 hover:text-pink-500 transition-colors">Contato</a>
    </div>
  </div>
</footer>

<script>
const DIAS      = <?= json_encode($dias_nomes) ?>;
const PLAYLISTS = <?= json_encode(array_map(fn($p) => [
  'nome' => $p['nome'], 'emoji' => $p['emoji'],
  'ytId' => preg_match('/(?:v=|youtu\.be\/)([A-Za-z0-9_\-]{11})/', $p['url'], $m) ? $m[1] : ''
], $playlists)) ?>;

const GUIAS = <?= json_encode($guias) ?>;
const RECS  = <?= json_encode($recomendacoes) ?>;

// ── Mobile nav ──────────────────────────────────
document.getElementById('mob-btn').addEventListener('click', () =>
  document.getElementById('mob-menu').classList.toggle('hidden'));

// ── Modal helpers ───────────────────────────────
function abrirModal(id)  { document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function fecharModal(id) { document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
function fecharModalGuia() {
  document.getElementById('yt-frame').src = '';
  fecharModal('modal-guia');
}

// ── Tabs histórico ──────────────────────────────
document.querySelectorAll('[data-tab-h]').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('[data-tab-h]').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const filtro = btn.dataset.tabH;
    let vis = 0;
    document.querySelectorAll('.hist-item').forEach(it => {
      const show = filtro==='todas' || it.dataset.status===filtro;
      it.classList.toggle('hidden', !show);
      if (show) vis++;
    });
    document.getElementById('hist-vazio').classList.toggle('hidden', vis > 0);
  });
});

// ── Check-in emocional ──────────────────────────
let humorAtual = null;
document.querySelectorAll('.humor-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.humor-btn').forEach(b => b.classList.remove('selecionado'));
    btn.classList.add('selecionado');
    humorAtual = btn.dataset.humor;
    const rec = RECS[humorAtual];
    if (!rec) return;
    const box = document.getElementById('recomendacao-box');
    box.className = `rounded-2xl p-5 flex items-center gap-5 transition-all ${rec.bg} border border-current/10`;
    document.getElementById('rec-icon-wrap').className = `p-3 rounded-full shrink-0 ${rec.bg}`;
    document.getElementById('rec-icon').className = `material-symbols-outlined text-2xl ${rec.cor}`;
    document.getElementById('rec-icon').textContent = rec.icon;
    document.getElementById('rec-titulo').textContent = rec.titulo;
    document.getElementById('rec-desc').textContent   = rec.desc;
    box.classList.remove('hidden');
  });
});

// ── Modal Guia ──────────────────────────────────
function abrirGuia() {
  if (!humorAtual) return;
  const rec   = RECS[humorAtual];
  const guia  = GUIAS[humorAtual];
  document.getElementById('guia-titulo').textContent = rec.titulo;
  document.getElementById('guia-texto').textContent  = guia;

  // Monta pills de playlists
  const pp = document.getElementById('guia-playlists');
  pp.innerHTML = '';
  PLAYLISTS.forEach(pl => {
    if (!pl.ytId) return;
    const b = document.createElement('button');
    b.className = 'flex items-center gap-1.5 px-3 py-1.5 rounded-full border border-outline-variant/30 bg-white/60 hover:border-primary/40 hover:bg-primary/5 text-xs font-medium text-on-surface transition-all';
    b.innerHTML = `${pl.emoji} ${pl.nome} <span class="material-symbols-outlined text-xs text-primary/60">play_circle</span>`;
    b.onclick = () => embedYT(pl.ytId);
    pp.appendChild(b);
  });

  // Auto-carrega a primeira playlist
  if (PLAYLISTS.length && PLAYLISTS[0].ytId) embedYT(PLAYLISTS[0].ytId);
  abrirModal('modal-guia');
}

function embedYT(ytId) {
  const c = document.getElementById('yt-container');
  document.getElementById('yt-frame').src = `https://www.youtube.com/embed/${ytId}?autoplay=1`;
  c.classList.remove('hidden');
}

// ── Playlist direta ─────────────────────────────
function abrirPlaylist(ytId, nome) {
  document.getElementById('playlist-titulo').textContent = nome;
  document.getElementById('playlist-frame').src = `https://www.youtube.com/embed/${ytId}?autoplay=1`;
  abrirModal('modal-playlist');
}
// Para ao fechar
document.getElementById('modal-playlist').addEventListener('click', e => {
  if (e.target === e.currentTarget) document.getElementById('playlist-frame').src = '';
});

// ── Detalhe da sessão ───────────────────────────
let ridAtual = null;
function abrirDetalhe(r) {
  ridAtual = r.id;
  const hi = r.hora_inicio?.substring(0,5), hf = r.hora_fim?.substring(0,5);
  const dia = DIAS[r.dia_semana]||'?';
  const dt  = r.data_sessao ? new Date(r.data_sessao+'T00:00').toLocaleDateString('pt-BR') : '';

  const cfg = {
    pendente:   {bg:'bg-primary/8 border border-primary/15',  ic:'pending',        cor:'text-primary',     txt:'Aguardando confirmação'},
    confirmado: {bg:'bg-emerald-50 border border-emerald-100',ic:'event_available', cor:'text-emerald-600', txt:'Sessão confirmada!'},
    cancelado:  {bg:'bg-red-50 border border-red-100',        ic:'event_busy',      cor:'text-red-500',     txt:'Agendamento cancelado'},
    concluido:  {bg:'bg-indigo-50 border border-indigo-100',  ic:'verified',        cor:'text-indigo-600',  txt:'Sessão realizada'},
  }[r.status] || {};
  const banner = document.getElementById('det-banner');
  banner.className = `rounded-2xl px-4 py-3 flex items-center gap-3 ${cfg.bg||''}`;
  banner.innerHTML = `<span class="material-symbols-outlined ${cfg.cor||''}">${cfg.ic||''}</span><span class="text-sm font-bold ${cfg.cor||''}">${cfg.txt||''}</span>`;

  document.getElementById('det-hora').textContent      = `${dia}, ${dt} • ${hi} – ${hf}`;
  document.getElementById('det-terapeuta').textContent = r.terapeuta_nome||'—';
  document.getElementById('det-local').textContent     = r.local||'A definir';
  document.getElementById('det-queixas').textContent   = r.queixas||'—';

  const pp = document.getElementById('det-praticas');
  pp.innerHTML='';
  (r.praticas||'').split(',').forEach(p => {
    if (!p.trim()) return;
    const s = document.createElement('span');
    s.className='px-3 py-1 rounded-full bg-primary/10 text-primary text-xs font-bold';
    s.textContent=p.trim(); pp.appendChild(s);
  });

  const obsW = document.getElementById('det-obs-wrap');
  if (r.status==='concluido' && r.observacao) {
    document.getElementById('det-obs').textContent = r.observacao;
    obsW.classList.remove('hidden');
  } else obsW.classList.add('hidden');

  document.getElementById('det-cancelar-wrap').classList.toggle('hidden',
    !['pendente','confirmado'].includes(r.status));

  abrirModal('modal-detalhe');
}

// ── Cancelar sessão ─────────────────────────────
document.getElementById('btn-cancelar').addEventListener('click', () => {
  fecharModal('modal-detalhe');
  setTimeout(() => abrirModal('modal-cancelar'), 150);
});
document.getElementById('btn-cancelar-ok').addEventListener('click', async () => {
  const btn = document.getElementById('btn-cancelar-ok');
  btn.disabled=true; btn.textContent='...';
  const form = new FormData();
  form.append('acao','cancelar'); form.append('reserva_id', ridAtual);
  try {
    const data = await fetch('../api/reserva_action.php',{method:'POST',body:form}).then(r=>r.json());
    fecharModal('modal-cancelar');
    if (data.ok) { toast('Agendamento cancelado.','event_busy','text-red-500'); setTimeout(()=>location.reload(),1200); }
    else toast(data.msg||'Erro.','error','text-red-500');
  } catch { toast('Erro de conexão.','error','text-red-500'); }
  finally { btn.disabled=false; btn.textContent='Sim, cancelar'; }
});

// ── Sugestão / reclamação ───────────────────────
async function enviarSugestao(e) {
  e.preventDefault();
  const tipo = document.getElementById('sug-tipo').value;
  const msg  = document.getElementById('sug-msg').value.trim();
  const erro = document.getElementById('sug-erro');
  const erroMsg = document.getElementById('sug-erro-msg');
  const btnLabel = document.getElementById('sug-btn-label');

  erro.classList.add('hidden');
  if (!msg || msg.length < 10) {
    erroMsg.textContent='Escreva ao menos 10 caracteres.'; erro.classList.remove('hidden'); return;
  }

  btnLabel.textContent='Enviando...';
  const form = new FormData();
  form.append('acao','sugestao'); form.append('tipo', tipo); form.append('mensagem', msg);

  try {
    const data = await fetch('../api/reserva_action.php',{method:'POST',body:form}).then(r=>r.json());
    if (data.ok) {
      document.getElementById('sug-msg').value='';
      toast('Mensagem enviada! 👍','check_circle','text-emerald-600');
      setTimeout(()=>location.reload(),1500);
    } else { erroMsg.textContent=data.msg||'Erro ao enviar.'; erro.classList.remove('hidden'); }
  } catch { erroMsg.textContent='Erro de conexão.'; erro.classList.remove('hidden'); }
  finally { btnLabel.textContent='Enviar mensagem'; }
}

// ── Toast ───────────────────────────────────────
function toast(msg, icon='check_circle', cor='text-emerald-600') {
  const t = document.getElementById('toast');
  document.getElementById('toast-msg').textContent=msg;
  const ic=document.getElementById('toast-icon'); ic.textContent=icon; ic.className='material-symbols-outlined text-base '+cor;
  t.classList.remove('hidden'); setTimeout(()=>t.classList.add('hidden'),3000);
}
</script>
<?php include '../includes/upload_component.php'; ?>
</body>
</html>