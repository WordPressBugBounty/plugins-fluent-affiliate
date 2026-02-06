<?php


if (!defined('ABSPATH')) exit; // Exit if accessed directly


$router->group(function ($router) {
    require_once __DIR__ . '/api.php';
});
