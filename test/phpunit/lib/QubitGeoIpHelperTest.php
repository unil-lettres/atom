<?php

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @internal
 *
 * @coversNothing
 */
class QubitGeoIpHelperTest extends TestCase
{
    protected $geoIpHelper;
    protected $asnDbPath = '/tmp/fake-asn.mmdb';
    protected $cityDbPath = '/tmp/fake-city.mmdb';

    protected function setUp(): void
    {
        // Use a mock logger to avoid real logging
        $mockLogger = $this->createMock(LoggerInterface::class);
        $this->geoIpHelper = new QubitGeoIpHelper($this->asnDbPath, $this->cityDbPath, $mockLogger);
    }

    public function testConstructorSetsProperties()
    {
        $reflection = new ReflectionClass($this->geoIpHelper);

        $asnDbPath = $reflection->getProperty('asnDbPath');
        $asnDbPath->setAccessible(true);
        $cityDbPath = $reflection->getProperty('cityDbPath');
        $cityDbPath->setAccessible(true);
        $logger = $reflection->getProperty('logger');
        $logger->setAccessible(true);

        $this->assertEquals($this->asnDbPath, $asnDbPath->getValue($this->geoIpHelper), 'ASN DB path should be set by constructor.');
        $this->assertEquals($this->cityDbPath, $cityDbPath->getValue($this->geoIpHelper), 'City DB path should be set by constructor.');
        $this->assertInstanceOf(LoggerInterface::class, $logger->getValue($this->geoIpHelper), 'Logger should be set by constructor.');
    }

    // IPv4 Tests
    public function testIsPrivateIPWithPrivateIPv4()
    {
        $this->assertTrue($this->geoIpHelper->isPrivateIP('10.0.0.1'), '10.0.0.1 should be private.');
        $this->assertTrue($this->geoIpHelper->isPrivateIP('172.16.0.1'), '172.16.0.1 should be private.');
        $this->assertTrue($this->geoIpHelper->isPrivateIP('192.168.1.1'), '192.168.1.1 should be private.');
        $this->assertTrue($this->geoIpHelper->isPrivateIP('127.0.0.1'), '127.0.0.1 should be private (loopback).');
    }

    public function testIsPrivateIPWithPublicIPv4()
    {
        $this->assertFalse($this->geoIpHelper->isPrivateIP('8.8.8.8'), '8.8.8.8 should not be private.');
        $this->assertFalse($this->geoIpHelper->isPrivateIP('1.1.1.1'), '1.1.1.1 should not be private.');
    }

    public function testIsPrivateIPWithInvalidIPv4()
    {
        $this->assertFalse($this->geoIpHelper->isPrivateIP('999.999.999.999'), 'Invalid IPv4 should return false.');
        $this->assertFalse($this->geoIpHelper->isPrivateIP(''), 'Empty IPv4 should return false.');
    }

    // IPv6 Tests
    public function testIsPrivateIPWithPrivateIPv6()
    {
        $this->assertTrue($this->geoIpHelper->isPrivateIP('::1'), '::1 should be private (loopback).');
        $this->assertTrue($this->geoIpHelper->isPrivateIP('fc00::1'), 'fc00::1 should be private (ULA).');
        $this->assertTrue($this->geoIpHelper->isPrivateIP('fe80::1'), 'fe80::1 should be private (link-local).');
    }

    public function testIsPrivateIPWithPublicIPv6()
    {
        $this->assertFalse($this->geoIpHelper->isPrivateIP('2001:4860:4860::8888'), '2001:4860:4860::8888 should not be private.');
        $this->assertFalse($this->geoIpHelper->isPrivateIP('2606:4700:4700::1111'), '2606:4700:4700::1111 should not be private.');
    }

    public function testIsPrivateIPWithInvalidIPv6()
    {
        $this->assertFalse($this->geoIpHelper->isPrivateIP('invalid-ipv6'), 'Invalid IPv6 should return false.');
        $this->assertFalse($this->geoIpHelper->isPrivateIP(''), 'Empty IPv6 should return false.');
    }

