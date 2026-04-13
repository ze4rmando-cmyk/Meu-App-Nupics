<?php
session_start();

if (isset($_SESSION['usuario_id'])) {
    if ($_SESSION['tipo'] === 'paciente') header('Location: paciente/dashboard.php');
    elseif ($_SESSION['tipo'] === 'coordenador') header('Location: coordenacao/dashboard.php');
    else header('Location: terapeuta/dashboard.php');
    exit;
}

$erro    = '';
$sucesso = '';
$modo    = $_GET['modo'] ?? 'login'; // 'login' ou 'cadastro'

// ── LOGIN ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'login') {
    require_once 'config/db.php';
    $email  = trim($_POST['email']);
    $senha  = trim($_POST['senha']);
    $stmt   = $pdo->prepare('SELECT * FROM usuarios WHERE email = ?');
    $stmt->execute([$email]);
    $usuario = $stmt->fetch();
    if ($usuario && password_verify($senha, $usuario['senha'])) {
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['nome']       = $usuario['nome'];
        $_SESSION['tipo']       = $usuario['tipo'];
        if ($usuario['tipo'] === 'paciente')    header('Location: paciente/dashboard.php');
        elseif ($usuario['tipo'] === 'coordenador') header('Location: coordenacao/dashboard.php');
        else header('Location: terapeuta/dashboard.php');
        exit;
    } else {
        $erro = 'E-mail ou senha incorretos.';
        $modo = 'login';
    }
}

