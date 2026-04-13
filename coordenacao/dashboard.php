<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['tipo'] !== 'coordenador') {
    header('Location: ../index.php');
    exit;
}

$sucesso = '';
$erro    = '';

// ── POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    $acao = $_POST['acao'];

    // Plantão
    if ($acao === 'registrar_plantao') {
        $tid  = (int)$_POST['terapeuta_id'];
        $pnome= trim($_POST['paciente_nome']??'');
        $pid  = !empty($_POST['paciente_id'])?(int)$_POST['paciente_id']:null;
        $prat = trim($_POST['tipo_pratica']??'');
        $data = $_POST['data_sessao']??date('Y-m-d');
        $hora = $_POST['hora_sessao']??date('H:i');
        $stat = $_POST['status_sessao']??'realizado';
        $obs  = trim($_POST['observacao']??'');
        if (!$tid||!$pnome||!$prat){$erro='Preencha terapeuta, paciente e prática.';}
        else {
            if (!$pid) {
                $chk=$pdo->prepare('SELECT p.id FROM pacientes p JOIN usuarios u ON u.id=p.usuario_id WHERE u.nome LIKE ? LIMIT 1');
                $chk->execute(['%'.$pnome.'%']); $f=$chk->fetch(); if($f) $pid=$f['id'];
            }
            $pdo->prepare('INSERT INTO sessoes_plantao(terapeuta_id,data,hora_inicio,paciente_id,paciente_nome,tipo_pratica,status,observacao)VALUES(?,?,?,?,?,?,?,?)')->execute([$tid,$data,$hora.':00',$pid,$pnome,$prat,$stat,$obs]);
            if ($pid) {
                $stmt=$pdo->prepare('SELECT id FROM ciclos WHERE paciente_id=? AND terapeuta_id=? AND status="ativo" LIMIT 1');$stmt->execute([$pid,$tid]);$ciclo=$stmt->fetch();
                if (!$ciclo) {
                    $ds=date('N',strtotime($data));
                    $hq=$pdo->prepare('SELECT h.id FROM horarios h JOIN horario_terapeutas ht ON ht.horario_id=h.id WHERE ht.terapeuta_id=? AND h.dia_semana=? AND h.ativo=1 LIMIT 1');$hq->execute([$tid,$ds]);$hor=$hq->fetch();
                    if($hor){$pdo->prepare('INSERT INTO ciclos(paciente_id,terapeuta_id,total_sessoes,status)VALUES(?,?,4,"ativo")')->execute([$pid,$tid]);$cid=$pdo->lastInsertId();$pdo->prepare('INSERT INTO agendamentos(ciclo_id,horario_id,data,numero_sessao,status)VALUES(?,?,?,1,"realizado")')->execute([$cid,$hor['id'],$data]);}
                }
            }
            $sucesso='Sessão de plantão registrada!';
        }
    }

    // Usuário
    if ($acao==='cadastrar_usuario') {
        $nome=trim($_POST['nome']??'');$email=trim($_POST['email']??'');$senha=trim($_POST['senha']??'');$tipo=$_POST['tipo']??'';$tel=trim($_POST['telefone']??'');$esp=trim($_POST['especialidade']??'');$per=trim($_POST['periodo']??'');$cpf=trim($_POST['cpf']??'');
        if(!$nome||!$email||!$senha||!$tipo){$erro='Preencha os campos obrigatórios.';}
        else{$chk=$pdo->prepare('SELECT id FROM usuarios WHERE email=?');$chk->execute([$email]);if($chk->fetch()){$erro='E-mail já cadastrado.';}else{$hash=password_hash($senha,PASSWORD_DEFAULT);$pdo->prepare('INSERT INTO usuarios(nome,email,senha,tipo,telefone)VALUES(?,?,?,?,?)')->execute([$nome,$email,$hash,$tipo,$tel]);$uid=$pdo->lastInsertId();if($tipo==='terapeuta'){$pdo->prepare('INSERT INTO terapeutas(usuario_id,especialidade,periodo)VALUES(?,?,?)')->execute([$uid,$esp,$per]);}elseif($tipo==='paciente'){$pdo->prepare('INSERT INTO pacientes(usuario_id,cpf)VALUES(?,?)')->execute([$uid,$cpf]);}$sucesso=ucfirst($tipo).' '.htmlspecialchars($nome).' cadastrado!';}}
    }

    if($acao==='desativar_terapeuta'){$pdo->prepare('UPDATE terapeutas SET ativo=0 WHERE id=?')->execute([(int)$_POST['terapeuta_id']]);$sucesso='Terapeuta desativado.';}
    if($acao==='reativar_terapeuta'){$pdo->prepare('UPDATE terapeutas SET ativo=1 WHERE id=?')->execute([(int)$_POST['terapeuta_id']]);$sucesso='Terapeuta reativado.';}
    if($acao==='estender_ciclo'){$pdo->prepare('UPDATE ciclos SET total_sessoes=total_sessoes+1 WHERE id=?')->execute([(int)$_POST['ciclo_id']]);$sucesso='Ciclo estendido.';}
    if($acao==='concluir_ciclo'){$pdo->prepare('UPDATE ciclos SET status="concluido" WHERE id=?')->execute([(int)$_POST['ciclo_id']]);$sucesso='Ciclo concluído.';}
    if($acao==='add_horario'){$dia=(int)$_POST['dia_semana'];$hora=trim($_POST['hora_inicio']??'');$dur=(int)$_POST['duracao'];$vag=(int)$_POST['vagas'];if(!$dia||!$hora||!$dur||!$vag){$erro='Preencha todos os campos.';}else{$chk=$pdo->prepare('SELECT id FROM horarios WHERE dia_semana=? AND hora_inicio=?');$chk->execute([$dia,$hora.':00']);if($chk->fetch()){$erro='Já existe este horário.';}else{$pdo->prepare('INSERT INTO horarios(dia_semana,hora_inicio,duracao_minutos,vagas_total,ativo)VALUES(?,?,?,?,1)')->execute([$dia,$hora.':00',$dur,$vag]);$nid=$pdo->lastInsertId();foreach($pdo->query('SELECT id FROM terapeutas WHERE ativo=1')->fetchAll() as $t)$pdo->prepare('INSERT INTO horario_terapeutas(horario_id,terapeuta_id)VALUES(?,?)')->execute([$nid,$t['id']]);$sucesso='Horário adicionado.';}}}
    if($acao==='toggle_horario'){$pdo->prepare('UPDATE horarios SET ativo=? WHERE id=?')->execute([(int)$_POST['estado'],(int)$_POST['horario_id']]);$sucesso='Horário atualizado.';}
    if($acao==='editar_vagas'){$v=(int)$_POST['vagas'];if($v>=1&&$v<=20)$pdo->prepare('UPDATE horarios SET vagas_total=? WHERE id=?')->execute([$v,(int)$_POST['horario_id']]);$sucesso='Vagas atualizadas.';}
    if($acao==='status_visita'){$s=$_POST['novo_status']??'';if(in_array($s,['pendente','aprovada','realizada','cancelada']))$pdo->prepare('UPDATE visitas_externas SET status=? WHERE id=?')->execute([$s,(int)$_POST['visita_id']]);$sucesso='Status atualizado.';}
    if($acao==='add_aviso'){$pdo->prepare('INSERT INTO avisos(tipo,titulo,texto)VALUES(?,?,?)')->execute([$_POST['tipo_aviso']??'info',trim($_POST['titulo_aviso']??''),trim($_POST['texto_aviso']??'')]);$sucesso='Aviso criado.';}
    if($acao==='del_aviso'){$pdo->prepare('UPDATE avisos SET ativo=0 WHERE id=?')->execute([(int)$_POST['aviso_id']]);$sucesso='Aviso removido.';}

    // Frases
    if($acao==='add_frase'){$pdo->prepare('INSERT INTO frases(tipo,texto,autor)VALUES(?,?,?)')->execute([$_POST['frase_tipo']??'paciente',trim($_POST['frase_texto']??''),trim($_POST['frase_autor']??'')?:null]);$sucesso='Frase adicionada.';}
    if($acao==='del_frase'){$pdo->prepare('UPDATE frases SET ativo=0 WHERE id=?')->execute([(int)$_POST['frase_id']]);$sucesso='Frase removida.';}

    // Playlists
    if($acao==='add_playlist'){$max=$pdo->query('SELECT MAX(ordem) FROM playlists')->fetchColumn();$pdo->prepare('INSERT INTO playlists(emoji,nome,url,ordem)VALUES(?,?,?,?)')->execute([trim($_POST['pl_emoji']??'🎵'),trim($_POST['pl_nome']??''),trim($_POST['pl_url']??''),$max+1]);$sucesso='Playlist adicionada.';}
    if($acao==='del_playlist'){$pdo->prepare('UPDATE playlists SET ativo=0 WHERE id=?')->execute([(int)$_POST['playlist_id']]);$sucesso='Playlist removida.';}
}

