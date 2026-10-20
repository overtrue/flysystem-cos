<?php

namespace Overtrue\Flysystem\Cos\Tests;

use GuzzleHttp\Psr7\Response as PsrResponse;
use League\Flysystem\Config;
use Mockery;
use Overtrue\CosClient\Http\Response;
use Overtrue\CosClient\ObjectClient;
use Overtrue\Flysystem\Cos\CosAdapter;
use PHPUnit\Framework\TestCase;

class CosAdapterTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function copySourceProvider(): array
    {
        return [
            'plain' => ['dir/file.md', 'dir/file.md'],
            'space' => ['dir/a b.md', 'dir/a%20b.md'],
            'multibyte' => ['目录/海报 1.md', '%E7%9B%AE%E5%BD%95/%E6%B5%B7%E6%8A%A5%201.md'],
            'reserved' => ['dir/x~y+z&q=1#h%.md', 'dir/x%7Ey%2Bz%26q%3D1%23h%25.md'],
        ];
    }

    /**
     * @dataProvider copySourceProvider
     */
    public function test_copy_encodes_source_key(string $source, string $encodedKey): void
    {
        $expectedSource = 'bucket-123.cos.ap-guangzhou.myqcloud.com/prefix/'.$encodedKey;

        $client = Mockery::mock(ObjectClient::class);
        $client->shouldReceive('copyObject')
            ->once()
            ->with('prefix/target.md', ['x-cos-copy-source' => $expectedSource])
            ->andReturn(new Response(new PsrResponse(200)));

        $adapter = new CosAdapter(['bucket' => 'bucket', 'app_id' => '123', 'prefix' => 'prefix']);
        $adapter->setObjectClient($client);

        $adapter->copy($source, 'target.md', new Config);

        // The signer encodes header values with RFC 1738 while COS uses RFC 3986, so they must agree.
        $this->assertSame(
            'x-cos-copy-source='.\rawurlencode($expectedSource),
            \http_build_query(['x-cos-copy-source' => $expectedSource])
        );
    }
}
