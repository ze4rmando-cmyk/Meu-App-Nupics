<?php
session_start();

// Já logado → redireciona
if (isset($_SESSION['usuario_id'])) {
    if ($_SESSION['tipo'] === 'paciente')         header('Location: paciente/dashboard.php');
    elseif ($_SESSION['tipo'] === 'coordenador')  header('Location: coordenacao/dashboard.php');
    else                                          header('Location: terapeuta/dashboard.php');
    exit;
}

$erro    = '';
$sucesso = '';
$modo    = $_GET['modo'] ?? 'login'; // 'login' | 'cadastro'
$etapa   = (int)($_POST['etapa'] ?? $_GET['etapa'] ?? 1); // 1 = pessoal | 2 = anamnese

// ═══════════════════════════════════════
//  LOGIN
// ═══════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'login') {
    require_once 'config/db.php';
    $email   = trim($_POST['email'] ?? '');
    $senha   = trim($_POST['senha'] ?? '');
    $stmt    = $pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();
    if ($usuario && password_verify($senha, $usuario['senha'])) {
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['nome']       = $usuario['nome'];
        $_SESSION['tipo']       = $usuario['tipo'];
        if ($usuario['tipo'] === 'paciente')        header('Location: paciente/dashboard.php');
        elseif ($usuario['tipo'] === 'coordenador') header('Location: coordenacao/dashboard.php');
        else                                        header('Location: terapeuta/dashboard.php');
        exit;
    }
    $erro = 'E-mail ou senha incorretos.';
    $modo = 'login';
}

// ═══════════════════════════════════════
//  CADASTRO — ETAPA 1 → avança para 2
// ═══════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastro_etapa1') {
    $modo  = 'cadastro';
    $etapa = 2;
    // Mantém os dados no POST (serão re-emitidos como hidden fields)
}

// ═══════════════════════════════════════
//  CADASTRO — ETAPA 2 → salva no banco
// ═══════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastro_final') {
    require_once 'config/db.php';
    $modo  = 'cadastro';
    $etapa = 2;

    // ── Etapa 1
    $nome        = trim($_POST['nome']       ?? '');
    $vinculo     = trim($_POST['vinculo']    ?? '');
    $email_uern  = trim($_POST['email_uern'] ?? '');
    $email_cad   = trim($_POST['email_cad']  ?? '');
    $senha       = trim($_POST['senha_cad']  ?? '');
    $telefone    = trim($_POST['telefone']   ?? '');
    $data_nasc   = trim($_POST['data_nasc']  ?? '');
    $cpf         = trim($_POST['cpf']        ?? '');
    $sexo        = trim($_POST['sexo']       ?? '');
    $sexo_outro  = trim($_POST['sexo_outro'] ?? '');
    $ocupacao    = trim($_POST['ocupacao']   ?? '');
    $como_conheceu = trim($_POST['como_conheceu'] ?? '');
    $como_outro  = trim($_POST['como_outro'] ?? '');

    // ── Etapa 2
    $doenca           = $_POST['doenca']           ?? 'nao';
    $doenca_qual      = trim($_POST['doenca_qual'] ?? '');
    $medicamento      = $_POST['medicamento']      ?? 'nao';
    $medicamento_qual = trim($_POST['medicamento_qual'] ?? '');
    $alergia          = $_POST['alergia']          ?? 'nao';
    $alergia_qual     = trim($_POST['alergia_qual'] ?? '');
    $trat_integ       = $_POST['trat_integ']       ?? 'nao';
    $trat_integ_qual  = trim($_POST['trat_integ_qual'] ?? '');
    $objetivos_arr    = $_POST['objetivos']        ?? [];   // array (checkboxes)
    $objetivo_outro   = trim($_POST['objetivo_outro'] ?? '');
    $bem_estar        = trim($_POST['bem_estar']   ?? '');
    $nivel_dor        = trim($_POST['nivel_dor']   ?? '');
    $qualidade_sono   = trim($_POST['qualidade_sono'] ?? '');
    $ativ_fisica      = trim($_POST['atividade_fisica'] ?? '');
    $consentimento    = isset($_POST['consentimento']) ? 1 : 0;

    // ── Validações
    $email_final = ($vinculo === 'interno') ? $email_uern : $email_cad;
    $sexo_final  = ($sexo === 'outro') ? $sexo_outro : $sexo;
    $como_final  = ($como_conheceu === 'outro') ? $como_outro : $como_conheceu;

    if (!$nome || !$email_final || !$senha || !$telefone || !$data_nasc || !$sexo) {
        $erro = 'Preencha todos os campos obrigatórios da etapa 1.';
    } elseif ($vinculo === 'externo' && !$cpf) {
        $erro = 'Informe o CPF (obrigatório para pacientes externos).';
    } elseif ($vinculo === 'interno' && !filter_var($email_uern, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Informe um e-mail institucional UERN válido.';
    } elseif (!$consentimento) {
        $erro = 'Você precisa aceitar os Termos de Consentimento.';
    } elseif (strlen($senha) < 6) {
        $erro = 'A senha deve ter pelo menos 6 caracteres.';
    } else {
        $chk = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
        $chk->execute([$email_final]);
        if ($chk->fetch()) {
            $erro = 'Este e-mail já está cadastrado.';
        } else {
            // Monta objetivos
            $objetivos_list = $objetivos_arr;
            if ($objetivo_outro) $objetivos_list[] = $objetivo_outro;
            $objetivos_str = implode(',', array_filter(array_map('trim', $objetivos_list)));

            // Monta observação clínica
            $obs = [];
            if ($doenca === 'sim' && $doenca_qual)         $obs[] = "Doença: $doenca_qual";
            if ($medicamento === 'sim' && $medicamento_qual) $obs[] = "Medicamentos: $medicamento_qual";
            if ($alergia === 'sim' && $alergia_qual)        $obs[] = "Alergias: $alergia_qual";
            if ($trat_integ === 'sim' && $trat_integ_qual)  $obs[] = "Trat. integrativo anterior: $trat_integ_qual";
            if ($bem_estar)   $obs[] = "Bem-estar atual: $bem_estar/10";
            if ($nivel_dor)   $obs[] = "Nível de dor: $nivel_dor/10";
            if ($qualidade_sono)   $obs[] = "Sono: $qualidade_sono";
            if ($ativ_fisica)      $obs[] = "Atividade física: $ativ_fisica";
            $observacao = implode(' | ', $obs);

            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO usuarios (nome, email, senha, tipo, telefone) VALUES (?,?,?,"paciente",?)')
                ->execute([$nome, $email_final, $hash, $telefone]);
            $uid = $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO pacientes (usuario_id, cpf, data_nasc) VALUES (?,?,?)')
                ->execute([$uid, $cpf ?: null, $data_nasc]);

            $pdo->prepare('
                UPDATE pacientes SET
                    sexo               = ?,
                    observacao_clinica = ?,
                    consentimento      = 1,
                    vinculo            = ?,
                    email_uern         = ?,
                    objetivos          = ?,
                    como_conheceu      = ?,
                    ocupacao           = ?,
                    nivel_dor          = ?,
                    qualidade_sono     = ?,
                    atividade_fisica   = ?
                WHERE usuario_id = ?
            ')->execute([
                $sexo_final, $observacao, $vinculo,
                $email_uern ?: null, $objetivos_str, $como_final,
                $ocupacao ?: null,
                $nivel_dor !== '' ? (int)$nivel_dor : null,
                $qualidade_sono ?: null, $ativ_fisica ?: null,
                $uid
            ]);

            $sucesso = 'Cadastro realizado! Faça login para continuar.';
            $modo    = 'login';
            $etapa   = 1;
        }
    }
}

