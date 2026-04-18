<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'paciente') {
    header('Location: ../index.php'); exit;
}

$uid = (int)$_SESSION['usuario_id'];
$stmt = $pdo->prepare('SELECT id FROM pacientes WHERE usuario_id = ?');
$stmt->execute([$uid]);
$paciente = $stmt->fetch();
if (!$paciente) { header('Location: ../index.php'); exit; }
$pid = $paciente['id'];

// ── Ciclo ativo ──
$stmt = $pdo->prepare('
    SELECT c.id, c.total_sessoes,
           COUNT(CASE WHEN a.status = "agendado" AND a.data >= CURDATE() THEN 1 END) AS proximas
    FROM ciclos c
    LEFT JOIN agendamentos a ON a.ciclo_id = c.id
    WHERE c.paciente_id = ? AND c.status = "ativo"
    GROUP BY c.id LIMIT 1
');
$stmt->execute([$pid]);
$ciclo_existente = $stmt->fetch();
$bloqueado = $ciclo_existente && $ciclo_existente['proximas'] > 0;

$sucesso = '';
$erro    = '';
$datas_confirmadas = [];

// ── Processar agendamento ──
if (!$bloqueado && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['horario_id'])) {
    $horario_id = (int)$_POST['horario_id'];
    if (!$horario_id) {
        $erro = 'Selecione um horário.';
    } else {
        $hcheck = $pdo->prepare('SELECT * FROM horarios WHERE id = ? AND ativo = 1');
        $hcheck->execute([$horario_id]);
        $horario = $hcheck->fetch();

        if (!$horario) {
            $erro = 'Horário inválido.';
        } else {
            $dia_semana = $horario['dia_semana'];
            $hoje = new DateTime('today');
            $hoje->modify('+1 day');
            $data_inicio = null;
            for ($i = 0; $i < 30; $i++) {
                if ($hoje->format('N') == $dia_semana) { $data_inicio = clone $hoje; break; }
                $hoje->modify('+1 day');
            }
            if (!$data_inicio) { $erro = 'Sem datas disponíveis.'; }
            else {
                $vq = $pdo->prepare('SELECT COUNT(*) FROM agendamentos WHERE horario_id=? AND data=? AND status="agendado"');
                $vq->execute([$horario_id, $data_inicio->format('Y-m-d')]);
                if ($vq->fetchColumn() >= $horario['vagas_total']) {
                    $data_inicio->modify('+7 days');
                    $vq->execute([$horario_id, $data_inicio->format('Y-m-d')]);
                    if ($vq->fetchColumn() >= $horario['vagas_total']) { $erro = 'Sem vagas. Escolha outro horário.'; $data_inicio = null; }
                }
                if ($data_inicio && !$erro) {
                    $ter = $pdo->prepare('SELECT terapeuta_id FROM horario_terapeutas WHERE horario_id=? LIMIT 1');
                    $ter->execute([$horario_id]);
                    $terapeuta_id = $ter->fetchColumn();
                    $pdo->beginTransaction();
                    $pdo->prepare('INSERT INTO ciclos(paciente_id,terapeuta_id,total_sessoes,status)VALUES(?,?,4,"ativo")')->execute([$pid,$terapeuta_id]);
                    $ciclo_id = $pdo->lastInsertId();
                    $dc = clone $data_inicio;
                    for ($i = 1; $i <= 4; $i++) {
                        $pdo->prepare('INSERT INTO agendamentos(ciclo_id,horario_id,data,numero_sessao,terapeuta_id,status)VALUES(?,?,?,?,?,"agendado")')->execute([$ciclo_id,$horario_id,$dc->format('Y-m-d'),$i,$terapeuta_id]);
                        $datas_confirmadas[] = $dc->format('Y-m-d');
                        $dc->modify('+7 days');
                    }
                    $pdo->commit();
                    $sucesso = 'Agendamento confirmado!';
                    $bloqueado = true;
                    $_SESSION['agend_datas'] = $datas_confirmadas;
                }
            }
        }
    }
}
if ($sucesso) { $datas_confirmadas = $_SESSION['agend_datas'] ?? []; unset($_SESSION['agend_datas']); }

