<?php
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $conn;

    public function __construct() {
        $this->host = getenv('DB_HOST') ?: "localhost";
        $this->db_name = getenv('DB_NAME') ?: "traccargps";
        $this->username = getenv('DB_USER') ?: "root";
        $this->password = getenv('DB_PASS') ?: "";
    }

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $this->conn->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            $this->conn->exec("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
        } catch(PDOException $e) {
            echo "Erreur de connexion : " . $e->getMessage();
            die();
        }
        return $this->conn;
    }

    public function closeConnection() {
        $this->conn = null;
    }
}