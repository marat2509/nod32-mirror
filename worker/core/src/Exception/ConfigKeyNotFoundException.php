<?php

declare(strict_types=1);

namespace Nod32Mirror\Exception;

class ConfigKeyNotFoundException extends ConfigException
{
    public function __construct(string $key)
    {
        parent::__construct(sprintf('Configuration key "%s" not found', $key));
    }
}