// ── Dados de etapa 1 que persistem ao avançar para 2
$p = $_POST; // atalho para pré-preencher campos após erro
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>NUPICS Caicó</title>
  <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
  <script>
    tailwind.config = {
      theme:{extend:{
        colors:{
          "primary":"#4e0078","on-primary":"#ffffff","primary-container":"#6a1b9a",
          "secondary":"#b7004d","surface":"#fff7fc","on-surface":"#201923",
          "surface-variant":"#ecdeed","on-surface-variant":"#4d4351",
          "outline":"#7f7383","outline-variant":"#d0c2d3",
          "surface-container-low":"#fdeffe","surface-container":"#f7eaf8",
          "surface-container-high":"#f2e4f2","surface-container-highest":"#ecdeed",
          "error":"#ba1a1a","error-container":"#ffdad6","on-error-container":"#93000a"
        },
        fontFamily:{"headline":["Plus Jakarta Sans"],"body":["Manrope"]}
      }}
    }
  </script>
  <style>
    body { font-family:"Manrope",sans-serif; background:radial-gradient(135deg,#f4d9ff 0%,#fff7fc 40%,#ffd9de 100%); min-height:100vh; }
    h1,h2,h3 { font-family:"Plus Jakarta Sans",sans-serif }
    .material-symbols-outlined { font-variation-settings:"FILL" 0,"wght" 400,"GRAD" 0,"opsz" 24 }
    /* glass card */
    .card { background:rgba(255,255,255,.82); backdrop-filter:blur(20px) saturate(180%);
            -webkit-backdrop-filter:blur(20px) saturate(180%); border:1px solid rgba(255,255,255,.5); }
    /* campo */
    .campo { position:relative; display:flex; align-items:center;
             background:rgba(255,255,255,.7); border:1.5px solid #d0c2d3;
             border-radius:14px; overflow:hidden; transition:.15s; }
    .campo:focus-within { border-color:#4e0078; box-shadow:0 0 0 3px rgba(78,0,120,.12); }
    .campo .ic { padding:0 12px; color:#7f7383; display:flex; align-items:center; flex-shrink:0; }
    .campo input, .campo select, .campo textarea {
      flex:1; border:none; background:transparent; padding:13px 14px 13px 0;
      font-size:.875rem; color:#201923; font-family:"Manrope",sans-serif;
      outline:none; min-width:0; }
    .campo select { cursor:pointer; }
    .campo .olho { padding:0 12px; background:none; border:none; cursor:pointer; color:#aaa; display:flex; }
    /* toggle vínculo */
    .vinculo-btn { flex:1; padding:10px; border-radius:10px; font-size:.8rem; font-weight:600;
                   text-align:center; cursor:pointer; transition:.15s; border:2px solid transparent;
                   background:rgba(255,255,255,.5); color:#4d4351; }
    .vinculo-btn.ativo { background:#4e0078; color:#fff; border-color:#4e0078; }
    /* checkbox/radio custom */
    .pill-check, .pill-radio { display:inline-flex; align-items:center; gap:6px; padding:7px 14px;
                 border-radius:99px; border:1.5px solid #d0c2d3; font-size:.78rem; font-weight:600;
                 cursor:pointer; transition:.15s; background:rgba(255,255,255,.6); color:#4d4351;
                 user-select:none; }
    .pill-check:has(input:checked), .pill-radio:has(input:checked) {
                 background:#4e0078; color:#fff; border-color:#4e0078; }
    .pill-check input, .pill-radio input { display:none; }
    /* be-bar */
    .be-btn { width:2.2rem; height:2.2rem; border-radius:50%; display:flex; align-items:center;
              justify-content:center; font-size:.75rem; font-weight:700; border:1.5px solid #d0c2d3;
              cursor:pointer; transition:.15s; background:rgba(255,255,255,.6); color:#4d4351; }
    .be-btn:has(input:checked) { background:#4e0078; color:#fff; border-color:#4e0078; }
    .be-btn input { display:none; }
    /* botão principal */
    .btn-primary { width:100%; padding:14px; border-radius:99px;
                   background:linear-gradient(135deg,#6a1b9a,#b7004d); color:#fff;
                   font-weight:700; font-size:.95rem; border:none; cursor:pointer;
                   transition:opacity .15s,transform .1s; font-family:"Manrope",sans-serif; }
    .btn-primary:hover { opacity:.92; }
    .btn-primary:active { transform:scale(.98); }
    .btn-outline { width:100%; padding:13px; border-radius:99px; border:2px solid #d0c2d3;
                   background:transparent; color:#4d4351; font-weight:700; font-size:.9rem;
                   cursor:pointer; transition:.15s; font-family:"Manrope",sans-serif; }
    .btn-outline:hover { border-color:#4e0078; color:#4e0078; }
    /* stepper */
    .step-dot { width:2rem; height:2rem; border-radius:50%; display:flex; align-items:center;
                justify-content:center; font-size:.75rem; font-weight:700; transition:.3s; }
    .step-dot.done { background:#4e0078; color:#fff; }
    .step-dot.active { background:#4e0078; color:#fff; box-shadow:0 0 0 4px rgba(78,0,120,.18); }
    .step-dot.idle { background:#ecdeed; color:#7f7383; }
    /* seção título */
    .sec-title { font-size:.7rem; font-weight:700; text-transform:uppercase;
                 letter-spacing:.07em; color:#7f7383; margin:1.2rem 0 .6rem;
                 display:flex; align-items:center; gap:.4rem; }
    .sec-title::after { content:''; flex:1; height:1px; background:#ecdeed; }
    /* alertas */
    .alerta-erro    { background:#ffdad6; color:#93000a; border-radius:12px; padding:.7rem 1rem; font-size:.85rem; font-weight:600; }
    .alerta-sucesso { background:#d1fae5; color:#065f46; border-radius:12px; padding:.7rem 1rem; font-size:.85rem; font-weight:600; }
    /* modal */
    .modal-overlay { display:none; position:fixed; inset:0; z-index:200; background:rgba(78,0,120,.25);
                     backdrop-filter:blur(4px); align-items:center; justify-content:center; padding:1rem; }
    .modal-overlay.open { display:flex; }
    .modal-box { background:#fff; border-radius:1.5rem; max-width:560px; width:100%;
                 max-height:88vh; display:flex; flex-direction:column; box-shadow:0 24px 60px rgba(0,0,0,.18); }
    .modal-header { display:flex; align-items:center; justify-content:space-between;
                    padding:1.25rem 1.5rem; border-bottom:1px solid #ecdeed; flex-shrink:0; }
    .modal-titulo { font-size:1rem; font-weight:700; color:#4e0078; font-family:"Plus Jakarta Sans",sans-serif; }
    .modal-fechar { background:none; border:none; font-size:1.1rem; cursor:pointer; color:#7f7383; padding:.25rem .4rem; border-radius:8px; }
    .modal-corpo { overflow-y:auto; padding:1.25rem 1.5rem; font-size:.83rem; color:#201923; line-height:1.7; flex:1; }
    .modal-corpo p { margin-bottom:.75rem; }
    .modal-rodape { margin-top:1rem; padding-top:1rem; border-top:1px solid #ecdeed; font-size:.78rem; color:#7f7383; }
    /* scroll fino */
    ::-webkit-scrollbar { width:5px; } ::-webkit-scrollbar-thumb { background:#d0c2d3; border-radius:99px; }
  </style>
</head>
<body>

<div class="min-h-screen flex items-center justify-center px-4 py-10">
  <div class="w-full max-w-md">

    <!-- Logo -->
    <div class="flex flex-col items-center mb-8">
      <div class="flex items-center gap-3 mb-1">
        <svg viewBox="0 0 40 40" width="38" height="38">
          <ellipse cx="20" cy="10" rx="6" ry="9" fill="none" stroke="#c084fc" stroke-width="2"/>
          <ellipse cx="10" cy="22" rx="9" ry="6" fill="none" stroke="#c084fc" stroke-width="2" transform="rotate(-30 10 22)"/>
          <ellipse cx="30" cy="22" rx="9" ry="6" fill="none" stroke="#c084fc" stroke-width="2" transform="rotate(30 30 22)"/>
          <circle cx="20" cy="20" r="3" fill="#c084fc"/>
        </svg>
        <span class="text-2xl font-extrabold bg-gradient-to-r from-purple-700 to-pink-600 bg-clip-text text-transparent" style="font-family:'Plus Jakarta Sans',sans-serif">nupics</span>
      </div>
      <p class="text-xs text-on-surface-variant font-medium tracking-wide">Núcleo de Práticas Integrativas · UERN Caicó</p>
    </div>

    <!-- ═══════════════ CARD LOGIN ═══════════════ -->
    <?php if ($modo === 'login'): ?>
    <div class="card rounded-3xl p-8 shadow-2xl shadow-primary/10">
      <h2 class="text-2xl font-extrabold text-primary mb-1">Bem-vindo(a)!</h2>
      <p class="text-sm text-on-surface-variant mb-6">Acesse sua conta para agendar sessões.</p>

      <?php if ($sucesso): ?><div class="alerta-sucesso mb-4"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
      <?php if ($erro):    ?><div class="alerta-erro mb-4"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

      <form method="POST" action="index.php" class="space-y-3">
        <input type="hidden" name="acao" value="login"/>

        <div class="campo">
          <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">mail</span></span>
          <input type="email" name="email" placeholder="E-mail" required
                 value="<?= htmlspecialchars($p['email'] ?? '') ?>"/>
        </div>

        <div class="campo">
          <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">lock</span></span>
          <input type="password" name="senha" id="senha-login" placeholder="Senha" required/>
          <button type="button" class="olho" onclick="toggleSenha('senha-login',this)">
            <span class="material-symbols-outlined" style="font-size:18px">visibility</span>
          </button>
        </div>

        <button type="submit" class="btn-primary mt-2">Entrar</button>
      </form>

      <p class="text-center text-sm text-on-surface-variant mt-5">
        Não tem conta?
        <a href="index.php?modo=cadastro" class="text-primary font-bold hover:underline">Cadastre-se</a>
      </p>
    </div>

    <!-- ═══════════════ CARD CADASTRO ═══════════════ -->
    <?php else: ?>

    <!-- Stepper -->
    <div class="flex items-center gap-2 mb-6 px-2">
      <!-- Step 1 -->
      <div class="step-dot <?= $etapa >= 1 ? 'done' : 'idle' ?>">1</div>
      <div class="text-xs font-semibold <?= $etapa===1 ? 'text-primary' : 'text-outline' ?> mr-1">Dados pessoais</div>
      <!-- Linha -->
      <div class="flex-1 h-0.5 rounded-full <?= $etapa >= 2 ? 'bg-primary' : 'bg-outline-variant' ?>"></div>
      <!-- Step 2 -->
      <div class="step-dot <?= $etapa >= 2 ? 'active' : 'idle' ?> ml-1">2</div>
      <div class="text-xs font-semibold <?= $etapa===2 ? 'text-primary' : 'text-outline' ?>">Anamnese</div>
    </div>

    <div class="card rounded-3xl p-7 shadow-2xl shadow-primary/10">

      <?php if ($erro): ?><div class="alerta-erro mb-4"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

      <!-- ─────────────────── ETAPA 1 ─────────────────── -->
      <?php if ($etapa === 1): ?>

      <h2 class="text-xl font-extrabold text-primary mb-0.5">Criar conta</h2>
      <p class="text-sm text-on-surface-variant mb-5">Etapa 1 de 2 · Dados pessoais</p>

      <form method="POST" action="index.php?modo=cadastro" id="form-etapa1" novalidate>
        <input type="hidden" name="acao" value="cadastro_etapa1"/>
        <input type="hidden" name="etapa" value="1"/>

        <!-- VÍNCULO -->
        <div class="sec-title"><span class="material-symbols-outlined" style="font-size:15px">badge</span>Vínculo com a UERN</div>
        <div class="flex gap-2 mb-3" id="vinculo-wrap">
          <button type="button" class="vinculo-btn <?= ($p['vinculo']??'') !== 'externo' ? 'ativo' : '' ?>" id="btn-interno"
                  onclick="setVinculo('interno')">🎓 Interno (UERN)</button>
          <button type="button" class="vinculo-btn <?= ($p['vinculo']??'') === 'externo' ? 'ativo' : '' ?>" id="btn-externo"
                  onclick="setVinculo('externo')">🏙️ Externo (Comunidade)</button>
        </div>
        <input type="hidden" name="vinculo" id="vinculo-val" value="<?= htmlspecialchars($p['vinculo'] ?? 'interno') ?>"/>
        <p id="vinculo-desc" class="text-xs text-on-surface-variant mb-3 leading-relaxed">
          <?= ($p['vinculo']??'externo') === 'externo'
            ? 'Moradores de Caicó e região sem vínculo com a UERN. Identificação por CPF.'
            : 'Estudantes, professores e servidores da UERN. Identificação pelo e-mail institucional.' ?>
        </p>

        <!-- NOME -->
        <div class="sec-title"><span class="material-symbols-outlined" style="font-size:15px">person</span>Dados pessoais</div>

        <div class="campo mb-3">
          <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">person</span></span>
          <input type="text" name="nome" placeholder="Nome completo" required
                 value="<?= htmlspecialchars($p['nome'] ?? '') ?>"/>
        </div>

        <!-- E-MAIL INSTITUCIONAL (interno) -->
        <div id="campo-email-uern" class="campo mb-3" style="display:<?= ($p['vinculo']??'interno') !== 'externo' ? 'flex' : 'none' ?>">
          <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">school</span></span>
          <input type="email" name="email_uern" placeholder="E-mail institucional (@alu.uern.br)"
                 value="<?= htmlspecialchars($p['email_uern'] ?? '') ?>"/>
        </div>

        <!-- E-MAIL (externo) -->
        <div id="campo-email-ext" class="campo mb-3" style="display:<?= ($p['vinculo']??'interno') === 'externo' ? 'flex' : 'none' ?>">
          <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">mail</span></span>
          <input type="email" name="email_cad" placeholder="E-mail"
                 value="<?= htmlspecialchars($p['email_cad'] ?? '') ?>"/>
        </div>

        <!-- CPF (externo) / Ocupação (interno) -->
        <div id="campo-cpf" class="campo mb-3" style="display:<?= ($p['vinculo']??'interno') === 'externo' ? 'flex' : 'none' ?>">
          <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">credit_card</span></span>
          <input type="text" name="cpf" placeholder="CPF (000.000.000-00)" maxlength="14"
                 value="<?= htmlspecialchars($p['cpf'] ?? '') ?>" oninput="mascaraCPF(this)"/>
        </div>

        <div id="campo-ocupacao" class="campo mb-3" style="display:<?= ($p['vinculo']??'externo') !== 'externo' ? 'flex' : 'none' ?>">
          <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">work</span></span>
          <input type="text" name="ocupacao" placeholder="Curso / Cargo / Vínculo na UERN"
                 value="<?= htmlspecialchars($p['ocupacao'] ?? '') ?>"/>
        </div>

        <!-- SENHA -->
        <div class="campo mb-3">
          <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">lock</span></span>
          <input type="password" name="senha_cad" id="senha-cad" placeholder="Criar senha (mín. 6 caracteres)" required/>
          <button type="button" class="olho" onclick="toggleSenha('senha-cad',this)">
            <span class="material-symbols-outlined" style="font-size:18px">visibility</span>
          </button>
        </div>

        <!-- TELEFONE -->
        <div class="campo mb-3">
          <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">phone</span></span>
          <input type="tel" name="telefone" placeholder="WhatsApp / Telefone" required
                 value="<?= htmlspecialchars($p['telefone'] ?? '') ?>"/>
        </div>

        <!-- NASC + SEXO lado a lado -->
        <div class="grid grid-cols-2 gap-3 mb-3">
          <div class="campo">
            <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">cake</span></span>
            <input type="date" name="data_nasc" required
                   value="<?= htmlspecialchars($p['data_nasc'] ?? '') ?>" style="padding-right:8px"/>
          </div>
          <div class="campo">
            <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">wc</span></span>
            <select name="sexo" id="sexo-sel" onchange="toggleSexoOutro()" required>
              <option value="">Sexo / Gênero</option>
              <?php foreach (['Feminino','Masculino','Não-binário','Prefiro não dizer','outro'] as $s): ?>
              <option value="<?= $s ?>" <?= ($p['sexo']??'') === $s ? 'selected' : '' ?>><?= $s === 'outro' ? 'Outro...' : $s ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div id="sexo-outro-wrap" class="campo mb-3" style="display:none">
          <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">edit</span></span>
          <input type="text" name="sexo_outro" placeholder="Como prefere ser identificado(a)"
                 value="<?= htmlspecialchars($p['sexo_outro'] ?? '') ?>"/>
        </div>

        <!-- COMO CONHECEU -->
        <div class="sec-title"><span class="material-symbols-outlined" style="font-size:15px">campaign</span>Como conheceu o NUPICS?</div>
        <div class="flex flex-wrap gap-2 mb-3">
          <?php
          $opcoes_como = ['Amigos / colegas','Rede social','Professor(a)','Família','Divulgação na UERN','Evento','Site / internet'];
          $como_val    = $p['como_conheceu'] ?? '';
          foreach ($opcoes_como as $op): ?>
          <label class="pill-radio">
            <input type="radio" name="como_conheceu" value="<?= $op ?>"
                   onchange="document.getElementById('como-outro-wrap').style.display='none'"
                   <?= $como_val === $op ? 'checked' : '' ?>>
            <?= $op ?>
          </label>
          <?php endforeach; ?>
          <label class="pill-radio">
            <input type="radio" name="como_conheceu" value="outro"
                   onchange="document.getElementById('como-outro-wrap').style.display='flex'"
                   <?= $como_val === 'outro' ? 'checked' : '' ?>>
            Outro
          </label>
        </div>
        <div id="como-outro-wrap" class="campo mb-3"
             style="display:<?= $como_val === 'outro' ? 'flex' : 'none' ?>">
          <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">edit</span></span>
          <input type="text" name="como_outro" placeholder="Como você conheceu?"
                 value="<?= htmlspecialchars($p['como_outro'] ?? '') ?>"/>
        </div>

        <button type="submit" class="btn-primary mt-4">
          Continuar para Anamnese →
        </button>
        <p class="text-center text-sm text-on-surface-variant mt-4">
          Já tem conta? <a href="index.php" class="text-primary font-bold hover:underline">Entrar</a>
        </p>
      </form>

      <!-- ─────────────────── ETAPA 2 ─────────────────── -->
      <?php else: ?>

      <h2 class="text-xl font-extrabold text-primary mb-0.5">Anamnese prévia</h2>
      <p class="text-sm text-on-surface-variant mb-5">Etapa 2 de 2 · Saúde e objetivos</p>

      <form method="POST" action="index.php?modo=cadastro" novalidate>
        <input type="hidden" name="acao"       value="cadastro_final"/>
        <input type="hidden" name="etapa"      value="2"/>
        <!-- Re-emite campos da etapa 1 -->
        <?php foreach (['nome','vinculo','email_uern','email_cad','senha_cad','telefone','data_nasc','cpf','sexo','sexo_outro','ocupacao','como_conheceu','como_outro'] as $f): ?>
        <input type="hidden" name="<?= $f ?>" value="<?= htmlspecialchars($p[$f] ?? '') ?>"/>
        <?php endforeach; ?>

        <!-- SAÚDE -->
        <div class="sec-title"><span class="material-symbols-outlined" style="font-size:15px">health_and_safety</span>Condições de saúde</div>
        <?php
        $perguntas = [
          ['key'=>'doenca',     'label'=>'Possui alguma doença crônica ou diagnóstico médico?', 'qual'=>'Qual doença?'],
          ['key'=>'medicamento','label'=>'Faz uso de algum medicamento contínuo?',              'qual'=>'Qual medicamento?'],
          ['key'=>'alergia',    'label'=>'Possui alergias (alimentos, plantas, produtos)?',     'qual'=>'Descreva as alergias'],
          ['key'=>'trat_integ', 'label'=>'Já fez alguma prática integrativa anteriormente?',    'qual'=>'Qual prática?'],
        ];
        foreach ($perguntas as $pq):
          $val = $p[$pq['key']] ?? 'nao';
        ?>
        <div class="mb-4">
          <p class="text-xs font-semibold text-on-surface mb-2 leading-relaxed"><?= $pq['label'] ?></p>
          <div class="flex gap-2 mb-1">
            <label class="pill-radio"><input type="radio" name="<?= $pq['key'] ?>" value="nao"
                   <?= $val !== 'sim' ? 'checked' : '' ?>
                   onchange="toggleQual('<?= $pq['key'] ?>')">Não</label>
            <label class="pill-radio"><input type="radio" name="<?= $pq['key'] ?>" value="sim"
                   <?= $val === 'sim' ? 'checked' : '' ?>
                   onchange="toggleQual('<?= $pq['key'] ?>')">Sim</label>
          </div>
          <div id="qual-<?= $pq['key'] ?>" class="campo"
               style="display:<?= $val==='sim'?'flex':'none' ?>;margin-top:6px">
            <span class="ic"><span class="material-symbols-outlined" style="font-size:16px">edit_note</span></span>
            <input type="text" name="<?= $pq['key'] ?>_qual" placeholder="<?= $pq['qual'] ?>"
                   value="<?= htmlspecialchars($p[$pq['key'].'_qual'] ?? '') ?>"/>
          </div>
        </div>
        <?php endforeach; ?>

        <!-- OBJETIVOS (múltipla escolha) -->
        <div class="sec-title"><span class="material-symbols-outlined" style="font-size:15px">flag</span>Objetivos com o atendimento</div>
        <p class="text-xs text-on-surface-variant mb-2">Selecione quantos quiser</p>
        <div class="flex flex-wrap gap-2 mb-2">
          <?php
          $obj_lista  = ['Redução do estresse','Ansiedade','Dor física','Qualidade do sono',
                         'Autoconhecimento','Equilíbrio emocional','Relaxamento','Espiritualidade'];
          $obj_selecionados = (array)($p['objetivos'] ?? []);
          foreach ($obj_lista as $ob): ?>
          <label class="pill-check">
            <input type="checkbox" name="objetivos[]" value="<?= $ob ?>"
                   <?= in_array($ob, $obj_selecionados) ? 'checked' : '' ?>>
            <?= $ob ?>
          </label>
          <?php endforeach; ?>
          <label class="pill-check">
            <input type="checkbox" name="objetivos[]" value="__outro__"
                   <?= in_array('__outro__', $obj_selecionados) ? 'checked' : '' ?>
                   onchange="document.getElementById('obj-outro-wrap').style.display=this.checked?'flex':'none'">
            Outro
          </label>
        </div>
        <div id="obj-outro-wrap" class="campo mb-3"
             style="display:<?= in_array('__outro__',$obj_selecionados)?'flex':'none' ?>">
          <span class="ic"><span class="material-symbols-outlined" style="font-size:16px">edit_note</span></span>
          <input type="text" name="objetivo_outro" placeholder="Descreva seu objetivo"
                 value="<?= htmlspecialchars($p['objetivo_outro'] ?? '') ?>"/>
        </div>

        <!-- ESCALAS DE SAÚDE -->
        <div class="sec-title"><span class="material-symbols-outlined" style="font-size:15px">monitor_heart</span>Escalas de saúde</div>

        <p class="text-xs font-semibold text-on-surface mb-1">Como você se sente atualmente?
          <span class="font-normal text-on-surface-variant">(0 = muito mal · 10 = ótimo)</span></p>
        <div class="flex gap-1.5 flex-wrap mb-4">
          <?php for ($i=0;$i<=10;$i++): ?>
          <label class="be-btn">
            <input type="radio" name="bem_estar" value="<?= $i ?>"
                   <?= ($p['bem_estar']??'') == $i ? 'checked':'' ?>>
            <?= $i ?>
          </label>
          <?php endfor; ?>
        </div>

        <p class="text-xs font-semibold text-on-surface mb-1">Nível de dor crônica (se houver)?
          <span class="font-normal text-on-surface-variant">(0 = sem dor · 10 = intensa)</span></p>
        <div class="flex gap-1.5 flex-wrap mb-4">
          <?php for ($i=0;$i<=10;$i++): ?>
          <label class="be-btn">
            <input type="radio" name="nivel_dor" value="<?= $i ?>"
                   <?= ($p['nivel_dor']??'') == $i ? 'checked':'' ?>>
            <?= $i ?>
          </label>
          <?php endfor; ?>
        </div>

        <!-- Sono + Atividade física lado a lado -->
        <div class="grid grid-cols-2 gap-3 mb-4">
          <div>
            <p class="text-xs font-semibold text-on-surface mb-2">Qualidade do sono</p>
            <div class="flex flex-col gap-1.5">
              <?php foreach (['boa'=>'😴 Boa','regular'=>'😐 Regular','ruim'=>'😩 Ruim'] as $v=>$l): ?>
              <label class="pill-radio text-xs">
                <input type="radio" name="qualidade_sono" value="<?= $v ?>"
                       <?= ($p['qualidade_sono']??'')===$v?'checked':'' ?>>
                <?= $l ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div>
            <p class="text-xs font-semibold text-on-surface mb-2">Atividade física</p>
            <div class="flex flex-col gap-1.5">
              <?php foreach (['sedentario'=>'🛋️ Sedentário','leve'=>'🚶 Leve','moderado'=>'🏃 Moderado','intenso'=>'💪 Intenso'] as $v=>$l): ?>
              <label class="pill-radio text-xs">
                <input type="radio" name="atividade_fisica" value="<?= $v ?>"
                       <?= ($p['atividade_fisica']??'')===$v?'checked':'' ?>>
                <?= $l ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- CONSENTIMENTO -->
        <div class="sec-title"><span class="material-symbols-outlined" style="font-size:15px">gavel</span>Consentimento</div>
        <label class="flex items-start gap-3 cursor-pointer mb-5 p-4 rounded-2xl border-2 border-outline-variant hover:border-primary transition-colors"
               id="consent-label">
          <input type="checkbox" name="consentimento" value="1" id="consent-check"
                 <?= isset($p['consentimento'])?'checked':'' ?>
                 onchange="document.getElementById('consent-label').style.borderColor=this.checked?'#4e0078':'#d0c2d3'"
                 class="mt-0.5 w-4 h-4 accent-primary shrink-0">
          <span class="text-sm text-on-surface leading-relaxed">
            Li e concordo com os
            <button type="button" onclick="abrirModalTermo()"
                    class="text-primary font-bold hover:underline">Termos de Consentimento</button>
            e estou ciente de que as práticas integrativas não substituem tratamento médico.
          </span>
        </label>

        <div class="flex gap-3">
          <a href="index.php?modo=cadastro&etapa=1" class="btn-outline" style="width:auto;padding:13px 20px;flex-shrink:0">
            ← Voltar
          </a>
          <button type="submit" class="btn-primary">Criar minha conta</button>
        </div>

        <p class="text-center text-sm text-on-surface-variant mt-4">
          Já tem conta? <a href="index.php" class="text-primary font-bold hover:underline">Entrar</a>
        </p>
      </form>
      <?php endif; ?>

    </div><!-- /card cadastro -->
    <?php endif; ?>

  </div><!-- /max-w -->
</div>

<!-- ═══ MODAL: Termo de Consentimento ═══ -->
<div class="modal-overlay" id="modal-termo">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-titulo">Termo de Consentimento</span>
      <button class="modal-fechar" onclick="fecharModalTermo()">✕</button>
    </div>
    <div class="modal-corpo">
      <p><strong>TERMO DE CONSENTIMENTO E USO DE DADOS</strong></p>
      <p>Ao realizar seu cadastro no <strong>Nupics – Núcleo de Práticas Integrativas e Complementares em Saúde</strong>, você declara que leu, compreendeu e concorda com os termos abaixo:</p>
      <p><strong>1. Finalidade do serviço</strong><br>O Nupics tem como objetivo facilitar o agendamento e o acesso a práticas integrativas e complementares em saúde, promovendo bem-estar físico, mental e emocional.</p>
      <p><strong>2. Natureza dos atendimentos</strong><br>As práticas integrativas e complementares não substituem acompanhamento médico, psicológico ou odontológico convencional, sendo utilizadas como suporte ao cuidado em saúde.</p>
      <p><strong>3. Coleta e uso de dados</strong><br>Os dados fornecidos no cadastro (pessoais e de saúde) serão utilizados exclusivamente para identificação do paciente, organização de atendimentos e agendamentos e adequação das práticas ofertadas. Seus dados serão tratados de forma confidencial, em conformidade com a <strong>Lei Geral de Proteção de Dados (LGPD – Lei nº 13.709/2018)</strong>.</p>
      <p><strong>4. Compartilhamento de informações</strong><br>Seus dados poderão ser acessados apenas por profissionais envolvidos no atendimento, sendo vedado qualquer uso para fins comerciais ou não autorizados.</p>
      <p><strong>5. Responsabilidade do usuário</strong><br>Você se compromete a fornecer informações verdadeiras e atualizadas, sendo responsável por qualquer omissão relevante que possa impactar seu atendimento.</p>
      <p><strong>6. Consentimento informado</strong><br>Ao aceitar este termo, você declara estar ciente sobre a natureza das práticas integrativas e autoriza a utilização dos seus dados para os fins descritos.</p>
      <p><strong>7. Direito de revogação</strong><br>Você poderá, a qualquer momento, solicitar a exclusão dos seus dados ou revogar este consentimento, mediante solicitação pelos canais oficiais do sistema.</p>
      <div class="modal-rodape">
        <strong>Universidade do Estado do Rio Grande do Norte – UERN</strong><br>
        Nupics Caicó · Núcleo de Práticas Integrativas e Complementares em Saúde<br>
        Administrador: <a href="mailto:jose20230067204@alu.uern.br" class="text-primary">jose20230067204@alu.uern.br</a>
      </div>
    </div>
    <div style="padding:0 1.5rem 1.5rem">
      <button class="btn-primary" onclick="aceitarTermo()">Entendi e concordo ✓</button>
    </div>
  </div>
</div>

<script>
// ── Toggle senha ──────────────────────────────────
function toggleSenha(id, btn) {
  var el = document.getElementById(id);
  el.type = el.type === 'password' ? 'text' : 'password';
  btn.querySelector('.material-symbols-outlined').textContent =
    el.type === 'text' ? 'visibility_off' : 'visibility';
}

// ── Vínculo ───────────────────────────────────────
function setVinculo(v) {
  document.getElementById('vinculo-val').value = v;
  document.getElementById('btn-interno').classList.toggle('ativo', v === 'interno');
  document.getElementById('btn-externo').classList.toggle('ativo', v === 'externo');
  document.getElementById('campo-email-uern').style.display = v === 'interno' ? 'flex' : 'none';
  document.getElementById('campo-email-ext').style.display  = v === 'externo' ? 'flex' : 'none';
  document.getElementById('campo-cpf').style.display        = v === 'externo' ? 'flex' : 'none';
  document.getElementById('campo-ocupacao').style.display   = v === 'interno' ? 'flex' : 'none';
  document.getElementById('vinculo-desc').textContent = v === 'externo'
    ? 'Moradores de Caicó e região sem vínculo com a UERN. Identificação por CPF.'
    : 'Estudantes, professores e servidores da UERN. Identificação pelo e-mail institucional.';
}

// ── Sexo "outro" ──────────────────────────────────
function toggleSexoOutro() {
  var v = document.getElementById('sexo-sel')?.value;
  var w = document.getElementById('sexo-outro-wrap');
  if (w) w.style.display = v === 'outro' ? 'flex' : 'none';
}

// ── Perguntas sim/não ─────────────────────────────
function toggleQual(key) {
  var radios = document.querySelectorAll('input[name="' + key + '"]');
  var sim = false;
  radios.forEach(function(r) { if (r.value === 'sim' && r.checked) sim = true; });
  var el = document.getElementById('qual-' + key);
  if (el) el.style.display = sim ? 'flex' : 'none';
}

// ── Máscara CPF ───────────────────────────────────
function mascaraCPF(el) {
  var v = el.value.replace(/\D/g,'').substring(0,11);
  if (v.length > 9) v = v.replace(/^(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
  else if (v.length > 6) v = v.replace(/^(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
  else if (v.length > 3) v = v.replace(/^(\d{3})(\d{1,3})/, '$1.$2');
  el.value = v;
}

// ── Modal termo ───────────────────────────────────
function abrirModalTermo()  { document.getElementById('modal-termo').classList.add('open'); }
function fecharModalTermo() { document.getElementById('modal-termo').classList.remove('open'); }
function aceitarTermo() {
  var c = document.getElementById('consent-check');
  if (c) { c.checked = true; document.getElementById('consent-label').style.borderColor='#4e0078'; }
  fecharModalTermo();
}

// ── Inicializa estados (após repost por erro) ─────
(function() {
  toggleSexoOutro();
  <?php if ($modo === 'cadastro' && $etapa === 2): ?>
  <?php foreach ($perguntas ?? [] as $pq): ?>
  toggleQual('<?= $pq['key'] ?>');
  <?php endforeach; ?>
  <?php endif; ?>
})();
</script>
</body>
</html>