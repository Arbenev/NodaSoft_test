<?php

namespace NW\WebService\References\Operations\Notification;

class Status
{

    public $id;
    public $name;
    static protected $statusNames = [
        0 => 'Completed',
        1 => 'Pending',
        2 => 'Rejected',
    ];

    public static function getName(int $id): string
    {

        return self::$statusNames[$id];
    }
}
