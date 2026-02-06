<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
?>

<table class="fluent-affiliate-dashboard-widget">
    <thead>
    <tr>
    <?php foreach ($headers as $fluentAffiliateHeader):?>
        <th><?php echo esc_html($fluentAffiliateHeader['label']) ?></th>
    <?php endforeach; ?>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($stats as $fluentAffiliateStat):?>
        <tr>
            <td><?php echo esc_html($fluentAffiliateStat['label']) ?></td>
            <td><?php echo esc_html($fluentAffiliateStat['value']) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
