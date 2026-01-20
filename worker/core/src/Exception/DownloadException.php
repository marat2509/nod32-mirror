<?php

declare(strict_types=1);

namespace Nod32Mirror\Exception;

use Exception;

class DownloadException extends Exception
{
    public function __construct(
        string $message,
        public readonly string $url = '',
        public readonly int $httpCode = 0,
        ?Exception $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
