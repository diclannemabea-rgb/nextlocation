<?php
header('Content-Type: application/manifest+json');
define('BASE_PATH', __DIR__);
require_once __DIR__ . '/config/constants.php';
$parsed   = parse_url(BASE_URL);
$basePath = rtrim($parsed['path'] ?? '/', '/') . '/';
$out = [
  "name"             => "FlotteCar",
  "short_name"       => "FlotteCar",
  "description"      => "Plateforme SaaS de gestion de flotte & tracking GPS",
  "theme_color"      => "#1a56db",
  "background_color" => "#ffffff",
  "display"          => "standalone",
  "orientation"      => "portrait-primary",
  "scope"            => $basePath,
  "start_url"        => $basePath . "auth/login.php",
  "lang"             => "fr",
  "categories"       => ["business","productivity"],
  "icons"            => [
    ["src"=>$basePath."assets/img/icon-192.png","sizes"=>"192x192","type"=>"image/png","purpose"=>"any maskable"],
    ["src"=>$basePath."assets/img/icon-512.png","sizes"=>"512x512","type"=>"image/png","purpose"=>"any maskable"],
  ],
  "shortcuts" => [
    ["name"=>"Tableau de bord","short_name"=>"Dashboard","url"=>$basePath."app/dashboard.php","icons"=>[["src"=>$basePath."assets/img/icon-192.png","sizes"=>"192x192"]]],
  ],
];
echo json_encode($out, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
