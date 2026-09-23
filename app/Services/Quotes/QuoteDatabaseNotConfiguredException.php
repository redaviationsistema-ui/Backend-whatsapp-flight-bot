<?php

namespace App\Services\Quotes;

use RuntimeException;

class QuoteDatabaseNotConfiguredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('QUOTE_DATABASE_NOT_CONFIGURED');
    }
}
