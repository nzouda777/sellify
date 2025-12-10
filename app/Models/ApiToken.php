<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken;

class ApiToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';
}
