<?php
// Railway injeta automaticamente as variáveis de ambiente
// Localmente cai nos valores padrão do XAMPP
$host     = getenv('MYSQLHOST')     ?: 'localhost';
$porta    = getenv('MYSQLPORT')     ?: '3306';
$banco    = getenv('MYSQLDATABASE') ?: 'nupics_db';
$usuario  = getenv('MYSQLUSER')     ?: 'root';
$senha    = getenv('MYSQLPASSWORD') ?: '';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$porta};dbname={$banco};charset=utf8mb4",
        $usuario,
        $senha,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Em produção não exibe detalhes do erro
    $ambiente = getenv('RAILWAY_ENVIRONMENT') ? 'production' : 'local';
    if ($ambiente === 'local') {
        die('Erro de conexão: ' . $e->getMessage());
    } else {
        die('Erro de conexão com o banco de dados. Contate o administrador.');
    }
}