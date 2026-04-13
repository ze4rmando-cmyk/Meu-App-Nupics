<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['tipo'], ['terapeuta','coordenador'])) {
    header('Location: ../index.php');
    exit;
}

$stmt = $pdo->prepare('
    SELECT t.id AS terapeuta_id, u.nome, u.email, u.telefone,
           t.especialidade, t.periodo
    FROM terapeutas t
    JOIN usuarios u ON u.id = t.usuario_id
    WHERE u.id = ?
');
$stmt->execute([$_SESSION['usuario_id']]);
$terapeuta    = $stmt->fetch();
$terapeuta_id = $terapeuta['terapeuta_id'] ?? null;

$dias_pt = ['','Segunda','Terça','Quarta','Quinta','Sexta','Sábado','Domingo'];

// ── Métricas ──
$hoje = $pdo->query('
    SELECT COUNT(*) AS total FROM agendamentos
    WHERE data = CURDATE() AND status = "agendado"
')->fetch()['total'];

$proximo = $pdo->prepare('
    SELECT up.nome AS paciente_nome, h.hora_inicio
    FROM agendamentos a
    JOIN horarios h ON h.id = a.horario_id
    JOIN ciclos c ON c.id = a.ciclo_id
    JOIN pacientes p ON p.id = c.paciente_id
    JOIN usuarios up ON up.id = p.usuario_id
    WHERE a.data = CURDATE() AND a.status = "agendado"
      AND h.hora_inicio >= TIME(NOW())
    ORDER BY h.hora_inicio ASC LIMIT 1
');
$proximo->execute();
$proximo = $proximo->fetch();

// Sessões do terapeuta logado este mês
$sessoes_mes = 0;
$sessoes_semana = 0;
$total_pacientes = 0;
if ($terapeuta_id) {
    $stmt = $pdo->prepare('
        SELECT COUNT(*) AS total FROM agendamentos a
        JOIN horarios h ON h.id = a.horario_id
        JOIN horario_terapeutas ht ON ht.horario_id = h.id
        WHERE ht.terapeuta_id = ? AND MONTH(a.data) = MONTH(CURDATE())
        AND a.status = "realizado"
    ');
    $stmt->execute([$terapeuta_id]);
    $sessoes_mes = $stmt->fetch()['total'];

    $stmt = $pdo->prepare('
        SELECT COUNT(*) AS total FROM agendamentos a
        JOIN horarios h ON h.id = a.horario_id
        JOIN horario_terapeutas ht ON ht.horario_id = h.id
        WHERE ht.terapeuta_id = ? AND YEARWEEK(a.data) = YEARWEEK(CURDATE())
    ');
    $stmt->execute([$terapeuta_id]);
    $sessoes_semana = $stmt->fetch()['total'];

    $stmt = $pdo->prepare('
        SELECT COUNT(DISTINCT c.paciente_id) AS total
        FROM ciclos c WHERE c.terapeuta_id = ? AND c.status = "ativo"
    ');
    $stmt->execute([$terapeuta_id]);
    $total_pacientes = $stmt->fetch()['total'];
}

// Sessões totais da equipe no semestre
$sessoes_semestre = $pdo->query('
    SELECT COUNT(*) AS total FROM agendamentos
    WHERE YEAR(data) = YEAR(CURDATE())
    AND MONTH(data) >= IF(MONTH(CURDATE()) <= 6, 1, 7)
    AND status = "realizado"
')->fetch()['total'];

// Ciclos ativos do terapeuta
$ciclos = [];
if ($terapeuta_id) {
    $stmt = $pdo->prepare('
        SELECT c.id, c.total_sessoes,
               up.nome AS paciente_nome,
               COUNT(CASE WHEN a.status = "realizado" THEN 1 END) AS feitas
        FROM ciclos c
        JOIN pacientes p ON p.id = c.paciente_id
        JOIN usuarios up ON up.id = p.usuario_id
        LEFT JOIN agendamentos a ON a.ciclo_id = c.id
        WHERE c.terapeuta_id = ? AND c.status = "ativo"
        GROUP BY c.id, c.total_sessoes, up.nome
        ORDER BY up.nome LIMIT 5
    ');
    $stmt->execute([$terapeuta_id]);
    $ciclos = $stmt->fetchAll();
}

// Notificações recentes (agendamentos criados hoje)
$notifs = $pdo->query('
    SELECT a.criado_em, up.nome AS paciente_nome, h.hora_inicio, a.data
    FROM agendamentos a
    JOIN ciclos c ON c.id = a.ciclo_id
    JOIN pacientes p ON p.id = c.paciente_id
    JOIN usuarios up ON up.id = p.usuario_id
    JOIN horarios h ON h.id = a.horario_id
    WHERE DATE(a.criado_em) = CURDATE()
    ORDER BY a.criado_em DESC LIMIT 5
')->fetchAll();

// Frases inspiradoras (rotativa pelo dia do ano)
$frases_db = $pdo->query('SELECT texto FROM frases WHERE tipo="terapeuta" AND ativo=1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$frases_ter = !empty($frases_db) ? $frases_db : ['"Presença plena é o maior presente que um terapeuta pode oferecer."'];
$frase_hoje = $frases_ter[date('z') % count($frases_ter)];

// Playlists terapêuticas (fixas — admin pode editar aqui)
$playlists = $playlists_db = $pdo->query('SELECT emoji,nome,url FROM playlists WHERE ativo=1 ORDER BY ordem')->fetchAll();
$playlists = !empty($playlists_db) ? $playlists_db : [['emoji'=>'🌿','nome'=>'Sons da natureza','url'=>'https://www.youtube.com/watch?v=1ZYbU82GVz4']];

// Equipe — todos os terapeutas
$equipe = $pdo->query('
    SELECT t.id, u.nome, t.especialidade,
           COUNT(DISTINCT c.paciente_id) AS total_pacientes,
           COUNT(CASE WHEN a.status="realizado" AND MONTH(a.data)=MONTH(CURDATE()) THEN 1 END) AS sessoes_mes
    FROM terapeutas t
    JOIN usuarios u ON u.id = t.usuario_id
    LEFT JOIN ciclos c ON c.terapeuta_id = t.id
    LEFT JOIN agendamentos a ON a.ciclo_id = c.id
    WHERE t.ativo = 1
    GROUP BY t.id, u.nome, t.especialidade
    ORDER BY sessoes_mes DESC
')->fetchAll();

$cores_av = ['#E1F5EE:#085041','#E6F1FB:#0C447C','#FAEEDA:#633806','#FBEAF0:#72243E','#EAF3DE:#27500A'];

function iniciais($nome) {
    $p = explode(' ', $nome); $i = '';
    foreach ($p as $x) { $i .= strtoupper(mb_substr($x,0,1)); if (strlen($i)>=2) break; }
    return $i;
}

$nome_curto = explode(' ', $terapeuta['nome'] ?? $_SESSION['nome']);
$primeiro_nome = $nome_curto[0];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NUPICS Caicó — Painel</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { headline: ['Plus Jakarta Sans'], body: ['Manrope'] },
          colors: {
            primary: '#4e0078', secondary: '#b7004d',
            'primary-light': '#6a1b9a', surface: '#fff7fc',
          }
        }
      }
    }
  </script>
  <style>
    .scrollbar-hide::-webkit-scrollbar { display: none; }
.scrollbar-hide { scrollbar-width: none; }
    body { font-family: 'Manrope', sans-serif; background: #fff7fc; }
    h1,h2,h3,.headline { font-family: 'Plus Jakarta Sans', sans-serif; }
    .material-symbols-outlined { font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; font-size:20px; }
    .glass { background: rgba(255,255,255,0.65); backdrop-filter: blur(12px); }
    .abas-h { display:flex; border-bottom: 1px solid #ecdeed; background: rgba(255,247,252,0.8); backdrop-filter: blur(12px); overflow-x:auto; gap:0; }
    .abas-h::-webkit-scrollbar { display:none; }
    .aba-h { padding:11px 18px; font-size:13px; font-weight:600; color:#7f7383; cursor:pointer; background:transparent; border:none; border-bottom:2px solid transparent; white-space:nowrap; font-family:'Manrope',sans-serif; transition:color .15s; }
    .aba-h.on { color:#4e0078; border-bottom-color:#4e0078; }
    .aba-h:hover { color:#4e0078; }
    .progress-bar { height:6px; border-radius:3px; background:#ecdeed; overflow:hidden; }
    .progress-fill { height:100%; border-radius:3px; background:linear-gradient(90deg,#4e0078,#b7004d); transition:width .6s; }
    .pip-livre { background:#22c55e; }
    .pip-ocup  { background:#ecdeed; }
  </style>
</head>
<body class="min-h-screen relative overflow-x-hidden">

  <!-- Fundo decorativo -->
  <div class="fixed inset-0 z-0 pointer-events-none"
       style="background:radial-gradient(ellipse at 10% 30%,rgba(233,213,255,.35) 0%,transparent 55%),
                          radial-gradient(ellipse at 90% 10%,rgba(252,231,243,.4) 0%,transparent 50%),
                          radial-gradient(ellipse at 70% 90%,rgba(237,233,254,.3) 0%,transparent 50%)">
  </div>

  <!-- TOPNAV -->
  <header class="sticky top-0 z-50 glass border-b border-purple-100/40 shadow-sm">
    <div class="max-w-7xl mx-auto px-5 py-3 flex justify-between items-center">
      <div class="text-lg font-extrabold font-headline"
           style="background:linear-gradient(135deg,#4e0078,#b7004d);-webkit-background-clip:text;-webkit-text-fill-color:transparent">
        NUPICS Caicó
      </div>
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold"
             style="background:#E1F5EE;color:#085041">
          <?= iniciais($terapeuta['nome'] ?? $_SESSION['nome']) ?>
        </div>
        <span class="text-sm font-semibold text-purple-900 hidden md:block">
          <?= htmlspecialchars($primeiro_nome) ?>
        </span>
        <a href="../api/trocar_senha.php"
           class="text-xs text-purple-400 hover:text-purple-700 border border-purple-200 rounded-full px-3 py-1 transition-colors hidden md:block">
          Senha
        </a>
        <a href="../api/logout.php"
           class="text-xs text-purple-400 hover:text-purple-700 border border-purple-200 rounded-full px-3 py-1 transition-colors">
          Sair
        </a>
      </div>
    </div>

    <!-- Abas horizontais -->
    <div class="abas-h max-w-7xl mx-auto px-2">
      <button class="aba-h on" onclick="setAba('dashboard')">Dashboard</button>
      <button class="aba-h"    onclick="setAba('agenda')">Agendamentos</button>
      <button class="aba-h"    onclick="setAba('ciclos')">Ciclos</button>
      <button class="aba-h"    onclick="setAba('equipe')">Equipe</button>
      <button class="aba-h"    onclick="setAba('pacientes')">Pacientes</button>
      <button class="aba-h"    onclick="window.location='plantoes.php'">Cronograma</button>
      <button class="aba-h"    onclick="setAba('perfil')">Perfil</button>
    </div>
  </header>

  <main class="relative z-10 max-w-7xl mx-auto px-4 py-6 space-y-6">

    <!-- ══ ABA: DASHBOARD ══ -->
    <div id="aba-dashboard">

      <!-- Saudação -->
      <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-2">
        <div>
          <h1 class="text-3xl font-extrabold font-headline text-purple-900">
            Olá, <?= htmlspecialchars($primeiro_nome) ?> 👋
          </h1>
          <p class="text-purple-400 font-medium mt-1">Seu espaço de cuidado começa aqui.</p>
        </div>
        <!-- Mini stats no topo -->
        <div class="flex flex-wrap gap-3">
          <div class="glass border border-purple-100/40 rounded-xl px-5 py-3 text-center shadow-sm">
            <div class="text-2xl font-extrabold font-headline text-purple-900"><?= $hoje ?></div>
            <div class="text-[10px] font-bold text-purple-400 uppercase tracking-wider">Atendimentos hoje</div>
          </div>
          <?php if ($proximo): ?>
          <div class="glass border border-purple-100/40 rounded-xl px-5 py-3 shadow-sm min-w-[150px]">
            <div class="flex items-center gap-1 mb-1">
              <div class="w-2 h-2 rounded-full bg-pink-500"></div>
              <span class="text-[10px] font-bold text-pink-500 uppercase tracking-wider">
                Próximo: <?= htmlspecialchars(explode(' ',$proximo['paciente_nome'])[0]) ?>
              </span>
            </div>
            <div class="text-xl font-extrabold font-headline text-purple-900">
              <?= substr($proximo['hora_inicio'],0,5) ?>
            </div>
          </div>
          <?php endif; ?>
          <div class="glass border border-purple-100/40 rounded-xl px-5 py-3 text-center shadow-sm">
            <?php
            $carga = $sessoes_semana >= 8 ? 'Alta' : ($sessoes_semana >= 4 ? 'Moderada' : 'Leve');
            $carga_cor = $sessoes_semana >= 8 ? 'text-pink-700 bg-pink-50' : ($sessoes_semana >= 4 ? 'text-purple-700 bg-purple-50' : 'text-green-700 bg-green-50');
            ?>
            <div class="text-base font-bold <?= $carga_cor ?> px-3 py-1 rounded-full"><?= $carga ?></div>
            <div class="text-[10px] font-bold text-purple-400 uppercase tracking-wider mt-1">Carga de Trabalho</div>
          </div>
        </div>
      </div>

      <!-- Grade principal -->
      <div class="grid lg:grid-cols-12 gap-5">

        <!-- Coluna esquerda (8/12) -->
        <div class="lg:col-span-8 space-y-5">

          <!-- Ações rápidas -->
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <?php
            $acoes = [
                ['icon'=>'calendar_month',  'label'=>'Ver agenda',      'cor'=>'text-purple-600', 'bg'=>'bg-purple-50', 'fn'=>"setAba('agenda')"],
                ['icon'=>'schedule',        'label'=>'Abrir horários',  'cor'=>'text-pink-600',   'bg'=>'bg-pink-50',   'fn'=>"window.location='plantoes.php'"],
                ['icon'=>'person_search',   'label'=>'Ver pacientes',   'cor'=>'text-purple-600', 'bg'=>'bg-purple-50', 'fn'=>"setAba('pacientes')"],
                ['icon'=>'history_edu',     'label'=>'Ciclos',          'cor'=>'text-pink-600',   'bg'=>'bg-pink-50',   'fn'=>"setAba('ciclos')"],
            ];
            foreach ($acoes as $a):
            ?>
            <button onclick="<?= $a['fn'] ?>"
                    class="glass border border-white/60 rounded-xl p-5 hover:shadow-lg transition-all group cursor-pointer text-left">
              <div class="w-10 h-10 rounded-full <?= $a['bg'] ?> flex items-center justify-center mb-3 group-hover:scale-110 transition-transform">
                <span class="material-symbols-outlined <?= $a['cor'] ?>" style="font-size:22px"><?= $a['icon'] ?></span>
              </div>
              <div class="text-sm font-bold text-purple-900"><?= $a['label'] ?></div>
            </button>
            <?php endforeach; ?>
          </div>

          <!-- Ambiente terapêutico -->
          <div class="glass border border-purple-100/30 rounded-2xl p-6 relative overflow-hidden">
            <div class="absolute -right-16 -top-16 w-48 h-48 rounded-full opacity-10"
                 style="background:radial-gradient(circle,#4e0078,transparent)"></div>
            <h2 class="text-lg font-extrabold font-headline text-purple-900 mb-4 flex items-center gap-2">
              <span class="material-symbols-outlined text-purple-400">headphones</span>
              Ambiente Terapêutico
            </h2>
            <div class="flex flex-wrap gap-2 mb-5">
              <?php foreach ($playlists as $pl): ?>
              <a href="<?= $pl['url'] ?>" target="_blank"
                 class="flex items-center gap-2 px-4 py-2 rounded-full border border-purple-100 bg-white/60 hover:bg-purple-50 hover:border-purple-300 transition-all text-sm font-semibold text-purple-700">
                <?= $pl['emoji'] ?> <?= $pl['nome'] ?>
              </a>
              <?php endforeach; ?>
            </div>
            <!-- Player embed do primeiro -->
            <div id="player-area" class="hidden mt-3 rounded-xl overflow-hidden">
              <iframe id="yt-frame" width="100%" height="120" frameborder="0"
                      allow="autoplay; encrypted-media" allowfullscreen
                      style="border-radius:12px;"></iframe>
            </div>
            <button onclick="abrirPlayer()"
                    class="flex items-center gap-3 rounded-full px-5 py-2 text-white text-sm font-bold transition-opacity hover:opacity-90 mt-2"
                    style="background:linear-gradient(135deg,#4e0078,#b7004d)">
              <span class="material-symbols-outlined" style="font-size:18px;font-variation-settings:'FILL' 1">play_arrow</span>
              Preparar Ambiente
            </button>
          </div>

          <!-- Resumo semanal -->
          <div class="glass border border-purple-100/30 rounded-2xl p-6">
            <h3 class="text-xs font-extrabold uppercase tracking-widest text-purple-400 mb-4 flex items-center gap-2">
              Resumo semanal
              <span class="flex-1 h-px bg-purple-100/60"></span>
            </h3>
            <div class="grid grid-cols-3 gap-3">
              <div class="bg-purple-50 rounded-xl p-4 text-center">
                <div class="text-3xl font-extrabold font-headline text-purple-900"><?= $sessoes_semana ?></div>
                <div class="text-[10px] font-bold text-purple-400 uppercase mt-1">Esta semana</div>
              </div>
              <div class="bg-pink-50 rounded-xl p-4 text-center">
                <div class="text-3xl font-extrabold font-headline text-pink-700"><?= $sessoes_mes ?></div>
                <div class="text-[10px] font-bold text-pink-400 uppercase mt-1">Este mês</div>
              </div>
              <div class="bg-purple-50 rounded-xl p-4 text-center">
                <div class="text-3xl font-extrabold font-headline text-purple-900"><?= $total_pacientes ?></div>
                <div class="text-[10px] font-bold text-purple-400 uppercase mt-1">Pacientes ativos</div>
              </div>
            </div>
          </div>

          <!-- Notificações -->
          <div class="glass border border-purple-100/30 rounded-2xl p-6">
            <div class="flex justify-between items-center mb-4">
              <h3 class="text-xs font-extrabold uppercase tracking-widest text-purple-400">Novos agendamentos hoje</h3>
            </div>
            <?php if (empty($notifs)): ?>
              <p class="text-sm text-purple-300 text-center py-4">Nenhum novo agendamento hoje.</p>
            <?php else: ?>
              <div class="space-y-2">
                <?php foreach ($notifs as $n):
                  $data_fmt = date('d/m', strtotime($n['data']));
                  $hi = substr($n['hora_inicio'],0,5);
                ?>
                <div class="flex items-center gap-3 p-3 rounded-xl bg-white/50 border border-white shadow-sm">
                  <div class="w-9 h-9 rounded-full bg-purple-50 flex items-center justify-center text-purple-600">
                    <span class="material-symbols-outlined" style="font-size:18px">event_available</span>
                  </div>
                  <div class="flex-1 min-w-0">
                    <p class="text-sm font-bold text-purple-900"><?= htmlspecialchars($n['paciente_nome']) ?></p>
                    <p class="text-xs text-purple-400"><?= $data_fmt ?> às <?= $hi ?></p>
                  </div>
                  <span class="text-[10px] text-purple-300 font-medium">novo</span>
                </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

        </div>

        <!-- Coluna direita (4/12) -->
        <div class="lg:col-span-4 space-y-5">

          <!-- Inspiração rotativa -->
          <div class="rounded-2xl p-6 flex flex-col items-center justify-between text-center relative overflow-hidden min-h-[180px]"
               style="background:linear-gradient(160deg,#fff7fc,#fde7f3)">
            <span class="material-symbols-outlined text-pink-200 text-6xl mb-2">spa</span>
            <div>
              <h3 class="text-sm font-bold text-purple-700 mb-3">Inspiração 🌿</h3>
              <p class="text-purple-800 italic text-sm leading-relaxed font-medium">
                <?= htmlspecialchars($frase_hoje) ?>
              </p>
            </div>
            <div class="flex gap-1.5 mt-4">
              <div class="w-1.5 h-1.5 rounded-full bg-pink-300"></div>
              <div class="w-4 h-1.5 rounded-full bg-pink-500"></div>
              <div class="w-1.5 h-1.5 rounded-full bg-pink-300"></div>
            </div>
          </div>

          <!-- Metas -->
          <div class="glass border border-purple-100/30 rounded-2xl p-5">
            <h3 class="text-xs font-extrabold uppercase tracking-widest text-purple-400 mb-4">Metas</h3>

            <!-- Meta da equipe -->
            <div class="mb-4">
              <div class="flex justify-between items-center mb-1">
                <span class="text-xs font-bold text-purple-700">Equipe — 100 sessões no semestre</span>
                <span class="text-xs font-extrabold text-purple-900"><?= $sessoes_semestre ?>/100</span>
              </div>
              <div class="progress-bar">
                <div class="progress-fill" style="width:<?= min(100, round($sessoes_semestre)) ?>%"></div>
              </div>
              <p class="text-[10px] text-purple-300 mt-1"><?= max(0, 100 - $sessoes_semestre) ?> sessões restantes</p>
            </div>

            <!-- Meta pessoal -->
            <div class="mb-2">
              <div class="flex justify-between items-center mb-1">
                <span class="text-xs font-bold text-pink-600">Pessoal — 20 sessões no mês</span>
                <span class="text-xs font-extrabold text-purple-900"><?= $sessoes_mes ?>/20</span>
              </div>
              <div class="progress-bar">
                <div class="progress-fill" style="width:<?= min(100, round($sessoes_mes/20*100)) ?>%"></div>
              </div>
              <p class="text-[10px] text-purple-300 mt-1"><?= max(0,20-$sessoes_mes) ?> sessões para a meta</p>
            </div>
          </div>

          <!-- Como você está? -->
          <div class="glass border border-purple-100/30 rounded-2xl p-5">
            <h3 class="text-sm font-bold text-purple-900 mb-4">Como você está hoje?</h3>
            <div class="flex justify-around">
              <?php
              $emocoes = [
                  ['icon'=>'sentiment_satisfied', 'label'=>'Bem',    'cor'=>'hover:bg-green-50'],
                  ['icon'=>'sentiment_neutral',   'label'=>'Neutro', 'cor'=>'hover:bg-purple-50'],
                  ['icon'=>'sentiment_dissatisfied','label'=>'Cansado','cor'=>'hover:bg-pink-50'],
              ];
              foreach ($emocoes as $e):
              ?>
              <button class="flex flex-col items-center gap-1.5 group">
                <div class="w-12 h-12 rounded-full bg-purple-50 flex items-center justify-center <?= $e['cor'] ?> transition-colors group-hover:scale-105 transition-transform">
                  <span class="material-symbols-outlined text-purple-600" style="font-size:26px"><?= $e['icon'] ?></span>
                </div>
                <span class="text-[10px] font-bold text-purple-400"><?= $e['label'] ?></span>
              </button>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Dicas rápidas -->
          <div class="rounded-2xl p-6 text-white relative overflow-hidden"
               style="background:linear-gradient(135deg,#4e0078,#6a1b9a)">
            <div class="absolute right-2 top-2 opacity-10">
              <span class="material-symbols-outlined" style="font-size:72px">lightbulb</span>
            </div>
            <h3 class="text-[10px] font-bold uppercase tracking-widest text-purple-200 mb-3">Dicas rápidas</h3>
            <p class="text-sm font-bold leading-snug">
              "Evite sobrecarga entre atendimentos. Reserve 10 minutos para respirar."
            </p>
          </div>

        </div>
      </div>
    </div>

    <!-- ══ ABA: AGENDAMENTOS ══ -->
    <div id="aba-agenda" style="display:none">
      <?php
      $ag_semana = $pdo->query('
          SELECT a.id, a.status, a.data, h.hora_inicio, h.dia_semana,
                 up.nome AS paciente_nome, ut.nome AS terapeuta_nome, t.especialidade
          FROM agendamentos a
          JOIN horarios h ON h.id = a.horario_id
          JOIN ciclos c ON c.id = a.ciclo_id
          JOIN pacientes p ON p.id = c.paciente_id
          JOIN usuarios up ON up.id = p.usuario_id
          LEFT JOIN terapeutas t ON t.id = a.terapeuta_id
          LEFT JOIN usuarios ut ON ut.id = t.usuario_id
          WHERE a.data BETWEEN
            DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
            AND DATE_ADD(DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY), INTERVAL 4 DAY)
          ORDER BY a.data, h.hora_inicio
      ')->fetchAll();
      $ag_por_dia = [];
      foreach ($ag_semana as $a) $ag_por_dia[$a['data']][] = $a;
      ?>
      <div class="glass border border-purple-100/30 rounded-2xl p-5">
        <h2 class="text-sm font-extrabold uppercase tracking-widest text-purple-400 mb-4">Semana atual · Toda a equipe</h2>
        <?php if (empty($ag_semana)): ?>
          <p class="text-center text-purple-300 py-8">Nenhum agendamento esta semana.</p>
        <?php else: ?>
          <?php foreach ($ag_por_dia as $data => $sessoes):
            $dia_fmt = $dias_pt[date('N', strtotime($data))];
            $data_fmt = date('d/m', strtotime($data));
          ?>
          <div class="mb-4">
            <div class="text-xs font-bold uppercase tracking-wider text-purple-400 border-b border-purple-100/40 pb-1 mb-2">
              <?= $dia_fmt ?>, <?= $data_fmt ?>
            </div>
            <?php foreach ($sessoes as $s):
              $hi = substr($s['hora_inicio'],0,5);
              $cls = match($s['status']) { 'realizado'=>'bg-green-50 border-green-200', 'cancelado'=>'bg-red-50 border-red-200', default=>'bg-white/60 border-purple-100' };
              $bdg = match($s['status']) { 'realizado'=>'bg-green-100 text-green-800', 'cancelado'=>'bg-red-100 text-red-800', default=>'bg-yellow-100 text-yellow-800' };
              $bdg_txt = match($s['status']) { 'realizado'=>'Realizado', 'cancelado'=>'Cancelado', default=>'Agendado' };
            ?>
           <div class="flex items-center gap-3 p-3 rounded-xl border <?= $cls ?> mb-2">
  <div class="text-xs font-bold text-purple-400 min-w-[42px]"><?= $hi ?></div>
  <div class="flex-1 min-w-0">
    <div class="text-sm font-bold text-purple-900"><?= htmlspecialchars($s['paciente_nome']) ?></div>
    <div class="text-xs text-purple-400">
      <?= $s['terapeuta_nome'] ? htmlspecialchars($s['terapeuta_nome']).' · '.htmlspecialchars($s['especialidade']) : 'Terapeuta a definir' ?>
    </div>
  </div>
  <span class="text-[10px] font-bold px-2 py-1 rounded-full <?= $bdg ?>"><?= $bdg_txt ?></span>
  <?php if ($s['status'] === 'agendado'): ?>
  <form method="POST" action="confirmar_sessao.php" style="margin:0">
    <input type="hidden" name="agendamento_id" value="<?= $s['id'] ?>">
    <input type="hidden" name="acao" value="realizar">
    <button type="submit" class="text-[10px] font-bold px-2 py-1 rounded-full bg-green-100 text-green-800 hover:bg-green-200 transition-colors whitespace-nowrap">
      ✓ Realizado
    </button>
  </form>
  <form method="POST" action="confirmar_sessao.php" style="margin:0"
        onsubmit="return confirm('Marcar falta para <?= htmlspecialchars($s['paciente_nome']) ?>?')">
    <input type="hidden" name="agendamento_id" value="<?= $s['id'] ?>">
    <input type="hidden" name="acao" value="cancelar">
    <button type="submit" class="text-[10px] font-bold px-2 py-1 rounded-full bg-red-100 text-red-700 hover:bg-red-200 transition-colors whitespace-nowrap">
      ✗ Falta
    </button>
  </form>
  <?php endif; ?>
</div>
            <?php endforeach; ?>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- ══ ABA: CICLOS ══ -->
    <div id="aba-ciclos" style="display:none">
      <div class="glass border border-purple-100/30 rounded-2xl p-5">
        <h2 class="text-sm font-extrabold uppercase tracking-widest text-purple-400 mb-4">Meus ciclos ativos</h2>
        <?php if (empty($ciclos)): ?>
          <p class="text-center text-purple-300 py-8">Nenhum ciclo ativo.</p>
        <?php else: ?>
          <?php foreach ($ciclos as $c):
            $pct = $c['total_sessoes'] > 0 ? round($c['feitas']/$c['total_sessoes']*100) : 0;
          ?>
          <div class="flex items-center gap-4 p-3 rounded-xl bg-white/50 border border-purple-100/30 mb-2">
            <div>
              <div class="text-sm font-bold text-purple-900"><?= htmlspecialchars($c['paciente_nome']) ?></div>
              <div class="text-xs text-purple-400"><?= $c['feitas'] ?>/<?= $c['total_sessoes'] ?> sessões</div>
            </div>
            <div class="flex-1">
              <div class="progress-bar">
                <div class="progress-fill" style="width:<?= $pct ?>%"></div>
              </div>
            </div>
            <span class="text-xs font-bold text-purple-900"><?= $pct ?>%</span>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- ══ ABA: EQUIPE ══ -->
    <div id="aba-equipe" style="display:none">
      <div class="glass border border-purple-100/30 rounded-2xl p-5">
        <h2 class="text-sm font-extrabold uppercase tracking-widest text-purple-400 mb-4">Equipe — <?= date('m/Y') ?></h2>
        <?php
        $max_ses = max(array_column($equipe,'sessoes_mes') ?: [1]);
        foreach ($equipe as $i => $e):
          $c = explode(':', $cores_av[$i % count($cores_av)]);
          $ini = iniciais($e['nome']);
          $pct = $max_ses > 0 ? round($e['sessoes_mes']/$max_ses*100) : 0;
        ?>
        <div class="flex items-center gap-3 p-3 rounded-xl bg-white/50 border border-purple-100/30 mb-2">
          <div class="w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0"
               style="background:<?= $c[0] ?>;color:<?= $c[1] ?>">
            <?= $ini ?>
          </div>
          <div class="min-w-[110px]">
            <div class="text-sm font-bold text-purple-900"><?= htmlspecialchars($e['nome']) ?></div>
            <div class="text-xs text-purple-400"><?= htmlspecialchars($e['especialidade']) ?></div>
          </div>
          <div class="flex-1">
            <div class="progress-bar">
              <div class="progress-fill" style="width:<?= $pct ?>%"></div>
            </div>
          </div>
          <div class="text-right text-xs text-purple-400 min-w-[70px]">
            <div class="font-bold text-purple-900"><?= $e['sessoes_mes'] ?> sessões</div>
            <div><?= $e['total_pacientes'] ?> pac.</div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ══ ABA: PACIENTES ══ -->
    <div id="aba-pacientes" style="display:none">
      <?php
      $pacs = $pdo->query('
          SELECT p.id, up.nome, up.email, c.status AS ciclo_status,
                 c.total_sessoes,
                 COUNT(CASE WHEN a.status="realizado" THEN 1 END) AS feitas,
                 MAX(a.data) AS ultima
          FROM pacientes p
          JOIN usuarios up ON up.id = p.usuario_id
          LEFT JOIN ciclos c ON c.paciente_id = p.id
          LEFT JOIN agendamentos a ON a.ciclo_id = c.id
          GROUP BY p.id, up.nome, up.email, c.status, c.total_sessoes
          ORDER BY up.nome
      ')->fetchAll();
      ?>
      <div class="glass border border-purple-100/30 rounded-2xl p-5">
        <h2 class="text-sm font-extrabold uppercase tracking-widest text-purple-400 mb-4">Todos os pacientes</h2>
        <?php foreach ($pacs as $i => $p):
          $c = explode(':', $cores_av[$i % count($cores_av)]);
          $ini = iniciais($p['nome']);
          $bdg = match($p['ciclo_status']??'') {
              'ativo'=>'bg-green-100 text-green-800','concluido'=>'bg-yellow-100 text-yellow-800',
              default=>'bg-purple-50 text-purple-400'
          };
          $bdg_txt = match($p['ciclo_status']??'') {'ativo'=>'Ativo','concluido'=>'Concluído',default=>'Sem ciclo'};
          $ultima = $p['ultima'] ? date('d/m/Y', strtotime($p['ultima'])) : '—';
        ?>
        <div class="flex items-center gap-3 p-3 rounded-xl bg-white/50 border border-purple-100/30 mb-2">
          <div class="w-9 h-9 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0"
               style="background:<?= $c[0] ?>;color:<?= $c[1] ?>">
            <?= $ini ?>
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-sm font-bold text-purple-900"><?= htmlspecialchars($p['nome']) ?></div>
            <div class="text-xs text-purple-400">Última sessão: <?= $ultima ?></div>
          </div>
          <span class="text-[10px] font-bold px-2 py-1 rounded-full <?= $bdg ?>"><?= $bdg_txt ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ══ ABA: PERFIL ══ -->
    <div id="aba-perfil" style="display:none">
      <div class="glass border border-purple-100/30 rounded-2xl p-6 max-w-md">
        <?php $ini_p = iniciais($terapeuta['nome'] ?? $_SESSION['nome']); ?>
        <div class="w-14 h-14 rounded-full flex items-center justify-center text-xl font-bold mb-3"
             style="background:#E1F5EE;color:#085041"><?= $ini_p ?></div>
        <div class="text-lg font-extrabold font-headline text-purple-900 mb-1">
          <?= htmlspecialchars($terapeuta['nome'] ?? $_SESSION['nome']) ?>
        </div>
        <div class="text-xs text-purple-400 mb-5">Terapeuta voluntário · UERN</div>
        <?php
        $campos = [
            'Especialidade' => $terapeuta['especialidade'] ?? '—',
            'Período'       => $terapeuta['periodo'] ?? '—',
            'E-mail'        => $terapeuta['email'] ?? '—',
            'Telefone'      => $terapeuta['telefone'] ?? '—',
            'Tipo de acesso'=> ucfirst($_SESSION['tipo']),
        ];
        foreach ($campos as $l => $v):
        ?>
        <div class="flex justify-between py-3 border-b border-purple-50 text-sm">
          <span class="text-purple-400"><?= $l ?></span>
          <span class="font-bold text-purple-900"><?= htmlspecialchars($v) ?></span>
        </div>
        <?php endforeach; ?>
        <a href="../api/trocar_senha.php"
           class="mt-4 block text-center py-2.5 rounded-xl text-white text-sm font-bold"
           style="background:linear-gradient(135deg,#4e0078,#b7004d)">
          Trocar senha
        </a>
      </div>
    </div>

  </main>

  <script>
    var abas = ['dashboard','agenda','ciclos','equipe','pacientes','perfil'];

    function setAba(id) {
      abas.forEach(function(a) {
        var el = document.getElementById('aba-' + a);
        if (el) el.style.display = a === id ? 'block' : 'none';
      });
      document.querySelectorAll('.aba-h').forEach(function(btn) {
        btn.classList.remove('on');
        if (btn.getAttribute('onclick') && btn.getAttribute('onclick').includes(id)) {
          btn.classList.add('on');
        }
      });
    }

    var playerAberto = false;
    function abrirPlayer() {
      var area  = document.getElementById('player-area');
      var frame = document.getElementById('yt-frame');
      if (!playerAberto) {
        // Converte URL do YouTube para embed
        var url = '<?= $playlists[0]['url'] ?>';
        var vid = url.match(/(?:v=|youtu\.be\/)([^&\s]+)/);
        if (vid) {
          frame.src = 'https://www.youtube.com/embed/' + vid[1] + '?autoplay=1';
          area.classList.remove('hidden');
          playerAberto = true;
        }
      } else {
        frame.src = '';
        area.classList.add('hidden');
        playerAberto = false;
      }
    }
  </script>

</body>
</html>