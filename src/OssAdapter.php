<?php

/*
 * This file is part of the jiannei/laravel-filesystem-aliyun.
 *
 * (c) jiannei <longjian.huang@foxmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Jiannei\Filesystem\Aliyun\Laravel;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Util;
use OSS\Core\OssException;
use OSS\OssClient;

class OssAdapter extends AbstractAdapter
{
    protected static $resultMap = [
        'Body' => 'raw_contents',
        'Content-Length' => 'size',
        'ContentType' => 'mimetype',
        'Size' => 'size',
        'StorageClass' => 'storage_class',
    ];

    protected static $metaOptions = [
        'CacheControl',
        'Expires',
        'ServerSideEncryption',
        'Metadata',
        'ACL',
        'ContentType',
        'ContentDisposition',
        'ContentLanguage',
        'ContentEncoding',
    ];

    protected static $metaMap = [
        'CacheControl' => 'Cache-Control',
        'Expires' => 'Expires',
        'ServerSideEncryption' => 'x-oss-server-side-encryption',
        'Metadata' => 'x-oss-metadata-directive',
        'ACL' => 'x-oss-object-acl',
        'ContentType' => 'Content-Type',
        'ContentDisposition' => 'Content-Disposition',
        'ContentLanguage' => 'response-content-language',
        'ContentEncoding' => 'Content-Encoding',
    ];

    protected $clientConfig;
    protected $ossClient;
    protected $bucket;
    protected $domain;
    protected $options = [];

    /**
     * AliYunOssAdapter constructor.
     *
     * @param $clientConfig
     * @param $bucket
     * @param $prefix
     * @param $domain
     * @param  array  $options
     */
    public function __construct($clientConfig, $bucket, $prefix, $domain, array $options = [])
    {
        $this->clientConfig = $clientConfig;
        $this->bucket = $bucket;
        $this->options = $options;
        $this->domain = $domain;

        $this->setPathPrefix($prefix);
    }

    /**
     * Get the OssClient bucket.
     *
     * @return string
     */
    public function getBucketName()
    {
        return $this->bucket;
    }

    /**
     * Write a new file using a stream.
     *
     * @param  $path
     * @param  $resource
     * @param  $config  Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function writeStream($path, $resource, Config $config)
    {
        $contents = stream_get_contents($resource);

        return $this->write($path, $contents, $config);
    }

    /**
     * upload specified in-memory data to an OSS object.
     *
     * @param  $path
     * @param  $content
     * @param  Config  $config  Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write($path, $content, Config $config)
    {
        $object = $this->applyPathPrefix($path);
        $options = $this->getOptionsFromConfig($config);

        try {
            $this->getOssClient()->putObject($this->bucket, $object, $content, $options);
        } catch (OssException $e) {
            return false;
        }

        return $this->normalizeResponse($options, $path);
    }

    /**
     * Retrieve options from a Config instance.
     *
     * @param  Config  $config
     *
     * @return array
     */
    protected function getOptionsFromConfig(Config $config): array
    {
        $options = $this->options;

        if ($visibility = $config->get('visibility')) {
            // For local reference
            // $options['visibility'] = $visibility;
            // For external reference
            $options[OssClient::OSS_OBJECT_ACL] = $visibility === AdapterInterface::VISIBILITY_PUBLIC ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;
        }

        if ($mimetype = $config->get('mimetype')) {
            // For local reference
            // $options['mimetype'] = $mimetype;
            // For external reference
            $options[OssClient::OSS_CONTENT_TYPE] = $mimetype;
        }

        foreach (static::$metaOptions as $option) {
            if (! $config->has($option)) {
                continue;
            }
            $options[static::$metaMap[$option]] = $config->get($option);
        }

        return $options;
    }

    /**
     * Get an OSSClient instance according to config.
     *
     * @throws OssException
     */
    public function getOssClient()
    {
        if ($this->ossClient) {
            return $this->ossClient;
        }

        $this->ossClient = new OssClient(
            $this->clientConfig['key'],
            $this->clientConfig['secret'],
            $this->clientConfig['endpoint'],
            $this->clientConfig['is_cname'],
            $this->clientConfig['security_token'],
            $this->clientConfig['request_proxy'],
        );

        $this->ossClient->setTimeout($this->clientConfig['timeout']);
        $this->ossClient->setConnectTimeout($this->clientConfig['connect_timeout']);
        $this->ossClient->setMaxTries($this->clientConfig['max_retries']);
        $this->ossClient->setUseSSL($this->clientConfig['ssl']);

        return $this->ossClient;
    }

    /**
     * Normalize the object result array.
     *
     * @param  array  $response
     * @param  string  $path
     *
     * @return array
     */
    protected function normalizeResponse(array $response, $path = null)
    {
        $result = ['path' => $path ?: $this->removePathPrefix($response['Key'] ?? $response['Prefix'])];
        $result['dirname'] = Util::dirname($result['path']);

        if (isset($response['LastModified'])) {
            $result['timestamp'] = strtotime($response['LastModified']);
        }

        if ($this->isOnlyDir($result['path'])) {
            $result['type'] = 'dir';
            $result['path'] = rtrim($result['path'], '/');

            return $result;
        }

        return array_merge($result, Util::map($response, static::$resultMap), ['type' => 'file']);
    }

    /**
     * Check if the path contains only directories.
     *
     * @param  string  $path
     *
     * @return bool
     */
    private function isOnlyDir($path)
    {
        return substr($path, -1) === '/';
    }

    /**
     * Uploads a local file to OSS.
     *
     * @param $path
     * @param $filePath
     * @param  Config  $config
     * @return array|false|string[]
     */
    public function writeFile($path, $filePath, Config $config)
    {
        $object = $this->applyPathPrefix($path);
        $options = $this->getOptionsFromConfig($config);

        $options[OssClient::OSS_CHECK_MD5] = true;

        try {
            $this->getOssClient()->uploadFile($this->bucket, $object, $filePath, $options);
        } catch (OssException $e) {
            return false;
        }

        return $this->normalizeResponse($options, $path);
    }

    /**
     * Update a file using a stream.
     *
     * @param  string  $path
     * @param  resource  $resource
     * @param  Config  $config  Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function updateStream($path, $resource, Config $config)
    {
        $contents = stream_get_contents($resource);

        return $this->update($path, $contents, $config);
    }

    /**
     * Update a file.
     *
     * @param  string  $path
     * @param  string  $contents
     * @param  Config  $config  Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        if (! $config->has('visibility') && ! $config->has('ACL')) {
            $config->set(static::$metaMap['ACL'], $this->getObjectACL($path));
        }

        return $this->write($path, $contents, $config);
    }

    /**
     * The the ACL visibility.
     *
     * @param  string  $path
     *
     * @return string
     */
    protected function getObjectACL($path)
    {
        $metadata = $this->getVisibility($path);

        return $metadata['visibility'] === AdapterInterface::VISIBILITY_PUBLIC ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;
    }

    /**
     * Get the visibility of a file.
     *
     * @param  string  $path
     *
     * @return array|false
     */
    public function getVisibility($path)
    {
        $object = $this->applyPathPrefix($path);
        try {
            $acl = $this->getOssClient()->getObjectAcl($this->bucket, $object);
        } catch (OssException $e) {
            return false;
        }

        if ($acl == OssClient::OSS_ACL_TYPE_PUBLIC_READ) {
            $res['visibility'] = AdapterInterface::VISIBILITY_PUBLIC;
        } else {
            $res['visibility'] = AdapterInterface::VISIBILITY_PRIVATE;
        }

        return $res;
    }

    /**
     * Rename a file.
     *
     * @param  string  $path
     * @param  string  $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        if (! $this->copy($path, $newpath)) {
            return false;
        }

        return $this->delete($path);
    }

    /**
     * Copy a file.
     *
     * @param  string  $path
     * @param  string  $newpath
     *
     * @return bool
     */
    public function copy($path, $newpath)
    {
        $object = $this->applyPathPrefix($path);
        $newObject = $this->applyPathPrefix($newpath);
        try {
            $this->getOssClient()->copyObject($this->bucket, $object, $this->bucket, $newObject);
        } catch (OssException $e) {
            return false;
        }

        return true;
    }

    /**
     * Delete a file.
     *
     * @param  string  $path
     *
     * @return bool
     */
    public function delete($path)
    {
        $bucket = $this->bucket;
        $object = $this->applyPathPrefix($path);

        try {
            $this->getOssClient()->deleteObject($bucket, $object);
        } catch (OssException $e) {
            return false;
        }

        return ! $this->has($path);
    }

    /**
     * Check whether a file exists.
     *
     * @param  string  $path
     *
     * @return bool
     */
    public function has($path)
    {
        $object = $this->applyPathPrefix($path);

        return $this->getOssClient()->doesObjectExist($this->bucket, $object);
    }

    /**
     * Delete a directory.
     *
     * @param  string  $directory
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        $dirname = rtrim($this->applyPathPrefix($dirname), '/').'/';
        $dirObjects = $this->listDirObjects($dirname, true);

        if (count($dirObjects['objects']) > 0) {
            $objects = [];
            foreach ($dirObjects['objects'] as $object) {
                $objects[] = $object['Key'];
            }

            try {
                $this->getOssClient()->deleteObjects($this->bucket, $objects);
            } catch (OssException $e) {
                return false;
            }
        }

        try {
            $this->getOssClient()->deleteObject($this->bucket, $dirname);
        } catch (OssException $e) {
            return false;
        }

        return true;
    }

    /**
     * 列举文件夹内文件列表；可递归获取子文件夹；.
     *
     * @param  string  $dirname  目录
     * @param  bool  $recursive  是否递归
     * @return mixed
     * @throws OssException
     */
    private function listDirObjects($dirname = '', $recursive = false)
    {
        $delimiter = '/';
        $nextMarker = '';
        $maxkeys = 1000;

        //存储结果
        $result = [];

        while (true) {
            $options = [
                'delimiter' => $delimiter,
                'prefix' => $dirname,
                'max-keys' => $maxkeys,
                'marker' => $nextMarker,
            ];

            try {
                $listObjectInfo = $this->getOssClient()->listObjects($this->bucket, $options);
            } catch (OssException $e) {
                throw $e;
            }

            $nextMarker = $listObjectInfo->getNextMarker(); // 得到nextMarker，从上一次listObjects读到的最后一个文件的下一个文件开始继续获取文件列表
            $objectList = $listObjectInfo->getObjectList(); // 文件列表
            $prefixList = $listObjectInfo->getPrefixList(); // 目录列表

            if (! empty($objectList)) {
                foreach ($objectList as $objectInfo) {
                    $object['Prefix'] = $dirname;
                    $object['Key'] = $objectInfo->getKey();
                    $object['LastModified'] = $objectInfo->getLastModified();
                    $object['eTag'] = $objectInfo->getETag();
                    $object['Type'] = $objectInfo->getType();
                    $object['Size'] = $objectInfo->getSize();
                    $object['StorageClass'] = $objectInfo->getStorageClass();

                    $result['objects'][] = $object;
                }
            } else {
                $result['objects'] = [];
            }

            if (! empty($prefixList)) {
                foreach ($prefixList as $prefixInfo) {
                    $result['prefix'][] = $prefixInfo->getPrefix();
                }
            } else {
                $result['prefix'] = [];
            }

            // 递归查询子目录所有文件
            if ($recursive) {
                foreach ($result['prefix'] as $pfix) {
                    $next = $this->listDirObjects($pfix, $recursive);
                    $result['objects'] = array_merge($result['objects'], $next['objects']);
                }
            }

            // 没有更多结果了
            if ($nextMarker === '') {
                break;
            }
        }

        return $result;
    }

    /**
     * Create a directory.
     *
     * @param  string  $dirname  directory name
     * @param  Config  $config
     *
     * @return bool|array
     */
    public function createDir($dirname, Config $config)
    {
        $object = $this->applyPathPrefix($dirname);
        $options = $this->getOptionsFromConfig($config);

        try {
            $this->getOssClient()->createObjectDir($this->bucket, $object, $options);
        } catch (OssException $e) {
            return false;
        }

        return ['path' => $dirname, 'type' => 'dir'];
    }

    /**
     * Set the visibility for a file.
     *
     * @param  string  $path
     * @param  string  $visibility
     *
     * @return array|false file meta data
     */
    public function setVisibility($path, $visibility)
    {
        $object = $this->applyPathPrefix($path);
        $acl = ($visibility === AdapterInterface::VISIBILITY_PUBLIC) ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE;

        $this->getOssClient()->putObjectAcl($this->bucket, $object, $acl);

        return compact('path', 'visibility');
    }

    /**
     * Read a file.
     *
     * @param  string  $path
     *
     * @return false|array
     */
    public function read($path)
    {
        $result = $this->readObject($path);
        $result['contents'] = (string) $result['raw_contents'];
        unset($result['raw_contents']);

        return $result;
    }

    /**
     * Read a file.
     *
     * @param  string  $path
     *
     * @return false|array
     */
    protected function readObject($path)
    {
        $object = $this->applyPathPrefix($path);

        $result['Body'] = $this->getOssClient()->getObject($this->bucket, $object);
        $result = array_merge($result, ['type' => 'file']);

        return $this->normalizeResponse($result, $path);
    }

    /**
     * Read a file as a stream.
     *
     * @param  string  $path
     *
     * @return array|false
     */
    public function readStream($path)
    {
        $result = $this->readObject($path);

        if (is_resource($result['raw_contents'])) {
            $result['stream'] = $result['raw_contents'];
            // Ensure the EntityBody object destruction doesn't close the stream
            $result['raw_contents']->detachStream();
        } else {
            $result['stream'] = fopen('php://temp', 'r+');
            fwrite($result['stream'], $result['raw_contents']);
        }

        rewind($result['stream']);

        unset($result['raw_contents']);

        return $result;
    }

    /**
     * List contents of a directory.
     *
     * @param  string  $directory
     * @param  bool  $recursive
     *
     * @return array
     */
    public function listContents($directory = '', $recursive = false)
    {
        $dirObjects = $this->listDirObjects($directory, true);
        $contents = $dirObjects['objects'];

        $result = array_map([$this, 'normalizeResponse'], $contents);
        $result = array_filter($result, function ($value) {
            return $value['path'] !== false;
        });

        return Util::emulateDirectories($result);
    }

    /**
     * Get the size of a file.
     *
     * @param  string  $path
     *
     * @return array|false
     */
    public function getSize($path)
    {
        $object = $this->getMetadata($path);
        $object['size'] = $object['content-length'];

        return $object;
    }

    /**
     * Get all the meta data of a file or directory.
     *
     * @param  string  $path
     *
     * @return array|false
     */
    public function getMetadata($path)
    {
        $object = $this->applyPathPrefix($path);

        try {
            $objectMeta = $this->getOssClient()->getObjectMeta($this->bucket, $object);
        } catch (OssException $e) {
            return false;
        }

        return $objectMeta;
    }

    /**
     * Get the mime-type of a file.
     *
     * @param  string  $path
     *
     * @return array|false
     */
    public function getMimetype($path)
    {
        if ($object = $this->getMetadata($path)) {
            $object['mimetype'] = $object['content-type'];
        }

        return $object;
    }

    /**
     * Get the timestamp of a file.
     *
     * @param  string  $path
     *
     * @return false|array
     */
    public function getTimestamp($path)
    {
        if ($object = $this->getMetadata($path)) {
            $object['timestamp'] = strtotime($object['last-modified']);
        }

        return $object;
    }

    /**
     * Get resource url.
     *
     * @param $path
     * @return string
     * @throws FileNotFoundException
     */
    public function getUrl($path)
    {
        if (! $this->has($path)) {
            throw new FileNotFoundException($path.' not found');
        }

        $resourceUrl = $this->clientConfig['ssl'] ? 'https://' : 'http://';

        if ($this->clientConfig['is_cname']) {
            $resourceUrl .= ($this->domain ?: $this->clientConfig['endpoint']);
        } else {
            $resourceUrl .= ($this->bucket.'.'.$this->clientConfig['endpoint']);
        }

        return $resourceUrl.'/'.ltrim($path, '/');
    }
}
