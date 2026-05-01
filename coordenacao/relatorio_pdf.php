<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'coordenador') {
    header('Location: ../index.php'); exit;
}
require_once '../config/db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ── Período ──────────────────────────────────────────────────────────────────
$ini = $_GET['ini'] ?? date('Y-m-01');
$fim = $_GET['fim'] ?? date('Y-m-d');
// Sanitiza
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ini)) $ini = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim)) $fim = date('Y-m-d');
$ini_fmt = date('d/m/Y', strtotime($ini));
$fim_fmt = date('d/m/Y', strtotime($fim));

// ── 1. Sumário geral ─────────────────────────────────────────────────────────
$sumario = $pdo->prepare("SELECT
    (SELECT COUNT(*) FROM sessoes_plantao   WHERE DATE(criado_em) BETWEEN :i AND :f AND status='realizado')   AS plt_realizados,
    (SELECT COUNT(*) FROM sessoes_plantao   WHERE DATE(criado_em) BETWEEN :i2 AND :f2)                        AS plt_total,
    (SELECT COUNT(*) FROM registros_sessao  WHERE data_sessao     BETWEEN :i3 AND :f3 AND status='realizado') AS ciclo_realizados,
    (SELECT COUNT(*) FROM registros_sessao  WHERE data_sessao     BETWEEN :i4 AND :f4 AND status='faltou')    AS faltas,
    (SELECT COUNT(*) FROM registros_sessao  WHERE data_sessao     BETWEEN :i5 AND :f5)                        AS ciclo_total,
    (SELECT COUNT(DISTINCT r.paciente_id)   FROM reservas r WHERE DATE(r.criado_em) BETWEEN :i6 AND :f6)      AS novos_pacientes,
    (SELECT COUNT(*) FROM ciclos            WHERE DATE(criado_em) BETWEEN :i7 AND :f7 AND status='concluido') AS ciclos_concluidos,
    (SELECT COUNT(*) FROM ciclos            WHERE DATE(criado_em) BETWEEN :i8 AND :f8 AND status='cancelado') AS ciclos_cancelados,
    (SELECT COUNT(*) FROM ciclos            WHERE status='ativo')                                              AS ciclos_ativos,
    (SELECT COUNT(*) FROM visitas_externas  WHERE DATE(criado_em) BETWEEN :i9 AND :f9 AND status='realizada') AS visitas_realizadas,
    (SELECT COUNT(*) FROM usuarios          WHERE tipo='paciente')                                             AS total_pacientes,
    (SELECT COUNT(*) FROM terapeutas        WHERE ativo=1)                                                     AS total_terapeutas
");
$sumario->execute([
    ':i'=>$ini,':f'=>$fim,':i2'=>$ini,':f2'=>$fim,':i3'=>$ini,':f3'=>$fim,
    ':i4'=>$ini,':f4'=>$fim,':i5'=>$ini,':f5'=>$fim,':i6'=>$ini,':f6'=>$fim,
    ':i7'=>$ini,':f7'=>$fim,':i8'=>$ini,':f8'=>$fim,
    ':i9'=>$ini,':f9'=>$fim,
]);
$s = $sumario->fetch(PDO::FETCH_ASSOC);

$total_atend   = (int)$s['plt_realizados'] + (int)$s['ciclo_realizados'];
$total_sessoes = (int)$s['plt_total']      + (int)$s['ciclo_total'];
$taxa_freq     = $total_sessoes > 0 ? round(($total_atend / $total_sessoes) * 100, 1) : 0;
$taxa_falta    = $total_sessoes > 0 ? round(((int)$s['faltas'] / $total_sessoes) * 100, 1) : 0;

// ── 2. Atendimentos por terapeuta ────────────────────────────────────────────
$por_terapeuta = $pdo->prepare("
    SELECT u.nome,
           t.especialidade,
           COUNT(DISTINCT sp.id) AS plantao,
           COUNT(DISTINCT CASE WHEN rs.status='realizado' THEN rs.id END) AS ciclo,
           COUNT(DISTINCT CASE WHEN rs.status='faltou'    THEN rs.id END) AS faltas_t
    FROM terapeutas t
    JOIN usuarios u ON t.usuario_id = u.id
    LEFT JOIN sessoes_plantao sp ON sp.terapeuta_id = t.id
        AND DATE(sp.criado_em) BETWEEN :i AND :f AND sp.status='realizado'
    LEFT JOIN slots sl ON sl.terapeuta_id = u.id
    LEFT JOIN reservas r ON r.slot_id = sl.id
    LEFT JOIN ciclos c ON c.reserva_id = r.id
    LEFT JOIN registros_sessao rs ON rs.ciclo_id = c.id
        AND rs.data_sessao BETWEEN :i2 AND :f2
    WHERE t.ativo = 1
    GROUP BY u.id, u.nome, t.especialidade
    ORDER BY (COUNT(DISTINCT sp.id) + COUNT(DISTINCT CASE WHEN rs.status='realizado' THEN rs.id END)) DESC
");
$por_terapeuta->execute([':i'=>$ini,':f'=>$fim,':i2'=>$ini,':f2'=>$fim]);
$terapeutas_lista = $por_terapeuta->fetchAll(PDO::FETCH_ASSOC);

// ── 3. Ciclos no período ─────────────────────────────────────────────────────
$ciclos_lista = $pdo->prepare("
    SELECT c.id, c.status, c.total_sessoes, c.sessoes_realizadas, c.faltas,
           c.criado_em, c.encerrado_em,
           u_pac.nome  AS pac_nome,
           u_ter.nome  AS ter_nome,
           t.especialidade,
           s.dia_semana, s.hora_inicio, s.hora_fim
    FROM ciclos c
    JOIN reservas r      ON c.reserva_id   = r.id
    JOIN usuarios u_pac  ON r.paciente_id  = u_pac.id
    JOIN slots s         ON r.slot_id      = s.id
    JOIN usuarios u_ter  ON s.terapeuta_id = u_ter.id
    JOIN terapeutas t    ON t.usuario_id   = u_ter.id
    WHERE DATE(c.criado_em) BETWEEN ? AND ?
       OR (c.status = 'ativo')
    ORDER BY c.status ASC, c.criado_em DESC
    LIMIT 100
");
$ciclos_lista->execute([$ini, $fim]);
$ciclos = $ciclos_lista->fetchAll(PDO::FETCH_ASSOC);

// ── 4. Visitas externas ──────────────────────────────────────────────────────
$visitas_lista = $pdo->prepare("
    SELECT v.local_nome, v.local_tipo, v.data_sugerida, v.status,
           u.nome AS solicitante,
           vr.total_participantes, vr.data_realizada,
           GROUP_CONCAT(u2.nome SEPARATOR ', ') AS terapeutas_escalados
    FROM visitas_externas v
    JOIN usuarios u ON v.solicitante_id = u.id
    LEFT JOIN visita_registros vr ON vr.visita_id = v.id
    LEFT JOIN visita_terapeutas vt ON vt.visita_id = v.id
    LEFT JOIN usuarios u2 ON vt.terapeuta_id = u2.id
    WHERE DATE(v.criado_em) BETWEEN ? AND ?
    GROUP BY v.id
    ORDER BY v.criado_em DESC
    LIMIT 50
");
$visitas_lista->execute([$ini, $fim]);
$visitas = $visitas_lista->fetchAll(PDO::FETCH_ASSOC);

// ── 5. Novos pacientes ───────────────────────────────────────────────────────
$novos_pac = $pdo->prepare("
    SELECT u.nome, u.email, p.vinculo, p.data_nasc, u.criado_em
    FROM usuarios u
    JOIN pacientes p ON p.usuario_id = u.id
    WHERE u.tipo = 'paciente' AND DATE(u.criado_em) BETWEEN ? AND ?
    ORDER BY u.criado_em DESC
    LIMIT 50
");
$novos_pac->execute([$ini, $fim]);
$pacientes_novos = $novos_pac->fetchAll(PDO::FETCH_ASSOC);

// ── 6. Sugestões respondidas ─────────────────────────────────────────────────
$sugestoes_resp = $pdo->prepare("
    SELECT tipo, COUNT(*) AS qtd
    FROM sugestoes
    WHERE DATE(criado_em) BETWEEN ? AND ?
    GROUP BY tipo
    ORDER BY qtd DESC
");
$sugestoes_resp->execute([$ini, $fim]);
$sugestoes = $sugestoes_resp->fetchAll(PDO::FETCH_ASSOC);

$dias_full = [1=>'Segunda',2=>'Terça',3=>'Quarta',4=>'Quinta',5=>'Sexta',6=>'Sábado',7=>'Domingo'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Relatório NUPICS · <?= $ini_fmt ?> a <?= $fim_fmt ?></title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<style>
/* ── Base ───────────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --primary: #4e0078;
  --secondary: #b7004d;
  --ink: #1a0a24;
  --muted: #6b6078;
  --border: #d8cce0;
  --bg-soft: #f9f4fc;
  --green: #15803d;
  --red: #b91c1c;
  --amber: #b45309;
  --blue: #1d4ed8;
}
body {
  font-family: 'Manrope', sans-serif;
  font-size: 12px;
  color: var(--ink);
  background: #fff;
  line-height: 1.5;
}
h1, h2, h3, h4 { font-family: 'Plus Jakarta Sans', sans-serif; }

/* ── Toolbar (só na tela) ───────────────────────────────────────────── */
.toolbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 100;
  background: white;
  border-bottom: 1px solid var(--border);
  padding: 10px 24px;
  display: flex; align-items: center; justify-content: space-between;
}
.toolbar-title { font-size: 13px; font-weight: 700; color: var(--primary); }
.btn-print {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 20px; border-radius: 99px;
  background: var(--primary); color: white;
  font-size: 12px; font-weight: 700; cursor: pointer; border: none;
  transition: opacity .15s;
}
.btn-print:hover { opacity: .88; }
.btn-back {
  font-size: 12px; font-weight: 600; color: var(--muted); text-decoration: none;
  padding: 8px 14px; border-radius: 99px; border: 1px solid var(--border);
}
.btn-back:hover { background: var(--bg-soft); }

/* ── Página ─────────────────────────────────────────────────────────── */
.page {
  max-width: 900px;
  margin: 0 auto;
  padding: 80px 40px 60px;
}

/* ── Cabeçalho institucional ────────────────────────────────────────── */
.report-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  padding-bottom: 20px;
  border-bottom: 3px solid var(--primary);
  margin-bottom: 28px;
}
.report-logo {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 26px; font-weight: 800;
  background: linear-gradient(135deg, var(--primary), var(--secondary));
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
  background-clip: text;
}
.report-inst { font-size: 11px; color: var(--muted); margin-top: 3px; }
.report-meta { text-align: right; font-size: 11px; color: var(--muted); }
.report-meta strong { display: block; font-size: 15px; font-weight: 800; color: var(--primary); margin-bottom: 3px; }

/* ── Seção ──────────────────────────────────────────────────────────── */
.section { margin-bottom: 30px; }
.section-title {
  font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em;
  color: var(--muted); padding-bottom: 6px;
  border-bottom: 1px solid var(--border);
  margin-bottom: 14px;
  display: flex; align-items: center; gap: 6px;
}
.section-title::before { content: ''; width: 3px; height: 12px; border-radius: 2px; background: var(--primary); flex-shrink: 0; }

/* ── Cards de sumário ───────────────────────────────────────────────── */
.stat-grid {
  display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px;
  margin-bottom: 10px;
}
.stat-grid-3 { grid-template-columns: repeat(3, 1fr); }
.stat-card {
  border: 1px solid var(--border); border-radius: 10px;
  padding: 12px 14px; background: var(--bg-soft);
}
.stat-card .lbl { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); }
.stat-card .val { font-size: 22px; font-weight: 800; color: var(--primary); line-height: 1.2; margin: 3px 0; }
.stat-card .sub { font-size: 9px; color: var(--muted); }
.stat-card.green .val { color: var(--green); }
.stat-card.red   .val { color: var(--red); }
.stat-card.amber .val { color: var(--amber); }
.stat-card.blue  .val { color: var(--blue); }

/* ── Tabelas ────────────────────────────────────────────────────────── */
table { width: 100%; border-collapse: collapse; font-size: 11px; }
thead th {
  background: var(--primary); color: white;
  padding: 7px 10px; text-align: left; font-weight: 700; font-size: 10px;
  text-transform: uppercase; letter-spacing: .05em;
}
thead th:first-child { border-radius: 6px 0 0 0; }
thead th:last-child  { border-radius: 0 6px 0 0; }
tbody tr:nth-child(even) { background: var(--bg-soft); }
tbody td { padding: 7px 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
tbody tr:last-child td { border-bottom: none; }

/* ── Barra de progresso ─────────────────────────────────────────────── */
.bar-wrap { background: #e8dce9; border-radius: 99px; height: 8px; overflow: hidden; min-width: 80px; }
.bar-fill  { height: 100%; border-radius: 99px; background: linear-gradient(90deg, var(--primary), var(--secondary)); }
.bar-fill.green { background: #22c55e; }
.bar-fill.red   { background: #ef4444; }
.bar-fill.amber { background: #f59e0b; }

/* ── Badges ─────────────────────────────────────────────────────────── */
.badge {
  display: inline-block; padding: 2px 7px; border-radius: 99px;
  font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
  white-space: nowrap;
}
.badge-ativo    { background: #ede9fe; color: #5b21b6; }
.badge-concluido{ background: #d1fae5; color: #065f46; }
.badge-cancelado{ background: #fee2e2; color: #7f1d1d; }
.badge-pendente { background: #fef9c3; color: #713f12; }
.badge-realizada{ background: #d1fae5; color: #065f46; }
.badge-aprovada { background: #dbeafe; color: #1e3a8a; }

/* ── Rodapé ─────────────────────────────────────────────────────────── */
.report-footer {
  margin-top: 40px; padding-top: 14px;
  border-top: 1px solid var(--border);
  display: flex; justify-content: space-between; align-items: flex-end;
  font-size: 9px; color: var(--muted);
}

/* ── Alert banner ───────────────────────────────────────────────────── */
.alert-banner {
  background: #fef9c3; border: 1px solid #fde047; border-radius: 8px;
  padding: 10px 14px; margin-bottom: 20px;
  font-size: 11px; color: #713f12;
  display: flex; align-items: center; gap: 8px;
}

/* ── Print overrides ────────────────────────────────────────────────── */
@media print {
  .toolbar { display: none !important; }
  .page { padding: 20px 30px 30px; max-width: 100%; }
  body { font-size: 11px; }
  .stat-card .val { font-size: 18px; }
  table { page-break-inside: auto; }
  tr { page-break-inside: avoid; }
  thead { display: table-header-group; }
  .section { page-break-inside: avoid; }
  .page-break { page-break-before: always; }
  @page {
    size: A4;
    margin: 18mm 14mm;
  }
}
</style>
</head>
<body>

<!-- Toolbar (só na tela) -->
<div class="toolbar">
  <div>
    <div class="toolbar-title">NUPICS · Relatório Institucional</div>
    <div style="font-size:11px;color:var(--muted);"><?= $ini_fmt ?> a <?= $fim_fmt ?></div>
  </div>
  <div style="display:flex;gap:8px;align-items:center;">
    <a href="?aba=relatorios&ini=<?= urlencode($ini) ?>&fim=<?= urlencode($fim) ?>" class="btn-back">← Voltar</a>
    <button class="btn-print" onclick="window.print()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>
      Salvar como PDF
    </button>
  </div>
</div>

<div class="page">

  <!-- ── Cabeçalho institucional ──────────────────────────────────────── -->
  <div class="report-header">
    <div>
      <div class="report-logo">NUPICS</div>
      <div class="report-inst">Núcleo de Práticas Integrativas e Complementares em Saúde</div>
      <div class="report-inst">Universidade do Estado do Rio Grande do Norte — UERN Caicó</div>
    </div>
    <div class="report-meta">
      <strong>Relatório Institucional</strong>
      Período: <?= $ini_fmt ?> a <?= $fim_fmt ?><br>
      Gerado em: <?= date('d/m/Y \à\s H:i') ?><br>
      Por: <?= htmlspecialchars($_SESSION['nome'] ?? 'Coordenação') ?>
    </div>
  </div>

  <!-- ── 1. Sumário executivo ──────────────────────────────────────────── -->
  <div class="section">
    <div class="section-title">Sumário executivo do período</div>
    <div class="stat-grid">
      <div class="stat-card">
        <div class="lbl">Total de atendimentos</div>
        <div class="val"><?= number_format($total_atend) ?></div>
        <div class="sub"><?= $s['plt_realizados'] ?> plantão + <?= $s['ciclo_realizados'] ?> ciclos</div>
      </div>
      <div class="stat-card green">
        <div class="lbl">Taxa de frequência</div>
        <div class="val"><?= $taxa_freq ?>%</div>
        <div class="sub">Sessões realizadas / previstas</div>
      </div>
      <div class="stat-card red">
        <div class="lbl">Faltas registradas</div>
        <div class="val"><?= $s['faltas'] ?></div>
        <div class="sub"><?= $taxa_falta ?>% do total de sessões</div>
      </div>
      <div class="stat-card blue">
        <div class="lbl">Novos pacientes</div>
        <div class="val"><?= $s['novos_pacientes'] ?></div>
        <div class="sub">Cadastrados no período</div>
      </div>
    </div>
    <div class="stat-grid" style="margin-top:10px">
      <div class="stat-card">
        <div class="lbl">Ciclos ativos</div>
        <div class="val"><?= $s['ciclos_ativos'] ?></div>
        <div class="sub">Em andamento atualmente</div>
      </div>
      <div class="stat-card green">
        <div class="lbl">Ciclos concluídos</div>
        <div class="val"><?= $s['ciclos_concluidos'] ?></div>
        <div class="sub">No período selecionado</div>
      </div>
      <div class="stat-card red">
        <div class="lbl">Ciclos cancelados</div>
        <div class="val"><?= $s['ciclos_cancelados'] ?></div>
        <div class="sub">No período selecionado</div>
      </div>
      <div class="stat-card amber">
        <div class="lbl">Visitas externas</div>
        <div class="val"><?= $s['visitas_realizadas'] ?></div>
        <div class="sub">Ações comunitárias realizadas</div>
      </div>
    </div>
    <div class="stat-grid stat-grid-3" style="margin-top:10px">
      <div class="stat-card blue">
        <div class="lbl">Total de pacientes cadastrados</div>
        <div class="val"><?= $s['total_pacientes'] ?></div>
        <div class="sub">Acumulado geral</div>
      </div>
      <div class="stat-card">
        <div class="lbl">Terapeutas ativos</div>
        <div class="val"><?= $s['total_terapeutas'] ?></div>
        <div class="sub">Cadastros habilitados</div>
      </div>
      <div class="stat-card green">
        <div class="lbl">Sessões de plantão</div>
        <div class="val"><?= $s['plt_realizados'] ?></div>
        <div class="sub">Atendimentos abertos no período</div>
      </div>
    </div>
  </div>

  <!-- ── 2. Desempenho por terapeuta ────────────────────────────────────── -->
  <div class="section">
    <div class="section-title">Desempenho por terapeuta</div>
    <?php if (empty($terapeutas_lista)): ?>
    <p style="color:var(--muted);font-size:11px;">Nenhum dado no período.</p>
    <?php else:
      $max_ter = max(1, max(array_map(fn($t)=>(int)$t['plantao']+(int)$t['ciclo'], $terapeutas_lista)));
    ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Terapeuta</th>
          <th>Especialidade</th>
          <th>Plantão</th>
          <th>Ciclos</th>
          <th>Faltas</th>
          <th>Total</th>
          <th style="width:120px">Progresso</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($terapeutas_lista as $i => $t):
          $tot = (int)$t['plantao'] + (int)$t['ciclo'];
          $pct = round(($tot / $max_ter) * 100);
        ?>
        <tr>
          <td style="font-weight:700;color:var(--muted)"><?= $i+1 ?></td>
          <td style="font-weight:700"><?= htmlspecialchars($t['nome']) ?></td>
          <td style="color:var(--muted)"><?= htmlspecialchars($t['especialidade'] ?? '—') ?></td>
          <td style="text-align:center"><?= $t['plantao'] ?></td>
          <td style="text-align:center"><?= $t['ciclo'] ?></td>
          <td style="text-align:center;color:var(--red)"><?= $t['faltas_t'] ?></td>
          <td style="text-align:center;font-weight:800;color:var(--primary)"><?= $tot ?></td>
          <td>
            <div class="bar-wrap">
              <div class="bar-fill" style="width:<?= $pct ?>%"></div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php if (!empty($sugestoes)): ?>
  <!-- ── 3. Sugestões e reclamações ──────────────────────────────────────── -->
  <div class="section">
    <div class="section-title">Feedback de pacientes</div>
    <table>
      <thead>
        <tr><th>Tipo</th><th>Quantidade</th><th style="width:200px">Proporção</th></tr>
      </thead>
      <tbody>
        <?php
        $total_sug = array_sum(array_column($sugestoes, 'qtd'));
        $icon_tipo = ['sugestao'=>'💡 Sugestão','elogio'=>'👏 Elogio','reclamacao'=>'⚠️ Reclamação','duvida'=>'❓ Dúvida'];
        foreach ($sugestoes as $sg):
          $pct = $total_sug > 0 ? round($sg['qtd']/$total_sug*100) : 0;
        ?>
        <tr>
          <td><?= $icon_tipo[$sg['tipo']] ?? ucfirst($sg['tipo']) ?></td>
          <td style="font-weight:700"><?= $sg['qtd'] ?> (<?= $pct ?>%)</td>
          <td><div class="bar-wrap"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div></td>
        </tr>
        <?php endforeach; ?>
        <tr style="font-weight:700;background:var(--bg-soft)">
          <td>Total</td><td><?= $total_sug ?></td><td></td>
        </tr>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ── 4. Ciclos no período ─────────────────────────────────────────────── -->
  <?php if (!empty($ciclos)): ?>
  <div class="section page-break">
    <div class="section-title">Ciclos terapêuticos — <?= count($ciclos) ?> registro(s)</div>
    <table>
      <thead>
        <tr>
          <th>Paciente</th>
          <th>Terapeuta</th>
          <th>Dia/Horário</th>
          <th>Sessões</th>
          <th>Faltas</th>
          <th>Início</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ciclos as $c): ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars($c['pac_nome']) ?></td>
          <td><?= htmlspecialchars($c['ter_nome']) ?></td>
          <td style="white-space:nowrap;color:var(--muted)"><?= $dias_full[(int)$c['dia_semana']] ?> <?= substr($c['hora_inicio'],0,5) ?>–<?= substr($c['hora_fim'],0,5) ?></td>
          <td style="text-align:center"><?= $c['sessoes_realizadas'] ?>/<?= $c['total_sessoes'] ?></td>
          <td style="text-align:center;color:<?= $c['faltas']>0?'var(--red)':'inherit' ?>"><?= $c['faltas'] ?></td>
          <td style="white-space:nowrap;color:var(--muted)"><?= $c['criado_em'] ? date('d/m/Y',strtotime($c['criado_em'])) : '—' ?></td>
          <td><span class="badge badge-<?= $c['status'] ?>"><?= ucfirst($c['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ── 5. Visitas externas ──────────────────────────────────────────────── -->
  <?php if (!empty($visitas)): ?>
  <div class="section">
    <div class="section-title">Visitas e ações externas — <?= count($visitas) ?> registro(s)</div>
    <table>
      <thead>
        <tr>
          <th>Local</th>
          <th>Tipo</th>
          <th>Data</th>
          <th>Participantes</th>
          <th>Terapeutas escalados</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($visitas as $v): ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars($v['local_nome']) ?></td>
          <td style="text-transform:capitalize;color:var(--muted)"><?= htmlspecialchars($v['local_tipo'] ?? '—') ?></td>
          <td style="white-space:nowrap;color:var(--muted)"><?= $v['data_realizada'] ? date('d/m/Y',strtotime($v['data_realizada'])) : ($v['data_sugerida'] ? date('d/m/Y',strtotime($v['data_sugerida'])) : '—') ?></td>
          <td style="text-align:center"><?= $v['total_participantes'] ?? '—' ?></td>
          <td style="color:var(--muted);font-size:10px"><?= htmlspecialchars($v['terapeutas_escalados'] ?? '—') ?></td>
          <td><span class="badge badge-<?= $v['status'] ?>"><?= ucfirst($v['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ── 6. Novos pacientes ───────────────────────────────────────────────── -->
  <?php if (!empty($pacientes_novos)): ?>
  <div class="section">
    <div class="section-title">Novos pacientes cadastrados — <?= count($pacientes_novos) ?> registro(s)</div>
    <table>
      <thead>
        <tr>
          <th>Nome</th>
          <th>E-mail</th>
          <th>Vínculo</th>
          <th>Cadastro</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pacientes_novos as $p): ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars($p['nome']) ?></td>
          <td style="color:var(--muted)"><?= htmlspecialchars($p['email']) ?></td>
          <td style="text-transform:capitalize"><?= $p['vinculo'] ?? '—' ?></td>
          <td style="white-space:nowrap;color:var(--muted)"><?= date('d/m/Y', strtotime($p['criado_em'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ── Rodapé ──────────────────────────────────────────────────────────── -->
  <div class="report-footer">
    <div>
      <strong style="color:var(--primary)">NUPICS — UERN Caicó</strong><br>
      Núcleo de Práticas Integrativas e Complementares em Saúde
    </div>
    <div style="text-align:right">
      Relatório gerado automaticamente pelo sistema<br>
      em <?= date('d/m/Y \à\s H:i') ?> · Período: <?= $ini_fmt ?> a <?= $fim_fmt ?>
    </div>
  </div>

</div><!-- /page -->
</body>
</html>