// ── Horários por dia ──
$horarios = $pdo->query('
    SELECT h.id, h.dia_semana, h.hora_inicio, h.duracao_minutos, h.vagas_total,
           GROUP_CONCAT(u.nome SEPARATOR ", ") AS terapeutas
    FROM horarios h
    JOIN horario_terapeutas ht ON ht.horario_id = h.id
    JOIN terapeutas t ON t.id = ht.terapeuta_id AND t.ativo = 1
    JOIN usuarios u ON u.id = t.usuario_id
    WHERE h.ativo = 1
    GROUP BY h.id ORDER BY h.dia_semana, h.hora_inicio
')->fetchAll();

$dias_info = [
    '1'=>['nome'=>'Segunda','abrev'=>'Seg'],
    '2'=>['nome'=>'Terça',  'abrev'=>'Ter'],
    '3'=>['nome'=>'Quarta', 'abrev'=>'Qua'],
    '4'=>['nome'=>'Quinta', 'abrev'=>'Qui'],
    '5'=>['nome'=>'Sexta',  'abrev'=>'Sex'],
];

$por_dia = [];
foreach ($horarios as $h) $por_dia[$h['dia_semana']][] = $h;

function proxima_data_dia($dia) {
    $hoje = new DateTime('today');
    $hoje->modify('+1 day');
    for ($i = 0; $i < 10; $i++) {
        if ($hoje->format('N') == $dia) return clone $hoje;
        $hoje->modify('+1 day');
    }
    return null;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Agendar sessão — NUPICS</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <style>
    :root { --p:#4e0078; --s:#b7004d; }
    * { box-sizing:border-box; }
    body { font-family:'DM Sans',sans-serif; background:#faf7fc; margin:0; color:#2d1040; }
    .hl { font-family:'Plus Jakarta Sans',sans-serif; }
    .grad { background:linear-gradient(135deg,#4e0078,#b7004d); }
    .grad-txt { background:linear-gradient(135deg,#4e0078,#b7004d); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
    .material-symbols-outlined { font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24; }

    /* TOPNAV */
    .topbar { position:sticky; top:0; z-index:50; background:rgba(250,247,252,.93); backdrop-filter:blur(14px); border-bottom:1px solid rgba(78,0,120,.08); padding:0 20px; height:54px; display:flex; align-items:center; justify-content:space-between; }

    /* WEEK STRIP */
    .week-strip { display:flex; background:white; border-radius:16px; border:1px solid rgba(78,0,120,.1); overflow:hidden; box-shadow:0 2px 14px rgba(78,0,120,.06); }
    .day-pill { flex:1; padding:12px 6px; display:flex; flex-direction:column; align-items:center; gap:2px; cursor:pointer; border:none; background:transparent; border-right:1px solid rgba(78,0,120,.07); transition:background .15s; font-family:'DM Sans',sans-serif; }
    .day-pill:last-child { border-right:none; }
    .day-pill:hover:not(.empty) { background:#f5eeff; }
    .day-pill.active { background:linear-gradient(135deg,#4e0078,#b7004d); }
    .day-pill .abrev { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#9d7fb5; }
    .day-pill.active .abrev { color:rgba(255,255,255,.7); }
    .day-pill .dnum { font-size:20px; font-weight:800; font-family:'Plus Jakarta Sans',sans-serif; color:#2d1040; line-height:1.1; }
    .day-pill.active .dnum { color:white; }
    .day-pill .dcount { font-size:9px; font-weight:700; background:#f3eaff; color:#7c3aed; padding:1px 6px; border-radius:99px; margin-top:3px; }
    .day-pill.active .dcount { background:rgba(255,255,255,.2); color:white; }
    .day-pill.empty { opacity:.38; cursor:not-allowed; }

    /* SLOT */
    .slot-card { background:white; border-radius:14px; border:1.5px solid rgba(78,0,120,.08); padding:16px 18px; cursor:pointer; transition:all .18s; display:flex; align-items:flex-start; gap:14px; }
    .slot-card:hover { border-color:#7c3aed; box-shadow:0 0 0 3px rgba(124,58,237,.07); transform:translateY(-1px); }
    .slot-card.selected { border-color:#4e0078; background:linear-gradient(135deg,rgba(78,0,120,.03),rgba(183,0,77,.03)); box-shadow:0 0 0 3px rgba(78,0,120,.1); }
    .slot-time { font-size:20px; font-weight:800; font-family:'Plus Jakarta Sans',sans-serif; color:#4e0078; min-width:96px; line-height:1; padding-top:2px; flex-shrink:0; }
    .slot-time span { font-size:13px; font-weight:600; color:#9d7fb5; }
    .slot-divider { width:1px; background:rgba(78,0,120,.1); align-self:stretch; flex-shrink:0; }
    .slot-meta { flex:1; min-width:0; }
    .slot-ter  { font-size:14px; font-weight:600; color:#2d1040; }
    .slot-sub  { font-size:11px; color:#9d7fb5; margin-top:2px; }
    .slot-date { font-size:12px; font-weight:700; color:#1D9E75; margin-top:5px; display:flex; align-items:center; gap:3px; }
    .vaga-badge { flex-shrink:0; padding:4px 10px; border-radius:99px; font-size:11px; font-weight:700; background:#f3eaff; color:#4e0078; align-self:flex-start; margin-top:2px; }

    /* PREVIEW */
    .preview-box { max-height:0; overflow:hidden; transition:max-height .3s ease, opacity .25s; opacity:0; }
    .preview-box.open { max-height:220px; opacity:1; }
    .sdot { display:flex; align-items:center; gap:9px; padding:6px 0; font-size:13px; }
    .snum { width:22px; height:22px; border-radius:50%; background:linear-gradient(135deg,#4e0078,#b7004d); color:white; font-size:9px; font-weight:800; display:flex; align-items:center; justify-content:center; flex-shrink:0; }

    /* BOTÃO CONFIRMAR */
    .btn-confirm { width:100%; padding:15px; background:linear-gradient(135deg,#4e0078,#b7004d); color:white; font-size:15px; font-weight:800; border:none; border-radius:14px; cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif; transition:opacity .15s; display:none; margin-top:16px; }
    .btn-confirm.show { display:block; }
    .btn-confirm:hover { opacity:.91; }

    /* BLOQUEIO */
    .bloqueio-card { background:linear-gradient(135deg,#4e0078,#b7004d); border-radius:20px; padding:22px 24px; color:white; }

    /* REGRAS */
    .ri { display:flex; align-items:flex-start; gap:11px; padding:13px 0; border-bottom:1px solid rgba(78,0,120,.06); font-size:13px; color:#4d3060; line-height:1.6; }
    .ri:last-child { border-bottom:none; }
    .ri-ico { width:28px; height:28px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0; margin-top:1px; }

    /* PAINEL DIA */
    .dia-painel { display:none; }
    .dia-painel.active { display:block; }

    /* FOOTER */
    footer { background:#f3eaff; padding:20px 24px; margin-top:40px; }
  </style>
</head>
<body>

<!-- NAV -->
<nav class="topbar">
  <span class="text-base font-extrabold hl grad-txt">NUPICS</span>
  <div class="flex items-center gap-3">
    <a href="dashboard.php" class="flex items-center gap-1 text-sm font-semibold" style="color:#4e0078">
      <span class="material-symbols-outlined" style="font-size:18px">arrow_back</span>
      Voltar ao início
    </a>
    <a href="../api/logout.php" class="text-xs text-gray-400 border border-gray-200 rounded-full px-3 py-1">Sair</a>
  </div>
</nav>

<main class="max-w-2xl mx-auto px-4 py-7 space-y-5">

  <!-- TÍTULO -->
  <div>
    <h1 class="text-2xl font-extrabold hl grad-txt">Agendar sessão</h1>
    <p class="text-sm mt-1" style="color:#9d7fb5">Escolha o dia e horário na grade semanal do NUPICS.</p>
  </div>

  <?php if ($erro): ?>
  <div style="background:#FBEAF0;color:#72243E;border:1px solid #f2b8cb;border-radius:12px;padding:12px 16px;font-size:13px;font-weight:600">
    ⚠ <?= htmlspecialchars($erro) ?>
  </div>
  <?php endif; ?>

  <?php if ($sucesso): ?>
  <!-- SUCESSO -->
  <div style="background:white;border-radius:18px;border:1.5px solid #a3d9c5;padding:18px 22px">
    <div class="flex items-center gap-3 mb-3">
      <div class="w-10 h-10 rounded-full text-white flex items-center justify-center font-bold" style="background:linear-gradient(135deg,#1D9E75,#0F6E56)">✓</div>
      <div>
        <p class="font-extrabold hl" style="color:#085041">Agendamento confirmado!</p>
        <p class="text-xs" style="color:#5a8a77">Suas 4 sessões foram criadas automaticamente.</p>
      </div>
    </div>
    <?php if (!empty($datas_confirmadas)):
      $dspt=['Mon'=>'Segunda','Tue'=>'Terça','Wed'=>'Quarta','Thu'=>'Quinta','Fri'=>'Sexta'];
    ?>
    <div style="padding-top:8px;border-top:1px solid #e0f5ec">
      <?php foreach ($datas_confirmadas as $i=>$d):
        $dt=new DateTime($d); $dow=$dspt[$dt->format('D')]??'';
      ?>
      <div class="sdot">
        <div class="snum"><?= $i+1 ?></div>
        <span style="font-weight:600;color:#2d1040"><?= $dow ?></span>
        <span style="color:#9d7fb5"><?= $dt->format('d/m/Y') ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="bloqueio-card">
    <p class="font-extrabold hl text-lg mb-2">🔒 Agendamento bloqueado</p>
    <p style="font-size:13px;opacity:.85">Você já possui sessões ativas. Não é possível criar um novo agendamento enquanto houver sessões pendentes.</p>
    <p style="font-size:11px;opacity:.6;margin-top:8px">Quando seu ciclo for concluído, você poderá agendar novamente.</p>
  </div>
  <a href="dashboard.php" style="display:block;text-align:center;padding:14px;background:white;border:2px solid #4e0078;color:#4e0078;font-weight:800;border-radius:14px;font-family:'Plus Jakarta Sans',sans-serif;">
    Ver minhas sessões
  </a>

  <?php elseif ($bloqueado): ?>
  <!-- BLOQUEADO -->
  <div class="bloqueio-card">
    <p class="font-extrabold hl text-lg mb-2">🔒 Agendamento bloqueado</p>
    <p style="font-size:13px;opacity:.85">Você já possui um ciclo de atendimento ativo com sessões agendadas. Não é possível criar um novo agendamento enquanto houver sessões pendentes.</p>
    <p style="font-size:11px;opacity:.6;margin-top:10px">Quando seu ciclo atual for concluído, você poderá fazer um novo agendamento.</p>
  </div>
  <a href="dashboard.php" style="display:block;text-align:center;padding:14px;background:white;border:2px solid #4e0078;color:#4e0078;font-weight:800;border-radius:14px;font-family:'Plus Jakarta Sans',sans-serif;">
    Ver minhas sessões
  </a>

  <?php else: ?>
  <!-- PODE AGENDAR -->

  <?php if (empty($por_dia)): ?>
  <div style="text-align:center;padding:48px 16px;color:#9d7fb5;font-size:14px">
    Nenhum horário disponível no momento.
  </div>
  <?php else: ?>

  <!-- LINHA SEMANAL -->
  <div class="week-strip">
    <?php foreach ($dias_info as $num => $info):
      $prx = proxima_data_dia($num);
      $tem = isset($por_dia[$num]);
      $qtd = $tem ? count($por_dia[$num]) : 0;
    ?>
    <button class="day-pill <?= !$tem?'empty':'' ?>"
            id="pill-<?= $num ?>"
            onclick="<?= $tem?"setDia('$num')":'' ?>"
            type="button">
      <span class="abrev"><?= $info['abrev'] ?></span>
      <span class="dnum"><?= $prx ? $prx->format('d') : '—' ?></span>
      <span class="dcount"><?= $tem ? "$qtd hor." : '—' ?></span>
    </button>
    <?php endforeach; ?>
  </div>

  <!-- FORM -->
  <form method="POST" id="form-agend">
    <input type="hidden" name="horario_id" id="input-horario" value="">

    <?php foreach ($dias_info as $num => $info):
      if (!isset($por_dia[$num])) continue;
      $prx_dia = proxima_data_dia($num);
    ?>
    <div class="dia-painel space-y-3" id="painel-<?= $num ?>">
      <p style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9d7fb5;margin-bottom:2px">
        <?= $info['nome'] ?> · <?= $prx_dia ? $prx_dia->format('d/m/Y') : '' ?>
      </p>

      <?php foreach ($por_dia[$num] as $h):
        $hi = substr($h['hora_inicio'],0,5);
        $hf = date('H:i',strtotime($h['hora_inicio'])+$h['duracao_minutos']*60);
        $prx = proxima_data_dia($num);
        $dp_arr = [];
        if ($prx) {
            $dp = clone $prx;
            for ($i=0;$i<4;$i++) { $dp_arr[]=$dp->format('Y-m-d'); $dp->modify('+7 days'); }
        }
      ?>
      <div class="slot-card" id="slot-<?= $h['id'] ?>"
           onclick="selecionarSlot(<?= $h['id'] ?>, <?= json_encode($dp_arr) ?>)">

        <div class="slot-time"><?= $hi ?><span> – <?= $hf ?></span></div>
        <div class="slot-divider"></div>
        <div class="slot-meta">
          <div class="slot-ter"><?= htmlspecialchars($h['terapeutas']) ?></div>
          <div class="slot-sub"><?= $h['duracao_minutos'] ?> min · <?= $h['vagas_total'] ?> vaga<?= $h['vagas_total']!=1?'s':'' ?></div>
          <?php if ($prx): ?>
          <div class="slot-date">
            <span class="material-symbols-outlined" style="font-size:13px">event</span>
            Próximo: <?= $prx->format('d/m') ?>
          </div>
          <?php endif; ?>

          <!-- Preview -->
          <div class="preview-box mt-3" id="preview-<?= $h['id'] ?>">
            <p style="font-size:11px;font-weight:700;color:#4e0078;margin-bottom:6px">✓ Suas 4 sessões serão:</p>
            <div id="preview-list-<?= $h['id'] ?>"></div>
          </div>
        </div>

        <div class="vaga-badge"><?= $h['vagas_total'] ?> vaga<?= $h['vagas_total']!=1?'s':'' ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <button type="submit" class="btn-confirm" id="btn-confirmar">
      Confirmar agendamento →
    </button>
  </form>

  <?php endif; ?>
  <?php endif; // fim não bloqueado ?>

  <!-- COMO FUNCIONA -->
  <div style="background:white;border-radius:18px;border:1px solid rgba(78,0,120,.08);padding:18px 20px;box-shadow:0 2px 10px rgba(78,0,120,.04)">
    <p class="font-extrabold hl" style="font-size:13px;color:#4e0078;display:flex;align-items:center;gap:5px;margin-bottom:10px">
      <span class="material-symbols-outlined" style="font-size:17px">help_outline</span>
      Como funciona o agendamento
    </p>
    <div class="ri"><div class="ri-ico" style="background:#E1F5EE">📅</div><div><strong>1 ciclo por vez.</strong> Cada paciente realiza um ciclo de 4 sessões semanais. Enquanto houver sessões ativas, não é possível criar um novo agendamento.</div></div>
    <div class="ri"><div class="ri-ico" style="background:#FAEEDA">⚠️</div><div><strong>Justificativa de falta:</strong> Você pode faltar com justificativa <strong>apenas uma vez</strong> por ciclo. Use o botão "Justificar falta" no seu painel com antecedência.</div></div>
    <div class="ri"><div class="ri-ico" style="background:#FBEAF0">❌</div><div><strong>Falta sem aviso = sessão perdida.</strong> Se você faltar sem justificar, a sessão é descartada automaticamente pelo sistema — não depende do terapeuta.</div></div>
    <div class="ri"><div class="ri-ico" style="background:#FBEAF0">🚫</div><div><strong>2 faltas = bloqueio.</strong> Duas faltas injustificadas encerram o ciclo e suspendem seu acesso a novos agendamentos.</div></div>
    <div class="ri"><div class="ri-ico" style="background:#f4e8ff">🤖</div><div><strong>Remoção automática:</strong> A exclusão da agenda é feita pelo próprio sistema — o terapeuta não tem controle sobre isso.</div></div>
  </div>

</main>

<!-- FOOTER -->
<footer>
  <div class="max-w-2xl mx-auto flex flex-col md:flex-row justify-between items-center gap-2">
    <span class="font-extrabold hl" style="font-size:14px;color:#4e0078">NUPICS Caicó — UERN</span>
    <span style="font-size:11px;color:#9d7fb5">Atendimentos integrativos gratuitos para a comunidade · Caicó/RN</span>
    <a href="dashboard.php" style="font-size:12px;font-weight:700;color:#4e0078">← Voltar ao painel</a>
  </div>
</footer>

<script>
var diasDisponiveis = <?= json_encode(array_keys($por_dia)) ?>;
var diasPt = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'];

// Ativa primeiro dia
(function(){ if (diasDisponiveis.length) setDia(diasDisponiveis[0]); })();

function setDia(num) {
  document.querySelectorAll('.dia-painel').forEach(function(p){ p.classList.remove('active'); });
  document.querySelectorAll('.day-pill').forEach(function(p){ p.classList.remove('active'); });
  var pan = document.getElementById('painel-' + num);
  var pil = document.getElementById('pill-'   + num);
  if (pan) pan.classList.add('active');
  if (pil) pil.classList.add('active');
  // reseta slot
  document.querySelectorAll('.slot-card').forEach(function(c){ c.classList.remove('selected'); });
  document.querySelectorAll('.preview-box').forEach(function(p){ p.classList.remove('open'); });
  document.getElementById('input-horario').value = '';
  var btn = document.getElementById('btn-confirmar');
  if (btn) btn.classList.remove('show');
}

function selecionarSlot(hid, datas) {
  document.querySelectorAll('.slot-card').forEach(function(c){ c.classList.remove('selected'); });
  document.querySelectorAll('.preview-box').forEach(function(p){ p.classList.remove('open'); });
  document.getElementById('slot-' + hid).classList.add('selected');
  document.getElementById('input-horario').value = hid;

  var list = document.getElementById('preview-list-' + hid);
  list.innerHTML = '';
  datas.forEach(function(d, i) {
    var dt  = new Date(d + 'T12:00:00');
    var dia = String(dt.getDate()).padStart(2,'0');
    var mes = String(dt.getMonth()+1).padStart(2,'0');
    var ano = dt.getFullYear();
    var dow = diasPt[dt.getDay()];
    var div = document.createElement('div');
    div.className = 'sdot';
    div.innerHTML = '<div class="snum">' + (i+1) + '</div><span style="font-weight:600;color:#2d1040">' + dow + '</span><span style="color:#9d7fb5">' + dia + '/' + mes + '/' + ano + '</span>';
    list.appendChild(div);
  });

  document.getElementById('preview-' + hid).classList.add('open');
  var btn = document.getElementById('btn-confirmar');
  if (btn) btn.classList.add('show');
  setTimeout(function(){ if (btn) btn.scrollIntoView({behavior:'smooth',block:'nearest'}); }, 300);
}
</script>
</body>
</html>