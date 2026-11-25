<?php

namespace Database\Factories\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;

trait ResolvesClientFromUser
{
    /**
     * Put the record in the client of its user. A record without a user gets no client.
     */
    protected function clientOfUser(): Closure
    {
        return fn (array $attributes) => $attributes['user_id'] === null
            ? null
            : DB::table('users')->where('id', $attributes['user_id'])->value('client_id');
    }
}
