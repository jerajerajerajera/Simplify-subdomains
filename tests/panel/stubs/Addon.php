<?php

// Stands in for Simplify's add-on base class when Simplify is not installed in the test panel.

namespace Simplify\Core\Addons;

abstract class Addon
{
    abstract public function id(): string;

    abstract public function name(): string;
}
