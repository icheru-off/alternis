<?php
if (!is_file(__DIR__ . '/../config/config.php')) {
    http_response_code(500);
    die('Configuration absente : copiez config/config.example.php en config/config.php puis renseignez-le (voir README).');
}
require_once __DIR__ . '/../config/config.php';

/**
 * Renvoie une instance PDO unique (singleton).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        if (APP_ENV === 'dev') {
            die('Erreur de connexion à la base : ' . $e->getMessage());
        }
        http_response_code(500);
        die('La connexion à la base de données a échoué. Vérifiez config/config.php.');
    }
    return $pdo;
}