// ── CADASTRO ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastro') {
    require_once 'config/db.php';
    $modo = 'cadastro';

    $nome        = trim($_POST['nome']        ?? '');
    $email       = trim($_POST['email_cad']   ?? '');
    $senha       = trim($_POST['senha_cad']   ?? '');
    $telefone    = trim($_POST['telefone']     ?? '');
    $data_nasc   = trim($_POST['data_nasc']   ?? '');
    $cpf         = trim($_POST['cpf']         ?? '');
    $sexo        = trim($_POST['sexo']        ?? '');
    $sexo_outro  = trim($_POST['sexo_outro']  ?? '');
    $doenca      = $_POST['doenca']           ?? 'nao';
    $doenca_qual = trim($_POST['doenca_qual'] ?? '');
    $medicamento      = $_POST['medicamento']       ?? 'nao';
    $medicamento_qual = trim($_POST['medicamento_qual'] ?? '');
    $alergia      = $_POST['alergia']         ?? 'nao';
    $alergia_qual = trim($_POST['alergia_qual'] ?? '');
    $trat_integ      = $_POST['trat_integ']       ?? 'nao';
    $trat_integ_qual = trim($_POST['trat_integ_qual'] ?? '');
    $objetivo    = trim($_POST['objetivo']    ?? '');
    $objetivo_outro = trim($_POST['objetivo_outro'] ?? '');
    $bem_estar   = trim($_POST['bem_estar']   ?? '');
    $consentimento = isset($_POST['consentimento']) ? 1 : 0;

    if (!$nome || !$email || !$senha || !$telefone || !$data_nasc || !$cpf || !$sexo) {
        $erro = 'Preencha todos os campos obrigatórios.';
    } elseif (!$consentimento) {
        $erro = 'Você precisa aceitar os Termos de Consentimento para continuar.';
    } elseif (strlen($senha) < 6) {
        $erro = 'A senha deve ter pelo menos 6 caracteres.';
    } else {
        $chk = $pdo->prepare('SELECT id FROM usuarios WHERE email = ?');
        $chk->execute([$email]);
        if ($chk->fetch()) {
            $erro = 'Este e-mail já está cadastrado.';
        } else {
            $sexo_final     = $sexo === 'outro' ? $sexo_outro : $sexo;
            $objetivo_final = $objetivo === 'outro' ? $objetivo_outro : $objetivo;

            // Monta observação clínica
            $obs = [];
            if ($doenca === 'sim' && $doenca_qual) $obs[] = "Doença: $doenca_qual";
            if ($medicamento === 'sim' && $medicamento_qual) $obs[] = "Medicamentos: $medicamento_qual";
            if ($alergia === 'sim' && $alergia_qual) $obs[] = "Alergias: $alergia_qual";
            if ($trat_integ === 'sim' && $trat_integ_qual) $obs[] = "Tratamento integrativo anterior: $trat_integ_qual";
            if ($objetivo_final) $obs[] = "Objetivo: $objetivo_final";
            if ($bem_estar) $obs[] = "Bem-estar atual: $bem_estar/10";
            $observacao = implode(' | ', $obs);

            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO usuarios (nome, email, senha, tipo, telefone) VALUES (?,?,?,"paciente",?)')
                ->execute([$nome, $email, $hash, $telefone]);
            $uid = $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO pacientes (usuario_id, cpf, data_nasc) VALUES (?,?,?)')
                ->execute([$uid, $cpf, $data_nasc]);

            // Salva dados clínicos na tabela pacientes como observação (coluna observacao — veja nota abaixo)
            $pdo->prepare('ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS sexo VARCHAR(60) DEFAULT NULL')->execute();
            $pdo->prepare('ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS observacao_clinica TEXT DEFAULT NULL')->execute();
            $pdo->prepare('ALTER TABLE pacientes ADD COLUMN IF NOT EXISTS consentimento TINYINT(1) DEFAULT 0')->execute();
            $pdo->prepare('UPDATE pacientes SET sexo=?, observacao_clinica=?, consentimento=1 WHERE usuario_id=?')
                ->execute([$sexo_final, $observacao, $uid]);

            $sucesso = 'Cadastro realizado com sucesso! Faça login para continuar.';
            $modo    = 'login';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NUPICS Caicó</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="nupics-login-body">

  <!-- Fundo com a imagem do projeto -->
  <div class="nupics-bg" style="background-image: url('assets/img/fundopc.png.png')"></div>

  <div class="nupics-centro">

    <!-- ══ TELA DE LOGIN ══ -->
    <?php if ($modo === 'login'): ?>
    <div class="nupics-card">

      <!-- Logo -->
      <div class="nupics-logo">
        <svg viewBox="0 0 40 40" width="42" height="42">
          <ellipse cx="20" cy="10" rx="6" ry="9" fill="none" stroke="#c084fc" stroke-width="2"/>
          <ellipse cx="10" cy="22" rx="9" ry="6" fill="none" stroke="#c084fc" stroke-width="2" transform="rotate(-30 10 22)"/>
          <ellipse cx="30" cy="22" rx="9" ry="6" fill="none" stroke="#c084fc" stroke-width="2" transform="rotate(30 30 22)"/>
          <circle cx="20" cy="20" r="3" fill="#c084fc"/>
        </svg>
        <div class="nupics-logo-texto">nupics</div>
      </div>

      <h2 class="nupics-boas-vindas">Bem-vindo(a)!</h2>

      <?php if ($sucesso): ?>
        <div class="alerta-sucesso" style="margin-bottom:1rem;"><?= $sucesso ?></div>
      <?php endif; ?>
      <?php if ($erro): ?>
        <div class="alerta-erro" style="margin-bottom:1rem;"><?= htmlspecialchars($erro) ?></div>
      <?php endif; ?>

      <form method="POST" action="index.php">
        <input type="hidden" name="acao" value="login">

        <div class="nupics-campo">
          <span class="nc-icon">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          </span>
          <input type="email" name="email" placeholder="E-mail" required
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>

        <div class="nupics-campo">
          <span class="nc-icon">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
          </span>
          <input type="password" name="senha" id="senha-login" placeholder="Senha" required>
          <button type="button" class="nc-olho" onclick="toggleSenha('senha-login',this)">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>

        <button type="submit" class="nupics-btn">Entrar</button>
      </form>

      <p class="nupics-link-alt">
        Não tem conta?
        <a href="index.php?modo=cadastro">Cadastre-se</a>
      </p>
    </div>

    <!-- ══ TELA DE CADASTRO ══ -->
    <?php else: ?>
    <div class="nupics-card nupics-card-lg">

      <div class="nupics-logo" style="margin-bottom:0.5rem;">
        <svg viewBox="0 0 40 40" width="36" height="36">
          <ellipse cx="20" cy="10" rx="6" ry="9" fill="none" stroke="#c084fc" stroke-width="2"/>
          <ellipse cx="10" cy="22" rx="9" ry="6" fill="none" stroke="#c084fc" stroke-width="2" transform="rotate(-30 10 22)"/>
          <ellipse cx="30" cy="22" rx="9" ry="6" fill="none" stroke="#c084fc" stroke-width="2" transform="rotate(30 30 22)"/>
          <circle cx="20" cy="20" r="3" fill="#c084fc"/>
        </svg>
        <div class="nupics-logo-texto">nupics</div>
      </div>
      <h2 class="nupics-boas-vindas" style="margin-bottom:1.5rem;">Criar minha conta</h2>

      <?php if ($erro): ?>
        <div class="alerta-erro" style="margin-bottom:1rem;"><?= htmlspecialchars($erro) ?></div>
      <?php endif; ?>

      <form method="POST" action="index.php">
        <input type="hidden" name="acao" value="cadastro">

        <!-- SEÇÃO 1: Dados básicos -->
        <div class="cad-secao-titulo">Dados pessoais</div>

        <div class="cad-grid-2">
          <div class="nupics-campo">
            <input type="text" name="nome" placeholder="Nome completo *" required
                   value="<?= htmlspecialchars($_POST['nome'] ?? '') ?>">
          </div>
          <div class="nupics-campo">
            <input type="email" name="email_cad" placeholder="E-mail *" required
                   value="<?= htmlspecialchars($_POST['email_cad'] ?? '') ?>">
          </div>
          <div class="nupics-campo">
            <input type="password" name="senha_cad" placeholder="Senha (mín. 6 caracteres) *" required>
          </div>
          <div class="nupics-campo">
            <input type="text" name="telefone" placeholder="WhatsApp *" required
                   value="<?= htmlspecialchars($_POST['telefone'] ?? '') ?>">
          </div>
          <div class="nupics-campo">
            <input type="date" name="data_nasc" placeholder="Data de nascimento *" required
                   value="<?= htmlspecialchars($_POST['data_nasc'] ?? '') ?>">
          </div>
          <div class="nupics-campo">
            <input type="text" name="cpf" placeholder="CPF *" required
                   value="<?= htmlspecialchars($_POST['cpf'] ?? '') ?>">
          </div>
        </div>

        <!-- Sexo/gênero -->
        <div class="nupics-campo" style="margin-bottom:0.5rem;">
          <select name="sexo" id="sexo-sel" required onchange="toggleSexoOutro()">
            <option value="">Sexo / Gênero *</option>
            <option value="Feminino"     <?= ($_POST['sexo']??'')==='Feminino'?'selected':'' ?>>Feminino</option>
            <option value="Masculino"    <?= ($_POST['sexo']??'')==='Masculino'?'selected':'' ?>>Masculino</option>
            <option value="Não-binário"  <?= ($_POST['sexo']??'')==='Não-binário'?'selected':'' ?>>Não-binário</option>
            <option value="Prefiro não informar" <?= ($_POST['sexo']??'')==='Prefiro não informar'?'selected':'' ?>>Prefiro não informar</option>
            <option value="outro"        <?= ($_POST['sexo']??'')==='outro'?'selected':'' ?>>Outro</option>
          </select>
        </div>
        <div class="nupics-campo" id="sexo-outro-wrap" style="display:none;margin-bottom:1rem;">
          <input type="text" name="sexo_outro" placeholder="Como prefere se identificar?"
                 value="<?= htmlspecialchars($_POST['sexo_outro'] ?? '') ?>">
        </div>

        <!-- SEÇÃO 2: Saúde -->
        <div class="cad-secao-titulo" style="margin-top:1rem;">Informações de saúde</div>

        <?php
        $perguntas = [
            ['key'=>'doenca',     'label'=>'Possui alguma doença diagnosticada?',        'qual'=>'Qual doença?'],
            ['key'=>'medicamento','label'=>'Faz uso de medicamentos contínuos?',          'qual'=>'Quais medicamentos?'],
            ['key'=>'alergia',    'label'=>'Possui alergias?',                            'qual'=>'Quais alergias?'],
            ['key'=>'trat_integ', 'label'=>'Já fez ou faz algum tratamento integrativo?', 'qual'=>'Qual tratamento?'],
        ];
        foreach ($perguntas as $pq):
            $k   = $pq['key'];
            $val = $_POST[$k] ?? 'nao';
        ?>
        <div class="saude-pergunta">
          <div class="saude-label"><?= $pq['label'] ?></div>
          <div class="saude-opcoes">
            <label class="saude-radio">
              <input type="radio" name="<?= $k ?>" value="nao"
                     <?= $val!=='sim'?'checked':'' ?>
                     onchange="toggleQual('<?= $k ?>')"> Não
            </label>
            <label class="saude-radio">
              <input type="radio" name="<?= $k ?>" value="sim"
                     <?= $val==='sim'?'checked':'' ?>
                     onchange="toggleQual('<?= $k ?>')"> Sim
            </label>
          </div>
          <div class="nupics-campo" id="qual-<?= $k ?>"
               style="display:<?= $val==='sim'?'flex':'none' ?>;margin-top:6px;">
            <input type="text" name="<?= $k ?>_qual"
                   placeholder="<?= $pq['qual'] ?>"
                   value="<?= htmlspecialchars($_POST[$k.'_qual'] ?? '') ?>">
          </div>
        </div>
        <?php endforeach; ?>

        <!-- SEÇÃO 3: Objetivo -->
        <div class="cad-secao-titulo" style="margin-top:1rem;">Objetivo com o atendimento</div>

        <div class="obj-grid">
          <?php
          $objetivos = [
              'Redução do estresse','Ansiedade','Dor física',
              'Qualidade do sono','Autoconhecimento',
          ];
          $obj_val = $_POST['objetivo'] ?? '';
          foreach ($objetivos as $ob):
          ?>
          <label class="obj-opcao">
            <input type="radio" name="objetivo" value="<?= $ob ?>"
                   <?= $obj_val===$ob?'checked':'' ?>
                   onchange="document.getElementById('obj-outro-wrap').style.display='none'">
            <?= $ob ?>
          </label>
          <?php endforeach; ?>
          <label class="obj-opcao">
            <input type="radio" name="objetivo" value="outro"
                   <?= $obj_val==='outro'?'checked':'' ?>
                   onchange="document.getElementById('obj-outro-wrap').style.display='flex'">
            Outro
          </label>
        </div>
        <div class="nupics-campo" id="obj-outro-wrap"
             style="display:<?= $obj_val==='outro'?'flex':'none' ?>;margin-top:6px;">
          <input type="text" name="objetivo_outro" placeholder="Descreva seu objetivo"
                 value="<?= htmlspecialchars($_POST['objetivo_outro'] ?? '') ?>">
        </div>

        <!-- Escala de bem-estar -->
        <div class="saude-label" style="margin-top:1rem;margin-bottom:8px;">
          Como você se sente atualmente? <span style="color:#888;font-weight:400">(0 = muito mal · 10 = muito bem)</span>
        </div>
        <div class="bem-estar-wrap">
          <?php for ($i = 0; $i <= 10; $i++): ?>
          <label class="be-opcao">
            <input type="radio" name="bem_estar" value="<?= $i ?>"
                   <?= ($_POST['bem_estar']??'')==(string)$i?'checked':'' ?>>
            <span><?= $i ?></span>
          </label>
          <?php endfor; ?>
        </div>

        <!-- CONSENTIMENTO -->
        <div class="consentimento-wrap">
          <label class="consent-label">
            <input type="checkbox" name="consentimento" value="1"
                   <?= isset($_POST['consentimento'])?'checked':'' ?> required>
            <span>
              Li e concordo com os
              <a href="#" onclick="abrirModal();return false;">Termos de Consentimento</a>
              e estou ciente de que as práticas integrativas não substituem tratamento médico.
            </span>
          </label>
        </div>

        <button type="submit" class="nupics-btn">Criar minha conta</button>

        <p class="nupics-link-alt">
          Já tem conta? <a href="index.php">Entrar</a>
        </p>
      </form>
    </div>
    <?php endif; ?>

  </div>

  <!-- ══ MODAL: Termo de Consentimento ══ -->
  <div class="modal-overlay" id="modal-termo" onclick="fecharModalFora(event)">
    <div class="modal-box">
      <div class="modal-header">
        <div class="modal-titulo">Termo de Consentimento</div>
        <button class="modal-fechar" onclick="fecharModal()">✕</button>
      </div>
      <div class="modal-corpo">
        <p><strong>TERMO DE CONSENTIMENTO E USO DE DADOS</strong></p>
        <p>Ao realizar seu cadastro no <strong>Nupics – Núcleo de Práticas Integrativas e Complementares em Saúde</strong>, você declara que leu, compreendeu e concorda com os termos abaixo:</p>

        <p><strong>1. Finalidade do serviço</strong><br>
        O Nupics tem como objetivo facilitar o agendamento e o acesso a práticas integrativas e complementares em saúde, promovendo bem-estar físico, mental e emocional.</p>

        <p><strong>2. Natureza dos atendimentos</strong><br>
        As práticas integrativas e complementares não substituem acompanhamento médico, psicológico ou odontológico convencional, sendo utilizadas como suporte ao cuidado em saúde.</p>

        <p><strong>3. Coleta e uso de dados</strong><br>
        Os dados fornecidos no cadastro (pessoais e de saúde) serão utilizados exclusivamente para:<br>
        • Identificação do paciente<br>
        • Organização de atendimentos e agendamentos<br>
        • Melhor adequação das práticas ofertadas<br>
        Seus dados serão tratados de forma confidencial, em conformidade com a <strong>Lei Geral de Proteção de Dados (LGPD – Lei nº 13.709/2018)</strong>.</p>

        <p><strong>4. Compartilhamento de informações</strong><br>
        Seus dados poderão ser acessados apenas por profissionais envolvidos no atendimento, sendo vedado qualquer uso para fins comerciais ou não autorizados.</p>

        <p><strong>5. Responsabilidade do usuário</strong><br>
        Você se compromete a fornecer informações verdadeiras, completas e atualizadas, sendo responsável por qualquer omissão relevante que possa impactar seu atendimento.</p>

        <p><strong>6. Consentimento informado</strong><br>
        Ao aceitar este termo, você declara estar ciente sobre a natureza das práticas integrativas e autoriza a utilização dos seus dados para os fins descritos.</p>

        <p><strong>7. Direito de revogação</strong><br>
        Você poderá, a qualquer momento, solicitar a exclusão dos seus dados ou revogar este consentimento, mediante solicitação pelos canais oficiais do sistema.</p>

        <div class="modal-rodape">
          <strong>Universidade do Estado do Rio Grande do Norte – UERN</strong><br>
          Nupics Caicó · Núcleo de Práticas Integrativas e Complementares em Saúde<br>
          Administrador do sistema:
          <a href="mailto:jose20230067204@alu.uern.br">jose20230067204@alu.uern.br</a>
        </div>
      </div>
      <div style="padding: 0 1.5rem 1.5rem;">
        <button class="nupics-btn" onclick="fecharModal()">Entendi e concordo</button>
      </div>
    </div>
  </div>

  <script>
    function toggleSenha(id, btn) {
      var el = document.getElementById(id);
      el.type = el.type === 'password' ? 'text' : 'password';
      btn.style.color = el.type === 'text' ? '#a855f7' : '#aaa';
    }

    function toggleSexoOutro() {
      var sel = document.getElementById('sexo-sel');
      document.getElementById('sexo-outro-wrap').style.display =
        sel.value === 'outro' ? 'flex' : 'none';
    }

    function toggleQual(key) {
      var radios = document.querySelectorAll('input[name="' + key + '"]');
      var sim = false;
      radios.forEach(function(r) { if (r.value === 'sim' && r.checked) sim = true; });
      document.getElementById('qual-' + key).style.display = sim ? 'flex' : 'none';
    }

    function abrirModal() {
      document.getElementById('modal-termo').style.display = 'flex';
    }

    function fecharModal() {
      document.getElementById('modal-termo').style.display = 'none';
    }

    function fecharModalFora(e) {
      if (e.target === document.getElementById('modal-termo')) fecharModal();
    }

    // Inicializa estados
    <?php if ($modo === 'cadastro'): ?>
    toggleSexoOutro();
    <?php foreach ($perguntas as $pq): ?>
    toggleQual('<?= $pq['key'] ?>');
    <?php endforeach; ?>
    <?php endif; ?>
  </script>

</body>
</html>