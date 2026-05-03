<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['ok'=>false,'msg'=>'Não autenticado.']); exit;
}
require_once '../config/db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$uid      = (int)$_SESSION['usuario_id'];
$tipo_usr = $_SESSION['tipo'] ?? '';
$contexto = trim($_POST['contexto'] ?? ''); // perfil | aviso | visita | logo

function resp(bool $ok, string $msg='', array $extra=[]): void {
    echo json_encode(array_merge(['ok'=>$ok,'msg'=>$msg],$extra)); exit;
}

// ── Validação do contexto e permissões ───────────────────────────────────────
$contextos_validos = ['perfil','aviso','visita','logo'];
if (!in_array($contexto, $contextos_validos)) resp(false, 'Contexto inválido.');

if (in_array($contexto, ['aviso','logo']) && $tipo_usr !== 'coordenador')
    resp(false, 'Acesso negado.');

// ── Validação do arquivo ─────────────────────────────────────────────────────
if (empty($_FILES['imagem']) || $_FILES['imagem']['error'] !== UPLOAD_ERR_OK)
    resp(false, 'Nenhum arquivo enviado ou erro no upload.');

$file     = $_FILES['imagem'];
$max_size = 2 * 1024 * 1024; // 2MB

if ($file['size'] > $max_size)
    resp(false, 'Imagem muito grande. O limite é 2MB.');

// Verifica tipo MIME real (não confia no navegador)
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mime     = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$mimes_ok = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
if (!isset($mimes_ok[$mime]))
    resp(false, 'Formato inválido. Use JPG, PNG, GIF ou WebP.');

$ext = $mimes_ok[$mime];

// ── Diretórios de destino ────────────────────────────────────────────────────
$base = dirname(__DIR__) . '/uploads/';
$dirs = [
    'perfil'  => $base . 'perfil/',
    'aviso'   => $base . 'avisos/',
    'visita'  => $base . 'visitas/',
    'logo'    => $base . 'logo/',
];
$dir = $dirs[$contexto];
if (!is_dir($dir)) mkdir($dir, 0755, true);

// ── Nome do arquivo ──────────────────────────────────────────────────────────
switch ($contexto) {
    case 'perfil':
        // Remove foto antiga se existir
        $old = $pdo->prepare("SELECT foto FROM usuarios WHERE id=?");
        $old->execute([$uid]);
        $old_foto = $old->fetchColumn();
        if ($old_foto && file_exists(dirname(__DIR__).'/'.$old_foto))
            @unlink(dirname(__DIR__).'/'.$old_foto);
        $filename = $uid . '.' . $ext;
        break;

    case 'aviso':
        $aviso_id = (int)($_POST['aviso_id'] ?? 0);
        if (!$aviso_id) resp(false, 'ID do aviso não informado.');
        $filename = 'aviso_' . $aviso_id . '_' . time() . '.' . $ext;
        break;

    case 'visita':
        $visita_id = (int)($_POST['visita_id'] ?? 0);
        if (!$visita_id) resp(false, 'ID da visita não informado.');
        $filename = 'visita_' . $visita_id . '_' . time() . '.' . $ext;
        break;

    case 'logo':
        // Apaga logo antiga
        $old_logo = $pdo->query("SELECT valor FROM configuracoes WHERE chave='logo_path' LIMIT 1")->fetchColumn();
        if ($old_logo && file_exists(dirname(__DIR__).'/'.$old_logo))
            @unlink(dirname(__DIR__).'/'.$old_logo);
        $filename = 'nupics_logo_' . time() . '.' . $ext;
        break;
}

$dest      = $dir . $filename;
$path_rel  = 'uploads/' . basename($dir) . '/' . $filename; // relativo à raiz do projeto

if (!move_uploaded_file($file['tmp_name'], $dest))
    resp(false, 'Erro ao salvar o arquivo. Verifique as permissões da pasta uploads/.');

// ── Salva o caminho no banco ─────────────────────────────────────────────────
try {
    switch ($contexto) {
        case 'perfil':
            $pdo->prepare("UPDATE usuarios SET foto=? WHERE id=?")->execute([$path_rel, $uid]);
            $_SESSION['foto'] = $path_rel;
            break;

        case 'aviso':
            $pdo->prepare("UPDATE avisos SET imagem=? WHERE id=?")->execute([$path_rel, $aviso_id]);
            break;

        case 'visita':
            // Acumula fotos (pipe-separated)
            $atual = $pdo->prepare("SELECT fotos FROM visitas_externas WHERE id=?");
            $atual->execute([$visita_id]);
            $fotos_atuais = $atual->fetchColumn();
            $novas = $fotos_atuais ? $fotos_atuais . '|' . $path_rel : $path_rel;
            // Limita a 8 fotos por visita
            $lista = array_slice(explode('|', $novas), -8);
            $pdo->prepare("UPDATE visitas_externas SET fotos=? WHERE id=?")->execute([implode('|',$lista), $visita_id]);
            break;

        case 'logo':
            $pdo->prepare("UPDATE configuracoes SET valor=? WHERE chave='logo_path'")->execute([$path_rel]);
            break;
    }
} catch (PDOException $e) {
    // Se o BD falhou (coluna não existe ainda), remove o arquivo e avisa
    @unlink($dest);
    resp(false, 'Erro no banco. Execute o arquivo imagens.sql primeiro. Detalhe: ' . $e->getMessage());
}

resp(true, 'Imagem salva com sucesso!', [
    'path'     => $path_rel,
    'url'      => '../' . $path_rel,
    'contexto' => $contexto,
]);