// ── Dados ──
$metricas = $pdo->query('SELECT (SELECT COUNT(*) FROM usuarios WHERE tipo="paciente") AS total_pacientes,(SELECT COUNT(*) FROM terapeutas WHERE ativo=1) AS terapeutas_ativos,(SELECT COUNT(*) FROM ciclos WHERE status="ativo") AS ciclos_ativos,(SELECT COUNT(*) FROM agendamentos WHERE MONTH(data)=MONTH(CURDATE()) AND status="realizado") AS sessoes_mes,(SELECT COUNT(*) FROM agendamentos WHERE data=CURDATE() AND status="agendado") AS hoje,(SELECT COUNT(*) FROM sessoes_plantao WHERE data=CURDATE()) AS plantoes_hoje')->fetch();

$agenda_hoje = $pdo->query('SELECT h.hora_inicio, up.nome AS paciente_nome, t.especialidade AS terapia, a.status, ut.nome AS terapeuta_nome, "agendamento" AS tipo FROM agendamentos a JOIN horarios h ON h.id=a.horario_id JOIN ciclos c ON c.id=a.ciclo_id JOIN pacientes p ON p.id=c.paciente_id JOIN usuarios up ON up.id=p.usuario_id LEFT JOIN terapeutas t ON t.id=a.terapeuta_id LEFT JOIN usuarios ut ON ut.id=t.usuario_id WHERE a.data=CURDATE() UNION ALL SELECT sp.hora_inicio, sp.paciente_nome, sp.tipo_pratica, sp.status, u.nome, "plantao" FROM sessoes_plantao sp JOIN terapeutas t ON t.id=sp.terapeuta_id JOIN usuarios u ON u.id=t.usuario_id WHERE sp.data=CURDATE() ORDER BY hora_inicio')->fetchAll();

$sessoes_semana = $pdo->query('SELECT DAYOFWEEK(data) AS dia, COUNT(*) AS total FROM agendamentos WHERE data>=DATE_SUB(CURDATE(),INTERVAL 28 DAY) AND status="realizado" GROUP BY DAYOFWEEK(data)')->fetchAll(PDO::FETCH_KEY_PAIR);
$max_bar = max(array_values($sessoes_semana)?:[1]);

$terapeutas = $pdo->query('SELECT t.id,t.ativo,t.especialidade,t.periodo,u.nome,u.email,u.telefone,COUNT(DISTINCT c.paciente_id) AS total_pacientes,COUNT(CASE WHEN a.status="realizado" AND MONTH(a.data)=MONTH(CURDATE()) THEN 1 END) AS sessoes_mes FROM terapeutas t JOIN usuarios u ON u.id=t.usuario_id LEFT JOIN ciclos c ON c.terapeuta_id=t.id LEFT JOIN agendamentos a ON a.ciclo_id=c.id GROUP BY t.id,t.ativo,t.especialidade,t.periodo,u.nome,u.email,u.telefone ORDER BY t.ativo DESC,u.nome')->fetchAll();

$pacientes = $pdo->query('SELECT p.id,u.nome,u.email,u.telefone,p.cpf,c.id AS ciclo_id,c.status AS ciclo_status,c.total_sessoes,ut.nome AS terapeuta_nome,t.especialidade,COUNT(CASE WHEN a.status="realizado" THEN 1 END) AS sessoes_feitas FROM pacientes p JOIN usuarios u ON u.id=p.usuario_id LEFT JOIN ciclos c ON c.paciente_id=p.id AND c.status="ativo" LEFT JOIN terapeutas t ON t.id=c.terapeuta_id LEFT JOIN usuarios ut ON ut.id=t.usuario_id LEFT JOIN agendamentos a ON a.ciclo_id=c.id GROUP BY p.id,u.nome,u.email,u.telefone,p.cpf,c.id,c.status,c.total_sessoes,ut.nome,t.especialidade ORDER BY u.nome')->fetchAll();

$ciclos_todos = $pdo->query('SELECT c.id,c.total_sessoes,c.status,up.nome AS paciente_nome,ut.nome AS terapeuta_nome,t.especialidade,COUNT(CASE WHEN a.status="realizado" THEN 1 END) AS feitas FROM ciclos c JOIN pacientes p ON p.id=c.paciente_id JOIN usuarios up ON up.id=p.usuario_id LEFT JOIN terapeutas t ON t.id=c.terapeuta_id LEFT JOIN usuarios ut ON ut.id=t.usuario_id LEFT JOIN agendamentos a ON a.ciclo_id=c.id WHERE c.status="ativo" GROUP BY c.id ORDER BY up.nome')->fetchAll();

$todos_horarios = $pdo->query('SELECT h.id,h.dia_semana,h.hora_inicio,h.duracao_minutos,h.vagas_total,h.ativo,COUNT(DISTINCT ht.terapeuta_id) AS num_terapeutas,COUNT(DISTINCT CASE WHEN a.status="agendado" AND a.data>=CURDATE() THEN a.id END) AS proximos_agend FROM horarios h LEFT JOIN horario_terapeutas ht ON ht.horario_id=h.id LEFT JOIN agendamentos a ON a.horario_id=h.id GROUP BY h.id ORDER BY h.dia_semana,h.hora_inicio')->fetchAll();

$agendamentos_semana = $pdo->query('SELECT a.id,a.status,a.data,h.hora_inicio,h.dia_semana,up.nome AS paciente_nome,ut.nome AS terapeuta_nome,t.especialidade FROM agendamentos a JOIN horarios h ON h.id=a.horario_id JOIN ciclos c ON c.id=a.ciclo_id JOIN pacientes p ON p.id=c.paciente_id JOIN usuarios up ON up.id=p.usuario_id LEFT JOIN terapeutas t ON t.id=a.terapeuta_id LEFT JOIN usuarios ut ON ut.id=t.usuario_id WHERE a.data BETWEEN DATE_SUB(CURDATE(),INTERVAL WEEKDAY(CURDATE()) DAY) AND DATE_ADD(DATE_SUB(CURDATE(),INTERVAL WEEKDAY(CURDATE()) DAY),INTERVAL 4 DAY) ORDER BY a.data,h.hora_inicio')->fetchAll();

$visitas = $pdo->query('SELECT v.*,u.nome AS solicitante_nome,u.telefone AS solicitante_tel FROM visitas_externas v JOIN usuarios u ON u.id=v.solicitante_id ORDER BY v.criado_em DESC')->fetchAll();

$avisos = $pdo->query('SELECT * FROM avisos WHERE ativo=1 ORDER BY criado_em DESC')->fetchAll();

$frases_paciente  = $pdo->query('SELECT * FROM frases WHERE tipo="paciente" AND ativo=1 ORDER BY id')->fetchAll();
$frases_terapeuta = $pdo->query('SELECT * FROM frases WHERE tipo="terapeuta" AND ativo=1 ORDER BY id')->fetchAll();
$playlists_db     = $pdo->query('SELECT * FROM playlists WHERE ativo=1 ORDER BY ordem')->fetchAll();

$historico_plantoes = $pdo->query('SELECT sp.*,u.nome AS terapeuta_nome FROM sessoes_plantao sp JOIN terapeutas t ON t.id=sp.terapeuta_id JOIN usuarios u ON u.id=t.usuario_id ORDER BY sp.data DESC,sp.hora_inicio DESC LIMIT 50')->fetchAll();

$top_praticas = $pdo->query('SELECT tipo_pratica,COUNT(*) AS total FROM sessoes_plantao GROUP BY tipo_pratica ORDER BY total DESC LIMIT 5')->fetchAll();

