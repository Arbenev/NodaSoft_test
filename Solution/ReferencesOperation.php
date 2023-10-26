<?php

namespace NW\WebService\References\Operations\Notification;

abstract class ReferencesOperation
{

    abstract public function doOperation(): array;

    public function getRequest($pName)
    {
        return filter_input(INPUT_REQUEST, $pName);
    }
}
