<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Newsletter;

class NewsletterMailExport extends ContactMailExport
{
    public function __construct(Newsletter $newsletter, Client $client)
    {
        parent::__construct($newsletter->message, $client);
    }
}
