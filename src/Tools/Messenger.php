<?php

namespace PrestaOps\Tools;

/**
 * 
 */
class Messenger
{
    public static function danger($message)
    {
        echo "\e[31m$message\e[0m\n"; // Red
        exit(1);
    }

    public static function warning($message)
    {
        echo "\e[33m$message\e[0m\n"; // Yellow
    }

    public static function info($message)
    {
        echo "\e[36m$message\e[0m\n"; // Cyan
    }

    public static function success($message)
    {
        echo "\e[32m$message\e[0m\n"; // Green
    }
}
