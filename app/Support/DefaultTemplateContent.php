<?php

namespace App\Support;

class DefaultTemplateContent
{
    public const NAME = 'Default';

    public static function html(): string
    {
        return <<<'HTML'
<p>{{ content }}</p>
<hr>
<p style="font-size:12px;color:#666;text-align:center;">
  <a href="{{ unsubscribe_url }}">Unsubscribe</a> ·
  <a href="{{ preferences_url }}">Manage preferences</a>
  <br>
  {{ postal_address }}
</p>
HTML;
    }
}