    // ipInCidr tests
    public function testIpInCidrWithIPv4()
    {
        $this->assertTrue($this->geoIpHelper->ipInCidr('192.168.1.5', '192.168.1.0/24'), '192.168.1.5 should be in 192.168.1.0/24.');
        $this->assertFalse($this->geoIpHelper->ipInCidr('192.168.2.5', '192.168.1.0/24'), '192.168.2.5 should not be in 192.168.1.0/24.');
        $this->assertNull($this->geoIpHelper->ipInCidr('', '192.168.1.0/24'), 'Empty IP should return null.');
        $this->assertNull($this->geoIpHelper->ipInCidr('192.168.1.5', ''), 'Empty CIDR should return null.');
        $this->assertNull($this->geoIpHelper->ipInCidr('192.168.1.5', 'invalid-cidr'), 'Invalid CIDR should return null.');
    }

    public function testIpInCidrWithIPv6()
    {
        $this->assertTrue($this->geoIpHelper->ipInCidr('2001:db8::1', '2001:db8::/32'), '2001:db8::1 should be in 2001:db8::/32.');
        $this->assertFalse($this->geoIpHelper->ipInCidr('2001:db9::1', '2001:db8::/32'), '2001:db9::1 should not be in 2001:db8::/32.');
        $this->assertNull($this->geoIpHelper->ipInCidr('', '2001:db8::/32'), 'Empty IP should return null.');
        $this->assertNull($this->geoIpHelper->ipInCidr('2001:db8::1', ''), 'Empty CIDR should return null.');
        $this->assertNull($this->geoIpHelper->ipInCidr('2001:db8::1', 'invalid-cidr'), 'Invalid CIDR should return null.');
    }

    // getCountry and getAsn tests (mocking Reader)
    public function testGetCountryReturnsNullForPrivateIPv4()
    {
        $this->assertNull($this->geoIpHelper->getCountry('192.168.1.1'), 'Private IPv4 should return null.');
    }

    public function testGetCountryReturnsNullForPrivateIPv6()
    {
        $this->assertNull($this->geoIpHelper->getCountry('fc00::1'), 'Private IPv6 should return null.');
    }

    public function testGetCountryReturnsNullForInvalidIP()
    {
        $this->assertNull($this->geoIpHelper->getCountry('invalid-ip'), 'Invalid IP should return null.');
        $this->assertNull($this->geoIpHelper->getCountry(''), 'Empty IP should return null.');
    }

    public function testGetAsnReturnsNullForPrivateIPv4()
    {
        $this->assertNull($this->geoIpHelper->getAsn('192.168.1.1'), 'Private IPv4 should return null.');
    }

    public function testGetAsnReturnsNullForPrivateIPv6()
    {
        $this->assertNull($this->geoIpHelper->getAsn('fc00::1'), 'Private IPv6 should return null.');
    }

    public function testGetAsnReturnsNullForInvalidIP()
    {
        $this->assertNull($this->geoIpHelper->getAsn('invalid-ip'), 'Invalid IP should return null.');
        $this->assertNull($this->geoIpHelper->getAsn(''), 'Empty IP should return null.');
    }

    // Additional: test logging fallback to error_log if no logger is set
    public function testLogInfoFallsBackToErrorLogIfNoLogger()
    {
        $geoIpHelper = new QubitGeoIpHelper($this->asnDbPath, $this->cityDbPath, null);

        // Use reflection to access protected logInfo
        $reflection = new ReflectionClass($geoIpHelper);
        $method = $reflection->getMethod('logInfo');
        $method->setAccessible(true);

        // Can't easily test error_log output, but this ensures no exception is thrown
        $this->assertNull($method->invoke($geoIpHelper), 'logInfo should not throw if logger is not set.');
    }
}
