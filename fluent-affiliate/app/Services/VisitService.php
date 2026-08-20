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

        $ipAddress = '';
        if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
            $ipAddress = sanitize_text_field(wp_unslash($_SERVER["REMOTE_ADDR"]));
            //If it's a valid Cloudflare request
            if (self::isCfIp($ipAddress)) {
                //Use the CF-Connecting-IP header.
                $ipAddress = self::validateProxyIp(sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP'])));
            }
        } else if ($_SERVER['REMOTE_ADDR'] == '127.0.0.1' && self::shouldTrustProxyHeaders()) {
            // most probably it's local reverse proxy
            if (isset($_SERVER["HTTP_CLIENT_IP"])) {
                $ipAddress = self::validateProxyIp(sanitize_text_field(wp_unslash($_SERVER["HTTP_CLIENT_IP"])));
            } else if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ipAddress = self::validateProxyIp(sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])));
            }
        }

        if (!$ipAddress) {
            $ipAddress = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        $ipAddress = preg_replace('/^(\d+\.\d+\.\d+\.\d+):\d+$/', '\1', $ipAddress);

        $ipAddress = apply_filters('fluent_affiliate/user_ip', $ipAddress);

        if ($anonymize) {
            return wp_privacy_anonymize_ip($ipAddress);
        }

        return $ipAddress;
    }

    /**
     * Proxy headers are attacker controlled, so a value is only accepted when it
     * really is an IP address. getIp() keys the auth rate limit, a spoofable one
     * resets the throttle at will.
     *
     * @param string $value
     * @return string empty when the header does not hold a usable IP
     */
    protected static function validateProxyIp($value)
    {
        $value = sanitize_text_field(wp_unslash($value));

        $value = trim(current(explode(',', $value)));

        return (string)rest_is_ip_address($value);
    }

    /**
     * @return bool
     */
    protected static function shouldTrustProxyHeaders()
    {
        return (bool)apply_filters('fluent_affiliate/trust_proxy_headers', true);
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

        $cloudflareIPRanges = array(
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
        );

        //Make sure that the request came via Cloudflare.
        foreach ($cloudflareIPRanges as $range) {
            //Use the ip_in_range function from Joomla.
            if (self::ipInRange($ip, $range)) {
                //IP is valid. Belongs to Cloudflare.
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the IP address is in the given range.
     *
     * @param string $ip The IP address to check.
     * @param string $range The range to check against.
     * @return bool True if the IP is in the range, false otherwise.
     */
    private static function ipInRange($ip, $range)
    {
        if (strpos($range, '/') !== false) {
            // $range is in IP/NETMASK format
            list($range, $netmask) = explode('/', $range, 2);
            if (strpos($netmask, '.') !== false) {
                // $netmask is a 255.255.0.0 format
                $netmask = str_replace('*', '0', $netmask);
                $netmask_dec = ip2long($netmask);
                return ((ip2long($ip) & $netmask_dec) == (ip2long($range) & $netmask_dec));
            } else {
                // $netmask is a CIDR size block
                // fix the range argument
                $x = explode('.', $range);
                while (count($x) < 4) $x[] = '0';
                list($a, $b, $c, $d) = $x;
                $range = sprintf("%u.%u.%u.%u", empty($a) ? '0' : $a, empty($b) ? '0' : $b, empty($c) ? '0' : $c, empty($d) ? '0' : $d);
                $range_dec = ip2long($range);
                $ip_dec = ip2long($ip);

                # Strategy 1 - Create the netmask with 'netmask' 1s and then fill it to 32 with 0s
                #$netmask_dec = bindec(str_pad('', $netmask, '1') . str_pad('', 32-$netmask, '0'));

                # Strategy 2 - Use math to create it
                $wildcard_dec = pow(2, (32 - $netmask)) - 1;
                $netmask_dec = ~$wildcard_dec;

                return (($ip_dec & $netmask_dec) == ($range_dec & $netmask_dec));
            }
        } else {
            // range might be 255.255.*.* or 1.2.3.0-1.2.3.255
            if (strpos($range, '*') !== false) { // a.b.*.* format
                // Just convert to A-B format by setting * to 0 for A and 255 for B
                $lower = str_replace('*', '0', $range);
                $upper = str_replace('*', '255', $range);
                $range = "$lower-$upper";
            }

            if (strpos($range, '-') !== false) { // A-B format
                list($lower, $upper) = explode('-', $range, 2);
                $lower_dec = (float)sprintf("%u", ip2long($lower));
                $upper_dec = (float)sprintf("%u", ip2long($upper));
                $ip_dec = (float)sprintf("%u", ip2long($ip));
                return (($ip_dec >= $lower_dec) && ($ip_dec <= $upper_dec));
            }
            return false;
        }
    }
}
