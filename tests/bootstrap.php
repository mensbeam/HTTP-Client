<?php
declare(strict_types=1);
namespace MensBeam\Logger\Test;

ini_set('memory_limit', '2G');
ini_set('zend.assertions', '1');
ini_set('assert.exception', 'true');
error_reporting(\E_ALL);
define('CWD', dirname(__DIR__));
require_once CWD . '/vendor/autoload.php';