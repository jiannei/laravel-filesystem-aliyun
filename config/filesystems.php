<?php

/*
 * This file is part of the jiannei/laravel-filesystem-aliyun.
 *
 * (c) jiannei <longjian.huang@foxmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

return [
    'disks' => [
        'oss' => [
            // doc: https://help.aliyun.com/document_detail/32099.html?spm=a2c4g.11186623.6.1216.453c56d2dzHrVi
            'driver' => 'oss',
            'domain' => env('ALIYUN_OSS_DOMAIN'),
            'bucket' => env('ALIYUN_OSS_BUCKET'),
            'prefix' => env('ALIYUN_OSS_PREFIX'),

            'client' => [
                'key' => env('ALIYUN_OSS_ACCESS_KEY_ID'), // yourAccessKeyId
                'secret' => env('ALIYUN_OSS_ACCESS_KEY_SECRET'), // yourAccessKeySecret
                'endpoint' => env('ALIYUN_OSS_ENDPOINT'), //
                'is_cname' => env('ALIYUN_OSS_IS_CNAME', false), // true为开启CNAME。CNAME是指将自定义域名绑定到存储空间上。使用自定义域名时，无法使用listBuckets方法。
                'security_token' => env('ALIYUN_OSS_SECURITY_TOKEN'), // yourSecurityToken
                'request_proxy' => env('ALIYUN_OSS_REQUEST_PROXY'), // 代理服务器地址，例如http://<用户名>:<密码>@<代理ip>:<代理端口>。
                'ssl' => env('ALIYUN_OSS_SSL', false),
                'timeout' => env('ALIYUN_OSS_TIMEOUT', 5184000), // 设置Socket层传输数据的超时时间，单位秒，默认5184000秒。
                'connect_timeout' => env('ALIYUN_OSS_CONNECT_TIMEOUT', 10), // 设置建立连接的超时时间，单位秒，默认10秒。
                'max_retries' => env('ALIYUN_OSS_MAX_RETRIES', 3), // 最多尝试次数
            ],

            'options' => [

            ],
        ],
    ],
];
