<?php

declare(strict_types=1);

namespace Synthigy\Tests;

use PHPUnit\Framework\TestCase;
use Synthigy\Client;
use Synthigy\Synthigy;
use Synthigy\SynthigyError;

final class SynthigyTest extends TestCase
{
    protected function tearDown(): void
    {
        Synthigy::disconnect();
    }

    public function testNotConnectedRaises(): void
    {
        Synthigy::disconnect();
        $this->expectException(SynthigyError::class);
        $this->expectExceptionMessage('not connected');
        Synthigy::client();
    }

    public function testConnectInstallsDefaultAndReturnsClient(): void
    {
        $c = Synthigy::connect('https://synthigy.example.test', token: 'tok');
        self::assertInstanceOf(Client::class, $c);
        self::assertSame($c, Synthigy::client());
    }

    public function testReconnectReplacesDefault(): void
    {
        $first = Synthigy::connect('https://synthigy.example.test', token: 'tok-1');
        $second = Synthigy::connect('https://synthigy.example.test', token: 'tok-2');
        self::assertNotSame($first, $second);
        self::assertSame($second, Synthigy::client());
    }

    public function testDisconnectUninstallsDefault(): void
    {
        Synthigy::connect('https://synthigy.example.test', token: 'tok');
        Synthigy::disconnect();
        $this->expectException(SynthigyError::class);
        Synthigy::client();
    }
}
