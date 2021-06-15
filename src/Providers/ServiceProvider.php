<?php


namespace Jiannei\Filesystem\Aliyun\Laravel\Providers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider as LaravelServiceProvider;
use Jiannei\Filesystem\Aliyun\Laravel\OssAdapter;
use League\Flysystem\Filesystem;

class ServiceProvider extends LaravelServiceProvider
{
    public function boot()
    {
        Storage::extend('oss', function ($app, $config) {

            $adapter = new OssAdapter(
                $config['client'],
                $config['bucket'],
                $config['prefix'],
                $config['domain'],
                $config['options']
            );

            return new Filesystem($adapter);
        });
    }
}