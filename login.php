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
$modo    = $_GET['modo'] ?? 'login';
$etapa   = (int)($_POST['etapa'] ?? $_GET['etapa'] ?? 1);

// ── LOGIN ────────────────────────────────────────────────────────────────────
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
        $_SESSION['foto']       = $usuario['foto'] ?? '';
        if ($usuario['tipo'] === 'paciente')        header('Location: paciente/dashboard.php');
        elseif ($usuario['tipo'] === 'coordenador') header('Location: coordenacao/dashboard.php');
        else                                        header('Location: terapeuta/dashboard.php');
        exit;
    }
    $erro = 'E-mail ou senha incorretos.';
    $modo = 'login';
}

// ── CADASTRO ETAPA 1 ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastro_etapa1') {
    $modo  = 'cadastro';
    $etapa = 2;
}

// ── CADASTRO ETAPA 2 ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastro_final') {
    require_once 'config/db.php';
    $modo  = 'cadastro';
    $etapa = 2;

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

    $doenca           = $_POST['doenca']           ?? 'nao';
    $doenca_qual      = trim($_POST['doenca_qual'] ?? '');
    $medicamento      = $_POST['medicamento']      ?? 'nao';
    $medicamento_qual = trim($_POST['medicamento_qual'] ?? '');
    $alergia          = $_POST['alergia']          ?? 'nao';
    $alergia_qual     = trim($_POST['alergia_qual'] ?? '');
    $trat_integ       = $_POST['trat_integ']       ?? 'nao';
    $trat_integ_qual  = trim($_POST['trat_integ_qual'] ?? '');
    $objetivos_arr    = $_POST['objetivos']        ?? [];
    $objetivo_outro   = trim($_POST['objetivo_outro'] ?? '');
    $bem_estar        = trim($_POST['bem_estar']   ?? '');
    $nivel_dor        = trim($_POST['nivel_dor']   ?? '');
    $qualidade_sono   = trim($_POST['qualidade_sono'] ?? '');
    $ativ_fisica      = trim($_POST['atividade_fisica'] ?? '');
    $consentimento    = isset($_POST['consentimento']) ? 1 : 0;

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
            $objetivos_list = $objetivos_arr;
            if ($objetivo_outro) $objetivos_list[] = $objetivo_outro;
            $objetivos_str = implode(',', array_filter(array_map('trim', $objetivos_list)));

            $obs = [];
            if ($doenca === 'sim' && $doenca_qual)           $obs[] = "Doença: $doenca_qual";
            if ($medicamento === 'sim' && $medicamento_qual) $obs[] = "Medicamentos: $medicamento_qual";
            if ($alergia === 'sim' && $alergia_qual)         $obs[] = "Alergias: $alergia_qual";
            if ($trat_integ === 'sim' && $trat_integ_qual)   $obs[] = "Trat. integrativo anterior: $trat_integ_qual";
            if ($bem_estar)      $obs[] = "Bem-estar atual: $bem_estar/10";
            if ($nivel_dor)      $obs[] = "Nível de dor: $nivel_dor/10";
            if ($qualidade_sono) $obs[] = "Sono: $qualidade_sono";
            if ($ativ_fisica)    $obs[] = "Atividade física: $ativ_fisica";
            $observacao = implode(' | ', $obs);

            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO usuarios (nome, email, senha, tipo, telefone) VALUES (?,?,?,"paciente",?)')
                ->execute([$nome, $email_final, $hash, $telefone]);
            $uid = $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO pacientes (usuario_id, cpf, data_nasc) VALUES (?,?,?)')
                ->execute([$uid, $cpf ?: null, $data_nasc]);

            $pdo->prepare('
                UPDATE pacientes SET sexo=?, observacao_clinica=?, consentimento=1, vinculo=?,
                    email_uern=?, objetivos=?, como_conheceu=?, ocupacao=?,
                    nivel_dor=?, qualidade_sono=?, atividade_fisica=?
                WHERE usuario_id=?
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

$p = $_POST;
$perguntas = [
    ['key'=>'doenca',     'label'=>'Possui alguma doença crônica ou diagnóstico médico?', 'qual'=>'Qual doença?'],
    ['key'=>'medicamento','label'=>'Faz uso de algum medicamento contínuo?',              'qual'=>'Qual medicamento?'],
    ['key'=>'alergia',    'label'=>'Possui alergias (alimentos, plantas, produtos)?',     'qual'=>'Descreva as alergias'],
    ['key'=>'trat_integ', 'label'=>'Já fez alguma prática integrativa anteriormente?',    'qual'=>'Qual prática?'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>NUPICS Caicó — <?= $modo === 'login' ? 'Entrar' : 'Cadastrar' ?></title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@400,0&display=swap" rel="stylesheet"/>
<style>
*,*::before,*::after{box-sizing:border-box}
body{font-family:'Manrope',sans-serif;min-height:100vh;margin:0;overflow-x:hidden;background:#f4d9ff}
h1,h2,h3,h4{font-family:'Plus Jakarta Sans',sans-serif}
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}

/* ── Split layout ── */
.split-left{
  position:fixed;left:0;top:0;bottom:0;width:50%;
  overflow:hidden;
}
.split-left img{width:100%;height:100%;object-fit:cover}
.split-right{
  margin-left:50%;min-height:100vh;
  display:flex;align-items:flex-start;justify-content:center;
  background:radial-gradient(circle at 30% 0%,#f4d9ff 0%,transparent 55%),
             radial-gradient(circle at 80% 100%,#ffd9de 0%,transparent 55%),
             #fff7fc;
  padding:32px 24px 40px;
}
@media(max-width:1023px){
  .split-left{display:none}
  .split-right{margin-left:0;background:
    url('uploads/logo/fundo.png') center/cover no-repeat fixed,
    radial-gradient(circle at 30% 0%,#f4d9ff,transparent 60%),#fff7fc}
}

/* ── Glass card ── */
.glass-card{
  background:rgba(255,255,255,.78);
  backdrop-filter:blur(28px) saturate(180%);
  -webkit-backdrop-filter:blur(28px) saturate(180%);
  border:1px solid rgba(255,255,255,.6);
  border-radius:2.5rem;
  width:100%;max-width:440px;
  padding:44px 40px;
  box-shadow:0 24px 60px rgba(32,25,35,.1);
}
@media(max-width:479px){.glass-card{padding:32px 24px;border-radius:2rem}}

/* ── Campo ── */
.campo{position:relative;display:flex;align-items:center;
       background:rgba(255,255,255,.6);
       border:1.5px solid rgba(208,194,211,.7);
       border-radius:99px;overflow:hidden;transition:.2s}
.campo:focus-within{border-color:#4e0078;box-shadow:0 0 0 3px rgba(78,0,120,.12);background:rgba(255,255,255,.85)}
.campo .ic{padding:0 14px;color:#7f7383;display:flex;align-items:center;flex-shrink:0}
.campo input,.campo select,.campo textarea{
  flex:1;border:none;background:transparent;padding:14px 14px 14px 0;
  font-size:.875rem;color:#201923;font-family:'Manrope',sans-serif;outline:none;min-width:0}
.campo-rect{border-radius:14px!important}
.campo select{cursor:pointer}
.campo .olho{padding:0 14px;background:none;border:none;cursor:pointer;color:#aaa;display:flex}

/* ── Btn ── */
.btn-primary{width:100%;padding:15px;border-radius:99px;
  background:linear-gradient(135deg,#6a1b9a,#b7004d);color:#fff;
  font-weight:700;font-size:.95rem;border:none;cursor:pointer;
  font-family:'Manrope',sans-serif;transition:opacity .15s,transform .1s;
  box-shadow:0 8px 24px rgba(78,0,120,.25)}
.btn-primary:hover{opacity:.92}
.btn-primary:active{transform:scale(.98)}
.btn-outline{width:100%;padding:13px;border-radius:99px;
  border:2px solid #d0c2d3;background:rgba(255,255,255,.5);
  color:#4d4351;font-weight:700;font-size:.9rem;cursor:pointer;
  font-family:'Manrope',sans-serif;transition:.15s}
.btn-outline:hover{border-color:#4e0078;color:#4e0078}

/* ── Vínculo ── */
.vinculo-btn{flex:1;padding:10px;border-radius:12px;font-size:.8rem;font-weight:600;
  text-align:center;cursor:pointer;transition:.15s;border:2px solid transparent;
  background:rgba(255,255,255,.5);color:#4d4351}
.vinculo-btn.ativo{background:#4e0078;color:#fff;border-color:#4e0078}

/* ── Pills ── */
.pill-check,.pill-radio{display:inline-flex;align-items:center;gap:6px;
  padding:7px 14px;border-radius:99px;border:1.5px solid #d0c2d3;
  font-size:.78rem;font-weight:600;cursor:pointer;transition:.15s;
  background:rgba(255,255,255,.6);color:#4d4351;user-select:none}
.pill-check:has(input:checked),.pill-radio:has(input:checked){
  background:#4e0078;color:#fff;border-color:#4e0078}
.pill-check input,.pill-radio input{display:none}

/* ── Be-btn (escalas) ── */
.be-btn{width:2.2rem;height:2.2rem;border-radius:50%;display:flex;
  align-items:center;justify-content:center;font-size:.75rem;font-weight:700;
  border:1.5px solid #d0c2d3;cursor:pointer;transition:.15s;
  background:rgba(255,255,255,.6);color:#4d4351}
.be-btn:has(input:checked){background:#4e0078;color:#fff;border-color:#4e0078}
.be-btn input{display:none}

/* ── Stepper ── */
.step-dot{width:2rem;height:2rem;border-radius:50%;display:flex;
  align-items:center;justify-content:center;font-size:.75rem;font-weight:700;transition:.3s}
.step-dot.done,.step-dot.active{background:#4e0078;color:#fff}
.step-dot.active{box-shadow:0 0 0 4px rgba(78,0,120,.18)}
.step-dot.idle{background:#ecdeed;color:#7f7383}

/* ── Seção título ── */
.sec-title{font-size:.7rem;font-weight:700;text-transform:uppercase;
  letter-spacing:.07em;color:#7f7383;margin:1.2rem 0 .6rem;
  display:flex;align-items:center;gap:.4rem}
.sec-title::after{content:'';flex:1;height:1px;background:#ecdeed}

/* ── Alertas ── */
.alerta-erro{background:#ffdad6;color:#93000a;border-radius:14px;
  padding:.75rem 1rem;font-size:.85rem;font-weight:600}
.alerta-sucesso{background:#d1fae5;color:#065f46;border-radius:14px;
  padding:.75rem 1rem;font-size:.85rem;font-weight:600}

/* ── Modal ── */
.modal-overlay{display:none;position:fixed;inset:0;z-index:200;
  background:rgba(78,0,120,.25);backdrop-filter:blur(4px);
  align-items:center;justify-content:center;padding:1rem}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:1.5rem;max-width:560px;width:100%;
  max-height:88vh;display:flex;flex-direction:column;
  box-shadow:0 24px 60px rgba(0,0,0,.18)}
.modal-header{display:flex;align-items:center;justify-content:space-between;
  padding:1.25rem 1.5rem;border-bottom:1px solid #ecdeed;flex-shrink:0}
.modal-titulo{font-size:1rem;font-weight:700;color:#4e0078;
  font-family:'Plus Jakarta Sans',sans-serif}
.modal-fechar{background:none;border:none;font-size:1.1rem;cursor:pointer;
  color:#7f7383;padding:.25rem .4rem;border-radius:8px}
.modal-corpo{overflow-y:auto;padding:1.25rem 1.5rem;
  font-size:.83rem;color:#201923;line-height:1.7;flex:1}
.modal-corpo p{margin-bottom:.75rem}
.modal-rodape{margin-top:1rem;padding-top:1rem;border-top:1px solid #ecdeed;
  font-size:.78rem;color:#7f7383}

/* ── Divider ── */
.divider{display:flex;align-items:center;gap:10px;margin:20px 0}
.divider span{flex:1;height:1px;background:rgba(208,194,211,.5)}
.divider p{font-size:.68rem;font-weight:700;text-transform:uppercase;
  letter-spacing:.1em;color:#7f7383;white-space:nowrap}

::-webkit-scrollbar{width:5px}
::-webkit-scrollbar-thumb{background:#d0c2d3;border-radius:99px}
</style>
</head>
<body>

<!-- Lado esquerdo: imagem fundo.png (desktop) -->
<div class="split-left">
  <img src="uploads/logo/fundo.png" alt="NUPICS" />
  <!-- Aura suave por cima da imagem -->
  <div style="position:absolute;inset:0;background:linear-gradient(135deg,rgba(78,0,120,.08),rgba(183,0,77,.05))"></div>
</div>

<!-- Lado direito: formulário -->
<div class="split-right">
  <div class="glass-card">

    <!-- Logo -->
    <div class="flex flex-col items-center mb-8 text-center">
      <img src="uploads/logo/lotus.png" alt="NUPICS" style="height:52px;width:52px;object-fit:contain;margin-bottom:10px;filter:drop-shadow(0 4px 12px rgba(78,0,120,.2))"/>
      <h1 style="font-size:1.6rem;font-weight:800;background:linear-gradient(135deg,#4e0078,#b7004d);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin:0 0 3px">NUPICS Caicó</h1>
      <p style="font-size:.78rem;color:#7f7383;font-weight:500">Práticas Integrativas e Complementares · UERN</p>
    </div>

    <?php if ($modo === 'login'): ?>
    <!-- ══════════════ LOGIN ══════════════ -->

    <?php if ($sucesso): ?><div class="alerta-sucesso mb-5"><?= htmlspecialchars($sucesso) ?></div><?php endif; ?>
    <?php if ($erro):    ?><div class="alerta-erro mb-5"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

    <form method="POST" action="login.php" class="space-y-4">
      <input type="hidden" name="acao" value="login"/>

      <div>
        <label style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(78,0,120,.8);display:block;margin-bottom:6px;margin-left:16px">E-mail</label>
        <div class="campo">
          <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">mail</span></span>
          <input type="email" name="email" placeholder="seu@email.com" required
                 value="<?= htmlspecialchars($p['email'] ?? '') ?>"/>
        </div>
      </div>

      <div>
        <label style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(78,0,120,.8);display:block;margin-bottom:6px;margin-left:16px">Senha</label>
        <div class="campo">
          <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">lock</span></span>
          <input type="password" name="senha" id="senha-login" placeholder="••••••••" required/>
          <button type="button" class="olho" onclick="toggleSenha('senha-login',this)">
            <span class="material-symbols-outlined" style="font-size:18px">visibility</span>
          </button>
        </div>
      </div>

      <div style="display:flex;justify-content:space-between;padding:0 4px">
        <a href="#" style="font-size:.78rem;font-weight:600;color:#b7004d;text-decoration:none">Esqueci minha senha</a>
        <a href="login.php?modo=cadastro" style="font-size:.78rem;font-weight:600;color:#4e0078;text-decoration:none">Cadastre-se</a>
      </div>

      <div style="padding-top:8px">
        <button type="submit" class="btn-primary">Entrar</button>
      </div>
    </form>

    <div class="divider"><span></span><p>Autenticação Segura</p><span></span></div>

    <p style="text-align:center;font-size:.78rem;color:#7f7383">
      Não tem conta?
      <a href="login.php?modo=cadastro" style="color:#4e0078;font-weight:700;text-decoration:none">Criar cadastro gratuito</a>
    </p>

    <?php else: ?>
    <!-- ══════════════ CADASTRO ══════════════ -->

    <!-- Stepper -->
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:24px">
      <div class="step-dot <?= $etapa >= 1 ? 'done' : 'idle' ?>">1</div>
      <span style="font-size:.72rem;font-weight:600;color:<?= $etapa===1?'#4e0078':'#7f7383' ?>">Dados pessoais</span>
      <div style="flex:1;height:2px;border-radius:99px;background:<?= $etapa >= 2 ? '#4e0078' : '#ecdeed' ?>"></div>
      <div class="step-dot <?= $etapa >= 2 ? 'active' : 'idle' ?>">2</div>
      <span style="font-size:.72rem;font-weight:600;color:<?= $etapa===2?'#4e0078':'#7f7383' ?>">Anamnese</span>
    </div>

    <?php if ($erro): ?><div class="alerta-erro mb-4"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

    <?php if ($etapa === 1): ?>
    <!-- ETAPA 1 -->
    <h2 style="font-size:1.2rem;font-weight:800;color:#4e0078;margin:0 0 2px">Criar conta</h2>
    <p style="font-size:.82rem;color:#7f7383;margin:0 0 20px">Etapa 1 de 2 · Dados pessoais</p>

    <form method="POST" action="login.php?modo=cadastro" id="form-etapa1" novalidate>
      <input type="hidden" name="acao" value="cadastro_etapa1"/>
      <input type="hidden" name="etapa" value="1"/>

      <div class="sec-title"><span class="material-symbols-outlined" style="font-size:14px">badge</span>Vínculo com a UERN</div>
      <div style="display:flex;gap:8px;margin-bottom:8px">
        <button type="button" class="vinculo-btn <?= ($p['vinculo']??'') !== 'externo' ? 'ativo' : '' ?>" id="btn-interno" onclick="setVinculo('interno')">🎓 Interno (UERN)</button>
        <button type="button" class="vinculo-btn <?= ($p['vinculo']??'') === 'externo' ? 'ativo' : '' ?>" id="btn-externo" onclick="setVinculo('externo')">🏙️ Externo (Comunidade)</button>
      </div>
      <input type="hidden" name="vinculo" id="vinculo-val" value="<?= htmlspecialchars($p['vinculo'] ?? 'interno') ?>"/>
      <p id="vinculo-desc" style="font-size:.73rem;color:#7f7383;margin:0 0 14px;line-height:1.5">
        <?= ($p['vinculo']??'externo') === 'externo'
          ? 'Moradores de Caicó e região sem vínculo com a UERN.'
          : 'Estudantes, professores e servidores da UERN.' ?>
      </p>

      <div class="sec-title"><span class="material-symbols-outlined" style="font-size:14px">person</span>Dados pessoais</div>

      <div class="campo campo-rect" style="margin-bottom:10px">
        <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">person</span></span>
        <input type="text" name="nome" placeholder="Nome completo" required value="<?= htmlspecialchars($p['nome'] ?? '') ?>"/>
      </div>

      <div id="campo-email-uern" class="campo campo-rect" style="margin-bottom:10px;display:<?= ($p['vinculo']??'interno') !== 'externo' ? 'flex' : 'none' ?>">
        <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">school</span></span>
        <input type="email" name="email_uern" placeholder="E-mail institucional (@alu.uern.br)" value="<?= htmlspecialchars($p['email_uern'] ?? '') ?>"/>
      </div>

      <div id="campo-email-ext" class="campo campo-rect" style="margin-bottom:10px;display:<?= ($p['vinculo']??'interno') === 'externo' ? 'flex' : 'none' ?>">
        <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">mail</span></span>
        <input type="email" name="email_cad" placeholder="E-mail" value="<?= htmlspecialchars($p['email_cad'] ?? '') ?>"/>
      </div>

      <div id="campo-cpf" class="campo campo-rect" style="margin-bottom:10px;display:<?= ($p['vinculo']??'interno') === 'externo' ? 'flex' : 'none' ?>">
        <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">credit_card</span></span>
        <input type="text" name="cpf" placeholder="CPF (000.000.000-00)" maxlength="14" value="<?= htmlspecialchars($p['cpf'] ?? '') ?>" oninput="mascaraCPF(this)"/>
      </div>

      <div id="campo-ocupacao" class="campo campo-rect" style="margin-bottom:10px;display:<?= ($p['vinculo']??'externo') !== 'externo' ? 'flex' : 'none' ?>">
        <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">work</span></span>
        <input type="text" name="ocupacao" placeholder="Curso / Cargo na UERN" value="<?= htmlspecialchars($p['ocupacao'] ?? '') ?>"/>
      </div>

      <div class="campo campo-rect" style="margin-bottom:10px">
        <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">lock</span></span>
        <input type="password" name="senha_cad" id="senha-cad" placeholder="Criar senha (mín. 6 caracteres)" required/>
        <button type="button" class="olho" onclick="toggleSenha('senha-cad',this)">
          <span class="material-symbols-outlined" style="font-size:18px">visibility</span>
        </button>
      </div>

      <div class="campo campo-rect" style="margin-bottom:10px">
        <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">phone</span></span>
        <input type="tel" name="telefone" placeholder="WhatsApp / Telefone" required value="<?= htmlspecialchars($p['telefone'] ?? '') ?>"/>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px">
        <div class="campo campo-rect">
          <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">cake</span></span>
          <input type="date" name="data_nasc" required value="<?= htmlspecialchars($p['data_nasc'] ?? '') ?>" style="padding-right:8px"/>
        </div>
        <div class="campo campo-rect">
          <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">wc</span></span>
          <select name="sexo" id="sexo-sel" onchange="toggleSexoOutro()" required>
            <option value="">Sexo / Gênero</option>
            <?php foreach (['Feminino','Masculino','Não-binário','Prefiro não dizer','outro'] as $s): ?>
            <option value="<?= $s ?>" <?= ($p['sexo']??'') === $s ? 'selected' : '' ?>><?= $s === 'outro' ? 'Outro...' : $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div id="sexo-outro-wrap" class="campo campo-rect" style="display:none;margin-bottom:10px">
        <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">edit</span></span>
        <input type="text" name="sexo_outro" placeholder="Como prefere ser identificado(a)" value="<?= htmlspecialchars($p['sexo_outro'] ?? '') ?>"/>
      </div>

      <div class="sec-title"><span class="material-symbols-outlined" style="font-size:14px">campaign</span>Como conheceu o NUPICS?</div>
      <div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:10px">
        <?php foreach (['Amigos / colegas','Rede social','Professor(a)','Família','Divulgação na UERN','Site / internet'] as $op): ?>
        <label class="pill-radio"><input type="radio" name="como_conheceu" value="<?= $op ?>" onchange="document.getElementById('como-outro-wrap').style.display='none'" <?= ($p['como_conheceu']??'')===$op?'checked':'' ?>><?= $op ?></label>
        <?php endforeach; ?>
        <label class="pill-radio"><input type="radio" name="como_conheceu" value="outro" onchange="document.getElementById('como-outro-wrap').style.display='flex'" <?= ($p['como_conheceu']??'')==='outro'?'checked':'' ?>>Outro</label>
      </div>
      <div id="como-outro-wrap" class="campo campo-rect" style="display:<?= ($p['como_conheceu']??'')==='outro'?'flex':'none' ?>;margin-bottom:14px">
        <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">edit</span></span>
        <input type="text" name="como_outro" placeholder="Como você conheceu?" value="<?= htmlspecialchars($p['como_outro'] ?? '') ?>"/>
      </div>

      <button type="submit" class="btn-primary" style="margin-top:8px">Continuar para Anamnese →</button>
      <p style="text-align:center;font-size:.8rem;color:#7f7383;margin-top:14px">
        Já tem conta? <a href="login.php" style="color:#4e0078;font-weight:700;text-decoration:none">Entrar</a>
      </p>
    </form>

    <?php else: ?>
    <!-- ETAPA 2 -->
    <h2 style="font-size:1.2rem;font-weight:800;color:#4e0078;margin:0 0 2px">Anamnese prévia</h2>
    <p style="font-size:.82rem;color:#7f7383;margin:0 0 18px">Etapa 2 de 2 · Saúde e objetivos</p>

    <form method="POST" action="login.php?modo=cadastro" novalidate>
      <input type="hidden" name="acao" value="cadastro_final"/>
      <input type="hidden" name="etapa" value="2"/>
      <?php foreach (['nome','vinculo','email_uern','email_cad','senha_cad','telefone','data_nasc','cpf','sexo','sexo_outro','ocupacao','como_conheceu','como_outro'] as $f): ?>
      <input type="hidden" name="<?= $f ?>" value="<?= htmlspecialchars($p[$f] ?? '') ?>"/>
      <?php endforeach; ?>

      <div class="sec-title"><span class="material-symbols-outlined" style="font-size:14px">health_and_safety</span>Condições de saúde</div>
      <?php foreach ($perguntas as $pq):
        $val = $p[$pq['key']] ?? 'nao';
      ?>
      <div style="margin-bottom:14px">
        <p style="font-size:.78rem;font-weight:600;color:#201923;margin:0 0 6px;line-height:1.4"><?= $pq['label'] ?></p>
        <div style="display:flex;gap:8px;margin-bottom:4px">
          <label class="pill-radio"><input type="radio" name="<?= $pq['key'] ?>" value="nao" <?= $val !== 'sim' ? 'checked' : '' ?> onchange="toggleQual('<?= $pq['key'] ?>')">Não</label>
          <label class="pill-radio"><input type="radio" name="<?= $pq['key'] ?>" value="sim" <?= $val === 'sim' ? 'checked' : '' ?> onchange="toggleQual('<?= $pq['key'] ?>')">Sim</label>
        </div>
        <div id="qual-<?= $pq['key'] ?>" class="campo campo-rect" style="display:<?= $val==='sim'?'flex':'none' ?>;margin-top:6px">
          <span class="ic"><span class="material-symbols-outlined" style="font-size:16px">edit_note</span></span>
          <input type="text" name="<?= $pq['key'] ?>_qual" placeholder="<?= $pq['qual'] ?>" value="<?= htmlspecialchars($p[$pq['key'].'_qual'] ?? '') ?>"/>
        </div>
      </div>
      <?php endforeach; ?>

      <div class="sec-title"><span class="material-symbols-outlined" style="font-size:14px">flag</span>Objetivos com o atendimento</div>
      <p style="font-size:.72rem;color:#7f7383;margin:0 0 8px">Selecione quantos quiser</p>
      <div style="display:flex;flex-wrap:wrap;gap:7px;margin-bottom:8px">
        <?php
        $obj_lista = ['Redução do estresse','Ansiedade','Dor física','Qualidade do sono','Autoconhecimento','Equilíbrio emocional','Relaxamento','Espiritualidade'];
        $obj_sel   = (array)($p['objetivos'] ?? []);
        foreach ($obj_lista as $ob): ?>
        <label class="pill-check"><input type="checkbox" name="objetivos[]" value="<?= $ob ?>" <?= in_array($ob,$obj_sel)?'checked':'' ?>><?= $ob ?></label>
        <?php endforeach; ?>
        <label class="pill-check"><input type="checkbox" name="objetivos[]" value="__outro__" <?= in_array('__outro__',$obj_sel)?'checked':'' ?> onchange="document.getElementById('obj-outro-wrap').style.display=this.checked?'flex':'none'">Outro</label>
      </div>
      <div id="obj-outro-wrap" class="campo campo-rect" style="display:<?= in_array('__outro__',$obj_sel)?'flex':'none' ?>;margin-bottom:14px">
        <span class="ic"><span class="material-symbols-outlined" style="font-size:16px">edit_note</span></span>
        <input type="text" name="objetivo_outro" placeholder="Descreva seu objetivo" value="<?= htmlspecialchars($p['objetivo_outro'] ?? '') ?>"/>
      </div>

      <div class="sec-title"><span class="material-symbols-outlined" style="font-size:14px">monitor_heart</span>Escalas de saúde</div>

      <p style="font-size:.78rem;font-weight:600;color:#201923;margin:0 0 6px">Como você se sente atualmente? <span style="font-weight:400;color:#7f7383">(0=muito mal · 10=ótimo)</span></p>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px">
        <?php for ($i=0;$i<=10;$i++): ?>
        <label class="be-btn"><input type="radio" name="bem_estar" value="<?= $i ?>" <?= ($p['bem_estar']??'')==$i?'checked':'' ?>><?= $i ?></label>
        <?php endfor; ?>
      </div>

      <p style="font-size:.78rem;font-weight:600;color:#201923;margin:0 0 6px">Nível de dor crônica (se houver)? <span style="font-weight:400;color:#7f7383">(0=sem dor · 10=intensa)</span></p>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px">
        <?php for ($i=0;$i<=10;$i++): ?>
        <label class="be-btn"><input type="radio" name="nivel_dor" value="<?= $i ?>" <?= ($p['nivel_dor']??'')==$i?'checked':'' ?>><?= $i ?></label>
        <?php endfor; ?>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px">
        <div>
          <p style="font-size:.78rem;font-weight:600;color:#201923;margin:0 0 8px">Qualidade do sono</p>
          <div style="display:flex;flex-direction:column;gap:6px">
            <?php foreach (['boa'=>'😴 Boa','regular'=>'😐 Regular','ruim'=>'😩 Ruim'] as $v=>$l): ?>
            <label class="pill-radio" style="font-size:.75rem"><input type="radio" name="qualidade_sono" value="<?= $v ?>" <?= ($p['qualidade_sono']??'')===$v?'checked':'' ?>><?= $l ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div>
          <p style="font-size:.78rem;font-weight:600;color:#201923;margin:0 0 8px">Atividade física</p>
          <div style="display:flex;flex-direction:column;gap:6px">
            <?php foreach (['sedentario'=>'🛋️ Sedentário','leve'=>'🚶 Leve','moderado'=>'🏃 Moderado','intenso'=>'💪 Intenso'] as $v=>$l): ?>
            <label class="pill-radio" style="font-size:.75rem"><input type="radio" name="atividade_fisica" value="<?= $v ?>" <?= ($p['atividade_fisica']??'')===$v?'checked':'' ?>><?= $l ?></label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="sec-title"><span class="material-symbols-outlined" style="font-size:14px">gavel</span>Consentimento</div>
      <label id="consent-label" style="display:flex;align-items:flex-start;gap:12px;cursor:pointer;margin-bottom:20px;padding:14px;border-radius:16px;border:2px solid #d0c2d3;transition:.15s">
        <input type="checkbox" name="consentimento" value="1" id="consent-check" <?= isset($p['consentimento'])?'checked':'' ?>
          onchange="this.closest('label').style.borderColor=this.checked?'#4e0078':'#d0c2d3'" class="mt-0.5 w-4 h-4 accent-primary shrink-0"/>
        <span style="font-size:.82rem;color:#201923;line-height:1.5">
          Li e concordo com os
          <button type="button" onclick="abrirModalTermo()" style="color:#4e0078;font-weight:700;background:none;border:none;cursor:pointer;padding:0;text-decoration:underline">Termos de Consentimento</button>.
          Estou ciente de que as práticas integrativas não substituem tratamento médico.
        </span>
      </label>

      <div style="display:flex;gap:10px">
        <a href="login.php?modo=cadastro&etapa=1" class="btn-outline" style="width:auto;padding:13px 20px;flex-shrink:0;text-decoration:none;display:inline-flex;align-items:center;justify-content:center">← Voltar</a>
        <button type="submit" class="btn-primary">Criar minha conta</button>
      </div>
      <p style="text-align:center;font-size:.8rem;color:#7f7383;margin-top:14px">
        Já tem conta? <a href="login.php" style="color:#4e0078;font-weight:700;text-decoration:none">Entrar</a>
      </p>
    </form>
    <?php endif; ?>
    <?php endif; ?>

  </div><!-- /glass-card -->
</div><!-- /split-right -->

<!-- Modal Termo -->
<div class="modal-overlay" id="modal-termo">
  <div class="modal-box">
    <div class="modal-header">
      <span class="modal-titulo">Termo de Consentimento</span>
      <button class="modal-fechar" onclick="fecharModalTermo()">✕</button>
    </div>
    <div class="modal-corpo">
      <p><strong>TERMO DE CONSENTIMENTO E USO DE DADOS</strong></p>
      <p>Ao realizar seu cadastro no <strong>Nupics – Núcleo de Práticas Integrativas e Complementares em Saúde</strong>, você declara que leu, compreendeu e concorda com os termos abaixo:</p>
      <p><strong>1. Finalidade do serviço</strong><br>O Nupics tem como objetivo facilitar o agendamento e o acesso a práticas integrativas e complementares em saúde.</p>
      <p><strong>2. Natureza dos atendimentos</strong><br>As práticas integrativas não substituem acompanhamento médico, psicológico ou odontológico convencional.</p>
      <p><strong>3. Coleta e uso de dados</strong><br>Os dados fornecidos serão utilizados exclusivamente para identificação do paciente e organização de atendimentos, em conformidade com a <strong>LGPD – Lei nº 13.709/2018</strong>.</p>
      <p><strong>4. Compartilhamento de informações</strong><br>Seus dados poderão ser acessados apenas por profissionais envolvidos no atendimento.</p>
      <p><strong>5. Responsabilidade do usuário</strong><br>Você se compromete a fornecer informações verdadeiras e atualizadas.</p>
      <p><strong>6. Direito de revogação</strong><br>Você poderá solicitar a exclusão dos seus dados a qualquer momento pelos canais oficiais do sistema.</p>
      <div class="modal-rodape">
        <strong>Universidade do Estado do Rio Grande do Norte – UERN</strong><br>
        Nupics Caicó · Núcleo de Práticas Integrativas e Complementares em Saúde
      </div>
    </div>
    <div style="padding:0 1.5rem 1.5rem">
      <button class="btn-primary" onclick="aceitarTermo()">Entendi e concordo ✓</button>
    </div>
  </div>
</div>

<script>
function toggleSenha(id,btn){
  var el=document.getElementById(id);
  el.type=el.type==='password'?'text':'password';
  btn.querySelector('.material-symbols-outlined').textContent=el.type==='text'?'visibility_off':'visibility';
}
function setVinculo(v){
  document.getElementById('vinculo-val').value=v;
  document.getElementById('btn-interno').classList.toggle('ativo',v==='interno');
  document.getElementById('btn-externo').classList.toggle('ativo',v==='externo');
  document.getElementById('campo-email-uern').style.display=v==='interno'?'flex':'none';
  document.getElementById('campo-email-ext').style.display=v==='externo'?'flex':'none';
  document.getElementById('campo-cpf').style.display=v==='externo'?'flex':'none';
  document.getElementById('campo-ocupacao').style.display=v==='interno'?'flex':'none';
  document.getElementById('vinculo-desc').textContent=v==='externo'
    ?'Moradores de Caicó e região sem vínculo com a UERN.'
    :'Estudantes, professores e servidores da UERN.';
}
function toggleSexoOutro(){
  var v=document.getElementById('sexo-sel')?.value;
  var w=document.getElementById('sexo-outro-wrap');
  if(w)w.style.display=v==='outro'?'flex':'none';
}
function toggleQual(key){
  var sim=false;
  document.querySelectorAll('input[name="'+key+'"]').forEach(function(r){if(r.value==='sim'&&r.checked)sim=true;});
  var el=document.getElementById('qual-'+key);
  if(el)el.style.display=sim?'flex':'none';
}
function mascaraCPF(el){
  var v=el.value.replace(/\D/g,'').substring(0,11);
  if(v.length>9)v=v.replace(/^(\d{3})(\d{3})(\d{3})(\d{1,2})/,'$1.$2.$3-$4');
  else if(v.length>6)v=v.replace(/^(\d{3})(\d{3})(\d{1,3})/,'$1.$2.$3');
  else if(v.length>3)v=v.replace(/^(\d{3})(\d{1,3})/,'$1.$2');
  el.value=v;
}
function abrirModalTermo(){document.getElementById('modal-termo').classList.add('open')}
function fecharModalTermo(){document.getElementById('modal-termo').classList.remove('open')}
function aceitarTermo(){
  var c=document.getElementById('consent-check');
  if(c){c.checked=true;c.closest('label').style.borderColor='#4e0078';}
  fecharModalTermo();
}
(function(){
  toggleSexoOutro();
  <?php if($modo==='cadastro'&&$etapa===2): ?>
  <?php foreach($perguntas as $pq): ?>toggleQual('<?= $pq['key'] ?>');<?php endforeach; ?>
  <?php endif; ?>
})();
</script>
</body>
</html>