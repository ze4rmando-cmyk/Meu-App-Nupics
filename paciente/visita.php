<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'paciente') {
    header('Location: ../index.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$sucesso = '';
$erro    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $local_nome      = trim($_POST['local_nome']       ?? '');
    $local_tipo      = trim($_POST['local_tipo']       ?? '');
    $local_endereco  = trim($_POST['local_endereco']   ?? '');
    $contato_nome    = trim($_POST['contato_nome']     ?? '');
    $contato_tel     = trim($_POST['contato_telefone'] ?? '');
    $data_sug        = trim($_POST['data_sugerida']    ?? '');
    $hora_sug        = trim($_POST['hora_sugerida']    ?? '');
    $num_pessoas     = (int)($_POST['num_pessoas']     ?? 1);
    $observacao      = trim($_POST['observacao']       ?? '');

    if (!$local_nome || !$local_tipo) {
        $erro = 'Informe o nome e o tipo do local.';
    } else {
        $pdo->prepare('
            INSERT INTO visitas_externas
            (solicitante_id, local_nome, local_tipo, local_endereco,
             contato_nome, contato_telefone, data_sugerida, hora_sugerida,
             num_pessoas, observacao, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,"pendente")
        ')->execute([
            $usuario_id, $local_nome, $local_tipo, $local_endereco,
            $contato_nome, $contato_tel,
            $data_sug ?: null, $hora_sug ?: null,
            $num_pessoas, $observacao
        ]);
        $sucesso = 'Solicitação enviada! A equipe do Nupics entrará em contato em breve.';
    }
}

