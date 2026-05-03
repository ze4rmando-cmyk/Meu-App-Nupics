<?php
session_start();
// Conexão com banco (para buscar terapeutas na equipe)
$pdo = null;
$db_config = __DIR__.'/config/db.php';
if (file_exists($db_config)) {
    try {
        require_once $db_config;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    } catch (Exception $e) { $pdo = null; }
}

// Já logado → vai direto pro dashboard
if (isset($_SESSION['usuario_id'])) {
    if ($_SESSION['tipo'] === 'paciente')        header('Location: paciente/dashboard.php');
    elseif ($_SESSION['tipo'] === 'coordenador') header('Location: coordenacao/dashboard.php');
    else                                         header('Location: terapeuta/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1.0"/>
<title>NUPICS Caicó — Cuidar, acolher e transformar</title>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,700;1,800&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@400,0&display=swap" rel="stylesheet"/>
<script>
tailwind.config = {
  darkMode:"class",
  theme:{extend:{
    colors:{
      "primary":"#4e0078","on-primary":"#ffffff","primary-container":"#6a1b9a",
      "primary-fixed":"#f4d9ff","primary-fixed-dim":"#e4b5ff","on-primary-fixed":"#2f004b",
      "secondary":"#b7004d","secondary-fixed":"#ffd9de","secondary-fixed-dim":"#ffb2bf",
      "on-secondary-fixed":"#3f0016","secondary-container":"#90003b",
      "surface":"#fff7fc","on-surface":"#201923","surface-variant":"#ecdeed",
      "on-surface-variant":"#4d4351","outline":"#7f7383","outline-variant":"#d0c2d3",
      "surface-container-low":"#fdeffe","surface-container":"#f7eaf8",
      "background":"#fff7fc","on-background":"#201923",
      "tertiary-fixed":"#f4dce4","on-tertiary-fixed-variant":"#524249",
    },
    fontFamily:{"headline":["Plus Jakarta Sans"],"body":["Manrope"]}
  }}
}
</script>
<style>
*,*::before,*::after{box-sizing:border-box}
body{font-family:'Manrope',sans-serif;background:#fff7fc;color:#201923;overflow-x:hidden}
h1,h2,h3,h4{font-family:'Plus Jakarta Sans',sans-serif}
.material-symbols-outlined{font-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24}

/* ── Glassmorphism ── */
.glass{background:rgba(255,255,255,.65);backdrop-filter:blur(18px) saturate(160%);-webkit-backdrop-filter:blur(18px) saturate(160%);border:1px solid rgba(255,255,255,.5)}

/* ── Noise overlay ── */
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='300' height='300'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='300' height='300' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");pointer-events:none;z-index:0;opacity:.4}

/* ── Orbs ── */
.orb{position:absolute;border-radius:50%;filter:blur(90px);pointer-events:none;z-index:0}

/* ── Nav ── */
.nav-link{font-family:'Plus Jakarta Sans',sans-serif;font-weight:600;font-size:.9rem;color:#4d4351;transition:.2s;position:relative}
.nav-link::after{content:'';position:absolute;left:0;bottom:-3px;height:2px;width:0;background:#4e0078;border-radius:2px;transition:.25s}
.nav-link:hover{color:#4e0078}
.nav-link:hover::after{width:100%}
.nav-link.active{color:#4e0078}
.nav-link.active::after{width:100%}

/* ── Buttons ── */
.btn-primary{display:inline-flex;align-items:center;gap:8px;padding:14px 32px;border-radius:99px;background:linear-gradient(135deg,#4e0078,#b7004d);color:#fff;font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:.95rem;border:none;cursor:pointer;transition:all .2s;box-shadow:0 8px 32px rgba(78,0,120,.25)}
.btn-primary:hover{opacity:.9;transform:translateY(-1px);box-shadow:0 12px 40px rgba(78,0,120,.35)}
.btn-primary:active{transform:scale(.98)}
.btn-outline{display:inline-flex;align-items:center;gap:8px;padding:13px 30px;border-radius:99px;border:2px solid rgba(78,0,120,.25);background:rgba(255,255,255,.5);backdrop-filter:blur(10px);color:#4e0078;font-family:'Plus Jakarta Sans',sans-serif;font-weight:700;font-size:.95rem;cursor:pointer;transition:all .2s}
.btn-outline:hover{background:rgba(78,0,120,.06);border-color:#4e0078;transform:translateY(-1px)}
.btn-ghost{display:inline-flex;align-items:center;padding:10px 20px;border-radius:99px;background:transparent;color:#4d4351;font-family:'Plus Jakarta Sans',sans-serif;font-weight:600;font-size:.88rem;border:none;cursor:pointer;transition:.2s}
.btn-ghost:hover{background:rgba(78,0,120,.06);color:#4e0078}

/* ── Cards bento ── */
.card-bento{border-radius:24px;padding:32px;transition:all .3s;position:relative;overflow:hidden}
.card-bento::before{content:'';position:absolute;inset:0;border-radius:24px;padding:1px;background:linear-gradient(135deg,rgba(255,255,255,.8),rgba(255,255,255,.2));-webkit-mask:linear-gradient(#fff 0 0) content-box,linear-gradient(#fff 0 0);-webkit-mask-composite:destination-out;mask-composite:exclude;pointer-events:none}
.card-bento:hover{transform:translateY(-3px);box-shadow:0 24px 60px rgba(78,0,120,.12)}

/* ── Steps ── */
.step-num{width:56px;height:56px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Plus Jakarta Sans',sans-serif;font-weight:800;font-size:1.1rem;background:#fff;color:#4e0078;box-shadow:0 4px 20px rgba(78,0,120,.12);position:relative}
.step-num::before{content:'';position:absolute;inset:-3px;border-radius:50%;background:linear-gradient(135deg,#4e0078,#b7004d);z-index:-1;opacity:.15}

/* ── Avatares ── */
.avatar-ring{border-radius:50%;overflow:hidden;border:3px solid rgba(255,255,255,.8);box-shadow:0 8px 32px rgba(78,0,120,.15);transition:.3s}
.avatar-ring:hover{transform:scale(1.03);box-shadow:0 12px 40px rgba(78,0,120,.25)}

/* ── Scroll reveal ── */
.reveal{opacity:0;transform:translateY(24px);transition:opacity .6s ease,transform .6s ease}
.reveal.visible{opacity:1;transform:none}

/* ── Logo image ── */
.logo-img{height:36px;width:36px;object-fit:contain;filter:drop-shadow(0 2px 8px rgba(78,0,120,.2))}
.logo-img-hero{height:120px;width:120px;object-fit:contain;filter:drop-shadow(0 8px 40px rgba(78,0,120,.3));animation:float 4s ease-in-out infinite}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-12px)}}

/* ── Animação hero title ── */
@keyframes fadeUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:none}}
.hero-anim{animation:fadeUp .8s ease both}
.hero-anim-2{animation:fadeUp .8s .15s ease both}
.hero-anim-3{animation:fadeUp .8s .3s ease both}
.hero-anim-4{animation:fadeUp .8s .45s ease both}

/* ── Divider decorativo ── */
.divider-grad{height:1px;background:linear-gradient(90deg,transparent,rgba(78,0,120,.12),transparent);margin:0 auto}

/* ── Tag ── */
.tag{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:99px;font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
</style>
</head>
<body>

<!-- ═══ HEADER ══════════════════════════════════════════════════════════════════ -->
<header class="fixed top-0 w-full z-50 glass" style="border-bottom:1px solid rgba(208,194,211,.3)">
  <div class="max-w-7xl mx-auto px-6 md:px-10 h-18 flex items-center justify-between" style="height:72px">

    <!-- Logo -->
    <a href="#" class="flex items-center gap-2.5 no-underline">
      <img src="uploads/logo/logo.png" alt="NUPICS" class="logo-img"/>
      <span class="text-xl font-extrabold bg-gradient-to-r from-purple-800 to-pink-600 bg-clip-text text-transparent" style="font-family:'Plus Jakarta Sans',sans-serif">NUPICS</span>
    </a>

    <!-- Nav desktop -->
    <nav class="hidden md:flex items-center gap-8">
      <a href="#sobre"    class="nav-link">Sobre</a>
      <a href="#praticas" class="nav-link">Práticas</a>
      <a href="#equipe"   class="nav-link">Equipe</a>
      <a href="#apoio"    class="nav-link">Apoiar</a>
    </nav>

    <!-- Actions -->
    <div class="flex items-center gap-3">
      <a href="login.php" class="btn-ghost">
        <span class="material-symbols-outlined text-base mr-1">login</span>Entrar
      </a>
      <a href="login.php?modo=cadastro" class="btn-primary" style="padding:10px 22px;font-size:.85rem">
        <span class="material-symbols-outlined text-base">person_add</span>Cadastrar
      </a>
    </div>
  </div>
</header>

<main class="relative" style="padding-top:72px">

<!-- ═══ HERO ══════════════════════════════════════════════════════════════════ -->
<section class="relative min-h-[92vh] flex items-center overflow-hidden py-20">
  <!-- Orbs -->
  <div class="orb" style="width:600px;height:600px;background:#4e0078;opacity:.07;top:-15%;right:-10%"></div>
  <div class="orb" style="width:500px;height:500px;background:#b7004d;opacity:.06;bottom:-10%;left:-8%"></div>
  <div class="orb" style="width:300px;height:300px;background:#f4d9ff;opacity:.5;top:30%;left:20%"></div>

  <div class="max-w-7xl mx-auto px-6 md:px-10 relative z-10 w-full">
    <div class="grid md:grid-cols-2 gap-16 items-center">

      <!-- Texto -->
      <div class="space-y-7">
        <div class="hero-anim">
          <span class="tag" style="background:#f4d9ff;color:#6a1b9a">
            <span class="material-symbols-outlined text-sm">local_florist</span>
            Cuidado Integral · UERN Caicó
          </span>
        </div>

        <h1 class="hero-anim-2 text-5xl md:text-6xl font-extrabold text-on-background leading-[1.08] tracking-tight">
          Cuidar, acolher<br/>e <em class="not-italic" style="background:linear-gradient(135deg,#4e0078,#b7004d);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text">transformar</em><br/>vidas.
        </h1>

        <p class="hero-anim-3 text-lg text-on-surface-variant leading-relaxed max-w-md">
          Práticas integrativas gratuitas e humanizadas para toda a comunidade. Recupere seu equilíbrio com o suporte da UERN.
        </p>

        <div class="hero-anim-4 flex flex-wrap gap-4">
          <a href="login.php?modo=cadastro" class="btn-primary">
            <span class="material-symbols-outlined">spa</span>
            Agendar atendimento
          </a>
          <a href="login.php" class="btn-outline">
            <span class="material-symbols-outlined">login</span>
            Entrar no sistema
          </a>
        </div>

        <!-- Stats -->
        <div class="hero-anim-4 flex gap-8 pt-2">
          <?php foreach ([['Gratuito','100%'],['Práticas','8+'],['Atendimentos','Desde 2022']] as [$l,$v]): ?>
          <div>
            <p class="text-xl font-extrabold text-primary"><?= $v ?></p>
            <p class="text-xs text-on-surface-variant font-semibold uppercase tracking-wider"><?= $l ?></p>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Visual com logo flutuante -->
      <div class="flex items-center justify-center relative">
        <!-- Círculo decorativo -->
        <div class="absolute" style="width:400px;height:400px;border-radius:50%;background:linear-gradient(135deg,rgba(78,0,120,.06),rgba(183,0,77,.06));border:1.5px solid rgba(78,0,120,.1)"></div>
        <div class="absolute" style="width:340px;height:340px;border-radius:50%;background:linear-gradient(135deg,rgba(78,0,120,.04),rgba(183,0,77,.04));border:1px dashed rgba(78,0,120,.12)"></div>

        <!-- Logo central flutuando -->
        <img src="uploads/logo/logo.png" alt="NUPICS" class="logo-img-hero relative z-10"/>

        <!-- Cards de praticas orbitando -->
        <div class="absolute glass rounded-2xl px-4 py-3 flex items-center gap-2.5 shadow-lg" style="top:5%;left:5%;animation:float 4s 1s ease-in-out infinite">
          <span class="material-symbols-outlined text-secondary" style="font-size:20px">self_improvement</span>
          <span class="text-xs font-bold text-on-surface">Meditação</span>
        </div>
        <div class="absolute glass rounded-2xl px-4 py-3 flex items-center gap-2.5 shadow-lg" style="top:10%;right:5%;animation:float 4s .5s ease-in-out infinite">
          <span class="material-symbols-outlined text-primary" style="font-size:20px">spa</span>
          <span class="text-xs font-bold text-on-surface">Reiki</span>
        </div>
        <div class="absolute glass rounded-2xl px-4 py-3 flex items-center gap-2.5 shadow-lg" style="bottom:15%;left:2%;animation:float 4s 2s ease-in-out infinite">
          <span class="material-symbols-outlined text-secondary" style="font-size:20px">healing</span>
          <span class="text-xs font-bold text-on-surface">Acupuntura</span>
        </div>
        <div class="absolute glass rounded-2xl px-4 py-3 flex items-center gap-2.5 shadow-lg" style="bottom:10%;right:3%;animation:float 4s 1.5s ease-in-out infinite">
          <span class="material-symbols-outlined text-primary" style="font-size:20px">fitness_center</span>
          <span class="text-xs font-bold text-on-surface">Yoga</span>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ═══ SOBRE ══════════════════════════════════════════════════════════════════ -->
<section id="sobre" class="py-28 px-6 md:px-10 max-w-7xl mx-auto">
  <div class="text-center mb-16 reveal">
    <span class="tag mb-4" style="background:#f4d9ff;color:#6a1b9a">
      <img src="uploads/logo/logo.png" alt="" style="height:16px;width:16px;object-fit:contain"/>
      O que é o NUPICS
    </span>
    <h2 class="text-4xl font-extrabold text-on-surface mt-3">Um santuário de bem-estar</h2>
    <p class="text-on-surface-variant max-w-2xl mx-auto mt-3 text-lg">Unimos a ciência acadêmica ao toque humano das terapias integrativas.</p>
  </div>

  <!-- Bento grid -->
  <div class="grid md:grid-cols-4 gap-5 reveal">

    <!-- Card grande: Práticas -->
    <div class="md:col-span-2 card-bento glass">
      <div class="w-14 h-14 rounded-2xl flex items-center justify-center mb-5" style="background:rgba(244,217,255,.6)">
        <span class="material-symbols-outlined text-primary" style="font-size:28px">spa</span>
      </div>
      <h3 class="text-xl font-extrabold text-on-surface mb-3">Práticas Integrativas</h3>
      <p class="text-on-surface-variant leading-relaxed text-sm">Reiki, Acupuntura, Yoga, Meditação e outras terapias que tratam o ser humano em sua totalidade — física, mental e emocional.</p>
      <div class="flex flex-wrap gap-2 mt-5">
        <?php foreach (['Reiki','Acupuntura','Yoga','Meditação','Florais','Massagem'] as $p): ?>
        <span class="text-xs font-bold px-3 py-1.5 rounded-full" style="background:rgba(78,0,120,.08);color:#4e0078"><?= $p ?></span>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Card: Gratuito -->
    <div class="card-bento" style="background:linear-gradient(135deg,#4e0078,#6a1b9a);color:#fff">
      <div class="w-14 h-14 rounded-2xl flex items-center justify-center mb-5" style="background:rgba(255,255,255,.15)">
        <span class="material-symbols-outlined" style="font-size:28px;color:#fff">volunteer_activism</span>
      </div>
      <h3 class="text-xl font-extrabold mb-3">100% Gratuito</h3>
      <p class="text-sm leading-relaxed" style="color:rgba(255,255,255,.8)">Acesso democrático à saúde e bem-estar para toda a comunidade de Caicó e região.</p>
    </div>

    <!-- Card: Humano -->
    <div class="card-bento glass">
      <div class="w-14 h-14 rounded-2xl flex items-center justify-center mb-5" style="background:rgba(255,217,222,.6)">
        <span class="material-symbols-outlined text-secondary" style="font-size:28px">groups</span>
      </div>
      <h3 class="text-xl font-extrabold text-on-surface mb-3">Foco Humano</h3>
      <p class="text-on-surface-variant leading-relaxed text-sm">Cada atendimento é único. Acolhemos sua história com empatia e respeito profissional.</p>
    </div>

    <!-- Card banner: UERN -->
    <div class="md:col-span-4 card-bento flex flex-col md:flex-row gap-8 items-center" style="background:linear-gradient(135deg,rgba(244,217,255,.4),rgba(255,217,222,.3));border:1px solid rgba(78,0,120,.1)">
      <div class="w-full md:w-1/3 space-y-4">
        <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:rgba(255,255,255,.8)">
          <span class="material-symbols-outlined text-primary" style="font-size:28px">school</span>
        </div>
        <h3 class="text-2xl font-extrabold text-on-surface">Projeto da UERN</h3>
        <p class="text-on-surface-variant leading-relaxed text-sm">Excelência acadêmica e compromisso social transformando a realidade local através da extensão universitária.</p>
        <a href="login.php?modo=cadastro" class="btn-primary" style="font-size:.82rem;padding:11px 24px;text-decoration:none">
          <span class="material-symbols-outlined text-sm">arrow_forward</span>Fazer cadastro
        </a>
      </div>
      <div class="w-full md:w-2/3 flex items-center justify-center gap-8 py-4">
        <?php foreach ([
          ['spa','8+ práticas','integrativas disponíveis'],
          ['calendar_month','Agendamento','online simplificado'],
          ['verified','Terapeutas','qualificados e supervisionados'],
        ] as [$ic,$t,$sub]): ?>
        <div class="text-center">
          <div class="w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-3" style="background:rgba(255,255,255,.7)">
            <span class="material-symbols-outlined text-primary" style="font-size:28px"><?= $ic ?></span>
          </div>
          <p class="font-extrabold text-on-surface text-sm"><?= $t ?></p>
          <p class="text-xs text-on-surface-variant"><?= $sub ?></p>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- ═══ PRÁTICAS ══════════════════════════════════════════════════════════════ -->
<section id="praticas" class="py-28" style="background:linear-gradient(180deg,#fff7fc,#fdeffe)">
  <div class="max-w-7xl mx-auto px-6 md:px-10">
    <div class="text-center mb-16 reveal">
      <span class="tag mb-4" style="background:#ffd9de;color:#90003b">
        <span class="material-symbols-outlined text-sm">auto_awesome</span>
        Nossas práticas
      </span>
      <h2 class="text-4xl font-extrabold text-on-surface mt-3">Sua jornada de autocuidado</h2>
      <p class="text-on-surface-variant mt-3">Passo a passo simples para iniciar sua transformação.</p>
    </div>

    <!-- Steps -->
    <div class="relative grid md:grid-cols-4 gap-10 reveal">
      <div class="hidden md:block absolute top-7 left-0 w-full h-px" style="background:linear-gradient(90deg,transparent,rgba(78,0,120,.12) 20%,rgba(183,0,77,.12) 80%,transparent)"></div>
      <?php foreach ([
        ['01','Cadastro','Crie sua conta em nossa plataforma digital segura.','person_add'],
        ['02','Agendamento','Escolha a prática e o horário que melhor se adapta a você.','calendar_month'],
        ['03','Sessões','Receba o acolhimento de nossos terapeutas especializados.','spa'],
        ['04','Evolução','Acompanhe seu progresso e mantenha seu equilíbrio.','trending_up'],
      ] as $i => [$num,$titulo,$desc,$ic]): ?>
      <div class="flex flex-col items-center text-center gap-5">
        <div class="step-num relative z-10">
          <span><?= $num ?></span>
          <?php if ($i === 0): ?><div class="absolute inset-0 rounded-full border-2 border-purple-200 animate-ping opacity-40"></div><?php endif; ?>
        </div>
        <div class="w-12 h-12 rounded-2xl flex items-center justify-center" style="background:rgba(244,217,255,.6)">
          <span class="material-symbols-outlined text-primary"><?= $ic ?></span>
        </div>
        <h4 class="font-extrabold text-on-surface"><?= $titulo ?></h4>
        <p class="text-sm text-on-surface-variant leading-relaxed"><?= $desc ?></p>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- CTA -->
    <div class="text-center mt-16 reveal">
      <a href="login.php?modo=cadastro" class="btn-primary" style="font-size:1rem;padding:16px 40px;text-decoration:none">
        <span class="material-symbols-outlined">arrow_forward</span>
        Começar agora — é gratuito
      </a>
    </div>
  </div>
</section>

<!-- ═══ EQUIPE ═════════════════════════════════════════════════════════════════ -->
<section id="equipe" class="py-28 max-w-7xl mx-auto px-6 md:px-10">
  <div class="flex flex-col md:flex-row justify-between items-start md:items-end mb-14 gap-4 reveal">
    <div class="space-y-3">
      <span class="tag" style="background:#f4d9ff;color:#6a1b9a">
        <span class="material-symbols-outlined text-sm">medical_services</span>
        Nossa equipe
      </span>
      <h2 class="text-4xl font-extrabold text-on-surface">Especialistas em cuidar</h2>
      <p class="text-on-surface-variant max-w-lg">Profissionais apaixonados e dedicados à saúde integrativa.</p>
    </div>
  </div>

  <?php
    // Busca terapeutas reais ativos do banco
    // Prioriza professores (periodo = 'professor' ou especialidade contém 'prof')
    $terapeutas_db = [];
    if ($pdo) {
        try {
            $ter_stmt = $pdo->query("
                SELECT u.nome, u.foto, t.especialidade, t.periodo
                FROM terapeutas t
                JOIN usuarios u ON t.usuario_id = u.id
                WHERE t.ativo = 1
                ORDER BY
                  CASE WHEN LOWER(t.periodo) LIKE '%prof%' OR LOWER(t.periodo) LIKE '%docente%' THEN 0 ELSE 1 END ASC,
                  u.nome ASC
                LIMIT 8
            ");
            $terapeutas_db = $ter_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) { $terapeutas_db = []; }
    }
    $n = max(1, count($terapeutas_db ?: [[]]));
    $cols = $n <= 2 ? 2 : ($n <= 3 ? 3 : 4);
  ?>
  <div class="grid grid-cols-2 md:grid-cols-<?= $cols ?> gap-8 reveal">
    <?php

    // Função: retorna só os dois primeiros nomes
    function doisNomes(string $nome): string {
        $partes = preg_split('/\s+/', trim($nome));
        // Remove títulos acadêmicos para não contar como nome
        $titulos = ['dr.','dra.','prof.','profa.','me.','ma.','esp.'];
        $titulo = '';
        if (count($partes) > 0 && in_array(mb_strtolower($partes[0]), $titulos)) {
            $titulo = array_shift($partes) . ' ';
        }
        return $titulo . implode(' ', array_slice($partes, 0, 2));
    }

    // Avatares de fallback para os dois primeiros se não tiverem foto
    $fallback_imgs = ['uploads/logo/avatar.png','uploads/logo/avatar2.png'];
    $fallback_idx  = 0;

    foreach ($terapeutas_db as $ter):
        $nome_curto = doisNomes($ter['nome']);
        $inicial    = mb_strtoupper(mb_substr(preg_replace('/^(Dr\.|Dra\.|Prof\.|Profa\.)\s*/i','',$ter['nome']),0,1));
        $tem_foto   = !empty($ter['foto']) && file_exists(__DIR__.'/'.$ter['foto']);
        $foto_url   = $tem_foto ? $ter['foto'] : ($fallback_idx < count($fallback_imgs) ? $fallback_imgs[$fallback_idx++] : null);
    ?>
    <div class="group text-center">
      <div class="relative w-full aspect-square mb-5 mx-auto" style="max-width:180px">
        <?php if ($foto_url): ?>
        <img src="<?= htmlspecialchars($foto_url) ?>" alt="<?= htmlspecialchars($nome_curto) ?>"
             class="avatar-ring w-full h-full object-cover"/>
        <?php else: ?>
        <div class="avatar-ring w-full h-full flex items-center justify-center text-4xl font-extrabold text-primary" style="background:linear-gradient(135deg,#f4d9ff,#ffd9de)">
          <?= $inicial ?>
        </div>
        <?php endif; ?>
        <div class="absolute inset-0 rounded-full opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center" style="background:rgba(78,0,120,.08)">
          <a href="login.php?modo=cadastro" class="text-xs font-bold text-primary bg-white rounded-full px-3 py-1.5 shadow-lg" style="text-decoration:none">Agendar</a>
        </div>
      </div>
      <h4 class="font-extrabold text-on-surface text-sm md:text-base"><?= htmlspecialchars($nome_curto) ?></h4>
      <p class="text-on-surface-variant text-xs uppercase tracking-widest font-semibold mt-1"><?= htmlspecialchars($ter['especialidade'] ?? '') ?></p>
      <?php if (!empty($ter['periodo'])): ?>
      <p class="text-on-surface-variant text-[10px] mt-0.5 opacity-70"><?= htmlspecialchars($ter['periodo']) ?></p>
      <?php endif; ?>
    </div>
    <?php endforeach;

    // Se não há terapeutas cadastrados ainda, mostra mensagem discreta
    if (empty($terapeutas_db)): ?>
    <div class="md:col-span-4 text-center py-12 text-on-surface-variant">
      <span class="material-symbols-outlined text-4xl mb-3 block opacity-30">people</span>
      <p class="text-sm">Nossa equipe será apresentada em breve.</p>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- ═══ APOIO ══════════════════════════════════════════════════════════════════ -->
<section id="apoio" class="py-28 px-6 md:px-10">
  <div class="max-w-5xl mx-auto reveal">
    <div class="card-bento relative overflow-hidden" style="background:linear-gradient(135deg,#4e0078,#6a1b9a,#b7004d);color:#fff;padding:60px 48px">
      <!-- Decoração -->
      <div class="absolute -top-20 -right-20 opacity-10" style="width:300px;height:300px;border-radius:50%;border:60px solid #fff"></div>
      <div class="absolute -bottom-16 -left-16 opacity-10" style="width:250px;height:250px;border-radius:50%;border:50px solid #fff"></div>
      <div class="absolute top-8 right-8 opacity-20">
        <img src="uploads/logo/logo.png" alt="" style="height:80px;width:80px;object-fit:contain;filter:brightness(0) invert(1)"/>
      </div>

      <div class="relative z-10 flex flex-col md:flex-row items-center gap-12">
        <div class="flex-1 space-y-5">
          <h2 class="text-3xl md:text-4xl font-extrabold leading-tight">
            Nosso atendimento é gratuito.<br/>
            <span style="color:#ffb2bf">Seu apoio é o que nos move.</span>
          </h2>
          <p class="text-lg leading-relaxed" style="color:rgba(255,255,255,.8)">
            O NUPICS sobrevive através de doações e do compromisso de voluntários. Sua contribuição mantém os insumos, a estrutura e expande os atendimentos para quem mais precisa.
          </p>
          <button class="btn-primary" style="background:rgba(255,255,255,.2);border:2px solid rgba(255,255,255,.4);backdrop-filter:blur(8px)">
            <span class="material-symbols-outlined">favorite</span>
            Quero apoiar o projeto
          </button>
        </div>
        <!-- Ícone grande -->
        <div class="hidden lg:flex w-52 h-52 rounded-full items-center justify-center flex-shrink-0" style="border:2px solid rgba(255,255,255,.2);background:rgba(255,255,255,.08)">
          <span class="material-symbols-outlined" style="font-size:5rem;color:rgba(255,255,255,.4);font-variation-settings:'FILL' 1">favorite</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ═══ CTA FINAL ══════════════════════════════════════════════════════════════ -->
<section class="py-32 relative overflow-hidden" style="background:#201923">
  <div class="orb" style="width:500px;height:500px;background:#4e0078;opacity:.3;top:-20%;right:-10%"></div>
  <div class="orb" style="width:400px;height:400px;background:#b7004d;opacity:.2;bottom:-20%;left:-5%"></div>

  <div class="max-w-7xl mx-auto px-6 md:px-10 relative z-10 text-center reveal">
    <img src="uploads/logo/logo.png" alt="NUPICS" class="mx-auto mb-8" style="height:72px;width:72px;object-fit:contain;filter:brightness(0) invert(1) opacity(.6)"/>
    <h2 class="text-5xl md:text-6xl font-extrabold mb-6 leading-tight" style="color:#fff">
      Pronto para cuidar de você?
    </h2>
    <p class="text-xl mb-12 max-w-2xl mx-auto" style="color:rgba(255,255,255,.6)">
      Dê o primeiro passo em direção a uma vida mais equilibrada. Nossos terapeutas estão prontos para acolher você.
    </p>
    <div class="flex flex-col md:flex-row justify-center items-center gap-4">
      <a href="login.php?modo=cadastro" class="btn-primary" style="font-size:1rem;padding:18px 48px;text-decoration:none">
        <span class="material-symbols-outlined">spa</span>Agendar agora
      </a>
      <a href="login.php" class="btn-outline" style="font-size:1rem;padding:16px 40px;border-color:rgba(255,255,255,.25);color:#fff;text-decoration:none">
        <span class="material-symbols-outlined">login</span>Já tenho conta
      </a>
    </div>
  </div>
</section>

</main>

<!-- ═══ FOOTER ══════════════════════════════════════════════════════════════════ -->
<footer style="background:#fdeffe;border-top:1px solid rgba(78,0,120,.07)">
  <div class="max-w-7xl mx-auto px-6 md:px-10 py-14 flex flex-col md:flex-row justify-between items-center gap-8">
    <div class="flex flex-col items-center md:items-start gap-3">
      <div class="flex items-center gap-2">
        <img src="uploads/logo/logo.png" alt="NUPICS" style="height:24px;width:24px;object-fit:contain"/>
        <span class="font-extrabold text-on-surface" style="font-family:'Plus Jakarta Sans',sans-serif">NUPICS</span>
      </div>
      <p class="text-sm text-on-surface-variant text-center md:text-left max-w-xs">
        Núcleo de Práticas Integrativas e Complementares em Saúde · UERN Caicó
      </p>
      <p class="text-xs text-on-surface-variant">© <?= date('Y') ?> NUPICS. Cuidar, acolher e transformar vidas.</p>
    </div>
    <div class="flex flex-wrap justify-center gap-8">
      <?php foreach ([['#sobre','Sobre'],['#praticas','Práticas'],['#equipe','Equipe'],['#apoio','Apoiar']] as [$href,$label]): ?>
      <a href="<?= $href ?>" class="text-on-surface-variant hover:text-primary transition-colors text-sm font-semibold" style="text-decoration:none"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
    <div class="flex gap-3">
      <a href="#" class="w-10 h-10 rounded-full flex items-center justify-center text-primary transition-all hover:-translate-y-0.5" style="background:rgba(78,0,120,.08)">
        <span class="material-symbols-outlined text-base">share</span>
      </a>
      <a href="#" class="w-10 h-10 rounded-full flex items-center justify-center text-primary transition-all hover:-translate-y-0.5" style="background:rgba(78,0,120,.08)">
        <span class="material-symbols-outlined text-base">mail</span>
      </a>
    </div>
  </div>
</footer>

<script>
// ── Scroll reveal ──────────────────────────────────────────────────────────
const observer = new IntersectionObserver(entries => {
  entries.forEach(e => { if(e.isIntersecting) { e.target.classList.add('visible'); observer.unobserve(e.target); }});
}, {threshold:.12, rootMargin:'0px 0px -40px 0px'});
document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

// ── Nav highlight on scroll ───────────────────────────────────────────────
const sections = document.querySelectorAll('section[id]');
const navLinks  = document.querySelectorAll('.nav-link[href^="#"]');
window.addEventListener('scroll', () => {
  let cur = '';
  sections.forEach(s => { if(window.scrollY >= s.offsetTop - 100) cur = s.id; });
  navLinks.forEach(l => {
    l.classList.toggle('active', l.getAttribute('href') === '#'+cur);
  });
}, {passive:true});

// ── Smooth scroll para âncoras ────────────────────────────────────────────
document.querySelectorAll('a[href^="#"]').forEach(a => {
  a.addEventListener('click', e => {
    const t = document.querySelector(a.getAttribute('href'));
    if(t){ e.preventDefault(); t.scrollIntoView({behavior:'smooth',block:'start'}); }
  });
});
</script>
</body>
</html>