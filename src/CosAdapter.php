<?php

namespace Overtrue\Flysystem\Cos;

use GuzzleHttp\Psr7\Uri;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\CanOverwriteFiles;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use Overtrue\CosClient\ObjectClient;
use Overtrue\CosClient\BucketClient;

class CosAdapter extends AbstractAdapter implements CanOverwriteFiles
{
    /**
     * @var \Overtrue\CosClient\ObjectClient|null
     */
    protected ?ObjectClient $objectClient;

    /**
     * @var \Overtrue\CosClient\BucketClient|null
     */
    protected ?BucketClient $bucketClient;

    /**
     * @var array
     */
    protected $config;

    /**
     * CosAdapter constructor.
     *
     * @param  array  $config
     */
    public function __construct(array $config)
    {
        $this->config = \array_merge(
            [
                'bucket' => null,
                'app_id' => null,
                'region' => 'ap-guangzhou',
                'signed_url' => false,
            ],
            $config
        );

        if (!empty($config['prefix'])) {
            $this->setPathPrefix($config['prefix']);
        }
    }

    /**
     * @param  string  $path
     *
     * @return bool
     */
    public function has($path)
    {
        return !empty($this->getMetadata($path));
    }

    /**
     * @inheritDoc
     */
    public function write($path, $contents, Config $config)
    {
        $response = $this->getObjectClient()->putObject($this->applyPathPrefix($path), \strval($contents), $config->get('headers', []));

        if (!$response->isSuccessful()) {
            return false;
        }

        $result = [
            'contents' => $contents,
            'type' => $response->getHeader('Content-Type'),
            'size' => $response->getHeader('Content-Length'),
            'path' => $this->applyPathPrefix($path),
        ];

        if ($visibility = $config->get('visibility')) {
            $result['visibility'] = $visibility;
            $this->setVisibility($path, $visibility);
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->write($path, \stream_get_contents($resource), $config);
    }

    /**
     * @inheritDoc
     */
    public function readStream($path)
    {
        $response = $this->getObjectClient()->get(\urlencode($this->applyPathPrefix($path)), ['stream' => true]);

        if ($response->isNotFound()) {
            return false;
        }

        return [
            'type' => 'file',
            'path' => $path,
            'stream' => $response->getBody()->detach(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * @inheritDoc
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * @inheritDoc
     */
    public function read($path)
    {
        $path = $this->applyPathPrefix($path);
        $response = $this->getObjectClient()->getObject($path);

        return $response->isNotFound() ? false : ['type' => 'file', 'path' => $path, 'contents' => $response->getContents()];
    }

    /**
     * @inheritDoc
     */
    public function rename($path, $newpath)
    {
        $result = $this->copy($path, $newpath);

        $this->delete($this->applyPathPrefix($path));

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function copy($path, $newpath)
    {
        $location = $this->getSourcePath($this->applyPathPrefix($path));
        $destination = $this->applyPathPrefix($newpath);

        try {
            return $this->getObjectClient()->copyObject(
                $destination,
                [
                    'x-cos-copy-source' => $location,
                ]
            )->isSuccessful();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function delete($path)
    {
        return $this->getObjectClient()->deleteObject($this->applyPathPrefix($path))->isSuccessful();
    }

    /**
     * @inheritDoc
     */
    public function listContents($directory = '', $recursive = false)
    {
        $list = [];

        $response = $this->listObjects($this->applyPathPrefix($directory), $recursive);

        // 处理目录
        foreach ($response['CommonPrefixes'] ?? [] as $prefix) {
            $list[] = $this->normalizeFileInfo(
                [
                    'Key' => $prefix['Prefix'],
                    'Size' => 0,
                    'LastModified' => 0,
                ]
            );
        }

        foreach ($response['Contents'] ?? [] as $content) {
            $list[] = $this->normalizeFileInfo($content);
        }

        return \array_filter($list);
    }

    /**
     * @inheritDoc
     */
    public function getMetadata($path)
    {
        try {
            return $this->getObjectClient()->headObject($this->applyPathPrefix($path))->getHeaders();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function getSize($path)
    {
        $meta = $this->getMetadata($path);

        return isset($meta['Content-Length'][0]) ? ['size' => $meta['Content-Length'][0]] : false;
    }

    /**
     * @inheritDoc
     */
    public function getMimetype($path)
    {
        $meta = $this->getMetadata($path);

        return isset($meta['Content-Type'][0]) ? ['mimetype' => $meta['Content-Type'][0]] : false;
    }

    /**
     * @inheritDoc
     */
    public function getTimestamp($path)
    {
        $meta = $this->getMetadata($path);

        return isset($meta['Last-Modified'][0]) ? ['timestamp' => \strtotime($meta['Last-Modified'][0])] : false;
    }

    /**
     * @inheritDoc
     */
    public function getVisibility($path)
    {
        $path = $this->applyPathPrefix($path);
        $meta = $this->getObjectClient()->getObjectACL($path);

        foreach ($meta['AccessControlPolicy']['AccessControlList']['Grant'] ?? [] as $grant) {
            if ('READ' === $grant['Permission'] && false !== strpos($grant['Grantee']['URI'] ?? '', 'global/AllUsers')) {
                return ['visibility' => AdapterInterface::VISIBILITY_PUBLIC];
            }
        }

        return ['path' => $path, 'visibility' => AdapterInterface::VISIBILITY_PRIVATE];
    }

    /**
     * @inheritDoc
     */
    public function setVisibility($path, $visibility)
    {
        return (bool)$this->getObjectClient()->putObjectACL(
            $this->applyPathPrefix($path),
            [],
            [
                'x-cos-acl' => $this->normalizeVisibility($visibility),
            ]
        );
    }

    /**
     * @param  string  $dirname
     * @param  Config  $config
     *
     * @return array|bool
     */
    public function createDir($dirname, Config $config)
    {
        $dirname = $this->applyPathPrefix($dirname);

        try {
            $this->getObjectClient()->putObject($dirname.'/', '');
        } catch (\Exception $e) {
            return false;
        }

        return ['type' => 'dir', 'path' => $dirname];
    }

    /**
     * @inheritDoc
     */
    public function deleteDir($dirname)
    {
        $response = $this->listObjects($this->applyPathPrefix($dirname));

        if (empty($response['Contents'])) {
            return true;
        }

        $keys = array_map(
            function ($item) {
                return ['Key' => $item['Key']];
            },
            $response['Contents']
        );

        return $this->getObjectClient()->deleteObjects(
            [
                'Delete' => [
                    'Quiet' => 'false',
                    'Object' => $keys,
                ],
            ]
        )->isSuccessful();
    }

    public function getUrl($path)
    {
        $path = $this->applyPathPrefix($path);

        if (!empty($this->config['cdn'])) {
            return \strval(new Uri(\sprintf('%s/%s', \rtrim($this->config['cdn'], '/'), $path)));
        }

        return $this->config['signed_url'] ? $this->getSignedUrl($path) : $this->getObjectClient()->getObjectUrl($path);
    }

    /**
     * @param  string  $path
     * @param  string  $expires
     *
     * @return string
     */
    public function getSignedUrl($path, $expires = '+60 minutes'): string
    {
        return $this->getObjectClient()->getObjectSignedUrl($this->applyPathPrefix($this->removePathPrefix($path)), $expires);
    }

    public function getObjectClient()
    {
        return $this->objectClient ?? $this->objectClient = new ObjectClient($this->config);
    }

    public function getBucketClient()
    {
        return $this->bucketClient ?? $this->bucketClient = new BucketClient($this->config);
    }

    /**
     * @param  \Overtrue\CosClient\ObjectClient  $objectClient
     *
     * @return $this
     */
    public function setObjectClient(ObjectClient $objectClient)
    {
        $this->objectClient = $objectClient;

        return $this;
    }

    /**
     * @param  \Overtrue\CosClient\BucketClient  $bucketClient
     *
     * @return $this
     */
    public function setBucketClient(BucketClient $bucketClient)
    {
        $this->bucketClient = $bucketClient;

        return $this;
    }

    /**
     * @param  string  $path
     *
     * @return string
     */
    protected function getSourcePath(string $path)
    {
        return sprintf(
            '%s-%s.cos.%s.myqcloud.com/%s',
            $this->config['bucket'],
            $this->config['app_id'],
            $this->config['region'],
            $path
        );
    }

    /**
     * @param  array  $content
     *
     * @return array
     */
    protected function normalizeFileInfo(array $content)
    {
        $path = pathinfo($content['Key']);

        return [
            'type' => '/' === substr($content['Key'], -1) ? 'dir' : 'file',
            'path' => $content['Key'],
            'size' => \intval($content['Size']),
            'dirname' => \strval($path['dirname'] === '.' ? '' : $path['dirname']),
            'basename' => \strval($path['basename']),
            'filename' => strval($path['filename']),
            'timestamp' => \strtotime($content['LastModified']),
            'extension' => $path['extension'] ?? '',
        ];
    }

    /**
     * @param  string  $directory
     * @param  bool    $recursive
     *
     * @return mixed
     */
    protected function listObjects($directory = '', $recursive = false)
    {
        $result = $this->getBucketClient()->getObjects(
            [
                'prefix' => ('' === (string)$directory) ? '' : ($directory.'/'),
                'delimiter' => $recursive ? '' : '/',
            ]
        )['ListBucketResult'];

        foreach (['CommonPrefixes', 'Contents'] as $key) {
            $result[$key] = $result[$key] ?? [];

            // 确保是二维数组
            if (($index = \key($result[$key])) !== 0) {
                $result[$key] = \is_null($index) ? [] : [$result[$key]];
            }
        }

        return $result;
    }

    /**
     * @param $visibility
     *
     * @return string
     */
    protected function normalizeVisibility($visibility)
    {
        return $visibility === AdapterInterface::VISIBILITY_PUBLIC ? 'public-read' : 'default';
    }
}
