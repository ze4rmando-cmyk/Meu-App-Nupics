<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'paciente') {
    header('Location: ../index.php');
    exit;
}

$stmt = $pdo->prepare('
    SELECT p.id AS paciente_id, u.nome
    FROM pacientes p JOIN usuarios u ON u.id = p.usuario_id
    WHERE u.id = ?
');
$stmt->execute([$_SESSION['usuario_id']]);
$paciente    = $stmt->fetch();
$paciente_id = $paciente['paciente_id'];

$erro    = '';
$passo   = 1;
$horario_sel = null;
$confirmacao = null;

$dias_pt = ['','Segunda','Terça','Quarta','Quinta','Sexta','Sábado','Domingo'];

// Verifica se paciente está bloqueado (2+ faltas)
$stmt = $pdo->prepare('
    SELECT COUNT(*) AS faltas FROM agendamentos a
    JOIN ciclos c ON c.id = a.ciclo_id
    WHERE c.paciente_id = ? AND a.status = "cancelado"
');
$stmt->execute([$paciente_id]);
$faltas = $stmt->fetch()['faltas'];
$bloqueado = $faltas >= 2;

// Busca horários com vagas disponíveis nas próximas 4 semanas
$horarios_raw = $pdo->query('
    SELECT h.id, h.dia_semana, h.hora_inicio, h.duracao_minutos, h.vagas_total,
           COUNT(DISTINCT ht.terapeuta_id) AS num_terapeutas
    FROM horarios h
    JOIN horario_terapeutas ht ON ht.horario_id = h.id
    WHERE h.ativo = 1
    GROUP BY h.id
    ORDER BY h.dia_semana, h.hora_inicio
')->fetchAll();

// Calcula vagas por horário para cada semana futura
function proximas_datas_horario($dia_semana, $semanas = 4) {
    $datas = [];
    $hoje  = new DateTime();
    $hoje->setTime(0, 0, 0);
    for ($s = 0; $s < $semanas * 2; $s++) {
        $dt   = clone $hoje;
        $diff = ($dia_semana - (int)$hoje->format('N') + 7) % 7;
        if ($diff === 0) $diff = 7;
        $dt->modify('+' . ($diff + ($s * 7)) . ' days');
        if (count($datas) >= $semanas) break;
        $datas[] = $dt->format('Y-m-d');
    }
    return array_unique($datas);
}

$horarios = [];
foreach ($horarios_raw as $h) {
    $datas  = proximas_datas_horario($h['dia_semana']);
    $opcoes = [];
    foreach ($datas as $data) {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) AS ocupados FROM agendamentos
            WHERE horario_id = ? AND data = ? AND status != "cancelado"
        ');
        $stmt->execute([$h['id'], $data]);
        $ocupados = $stmt->fetch()['ocupados'];
        $livres   = $h['vagas_total'] - $ocupados;
        $opcoes[] = ['data' => $data, 'livres' => $livres, 'total' => $h['vagas_total']];
    }
    $total_livres = array_sum(array_column($opcoes, 'livres'));
    if ($total_livres > 0) {
        $h['opcoes']       = $opcoes;
        $h['total_livres'] = $total_livres;
        $horarios[]        = $h;
    }
}

// Agrupa por dia
$por_dia = [];
for ($d = 1; $d <= 5; $d++) $por_dia[$d] = [];
foreach ($horarios as $h) $por_dia[$h['dia_semana']][] = $h;

// ── PASSO 2: horário selecionado → mostra datas ──
if (isset($_POST['horario_id']) && !isset($_POST['data_escolhida'])) {
    $passo = 2;
    $hid   = (int) $_POST['horario_id'];
    foreach ($horarios as $h) {
        if ($h['id'] === $hid) { $horario_sel = $h; break; }
    }
    if (!$horario_sel) { $passo = 1; $erro = 'Horário inválido.'; }
}

// ── PASSO 3: confirma ──
if (isset($_POST['horario_id']) && isset($_POST['data_escolhida'])) {
    $hid  = (int) $_POST['horario_id'];
    $data = $_POST['data_escolhida'];

    // Verifica vaga
    $stmt = $pdo->prepare('
        SELECT h.vagas_total, COUNT(a.id) AS ocupados
        FROM horarios h
        LEFT JOIN agendamentos a ON a.horario_id = h.id
            AND a.data = ? AND a.status != "cancelado"
        WHERE h.id = ?
        GROUP BY h.vagas_total
    ');
    $stmt->execute([$data, $hid]);
    $vaga = $stmt->fetch();

    if (!$vaga || ($vaga['vagas_total'] - $vaga['ocupados']) <= 0) {
        $erro  = 'Esta vaga foi preenchida. Escolha outro horário.';
        $passo = 1;
    } else {
        // Verifica duplicata
        $stmt = $pdo->prepare('
            SELECT a.id FROM agendamentos a
            JOIN ciclos c ON c.id = a.ciclo_id
            WHERE c.paciente_id = ? AND a.horario_id = ? AND a.data = ?
            AND a.status != "cancelado"
        ');
        $stmt->execute([$paciente_id, $hid, $data]);
        if ($stmt->fetch()) {
            $erro  = 'Você já tem uma sessão neste horário e data.';
            $passo = 1;
        } else {
            // Ciclo ativo ou novo — sempre começa da data atual
            $stmt = $pdo->prepare('
                SELECT id, total_sessoes FROM ciclos
                WHERE paciente_id = ? AND status = "ativo" LIMIT 1
            ');
            $stmt->execute([$paciente_id]);
            $ciclo = $stmt->fetch();

            if (!$ciclo) {
                $stmt = $pdo->prepare('
                    INSERT INTO ciclos (paciente_id, total_sessoes, status, criado_em)
                    VALUES (?, 4, "ativo", NOW())
                ');
                $stmt->execute([$paciente_id]);
                $ciclo_id = $pdo->lastInsertId();
            } else {
                $ciclo_id = $ciclo['id'];
            }

            // Número da sessão
            $stmt = $pdo->prepare('
                SELECT COUNT(*) AS total FROM agendamentos
                WHERE ciclo_id = ? AND status != "cancelado"
            ');
            $stmt->execute([$ciclo_id]);
            $numero_sessao = $stmt->fetch()['total'] + 1;

            $stmt = $pdo->prepare('
                INSERT INTO agendamentos (ciclo_id, horario_id, data, numero_sessao, status)
                VALUES (?, ?, ?, ?, "agendado")
            ');
            $stmt->execute([$ciclo_id, $hid, $data, $numero_sessao]);

            // Detalhes para confirmação
            $stmt = $pdo->prepare('SELECT * FROM horarios WHERE id = ?');
            $stmt->execute([$hid]);
            $confirmacao = $stmt->fetch();
            $confirmacao['data']          = $data;
            $confirmacao['numero_sessao'] = $numero_sessao;

            // Gera as próximas sessões automaticamente (semanalmente)
            // As datas futuras são calculadas a cada 7 dias a partir da data escolhida
            $total_ciclo = $ciclo['total_sessoes'] ?? 4;
            $sessoes_restantes = $total_ciclo - $numero_sessao;
            if ($sessoes_restantes > 0) {
                $dt_base = new DateTime($data);
                for ($s = 1; $s <= $sessoes_restantes; $s++) {
                    $dt_base->modify('+7 days');
                    $data_futura = $dt_base->format('Y-m-d');
                    // Verifica se ainda há vaga
                    $chk = $pdo->prepare('
                        SELECT COUNT(*) AS oc FROM agendamentos
                        WHERE horario_id = ? AND data = ? AND status != "cancelado"
                    ');
                    $chk->execute([$hid, $data_futura]);
                    $oc = $chk->fetch()['oc'];
                    if ($oc < $vaga['vagas_total']) {
                        $pdo->prepare('
                            INSERT INTO agendamentos (ciclo_id, horario_id, data, numero_sessao, status)
                            VALUES (?, ?, ?, ?, "agendado")
                        ')->execute([$ciclo_id, $hid, $data_futura, $numero_sessao + $s]);
                    }
                }
            }

            $passo = 3;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NUPICS — Agendar Sessão</title>
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
    body { font-family:'Manrope',sans-serif; }
    h1,h2,h3 { font-family:'Plus Jakarta Sans',sans-serif; }
    .material-symbols-outlined { font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; }
    .slot-sel { border:2px solid #4e0078 !important; background:white !important; box-shadow:0 4px 20px rgba(78,0,120,.15); }
    .dia-sel  { border:2px solid rgba(78,0,120,.2) !important; background:rgba(78,0,120,.03) !important; }
  </style>
</head>
<body class="min-h-screen text-gray-900" style="background:radial-gradient(circle at top left,#f7eaf8,#fff7fc)">

<!-- Decorações de fundo -->
<div class="fixed inset-0 pointer-events-none -z-10 overflow-hidden">
  <div class="absolute -top-40 -right-40 w-96 h-96 rounded-full blur-[100px]" style="background:rgba(78,0,120,.08)"></div>
  <div class="absolute top-1/2 -left-40 w-96 h-96 rounded-full blur-[100px]" style="background:rgba(183,0,77,.07)"></div>
</div>

<!-- TOPNAV -->
<nav class="fixed top-0 w-full z-50 bg-white/70 backdrop-blur-md shadow-sm">
  <div class="flex justify-between items-center px-6 md:px-10 h-16 max-w-7xl mx-auto">
    <span class="text-xl font-extrabold headline"
          style="background:linear-gradient(135deg,#4e0078,#b7004d);-webkit-background-clip:text;-webkit-text-fill-color:transparent">
      NUPICS
    </span>
    <div class="hidden md:flex items-center gap-8 font-semibold text-sm">
      <a href="dashboard.php" class="text-gray-500 hover:text-purple-700 transition-colors">Início</a>
      <a href="dashboard.php" class="text-gray-500 hover:text-purple-700 transition-colors">Meus Agendamentos</a>
      <a href="../api/logout.php" class="text-purple-700 font-bold hover:text-pink-600 transition-colors">Sair</a>
    </div>
  </div>
</nav>

<main class="pt-28 pb-20 px-5 md:px-10 max-w-7xl mx-auto">

  <?php if ($bloqueado): ?>
  <!-- Bloqueio por faltas -->
  <div class="max-w-xl mx-auto mt-16 text-center">
    <div class="w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-5"
         style="background:#ffdad6">
      <span class="material-symbols-outlined text-red-700" style="font-size:32px">block</span>
    </div>
    <h2 class="text-2xl font-extrabold headline text-gray-800 mb-3">Agendamento suspenso</h2>
    <p class="text-gray-500 leading-relaxed">
      Você teve <strong><?= $faltas ?> falta<?= $faltas > 1 ? 's' : '' ?></strong> registrada<?= $faltas > 1 ? 's' : '' ?>.
      Conforme as diretrizes do Nupics Caicó, após 2 faltas o agendamento é suspenso para dar espaço a outros pacientes.
      Entre em contato com a coordenação para regularizar sua situação.
    </p>
    <a href="dashboard.php"
       class="inline-block mt-6 px-8 py-3 text-white font-bold rounded-full transition-opacity hover:opacity-90"
       style="background:linear-gradient(135deg,#4e0078,#b7004d)">
      Voltar ao início
    </a>
  </div>

  <?php elseif ($passo === 1): ?>
  <!-- ══ PASSO 1: Escolher dia e horário ══ -->

  <div class="mb-10">
    <h1 class="text-4xl md:text-5xl font-extrabold headline text-purple-900 mb-3">Agendar sua Sessão</h1>
    <p class="text-gray-500 max-w-2xl text-base leading-relaxed">
      Escolha o momento ideal para sua jornada de bem-estar. Após escolher o horário,
      suas próximas 4 sessões serão agendadas automaticamente, uma por semana.
    </p>
  </div>

  <!-- Stepper -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-12">
    <div class="flex items-center gap-4 p-5 rounded-2xl border" style="background:rgba(78,0,120,.05);border-color:rgba(78,0,120,.15)">
      <div class="w-10 h-10 rounded-full flex items-center justify-center text-white font-bold flex-shrink-0"
           style="background:#4e0078">1</div>
      <div>
        <p class="text-[10px] font-bold uppercase tracking-widest text-purple-500">Passo atual</p>
        <h3 class="font-bold text-gray-800">Dia e horário</h3>
      </div>
    </div>
    <div class="flex items-center gap-4 p-5 rounded-2xl border border-gray-100 bg-white/60">
      <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold flex-shrink-0 text-gray-400 bg-gray-100">2</div>
      <div>
        <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Próximo</p>
        <h3 class="font-bold text-gray-400">Escolher data</h3>
      </div>
    </div>
    <div class="flex items-center gap-4 p-5 rounded-2xl border border-gray-100 bg-white/60">
      <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold flex-shrink-0 text-gray-400 bg-gray-100">3</div>
      <div>
        <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Finalização</p>
        <h3 class="font-bold text-gray-400">Confirmação</h3>
      </div>
    </div>
  </div>

  <?php if ($erro): ?>
  <div class="mb-6 bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-3 text-sm font-medium">
    <?= htmlspecialchars($erro) ?>
  </div>
  <?php endif; ?>

  <!-- Grade semanal bento -->
  <form method="POST" action="agendar.php" id="form-agendar">
    <input type="hidden" name="horario_id" id="horario_id_input" value="">

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-5 mb-10">
      <?php for ($d = 1; $d <= 5; $d++):
        $hs = $por_dia[$d];
        $tem = !empty($hs);
      ?>
      <div class="rounded-2xl p-5 flex flex-col border transition-all duration-200 <?= $tem ? 'bg-white/70 border-white/60 shadow-sm backdrop-blur-sm' : 'bg-white/40 border-gray-100' ?>"
           id="dia-card-<?= $d ?>">
        <div class="flex justify-between items-center mb-5">
          <h4 class="text-lg font-extrabold headline <?= $tem ? 'text-purple-900' : 'text-gray-400' ?>">
            <?= $dias_pt[$d] ?>
          </h4>
          <span class="material-symbols-outlined <?= $tem ? 'text-pink-400' : 'text-gray-300' ?>"
                style="font-size:20px">
            <?= $tem ? 'event_available' : 'calendar_today' ?>
          </span>
        </div>

        <?php if (!$tem): ?>
        <div class="flex-1 flex flex-col items-center justify-center py-8 text-center">
          <span class="material-symbols-outlined text-gray-300 mb-2" style="font-size:36px">event_busy</span>
          <p class="text-xs text-gray-400 font-medium">Sem vagas disponíveis</p>
        </div>
        <?php else: ?>
        <div class="space-y-3 flex-1">
          <?php foreach ($hs as $h):
            $hi = substr($h['hora_inicio'],0,5);
            $hf = date('H:i', strtotime($h['hora_inicio']) + $h['duracao_minutos']*60);
            // Vagas na próxima data disponível
            $proxima_livre = 0;
            foreach ($h['opcoes'] as $op) {
                if ($op['livres'] > 0) { $proxima_livre = $op['livres']; break; }
            }
          ?>
          <button type="button"
                  class="slot-btn w-full text-left p-4 rounded-2xl border border-gray-200 hover:border-purple-400 hover:bg-purple-50/50 transition-all"
                  data-hid="<?= $h['id'] ?>"
                  data-dia="<?= $d ?>"
                  onclick="selecionarSlot(this)">
            <div class="flex justify-between items-center">
              <span class="font-bold text-gray-800"><?= $hi ?> – <?= $hf ?></span>
              <div class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-emerald-500 flex-shrink-0"></span>
                <span class="text-[10px] font-extrabold text-emerald-600 uppercase tracking-wider">
                  <?= $proxima_livre ?> vaga<?= $proxima_livre !== 1 ? 's' : '' ?>
                </span>
              </div>
            </div>
          </button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endfor; ?>
    </div>

    <!-- Action bar (aparece ao selecionar) -->
    <div id="action-bar"
         class="hidden rounded-2xl p-8 md:p-10 flex flex-col md:flex-row items-center justify-between gap-6 text-white relative overflow-hidden"
         style="background:linear-gradient(135deg,#4e0078,#b7004d)">
      <div class="relative z-10">
        <h3 class="text-xl font-extrabold headline mb-1">Seleção realizada</h3>
        <p class="text-purple-200 font-medium max-w-md" id="action-texto">
          Você escolheu um horário. Clique para escolher a data de início.
        </p>
      </div>
      <button type="submit"
              class="relative z-10 flex items-center gap-3 px-10 py-4 rounded-full bg-white text-purple-900 font-extrabold text-base hover:scale-105 active:scale-95 transition-transform shadow-2xl whitespace-nowrap">
        Confirmar Escolha
        <span class="material-symbols-outlined">arrow_forward</span>
      </button>
    </div>
  </form>

  <?php elseif ($passo === 2 && $horario_sel): ?>
  <!-- ══ PASSO 2: Escolher a data de início ══ -->

  <div class="mb-10">
    <h1 class="text-4xl font-extrabold headline text-purple-900 mb-3">Escolha a data de início</h1>
    <p class="text-gray-500 text-base leading-relaxed max-w-xl">
      A partir da data escolhida, suas próximas sessões serão agendadas automaticamente
      a cada 7 dias, sempre no mesmo horário.
    </p>
  </div>

  <!-- Stepper -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-10">
    <div class="flex items-center gap-4 p-5 rounded-2xl bg-green-50 border border-green-200">
      <div class="w-10 h-10 rounded-full bg-green-500 flex items-center justify-center text-white font-bold flex-shrink-0">✓</div>
      <div><p class="text-[10px] font-bold uppercase tracking-widest text-green-600">Concluído</p>
      <h3 class="font-bold text-gray-800">Dia e horário</h3></div>
    </div>
    <div class="flex items-center gap-4 p-5 rounded-2xl border" style="background:rgba(78,0,120,.05);border-color:rgba(78,0,120,.15)">
      <div class="w-10 h-10 rounded-full flex items-center justify-center text-white font-bold flex-shrink-0" style="background:#4e0078">2</div>
      <div><p class="text-[10px] font-bold uppercase tracking-widest text-purple-500">Passo atual</p>
      <h3 class="font-bold text-gray-800">Escolher data</h3></div>
    </div>
    <div class="flex items-center gap-4 p-5 rounded-2xl border border-gray-100 bg-white/60">
      <div class="w-10 h-10 rounded-full bg-gray-100 text-gray-400 flex items-center justify-center font-bold flex-shrink-0">3</div>
      <div><p class="text-[10px] font-bold uppercase tracking-widest text-gray-400">Próximo</p>
      <h3 class="font-bold text-gray-400">Confirmação</h3></div>
    </div>
  </div>

  <!-- Resumo do horário -->
  <?php
  $hi_s = substr($horario_sel['hora_inicio'],0,5);
  $hf_s = date('H:i', strtotime($horario_sel['hora_inicio']) + $horario_sel['duracao_minutos']*60);
  ?>
  <div class="inline-flex items-center gap-3 px-5 py-3 rounded-2xl mb-8 text-sm font-bold"
       style="background:rgba(78,0,120,.08);color:#4e0078">
    <span class="material-symbols-outlined" style="font-size:18px">schedule</span>
    <?= $dias_pt[$horario_sel['dia_semana']] ?> · <?= $hi_s ?> – <?= $hf_s ?> · <?= $horario_sel['duracao_minutos'] ?>min
  </div>

  <?php if ($erro): ?>
  <div class="mb-6 bg-red-50 border border-red-200 text-red-700 rounded-2xl px-5 py-3 text-sm font-medium">
    <?= htmlspecialchars($erro) ?>
  </div>
  <?php endif; ?>

  <form method="POST" action="agendar.php">
    <input type="hidden" name="horario_id" value="<?= $horario_sel['id'] ?>">

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8 max-w-2xl">
      <?php foreach ($horario_sel['opcoes'] as $op):
        if ($op['livres'] <= 0) continue;
        $dt       = new DateTime($op['data']);
        $dia_nome = $dias_pt[(int)$dt->format('N')];
        $data_fmt = $dia_nome . ', ' . $dt->format('d/m/Y');

        // Preview das 4 semanas a partir dessa data
        $preview = [];
        for ($s = 0; $s < 4; $s++) {
            $dt2 = clone $dt;
            $dt2->modify('+' . ($s * 7) . ' days');
            $preview[] = $dt2->format('d/m');
        }
      ?>
      <label class="cursor-pointer group">
        <input type="radio" name="data_escolhida" value="<?= $op['data'] ?>" required class="hidden peer">
        <div class="p-5 rounded-2xl border-2 border-gray-200 bg-white/70 transition-all
                    peer-checked:border-purple-700 peer-checked:bg-purple-50/60
                    group-hover:border-purple-300 group-hover:shadow-md">
          <div class="flex justify-between items-start mb-3">
            <div>
              <div class="font-extrabold text-gray-800 headline"><?= $data_fmt ?></div>
              <div class="flex items-center gap-1.5 mt-1">
                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                <span class="text-xs font-bold text-emerald-600">
                  <?= $op['livres'] ?>/<?= $op['total'] ?> vagas livres
                </span>
              </div>
            </div>
            <div class="w-6 h-6 rounded-full border-2 border-gray-300 peer-checked:border-purple-700 flex items-center justify-center
                        group-[input:checked+div]:border-purple-700 transition-all" id="radio-circle-<?= $op['data'] ?>">
              <div class="w-3 h-3 rounded-full bg-purple-700 hidden" id="radio-dot-<?= $op['data'] ?>"></div>
            </div>
          </div>
          <!-- Preview das 4 sessões -->
          <div class="border-t border-gray-100 pt-3 mt-1">
            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-2">
              Suas 4 sessões serão em:
            </p>
            <div class="flex flex-wrap gap-1.5">
              <?php foreach ($preview as $idx => $prev): ?>
              <span class="text-[10px] font-bold px-2 py-1 rounded-full"
                    style="background:<?= $idx===0?'#4e0078':'rgba(78,0,120,.08)' ?>;color:<?= $idx===0?'white':'#4e0078' ?>">
                <?= ($idx===0?'1ª ':($idx===1?'2ª ':($idx===2?'3ª ':'4ª '))) ?><?= $prev ?>
              </span>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </label>
      <?php endforeach; ?>
    </div>

    <div class="flex gap-4 flex-wrap">
      <a href="agendar.php"
         class="px-8 py-3 border-2 border-gray-200 rounded-full text-gray-600 font-bold hover:border-gray-300 transition-colors">
        ← Voltar
      </a>
      <button type="submit"
              class="flex items-center gap-2 px-10 py-3 rounded-full text-white font-bold transition-opacity hover:opacity-90"
              style="background:linear-gradient(135deg,#4e0078,#b7004d)">
        Confirmar agendamento
        <span class="material-symbols-outlined">arrow_forward</span>
      </button>
    </div>
  </form>

  <?php elseif ($passo === 3 && $confirmacao): ?>
  <!-- ══ PASSO 3: Confirmação ══ -->

  <?php
  $dt_conf = new DateTime($confirmacao['data']);
  $hi_c    = substr($confirmacao['hora_inicio'],0,5);
  $hf_c    = date('H:i', strtotime($confirmacao['hora_inicio']) + $confirmacao['duracao_minutos']*60);
  ?>
  <div class="max-w-xl mx-auto text-center mt-10">

    <div class="w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-6 text-3xl"
         style="background:linear-gradient(135deg,#4e0078,#b7004d)">
      ✓
    </div>
    <h1 class="text-3xl font-extrabold headline text-purple-900 mb-2">Agendamento confirmado!</h1>
    <p class="text-gray-500 mb-8">Suas sessões foram agendadas automaticamente a cada semana.</p>

    <div class="bg-white/80 rounded-2xl p-6 text-left space-y-3 mb-8 border border-purple-100 shadow-sm">
      <div class="flex justify-between py-2 border-b border-gray-50 text-sm">
        <span class="text-gray-400">Dia da semana</span>
        <span class="font-bold text-gray-800"><?= $dias_pt[(int)$dt_conf->format('N')] ?></span>
      </div>
      <div class="flex justify-between py-2 border-b border-gray-50 text-sm">
        <span class="text-gray-400">Primeira sessão</span>
        <span class="font-bold text-gray-800"><?= $dt_conf->format('d/m/Y') ?></span>
      </div>
      <div class="flex justify-between py-2 border-b border-gray-50 text-sm">
        <span class="text-gray-400">Horário</span>
        <span class="font-bold text-gray-800"><?= $hi_c ?> – <?= $hf_c ?></span>
      </div>
      <div class="flex justify-between py-2 border-b border-gray-50 text-sm">
        <span class="text-gray-400">Duração</span>
        <span class="font-bold text-gray-800"><?= $confirmacao['duracao_minutos'] ?> minutos</span>
      </div>
      <div class="flex justify-between py-2 border-b border-gray-50 text-sm">
        <span class="text-gray-400">Sessão</span>
        <span class="font-bold text-gray-800">Nº <?= $confirmacao['numero_sessao'] ?> do ciclo</span>
      </div>
      <div class="flex justify-between py-2 text-sm">
        <span class="text-gray-400">Periodicidade</span>
        <span class="font-bold text-purple-700">Semanal — toda semana no mesmo horário</span>
      </div>
    </div>

    <!-- Preview das sessões do ciclo -->
    <div class="bg-purple-50/60 rounded-2xl p-5 mb-8 text-left">
      <p class="text-xs font-extrabold uppercase tracking-widest text-purple-400 mb-3">Suas sessões agendadas</p>
      <div class="space-y-2">
        <?php
        $dt_preview = clone $dt_conf;
        for ($s = 0; $s < 4; $s++):
          if ($s > 0) $dt_preview->modify('+7 days');
        ?>
        <div class="flex items-center gap-3">
          <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
               style="background:<?= $s===0?'#4e0078':'rgba(78,0,120,.3)' ?>">
            <?= $s+1 ?>
          </div>
          <span class="text-sm font-semibold text-gray-700">
            <?= $dias_pt[(int)$dt_preview->format('N')] ?>, <?= $dt_preview->format('d/m/Y') ?> às <?= $hi_c ?>
          </span>
          <?php if ($s===0): ?>
          <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-purple-100 text-purple-700">Hoje</span>
          <?php endif; ?>
        </div>
        <?php endfor; ?>
      </div>
    </div>

    <div class="flex gap-3 justify-center flex-wrap">
      <a href="dashboard.php"
         class="px-8 py-3 rounded-full text-white font-bold transition-opacity hover:opacity-90"
         style="background:linear-gradient(135deg,#4e0078,#b7004d)">
        Ir para meu painel
      </a>
      <a href="agendar.php"
         class="px-8 py-3 rounded-full border-2 border-purple-200 text-purple-700 font-bold hover:bg-purple-50 transition-colors">
        Novo agendamento
      </a>
    </div>
  </div>
  <?php endif; ?>

</main>

<!-- FOOTER -->
<footer class="mt-16 py-10 px-8 border-t border-purple-100/40" style="background:rgba(247,234,248,.5)">
  <div class="max-w-7xl mx-auto flex flex-col md:flex-row justify-between items-center gap-4 text-sm">
    <div>
      <div class="font-extrabold headline text-purple-900">NUPICS</div>
      <p class="text-gray-400 mt-1">© <?= date('Y') ?> NUPICS — Núcleo de Práticas Integrativas e Complementares em Saúde.</p>
    </div>
    <div class="flex gap-6">
      <a href="#" class="text-gray-500 hover:text-purple-700 transition-colors">Privacidade</a>
      <a href="#" class="text-gray-500 hover:text-purple-700 transition-colors">Termos de Uso</a>
      <a href="#" class="text-gray-500 hover:text-purple-700 transition-colors">Contato</a>
    </div>
  </div>
</footer>

<script>
var slotSelecionado = null;
var diaSelecionado  = null;

function selecionarSlot(btn) {
  // Remove seleção anterior
  document.querySelectorAll('.slot-btn').forEach(function(b) {
    b.classList.remove('slot-sel');
  });
  document.querySelectorAll('[id^="dia-card-"]').forEach(function(c) {
    c.classList.remove('dia-sel');
  });

  btn.classList.add('slot-sel');
  var dia = btn.getAttribute('data-dia');
  document.getElementById('dia-card-' + dia).classList.add('dia-sel');

  var hid  = btn.getAttribute('data-hid');
  var hora = btn.querySelector('.font-bold').textContent.trim();
  var dias = ['','Segunda','Terça','Quarta','Quinta','Sexta'];

  document.getElementById('horario_id_input').value = hid;
  document.getElementById('action-texto').textContent =
    'Você escolheu ' + dias[dia] + '-feira, às ' + hora.split('–')[0].trim() +
    '. Clique para ver as datas disponíveis.';
  document.getElementById('action-bar').classList.remove('hidden');
  document.getElementById('action-bar').classList.add('flex');

  slotSelecionado = hid;
  diaSelecionado  = dia;
}
</script>

</body>
</html>