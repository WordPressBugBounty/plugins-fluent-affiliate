<?php

namespace FluentAffiliate\App\Services;

use FluentAffiliate\App\Models\Affiliate;
use FluentAffiliate\App\Models\Visit;

class VisitService
{
    public static function addVisit($affliate, $data)
    {
        if (is_numeric($affliate)) {
            $affliate = Affiliate::find($affliate);
        }

        if (!$affliate) {
            return null;
        }

        $data['affiliate_id'] = $affliate->id;

        $visit = Visit::create($data);

        $affliate->increase('visits');

        if ($visit && !empty($data['ip'])) {
            self::rememberVisit($affliate->id, $data['ip'], $visit->id);
            self::countVisitCreation($data['ip']);
        }

        return $visit;
    }

    /**
     * Visit tracking is a public, cookie-less endpoint, so the same IP hammering
     * it would otherwise create an unbounded number of rows and inflate the
     * affiliate visit counter. Within the dedup window we hand back the visit
     * row that IP already produced for the same affiliate instead of a new one,
     * so attribution keeps working while the counter stays honest.
     *
     * @param int $affiliateId
     * @param string $ip
     * @return \FluentAffiliate\App\Models\Visit|null
     */
    public static function getRecentVisit($affiliateId, $ip)
    {
        if (!$affiliateId || !$ip || self::dedupWindow() <= 0) {
            return null;
        }

        $visitId = (int)get_transient(self::dedupKey($affiliateId, $ip));

        if (!$visitId) {
            return null;
        }

        $visit = Visit::find($visitId);

        if (!$visit || $visit->affiliate_id != $affiliateId) {
            return null;
        }

        return $visit;
    }

    /**
     * @param int $affiliateId
     * @param string $ip
     * @param int $visitId
     * @return void
     */
    public static function rememberVisit($affiliateId, $ip, $visitId)
    {
        $window = self::dedupWindow();

        if (!$affiliateId || !$ip || !$visitId || $window <= 0) {
            return;
        }

        set_transient(self::dedupKey($affiliateId, $ip), (int)$visitId, $window);
    }

    /**
     * Backstop against an IP that rotates the affiliate param to sidestep the
     * per-affiliate dedup. The limit is deliberately far above anything a real
     * visitor produces, so legitimate attribution is never dropped.
     *
     * @param string $ip
     * @return bool
     */
    public static function isVisitFlooding($ip)
    {
        if (!$ip) {
            return false;
        }

        $limit = (int)apply_filters('fluent_affiliate/visit_flood_limit', 100);

        if ($limit <= 0) {
            return false;
        }

        return (int)get_transient(self::floodKey($ip)) >= $limit;
    }

    /**
     * @param string $ip
     * @return void
     */
    protected static function countVisitCreation($ip)
    {
        $key = self::floodKey($ip);

        set_transient($key, (int)get_transient($key) + 1, HOUR_IN_SECONDS);
    }

    /**
     * @return int
     */
    protected static function dedupWindow()
    {
        $window = (int)apply_filters('fluent_affiliate/visit_dedup_window', 30 * MINUTE_IN_SECONDS);

        return $window > 0 ? $window : 0;
    }

    /**
     * @param int $affiliateId
     * @param string $ip
     * @return string
     */
    protected static function dedupKey($affiliateId, $ip)
    {
        return 'fa_visit_dedup_' . md5($affiliateId . '|' . $ip);
    }

    /**
     * @param string $ip
     * @return string
     */
    protected static function floodKey($ip)
    {
        return 'fa_visit_flood_' . md5($ip);
    }


    /**
     * Get the IP address of the user.
     *
     * @param bool $anonymize Whether to anonymize the IP address.
     * @return string The IP address.
     */
    public static function getIp($anonymize = false)
    {
        static $ipAddress;

        if ($ipAddress) {
            return $ipAddress;
        }

        if (empty($_SERVER['REMOTE_ADDR'])) {
            // It's a local cli request
            return '127.0.0.1';
        }

        $ipAddress = self::resolveClientIp($_SERVER);

        $ipAddress = apply_filters('fluent_affiliate/user_ip', $ipAddress);

        if ($anonymize) {
            return wp_privacy_anonymize_ip($ipAddress);
        }

        return $ipAddress;
    }

