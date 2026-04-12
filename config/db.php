<?php
// Configurações do banco de dados
define('DB_HOST', 'localhost');
define('DB_NAME', 'nupics_db');
define('DB_USER', 'root');   // usuário padrão do XAMPP
define('DB_PASS', '');       // senha em branco no XAMPP local

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8',
        DB_USER,
        DB_PASS
    );

    // Faz o PHP mostrar erros do banco durante o desenvolvimento
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Retorna os resultados como array associativo (ex: $row['nome'])
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Se não conseguir conectar, mostra a mensagem de erro
    die('Erro ao conectar com o banco de dados: ' . $e->getMessage());
}