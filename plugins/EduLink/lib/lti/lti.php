<?php
// Import all
foreach (glob(__DIR__ . "/*.php") as $filename) {
    require_once $filename;
}

define(
  "TOOL_HOST",
  ((! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') ? "https" : "http")
  ."://".$_SERVER['HTTP_HOST']
);

Firebase\JWT\JWT::$leeway = 5;
