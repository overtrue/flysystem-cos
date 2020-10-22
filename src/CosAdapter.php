<?php

/*
 * This file is part of the overtrue/flysystem-cos.
 * (c) overtrue <i@overtrue.me>
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Overtrue\Flysystem\Cos;

use GuzzleHttp\Client as HttpClient;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\CanOverwriteFiles;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use Qcloud\Cos\Client;
use Qcloud\Cos\Exception\NoSuchKeyException;

/**
 * Class CosAdapter.
 */
class CosAdapter extends AbstractAdapter implements CanOverwriteFiles
{
    /**
     * @var Client
     */
    protected $client;

    /**
     * @var \GuzzleHttp\Client
     */
    protected $httpClient;

    /**
     * @var array
     */
    protected $config;

    /**
     * CosAdapter constructor.
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;

        if (!empty($this->config['cdn'])) {
            $this->setPathPrefix($this->config['cdn']);
        }
    }

    /**
     * @return string
     */
    public function getBucket()
    {
        return $this->config['bucket'];
    }

    /**
     * @return string
     */
    public function getAppId()
    {
        return $this->config['credentials']['appId'] ?? null;
    }

    /**
     * @return string
     */
    public function getRegion()
    {
        return $this->config['region'] ?? '';
    }

    /**
     * @param string $path
     *
     * @return string
     */
    public function getSourcePath($path)
    {
        return sprintf('%s.cos.%s.myqcloud.com/%s',
            $this->getBucket(), $this->getRegion(), $path
        );
    }

    /**
     * @param string $path
     *
     * @return string
     */
    public function getUrl($path)
    {
        if (!empty($this->config['cdn'])) {
            return $this->applyPathPrefix($path);
        }

        $options = [
            'Scheme' => $this->config['scheme'] ?? 'http',
        ];

        return $this->getClient()->getObjectUrl(
            $this->getBucket(), $path, null, $options
        );
    }

    /**
     * @param string     $path
     * @param string|int $expiration
     * @param array      $options
     *
     * @return string
     */
    public function getTemporaryUrl($path, $expiration, array $options = [])
    {
        $options = array_merge($options, ['Scheme' => $this->config['scheme'] ?? 'http']);

        $expiration = date('c', !\is_numeric($expiration) ? \strtotime($expiration) : \intval($expiration));

        $objectUrl = $this->getClient()->getObjectUrl(
            $this->getBucket(), $path, $expiration, $options
        );

        $url = parse_url($objectUrl);

        if (!empty($this->config['cdn'])) {
            return \sprintf(
                '%s/%s?%s',
                \rtrim($this->config['cdn'], '/'),
                \ltrim(urldecode($url['path']), '/'),
                $url['query']
            );
        }

        return $objectUrl;
    }

    /**
     * @param string $path
     * @param string $contents
     * @param Config $config
     *
     * @return array|false
     */
    public function write($path, $contents, Config $config)
    {
        $options = $this->getUploadOptions($config);

        return $this->getClient()->upload($this->getBucket(), $path, $contents, $options['params']);
    }

    /**
     * @param string   $path
     * @param resource $resource
     * @param Config   $config
     *
     * @return array|false
     */
    public function writeStream($path, $resource, Config $config)
    {
        $options = $this->getUploadOptions($config);

        return $this->getClient()->upload(
            $this->getBucket(),
            $path,
            stream_get_contents($resource, -1, 0),
            $options['params']
        );
    }

    /**
     * @param string $path
     * @param string $contents
     * @param Config $config
     *
     * @return array|bool
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * @param string   $path
     * @param resource $resource
     * @param Config   $config
     *
     * @return array|bool
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * @param string $path
     * @param string $to
     *
     * @return bool
     */
    public function rename($path, $to)
    {
        $result = $this->copy($path, $to);

        $this->delete($path);

        return $result;
    }

