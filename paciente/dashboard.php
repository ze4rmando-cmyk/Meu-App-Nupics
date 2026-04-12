<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'paciente') {
    header('Location: ../index.php');
    exit;
}

$stmt = $pdo->prepare('
    SELECT p.id AS paciente_id, u.nome, u.email, u.telefone
    FROM pacientes p JOIN usuarios u ON u.id = p.usuario_id
    WHERE u.id = ?
');
$stmt->execute([$_SESSION['usuario_id']]);
$paciente    = $stmt->fetch();
$paciente_id = $paciente['paciente_id'];
$primeiro    = explode(' ', $paciente['nome'])[0];

// Próximo agendamento
$stmt = $pdo->prepare('
    SELECT a.data, h.hora_inicio, u.nome AS terapeuta_nome, t.especialidade
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

// Histórico recente
$stmt = $pdo->prepare('
    SELECT a.data, a.status, h.hora_inicio, t.especialidade, u.nome AS terapeuta_nome
    FROM agendamentos a
    JOIN horarios h ON h.id = a.horario_id
    JOIN ciclos c ON c.id = a.ciclo_id
    LEFT JOIN terapeutas t ON t.id = a.terapeuta_id
    LEFT JOIN usuarios u ON u.id = t.usuario_id
    WHERE c.paciente_id = ? AND a.data < CURDATE()
    ORDER BY a.data DESC LIMIT 3
');
$stmt->execute([$paciente_id]);
$historico = $stmt->fetchAll();

// Ciclo ativo
$stmt = $pdo->prepare('
    SELECT c.total_sessoes, COUNT(CASE WHEN a.status="realizado" THEN 1 END) AS feitas
    FROM ciclos c LEFT JOIN agendamentos a ON a.ciclo_id = c.id
    WHERE c.paciente_id = ? AND c.status = "ativo"
    GROUP BY c.id LIMIT 1
');
$stmt->execute([$paciente_id]);
$ciclo = $stmt->fetch();

// Frases do dia
$frases_db = $pdo->query('SELECT texto FROM frases WHERE tipo="paciente" AND ativo=1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$frases = !empty($frases_db) ? $frases_db : ['"Cuidar de você também é prioridade."'];
$frase  = $frases[date('z') % count($frases)];

$meses_pt = ['','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

// Práticas
$praticas = [
    [
        'id'    => 'respiracao',
        'titulo'=> 'Respiração Guiada',
        'duracao'=> '5 min • Suave',
        'badge' => ['cor'=>'bg-pink-700/80','txt'=>'Recomendado'],
        'desc'  => 'Acalme a mente ansiosa e reequilibre o corpo em poucos minutos.',
        'img'   => 'https://images.unsplash.com/photo-1506126613408-eca07ce68773?w=400&q=80',
        'musica'=> ['nome'=>'Sons da Natureza 🌿','url'=>'https://www.youtube.com/watch?v=1ZYbU82GVz4'],
        'passos'=> [
            ['icone'=>'🧘','titulo'=>'Início','texto'=>'Sente-se de forma confortável. Relaxe os ombros e descruze as pernas. Se puder, feche os olhos.'],
            ['icone'=>'🫁','titulo'=>'Condução','texto'=>"Inspire lentamente pelo nariz… (1… 2… 3… 4)\nSegure por um instante…\nAgora expire pela boca… (1… 2… 3… 4… 5… 6)\nRepita esse ciclo.\nEnquanto respira, apenas observe: o ar entrando… e saindo…\nSe sua mente se distrair, tudo bem. Apenas volte para a respiração."],
            ['icone'=>'🌿','titulo'=>'Fechamento','texto'=>"Respire fundo mais uma vez… e solte devagar.\nPerceba como seu corpo está agora.\n👉 Você pode seguir com seu dia mais leve."],
        ],
    ],
    [
        'id'    => 'meditacao',
        'titulo'=> 'Meditação Breve',
        'duracao'=> '7 min • Moderada',
        'badge' => ['cor'=>'bg-purple-700/80','txt'=>'Destaque'],
        'desc'  => 'Foque no agora e liberte-se de preocupações futuras.',
        'img'   => 'https://images.unsplash.com/photo-1508672019048-805c876b67e2?w=400&q=80',
        'musica'=> ['nome'=>'Piano Suave 🎹','url'=>'https://www.youtube.com/watch?v=jfKfPfyJRdk'],
        'passos'=> [
            ['icone'=>'🧘','titulo'=>'Início','texto'=>'Encontre uma posição confortável. Mantenha a coluna levemente ereta.'],
            ['icone'=>'🧠','titulo'=>'Condução','texto'=>"Traga sua atenção para o momento presente.\nObserve:\n• sua respiração\n• seu corpo\n• os sons ao redor\n\nNão tente controlar nada. Apenas observe.\nSe pensamentos surgirem, não lute contra eles. Apenas deixe passar… como nuvens.\nVolte, gentilmente, para o agora."],
            ['icone'=>'🌿','titulo'=>'Fechamento','texto'=>"Leve uma mão ao peito.\nRespire fundo.\nReconheça: 👉 você tirou um tempo pra você."],
        ],
    ],
    [
        'id'    => 'escalda',
        'titulo'=> 'Escalda-pés Relaxante',
        'duracao'=> '10–15 min • Suave',
        'badge' => ['cor'=>'bg-teal-700/80','txt'=>'Ancestral'],
        'desc'  => 'Um ritual ancestral para descarregar tensões e preparar o sono.',
        'img'   => 'https://images.unsplash.com/photo-1544161515-4ab6ce6db874?w=400&q=80',
        'musica'=> ['nome'=>'Ruído Branco 🌊','url'=>'https://www.youtube.com/watch?v=nMfPqeZjc2c'],
        'passos'=> [
            ['icone'=>'🧘','titulo'=>'Início','texto'=>'Prepare um recipiente com água morna. Se quiser, adicione ervas ou óleos. Sente-se confortavelmente.'],
            ['icone'=>'🌊','titulo'=>'Condução','texto'=>"Coloque os pés na água… devagar.\nSinta a temperatura. Sinta o contato.\nRespire fundo.\nA cada respiração, imagine o estresse saindo do corpo… e sendo liberado na água.\nPermaneça assim por alguns minutos.\nSem pressa."],
            ['icone'=>'🌿','titulo'=>'Fechamento','texto'=>"Retire os pés lentamente.\nSeque com calma.\n👉 Perceba o relaxamento no corpo inteiro."],
        ],
    ],
    [
        'id'    => 'massoterapia',
        'titulo'=> 'Preparação para Massoterapia',
        'duracao'=> '5 min • Suave',
        'badge' => ['cor'=>'bg-indigo-700/80','txt'=>'Pré-sessão'],
        'desc'  => 'Prepare corpo e mente para receber o cuidado integrativo.',
        'img'   => 'https://images.unsplash.com/photo-1600334089648-b0d9d3028eb2?w=400&q=80',
        'musica'=> ['nome'=>'Instrumental 🎻','url'=>'https://www.youtube.com/watch?v=7NOSDKb0HlU'],
        'passos'=> [
            ['icone'=>'🧘','titulo'=>'Início','texto'=>'Antes do atendimento, desacelere. Evite celular ou estímulos intensos.'],
            ['icone'=>'🫁','titulo'=>'Condução','texto'=>"Respire fundo 3 vezes.\nRelaxe o corpo.\nSolte os ombros, mandíbula e mãos."],
            ['icone'=>'🌿','titulo'=>'Orientação','texto'=>"Permita-se receber o cuidado.\nNão tente controlar a experiência.\n👉 Apenas esteja presente."],
        ],
    ],
    [
        'id'    => 'estresse',
        'titulo'=> 'Relaxamento Rápido',
        'duracao'=> '3 min • Rápido',
        'badge' => ['cor'=>'bg-orange-700/80','txt'=>'Urgente'],
        'desc'  => 'Para momentos de alta pressão. Pause, respire, recentre.',
        'img'   => 'https://images.unsplash.com/photo-1499209974431-9dddcece7f88?w=400&q=80',
        'musica'=> ['nome'=>'Sons da Natureza 🌿','url'=>'https://www.youtube.com/watch?v=1ZYbU82GVz4'],
        'passos'=> [
            ['icone'=>'🧘','titulo'=>'Início','texto'=>'Pare por um momento. Respire fundo.'],
            ['icone'=>'🧠','titulo'=>'Condução','texto'=>"Pergunte a si mesmo:\n👉 \"O que realmente precisa da minha atenção agora?\"\nDeixe o resto de lado.\nSolte a tensão do corpo."],
            ['icone'=>'🌿','titulo'=>'Fechamento','texto'=>"Você não precisa resolver tudo hoje.\nUm passo de cada vez."],
        ],
    ],
    [
        'id'    => 'aterramento',
        'titulo'=> 'Aterramento (Ansiedade)',
        'duracao'=> '5 min • Suave',
        'badge' => ['cor'=>'bg-emerald-700/80','txt'=>'Ansiedade'],
        'desc'  => 'Técnica 5-4-3-2-1 para reconectar com o momento presente.',
        'img'   => 'https://images.unsplash.com/photo-1476611338391-6f395a0dd82e?w=400&q=80',
        'musica'=> ['nome'=>'MPB Leve 🎵','url'=>'https://www.youtube.com/watch?v=dDo3IHiXMeI'],
        'passos'=> [
            ['icone'=>'🧘','titulo'=>'Início','texto'=>'Olhe ao seu redor. Respire fundo.'],
            ['icone'=>'🧠','titulo'=>'Condução (5-4-3-2-1)','texto'=>"Identifique:\n• 5 coisas que você vê\n• 4 que pode tocar\n• 3 que pode ouvir\n• 2 que pode sentir\n• 1 que pode cheirar"],
            ['icone'=>'🌿','titulo'=>'Fechamento','texto'=>'Você está aqui. Agora. Seguro.'],
        ],
    ],
];

// Playlists ambiente
$playlists_db = $pdo->query('SELECT emoji,nome,url FROM playlists WHERE ativo=1 ORDER BY ordem')->fetchAll();
$playlists = !empty($playlists_db) ? $playlists_db : [['emoji'=>'🌿','nome'=>'Sons da natureza','url'=>'https://www.youtube.com/watch?v=1ZYbU82GVz4']];
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
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { headline: ['Plus Jakarta Sans'], body: ['Manrope'] },
          colors: { primary: '#4e0078', secondary: '#b7004d' }
        }
      }
    }
  </script>
  <style>
    body { font-family:'Manrope',sans-serif; background:radial-gradient(circle at top left,#f7eaf8,#fff7fc); }
    h1,h2,h3,.headline { font-family:'Plus Jakarta Sans',sans-serif; }
    .material-symbols-outlined { font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; }
    .glass { background:rgba(255,255,255,0.65); backdrop-filter:blur(12px); }
    /* Modal prática */
    .pratica-modal { display:none; position:fixed; inset:0; z-index:100; background:rgba(32,25,35,0.6); backdrop-filter:blur(6px); align-items:center; justify-content:center; padding:1rem; }
    .pratica-modal.open { display:flex; }
    /* Scrollbar suave */
    ::-webkit-scrollbar { width:4px; } ::-webkit-scrollbar-thumb { background:#d0c2d3; border-radius:2px; }
    /* Player */
    .player-bar { height:4px; background:#d0c2d3; border-radius:2px; }
    .player-prog { height:4px; border-radius:2px; background:linear-gradient(90deg,#4e0078,#b7004d); width:25%; }
    /* Passo ativo */
    .passo-item { transition: all .3s; }
    .passo-item.ativo { background:linear-gradient(135deg,rgba(78,0,120,.07),rgba(183,0,77,.07)); border-left:3px solid #4e0078; }
  </style>
</head>
<body class="text-gray-900 min-h-screen">

<!-- ── TOPNAV ── -->
<nav class="fixed top-0 w-full z-50 bg-white/70 backdrop-blur-md shadow-sm">
  <div class="flex justify-between items-center px-6 md:px-10 h-16 max-w-7xl mx-auto">
    <div class="text-xl font-extrabold headline"
         style="background:linear-gradient(135deg,#4e0078,#b7004d);-webkit-background-clip:text;-webkit-text-fill-color:transparent">
      NUPICS
    </div>
    <div class="hidden md:flex items-center gap-8 font-semibold text-sm font-headline">
      <a href="dashboard.php" class="text-purple-900 border-b-2 border-purple-700 pb-0.5">Início</a>
      <a href="agendar.php"   class="text-gray-500 hover:text-purple-700 transition-colors">Agendar</a>
      <a href="visita.php"    class="text-gray-500 hover:text-purple-700 transition-colors">Visita</a>
    </div>
    <div class="flex items-center gap-3">
      <button class="p-2 text-purple-700 hover:bg-purple-50 rounded-full transition-colors">
        <span class="material-symbols-outlined">notifications</span>
      </button>
      <div class="w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold"
           style="background:#E1F5EE;color:#085041">
        <?= strtoupper(substr($primeiro,0,1)) ?>
      </div>
      <a href="../api/logout.php"
         class="text-xs text-purple-400 hover:text-purple-700 border border-purple-200 rounded-full px-3 py-1 transition-colors hidden md:block">
        Sair
      </a>
    </div>
  </div>
</nav>

<main class="pt-24 pb-20 px-5 md:px-10 max-w-7xl mx-auto space-y-14">

  <!-- ── HERO ── -->
  <section class="grid grid-cols-1 md:grid-cols-12 gap-6 items-end mt-4">
    <div class="md:col-span-8 space-y-3">
      <h1 class="text-4xl md:text-5xl font-extrabold headline text-purple-900">
        Olá, <?= htmlspecialchars($primeiro) ?>
      </h1>
      <p class="text-lg text-gray-500 max-w-xl">
        Cuidar de você também é prioridade. Respire fundo e aproveite seu momento de paz.
      </p>
    </div>
    <div class="md:col-span-4">
      <?php if ($proximo): ?>
      <div class="glass p-5 rounded-2xl border border-purple-100/40 shadow-sm flex items-center gap-4">
        <div class="w-12 h-12 rounded-full flex items-center justify-center text-white flex-shrink-0"
             style="background:linear-gradient(135deg,#6a1b9a,#4e0078)">
          <span class="material-symbols-outlined" style="font-size:22px">calendar_today</span>
        </div>
        <div>
          <p class="text-[10px] font-bold uppercase tracking-widest text-purple-400">Próximo Atendimento</p>
          <p class="font-bold text-purple-900">
            <?= htmlspecialchars($proximo['especialidade'] ?? 'Sessão') ?>
            <?php if ($proximo['terapeuta_nome']): ?>
              com <?= htmlspecialchars(explode(' ',$proximo['terapeuta_nome'])[0]) ?>
            <?php endif; ?>
          </p>
          <p class="text-sm font-semibold text-pink-600">
            <?php
            $dt = new DateTime($proximo['data']);
            $hoje = new DateTime();
            $diff = $hoje->diff($dt)->days;
            $prefixo = $diff === 0 ? 'Hoje' : ($diff === 1 ? 'Amanhã' : $dt->format('d/m'));
            echo $prefixo . ' às ' . substr($proximo['hora_inicio'],0,5);
            ?>
          </p>
        </div>
      </div>
      <?php else: ?>
      <div class="glass p-5 rounded-2xl border border-dashed border-purple-200 text-center">
        <p class="text-sm text-purple-400">Nenhuma sessão agendada</p>
        <a href="agendar.php" class="text-sm font-bold text-purple-700 mt-1 block hover:underline">Agendar agora →</a>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ── CHECK-IN EMOCIONAL + SOBRE NUPICS ── -->
  <section class="grid grid-cols-1 md:grid-cols-3 gap-6">

    <div class="md:col-span-2 glass p-7 rounded-2xl border border-purple-100/30 space-y-7">
      <div class="flex justify-between items-center">
        <h2 class="text-xl font-bold headline text-gray-800">Como você está se sentindo hoje?</h2>
        <span class="material-symbols-outlined text-purple-300" style="font-size:28px">mood</span>
      </div>
      <div class="flex flex-wrap justify-between gap-3">
        <?php
        $emocoes = [
            ['icon'=>'sentiment_very_satisfied','label'=>'Bem',           'cor'=>'hover:bg-purple-100'],
            ['icon'=>'sentiment_neutral',       'label'=>'Neutro',        'cor'=>'hover:bg-purple-100'],
            ['icon'=>'battery_low',             'label'=>'Cansado',       'cor'=>'hover:bg-purple-100'],
            ['icon'=>'psychology',              'label'=>'Ansioso',       'cor'=>'hover:bg-pink-100',  'destaque'=>true],
            ['icon'=>'layers_clear',            'label'=>'Sobrecarregado','cor'=>'hover:bg-purple-100'],
            ['icon'=>'sentiment_dissatisfied',  'label'=>'Triste',        'cor'=>'hover:bg-purple-100'],
        ];
        foreach ($emocoes as $e):
          $base = $e['destaque'] ?? false ? 'bg-pink-100' : 'bg-gray-100';
        ?>
        <button class="flex flex-col items-center gap-2 group" onclick="selecionarEmocao(this, '<?= $e['label'] ?>')">
          <div class="w-14 h-14 rounded-full <?= $base ?> <?= $e['cor'] ?> flex items-center justify-center transition-all group-hover:scale-110">
            <span class="material-symbols-outlined text-purple-700" style="font-size:28px"><?= $e['icon'] ?></span>
          </div>
          <span class="text-xs font-medium text-gray-600"><?= $e['label'] ?></span>
        </button>
        <?php endforeach; ?>
      </div>
      <div class="rounded-2xl flex items-center gap-5 p-5" style="background:#ffd9de">
        <div class="p-2.5 bg-white/60 rounded-full">
          <span class="material-symbols-outlined text-pink-700" style="font-size:22px">wind_power</span>
        </div>
        <div class="flex-1">
          <p class="font-bold text-gray-800" id="sugestao-titulo">Sugerimos: Respiração Guiada</p>
          <p class="text-xs text-gray-600" id="sugestao-desc">Para acalmar a mente ansiosa em apenas 5 minutos.</p>
        </div>
        <button onclick="abrirPratica('respiracao')"
                class="px-5 py-2 text-white text-sm font-bold rounded-full transition-opacity hover:opacity-90 whitespace-nowrap"
                style="background:#b7004d">
          Começar
        </button>
      </div>
    </div>

    <div class="rounded-2xl text-white relative overflow-hidden p-7 flex flex-col justify-between min-h-[280px]"
         style="background:linear-gradient(145deg,#4e0078,#b7004d)">
      <div class="relative z-10 space-y-4">
        <h3 class="text-xl font-extrabold headline">NUPICS Caicó</h3>
        <p class="text-purple-200 text-sm leading-relaxed">
          Um projeto de extensão da UERN coordenado pela Professora Rosangela Cavalcante,
          oferecendo atendimentos integrativos gratuitos para toda a comunidade.
        </p>
        <button class="w-full py-3 bg-white text-purple-900 font-bold text-sm rounded-full hover:bg-purple-50 transition-colors">
          Conhecer o projeto
        </button>
      </div>
      <div class="absolute -bottom-8 -right-8 opacity-20">
        <span class="material-symbols-outlined" style="font-size:140px">eco</span>
      </div>
    </div>
  </section>

  <!-- ── AÇÕES RÁPIDAS ── -->
  <section class="space-y-5">
    <h2 class="text-2xl font-extrabold headline text-gray-800 ml-1">Ações Rápidas</h2>
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
      <?php
      $acoes = [
          ['icon'=>'add_circle',  'label'=>'Agendar',      'href'=>'agendar.php',  'cor'=>'text-purple-700'],
          ['icon'=>'event_repeat','label'=>'Reagendar',    'href'=>'agendar.php',  'cor'=>'text-purple-700'],
          ['icon'=>'cancel',      'label'=>'Cancelar',     'href'=>'dashboard.php','cor'=>'text-red-500'],
          ['icon'=>'group',       'label'=>'Ver Terapeuta','href'=>'dashboard.php','cor'=>'text-purple-700'],
          ['icon'=>'map',         'label'=>'Solicitar visita','href'=>'visita.php','cor'=>'text-purple-700'],
      ];
      foreach ($acoes as $a):
      ?>
      <a href="<?= $a['href'] ?>"
         class="glass border border-white/60 rounded-2xl p-5 flex flex-col items-center gap-3 hover:shadow-lg transition-all group">
        <span class="material-symbols-outlined text-3xl <?= $a['cor'] ?> group-hover:scale-110 transition-transform">
          <?= $a['icon'] ?>
        </span>
        <span class="text-sm font-semibold text-gray-700"><?= $a['label'] ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ── GRADE PRINCIPAL ── -->
  <div class="grid grid-cols-1 lg:grid-cols-12 gap-10">

    <!-- Coluna esquerda -->
    <div class="lg:col-span-8 space-y-12">

      <!-- Seu Momento de Cuidado -->
      <section class="space-y-5">
        <div class="flex justify-between items-center">
          <h2 class="text-2xl font-extrabold headline text-gray-800">Seu Momento de Cuidado</h2>
          <button class="text-sm font-bold text-purple-700 hover:underline">Ver tudo</button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
          <?php foreach (array_slice($praticas, 0, 2) as $pr): ?>
          <div class="glass rounded-2xl overflow-hidden group hover:shadow-xl transition-all cursor-pointer"
               onclick="abrirPratica('<?= $pr['id'] ?>')">
            <div class="h-40 relative overflow-hidden">
              <img src="<?= $pr['img'] ?>" alt="<?= $pr['titulo'] ?>"
                   class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
              <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(0,0,0,.6),transparent)"></div>
              <div class="absolute bottom-3 left-4">
                <span class="text-[10px] uppercase font-bold tracking-widest text-white px-2 py-1 rounded <?= $pr['badge']['cor'] ?>">
                  <?= $pr['badge']['txt'] ?>
                </span>
              </div>
            </div>
            <div class="p-5 space-y-2">
              <div class="flex justify-between items-start">
                <h4 class="font-bold text-base text-gray-800"><?= $pr['titulo'] ?></h4>
                <span class="text-xs text-gray-400 whitespace-nowrap ml-2"><?= $pr['duracao'] ?></span>
              </div>
              <p class="text-sm text-gray-500"><?= $pr['desc'] ?></p>
              <button class="flex items-center gap-1.5 text-purple-700 font-bold text-sm">
                <span class="material-symbols-outlined" style="font-size:18px;font-variation-settings:'FILL' 1">play_circle</span>
                Começar prática
              </button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Grid secundário (4 práticas) -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <?php foreach (array_slice($praticas, 2) as $pr): ?>
          <div class="glass rounded-2xl overflow-hidden cursor-pointer hover:shadow-lg transition-all group"
               onclick="abrirPratica('<?= $pr['id'] ?>')">
            <div class="h-28 relative overflow-hidden">
              <img src="<?= $pr['img'] ?>" alt="<?= $pr['titulo'] ?>"
                   class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700">
              <div class="absolute inset-0" style="background:linear-gradient(to top,rgba(0,0,0,.65),transparent)"></div>
              <div class="absolute bottom-2 left-3">
                <span class="text-[9px] uppercase font-bold text-white px-1.5 py-0.5 rounded <?= $pr['badge']['cor'] ?>">
                  <?= $pr['badge']['txt'] ?>
                </span>
              </div>
            </div>
            <div class="p-3">
              <h4 class="font-bold text-xs text-gray-800 leading-tight"><?= $pr['titulo'] ?></h4>
              <p class="text-[10px] text-gray-400 mt-0.5"><?= $pr['duracao'] ?></p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- Ambiente Terapêutico -->
      <section class="glass border border-purple-100/30 rounded-2xl p-7 space-y-5">
        <div class="flex items-center gap-4">
          <div class="p-3 rounded-xl text-white" style="background:#4e0078">
            <span class="material-symbols-outlined" style="font-size:22px">graphic_eq</span>
          </div>
          <div>
            <h3 class="text-xl font-bold headline text-gray-800">Ambiente Terapêutico</h3>
            <p class="text-sm text-gray-400">Músicas para harmonizar o seu espaço.</p>
          </div>
        </div>

        <div class="flex flex-col md:flex-row items-center gap-6 rounded-2xl p-5" style="background:#fdeffe">
          <div class="w-24 h-24 rounded-2xl overflow-hidden flex-shrink-0">
            <img src="https://images.unsplash.com/photo-1441974231531-c6227db76b6e?w=200&q=80"
                 alt="Natureza" class="w-full h-full object-cover">
          </div>
          <div class="flex-1 space-y-3 w-full">
            <div class="flex justify-between items-end">
              <div>
                <p class="font-bold text-purple-900" id="ambiente-nome">Sons da Natureza 🌿</p>
                <p class="text-xs text-gray-400">Caicó/RN · Imersivo</p>
              </div>
              <span class="text-xs font-mono text-gray-400">ao vivo</span>
            </div>
            <div class="player-bar"><div class="player-prog" id="player-prog"></div></div>
            <div class="flex justify-center gap-8 items-center">
              <button onclick="mudarPlaylist(-1)" class="text-gray-400 hover:text-purple-700 transition-colors">
                <span class="material-symbols-outlined">skip_previous</span>
              </button>
              <button onclick="togglePlayer()"
                      class="w-11 h-11 rounded-full text-white flex items-center justify-center hover:scale-105 transition-all"
                      style="background:linear-gradient(135deg,#4e0078,#b7004d)" id="btn-play">
                <span class="material-symbols-outlined" style="font-variation-settings:'FILL' 1">play_arrow</span>
              </button>
              <button onclick="mudarPlaylist(1)" class="text-gray-400 hover:text-purple-700 transition-colors">
                <span class="material-symbols-outlined">skip_next</span>
              </button>
            </div>
          </div>
          <button onclick="togglePlayer()"
                  class="px-6 py-3 border-2 border-purple-700 text-purple-700 font-bold text-sm rounded-full hover:bg-purple-700 hover:text-white transition-all whitespace-nowrap">
            Preparar ambiente
          </button>
        </div>

        <div id="yt-container" class="hidden rounded-2xl overflow-hidden">
          <iframe id="yt-frame" width="100%" height="100" frameborder="0"
                  allow="autoplay;encrypted-media" style="border-radius:12px"></iframe>
        </div>

        <div class="flex flex-wrap gap-2">
          <?php foreach ($playlists as $i => $pl): ?>
          <button onclick="selecionarPlaylist(<?= $i ?>)"
                  class="playlist-btn flex items-center gap-1.5 px-4 py-2 rounded-full border text-sm font-semibold transition-all"
                  style="border-color:#d0c2d3;color:#4d4351"
                  data-url="<?= $pl['url'] ?>" data-nome="<?= $pl['nome'] ?>">
            <?= $pl['emoji'] ?> <?= $pl['nome'] ?>
          </button>
          <?php endforeach; ?>
        </div>
      </section>

    </div>

    <!-- Coluna direita -->
    <div class="lg:col-span-4 space-y-8">

      <!-- Frase -->
      <div class="rounded-2xl p-7 relative overflow-hidden" style="background:#f4dce4">
        <span class="material-symbols-outlined absolute top-3 right-3 opacity-20 rotate-12"
              style="font-size:64px;color:#3a2c32">format_quote</span>
        <p class="text-lg italic font-serif leading-relaxed relative z-10" style="color:#25181e">
          <?= htmlspecialchars($frase) ?>
        </p>
      </div>

      <!-- Ciclo ativo -->
      <?php if ($ciclo): ?>
      <div class="glass border border-purple-100/30 rounded-2xl p-5">
        <h3 class="text-xs font-extrabold uppercase tracking-widest text-purple-400 mb-3">Meu ciclo atual</h3>
        <div class="flex gap-2 mb-2">
          <?php for ($i = 1; $i <= $ciclo['total_sessoes']; $i++):
            if ($i <= $ciclo['feitas']) $cls = 'bg-purple-700 text-white';
            elseif ($i === $ciclo['feitas']+1) $cls = 'border-2 border-purple-700 text-purple-700 bg-purple-50';
            else $cls = 'bg-gray-100 text-gray-400';
          ?>
          <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold <?= $cls ?>">
            <?= $i ?>
          </div>
          <?php endfor; ?>
        </div>
        <p class="text-xs text-gray-400"><?= $ciclo['feitas'] ?> de <?= $ciclo['total_sessoes'] ?> sessões realizadas</p>
      </div>
      <?php endif; ?>

      <!-- Histórico -->
      <section class="space-y-4">
        <h3 class="text-lg font-extrabold headline text-gray-800">Histórico & Acompanhamento</h3>
        <div class="space-y-3">
          <?php if (empty($historico)): ?>
          <p class="text-sm text-gray-400">Nenhuma sessão realizada ainda.</p>
          <?php else: ?>
          <?php
          $cores_linha = ['#4e0078','#b7004d','#d0c2d3'];
          foreach ($historico as $idx => $h):
            $dt = new DateTime($h['data']);
            $mes = $meses_pt[(int)$dt->format('n')];
            $data_fmt = $dt->format('d') . ' de ' . $mes;
          ?>
          <div class="p-4 bg-white rounded-2xl border border-gray-100 flex gap-4 shadow-sm">
            <div class="w-1.5 rounded-full flex-shrink-0" style="background:<?= $cores_linha[$idx] ?>; min-height:40px"></div>
            <div>
              <p class="text-sm font-bold text-gray-800"><?= htmlspecialchars($h['especialidade'] ?? 'Sessão') ?></p>
              <p class="text-xs text-gray-400">Realizada em <?= $data_fmt ?></p>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </section>

      <!-- Como funciona -->
      <div class="glass border border-purple-100/30 rounded-2xl p-5 space-y-3">
        <h3 class="font-bold text-gray-800 flex items-center gap-2">
          <span class="material-symbols-outlined text-purple-600" style="font-size:20px">help_center</span>
          Como Funciona?
        </h3>
        <p class="text-sm text-gray-500">Todos os nossos atendimentos são 100% gratuitos para a comunidade.</p>
        <div class="flex items-center gap-2 text-sm text-gray-600">
          <span class="material-symbols-outlined text-purple-600" style="font-size:16px">location_on</span>
          Campus UERN, Caicó/RN
        </div>
        <div class="flex items-center gap-2 text-sm text-gray-600">
          <span class="material-symbols-outlined text-purple-600" style="font-size:16px">schedule</span>
          Ciclos de 4 sessões semanais
        </div>
        <a href="visita.php"
           class="block w-full py-2 text-center text-purple-700 font-bold text-sm border-t border-gray-100 mt-2 hover:bg-purple-50 rounded-b-xl transition-colors">
          Solicitar visita externa →
        </a>
      </div>

    </div>
  </div>

</main>

<!-- ── FOOTER ── -->
<footer class="mt-16 py-10 px-8" style="background:#f7eaf8">
  <div class="max-w-7xl mx-auto flex flex-col md:flex-row justify-between items-center gap-4 text-sm">
    <div class="font-extrabold text-purple-900 headline">NUPICS — UERN</div>
    <div class="text-gray-500">© <?= date('Y') ?> NUPICS — Saúde Integrativa e Bem-estar.</div>
    <div class="flex gap-6">
      <a href="#" class="text-gray-500 hover:text-purple-700 transition-colors">Privacidade</a>
      <a href="#" class="text-gray-500 hover:text-purple-700 transition-colors">Termos de Uso</a>
      <a href="#" class="text-gray-500 hover:text-purple-700 transition-colors">Contato</a>
    </div>
  </div>
</footer>

<!-- ══ MODAIS DAS PRÁTICAS ══ -->
<?php foreach ($praticas as $pr): ?>
<div class="pratica-modal" id="modal-<?= $pr['id'] ?>">
  <div class="bg-white rounded-3xl w-full max-w-lg max-h-[92vh] flex flex-col overflow-hidden shadow-2xl">

    <!-- Header -->
    <div class="flex items-center justify-between px-6 pt-5 pb-4 border-b border-gray-100">
      <div>
        <h3 class="text-lg font-extrabold headline text-purple-900"><?= $pr['titulo'] ?></h3>
        <p class="text-xs text-gray-400 mt-0.5"><?= $pr['duracao'] ?> · <?= $pr['musica']['nome'] ?></p>
      </div>
      <button onclick="fecharPratica('<?= $pr['id'] ?>')"
              class="w-8 h-8 rounded-full bg-gray-100 hover:bg-gray-200 flex items-center justify-center transition-colors text-gray-500">
        ✕
      </button>
    </div>

    <!-- Player de música -->
    <div class="px-6 py-4 border-b border-gray-50" style="background:#fdeffe">
      <div class="flex items-center gap-4">
        <button onclick="togglePraticaPlayer('<?= $pr['id'] ?>', '<?= $pr['musica']['url'] ?>')"
                id="btn-pratica-<?= $pr['id'] ?>"
                class="w-10 h-10 rounded-full text-white flex items-center justify-center flex-shrink-0 transition-opacity hover:opacity-90"
                style="background:linear-gradient(135deg,#4e0078,#b7004d)">
          <span class="material-symbols-outlined" style="font-size:20px;font-variation-settings:'FILL' 1">play_arrow</span>
        </button>
        <div class="flex-1">
          <p class="text-xs font-bold text-purple-900"><?= $pr['musica']['nome'] ?></p>
          <div class="player-bar mt-1.5"><div class="player-prog" style="width:0%"></div></div>
        </div>
        <span class="material-symbols-outlined text-purple-300" style="font-size:20px">headphones</span>
      </div>
      <div class="hidden mt-3 rounded-xl overflow-hidden" id="yt-pratica-<?= $pr['id'] ?>">
        <iframe width="100%" height="80" frameborder="0" allow="autoplay;encrypted-media"
                id="frame-pratica-<?= $pr['id'] ?>" style="border-radius:8px"></iframe>
      </div>
    </div>

    <!-- Passos -->
    <div class="flex-1 overflow-y-auto px-6 py-5 space-y-3">
      <?php foreach ($pr['passos'] as $idx => $passo): ?>
      <div class="passo-item p-4 rounded-2xl cursor-pointer border border-transparent hover:border-purple-100 transition-all <?= $idx===0?'ativo':'' ?>"
           onclick="ativarPasso(this)">
        <div class="flex items-center gap-2 mb-2">
          <span class="text-xl"><?= $passo['icone'] ?></span>
          <span class="text-sm font-extrabold text-purple-900 headline"><?= $passo['titulo'] ?></span>
        </div>
        <p class="text-sm text-gray-600 leading-relaxed whitespace-pre-line"><?= htmlspecialchars($passo['texto']) ?></p>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Footer do modal -->
    <div class="px-6 pb-5 pt-3 border-t border-gray-100">
      <button onclick="fecharPratica('<?= $pr['id'] ?>')"
              class="w-full py-3 rounded-xl text-white font-bold text-sm transition-opacity hover:opacity-90"
              style="background:linear-gradient(135deg,#4e0078,#b7004d)">
        Concluir prática
      </button>
    </div>
  </div>
</div>
<?php endforeach; ?>

<script>
// Playlists do ambiente
var playlists = <?= json_encode($playlists) ?>;
var playlistIdx = 0;
var playerAberto = false;

function togglePlayer() {
  var container = document.getElementById('yt-container');
  var frame     = document.getElementById('yt-frame');
  var btn       = document.getElementById('btn-play');
  if (!playerAberto) {
    var url = playlists[playlistIdx].url;
    var vid = url.match(/(?:v=|youtu\.be\/)([^&\s]+)/);
    if (vid) {
      frame.src = 'https://www.youtube.com/embed/' + vid[1] + '?autoplay=1';
      container.classList.remove('hidden');
      btn.innerHTML = '<span class="material-symbols-outlined" style="font-variation-settings:\'FILL\' 1">pause</span>';
      playerAberto = true;
    }
  } else {
    frame.src = '';
    container.classList.add('hidden');
    btn.innerHTML = '<span class="material-symbols-outlined" style="font-variation-settings:\'FILL\' 1">play_arrow</span>';
    playerAberto = false;
  }
}

function mudarPlaylist(dir) {
  playlistIdx = (playlistIdx + dir + playlists.length) % playlists.length;
  document.getElementById('ambiente-nome').textContent = playlists[playlistIdx].nome;
  if (playerAberto) {
    playerAberto = false;
    togglePlayer();
  }
}

function selecionarPlaylist(idx) {
  playlistIdx = idx;
  document.getElementById('ambiente-nome').textContent = playlists[idx].nome;
  document.querySelectorAll('.playlist-btn').forEach(function(b, i) {
    b.style.background     = i === idx ? '#4e0078' : '';
    b.style.color          = i === idx ? 'white' : '#4d4351';
    b.style.borderColor    = i === idx ? '#4e0078' : '#d0c2d3';
  });
  if (playerAberto) { playerAberto = false; togglePlayer(); }
}

// Check-in emocional
var sugestoes = {
  'Bem':           ['Meditação Breve',       'Para aprofundar sua paz interior.'],
  'Neutro':        ['Respiração Guiada',     'Para trazer mais presença ao momento.'],
  'Cansado':       ['Escalda-pés Relaxante', 'Para aliviar o peso do corpo e da mente.'],
  'Ansioso':       ['Aterramento (Ansiedade)','Técnica 5-4-3-2-1 para o momento presente.'],
  'Sobrecarregado':['Relaxamento Rápido',    'Pause por 3 minutos e recentre-se.'],
  'Triste':        ['Meditação Breve',       'Um espaço seguro para observar seus sentimentos.'],
};

function selecionarEmocao(btn, emocao) {
  document.querySelectorAll('.group button, button.group').forEach(function(b) {
    b.querySelector('div') && (b.querySelector('div').style.background = '');
  });
  var sug = sugestoes[emocao] || ['Respiração Guiada','Para cuidar de você agora.'];
  document.getElementById('sugestao-titulo').textContent = 'Sugerimos: ' + sug[0];
  document.getElementById('sugestao-desc').textContent   = sug[1];
}

// Modais de prática
var praticaFrames = {};

function abrirPratica(id) {
  document.getElementById('modal-' + id).classList.add('open');
  document.body.style.overflow = 'hidden';
}

function fecharPratica(id) {
  document.getElementById('modal-' + id).classList.remove('open');
  document.body.style.overflow = '';
  // Para o player da prática
  var frame = document.getElementById('frame-pratica-' + id);
  if (frame) frame.src = '';
  var yt = document.getElementById('yt-pratica-' + id);
  if (yt) yt.classList.add('hidden');
  praticaFrames[id] = false;
}

function togglePraticaPlayer(id, url) {
  var frame = document.getElementById('frame-pratica-' + id);
  var yt    = document.getElementById('yt-pratica-' + id);
  var btn   = document.getElementById('btn-pratica-' + id);
  if (!praticaFrames[id]) {
    var vid = url.match(/(?:v=|youtu\.be\/)([^&\s]+)/);
    if (vid) {
      frame.src = 'https://www.youtube.com/embed/' + vid[1] + '?autoplay=1';
      yt.classList.remove('hidden');
      btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:20px;font-variation-settings:\'FILL\' 1">pause</span>';
      praticaFrames[id] = true;
    }
  } else {
    frame.src = '';
    yt.classList.add('hidden');
    btn.innerHTML = '<span class="material-symbols-outlined" style="font-size:20px;font-variation-settings:\'FILL\' 1">play_arrow</span>';
    praticaFrames[id] = false;
  }
}

function ativarPasso(el) {
  el.closest('.flex-1').querySelectorAll('.passo-item').forEach(function(p) {
    p.classList.remove('ativo');
  });
  el.classList.add('ativo');
}

// Fechar modal ao clicar fora
document.querySelectorAll('.pratica-modal').forEach(function(m) {
  m.addEventListener('click', function(e) {
    if (e.target === m) fecharPratica(m.id.replace('modal-',''));
  });
});
</script>

</body>
</html>