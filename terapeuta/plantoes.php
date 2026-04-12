<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['tipo'], ['terapeuta','coordenador'])) {
    header('Location: ../index.php');
    exit;
}

$stmt = $pdo->prepare('
    SELECT t.id AS terapeuta_id, u.nome
    FROM terapeutas t JOIN usuarios u ON u.id = t.usuario_id
    WHERE u.id = ?
');
$stmt->execute([$_SESSION['usuario_id']]);
$terapeuta    = $stmt->fetch();
$terapeuta_id = $terapeuta['terapeuta_id'];

$sucesso = '';
$erro    = '';

// ── Adicionar horário (com suporte a co-terapia) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'add') {
    $dia         = (int)   $_POST['dia_semana'];
    $hora_inicio = trim(   $_POST['hora_inicio'] ?? '');
    $hora_fim    = trim(   $_POST['hora_fim']    ?? '');
    $vagas       = (int)  ($_POST['vagas']       ?? 4);
    $ambiente_id = !empty($_POST['ambiente_id']) ? (int)$_POST['ambiente_id'] : null;
    $tipo        = $_POST['tipo'] ?? 'plantao'; // plantao | agendamento
    $terapeutas_ids = $_POST['terapeutas_ids'] ?? []; // array de ids (co-terapia)

    if (!$dia || !$hora_inicio || !$hora_fim) {
        $erro = 'Preencha dia, horário de início e fim.';
    } elseif ($hora_fim <= $hora_inicio) {
        $erro = 'O horário de fim deve ser depois do início.';
    } else {
        // Verifica conflito
                  $chk = $pdo->prepare('
              SELECT id FROM horarios
              WHERE dia_semana = ? AND ativo = 1
              AND hora_inicio = ?
          ');
          $chk->execute([$dia, $hora_inicio.':00']);

        if ($chk->fetch()) {
            $erro = 'Já existe um horário conflitante neste dia.';
        } else {
            $duracao = (strtotime($hora_fim) - strtotime($hora_inicio)) / 60;
            $pdo->prepare('
                INSERT INTO horarios (dia_semana, hora_inicio, duracao_minutos, vagas_total, ativo)
                VALUES (?, ?, ?, ?, 1)
            ')->execute([$dia, $hora_inicio.':00', $duracao, $vagas]);
            $hid = $pdo->lastInsertId();

            // Vincula terapeutas (filtra IDs inválidos)
                  $ids_base = array_map('intval', $terapeutas_ids);
                  if ($terapeuta_id) $ids_base[] = $terapeuta_id;
                  $ids_vincular = array_unique(array_filter($ids_base));

                  foreach ($ids_vincular as $tid) {
                      // Verifica se o terapeuta existe antes de vincular
                      $chk_t = $pdo->prepare('SELECT id FROM terapeutas WHERE id = ?');
                      $chk_t->execute([$tid]);
                      if ($chk_t->fetch()) {
                          $pdo->prepare('INSERT INTO horario_terapeutas (horario_id, terapeuta_id) VALUES (?,?)')
                              ->execute([$hid, $tid]);
                      }
                  }

            $sucesso = 'Horário adicionado com sucesso!';
        }
    }
}

// ── Remover horário ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'remover') {
    $hid = (int) $_POST['horario_id'];
    // Só remove se não tiver agendamentos futuros
    $chk = $pdo->prepare('SELECT id FROM agendamentos WHERE horario_id = ? AND data >= CURDATE() AND status != "cancelado"');
    $chk->execute([$hid]);
    if ($chk->fetch()) {
        $erro = 'Não é possível remover: há agendamentos futuros neste horário.';
    } else {
        $pdo->prepare('DELETE FROM horario_terapeutas WHERE horario_id = ?')->execute([$hid]);
        $pdo->prepare('UPDATE horarios SET ativo = 0 WHERE id = ?')->execute([$hid]);
        $sucesso = 'Horário removido.';
    }
}

$dias_pt = ['','Segunda','Terça','Quarta','Quinta','Sexta'];

// Ambientes
$ambientes = $pdo->query('SELECT id, nome FROM ambientes WHERE ativo = 1 ORDER BY nome')->fetchAll();

// Todos os terapeutas (para co-terapia)
$todos_terapeutas = $pdo->query('
    SELECT t.id, u.nome FROM terapeutas t
    JOIN usuarios u ON u.id = t.usuario_id
    WHERE t.ativo = 1 ORDER BY u.nome
')->fetchAll();

// Horários dos terapeutas da equipe (todos, para a grade)
$horarios_raw = $pdo->query('
    SELECT h.id, h.dia_semana, h.hora_inicio, h.duracao_minutos, h.vagas_total,
           GROUP_CONCAT(u.nome ORDER BY u.nome SEPARATOR "||") AS nomes_terapeutas,
           GROUP_CONCAT(t.id ORDER BY u.nome SEPARATOR ",") AS ids_terapeutas,
           COUNT(DISTINCT ht.terapeuta_id) AS num_terapeutas
    FROM horarios h
    JOIN horario_terapeutas ht ON ht.horario_id = h.id
    JOIN terapeutas t ON t.id = ht.terapeuta_id
    JOIN usuarios u ON u.id = t.usuario_id
    WHERE h.ativo = 1
    GROUP BY h.id
    ORDER BY h.dia_semana, h.hora_inicio
')->fetchAll();

// Próximos agendamentos (14 dias)
$agend_raw = $pdo->query('
    SELECT a.id, a.data, a.status, h.dia_semana, h.hora_inicio, h.id AS horario_id,
           up.nome AS paciente_nome
    FROM agendamentos a
    JOIN horarios h ON h.id = a.horario_id
    JOIN ciclos c ON c.id = a.ciclo_id
    JOIN pacientes p ON p.id = c.paciente_id
    JOIN usuarios up ON up.id = p.usuario_id
    WHERE a.data BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
    AND a.status = "agendado"
    ORDER BY a.data, h.hora_inicio
')->fetchAll();

// Mapeia agendamentos por horario_id
$agend_map = [];
foreach ($agend_raw as $a) {
    $agend_map[$a['horario_id']][] = $a;
}

// Métricas
$metricas = $pdo->query('
    SELECT
        (SELECT COUNT(*) FROM agendamentos WHERE data = CURDATE() AND status = "agendado") AS hoje,
        (SELECT COUNT(*) FROM horarios h
            WHERE h.ativo = 1
            AND (h.vagas_total - (SELECT COUNT(*) FROM agendamentos a2
                WHERE a2.horario_id = h.id AND a2.data >= CURDATE() AND a2.status != "cancelado")) > 0
        ) AS vagas,
        (SELECT COUNT(DISTINCT terapeuta_id) FROM horario_terapeutas ht
            JOIN horarios hh ON hh.id = ht.horario_id WHERE hh.ativo = 1) AS plantoes,
        (SELECT COUNT(*) FROM horario_terapeutas ht2
            JOIN horarios hh2 ON hh2.id = ht2.horario_id
            WHERE hh2.ativo = 1
            GROUP BY hh2.id HAVING COUNT(*) >= 2 LIMIT 1) AS coterapias
')->fetch();

// Organiza horários por dia
$grade = [];
for ($d = 1; $d <= 5; $d++) $grade[$d] = [];
foreach ($horarios_raw as $h) {
    $grade[$h['dia_semana']][] = $h;
}

// Verifica se o terapeuta logado cobre cada horário
$meus_ids = array_column($pdo->query(
    "SELECT horario_id FROM horario_terapeutas WHERE terapeuta_id = $terapeuta_id"
)->fetchAll(), 'horario_id');

// Horas para a grade (linhas)
$horas_grade = ['07:00','09:00','11:00','14:00','16:00'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NUPICS Caicó — Cronograma</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: {
            headline: ['Plus Jakarta Sans'],
            body: ['Manrope'],
          },
          colors: {
            primary: '#4e0078',
            'primary-light': '#6a1b9a',
            secondary: '#b7004d',
            'surface': '#fff7fc',
            'surface-variant': '#ecdeed',
            'on-surface': '#201923',
            'on-surface-variant': '#4d4351',
            'outline-variant': '#d0c2d3',
          }
        }
      }
    }
  </script>
  <style>
    body { font-family: 'Manrope', sans-serif; background: #fff7fc; }
    h1,h2,h3,.headline { font-family: 'Plus Jakarta Sans', sans-serif; }
    .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; font-size: 20px; }
    .glass { background: rgba(255,255,255,0.6); backdrop-filter: blur(10px); }
    .glass-dark { background: rgba(32,25,35,0.85); backdrop-filter: blur(16px); }
    .slot-plantao { background: linear-gradient(135deg, #4e0078, #6a1b9a); color: white; }
    .slot-agendado { background: rgba(255,255,255,0.9); border: 1.5px solid rgba(183,0,77,0.3); }
    .slot-coterapia { background: linear-gradient(135deg, #4e0078, #b7004d); color: white; }
    .slot-livre { border: 2px dashed rgba(78,0,120,0.25); }
    input[type=time]::-webkit-calendar-picker-indicator { filter: invert(0.5); }
    select option { color: #201923; background: white; }
  </style>
</head>
<body class="min-h-screen relative overflow-x-hidden">

  <!-- Fundo decorativo -->
  <div class="fixed inset-0 z-0 pointer-events-none opacity-30"
       style="background: radial-gradient(ellipse at 20% 50%, #e9d5ff 0%, transparent 60%),
                          radial-gradient(ellipse at 80% 20%, #fce7f3 0%, transparent 50%),
                          radial-gradient(ellipse at 60% 80%, #ede9fe 0%, transparent 50%);">
  </div>

  <!-- TOPNAV -->
  <header class="w-full sticky top-0 z-50 glass shadow-sm border-b border-purple-100/40">
    <div class="flex justify-between items-center px-6 py-3 max-w-7xl mx-auto">
      <div class="text-lg font-extrabold font-headline"
           style="background: linear-gradient(135deg,#4e0078,#b7004d);-webkit-background-clip:text;-webkit-text-fill-color:transparent">
        NUPICS Caicó
      </div>
      <nav class="hidden md:flex items-center gap-6 text-sm font-semibold font-headline">
        <a href="dashboard.php" class="text-purple-400 hover:text-purple-700 transition-colors">Painel</a>
        <a href="plantoes.php" class="text-purple-900 border-b-2 border-purple-700 pb-0.5">Cronograma</a>
        <a href="../api/trocar_senha.php" class="text-purple-400 hover:text-purple-700 transition-colors">Perfil</a>
      </nav>
      <div class="flex items-center gap-3">
        <span class="text-sm text-purple-700 font-medium hidden md:block">
          <?= htmlspecialchars($terapeuta['nome']) ?>
        </span>
        <a href="../api/logout.php"
           class="text-xs text-purple-400 hover:text-purple-700 border border-purple-200 rounded-full px-3 py-1 transition-colors">
          Sair
        </a>
      </div>
    </div>
  </header>

  <main class="relative z-10 max-w-7xl mx-auto px-4 py-8">

    <?php if ($sucesso): ?>
    <div class="mb-4 bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm font-medium">
      <?= $sucesso ?>
    </div>
    <?php endif; ?>
    <?php if ($erro): ?>
    <div class="mb-4 bg-red-50 border border-red-200 text-red-800 rounded-xl px-4 py-3 text-sm font-medium">
      <?= htmlspecialchars($erro) ?>
    </div>
    <?php endif; ?>

    <!-- Métricas -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
      <div class="glass p-5 rounded-xl border border-purple-100/40 shadow-sm">
        <div class="text-xs text-purple-500 font-semibold mb-1">Atendimentos hoje</div>
        <div class="flex items-end justify-between">
          <span class="text-3xl font-extrabold font-headline text-purple-900"><?= str_pad($metricas['hoje'],2,'0',STR_PAD_LEFT) ?></span>
          <span class="material-symbols-outlined text-purple-300">calendar_today</span>
        </div>
      </div>
      <div class="glass p-5 rounded-xl border border-purple-100/40 shadow-sm">
        <div class="text-xs text-pink-500 font-semibold mb-1">Vagas disponíveis</div>
        <div class="flex items-end justify-between">
          <span class="text-3xl font-extrabold font-headline text-pink-700"><?= str_pad($metricas['vagas'],2,'0',STR_PAD_LEFT) ?></span>
          <span class="material-symbols-outlined text-pink-300">event_available</span>
        </div>
      </div>
      <div class="glass p-5 rounded-xl border border-purple-100/40 shadow-sm">
        <div class="text-xs text-purple-500 font-semibold mb-1">Plantões ativos</div>
        <div class="flex items-end justify-between">
          <span class="text-3xl font-extrabold font-headline text-purple-900"><?= str_pad(count($horarios_raw),2,'0',STR_PAD_LEFT) ?></span>
          <span class="material-symbols-outlined text-purple-300">medical_services</span>
        </div>
      </div>
      <div class="glass p-5 rounded-xl border border-purple-100/40 shadow-sm">
        <div class="text-xs text-pink-500 font-semibold mb-1">Co-terapias ativas</div>
        <div class="flex items-end justify-between">
          <?php
          $coterapias = array_filter($horarios_raw, fn($h) => $h['num_terapeutas'] >= 2);
          ?>
          <span class="text-3xl font-extrabold font-headline text-pink-700"><?= str_pad(count($coterapias),2,'0',STR_PAD_LEFT) ?></span>
          <span class="material-symbols-outlined text-pink-300">group</span>
        </div>
      </div>
    </div>

    <!-- Cabeçalho da grade -->
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-extrabold font-headline text-purple-900">Cronograma Semanal</h1>
      <button onclick="abrirModal()"
              class="flex items-center gap-2 text-white text-sm font-bold px-5 py-2.5 rounded-full shadow-lg transition-opacity hover:opacity-90"
              style="background: linear-gradient(135deg,#4e0078,#6a1b9a)">
        <span class="material-symbols-outlined" style="font-size:18px">add</span>
        Novo Horário
      </button>
    </div>

    <!-- Grade semanal -->
    <div class="glass rounded-2xl border border-purple-100/30 overflow-hidden shadow-xl">

      <!-- Cabeçalho dos dias -->
      <div class="grid border-b border-purple-100/30" style="grid-template-columns: 70px repeat(5,1fr)">
        <div class="p-3 text-center text-xs font-bold text-purple-400 uppercase tracking-widest">Horário</div>
        <?php for ($d = 1; $d <= 5; $d++): ?>
        <div class="p-3 text-center text-sm font-bold text-purple-900 border-l border-purple-100/20 font-headline">
          <?= $dias_pt[$d] ?>
        </div>
        <?php endfor; ?>
      </div>

      <!-- Corpo da grade -->
      <div class="grid" style="grid-template-columns: 70px repeat(5,1fr)">

        <!-- Coluna de horas -->
        <div class="flex flex-col text-xs font-semibold text-purple-400">
          <?php foreach ($horas_grade as $h): ?>
          <div class="h-32 border-b border-purple-50/50 flex items-start justify-center pt-3">
            <?= $h ?>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Colunas dos dias -->
        <?php for ($d = 1; $d <= 5; $d++): ?>
        <div class="border-l border-purple-100/20 p-2 space-y-2">
          <?php if (empty($grade[$d])): ?>
            <!-- Dia sem horários -->
            <div class="h-28 slot-livre rounded-xl flex items-center justify-center cursor-pointer hover:bg-purple-50/50 transition-colors"
                 onclick="abrirModalDia(<?= $d ?>)">
              <span class="text-xs font-bold text-purple-300 hover:text-purple-600">DISPONÍVEL</span>
            </div>
          <?php else: ?>
            <?php foreach ($grade[$d] as $slot):
              $hi = substr($slot['hora_inicio'], 0, 5);
              $hf = date('H:i', strtotime($slot['hora_inicio']) + $slot['duracao_minutos'] * 60);
              $nomes = explode('||', $slot['nomes_terapeutas']);
              $ids_t = explode(',', $slot['ids_terapeutas']);
              $is_coterapia = $slot['num_terapeutas'] >= 2;
              $eu_cubro = in_array($slot['id'], $meus_ids);
              $agendamentos_slot = $agend_map[$slot['id']] ?? [];
              $ocupados = count($agendamentos_slot);
              $livres   = $slot['vagas_total'] - $ocupados;

              if ($is_coterapia) $cls = 'slot-coterapia';
              elseif (!empty($agendamentos_slot)) $cls = 'slot-agendado';
              else $cls = 'slot-plantao';
            ?>
            <div class="h-28 <?= $cls ?> rounded-xl p-3 flex flex-col justify-between shadow-md relative group">

              <!-- Cabeçalho do slot -->
              <div>
                <?php if ($is_coterapia): ?>
                  <span class="text-[9px] uppercase font-bold opacity-80 flex items-center gap-1">
                    <span class="material-symbols-outlined" style="font-size:11px">group</span> Co-terapia
                  </span>
                <?php elseif (!empty($agendamentos_slot)): ?>
                  <span class="text-[9px] uppercase font-bold text-pink-600">Agendamento</span>
                <?php else: ?>
                  <span class="text-[9px] uppercase font-bold opacity-70">Plantão</span>
                <?php endif; ?>

                <!-- Nomes dos terapeutas -->
                <div class="mt-0.5">
                  <?php foreach ($nomes as $nm): ?>
                  <p class="text-xs font-semibold leading-tight <?= (!$is_coterapia && !empty($agendamentos_slot)) ? 'text-purple-900' : 'text-white' ?>">
                    <?= htmlspecialchars(explode(' ', trim($nm))[0].' '.explode(' ', trim($nm))[1] ?? '') ?>
                  </p>
                  <?php endforeach; ?>
                </div>

                <!-- Paciente agendado -->
                <?php if (!empty($agendamentos_slot)): ?>
                <p class="text-[10px] text-purple-500 mt-0.5">
                  <?= htmlspecialchars($agendamentos_slot[0]['paciente_nome']) ?>
                  <?php if (count($agendamentos_slot) > 1): ?>
                    +<?= count($agendamentos_slot)-1 ?>
                  <?php endif; ?>
                </p>
                <?php endif; ?>
              </div>

              <!-- Rodapé: horário + vagas -->
              <div class="flex items-center justify-between">
                <span class="text-[9px] <?= (!$is_coterapia && !empty($agendamentos_slot)) ? 'bg-purple-100 text-purple-700' : 'bg-white/20 text-white' ?> px-2 py-0.5 rounded-full font-bold">
                  <?= $hi ?> – <?= $hf ?>
                </span>
                <div class="flex gap-0.5">
                  <?php for ($v = 0; $v < $slot['vagas_total']; $v++): ?>
                  <div class="w-1.5 h-1.5 rounded-full <?= $v < $livres ? 'bg-green-400' : 'bg-white/30' ?>"></div>
                  <?php endfor; ?>
                </div>
              </div>

              <!-- Botão remover (hover) -->
              <?php if ($eu_cubro): ?>
              <form method="POST" class="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
                <input type="hidden" name="acao" value="remover">
                <input type="hidden" name="horario_id" value="<?= $slot['id'] ?>">
                <button type="submit"
                        onclick="return confirm('Remover este horário?')"
                        class="w-5 h-5 rounded-full bg-red-500/80 text-white text-xs flex items-center justify-center hover:bg-red-600">
                  ✕
                </button>
              </form>
              <?php endif; ?>
            </div>

            <?php endforeach; ?>

            <!-- Botão adicionar no mesmo dia -->
            <div class="slot-livre rounded-xl h-10 flex items-center justify-center cursor-pointer hover:bg-purple-50/50 transition-colors"
                 onclick="abrirModalDia(<?= $d ?>)">
              <span class="text-[10px] font-bold text-purple-300">+ horário</span>
            </div>
          <?php endif; ?>
        </div>
        <?php endfor; ?>

      </div>
    </div>

    <!-- Legenda -->
    <div class="flex gap-6 mt-4 flex-wrap">
      <div class="flex items-center gap-2 text-xs text-purple-500">
        <div class="w-3 h-3 rounded-sm" style="background:linear-gradient(135deg,#4e0078,#6a1b9a)"></div>Plantão
      </div>
      <div class="flex items-center gap-2 text-xs text-purple-500">
        <div class="w-3 h-3 rounded-sm bg-white border border-pink-300"></div>Agendado
      </div>
      <div class="flex items-center gap-2 text-xs text-purple-500">
        <div class="w-3 h-3 rounded-sm" style="background:linear-gradient(135deg,#4e0078,#b7004d)"></div>Co-terapia
      </div>
      <div class="flex items-center gap-2 text-xs text-purple-500">
        <div class="w-2 h-2 rounded-full bg-green-400"></div>Vaga livre
        <div class="w-2 h-2 rounded-full bg-purple-200"></div>Vaga ocupada
      </div>
    </div>

  </main>

  <!-- Rodapé -->
  <footer class="relative z-10 mt-10 py-6 px-8 border-t border-purple-100/30 flex flex-col md:flex-row justify-between items-center text-xs text-purple-400 gap-2">
    <span>© <?= date('Y') ?> NUPICS Caicó · Práticas Integrativas e Complementares · UERN</span>
    <div class="flex gap-5">
      <a href="#" class="hover:text-pink-500 transition-colors">Privacidade</a>
      <a href="#" class="hover:text-pink-500 transition-colors">Termos de Uso</a>
      <a href="#" class="hover:text-pink-500 transition-colors">Contato</a>
    </div>
  </footer>

  <!-- ══ MODAL: Novo Horário ══ -->
  <div id="modal-horario"
       class="fixed inset-0 z-50 hidden items-center justify-center p-4"
       style="background:rgba(32,25,35,0.6);backdrop-filter:blur(4px)">

    <div class="glass-dark rounded-2xl w-full max-w-md shadow-2xl overflow-hidden">

      <!-- Cabeçalho do modal -->
      <div class="flex items-center justify-between px-6 pt-5 pb-4 border-b border-white/10">
        <div class="flex items-center gap-2 text-white font-bold font-headline text-base">
          <span class="material-symbols-outlined text-pink-400" style="font-size:18px">calendar_month</span>
          + Novo Horário
        </div>
        <button onclick="fecharModal()" class="text-white/40 hover:text-white transition-colors text-lg">✕</button>
      </div>

      <form method="POST" action="plantoes.php" class="px-6 py-5 space-y-4">
        <input type="hidden" name="acao" value="add">

        <div class="grid grid-cols-2 gap-4">
          <!-- Dia da semana -->
          <div>
            <label class="text-[10px] uppercase font-bold text-purple-300 tracking-wider mb-1 block">Dia da semana</label>
            <select name="dia_semana" id="modal-dia" required
                    class="w-full bg-white/10 border border-white/20 text-white rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400">
              <option value="">Selecione</option>
              <?php for ($d = 1; $d <= 5; $d++): ?>
              <option value="<?= $d ?>"><?= $dias_pt[$d] ?>-feira</option>
              <?php endfor; ?>
            </select>
          </div>

          <!-- Ambiente -->
          <div>
            <label class="text-[10px] uppercase font-bold text-purple-300 tracking-wider mb-1 block">Maca / Sala</label>
            <select name="ambiente_id"
                    class="w-full bg-white/10 border border-white/20 text-white rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400">
              <option value="">Sem ambiente</option>
              <?php foreach ($ambientes as $am): ?>
              <option value="<?= $am['id'] ?>"><?= htmlspecialchars($am['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
          <!-- Horário início -->
          <div>
            <label class="text-[10px] uppercase font-bold text-purple-300 tracking-wider mb-1 block">Horário início</label>
            <input type="time" name="hora_inicio" required
                   class="w-full bg-white/10 border border-white/20 text-white rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400">
          </div>
          <!-- Horário fim -->
          <div>
            <label class="text-[10px] uppercase font-bold text-purple-300 tracking-wider mb-1 block">Horário fim</label>
            <input type="time" name="hora_fim" required
                   class="w-full bg-white/10 border border-white/20 text-white rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400">
          </div>
        </div>

        <!-- Vagas -->
        <div>
          <label class="text-[10px] uppercase font-bold text-purple-300 tracking-wider mb-1 block">Número de vagas</label>
          <select name="vagas"
                  class="w-full bg-white/10 border border-white/20 text-white rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400">
            <?php for ($v = 1; $v <= 8; $v++): ?>
            <option value="<?= $v ?>" <?= $v===4?'selected':'' ?>><?= $v ?> vaga<?= $v!==1?'s':'' ?></option>
            <?php endfor; ?>
          </select>
        </div>

        <!-- Tipo de registro -->
        <div>
          <label class="text-[10px] uppercase font-bold text-purple-300 tracking-wider mb-2 block">Tipo de registro</label>
          <div class="flex gap-4">
            <label class="flex items-center gap-2 text-white text-sm cursor-pointer">
              <input type="radio" name="tipo" value="plantao" checked
                     class="accent-purple-500"> Plantão
            </label>
            <label class="flex items-center gap-2 text-white text-sm cursor-pointer">
              <input type="radio" name="tipo" value="agendamento"
                     class="accent-pink-500"> Agendamento
            </label>
          </div>
        </div>

        <!-- Co-terapeutas -->
        <div>
          <label class="text-[10px] uppercase font-bold text-purple-300 tracking-wider mb-2 block">
            Terapeutas <span class="text-white/40 normal-case font-normal">(até 2 para co-terapia)</span>
          </label>

          <div id="lista-terapeutas" class="space-y-2">
            <!-- Terapeuta logado (fixo) -->
            <div class="flex items-center justify-between bg-purple-600/40 border border-purple-400/40 rounded-xl px-3 py-2">
              <span class="text-white text-sm font-medium">
                <?= htmlspecialchars($terapeuta['nome']) ?> <span class="text-purple-300 text-xs">(você)</span>
              </span>
            </div>
          </div>

          <div id="segundo-terapeuta" class="mt-2" style="display:none">
            <select name="terapeutas_ids[]" id="sel-segundo"
                    class="w-full bg-white/10 border border-white/20 text-white rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400">
              <option value="">Selecione o segundo terapeuta</option>
              <?php foreach ($todos_terapeutas as $t): ?>
                <?php if ($t['id'] !== $terapeuta_id): ?>
                <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['nome']) ?></option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </div>

          <button type="button" onclick="toggleSegundoTerapeuta()"
                  id="btn-add-terapeuta"
                  class="mt-2 text-xs text-pink-400 hover:text-pink-300 font-semibold flex items-center gap-1 transition-colors">
            <span class="material-symbols-outlined" style="font-size:14px">add</span>
            + Adicionar segundo terapeuta (co-terapia)
          </button>
        </div>

        <!-- Botão confirmar -->
        <button type="submit"
                class="w-full py-3 rounded-xl text-white font-bold text-sm mt-2 transition-opacity hover:opacity-90"
                style="background: linear-gradient(135deg,#b7004d,#9333ea)">
          Confirmar Horário
        </button>

      </form>
    </div>
  </div>

  <script>
    function abrirModal() {
      document.getElementById('modal-horario').style.display = 'flex';
    }

    function abrirModalDia(dia) {
      document.getElementById('modal-horario').style.display = 'flex';
      document.getElementById('modal-dia').value = dia;
    }

    function fecharModal() {
      document.getElementById('modal-horario').style.display = 'none';
    }

    document.getElementById('modal-horario').addEventListener('click', function(e) {
      if (e.target === this) fecharModal();
    });

    var segundoVisivel = false;
    function toggleSegundoTerapeuta() {
      segundoVisivel = !segundoVisivel;
      var wrap = document.getElementById('segundo-terapeuta');
      var btn  = document.getElementById('btn-add-terapeuta');
      wrap.style.display = segundoVisivel ? 'block' : 'none';
      btn.innerHTML = segundoVisivel
        ? '<span class="material-symbols-outlined" style="font-size:14px">remove</span> Remover co-terapeuta'
        : '<span class="material-symbols-outlined" style="font-size:14px">add</span> + Adicionar segundo terapeuta (co-terapia)';
      if (!segundoVisivel) document.getElementById('sel-segundo').value = '';
    }
  </script>

</body>
</html>