    /**
     * @param string $path
     * @param string $to
     *
     * @return bool
     */
    public function copy($path, $to)
    {
        $source = [
            'Region' => $this->getRegion(),
            'Bucket' => $this->getBucket(),
            'Key' => $path,
        ];

        return (bool) $this->getClient()->copy($this->getBucket(), $to, $source);
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public function delete($path)
    {
        return (bool) $this->getClient()->deleteObject([
            'Bucket' => $this->getBucket(),
            'Key' => $path,
        ]);
    }

    /**
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        $response = $this->listObjects($dirname);

        if (empty($response['Contents'])) {
            return true;
        }

        $keys = array_map(function ($item) {
            return ['Key' => $item['Key']];
        }, (array) $response['Contents']);

        return (bool) $this->getClient()->deleteObjects([
            'Bucket' => $this->getBucket(),
            'Objects' => $keys,
        ]);
    }

    /**
     * @param string $dirname
     * @param Config $config
     *
     * @return array|bool
     */
    public function createDir($dirname, Config $config)
    {
        return $this->getClient()->putObject([
            'Bucket' => $this->getBucket(),
            'Key' => $dirname.'/',
            'Body' => '',
        ]);
    }

    /**
     * @param string $path
     * @param string $visibility
     *
     * @return array|false
     */
    public function setVisibility($path, $visibility)
    {
        return (bool) $this->getClient()->PutObjectAcl([
            'Bucket' => $this->getBucket(),
            'Key' => $path,
            'ACL' => $this->normalizeVisibility($visibility),
        ]);
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public function has($path)
    {
        try {
            return (bool) $this->getMetadata($path);
        } catch (\Throwable $e) {
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function read($path)
    {
        try {
            if ($this->config['read_from_cdn']) {
                $response = $this->getHttpClient()
                    ->get($this->getTemporaryUrl($path, date('+5 min')))
                    ->getBody()
                    ->getContents();
            } else {
                $response = $this->getClient()->getObject([
                    'Bucket' => $this->getBucket(),
                    'Key' => $path,
                ])['Body'];
            }

            return ['contents' => (string) $response];
        } catch (\Throwable $e) {
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getClient()
    {
        return $this->client ?: $this->client = new Client($this->config);
    }

    /**
     * @param \Qcloud\Cos\Client $client
     *
     * @return $this
     */
    public function setClient(Client $client)
    {
        $this->client = $client;

        return $this;
    }

    /**
     * @return \GuzzleHttp\Client
     */
    public function getHttpClient()
    {
        return $this->httpClient ?: $this->httpClient = new HttpClient();
    }

    /**
     * @param \GuzzleHttp\Client $client
     *
     * @return $this
     */
    public function setHttpClient(HttpClient $client)
    {
        $this->httpClient = $client;

        return $this;
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function readStream($path)
    {
        try {
            $temporaryUrl = $this->getTemporaryUrl($path, \strtotime('+5 min'));

            $stream = $this->getHttpClient()
                ->get($temporaryUrl, ['stream' => true])
                ->getBody()
                ->detach();

            return ['stream' => $stream];
        } catch (\Throwable $e) {
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param string $directory
     * @param bool   $recursive
     *
     * @return array|bool
     */
    public function listContents($directory = '', $recursive = false)
    {
        $list = [];

        $response = $this->listObjects($directory, $recursive);

        foreach ((array) $response['Contents'] as $content) {
            $list[] = $this->normalizeFileInfo($content);
        }

        return $list;
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function getMetadata($path)
    {
        return $this->getClient()->headObject([
            'Bucket' => $this->getBucket(),
            'Key' => $path,
        ]);
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function getSize($path)
    {
        $meta = $this->getMetadata($path);

        return isset($meta['ContentLength']) ? ['size' => $meta['ContentLength']] : false;
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function getMimetype($path)
    {
        $meta = $this->getMetadata($path);

        return isset($meta['ContentType']) ? ['mimetype' => $meta['ContentType']] : false;
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function getTimestamp($path)
    {
        $meta = $this->getMetadata($path);

        return isset($meta['LastModified']) ? ['timestamp' => strtotime($meta['LastModified'])] : false;
    }

    /**
     * @param string $path
     *
     * @return array|bool
     */
    public function getVisibility($path)
    {
        $meta = $this->getClient()->getObjectAcl([
            'Bucket' => $this->getBucket(),
            'Key' => $path,
        ]);

        foreach ($meta->get('Grants') as $grant) {
            if ('READ' === $grant['Permission'] && false !== strpos($grant['Grantee']['URI'] ?? '', 'global/AllUsers')) {
                return ['visibility' => AdapterInterface::VISIBILITY_PUBLIC];
            }
        }

        return ['visibility' => AdapterInterface::VISIBILITY_PRIVATE];
    }

    /**
     * @param array $content
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
            'dirname' => \strval($path['dirname']),
            'basename' => \strval($path['basename']),
            'filename' => strval($path['filename']),
            'timestamp' => \strtotime($content['LastModified']),
            'extension' => $path['extension'] ?? '',
        ];
    }

    /**
     * @param string $directory
     * @param bool   $recursive
     *
     * @return mixed
     */
    protected function listObjects($directory = '', $recursive = false)
    {
        return $this->getClient()->listObjects([
            'Bucket' => $this->getBucket(),
            'Prefix' => ('' === (string) $directory) ? '' : ($directory.'/'),
            'Delimiter' => $recursive ? '' : '/',
        ]);
    }

    /**
     * @param Config $config
     *
     * @return array
     */
    protected function getUploadOptions(Config $config)
    {
        $options = ['params' => []];

        if ($config->has('params')) {
            $options['params'] = (array) $config->get('params');
        }

        if ($config->has('visibility')) {
            $options['params']['ACL'] = $this->normalizeVisibility($config->get('visibility'));
        }

        return $options;
    }

    /**
     * @param $visibility
     *
     * @return string
     */
    protected function normalizeVisibility($visibility)
    {
        switch ($visibility) {
            case AdapterInterface::VISIBILITY_PUBLIC:
                $visibility = 'public-read';
                break;
        }

        return $visibility;
    }
}
