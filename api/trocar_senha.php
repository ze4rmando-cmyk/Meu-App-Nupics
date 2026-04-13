<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../index.php');
    exit;
}

$sucesso = '';
$erro    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senha_atual = $_POST['senha_atual']    ?? '';
    $senha_nova  = $_POST['senha_nova']     ?? '';
    $confirmar   = $_POST['confirmar_senha'] ?? '';

    if (!$senha_atual || !$senha_nova || !$confirmar) {
        $erro = 'Preencha todos os campos.';
    } elseif (strlen($senha_nova) < 6) {
        $erro = 'A nova senha deve ter pelo menos 6 caracteres.';
    } elseif ($senha_nova !== $confirmar) {
        $erro = 'A nova senha e a confirmação não coincidem.';
    } else {
        // Busca a senha atual do banco
        $stmt = $pdo->prepare('SELECT senha FROM usuarios WHERE id = ?');
        $stmt->execute([$_SESSION['usuario_id']]);
        $usuario = $stmt->fetch();

        if (!password_verify($senha_atual, $usuario['senha'])) {
            $erro = 'Senha atual incorreta.';
        } else {
            $hash = password_hash($senha_nova, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE usuarios SET senha = ? WHERE id = ?');
            $stmt->execute([$hash, $_SESSION['usuario_id']]);
            $sucesso = 'Senha alterada com sucesso!';
        }
    }
}

// Redireciona para o painel correto após salvar
$painel = match($_SESSION['tipo']) {
    'paciente'     => '../paciente/dashboard.php',
    'coordenador'  => '../coordenacao/dashboard.php',
    default        => '../terapeuta/dashboard.php',
};
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NUPICS Caicó — Trocar senha</title>
  <link rel="stylesheet" href="../assets/css/style.css">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>

  <nav class="topnav">
    <div class="logo-wrap">
      <div class="logo-box">
        <svg viewBox="0 0 24 24" fill="white" width="20" height="20">
          <path d="M17 8C8 10 5.9 16.17 3.82 21.34L5.71 22l1-2.3A4.49 4.49 0 0 0 8 20C19 20 22 3 22 3c-1 2-8 3-11 8l-3.54 4.54-.75-.21A15 15 0 0 1 17 8z"/>
        </svg>
      </div>
      <div>
        <div class="logo-text">NUPICS Caicó</div>
        <div class="logo-sub">UERN · Práticas Integrativas</div>
      </div>
    </div>
    <div class="nav-usuario">
      <a href="<?= $painel ?>" class="btn-logout">← Voltar</a>
      <a href="logout.php" class="btn-logout">Sair</a>
    </div>
  </nav>

  <main class="container" style="max-width: 480px;">

    <h1 class="hero-titulo" style="margin-bottom: 6px;">Trocar senha</h1>
    <p style="font-size:13px;color:#888;margin-bottom:1.5rem;">
      <?= htmlspecialchars($_SESSION['nome']) ?> · <?= ucfirst($_SESSION['tipo']) ?>
    </p>

    <?php if ($sucesso): ?>
      <div class="alerta-sucesso" style="margin-bottom:1rem;">
        <?= $sucesso ?>
        <a href="<?= $painel ?>" style="color:#085041;font-weight:600;margin-left:8px;">
          Ir para o painel →
        </a>
      </div>
    <?php endif; ?>

    <?php if ($erro): ?>
      <div class="alerta-erro"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <div class="card" style="padding: 24px;">
      <form method="POST" action="trocar_senha.php">

        <div class="campo">
          <label>Senha atual</label>
          <div class="campo-senha">
            <input type="password" name="senha_atual" id="senha_atual"
                   placeholder="••••••••" required>
            <button type="button" class="btn-olho" onclick="toggleSenha('senha_atual', this)">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
        </div>

        <div class="campo">
          <label>Nova senha</label>
          <div class="campo-senha">
            <input type="password" name="senha_nova" id="senha_nova"
                   placeholder="mínimo 6 caracteres" required>
            <button type="button" class="btn-olho" onclick="toggleSenha('senha_nova', this)">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
          <!-- Indicador de força da senha -->
          <div class="forca-wrap" id="forca-wrap" style="display:none">
            <div class="forca-track">
              <div class="forca-fill" id="forca-fill"></div>
            </div>
            <span class="forca-label" id="forca-label"></span>
          </div>
        </div>

        <div class="campo">
          <label>Confirmar nova senha</label>
          <div class="campo-senha">
            <input type="password" name="confirmar_senha" id="confirmar_senha"
                   placeholder="repita a nova senha" required>
            <button type="button" class="btn-olho" onclick="toggleSenha('confirmar_senha', this)">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
              </svg>
            </button>
          </div>
          <div class="conf-aviso" id="conf-aviso"></div>
        </div>

        <button type="submit" class="btn-entrar">Salvar nova senha</button>

      </form>
    </div>

    <p style="font-size:12px;color:#aaa;text-align:center;margin-top:1rem;line-height:1.6">
      Após salvar, use a nova senha no próximo acesso.
    </p>

  </main>

  <script>
    // Mostrar/ocultar senha
    function toggleSenha(id, btn) {
      var input = document.getElementById(id);
      if (input.type === 'password') {
        input.type = 'text';
        btn.style.color = '#1D9E75';
      } else {
        input.type = 'password';
        btn.style.color = '#aaa';
      }
    }

    // Indicador de força da senha
    var novaSenha = document.getElementById('senha_nova');
    var forcaWrap = document.getElementById('forca-wrap');
    var forcaFill = document.getElementById('forca-fill');
    var forcaLabel = document.getElementById('forca-label');

    novaSenha.addEventListener('input', function () {
      var val = this.value;
      if (!val) { forcaWrap.style.display = 'none'; return; }
      forcaWrap.style.display = 'flex';

      var pontos = 0;
      if (val.length >= 6)  pontos++;
      if (val.length >= 10) pontos++;
      if (/[A-Z]/.test(val)) pontos++;
      if (/[0-9]/.test(val)) pontos++;
      if (/[^A-Za-z0-9]/.test(val)) pontos++;

      var pct, cor, texto;
      if (pontos <= 1) {
        pct = '25%'; cor = '#E24B4A'; texto = 'Fraca';
      } else if (pontos <= 2) {
        pct = '50%'; cor = '#BA7517'; texto = 'Razoável';
      } else if (pontos <= 3) {
        pct = '75%'; cor = '#1D9E75'; texto = 'Boa';
      } else {
        pct = '100%'; cor = '#0F6E56'; texto = 'Forte';
      }

      forcaFill.style.width = pct;
      forcaFill.style.background = cor;
      forcaLabel.textContent = texto;
      forcaLabel.style.color = cor;
    });

    // Aviso de senhas diferentes
    var confirmar = document.getElementById('confirmar_senha');
    var confAviso = document.getElementById('conf-aviso');

    confirmar.addEventListener('input', function () {
      if (!this.value) { confAviso.textContent = ''; return; }
      if (this.value === novaSenha.value) {
        confAviso.textContent = 'Senhas coincidem';
        confAviso.style.color = '#0F6E56';
      } else {
        confAviso.textContent = 'Senhas não coincidem';
        confAviso.style.color = '#A32D2D';
      }
    });
  </script>

</body>
</html>