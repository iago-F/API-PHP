<?php

class Database
{
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO
    {
        if (self::$pdo === null) {
            $dbDir = __DIR__ . '/../database';
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0777, true);
            }

            $dbPath = $dbDir . '/empresas.sqlite';
            self::$pdo = new PDO("sqlite:{$dbPath}");
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            self::criarTabela();
        }

        return self::$pdo;
    }

    private static function criarTabela(): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS empresas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                cnpj TEXT UNIQUE NOT NULL,
                razao_social TEXT NOT NULL,
                nome_fantasia TEXT,
                situacao_cadastral TEXT,
                data_abertura TEXT,
                cnae_principal TEXT,
                logradouro TEXT,
                numero TEXT,
                bairro TEXT,
                municipio TEXT,
                uf TEXT,
                cep TEXT,
                valido INTEGER NOT NULL,
                payload_completo TEXT,
                atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ";
        self::$pdo->exec($sql);
    }
}