    /**
     * @param array $serverData $_SERVER or an equivalent
     * @return string
     */
    public static function resolveClientIp($serverData)
    {
        $remoteAddr = self::stripPort(sanitize_text_field(wp_unslash((string) ($serverData['REMOTE_ADDR'] ?? ''))));
        $cloudflareIp = (string) rest_is_ip_address(self::stripPort(sanitize_text_field(wp_unslash((string) ($serverData['HTTP_CF_CONNECTING_IP'] ?? '')))));

        if ($cloudflareIp && self::isCfIp($remoteAddr)) {
            return $cloudflareIp;
        }

        // only the right-most hop is written by the proxy; the rest is client sent
        if (self::isTrustedProxy($remoteAddr)) {
            $hops = explode(',', sanitize_text_field(wp_unslash((string) ($serverData['HTTP_X_FORWARDED_FOR'] ?? ''))));
            $forwardedIp = (string) rest_is_ip_address(trim(end($hops)));

            if ($forwardedIp) {
                return $forwardedIp;
            }
        }

        return $remoteAddr;
    }

    /**
     * @param string $ip
     * @return string The address without a port, for both 1.2.3.4:80 and [2001:db8::1]:80
     */
    private static function stripPort($ip)
    {
        $ip = trim((string) $ip);

        if (preg_match('/^\[(.+)\](?::\d+)?$/', $ip, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $ip, $matches)) {
            return $matches[1];
        }

        return $ip;
    }

    /**
     * @param string $remoteAddr
     * @return bool
     */
    protected static function isTrustedProxy($remoteAddr)
    {
        if (!apply_filters('fluent_affiliate/trust_proxy_headers', true)) {
            return false;
        }

        return in_array($remoteAddr, (array)apply_filters('fluent_affiliate/trusted_proxies', ['127.0.0.1']), true);
    }

    /**
     * Check if the IP address is from Cloudflare.
     *
     * @param string $ip The IP address to check.
     * @return bool True if the IP is from Cloudflare, false otherwise.
     */
    public static function isCfIp($ip = '')
    {
        if (!$ip && isset($_SERVER["REMOTE_ADDR"])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER["REMOTE_ADDR"]));
        }

        if (!$ip) {
            return false;
        }

        // Cloudflare reaches IPv6 origins over IPv6, so a v4-only list puts every visitor behind one edge.
        // Filterable so a range change does not have to wait for a release. Source: https://www.cloudflare.com/ips/
        $cloudflareIPRanges = (array) apply_filters('fluent_affiliate/cloudflare_ip_ranges', [
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        ]);

        foreach ($cloudflareIPRanges as $range) {
            if (self::ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * CIDR or exact match for both IPv4 and IPv6, on the packed binary form,
     * because ip2long() returns false for any IPv6 address.
     *
     * @param string $ip The IP address to check.
     * @param string $range The range to check against.
     * @return bool True if the IP is in the range, false otherwise.
     */
    private static function ipInRange($ip, $range)
    {
        if (strpos($range, '/') === false) {
            return $ip === $range;
        }

        list($subnet, $bits) = explode('/', $range, 2);

        if (!filter_var($ip, FILTER_VALIDATE_IP) || !filter_var($subnet, FILTER_VALIDATE_IP)) {
            return false;
        }

        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        // Differing lengths means v4 against v6.
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;

        if ($bits < 0 || $bits > strlen($ipBin) * 8) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $remainingBits = $bits % 8;

        if ($wholeBytes && strncmp($ipBin, $subnetBin, $wholeBytes) !== 0) {
            return false;
        }

        if ($remainingBits) {
            $mask = chr((0xFF << (8 - $remainingBits)) & 0xFF);
            if ((($ipBin[$wholeBytes] ^ $subnetBin[$wholeBytes]) & $mask) !== "\0") {
                return false;
            }
        }

        return true;
    }
}