$cores  = [['bg'=>'#E1F5EE','txt'=>'#085041'],['bg'=>'#E6F1FB','txt'=>'#0C447C'],['bg'=>'#FAEEDA','txt'=>'#633806'],['bg'=>'#FBEAF0','txt'=>'#72243E'],['bg'=>'#EAF3DE','txt'=>'#27500A']];
$dias_pt = ['','Segunda','Terça','Quarta','Quinta','Sexta','Sábado','Domingo'];
$dias_sel= ['1'=>'Segunda','2'=>'Terça','3'=>'Quarta','4'=>'Quinta','5'=>'Sexta'];
$tipos_aviso = ['info'=>'Info','evento'=>'Evento','manutencao'=>'Manutenção','urgente'=>'Urgente'];
$tipos_visita= ['ubs'=>'UBS','hospital'=>'Hospital','clinica'=>'Clínica','empresa'=>'Empresa','outro'=>'Outro'];
function ini($n){$p=explode(' ',$n);$i='';foreach($p as $x){$i.=strtoupper(mb_substr($x,0,1));if(strlen($i)>=2)break;}return $i;}
$primeiro = explode(' ',$_SESSION['nome'])[0];
$frase_hoje = !empty($frases_terapeuta) ? $frases_terapeuta[date('z')%count($frases_terapeuta)]['texto'] : '"Presença plena é o maior presente que um terapeuta pode oferecer."';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NUPICS — Gestão</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
  <script>
    tailwind.config={theme:{extend:{fontFamily:{headline:['Plus Jakarta Sans'],body:['Manrope']},colors:{primary:'#4e0078',secondary:'#b7004d'}}}}
  </script>
  <style>
    .scrollbar-hide::-webkit-scrollbar { display: none; }
