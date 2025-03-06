#!/usr/local/bin/php
<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('PRESTA_OPS_ROOT_DIR', getcwd() . '/');

include 'vendor/autoload.php';
include 'prestaops.php';

