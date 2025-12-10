<?php

return [
    'models' => [
        'permission' => \Spatie\Permission\Models\Permission::class,
        'role' => \Spatie\Permission\Models\Role::class,
    ],
    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],
    'cache' => [
        'store' => env('PERMISSION_CACHE_STORE', 'default'),
        'expiration_time' => \DateInterval::createFromDateString('24 hours'),
        'key' => env('PERMISSION_CACHE_KEY', 'spatie.permission.cache'),
    ],
    'display_permission_in_exception' => false,
    'enable_wildcard_permission' => false,
    'default_roles' => [
        'super-admin',
        'shop-owner',
        'shop-collaborator',
    ],
];