// Histórico de solicitações do paciente
$historico = $pdo->prepare('
    SELECT * FROM visitas_externas WHERE solicitante_id = ?
    ORDER BY criado_em DESC
');
$historico->execute([$usuario_id]);
$historico = $historico->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NUPICS Caicó — Solicitar Visita</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    body { font-family:'Manrope',sans-serif; background:#fff7fc; }
    .headline { font-family:'Plus Jakarta Sans',sans-serif; }
    .glass { background:rgba(255,255,255,0.7); backdrop-filter:blur(12px); }
    .campo label { display:block; font-size:12px; font-weight:600; color:#6b21a8; margin-bottom:5px; }
    .campo input, .campo select, .campo textarea {
      width:100%; padding:10px 14px; border:1.5px solid #e9d5ff;
      border-radius:10px; font-size:13px; font-family:inherit;
      color:#1a1a1a; background:white; transition:border-color .15s;
    }
    .campo input:focus, .campo select:focus, .campo textarea:focus {
      outline:none; border-color:#a855f7;
      box-shadow:0 0 0 3px rgba(168,85,247,.1);
    }
  </style>
</head>
<body class="min-h-screen">

  <div class="fixed inset-0 -z-10 pointer-events-none"
       style="background:radial-gradient(ellipse at 10% 30%,rgba(233,213,255,.4) 0%,transparent 55%),
                          radial-gradient(ellipse at 90% 70%,rgba(252,231,243,.4) 0%,transparent 50%)">
  </div>

  <!-- Nav -->
  <nav class="sticky top-0 z-50 glass border-b border-purple-100/40 px-5 py-3 flex justify-between items-center">
    <div class="text-base font-extrabold headline"
         style="background:linear-gradient(135deg,#4e0078,#b7004d);-webkit-background-clip:text;-webkit-text-fill-color:transparent">
      NUPICS Caicó
    </div>
    <div class="flex gap-2">
      <a href="dashboard.php"
         class="text-xs text-purple-400 hover:text-purple-700 border border-purple-200 rounded-full px-3 py-1">
        ← Voltar
      </a>
      <a href="../api/logout.php"
         class="text-xs text-purple-400 hover:text-purple-700 border border-purple-200 rounded-full px-3 py-1">
        Sair
      </a>
    </div>
  </nav>

  <main class="max-w-2xl mx-auto px-4 py-8">

    <h1 class="text-2xl font-extrabold headline text-purple-900 mb-1">Solicitar visita do Nupics</h1>
    <p class="text-sm text-purple-400 mb-6">
      O Nupics realiza atendimentos em UBS, hospitais, clínicas e empresas.
      Preencha os dados abaixo e entraremos em contato para confirmar.
    </p>

    <?php if ($sucesso): ?>
    <div class="bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-3 text-sm font-medium mb-5">
      <?= $sucesso ?>
    </div>
    <?php endif; ?>

    <?php if ($erro): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm font-medium mb-5">
      <?= htmlspecialchars($erro) ?>
    </div>
    <?php endif; ?>

    <!-- Formulário -->
    <div class="glass border border-purple-100/40 rounded-2xl p-6 mb-6 shadow-sm">
      <form method="POST" action="visita.php" class="space-y-4">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="campo">
            <label>Nome do local *</label>
            <input type="text" name="local_nome" placeholder="Ex: UBS Centro, Hospital Regional"
                   required value="<?= htmlspecialchars($_POST['local_nome'] ?? '') ?>">
          </div>
          <div class="campo">
            <label>Tipo de local *</label>
            <select name="local_tipo" required>
              <option value="">Selecione</option>
              <option value="ubs"      <?= ($_POST['local_tipo']??'')==='ubs'?'selected':'' ?>>UBS</option>
              <option value="hospital" <?= ($_POST['local_tipo']??'')==='hospital'?'selected':'' ?>>Hospital</option>
              <option value="clinica"  <?= ($_POST['local_tipo']??'')==='clinica'?'selected':'' ?>>Clínica</option>
              <option value="empresa"  <?= ($_POST['local_tipo']??'')==='empresa'?'selected':'' ?>>Empresa</option>
              <option value="outro"    <?= ($_POST['local_tipo']??'')==='outro'?'selected':'' ?>>Outro</option>
            </select>
          </div>
        </div>

        <div class="campo">
          <label>Endereço do local</label>
          <input type="text" name="local_endereco"
                 placeholder="Rua, número, bairro — Caicó/RN"
                 value="<?= htmlspecialchars($_POST['local_endereco'] ?? '') ?>">
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div class="campo">
            <label>Nome do responsável no local</label>
            <input type="text" name="contato_nome"
                   placeholder="Quem receberá a equipe?"
                   value="<?= htmlspecialchars($_POST['contato_nome'] ?? '') ?>">
          </div>
          <div class="campo">
            <label>Telefone de contato</label>
            <input type="text" name="contato_telefone"
                   placeholder="(84) 9 0000-0000"
                   value="<?= htmlspecialchars($_POST['contato_telefone'] ?? '') ?>">
          </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="campo">
            <label>Data sugerida</label>
            <input type="date" name="data_sugerida"
                   value="<?= htmlspecialchars($_POST['data_sugerida'] ?? '') ?>">
          </div>
          <div class="campo">
            <label>Horário sugerido</label>
            <input type="time" name="hora_sugerida"
                   value="<?= htmlspecialchars($_POST['hora_sugerida'] ?? '') ?>">
          </div>
          <div class="campo">
            <label>Nº aproximado de pessoas</label>
            <input type="number" name="num_pessoas" min="1" max="200"
                   value="<?= htmlspecialchars($_POST['num_pessoas'] ?? '1') ?>">
          </div>
        </div>

        <div class="campo">
          <label>Observações / contexto da visita</label>
          <textarea name="observacao" rows="3"
                    placeholder="Descreva o objetivo da visita, o perfil das pessoas a serem atendidas, necessidades específicas..."><?= htmlspecialchars($_POST['observacao'] ?? '') ?></textarea>
        </div>

        <button type="submit"
                class="w-full py-3 rounded-xl text-white font-bold text-sm transition-opacity hover:opacity-90"
                style="background:linear-gradient(135deg,#4e0078,#b7004d)">
          Enviar solicitação
        </button>
      </form>
    </div>

    <!-- Histórico de solicitações -->
    <?php if (!empty($historico)): ?>
    <div class="glass border border-purple-100/40 rounded-2xl p-5 shadow-sm">
      <h2 class="text-xs font-extrabold uppercase tracking-widest text-purple-400 mb-4">Minhas solicitações</h2>
      <?php foreach ($historico as $v):
        $status_info = match($v['status']) {
            'aprovada'  => ['bg-blue-100 text-blue-800',   'Aprovada'],
            'realizada' => ['bg-green-100 text-green-800', 'Realizada'],
            'cancelada' => ['bg-red-100 text-red-800',     'Cancelada'],
            default     => ['bg-yellow-100 text-yellow-800','Pendente'],
        };
        $data_fmt = $v['data_sugerida'] ? date('d/m/Y', strtotime($v['data_sugerida'])) : '—';
        $tipos = ['ubs'=>'UBS','hospital'=>'Hospital','clinica'=>'Clínica','empresa'=>'Empresa','outro'=>'Outro'];
      ?>
      <div class="flex items-start gap-3 p-3 rounded-xl bg-white/50 border border-purple-100/30 mb-2">
        <div class="flex-1 min-w-0">
          <div class="text-sm font-bold text-purple-900"><?= htmlspecialchars($v['local_nome']) ?></div>
          <div class="text-xs text-purple-400">
            <?= $tipos[$v['local_tipo']] ?? $v['local_tipo'] ?>
            · <?= $data_fmt ?>
            · <?= $v['num_pessoas'] ?> pessoa<?= $v['num_pessoas']!=1?'s':'' ?>
          </div>
        </div>
        <span class="text-[10px] font-bold px-2 py-1 rounded-full <?= $status_info[0] ?> whitespace-nowrap">
          <?= $status_info[1] ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </main>
</body>
</html>