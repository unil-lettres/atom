<?php

use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class QubitUserChallengeTest extends TestCase
{
    protected $geoIpHelper;

    protected function setUp(): void
    {
        // Use a mock GeoIpHelper for all tests
        $this->geoIpHelper = $this->createMock(QubitGeoIpHelper::class);
    }

    public function testCheckHeadlessReturnsTrueWhenTestIsDisabled()
    {
        $config = [];
        $userChallenge = new QubitUserChallenge($config, $this->geoIpHelper);
        $this->assertTrue($userChallenge->checkHeadless('test_key'), 'Should return true if headless test is disabled.');
    }

    public function testCheckHeadlessReturnsTrueWhenCookieMatches()
    {
        $_COOKIE['headless_cookie'] = base64_encode('test_key:false');
        $config = [];
        $userChallenge = new QubitUserChallenge($config, $this->geoIpHelper);
        $this->assertTrue($userChallenge->checkHeadless('test_key'), 'Should return true if cookie matches and is not headless.');
    }

    public function testCheckHeadlessReturnsFalseWhenCookieDoesNotMatch()
    {
        $_COOKIE['headless_cookie'] = base64_encode('wrong_key:true');
        $config = ['test_headless' => true];
        $userChallenge = new QubitUserChallenge($config, $this->geoIpHelper);
        $this->assertFalse($userChallenge->checkHeadless('test_key'), 'Should return false if cookie does not match.');
    }

    public function testCheckHeadlessReturnsFalseWhenCookieIsMissing()
    {
        unset($_COOKIE['headless_cookie']);
        $config = ['test_headless' => true];
        $userChallenge = new QubitUserChallenge($config, $this->geoIpHelper);
        $this->assertFalse($userChallenge->checkHeadless('test_key'), 'Should return false if cookie is missing.');
    }

    public function testShouldBypassChallengeByCountry()
    {
        $config = [
            'country_exceptions' => [
                'canada' => ['country' => 'CA'],
            ],
        ];
        $this->geoIpHelper->method('getCountry')->willReturn('CA');
        $this->geoIpHelper->method('getAsn')->willReturn(12345);

        $userChallenge = new QubitUserChallenge($config, $this->geoIpHelper);
        $this->assertTrue($userChallenge->shouldBypassChallenge('1.2.3.4', 'UA'), 'Should bypass if country is in exceptions.');
    }

    public function testShouldBypassChallengeByAsnUserAgent()
    {
        $config = [
            'asn_user_agent_exceptions' => [
                'Test' => [
                    'asn' => 12345,
                    'user_agent' => '.*TestUA.*',
                ],
            ],
        ];
        $this->geoIpHelper->method('getCountry')->willReturn(null);
        $this->geoIpHelper->method('getAsn')->willReturn(12345);

        $userChallenge = new QubitUserChallenge($config, $this->geoIpHelper);
        $this->assertTrue($userChallenge->shouldBypassChallenge('1.2.3.4', 'TestUA'), 'Should bypass if ASN and UA match exception.');
    }

    public function testShouldBypassChallengeByNetworkUserAgent()
    {
        $config = [
            'network_user_agent_exceptions' => [
                'test' => [
                    'src_net' => '192.168.1.0/24',
                    'user_agent' => '.*TestUA.*',
                ],
            ],
        ];
        $this->geoIpHelper->method('getCountry')->willReturn(null);
        $this->geoIpHelper->method('getAsn')->willReturn(null);
        $this->geoIpHelper->method('ipInCidr')->willReturn(true);

        $userChallenge = new QubitUserChallenge($config, $this->geoIpHelper);
        $this->assertTrue($userChallenge->shouldBypassChallenge('192.168.1.5', 'TestUA'), 'Should bypass if network and UA match exception.');
    }

    public function testShouldBypassChallengeByAsnOnly()
    {
        $config = ['asn_exceptions' => [12345]];
        $this->geoIpHelper->method('getCountry')->willReturn(null);
        $this->geoIpHelper->method('getAsn')->willReturn(12345);

        $userChallenge = new QubitUserChallenge($config, $this->geoIpHelper);
        $this->assertTrue($userChallenge->shouldBypassChallenge('1.2.3.4', 'UA'), 'Should bypass if ASN is in exceptions.');
    }

    public function testShouldBypassChallengeByCidrOnly()
    {
        $config = ['cidr_exceptions' => ['192.168.1.0/24']];
        $this->geoIpHelper->method('getCountry')->willReturn(null);
        $this->geoIpHelper->method('getAsn')->willReturn(null);
        $this->geoIpHelper->method('ipInCidr')->willReturn(true);

        $userChallenge = new QubitUserChallenge($config, $this->geoIpHelper);
        $this->assertTrue($userChallenge->shouldBypassChallenge('192.168.1.5', 'UA'), 'Should bypass if IP is in CIDR exception.');
    }

    public function testShouldNotBypassChallengeIfNoExceptionsMatch()
    {
        $config = [];
        $this->geoIpHelper->method('getCountry')->willReturn('US');
        $this->geoIpHelper->method('getAsn')->willReturn(54321);
        $this->geoIpHelper->method('ipInCidr')->willReturn(false);

        $userChallenge = new QubitUserChallenge($config, $this->geoIpHelper);
        $this->assertFalse($userChallenge->shouldBypassChallenge('8.8.8.8', 'SomeUA'), 'Should not bypass if no exceptions match.');
    }

    public function testLogInfoFallsBackToErrorLogIfNoLogger()
    {
        $config = [];
        $userChallenge = new QubitUserChallenge($config, $this->geoIpHelper, null);

        $reflection = new ReflectionClass($userChallenge);
        $method = $reflection->getMethod('logInfo');
        $method->setAccessible(true);

        // Should not throw even if logger is not set
        $this->assertNull($method->invoke($userChallenge), 'logInfo should not throw if logger is not set.');
    }
}