.scrollbar-hide { scrollbar-width: none; }
    body{font-family:'Manrope',sans-serif;background:#fff7fc;}
    h1,h2,h3{font-family:'Plus Jakarta Sans',sans-serif;}
    .material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;}
    .glass{background:rgba(255,255,255,.65);backdrop-filter:blur(12px);}
    .grad{background:linear-gradient(135deg,#4e0078,#b7004d);}
    .nav-tab{padding:10px 14px;font-size:12px;font-weight:600;color:#888;cursor:pointer;background:transparent;border:none;border-bottom:2px solid transparent;white-space:nowrap;font-family:'Manrope',sans-serif;transition:color .15s;}
    .nav-tab.on{color:#4e0078;border-bottom-color:#4e0078;}
    .nav-tab:hover{color:#4e0078;}
    .modal-bg{display:none;position:fixed;inset:0;z-index:100;background:rgba(32,25,35,.55);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:1rem;}
    .modal-bg.open{display:flex;}
    .ciclo-dot{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;}
    .cdot-feito{background:#1D9E75;color:white;}
    .cdot-prox{background:#E1F5EE;border:2px solid #1D9E75;color:#085041;}
    .cdot-vazio{background:#f5f5f3;border:1px solid #ddd;color:#aaa;}
  </style>
</head>
<body class="min-h-screen">

<!-- TOPNAV -->
<nav class="fixed top-0 w-full z-50 bg-white/75 backdrop-blur-md shadow-sm border-b border-purple-100/40">
  <div class="flex justify-between items-center px-5 md:px-8 h-14 max-w-[1600px] mx-auto">
    <div class="flex items-center gap-6">
      <span class="text-lg font-extrabold headline"
            style="background:linear-gradient(135deg,#4e0078,#b7004d);-webkit-background-clip:text;-webkit-text-fill-color:transparent">
        NUPICS
      </span>
      <div class="hidden md:flex overflow-x-auto gap-0">
        <button class="nav-tab on" onclick="setAba('home')">Dashboard</button>
        <button class="nav-tab" onclick="setAba('agenda')">Agendamentos</button>
        <button class="nav-tab" onclick="setAba('ciclos')">Ciclos</button>
        <button class="nav-tab" onclick="setAba('pacientes_tab')">Pacientes</button>
        <button class="nav-tab" onclick="window.location='../terapeuta/plantoes.php'">Cronograma</button>
        <button class="nav-tab" onclick="setAba('equipe')">Equipe</button>
        <button class="nav-tab" onclick="setAba('gestao')">Gestão</button>
        <button class="nav-tab" onclick="setAba('conteudo')">Conteúdo</button>
      </div>
    </div>
    <div class="flex items-center gap-2">
      <div class="relative hidden md:block">
        <span class="material-symbols-outlined absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" style="font-size:16px">search</span>
        <input type="text" id="busca-global" placeholder="Buscar paciente..."
               oninput="buscarPacs(this.value)"
               class="bg-gray-100 rounded-full py-1.5 pl-8 pr-3 text-xs w-44 focus:outline-none focus:ring-2 focus:ring-purple-200">
        <div id="busca-res" class="absolute top-full left-0 right-0 bg-white border border-gray-100 rounded-xl shadow-lg mt-1 hidden z-10 text-sm"></div>
      </div>
      <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold"
           style="background:#E1F5EE;color:#085041"><?= ini($_SESSION['nome']) ?></div>
      <span class="text-xs text-gray-600 hidden md:block font-medium"><?= htmlspecialchars($primeiro) ?></span>
      <a href="../api/trocar_senha.php" class="text-xs text-gray-400 border border-gray-200 rounded-full px-2.5 py-1 hover:text-purple-700 transition-colors hidden md:block">Senha</a>
      <a href="../api/logout.php" class="text-xs text-gray-400 border border-gray-200 rounded-full px-2.5 py-1 hover:text-purple-700 transition-colors">Sair</a>
    </div>
  </div>
</nav>

<main class="pt-16 pb-12 px-4 md:px-8 max-w-[1600px] mx-auto">

<?php if($sucesso): ?>
<div class="mt-3 mb-3 bg-green-50 border border-green-200 text-green-800 rounded-xl px-4 py-2.5 text-sm font-medium"><?= htmlspecialchars($sucesso) ?></div>
<?php endif; ?>
<?php if($erro): ?>
<div class="mt-3 mb-3 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-2.5 text-sm font-medium"><?= htmlspecialchars($erro) ?></div>
<?php endif; ?>

<!-- ══ DASHBOARD HOME ══ -->
<div id="aba-home">
  <!-- Hero -->
  <div class="grad rounded-3xl p-6 text-white relative overflow-hidden flex flex-col md:flex-row items-center justify-between mb-6 shadow-xl mt-3">
    <div class="relative z-10">
      <h1 class="text-2xl font-extrabold headline">Bem-vindo, <?= htmlspecialchars($primeiro) ?>.</h1>
      <p class="text-white/80 text-sm mt-1"><?= $metricas['hoje'] ?> atendimentos hoje · <?= $metricas['plantoes_hoje'] ?> plantões registrados · <?= $metricas['sessoes_mes'] ?> sessões no mês</p>
    </div>
    <div class="relative z-10 mt-4 md:mt-0 flex gap-3">
      <button onclick="document.getElementById('modal-plantao').classList.add('open')"
              class="bg-white text-purple-900 font-bold px-4 py-2.5 rounded-full flex items-center gap-2 hover:scale-105 transition-transform shadow text-sm">
        <span class="material-symbols-outlined" style="font-size:16px">add_circle</span> Registrar Plantão
      </button>
    </div>
    <div class="absolute -right-16 -top-16 w-56 h-56 bg-white/10 rounded-full blur-3xl"></div>
    <div class="absolute -left-16 -bottom-16 w-56 h-56 bg-pink-500/20 rounded-full blur-3xl"></div>
  </div>

  <!-- Métricas -->
  <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-6">
    <?php $cards=[['calendar_today','Hoje',$metricas['hoje'],'text-purple-700','bg-purple-50'],['event_note','Mês',$metricas['sessoes_mes'],'text-pink-700','bg-pink-50'],['groups','Pacientes',$metricas['total_pacientes'],'text-purple-700','bg-purple-50'],['medical_services','Terapeutas',$metricas['terapeutas_ativos'],'text-pink-700','bg-pink-50'],['autorenew','Ciclos ativos',$metricas['ciclos_ativos'],'text-purple-700','bg-purple-50'],['spa','Plantões hoje',$metricas['plantoes_hoje'],'text-pink-700','bg-pink-50']];foreach($cards as $c): ?>
    <div class="glass border border-white/60 rounded-2xl p-4 hover:-translate-y-0.5 transition-transform shadow-sm">
      <div class="flex items-center gap-1.5 mb-2">
        <span class="material-symbols-outlined <?= $c[3] ?> p-1.5 <?= $c[4] ?> rounded-lg" style="font-size:16px"><?= $c[0] ?></span>
        <span class="text-[10px] font-bold text-gray-500"><?= $c[1] ?></span>
      </div>
      <p class="text-2xl font-extrabold headline text-gray-800"><?= $c[2] ?></p>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-12 gap-5">
    <!-- Agenda do dia -->
    <div class="lg:col-span-8 glass border border-white/60 rounded-3xl overflow-hidden shadow-sm">
      <div class="p-5 border-b border-gray-100 flex justify-between items-center">
        <div><h2 class="text-base font-extrabold headline text-gray-800">Agenda do dia</h2><p class="text-xs text-gray-400">Atendimentos + plantões</p></div>
        <span class="px-3 py-1 bg-purple-50 text-purple-700 rounded-full text-xs font-bold"><?= date('d/m/Y') ?></span>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-left">
          <thead class="bg-gray-50/60"><tr>
            <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Hora</th>
            <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Paciente</th>
            <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Prática</th>
            <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Status</th>
            <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Tipo</th>
          </tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php if(empty($agenda_hoje)): ?>
            <tr><td colspan="5" class="px-5 py-8 text-center text-gray-400 text-sm">Nenhum atendimento hoje.</td></tr>
            <?php else: ?>
            <?php foreach($agenda_hoje as $item): $hi=substr($item['hora_inicio'],0,5); $sc=match($item['status']){'realizado'=>'bg-blue-100 text-blue-700','cancelado','faltou'=>'bg-red-100 text-red-700',default=>'bg-green-100 text-green-700'}; $st=match($item['status']){'realizado'=>'Realizado','cancelado'=>'Cancelado','faltou'=>'Faltou',default=>'Agendado'}; ?>
            <tr class="hover:bg-purple-50/20 transition-colors">
              <td class="px-5 py-3 font-extrabold headline text-purple-700 text-sm"><?= $hi ?></td>
              <td class="px-5 py-3"><div class="flex items-center gap-2"><div class="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold" style="background:#f4dce4;color:#3a2c32"><?= ini($item['paciente_nome']) ?></div><span class="text-sm font-medium text-gray-800"><?= htmlspecialchars($item['paciente_nome']) ?></span></div></td>
              <td class="px-5 py-3 text-gray-500 text-sm"><?= htmlspecialchars($item['terapia']??'—') ?></td>
              <td class="px-5 py-3"><span class="px-2 py-0.5 text-[10px] font-bold rounded-full <?= $sc ?>"><?= $st ?></span></td>
              <td class="px-5 py-3"><span class="px-2 py-0.5 text-[10px] font-bold rounded-full <?= $item['tipo']==='plantao'?'bg-purple-100 text-purple-700':'bg-gray-100 text-gray-500' ?>"><?= $item['tipo']==='plantao'?'Plantão':'Agendado' ?></span></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Coluna direita do home -->
    <div class="lg:col-span-4 space-y-4">
      <!-- Gráfico -->
      <div class="glass border border-white/60 rounded-3xl p-5 shadow-sm">
        <h2 class="text-sm font-extrabold headline text-gray-800 mb-4">Atendimentos — semana</h2>
        <div class="flex items-end gap-2 h-28">
          <?php $dias_b=[2=>'Seg',3=>'Ter',4=>'Qua',5=>'Qui',6=>'Sex']; foreach($dias_b as $d=>$nm): $v=$sessoes_semana[$d]??0; $p=$max_bar>0?round(($v/$max_bar)*100):0; ?>
          <div class="flex-1 flex flex-col items-center gap-0.5">
            <span class="text-[9px] font-bold text-gray-400"><?= $v ?></span>
            <div class="w-full rounded-t-lg" style="height:<?= max(4,$p) ?>%;background:<?= $d==4?'linear-gradient(135deg,#4e0078,#b7004d)':'rgba(78,0,120,.2)' ?>;min-height:5px"></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="flex justify-between mt-1"><?php foreach($dias_b as $nm): ?><span class="flex-1 text-center text-[9px] font-bold text-gray-400"><?= $nm ?></span><?php endforeach; ?></div>
      </div>

      <!-- Práticas top -->
      <div class="glass border border-white/60 rounded-3xl p-5 shadow-sm">
        <h2 class="text-sm font-extrabold headline text-gray-800 mb-3">Top práticas</h2>
        <?php if(empty($top_praticas)): ?><p class="text-xs text-gray-400 text-center py-3">Registre plantões para ver dados.</p>
        <?php else: ?>
        <?php $icons=['Massoterapia'=>'dry_cleaning','Ventosaterapia'=>'air','Acupuntura'=>'spa','Reiki'=>'self_improvement','Escalda-pés'=>'water_drop']; foreach($top_praticas as $idx=>$pr): ?>
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-full flex items-center justify-center <?= $idx==0?'bg-purple-100 text-purple-700':($idx==1?'bg-pink-100 text-pink-700':'bg-gray-100 text-gray-500') ?>">
              <span class="material-symbols-outlined" style="font-size:16px"><?= $icons[$pr['tipo_pratica']]??'spa' ?></span>
            </div>
            <div><p class="text-xs font-bold text-gray-800"><?= htmlspecialchars($pr['tipo_pratica']) ?></p><p class="text-[10px] text-gray-400"><?= $pr['total'] ?> atend.</p></div>
          </div>
          <span class="text-xs font-extrabold <?= $idx==0?'text-purple-700':($idx==1?'text-pink-700':'text-gray-400') ?>">#<?= $idx+1 ?></span>
        </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- Avisos -->
      <div class="glass border border-white/60 rounded-3xl p-5 shadow-sm">
        <div class="flex justify-between items-center mb-3">
          <h2 class="text-sm font-extrabold headline text-gray-800">Avisos internos</h2>
          <button onclick="document.getElementById('modal-aviso').classList.add('open')" class="text-[10px] font-bold text-purple-600 hover:underline">+ Novo</button>
        </div>
        <?php if(empty($avisos)): ?><p class="text-xs text-gray-400 py-2 text-center">Nenhum aviso.</p>
        <?php else: ?>
        <?php $avc=['manutencao'=>'border-purple-400 bg-purple-50','evento'=>'border-pink-400 bg-pink-50','urgente'=>'border-red-400 bg-red-50','info'=>'border-blue-400 bg-blue-50'];$avt=['manutencao'=>'text-purple-700','evento'=>'text-pink-700','urgente'=>'text-red-700','info'=>'text-blue-700']; foreach($avisos as $av): $ac=$avc[$av['tipo']]??'border-gray-300 bg-gray-50'; $at=$avt[$av['tipo']]??'text-gray-600'; ?>
        <div class="p-2.5 <?= $ac ?> rounded-xl border-l-4 mb-2 flex justify-between items-start">
          <div><p class="text-[9px] font-extrabold <?= $at ?> uppercase tracking-wider mb-0.5"><?= $tipos_aviso[$av['tipo']] ?></p><p class="text-xs font-medium text-gray-800"><?= htmlspecialchars($av['texto']) ?></p></div>
          <form method="POST" style="margin:0"><input type="hidden" name="acao" value="del_aviso"><input type="hidden" name="aviso_id" value="<?= $av['id'] ?>"><button type="submit" class="text-gray-300 hover:text-red-400 ml-1 text-xs">✕</button></form>
        </div>
        <?php endforeach; endif; ?>
      </div>

      <!-- Frase do dia -->
      <div class="rounded-3xl p-5 relative overflow-hidden" style="background:linear-gradient(160deg,#fff7fc,#fde7f3)">
        <span class="material-symbols-outlined absolute top-3 right-3 opacity-15 rotate-12" style="font-size:56px;color:#3a2c32">format_quote</span>
        <p class="text-xs font-extrabold text-purple-500 uppercase tracking-wider mb-2">Inspiração do dia</p>
        <p class="text-sm italic leading-relaxed text-purple-900 relative z-10"><?= htmlspecialchars($frase_hoje) ?></p>
      </div>
    </div>
  </div>
</div>

<!-- ══ AGENDAMENTOS ══ -->
<div id="aba-agenda" style="display:none">
  <div class="mt-4 glass border border-white/60 rounded-3xl overflow-hidden shadow-sm">
    <div class="p-5 border-b border-gray-100 flex justify-between items-center">
      <h2 class="text-base font-extrabold headline text-gray-800">Semana atual — toda a equipe</h2>
      <button onclick="document.getElementById('modal-plantao').classList.add('open')"
              class="grad text-white text-xs font-bold px-4 py-2 rounded-full flex items-center gap-1">
        <span class="material-symbols-outlined" style="font-size:14px">add</span> Registrar Plantão
      </button>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-left">
        <thead class="bg-gray-50/60"><tr>
          <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Data/Hora</th>
          <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Paciente</th>
          <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Terapeuta</th>
          <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Prática</th>
          <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Status</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php if(empty($agendamentos_semana)): ?><tr><td colspan="5" class="px-5 py-8 text-center text-gray-400 text-sm">Nenhum agendamento esta semana.</td></tr>
          <?php else: ?>
          <?php foreach($agendamentos_semana as $s): $hi=substr($s['hora_inicio'],0,5); $df=date('d/m',strtotime($s['data'])); $sc=match($s['status']){'realizado'=>'bg-blue-100 text-blue-700','cancelado'=>'bg-red-100 text-red-700',default=>'bg-green-100 text-green-700'}; $st=match($s['status']){'realizado'=>'Realizado','cancelado'=>'Cancelado',default=>'Agendado'}; ?>
          <tr class="hover:bg-purple-50/20 transition-colors">
            <td class="px-5 py-3 font-bold text-purple-700 text-sm"><?= $dias_pt[date('N',strtotime($s['data']))] ?> <?= $df ?> <?= $hi ?></td>
            <td class="px-5 py-3"><div class="flex items-center gap-2"><div class="w-6 h-6 rounded-full flex items-center justify-center text-[9px] font-bold" style="background:#f4dce4;color:#3a2c32"><?= ini($s['paciente_nome']) ?></div><span class="text-sm text-gray-800"><?= htmlspecialchars($s['paciente_nome']) ?></span></div></td>
            <td class="px-5 py-3 text-xs text-gray-500"><?= htmlspecialchars($s['terapeuta_nome']??'A definir') ?></td>
            <td class="px-5 py-3 text-xs text-gray-500"><?= htmlspecialchars($s['especialidade']??'—') ?></td>
            <td class="px-5 py-3"><span class="px-2 py-0.5 text-[10px] font-bold rounded-full <?= $sc ?>"><?= $st ?></span></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══ CICLOS ══ -->
<div id="aba-ciclos" style="display:none">
  <div class="mt-4 glass border border-white/60 rounded-3xl p-5 shadow-sm">
    <h2 class="text-base font-extrabold headline text-gray-800 mb-4">Ciclos ativos</h2>
    <?php if(empty($ciclos_todos)): ?><p class="text-sm text-gray-400 py-8 text-center">Nenhum ciclo ativo.</p>
    <?php else: ?>
    <?php foreach($ciclos_todos as $c): $f=(int)$c['feitas']; $tot=(int)$c['total_sessoes']; ?>
    <div class="flex items-center gap-4 p-3 rounded-2xl hover:bg-purple-50/30 transition-colors mb-2 flex-wrap">
      <div class="min-w-[160px]">
        <div class="text-sm font-bold text-gray-800"><?= htmlspecialchars($c['paciente_nome']) ?></div>
        <div class="text-xs text-gray-400"><?= htmlspecialchars($c['terapeuta_nome']??'—') ?> · <?= htmlspecialchars($c['especialidade']??'—') ?></div>
      </div>
      <div class="flex gap-1.5 flex-1">
        <?php for($i=1;$i<=$tot;$i++): if($i<=$f)$cls='cdot-feito'; elseif($i===$f+1)$cls='cdot-prox'; else $cls='cdot-vazio'; ?>
        <div class="ciclo-dot <?= $cls ?>"><?= $i ?></div>
        <?php endfor; ?>
      </div>
      <div class="flex gap-2">
        <form method="POST" style="margin:0"><input type="hidden" name="acao" value="estender_ciclo"><input type="hidden" name="ciclo_id" value="<?= $c['id'] ?>"><button type="submit" onclick="return confirm('Adicionar sessão?')" class="text-xs text-blue-500 hover:underline font-bold border border-blue-200 px-2 py-1 rounded-lg">+sessão</button></form>
        <form method="POST" style="margin:0"><input type="hidden" name="acao" value="concluir_ciclo"><input type="hidden" name="ciclo_id" value="<?= $c['id'] ?>"><button type="submit" onclick="return confirm('Concluir?')" class="text-xs text-pink-500 hover:underline font-bold border border-pink-200 px-2 py-1 rounded-lg">Concluir</button></form>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- ══ PACIENTES ══ -->
<div id="aba-pacientes_tab" style="display:none">
  <div class="mt-4 glass border border-white/60 rounded-3xl overflow-hidden shadow-sm">
    <div class="p-5 border-b border-gray-100"><h2 class="text-base font-extrabold headline text-gray-800">Todos os pacientes</h2></div>
    <div class="overflow-x-auto">
      <table class="w-full text-left">
        <thead class="bg-gray-50/60"><tr>
          <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Paciente</th>
          <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Terapeuta</th>
          <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Ciclo</th>
          <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Sessões</th>
          <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Ações</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-50">
          <?php foreach($pacientes as $i=>$p): $cor=$cores[$i%count($cores)]; $bdg=match($p['ciclo_status']??''){'ativo'=>'bg-green-100 text-green-700','concluido'=>'bg-yellow-100 text-yellow-800',default=>'bg-gray-100 text-gray-400'}; $bdt=match($p['ciclo_status']??''){'ativo'=>'Ativo','concluido'=>'Concluído',default=>'Sem ciclo'}; ?>
          <tr class="hover:bg-purple-50/20 transition-colors">
            <td class="px-5 py-3"><div class="flex items-center gap-2"><div class="w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold" style="background:<?= $cor['bg'] ?>;color:<?= $cor['txt'] ?>"><?= ini($p['nome']) ?></div><div><div class="text-sm font-bold text-gray-800"><?= htmlspecialchars($p['nome']) ?></div><div class="text-xs text-gray-400"><?= htmlspecialchars($p['email']) ?></div></div></div></td>
            <td class="px-5 py-3 text-xs text-gray-500"><?= $p['terapeuta_nome']?htmlspecialchars($p['terapeuta_nome']):'—' ?></td>
            <td class="px-5 py-3"><span class="px-2 py-0.5 text-[10px] font-bold rounded-full <?= $bdg ?>"><?= $bdt ?></span></td>
            <td class="px-5 py-3 text-xs text-gray-500"><?= $p['ciclo_status']==='ativo'?$p['sessoes_feitas'].'/'.$p['total_sessoes']:'—' ?></td>
            <td class="px-5 py-3">
              <?php if($p['ciclo_status']==='ativo'): ?>
              <div class="flex gap-1">
                <form method="POST" style="margin:0"><input type="hidden" name="acao" value="estender_ciclo"><input type="hidden" name="ciclo_id" value="<?= $p['ciclo_id'] ?>"><button type="submit" onclick="return confirm('Adicionar sessão?')" class="text-[10px] text-blue-500 hover:underline font-bold">+sess.</button></form>
                <form method="POST" style="margin:0"><input type="hidden" name="acao" value="concluir_ciclo"><input type="hidden" name="ciclo_id" value="<?= $p['ciclo_id'] ?>"><button type="submit" onclick="return confirm('Concluir?')" class="text-[10px] text-pink-500 hover:underline font-bold ml-2">Concluir</button></form>
              </div>
              <?php else: ?><span class="text-gray-300 text-xs">—</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ══ EQUIPE ══ -->
<div id="aba-equipe" style="display:none">
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mt-4">
    <div class="lg:col-span-2 glass border border-white/60 rounded-3xl p-5 shadow-sm">
      <h2 class="text-base font-extrabold headline text-gray-800 mb-4">Equipe de terapeutas</h2>
      <?php foreach($terapeutas as $i=>$t): $cor=$cores[$i%count($cores)]; ?>
      <div class="flex items-center gap-3 p-3 rounded-2xl hover:bg-purple-50/30 transition-colors mb-2">
        <div class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold flex-shrink-0" style="background:<?= $cor['bg'] ?>;color:<?= $cor['txt'] ?>"><?= ini($t['nome']) ?></div>
        <div class="flex-1 min-w-0">
          <div class="text-sm font-bold text-gray-800"><?= htmlspecialchars($t['nome']) ?></div>
          <div class="text-xs text-gray-400"><?= htmlspecialchars($t['especialidade']??'—') ?><?= $t['periodo']?' · '.htmlspecialchars($t['periodo']):'' ?></div>
          <div class="text-xs text-gray-400"><?= htmlspecialchars($t['email']) ?></div>
        </div>
        <div class="flex-shrink-0 text-right">
          <div class="flex gap-1 justify-end mb-1">
            <span class="px-2 py-0.5 text-[10px] font-bold rounded-full <?= $t['ativo']?'bg-green-100 text-green-700':'bg-red-100 text-red-700' ?>"><?= $t['ativo']?'Ativo':'Inativo' ?></span>
            <span class="px-2 py-0.5 bg-gray-100 text-gray-500 text-[10px] font-bold rounded-full"><?= $t['sessoes_mes'] ?> sess.</span>
          </div>
          <form method="POST" style="margin:0">
            <?php if($t['ativo']): ?><input type="hidden" name="acao" value="desativar_terapeuta"><input type="hidden" name="terapeuta_id" value="<?= $t['id'] ?>"><button type="submit" onclick="return confirm('Desativar?')" class="text-[10px] text-red-400 hover:underline font-bold">Desativar</button>
            <?php else: ?><input type="hidden" name="acao" value="reativar_terapeuta"><input type="hidden" name="terapeuta_id" value="<?= $t['id'] ?>"><button type="submit" class="text-[10px] text-blue-500 hover:underline font-bold">Reativar</button><?php endif; ?>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="glass border border-white/60 rounded-3xl p-5 shadow-sm h-fit">
      <h2 class="text-base font-extrabold headline text-gray-800 mb-4">Cadastrar usuário</h2>
      <form method="POST" class="space-y-3">
        <input type="hidden" name="acao" value="cadastrar_usuario">
        <select name="tipo" id="tipo-sel" required onchange="toggleCadCampos()" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400"><option value="">Tipo *</option><option value="paciente">Paciente</option><option value="terapeuta">Terapeuta</option><option value="coordenador">Coordenador</option></select>
        <input type="text" name="nome" placeholder="Nome completo *" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400">
        <input type="email" name="email" placeholder="E-mail *" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400">
        <input type="text" name="senha" placeholder="Senha inicial *" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400">
        <input type="text" name="telefone" placeholder="Telefone" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400">
        <div id="cad-ter" style="display:none" class="space-y-3"><input type="text" name="especialidade" placeholder="Especialidade" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400"><input type="text" name="periodo" placeholder="Período UERN" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400"></div>
        <div id="cad-pac" style="display:none"><input type="text" name="cpf" placeholder="CPF" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400"></div>
        <button type="submit" class="w-full py-2.5 grad text-white text-sm font-bold rounded-xl hover:opacity-90">Cadastrar</button>
      </form>
    </div>
  </div>
</div>

<!-- ══ GESTÃO ══ -->
<div id="aba-gestao" style="display:none">
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-4">

    <!-- Grade horária -->
    <div class="glass border border-white/60 rounded-3xl p-5 shadow-sm">
      <h2 class="text-base font-extrabold headline text-gray-800 mb-4">Grade de Horários</h2>
      <form method="POST" class="grid grid-cols-2 gap-2 mb-4">
        <input type="hidden" name="acao" value="add_horario">
        <select name="dia_semana" required class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-purple-400"><option value="">Dia</option><?php foreach($dias_sel as $v=>$l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?></select>
        <select name="hora_inicio" required class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-purple-400"><option value="">Hora</option><?php for($h=7;$h<=20;$h++):foreach(['00','30'] as $m):$v=sprintf('%02d:%02d',$h,$m); ?><option value="<?= $v ?>"><?= $v ?></option><?php endforeach;endfor; ?></select>
        <select name="duracao" class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-purple-400"><option value="30">30 min</option><option value="45">45 min</option><option value="60">60 min</option></select>
        <select name="vagas" class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-purple-400"><?php for($v=1;$v<=8;$v++): ?><option value="<?= $v ?>" <?= $v==4?'selected':'' ?>><?= $v ?> vaga<?= $v!=1?'s':'' ?></option><?php endfor; ?></select>
        <button type="submit" class="col-span-2 grad text-white text-sm font-bold py-2 rounded-xl">+ Adicionar horário</button>
      </form>
      <?php foreach($todos_horarios as $h): $hi=substr($h['hora_inicio'],0,5);$hf=date('H:i',strtotime($h['hora_inicio'])+$h['duracao_minutos']*60); ?>
      <div class="flex items-center justify-between py-2 border-b border-gray-50 text-sm <?= !$h['ativo']?'opacity-40':'' ?>">
        <span class="font-bold text-purple-700"><?= $dias_sel[$h['dia_semana']]??'' ?> <?= $hi ?>–<?= $hf ?></span>
        <div class="flex items-center gap-3">
          <form method="POST" style="display:flex;align-items:center;gap:4px;margin:0">
            <input type="hidden" name="acao" value="editar_vagas"><input type="hidden" name="horario_id" value="<?= $h['id'] ?>">
            <select name="vagas" class="text-xs border border-gray-200 rounded-lg px-1.5 py-1 focus:outline-none"><?php for($v=1;$v<=8;$v++): ?><option value="<?= $v ?>" <?= $v==$h['vagas_total']?'selected':'' ?>><?= $v ?>v</option><?php endfor; ?></select>
            <button type="submit" class="text-[10px] text-blue-500 hover:underline font-bold">Salvar</button>
          </form>
          <form method="POST" style="margin:0"><input type="hidden" name="acao" value="toggle_horario"><input type="hidden" name="horario_id" value="<?= $h['id'] ?>"><input type="hidden" name="estado" value="<?= $h['ativo']?0:1 ?>"><button type="submit" onclick="return confirm('<?= $h['ativo']?'Desativar':'Reativar' ?>?')" class="text-[10px] <?= $h['ativo']?'text-red-400':'text-green-500' ?> hover:underline font-bold"><?= $h['ativo']?'Desativar':'Reativar' ?></button></form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Visitas externas -->
    <div class="glass border border-white/60 rounded-3xl p-5 shadow-sm">
      <h2 class="text-base font-extrabold headline text-gray-800 mb-4">Visitas Externas</h2>
      <?php
      $pend_v=count(array_filter($visitas,fn($v)=>$v['status']==='pendente'));
      $aprov_v=count(array_filter($visitas,fn($v)=>$v['status']==='aprovada'));
      $real_v=count(array_filter($visitas,fn($v)=>$v['status']==='realizada'));
      ?>
      <div class="grid grid-cols-3 gap-2 mb-4">
        <div class="text-center p-2 bg-yellow-50 rounded-xl"><div class="text-lg font-extrabold text-yellow-700"><?= $pend_v ?></div><div class="text-[9px] font-bold text-yellow-600 uppercase">Pendentes</div></div>
        <div class="text-center p-2 bg-blue-50 rounded-xl"><div class="text-lg font-extrabold text-blue-700"><?= $aprov_v ?></div><div class="text-[9px] font-bold text-blue-600 uppercase">Aprovadas</div></div>
        <div class="text-center p-2 bg-green-50 rounded-xl"><div class="text-lg font-extrabold text-green-700"><?= $real_v ?></div><div class="text-[9px] font-bold text-green-600 uppercase">Realizadas</div></div>
      </div>
      <?php if(empty($visitas)): ?><p class="text-xs text-gray-400 text-center py-4">Nenhuma solicitação.</p>
      <?php else: ?>
      <?php $si=['aprovada'=>'bg-blue-100 text-blue-700','realizada'=>'bg-green-100 text-green-700','cancelada'=>'bg-red-100 text-red-700']; foreach($visitas as $v): $sc=$si[$v['status']]??'bg-yellow-100 text-yellow-700'; ?>
      <div class="flex items-start justify-between py-3 border-b border-gray-50 gap-2">
        <div class="flex-1 min-w-0">
          <div class="text-sm font-bold text-gray-800 truncate"><?= htmlspecialchars($v['local_nome']) ?></div>
          <div class="text-xs text-gray-400"><?= htmlspecialchars($v['solicitante_nome']) ?> · <?= $tipos_visita[$v['local_tipo']]??$v['local_tipo'] ?><?= $v['data_sugerida']?' · '.date('d/m/Y',strtotime($v['data_sugerida'])):'' ?></div>
          <?php if($v['observacao']): ?><div class="text-xs text-gray-500 mt-1 bg-gray-50 rounded-lg p-2"><?= htmlspecialchars($v['observacao']) ?></div><?php endif; ?>
        </div>
        <form method="POST" class="flex items-center gap-1 flex-shrink-0" style="margin:0">
          <input type="hidden" name="acao" value="status_visita"><input type="hidden" name="visita_id" value="<?= $v['id'] ?>">
          <select name="novo_status" class="text-[10px] border border-gray-200 rounded-lg px-1.5 py-1 focus:outline-none"><?php foreach(['pendente','aprovada','realizada','cancelada'] as $s): ?><option value="<?= $s ?>" <?= $v['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select>
          <button type="submit" class="text-[10px] text-purple-600 hover:underline font-bold">Ok</button>
        </form>
      </div>
      <?php endforeach; endif; ?>
    </div>

    <!-- Histórico plantões -->
    <div class="lg:col-span-2 glass border border-white/60 rounded-3xl overflow-hidden shadow-sm">
      <div class="p-5 border-b border-gray-100"><h2 class="text-base font-extrabold headline text-gray-800">Histórico de Plantões</h2></div>
      <div class="overflow-x-auto">
        <table class="w-full text-left">
          <thead class="bg-gray-50/60"><tr>
            <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Data/Hora</th>
            <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Paciente</th>
            <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Terapeuta</th>
            <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Prática</th>
            <th class="px-5 py-3 text-[10px] font-bold uppercase tracking-widest text-gray-400">Cadastrado?</th>
          </tr></thead>
          <tbody class="divide-y divide-gray-50">
            <?php if(empty($historico_plantoes)): ?><tr><td colspan="5" class="px-5 py-6 text-center text-gray-400 text-sm">Nenhum plantão registrado.</td></tr>
            <?php else: ?>
            <?php foreach($historico_plantoes as $sp): $dt=date('d/m/Y',strtotime($sp['data'])); $hi=substr($sp['hora_inicio'],0,5); ?>
            <tr class="hover:bg-purple-50/20 transition-colors">
              <td class="px-5 py-3 font-bold text-purple-700 text-xs"><?= $dt ?> <?= $hi ?></td>
              <td class="px-5 py-3"><div class="flex items-center gap-2"><div class="w-6 h-6 rounded-full flex items-center justify-center text-[9px] font-bold" style="background:#f4dce4;color:#3a2c32"><?= ini($sp['paciente_nome']) ?></div><span class="text-xs font-medium text-gray-800"><?= htmlspecialchars($sp['paciente_nome']) ?></span></div></td>
              <td class="px-5 py-3 text-xs text-gray-500"><?= htmlspecialchars($sp['terapeuta_nome']) ?></td>
              <td class="px-5 py-3 text-xs text-gray-500"><?= htmlspecialchars($sp['tipo_pratica']) ?></td>
              <td class="px-5 py-3"><span class="px-2 py-0.5 text-[9px] font-bold rounded-full <?= $sp['paciente_id']?'bg-green-100 text-green-700':'bg-gray-100 text-gray-400' ?>"><?= $sp['paciente_id']?'Sim — no histórico':'Não cadastrado' ?></span></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ══ CONTEÚDO (frases + playlists) ══ -->
<div id="aba-conteudo" style="display:none">
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-4">

    <!-- Frases dos pacientes -->
    <div class="glass border border-white/60 rounded-3xl p-5 shadow-sm">
      <h2 class="text-base font-extrabold headline text-gray-800 mb-1">Frases para pacientes</h2>
      <p class="text-xs text-gray-400 mb-4">Aparecem rotativamente no dashboard do paciente.</p>
      <form method="POST" class="flex gap-2 mb-4">
        <input type="hidden" name="acao" value="add_frase">
        <input type="hidden" name="frase_tipo" value="paciente">
        <input type="text" name="frase_texto" placeholder="Nova frase inspiradora..." required class="flex-1 border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-purple-400">
        <button type="submit" class="grad text-white text-xs font-bold px-4 py-2 rounded-xl whitespace-nowrap">Adicionar</button>
      </form>
      <div class="space-y-2 max-h-80 overflow-y-auto pr-1">
        <?php foreach($frases_paciente as $fr): ?>
        <div class="flex items-start gap-2 p-3 bg-pink-50/60 rounded-xl border border-pink-100">
          <p class="text-sm italic text-gray-700 flex-1 leading-relaxed"><?= htmlspecialchars($fr['texto']) ?></p>
          <form method="POST" style="margin:0;flex-shrink:0">
            <input type="hidden" name="acao" value="del_frase">
            <input type="hidden" name="frase_id" value="<?= $fr['id'] ?>">
            <button type="submit" onclick="return confirm('Remover frase?')" class="text-gray-300 hover:text-red-400 text-xs">✕</button>
          </form>
        </div>
        <?php endforeach; ?>
        <?php if(empty($frases_paciente)): ?><p class="text-xs text-gray-400 text-center py-4">Nenhuma frase cadastrada.</p><?php endif; ?>
      </div>
    </div>

    <!-- Frases dos terapeutas -->
    <div class="glass border border-white/60 rounded-3xl p-5 shadow-sm">
      <h2 class="text-base font-extrabold headline text-gray-800 mb-1">Frases para terapeutas</h2>
      <p class="text-xs text-gray-400 mb-4">Aparecem rotativamente no dashboard do terapeuta.</p>
      <form method="POST" class="flex gap-2 mb-4">
        <input type="hidden" name="acao" value="add_frase">
        <input type="hidden" name="frase_tipo" value="terapeuta">
        <input type="text" name="frase_texto" placeholder="Nova frase inspiradora..." required class="flex-1 border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-purple-400">
        <button type="submit" class="grad text-white text-xs font-bold px-4 py-2 rounded-xl whitespace-nowrap">Adicionar</button>
      </form>
      <div class="space-y-2 max-h-80 overflow-y-auto pr-1">
        <?php foreach($frases_terapeuta as $fr): ?>
        <div class="flex items-start gap-2 p-3 bg-purple-50/60 rounded-xl border border-purple-100">
          <p class="text-sm italic text-gray-700 flex-1 leading-relaxed"><?= htmlspecialchars($fr['texto']) ?></p>
          <form method="POST" style="margin:0;flex-shrink:0">
            <input type="hidden" name="acao" value="del_frase">
            <input type="hidden" name="frase_id" value="<?= $fr['id'] ?>">
            <button type="submit" onclick="return confirm('Remover frase?')" class="text-gray-300 hover:text-red-400 text-xs">✕</button>
          </form>
        </div>
        <?php endforeach; ?>
        <?php if(empty($frases_terapeuta)): ?><p class="text-xs text-gray-400 text-center py-4">Nenhuma frase cadastrada.</p><?php endif; ?>
      </div>
    </div>

    <!-- Playlists terapêuticas -->
    <div class="lg:col-span-2 glass border border-white/60 rounded-3xl p-5 shadow-sm">
      <h2 class="text-base font-extrabold headline text-gray-800 mb-1">Playlists terapêuticas</h2>
      <p class="text-xs text-gray-400 mb-4">Aparecem no ambiente terapêutico do portal do paciente e do terapeuta.</p>
      <form method="POST" class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
        <input type="hidden" name="acao" value="add_playlist">
        <input type="text" name="pl_emoji" placeholder="Emoji (ex: 🌿)" class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-purple-400">
        <input type="text" name="pl_nome" placeholder="Nome da playlist *" required class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-purple-400">
        <input type="url" name="pl_url" placeholder="URL do YouTube *" required class="border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-purple-400">
        <button type="submit" class="grad text-white text-sm font-bold rounded-xl hover:opacity-90">+ Adicionar</button>
      </form>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
        <?php foreach($playlists_db as $pl): ?>
        <div class="flex items-center gap-3 p-3 bg-purple-50/50 border border-purple-100 rounded-2xl">
          <span class="text-2xl"><?= $pl['emoji'] ?></span>
          <div class="flex-1 min-w-0">
            <div class="text-sm font-bold text-gray-800"><?= htmlspecialchars($pl['nome']) ?></div>
            <a href="<?= htmlspecialchars($pl['url']) ?>" target="_blank" class="text-xs text-purple-500 hover:underline truncate block">Abrir no YouTube ↗</a>
          </div>
          <form method="POST" style="margin:0">
            <input type="hidden" name="acao" value="del_playlist">
            <input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
            <button type="submit" onclick="return confirm('Remover playlist?')" class="text-gray-300 hover:text-red-400 text-xs">✕</button>
          </form>
        </div>
        <?php endforeach; ?>
        <?php if(empty($playlists_db)): ?><p class="text-xs text-gray-400 col-span-3 text-center py-4">Nenhuma playlist cadastrada.</p><?php endif; ?>
      </div>
    </div>

  </div>
</div>

</main>

<!-- FAB -->
<button onclick="document.getElementById('modal-plantao').classList.add('open')"
        class="fixed bottom-7 right-7 w-13 h-13 grad rounded-full shadow-2xl flex items-center justify-center text-white hover:scale-110 transition-transform z-40 w-14 h-14">
  <span class="material-symbols-outlined text-2xl">add</span>
</button>

<!-- MODAL: Plantão -->
<div class="modal-bg" id="modal-plantao">
  <div class="bg-white rounded-3xl w-full max-w-lg shadow-2xl overflow-hidden">
    <div class="flex justify-between items-center px-6 pt-5 pb-4 border-b border-gray-100">
      <h3 class="text-base font-extrabold headline text-gray-800">Registrar Sessão de Plantão</h3>
      <button onclick="document.getElementById('modal-plantao').classList.remove('open')" class="w-8 h-8 rounded-full bg-gray-100 hover:bg-gray-200 flex items-center justify-center text-gray-500">✕</button>
    </div>
    <form method="POST" class="px-6 py-5 space-y-3">
      <input type="hidden" name="acao" value="registrar_plantao">
      <input type="hidden" name="paciente_id" id="pac-id-hidden" value="">
      <div>
        <label class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 block">Terapeuta *</label>
        <select name="terapeuta_id" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400">
          <option value="">Selecione</option>
          <?php foreach($terapeutas as $t): if(!$t['ativo']) continue; ?><option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['nome']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="relative">
        <label class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 block">Nome do paciente * <span class="normal-case font-normal text-gray-400">(busca cadastrados)</span></label>
        <input type="text" name="paciente_nome" id="pac-nome-input" placeholder="Digite o nome..." required autocomplete="off" oninput="buscarPacientesModal(this.value)" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400">
        <div id="pac-sugestoes" class="absolute top-full left-0 right-0 bg-white border border-gray-100 rounded-xl shadow-lg mt-1 hidden z-10 max-h-40 overflow-y-auto"></div>
        <div id="pac-vinculado" class="hidden mt-1 px-3 py-1.5 bg-green-50 border border-green-200 rounded-xl text-xs text-green-700 font-medium"></div>
      </div>
      <div>
        <label class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 block">Prática *</label>
        <select name="tipo_pratica" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400"><option value="">Selecione</option><?php foreach(['Massoterapia','Ventosaterapia','Acupuntura','Reiki','Aromaterapia','Auriculoterapia','Escalda-pés','Reflexologia','Meditação','Outra'] as $pr): ?><option value="<?= $pr ?>"><?= $pr ?></option><?php endforeach; ?></select>
      </div>
      <div class="grid grid-cols-3 gap-2">
        <div><label class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 block">Data</label><input type="date" name="data_sessao" value="<?= date('Y-m-d') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-purple-400"></div>
        <div><label class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 block">Hora</label><input type="time" name="hora_sessao" value="<?= date('H:i') ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-purple-400"></div>
        <div><label class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1 block">Status</label><select name="status_sessao" class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm focus:outline-none focus:border-purple-400"><option value="realizado">Realizado</option><option value="faltou">Faltou</option><option value="cancelado">Cancelado</option></select></div>
      </div>
      <textarea name="observacao" rows="2" placeholder="Observações clínicas..." class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400 resize-none"></textarea>
      <button type="submit" class="w-full py-3 grad text-white font-bold text-sm rounded-xl hover:opacity-90">Registrar sessão</button>
    </form>
  </div>
</div>

<!-- MODAL: Aviso -->
<div class="modal-bg" id="modal-aviso">
  <div class="bg-white rounded-3xl w-full max-w-sm shadow-2xl overflow-hidden">
    <div class="flex justify-between items-center px-6 pt-5 pb-4 border-b border-gray-100">
      <h3 class="text-base font-extrabold headline text-gray-800">Novo Aviso</h3>
      <button onclick="document.getElementById('modal-aviso').classList.remove('open')" class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center text-gray-500">✕</button>
    </div>
    <form method="POST" class="px-6 py-5 space-y-3">
      <input type="hidden" name="acao" value="add_aviso">
      <select name="tipo_aviso" class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400"><?php foreach($tipos_aviso as $v=>$l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?></select>
      <input type="text" name="titulo_aviso" placeholder="Título" required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400">
      <textarea name="texto_aviso" rows="3" placeholder="Texto do aviso..." required class="w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm focus:outline-none focus:border-purple-400 resize-none"></textarea>
      <button type="submit" class="w-full py-2.5 grad text-white text-sm font-bold rounded-xl hover:opacity-90">Publicar</button>
    </form>
  </div>
</div>

<script>
var allAbas = ['home','agenda','ciclos','pacientes_tab','equipe','gestao','conteudo'];
function setAba(id) {
  allAbas.forEach(function(a){var el=document.getElementById('aba-'+a);if(el)el.style.display=a===id?'block':'none';});
  document.querySelectorAll('.nav-tab').forEach(function(btn){
    btn.classList.remove('on');
    if(btn.getAttribute('onclick')&&btn.getAttribute('onclick').includes("'"+id+"'"))btn.classList.add('on');
  });
}
function toggleCadCampos(){var t=document.getElementById('tipo-sel').value;document.getElementById('cad-ter').style.display=t==='terapeuta'?'block':'none';document.getElementById('cad-pac').style.display=t==='paciente'?'block':'none';}
['modal-plantao','modal-aviso'].forEach(function(id){document.getElementById(id).addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});});

var pacs_json = <?= json_encode(array_map(function($p){return['id'=>$p['id'],'nome'=>$p['nome']];}, $pacientes)) ?>;
function buscarPacientesModal(q){
  var sug=document.getElementById('pac-sugestoes'),vinc=document.getElementById('pac-vinculado');
  document.getElementById('pac-id-hidden').value='';vinc.classList.add('hidden');
  if(!q||q.length<2){sug.classList.add('hidden');return;}
  var m=pacs_json.filter(function(p){return p.nome.toLowerCase().includes(q.toLowerCase());}).slice(0,6);
  if(!m.length){sug.classList.add('hidden');return;}
  sug.innerHTML=m.map(function(p){return'<div class="px-4 py-2.5 text-sm cursor-pointer hover:bg-purple-50 font-medium text-gray-800" onclick="selecionarPac('+p.id+',\''+p.nome.replace(/\'/g,"\\'")+'\')">' +p.nome+' <span class="text-[10px] text-green-600 font-bold">cadastrado</span></div>';}).join('');
  sug.classList.remove('hidden');
}
function selecionarPac(id,nome){
  document.getElementById('pac-nome-input').value=nome;
  document.getElementById('pac-id-hidden').value=id;
  document.getElementById('pac-sugestoes').classList.add('hidden');
  var v=document.getElementById('pac-vinculado');v.textContent='✓ Paciente cadastrado — sessão entrará no histórico pessoal.';v.classList.remove('hidden');
}

var allPacs = <?= json_encode(array_map(function($p){return['id'=>$p['id'],'nome'=>$p['nome'],'email'=>$p['email']];}, $pacientes)) ?>;
function buscarPacs(q){
  var r=document.getElementById('busca-res');
  if(!q||q.length<2){r.classList.add('hidden');return;}
  var m=allPacs.filter(function(p){return p.nome.toLowerCase().includes(q.toLowerCase());}).slice(0,5);
  if(!m.length){r.classList.add('hidden');return;}
  r.innerHTML=m.map(function(p){return'<div class="px-4 py-2.5 cursor-pointer hover:bg-purple-50"><div class="text-sm font-bold text-gray-800">'+p.nome+'</div><div class="text-xs text-gray-400">'+p.email+'</div></div>';}).join('');
  r.classList.remove('hidden');
}
document.addEventListener('click',function(e){
  if(!e.target.closest('#busca-global')&&!e.target.closest('#busca-res'))document.getElementById('busca-res').classList.add('hidden');
  if(!e.target.closest('#pac-nome-input')&&!e.target.closest('#pac-sugestoes'))document.getElementById('pac-sugestoes').classList.add('hidden');
});
</script>
</body>
</html>