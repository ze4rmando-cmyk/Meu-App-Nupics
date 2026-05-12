<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../login.php'); exit;
}

$sucesso = ''; $erro = '';
$tipo    = $_SESSION['tipo'] ?? 'paciente';
$painel  = match($tipo) {
    'paciente'    => '../paciente/dashboard.php',
    'coordenador' => '../coordenacao/dashboard.php',
    default       => '../terapeuta/dashboard.php',
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $atual   = $_POST['senha_atual']     ?? '';
    $nova    = $_POST['senha_nova']      ?? '';
    $conf    = $_POST['confirmar_senha'] ?? '';

    if (!$atual || !$nova || !$conf)   $erro = 'Preencha todos os campos.';
    elseif (strlen($nova) < 6)         $erro = 'A nova senha deve ter pelo menos 6 caracteres.';
    elseif ($nova !== $conf)           $erro = 'As senhas não coincidem.';
    else {
        $row = $pdo->prepare('SELECT senha FROM usuarios WHERE id=?');
        $row->execute([$_SESSION['usuario_id']]);
        $u = $row->fetch();
        if (!$u || !password_verify($atual, $u['senha'])) {
            $erro = 'Senha atual incorreta.';
        } else {
            $pdo->prepare('UPDATE usuarios SET senha=? WHERE id=?')
                ->execute([password_hash($nova, PASSWORD_DEFAULT), $_SESSION['usuario_id']]);
            $sucesso = 'Senha alterada com sucesso!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>Alterar senha · NUPICS</title>
<script src="https://cdn.tailwindcss.com?plugins=forms"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@600;700;800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@400,0&display=swap" rel="stylesheet"/>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Manrope',sans-serif;min-height:100vh;
  background:radial-gradient(circle at 20% 20%,#f4d9ff 0%,transparent 50%),
             radial-gradient(circle at 80% 80%,#ffd9de 0%,transparent 50%),#fff7fc;
  display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px}
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}
.card{background:rgba(255,255,255,.82);backdrop-filter:blur(24px);
  border:1px solid rgba(255,255,255,.6);border-radius:2rem;
  padding:40px 36px;width:100%;max-width:420px;
  box-shadow:0 20px 60px rgba(78,0,120,.1)}
.campo{position:relative;display:flex;align-items:center;
  background:rgba(255,255,255,.7);border:1.5px solid #d0c2d3;
  border-radius:99px;overflow:hidden;transition:.2s;margin-bottom:12px}
.campo:focus-within{border-color:#4e0078;box-shadow:0 0 0 3px rgba(78,0,120,.1)}
.campo .ic{padding:0 14px;color:#7f7383;display:flex;align-items:center;flex-shrink:0}
.campo input{flex:1;border:none;background:transparent;padding:14px 6px;
  font-size:.875rem;color:#201923;font-family:'Manrope',sans-serif;outline:none}
.campo .olho{padding:0 14px;background:none;border:none;cursor:pointer;color:#aaa;display:flex;flex-shrink:0}
.label{font-size:.7rem;font-weight:700;text-transform:uppercase;
  letter-spacing:.08em;color:rgba(78,0,120,.75);display:block;margin-bottom:6px;margin-left:14px}
.btn{width:100%;padding:15px;border-radius:99px;
  background:linear-gradient(135deg,#6a1b9a,#b7004d);color:#fff;
  font-weight:700;font-size:.95rem;border:none;cursor:pointer;
  font-family:'Manrope',sans-serif;transition:.15s;
  box-shadow:0 8px 24px rgba(78,0,120,.25);margin-top:8px}
.btn:hover{opacity:.9}
.btn:active{transform:scale(.98)}
.forca-wrap{display:none;align-items:center;gap:8px;margin-top:4px;margin-bottom:8px;padding:0 4px}
.forca-track{flex:1;height:4px;background:#ecdeed;border-radius:99px;overflow:hidden}
.forca-fill{height:100%;border-radius:99px;transition:width .3s,background .3s}
.forca-label{font-size:.72rem;font-weight:700;white-space:nowrap}
.conf-aviso{font-size:.72rem;font-weight:600;min-height:16px;padding:0 4px;margin-bottom:8px}
.alerta{border-radius:14px;padding:12px 16px;font-size:.85rem;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.alerta-ok{background:#d1fae5;color:#065f46}
.alerta-err{background:#ffdad6;color:#93000a}
</style>
</head>
<body>

<div class="card">
  <!-- Header -->
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:28px">
    <a href="<?= $painel ?>" style="width:36px;height:36px;border-radius:50%;background:rgba(78,0,120,.07);display:flex;align-items:center;justify-content:center;text-decoration:none;flex-shrink:0">
      <span class="material-symbols-outlined" style="font-size:18px;color:#4e0078">arrow_back</span>
    </a>
    <div>
      <h1 style="font-family:'Plus Jakarta Sans',sans-serif;font-size:1.3rem;font-weight:800;color:#4e0078;line-height:1.1">Alterar senha</h1>
      <p style="font-size:.75rem;color:#7f7383;margin-top:2px"><?= htmlspecialchars($_SESSION['nome']) ?> · <?= ucfirst($tipo) ?></p>
    </div>
  </div>

  <?php if ($sucesso): ?>
  <div class="alerta alerta-ok">
    <span class="material-symbols-outlined" style="font-size:18px">check_circle</span>
    <?= htmlspecialchars($sucesso) ?>
    <a href="<?= $painel ?>" style="margin-left:auto;color:#065f46;font-weight:700;font-size:.8rem;text-decoration:none;white-space:nowrap">Ir ao painel →</a>
  </div>
  <?php endif; ?>

  <?php if ($erro): ?>
  <div class="alerta alerta-err">
    <span class="material-symbols-outlined" style="font-size:18px">error</span>
    <?= htmlspecialchars($erro) ?>
  </div>
  <?php endif; ?>

  <form method="POST" action="trocar_senha.php">
    <label class="label">Senha atual</label>
    <div class="campo">
      <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">lock</span></span>
      <input type="password" name="senha_atual" id="s-atual" placeholder="••••••••" required/>
      <button type="button" class="olho" onclick="tog('s-atual',this)">
        <span class="material-symbols-outlined" style="font-size:18px">visibility</span>
      </button>
    </div>

    <label class="label">Nova senha</label>
    <div class="campo">
      <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">lock_open</span></span>
      <input type="password" name="senha_nova" id="s-nova" placeholder="Mínimo 6 caracteres" required/>
      <button type="button" class="olho" onclick="tog('s-nova',this)">
        <span class="material-symbols-outlined" style="font-size:18px">visibility</span>
      </button>
    </div>
    <div class="forca-wrap" id="forca-wrap">
      <div class="forca-track"><div class="forca-fill" id="forca-fill"></div></div>
      <span class="forca-label" id="forca-label"></span>
    </div>

    <label class="label">Confirmar nova senha</label>
    <div class="campo">
      <span class="ic"><span class="material-symbols-outlined" style="font-size:18px">lock_open</span></span>
      <input type="password" name="confirmar_senha" id="s-conf" placeholder="Repita a nova senha" required/>
      <button type="button" class="olho" onclick="tog('s-conf',this)">
        <span class="material-symbols-outlined" style="font-size:18px">visibility</span>
      </button>
    </div>
    <div class="conf-aviso" id="conf-aviso"></div>

    <button type="submit" class="btn">
      <span style="display:flex;align-items:center;justify-content:center;gap:6px">
        <span class="material-symbols-outlined" style="font-size:18px">key</span>
        Salvar nova senha
      </span>
    </button>
  </form>

  <p style="text-align:center;font-size:.72rem;color:#aaa;margin-top:16px">
    Use sua nova senha no próximo acesso.
  </p>
</div>

<script>
function tog(id,btn){
  var el=document.getElementById(id);
  el.type=el.type==='password'?'text':'password';
  btn.querySelector('.material-symbols-outlined').textContent=el.type==='text'?'visibility_off':'visibility';
}
var sNova=document.getElementById('s-nova');
var sConf=document.getElementById('s-conf');
sNova.addEventListener('input',function(){
  var v=this.value,w=document.getElementById('forca-wrap'),f=document.getElementById('forca-fill'),l=document.getElementById('forca-label');
  if(!v){w.style.display='none';return}
  w.style.display='flex';
  var p=0;
  if(v.length>=6)p++;if(v.length>=10)p++;if(/[A-Z]/.test(v))p++;if(/[0-9]/.test(v))p++;if(/[^A-Za-z0-9]/.test(v))p++;
  var cfg=p<=1?['25%','#E24B4A','Fraca']:p<=2?['50%','#BA7517','Razoável']:p<=3?['75%','#1D9E75','Boa']:['100%','#0F6E56','Forte'];
  f.style.width=cfg[0];f.style.background=cfg[1];l.textContent=cfg[2];l.style.color=cfg[1];
  checarConf();
});
sConf.addEventListener('input',checarConf);
function checarConf(){
  var a=document.getElementById('conf-aviso');
  if(!sConf.value){a.textContent='';return}
  if(sConf.value===sNova.value){a.textContent='✓ Senhas coincidem';a.style.color='#0F6E56';}
  else{a.textContent='✗ Senhas não coincidem';a.style.color='#A32D2D';}
}
</script>
</body>
</html>