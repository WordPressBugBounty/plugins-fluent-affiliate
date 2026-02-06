<?php

namespace FluentAffiliate\App\Services;

use FluentAffiliate\App\Models\Affiliate;

class AffiliateService
{
    public static function getAffiliatesOptions($search = '', $includedIds = [], $limit = 20, $valueKey = 'id', $labelKey = 'full_name')
    {
        $affiliates = Affiliate::query()
            ->searchBy($search)
            ->whereHas('user')
            ->limit($limit)
            ->get();

        if ($includedIds) {
            $pushedIds = $affiliates->pluck('id')->toArray();
            $leftOutIds = array_diff($includedIds, $pushedIds);
            if ($leftOutIds) {
                $leftOutAffiliates = Affiliate::query()
                    ->whereIn('id', $leftOutIds)
                    ->whereHas('user')
                    ->get();
                $affiliates = $affiliates->merge($leftOutAffiliates);
            }
        }

        $formattedAffiliates = [];
        foreach ($affiliates as $affiliate) {
            if (empty($affiliate->user)) {
                continue;
            }

            $formattedAffiliates[] = [
                $valueKey => $affiliate->id,
                $labelKey => $affiliate->user_details['full_name'] ?? $affiliate->user->display_name,
            ];

        }

        return $formattedAffiliates;
    }
}