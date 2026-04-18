<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'paciente') {
    header('Location: ../index.php');
    exit;
}

// ── Processa faltas automáticas ──
$pdo->query('
    UPDATE agendamentos
    SET status = "faltou"
    WHERE status = "agendado"
      AND data < CURDATE()
      AND justificativa IS NULL
');
$ciclos_faltas = $pdo->query('
    SELECT c.id, c.paciente_id,
           COUNT(CASE WHEN a.status = "faltou" AND a.justificativa IS NULL THEN 1 END) AS faltas_inj
    FROM ciclos c
    JOIN agendamentos a ON a.ciclo_id = c.id
    WHERE c.status = "ativo"
    GROUP BY c.id
    HAVING faltas_inj >= 2
')->fetchAll();
foreach ($ciclos_faltas as $cf) {
    $pdo->prepare('UPDATE ciclos SET status = "cancelado" WHERE id = ?')->execute([$cf['id']]);
    $pdo->prepare('UPDATE agendamentos SET status = "cancelado" WHERE ciclo_id = ? AND status = "agendado" AND data >= CURDATE()')->execute([$cf['id']]);
}

$uid = (int)$_SESSION['usuario_id'];
$stmt = $pdo->prepare('
    SELECT p.id AS paciente_id, u.nome, u.email, u.telefone
    FROM pacientes p JOIN usuarios u ON u.id = p.usuario_id
    WHERE u.id = ?
');
$stmt->execute([$uid]);
$paciente    = $stmt->fetch();
$paciente_id = $paciente['paciente_id'];
$primeiro    = explode(' ', $paciente['nome'])[0];

$sucesso = '';
$erro    = '';

// ── Justificar falta ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'justificar_falta') {
    $agend_id      = (int)$_POST['agendamento_id'];
    $justificativa = trim($_POST['justificativa'] ?? '');

    if (!$justificativa) {
        $erro = 'Escreva o motivo da justificativa.';
    } else {
        $chk = $pdo->prepare('
            SELECT COUNT(*) FROM agendamentos
            WHERE ciclo_id = (SELECT ciclo_id FROM agendamentos WHERE id = ?)
              AND justificativa IS NOT NULL
        ');
        $chk->execute([$agend_id]);
        if ($chk->fetchColumn() >= 1) {
            $erro = 'Você já usou sua única justificativa permitida neste ciclo.';
        } else {
            $pdo->prepare('
                UPDATE agendamentos
                SET justificativa = ?, justificativa_em = NOW()
                WHERE id = ? AND status = "agendado"
            ')->execute([$justificativa, $agend_id]);
            $sucesso = 'Justificativa registrada com sucesso.';
        }
    }
}

// ── Próximo agendamento ──
$stmt = $pdo->prepare('
    SELECT a.id, a.data, a.status, a.numero_sessao, a.justificativa,
           h.hora_inicio, h.duracao_minutos,
           u.nome AS terapeuta_nome, t.especialidade,
           c.total_sessoes, c.id AS ciclo_id
    FROM agendamentos a
    JOIN horarios h ON h.id = a.horario_id
    JOIN ciclos c ON c.id = a.ciclo_id
    LEFT JOIN terapeutas t ON t.id = a.terapeuta_id
    LEFT JOIN usuarios u ON u.id = t.usuario_id
    WHERE c.paciente_id = ? AND a.data >= CURDATE() AND a.status = "agendado"
    ORDER BY a.data, h.hora_inicio LIMIT 1
');
$stmt->execute([$paciente_id]);
$proximo = $stmt->fetch();

// ── Histórico de sessões ──
$stmt = $pdo->prepare('
    SELECT a.id, a.data, a.status, a.numero_sessao, a.justificativa,
           h.hora_inicio, h.duracao_minutos,
           u.nome AS terapeuta_nome, t.especialidade,
           c.total_sessoes, c.id AS ciclo_id
    FROM agendamentos a
    JOIN horarios h ON h.id = a.horario_id
    JOIN ciclos c ON c.id = a.ciclo_id
    LEFT JOIN terapeutas t ON t.id = a.terapeuta_id
    LEFT JOIN usuarios u ON u.id = t.usuario_id
    WHERE c.paciente_id = ?
    ORDER BY a.data DESC, h.hora_inicio DESC
    LIMIT 20
');
$stmt->execute([$paciente_id]);
$historico = $stmt->fetchAll();

// ── Ciclo ativo ──
$stmt = $pdo->prepare('
    SELECT c.id, c.total_sessoes,
           COUNT(CASE WHEN a.status = "realizado" THEN 1 END) AS feitas,
           COUNT(CASE WHEN a.status = "faltou" AND a.justificativa IS NULL THEN 1 END) AS faltas_inj,
           u.nome AS terapeuta_nome, t.especialidade
    FROM ciclos c
    JOIN terapeutas t ON t.id = c.terapeuta_id
    JOIN usuarios u ON u.id = t.usuario_id
    LEFT JOIN agendamentos a ON a.ciclo_id = c.id
    WHERE c.paciente_id = ? AND c.status = "ativo"
    GROUP BY c.id LIMIT 1
');
$stmt->execute([$paciente_id]);
$ciclo = $stmt->fetch();

// ── Terapeutas ──
$terapeutas = $pdo->query('
    SELECT t.id, u.nome, t.especialidade,
           COUNT(DISTINCT h.id) AS vagas
    FROM terapeutas t
    JOIN usuarios u ON u.id = t.usuario_id
    JOIN horario_terapeutas ht ON ht.terapeuta_id = t.id
    JOIN horarios h ON h.id = ht.horario_id AND h.ativo = 1
    WHERE t.ativo = 1
    GROUP BY t.id, u.nome, t.especialidade
')->fetchAll();

// ── Frases do banco ou fallback ──
try {
    $frases_db = $pdo->query('SELECT texto FROM frases WHERE tipo="paciente" AND ativo=1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $frases = !empty($frases_db) ? $frases_db : ['"Cuidar de você também é prioridade. Respire fundo."'];
} catch (Exception $e) {
    $frases = ['"Cuidar de você também é prioridade. Respire fundo."'];
}
$frase = $frases[date('z') % count($frases)];

// ── Playlists do banco ou fallback ──
try {
    $playlists_db = $pdo->query('SELECT emoji, nome, url FROM playlists WHERE ativo=1 ORDER BY ordem')->fetchAll();
    $playlists = !empty($playlists_db) ? $playlists_db : [
        ['emoji'=>'🌿','nome'=>'Sons da natureza','url'=>'https://www.youtube.com/watch?v=1ZYbU82GVz4'],
    ];
} catch (Exception $e) {
    $playlists = [
        ['emoji'=>'🌿','nome'=>'Sons da natureza','url'=>'https://www.youtube.com/watch?v=1ZYbU82GVz4'],
        ['emoji'=>'🌊','nome'=>'Ruído branco','url'=>'https://www.youtube.com/watch?v=nMfPqeZjc2c'],
        ['emoji'=>'🎹','nome'=>'Piano suave','url'=>'https://www.youtube.com/watch?v=jfKfPfyJRdk'],
        ['emoji'=>'🎻','nome'=>'Instrumental','url'=>'https://www.youtube.com/watch?v=7NOSDKb0HlU'],
        ['emoji'=>'🎵','nome'=>'MPB leve','url'=>'https://www.youtube.com/watch?v=dDo3IHiXMeI'],
    ];
}

$meses_pt = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

// ── Práticas ──
$praticas = [
    ['id'=>'respiracao','titulo'=>'Respiração Guiada','duracao'=>'5 min · Suave',
     'badge'=>['cor'=>'bg-pink-700/80','txt'=>'Recomendado'],
     'desc'=>'Acalme a mente ansiosa e reequilibre o corpo em poucos minutos.',
     'img'=>'https://images.unsplash.com/photo-1506126613408-eca07ce68773?w=400&q=80',
     'musica'=>['nome'=>'Sons da Natureza 🌿','url'=>'https://www.youtube.com/watch?v=1ZYbU82GVz4'],
     'passos'=>[
       ['icone'=>'🧘','titulo'=>'Início','texto'=>'Sente-se confortavelmente. Relaxe os ombros. Se puder, feche os olhos.'],
       ['icone'=>'🫁','titulo'=>'Condução','texto'=>"Inspire pelo nariz (1…2…3…4)\nSegure um instante…\nExpire pela boca (1…2…3…4…5…6)\nRepita. Apenas observe o ar entrando e saindo."],
       ['icone'=>'🌿','titulo'=>'Fechamento','texto'=>"Respire fundo mais uma vez… e solte devagar.\n👉 Você pode seguir com seu dia mais leve."],
     ]],
    ['id'=>'meditacao','titulo'=>'Meditação Breve','duracao'=>'7 min · Moderada',
     'badge'=>['cor'=>'bg-purple-700/80','txt'=>'Destaque'],
     'desc'=>'Foque no agora e liberte-se de preocupações futuras.',
     'img'=>'https://images.unsplash.com/photo-1508672019048-805c876b67e2?w=400&q=80',
     'musica'=>['nome'=>'Piano Suave 🎹','url'=>'https://www.youtube.com/watch?v=jfKfPfyJRdk'],
     'passos'=>[
       ['icone'=>'🧘','titulo'=>'Início','texto'=>'Encontre uma posição confortável. Mantenha a coluna levemente ereta.'],
       ['icone'=>'🧠','titulo'=>'Condução','texto'=>"Traga sua atenção para o momento presente.\nObserve: sua respiração, seu corpo, os sons ao redor.\nSe pensamentos surgirem, deixe passar… como nuvens."],
       ['icone'=>'🌿','titulo'=>'Fechamento','texto'=>"Leve uma mão ao peito.\nRespire fundo.\n👉 Você tirou um tempo pra você."],
     ]],
    ['id'=>'escalda','titulo'=>'Escalda-pés Relaxante','duracao'=>'10–15 min · Suave',
     'badge'=>['cor'=>'bg-teal-700/80','txt'=>'Ancestral'],
     'desc'=>'Um ritual ancestral para descarregar tensões e preparar o sono.',
     'img'=>'https://images.unsplash.com/photo-1544161515-4ab6ce6db874?w=400&q=80',
     'musica'=>['nome'=>'Ruído Branco 🌊','url'=>'https://www.youtube.com/watch?v=nMfPqeZjc2c'],
     'passos'=>[
       ['icone'=>'🧘','titulo'=>'Início','texto'=>'Prepare um recipiente com água morna. Sente-se confortavelmente.'],
       ['icone'=>'🌊','titulo'=>'Condução','texto'=>"Coloque os pés na água… devagar.\nSinta a temperatura. Respire fundo.\nImagine o estresse saindo do corpo…"],
       ['icone'=>'🌿','titulo'=>'Fechamento','texto'=>"Retire os pés lentamente. Seque com calma.\n👉 Perceba o relaxamento no corpo inteiro."],
     ]],
    ['id'=>'estresse','titulo'=>'Relaxamento Rápido','duracao'=>'3 min · Rápido',
     'badge'=>['cor'=>'bg-orange-700/80','txt'=>'Urgente'],
     'desc'=>'Para momentos de alta pressão. Pause, respire, recentre.',
     'img'=>'https://images.unsplash.com/photo-1499209974431-9dddcece7f88?w=400&q=80',
     'musica'=>['nome'=>'Sons da Natureza 🌿','url'=>'https://www.youtube.com/watch?v=1ZYbU82GVz4'],
     'passos'=>[
       ['icone'=>'🧘','titulo'=>'Início','texto'=>'Pare por um momento. Respire fundo.'],
       ['icone'=>'🧠','titulo'=>'Condução','texto'=>"Pergunte a si mesmo:\n👉 \"O que realmente precisa da minha atenção agora?\"\nDeixe o resto de lado. Solte a tensão do corpo."],
       ['icone'=>'🌿','titulo'=>'Fechamento','texto'=>"Você não precisa resolver tudo hoje.\nUm passo de cada vez."],
     ]],
    ['id'=>'massoterapia','titulo'=>'Preparação para Massoterapia','duracao'=>'5 min · Suave',
     'badge'=>['cor'=>'bg-indigo-700/80','txt'=>'Pré-sessão'],
     'desc'=>'Prepare corpo e mente para receber o cuidado integrativo.',
     'img'=>'https://images.unsplash.com/photo-1600334089648-b0d9d3028eb2?w=400&q=80',
     'musica'=>['nome'=>'Instrumental 🎻','url'=>'https://www.youtube.com/watch?v=7NOSDKb0HlU'],
     'passos'=>[
       ['icone'=>'🧘','titulo'=>'Início','texto'=>'Antes do atendimento, desacelere. Evite celular ou estímulos intensos.'],
       ['icone'=>'🫁','titulo'=>'Condução','texto'=>"Respire fundo 3 vezes.\nRelaxe o corpo. Solte os ombros, mandíbula e mãos."],
       ['icone'=>'🌿','titulo'=>'Orientação','texto'=>"Permita-se receber o cuidado.\n👉 Apenas esteja presente."],
     ]],
    ['id'=>'aterramento','titulo'=>'Aterramento (Ansiedade)','duracao'=>'5 min · Suave',
     'badge'=>['cor'=>'bg-emerald-700/80','txt'=>'Ansiedade'],
     'desc'=>'Técnica 5-4-3-2-1 para reconectar com o momento presente.',
     'img'=>'https://images.unsplash.com/photo-1476611338391-6f395a0dd82e?w=400&q=80',
     'musica'=>['nome'=>'MPB Leve 🎵','url'=>'https://www.youtube.com/watch?v=dDo3IHiXMeI'],
     'passos'=>[
       ['icone'=>'🧘','titulo'=>'Início','texto'=>'Olhe ao seu redor. Respire fundo.'],
       ['icone'=>'🧠','titulo'=>'Condução (5-4-3-2-1)','texto'=>"Identifique:\n• 5 coisas que você vê\n• 4 que pode tocar\n• 3 que pode ouvir\n• 2 que pode sentir\n• 1 que pode cheirar"],
       ['icone'=>'🌿','titulo'=>'Fechamento','texto'=>'Você está aqui. Agora. Seguro.'],
     ]],
];

$cores = [['bg'=>'#E1F5EE','txt'=>'#085041'],['bg'=>'#E6F1FB','txt'=>'#0C447C'],['bg'=>'#FAEEDA','txt'=>'#633806'],['bg'=>'#FBEAF0','txt'=>'#72243E'],['bg'=>'#EAF3DE','txt'=>'#27500A']];
function ini($n){$p=explode(' ',$n);$i='';foreach($p as $x){$i.=strtoupper(mb_substr($x,0,1));if(strlen($i)>=2)break;}return $i;}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NUPICS — Portal do Paciente</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <script>tailwind.config={theme:{extend:{fontFamily:{headline:['Plus Jakarta Sans'],body:['Manrope']},colors:{primary:'#4e0078',secondary:'#b7004d'}}}}</script>
  <style>
    body{font-family:'Manrope',sans-serif;background:radial-gradient(circle at top left,#f7eaf8,#fff7fc);}
    h1,h2,h3,.headline{font-family:'Plus Jakarta Sans',sans-serif;}
    .material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;}
    .glass{background:rgba(255,255,255,0.65);backdrop-filter:blur(12px);}
    .grad{background:linear-gradient(135deg,#4e0078,#b7004d);}
    .pratica-modal{display:none;position:fixed;inset:0;z-index:100;background:rgba(32,25,35,0.6);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:1rem;}
    .pratica-modal.open{display:flex;}
    .modal-just{display:none;position:fixed;inset:0;z-index:200;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:1rem;}
    .modal-just.open{display:flex;}
    .passo-item{transition:all .3s;}
    .passo-item.ativo{background:linear-gradient(135deg,rgba(78,0,120,.07),rgba(183,0,77,.07));border-left:3px solid #4e0078;}
    ::-webkit-scrollbar{width:4px;} ::-webkit-scrollbar-thumb{background:#d0c2d3;border-radius:2px;}
    .player-prog{height:4px;border-radius:2px;background:linear-gradient(90deg,#4e0078,#b7004d);width:25%;}
  </style>
</head>
<body class="text-gray-900 min-h-screen">

<!-- TOPNAV -->
<nav class="fixed top-0 w-full z-50 bg-white/70 backdrop-blur-md shadow-sm">
  <div class="flex justify-between items-center px-5 md:px-10 h-14 max-w-7xl mx-auto">
    <span class="text-lg font-extrabold headline"
          style="background:linear-gradient(135deg,#4e0078,#b7004d);-webkit-background-clip:text;-webkit-text-fill-color:transparent">
      NUPICS
    </span>
    <div class="hidden md:flex items-center gap-7 font-semibold text-sm">
      <a href="dashboard.php" class="text-purple-900 border-b-2 border-purple-700 pb-0.5">Início</a>
      <a href="agendar.php"   class="text-gray-500 hover:text-purple-700 transition-colors">Agendar</a>
      <a href="visita.php"    class="text-gray-500 hover:text-purple-700 transition-colors">Visita</a>
    </div>
    <div class="flex items-center gap-2">
      <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold"
           style="background:#E1F5EE;color:#085041"><?= ini($paciente['nome']) ?></div>
      <span class="text-sm font-semibold text-gray-600 hidden md:block"><?= htmlspecialchars($primeiro) ?></span>
      <a href="../api/trocar_senha.php"
         class="text-xs text-purple-400 hover:text-purple-700 border border-purple-200 rounded-full px-3 py-1 transition-colors hidden md:block">Senha</a>
      <a href="../api/logout.php"
         class="text-xs text-purple-400 hover:text-purple-700 border border-purple-200 rounded-full px-3 py-1 transition-colors">Sair</a>
    </div>
  </div>
</nav>

<main class="pt-20 pb-20 px-4 md:px-8 max-w-7xl mx-auto space-y-10">

  <?php if ($sucesso): ?>
  <div class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm font-medium mt-2">
    ✓ <?= htmlspecialchars($sucesso) ?>
  </div>
  <?php endif; ?>
  <?php if ($erro): ?>
  <div class="bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm font-medium mt-2">
    ⚠ <?= htmlspecialchars($erro) ?>
  </div>
  <?php endif; ?>

  <!-- HERO -->
  <section class="grid grid-cols-1 md:grid-cols-12 gap-5 items-end mt-2">
    <div class="md:col-span-8">
      <h1 class="text-3xl md:text-4xl font-extrabold headline text-purple-900">
        Olá, <?= htmlspecialchars($primeiro) ?> 👋
      </h1>
      <p class="text-gray-500 mt-2 max-w-xl">Cuidar de você também é prioridade. Respire fundo e aproveite seu momento de paz.</p>
    </div>
    <div class="md:col-span-4">
      <?php if ($proximo): ?>
      <div class="glass p-4 rounded-2xl border border-purple-100/40 shadow-sm flex items-center gap-3">
        <div class="w-11 h-11 rounded-full flex items-center justify-center text-white flex-shrink-0 grad">
          <span class="material-symbols-outlined" style="font-size:20px">calendar_today</span>
        </div>
        <div>
          <p class="text-[10px] font-bold uppercase tracking-widest text-purple-400">Próximo Atendimento</p>
          <p class="font-bold text-purple-900 text-sm"><?= htmlspecialchars($proximo['especialidade'] ?? 'Sessão') ?></p>
          <p class="text-xs font-semibold text-pink-600">
            <?php
            $dt   = new DateTime($proximo['data']);
            $hoje = new DateTime();
            $diff = (int)$hoje->diff($dt)->days;
            $pre  = $diff === 0 ? 'Hoje' : ($diff === 1 ? 'Amanhã' : $dt->format('d/m'));
            echo $pre . ' às ' . substr($proximo['hora_inicio'],0,5);
            ?>
          </p>
        </div>
      </div>
      <?php else: ?>
      <div class="glass p-4 rounded-2xl border border-dashed border-purple-200 text-center">
        <p class="text-sm text-purple-400">Nenhuma sessão agendada</p>
        <a href="agendar.php" class="text-sm font-bold text-purple-700 mt-1 block hover:underline">Agendar agora →</a>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- CHECK-IN + NUPICS INFO -->
  <section class="grid grid-cols-1 md:grid-cols-3 gap-5">
    <div class="md:col-span-2 glass p-6 rounded-2xl border border-purple-100/30 space-y-5">
      <div class="flex justify-between items-center">
        <h2 class="text-lg font-bold headline text-gray-800">Como você está se sentindo hoje?</h2>
        <span class="material-symbols-outlined text-purple-300" style="font-size:24px">mood</span>
      </div>
      <div class="flex flex-wrap justify-between gap-2">
        <?php
        $emocoes = [
          ['icon'=>'sentiment_very_satisfied','label'=>'Bem'],
          ['icon'=>'sentiment_neutral','label'=>'Neutro'],
          ['icon'=>'battery_low','label'=>'Cansado'],
          ['icon'=>'psychology','label'=>'Ansioso','destaque'=>true],
          ['icon'=>'layers_clear','label'=>'Sobrecarregado'],
          ['icon'=>'sentiment_dissatisfied','label'=>'Triste'],
        ];
        foreach ($emocoes as $e):
          $bg = ($e['destaque'] ?? false) ? 'bg-pink-100' : 'bg-gray-100';
        ?>
        <button class="flex flex-col items-center gap-1.5 group" onclick="selecionarEmocao(this,'<?= $e['label'] ?>')">
          <div class="w-12 h-12 rounded-full <?= $bg ?> hover:bg-purple-100 flex items-center justify-center transition-all group-hover:scale-110">
            <span class="material-symbols-outlined text-purple-700" style="font-size:24px"><?= $e['icon'] ?></span>
          </div>
          <span class="text-[11px] font-medium text-gray-600"><?= $e['label'] ?></span>
        </button>
        <?php endforeach; ?>
      </div>
      <div class="rounded-2xl flex items-center gap-4 p-4" style="background:#ffd9de">
        <div class="p-2 bg-white/60 rounded-full flex-shrink-0">
          <span class="material-symbols-outlined text-pink-700" style="font-size:20px">wind_power</span>
        </div>
        <div class="flex-1">
          <p class="font-bold text-gray-800 text-sm" id="sugestao-titulo">Sugerimos: Respiração Guiada</p>
          <p class="text-xs text-gray-600" id="sugestao-desc">Para acalmar a mente ansiosa em apenas 5 minutos.</p>
        </div>
        <button onclick="abrirPratica('respiracao')"
                class="px-4 py-2 text-white text-xs font-bold rounded-full hover:opacity-90 whitespace-nowrap"
                style="background:#b7004d">
          Começar
        </button>
      </div>
    </div>

    <div class="rounded-2xl text-white relative overflow-hidden p-6 flex flex-col justify-between min-h-[240px] grad">
      <div class="relative z-10 space-y-3">
        <h3 class="text-lg font-extrabold headline">NUPICS Caicó</h3>
        <p class="text-purple-200 text-sm leading-relaxed">
          Projeto de extensão da UERN com atendimentos integrativos gratuitos para toda a comunidade, sob supervisão docente.
        </p>
        <a href="agendar.php"
           class="inline-block py-2 px-5 bg-white text-purple-900 font-bold text-sm rounded-full hover:bg-purple-50 transition-colors">
          Agendar sessão →
        </a>
      </div>
      <div class="absolute -bottom-6 -right-6 opacity-20">
        <span class="material-symbols-outlined" style="font-size:120px">eco</span>
      </div>
    </div>
  </section>

  <!-- AÇÕES RÁPIDAS -->
  <section class="space-y-4">
    <h2 class="text-xl font-extrabold headline text-gray-800">Ações Rápidas</h2>
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
      <?php
      $acoes = [
        ['icon'=>'add_circle',  'label'=>'Agendar',         'href'=>'agendar.php',  'cor'=>'text-purple-700'],
        ['icon'=>'event_repeat','label'=>'Reagendar',       'href'=>'agendar.php',  'cor'=>'text-purple-700'],
        ['icon'=>'cancel',      'label'=>'Cancelar',        'href'=>'dashboard.php','cor'=>'text-red-500'],
        ['icon'=>'group',       'label'=>'Ver Terapeuta',   'href'=>'dashboard.php','cor'=>'text-purple-700'],
        ['icon'=>'map',         'label'=>'Solicitar Visita','href'=>'visita.php',   'cor'=>'text-purple-700'],
      ];
      foreach ($acoes as $a):
      ?>
      <a href="<?= $a['href'] ?>"
         class="glass border border-white/60 rounded-2xl p-4 flex flex-col items-center gap-2 hover:shadow-lg transition-all group">
        <span class="material-symbols-outlined text-2xl <?= $a['cor'] ?> group-hover:scale-110 transition-transform"><?= $a['icon'] ?></span>
        <span class="text-xs font-semibold text-gray-700 text-center"><?= $a['label'] ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- GRADE PRINCIPAL -->
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

    <!-- COLUNA ESQUERDA -->
    <div class="lg:col-span-8 space-y-10">

      <!-- Práticas -->
      <section class="space-y-4">
        <h2 class="text-xl font-extrabold headline text-gray-800">Seu Momento de Cuidado</h2>

        <!-- 2 práticas em destaque -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
          <?php foreach (array_slice($praticas, 0, 2) as $pr): ?>
          <div class="glass rounded-2xl overflow-hidden group hover:shadow-xl transition-all cursor-pointer"
               onclick="abrirPratica('<?= $pr['id'] ?>')">
            <div class="h-36 relative overflow-hidden">
              <img src="<?= $pr['img'] ?>" alt="<?= $pr['titulo'] ?>"
                   class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
              <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(0,0,0,.6),transparent)"></div>
              <span class="absolute bottom-2 left-3 text-[9px] uppercase font-bold tracking-widest text-white px-2 py-0.5 rounded <?= $pr['badge']['cor'] ?>">
                <?= $pr['badge']['txt'] ?>
              </span>
            </div>
            <div class="p-4 space-y-1.5">
              <div class="flex justify-between items-start">
                <h4 class="font-bold text-sm text-gray-800"><?= $pr['titulo'] ?></h4>
                <span class="text-[10px] text-gray-400 ml-2 whitespace-nowrap"><?= $pr['duracao'] ?></span>
              </div>
              <p class="text-xs text-gray-500"><?= $pr['desc'] ?></p>
              <button class="flex items-center gap-1 text-purple-700 font-bold text-xs">
                <span class="material-symbols-outlined" style="font-size:16px;font-variation-settings:'FILL' 1">play_circle</span>
                Começar prática
              </button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Grid 4 práticas -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <?php foreach (array_slice($praticas, 2) as $pr): ?>
          <div class="glass rounded-2xl overflow-hidden cursor-pointer hover:shadow-lg transition-all group"
               onclick="abrirPratica('<?= $pr['id'] ?>')">
            <div class="h-24 relative overflow-hidden">
              <img src="<?= $pr['img'] ?>" alt="<?= $pr['titulo'] ?>"
                   class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700">
              <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(0,0,0,.65),transparent)"></div>
              <span class="absolute bottom-1.5 left-2 text-[8px] uppercase font-bold text-white px-1.5 py-0.5 rounded <?= $pr['badge']['cor'] ?>">
                <?= $pr['badge']['txt'] ?>
              </span>
            </div>
            <div class="p-2.5">
              <h4 class="font-bold text-xs text-gray-800 leading-tight"><?= $pr['titulo'] ?></h4>
              <p class="text-[9px] text-gray-400 mt-0.5"><?= $pr['duracao'] ?></p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- Ambiente Terapêutico -->
      <section class="glass border border-purple-100/30 rounded-2xl p-6 space-y-4">
        <div class="flex items-center gap-3">
          <div class="p-2.5 rounded-xl text-white grad">
            <span class="material-symbols-outlined" style="font-size:20px">graphic_eq</span>
          </div>
          <div>
            <h3 class="text-lg font-bold headline text-gray-800">Ambiente Terapêutico</h3>
            <p class="text-xs text-gray-400">Músicas para harmonizar o seu espaço.</p>
          </div>
        </div>

        <div class="flex flex-col md:flex-row items-center gap-5 rounded-2xl p-4" style="background:#fdeffe">
          <div class="w-20 h-20 rounded-xl overflow-hidden flex-shrink-0">
            <img src="https://images.unsplash.com/photo-1441974231531-c6227db76b6e?w=200&q=80" class="w-full h-full object-cover">
          </div>
          <div class="flex-1 space-y-2 w-full">
            <div class="flex justify-between items-end">
              <div>
                <p class="font-bold text-purple-900 text-sm" id="ambiente-nome">
                  <?= $playlists[0]['emoji'] ?> <?= $playlists[0]['nome'] ?>
                </p>
                <p class="text-xs text-gray-400">Ambiente imersivo</p>
              </div>
              <span class="text-xs font-mono text-gray-400">ao vivo</span>
            </div>
            <div class="h-1 bg-gray-200 rounded-full"><div class="player-prog" id="player-prog"></div></div>
            <div class="flex justify-center gap-7 items-center">
              <button onclick="mudarPlaylist(-1)" class="text-gray-400 hover:text-purple-700 transition-colors">
                <span class="material-symbols-outlined">skip_previous</span>
              </button>
              <button onclick="togglePlayer()"
                      id="btn-play"
                      class="w-10 h-10 rounded-full text-white flex items-center justify-center hover:scale-105 transition-all grad">
                <span class="material-symbols-outlined" style="font-variation-settings:'FILL' 1">play_arrow</span>
              </button>
              <button onclick="mudarPlaylist(1)" class="text-gray-400 hover:text-purple-700 transition-colors">
                <span class="material-symbols-outlined">skip_next</span>
              </button>
            </div>
          </div>
          <button onclick="togglePlayer()"
                  class="px-5 py-2.5 border-2 border-purple-700 text-purple-700 font-bold text-sm rounded-full hover:bg-purple-700 hover:text-white transition-all whitespace-nowrap">
            Preparar ambiente
          </button>
        </div>

        <div id="yt-container" class="hidden rounded-xl overflow-hidden">
          <iframe id="yt-frame" width="100%" height="90" frameborder="0" allow="autoplay;encrypted-media" style="border-radius:10px"></iframe>
        </div>

        <div class="flex flex-wrap gap-2">
          <?php foreach ($playlists as $i => $pl): ?>
          <button onclick="selecionarPlaylist(<?= $i ?>)"
                  class="playlist-btn flex items-center gap-1.5 px-3 py-1.5 rounded-full border text-xs font-semibold transition-all"
                  style="border-color:#d0c2d3;color:#4d4351"
                  data-url="<?= htmlspecialchars($pl['url']) ?>"
                  data-nome="<?= htmlspecialchars($pl['emoji'].' '.$pl['nome']) ?>">
            <?= $pl['emoji'] ?> <?= htmlspecialchars($pl['nome']) ?>
          </button>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- Histórico de sessões -->
      <section class="space-y-3">
        <h2 class="text-xl font-extrabold headline text-gray-800">Minhas sessões</h2>
        <?php if (empty($historico)): ?>
        <div class="glass rounded-2xl p-8 text-center text-gray-400 text-sm">
          Você ainda não tem sessões agendadas.
        </div>
        <?php else: ?>
        <div class="space-y-2">
          <?php foreach ($historico as $s):
            $dt      = new DateTime($s['data']);
            $data_f  = $dt->format('d/m/Y');
            $hora_f  = substr($s['hora_inicio'],0,5);
            $sc      = match($s['status']) {
              'realizado' => 'bg-blue-100 text-blue-700',
              'cancelado' => 'bg-gray-100 text-gray-500',
              'faltou'    => 'bg-red-100 text-red-700',
              default     => 'bg-green-100 text-green-700',
            };
            $st = match($s['status']){'realizado'=>'Realizado','cancelado'=>'Cancelado','faltou'=>'Faltou',default=>'Agendado'};
            $pode_justificar = ($s['status']==='agendado' && $s['data']>=date('Y-m-d') && !$s['justificativa']);
          ?>
          <div class="glass border border-white/60 rounded-2xl p-4 flex items-start gap-3 flex-wrap">
            <div class="w-9 h-9 rounded-full grad flex items-center justify-center text-white text-xs font-bold flex-shrink-0 mt-0.5">
              <?= $s['numero_sessao'] ?>
            </div>
            <div class="flex-1 min-w-[160px]">
              <div class="font-bold text-sm text-gray-800">
                <?= htmlspecialchars($s['terapeuta_nome'] ?? 'Terapeuta') ?>
              </div>
              <div class="text-xs text-gray-400">
                <?= htmlspecialchars($s['especialidade'] ?? '—') ?>
                · <?= $data_f ?> às <?= $hora_f ?>
                · Sessão <?= $s['numero_sessao'] ?>/<?= $s['total_sessoes'] ?>
              </div>
              <?php if ($s['justificativa']): ?>
              <div class="text-xs text-yellow-700 mt-1 bg-yellow-50 rounded-lg px-2 py-1">
                ✓ Falta justificada
              </div>
              <?php endif; ?>
              <?php if ($pode_justificar): ?>
              <button onclick="document.getElementById('just-<?= $s['id'] ?>').classList.add('open')"
                      class="mt-1.5 text-[10px] font-bold text-yellow-700 border border-yellow-300 bg-yellow-50 rounded-full px-3 py-1 hover:bg-yellow-100 transition-colors">
                ⚠ Justificar falta
              </button>
              <?php endif; ?>
            </div>
            <span class="px-2.5 py-1 text-[10px] font-bold rounded-full <?= $sc ?>"><?= $st ?></span>
          </div>

          <?php if ($pode_justificar): ?>
          <!-- Modal justificativa -->
          <div class="modal-just" id="just-<?= $s['id'] ?>">
            <div class="bg-white rounded-2xl p-6 max-w-md w-full shadow-2xl">
              <h3 class="text-base font-extrabold headline text-gray-800 mb-2">Justificar falta</h3>
              <p class="text-xs text-gray-500 mb-4 leading-relaxed">
                Você tem direito a <strong>1 justificativa por ciclo</strong>.
                Se faltar sem justificar, a sessão é descartada automaticamente pelo sistema.
              </p>
              <form method="POST">
                <input type="hidden" name="acao" value="justificar_falta">
                <input type="hidden" name="agendamento_id" value="<?= $s['id'] ?>">
                <textarea name="justificativa" rows="3" required
                          placeholder="Explique o motivo da falta..."
                          class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400 resize-none mb-3 font-sans"></textarea>
                <div class="flex gap-2">
                  <button type="button"
                          onclick="document.getElementById('just-<?= $s['id'] ?>').classList.remove('open')"
                          class="flex-1 py-2.5 border border-gray-200 rounded-xl font-semibold text-sm text-gray-600 hover:bg-gray-50">
                    Cancelar
                  </button>
                  <button type="submit"
                          class="flex-1 py-2.5 grad text-white font-bold text-sm rounded-xl hover:opacity-90">
                    Enviar
                  </button>
                </div>
              </form>
            </div>
          </div>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </section>

    </div><!-- fim col esquerda -->

    <!-- COLUNA DIREITA -->
    <div class="lg:col-span-4 space-y-6">

      <!-- Frase do dia -->
      <div class="rounded-2xl p-6 relative overflow-hidden" style="background:#f4dce4">
        <span class="material-symbols-outlined absolute top-2 right-2 opacity-15 rotate-12"
              style="font-size:60px;color:#3a2c32">format_quote</span>
        <p class="text-xs font-bold uppercase tracking-widest text-pink-700 mb-2">Inspiração do dia</p>
        <p class="text-sm italic leading-relaxed relative z-10" style="color:#25181e">
          <?= htmlspecialchars($frase) ?>
        </p>
      </div>

      <!-- Ciclo ativo -->
      <?php if ($ciclo): ?>
      <div class="glass border border-purple-100/30 rounded-2xl p-5">
        <h3 class="text-xs font-extrabold uppercase tracking-widest text-purple-400 mb-1">Meu ciclo atual</h3>
        <p class="text-xs text-gray-500 mb-3">
          <?= htmlspecialchars($ciclo['terapeuta_nome']) ?> · <?= htmlspecialchars($ciclo['especialidade'] ?? '—') ?>
        </p>
        <div class="flex gap-2 mb-2">
          <?php for ($i = 1; $i <= $ciclo['total_sessoes']; $i++):
            if ($i <= $ciclo['feitas'])       $cls = 'grad text-white';
            elseif ($i === $ciclo['feitas']+1) $cls = 'border-2 border-purple-700 text-purple-700 bg-purple-50';
            else                               $cls = 'bg-gray-100 text-gray-400';
          ?>
          <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold <?= $cls ?>">
            <?= $i ?>
          </div>
          <?php endfor; ?>
        </div>
        <p class="text-xs text-gray-400"><?= $ciclo['feitas'] ?> de <?= $ciclo['total_sessoes'] ?> sessões realizadas</p>
        <?php if ($ciclo['faltas_inj'] > 0): ?>
        <p class="text-xs text-red-500 mt-1 font-semibold">
          ⚠ <?= $ciclo['faltas_inj'] ?> falta<?= $ciclo['faltas_inj']>1?'s':'' ?> sem justificativa
        </p>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Terapeutas -->
      <div class="glass border border-purple-100/30 rounded-2xl p-5">
        <h3 class="text-xs font-extrabold uppercase tracking-widest text-purple-400 mb-3">Nossa equipe</h3>
        <div class="space-y-3">
          <?php foreach (array_slice($terapeutas, 0, 4) as $i => $t):
            $cor = $cores[$i % count($cores)];
          ?>
          <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0"
                 style="background:<?= $cor['bg'] ?>;color:<?= $cor['txt'] ?>">
              <?= ini($t['nome']) ?>
            </div>
            <div class="flex-1 min-w-0">
              <div class="text-xs font-bold text-gray-800 truncate"><?= htmlspecialchars($t['nome']) ?></div>
              <div class="text-[10px] text-gray-400"><?= htmlspecialchars($t['especialidade']) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <a href="agendar.php"
           class="block w-full py-2 text-center text-purple-700 font-bold text-xs border-t border-gray-100 mt-3 hover:bg-purple-50 rounded-b-xl transition-colors">
          Agendar com um terapeuta →
        </a>
      </div>

      <!-- Como funciona -->
      <div class="glass border border-purple-100/30 rounded-2xl p-5 space-y-2">
        <h3 class="font-bold text-gray-800 flex items-center gap-2 text-sm">
          <span class="material-symbols-outlined text-purple-600" style="font-size:18px">help_center</span>
          Como Funciona?
        </h3>
        <p class="text-xs text-gray-500">Atendimentos 100% gratuitos para a comunidade de Caicó/RN.</p>
        <div class="flex items-center gap-2 text-xs text-gray-600">
          <span class="material-symbols-outlined text-purple-600" style="font-size:14px">location_on</span>
          Campus UERN, Caicó/RN
        </div>
        <div class="flex items-center gap-2 text-xs text-gray-600">
          <span class="material-symbols-outlined text-purple-600" style="font-size:14px">schedule</span>
          Ciclos de 4 sessões semanais
        </div>
        <a href="visita.php"
           class="block w-full py-2 text-center text-purple-700 font-bold text-xs border-t border-gray-100 mt-2 hover:bg-purple-50 rounded-b-xl transition-colors">
          Solicitar visita externa →
        </a>
      </div>

    </div>
  </div>

</main>

<!-- FOOTER -->
<footer class="py-8 px-8" style="background:#f7eaf8">
  <div class="max-w-7xl mx-auto flex flex-col md:flex-row justify-between items-center gap-3 text-xs text-gray-400">
    <span class="font-extrabold text-purple-900 text-sm headline">NUPICS — UERN</span>
    <span>© <?= date('Y') ?> NUPICS — Saúde Integrativa e Bem-estar.</span>
  </div>
</footer>

<!-- MODAIS DAS PRÁTICAS -->
<?php foreach ($praticas as $pr): ?>
<div class="pratica-modal" id="modal-<?= $pr['id'] ?>">
  <div class="bg-white rounded-3xl w-full max-w-lg max-h-[92vh] flex flex-col overflow-hidden shadow-2xl">
    <div class="flex items-center justify-between px-6 pt-5 pb-4 border-b border-gray-100">
      <div>
        <h3 class="text-base font-extrabold headline text-purple-900"><?= $pr['titulo'] ?></h3>
        <p class="text-xs text-gray-400 mt-0.5"><?= $pr['duracao'] ?> · <?= $pr['musica']['nome'] ?></p>
      </div>
      <button onclick="fecharPratica('<?= $pr['id'] ?>')"
              class="w-8 h-8 rounded-full bg-gray-100 hover:bg-gray-200 flex items-center justify-center text-gray-500">✕</button>
    </div>

    <div class="px-6 py-3 border-b border-gray-50" style="background:#fdeffe">
      <div class="flex items-center gap-3">
        <button onclick="togglePraticaPlayer('<?= $pr['id'] ?>','<?= $pr['musica']['url'] ?>')"
                id="btn-pratica-<?= $pr['id'] ?>"
                class="w-9 h-9 rounded-full text-white flex items-center justify-center flex-shrink-0 grad hover:opacity-90">
          <span class="material-symbols-outlined" style="font-size:18px;font-variation-settings:'FILL' 1">play_arrow</span>
        </button>
        <div class="flex-1">
          <p class="text-xs font-bold text-purple-900"><?= $pr['musica']['nome'] ?></p>
          <div class="h-1 bg-gray-200 rounded-full mt-1"><div class="player-prog" style="width:0%"></div></div>
        </div>
        <span class="material-symbols-outlined text-purple-300" style="font-size:18px">headphones</span>
      </div>
      <div class="hidden mt-2 rounded-xl overflow-hidden" id="yt-pratica-<?= $pr['id'] ?>">
        <iframe width="100%" height="72" frameborder="0" allow="autoplay;encrypted-media"
                id="frame-pratica-<?= $pr['id'] ?>" style="border-radius:8px"></iframe>
      </div>
    </div>

    <div class="flex-1 overflow-y-auto px-6 py-4 space-y-2">
      <?php foreach ($pr['passos'] as $idx => $passo): ?>
      <div class="passo-item p-4 rounded-2xl cursor-pointer border border-transparent hover:border-purple-100 <?= $idx===0?'ativo':'' ?>"
           onclick="ativarPasso(this)">
        <div class="flex items-center gap-2 mb-1.5">
          <span class="text-lg"><?= $passo['icone'] ?></span>
          <span class="text-sm font-extrabold text-purple-900"><?= $passo['titulo'] ?></span>
        </div>
        <p class="text-sm text-gray-600 leading-relaxed whitespace-pre-line"><?= htmlspecialchars($passo['texto']) ?></p>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="px-6 pb-5 pt-3 border-t border-gray-100">
      <button onclick="fecharPratica('<?= $pr['id'] ?>')"
              class="w-full py-3 grad text-white font-bold text-sm rounded-xl hover:opacity-90">
        Concluir prática
      </button>
    </div>
  </div>
</div>
<?php endforeach; ?>

<script>
var playlists = <?= json_encode(array_values($playlists)) ?>;
var playlistIdx = 0;
var playerAberto = false;

function togglePlayer() {
  var c = document.getElementById('yt-container');
  var f = document.getElementById('yt-frame');
  var b = document.getElementById('btn-play');
  if (!playerAberto) {
    var url = playlists[playlistIdx].url;
    var vid = url.match(/(?:v=|youtu\.be\/)([^&\s]+)/);
    if (vid) {
      f.src = 'https://www.youtube.com/embed/' + vid[1] + '?autoplay=1';
      c.classList.remove('hidden');
      b.innerHTML = '<span class="material-symbols-outlined" style="font-variation-settings:\'FILL\' 1">pause</span>';
      playerAberto = true;
    }
  } else {
    f.src = ''; c.classList.add('hidden');
    b.innerHTML = '<span class="material-symbols-outlined" style="font-variation-settings:\'FILL\' 1">play_arrow</span>';
    playerAberto = false;
  }
}

function mudarPlaylist(dir) {
  playlistIdx = (playlistIdx + dir + playlists.length) % playlists.length;
  document.getElementById('ambiente-nome').textContent = playlists[playlistIdx].nome || playlists[playlistIdx].emoji + ' ' + playlists[playlistIdx].nome;
  if (playerAberto) { playerAberto = false; togglePlayer(); }
}

function selecionarPlaylist(idx) {
  playlistIdx = idx;
  var p = playlists[idx];
  document.getElementById('ambiente-nome').textContent = (p.emoji||'') + ' ' + p.nome;
  document.querySelectorAll('.playlist-btn').forEach(function(b, i) {
    b.style.background  = i===idx ? '#4e0078' : '';
    b.style.color       = i===idx ? 'white'   : '#4d4351';
    b.style.borderColor = i===idx ? '#4e0078' : '#d0c2d3';
  });
  if (playerAberto) { playerAberto = false; togglePlayer(); }
}

var sugestoes = {
  'Bem':           ['Meditação Breve',        'Para aprofundar sua paz interior.',                    'meditacao'],
  'Neutro':        ['Respiração Guiada',      'Para trazer mais presença ao momento.',                'respiracao'],
  'Cansado':       ['Escalda-pés Relaxante',  'Para aliviar o peso do corpo e da mente.',             'escalda'],
  'Ansioso':       ['Aterramento (Ansiedade)','Técnica 5-4-3-2-1 para o momento presente.',           'aterramento'],
  'Sobrecarregado':['Relaxamento Rápido',     'Pause por 3 minutos e recentre-se.',                   'estresse'],
  'Triste':        ['Meditação Breve',        'Um espaço seguro para observar seus sentimentos.',     'meditacao'],
};

function selecionarEmocao(btn, emocao) {
  var sug = sugestoes[emocao] || ['Respiração Guiada','Para cuidar de você agora.','respiracao'];
  document.getElementById('sugestao-titulo').textContent = 'Sugerimos: ' + sug[0];
  document.getElementById('sugestao-desc').textContent   = sug[1];
  document.querySelector('#sugestao-desc').closest('div').querySelector('button').onclick = function(){abrirPratica(sug[2]);};
}

var praticaFrames = {};
function abrirPratica(id) {
  document.getElementById('modal-' + id).classList.add('open');
  document.body.style.overflow = 'hidden';
}
function fecharPratica(id) {
  document.getElementById('modal-' + id).classList.remove('open');
  document.body.style.overflow = '';
  var f = document.getElementById('frame-pratica-' + id);
  if (f) f.src = '';
  var y = document.getElementById('yt-pratica-' + id);
  if (y) y.classList.add('hidden');
  praticaFrames[id] = false;
}
function togglePraticaPlayer(id, url) {
  var f = document.getElementById('frame-pratica-' + id);
  var y = document.getElementById('yt-pratica-' + id);
  var b = document.getElementById('btn-pratica-' + id);
  if (!praticaFrames[id]) {
    var vid = url.match(/(?:v=|youtu\.be\/)([^&\s]+)/);
    if (vid) {
      f.src = 'https://www.youtube.com/embed/' + vid[1] + '?autoplay=1';
      y.classList.remove('hidden');
      b.innerHTML = '<span class="material-symbols-outlined" style="font-size:18px;font-variation-settings:\'FILL\' 1">pause</span>';
      praticaFrames[id] = true;
    }
  } else {
    f.src = ''; y.classList.add('hidden');
    b.innerHTML = '<span class="material-symbols-outlined" style="font-size:18px;font-variation-settings:\'FILL\' 1">play_arrow</span>';
    praticaFrames[id] = false;
  }
}
function ativarPasso(el) {
  el.closest('.flex-1').querySelectorAll('.passo-item').forEach(function(p){ p.classList.remove('ativo'); });
  el.classList.add('ativo');
}
document.querySelectorAll('.pratica-modal').forEach(function(m) {
  m.addEventListener('click', function(e){ if(e.target===m) fecharPratica(m.id.replace('modal-','')); });
});
</script>

</body>
</html>