<?php

return [
    // 内网穿透服务器客户端进程
    'client' => [
        'handler' => Saithink\NatClient\Client::class,
        'reloadable' => false,
        'constructor' => [
            'debug' => false
        ]
    ]
];
