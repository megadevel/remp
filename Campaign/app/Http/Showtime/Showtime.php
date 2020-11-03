<?php

namespace App\Http\Showtime;

use App\Banner;
use App\Campaign;
use App\CampaignBanner;
use App\Contracts\SegmentAggregator;
use App\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Predis\ClientInterface;
use Psr\Log\LoggerInterface;

class Showtime
{
    private const BANNER_ONETIME_USER_KEY = 'banner_onetime_user';

    private const BANNER_ONETIME_BROWSER_KEY = 'banner_onetime_browser';

    private $redis;

    private $segmentAggregator;

    private $logger;

    private $geoReader;

    private $request;

    private $deviceDetector;

    private $positionMap;

    private $dimensionMap;

    private $alignmentsMap;

    public function __construct(
        ClientInterface $redis,
        SegmentAggregator $segmentAggregator,
        LazyGeoReader $geoReader,
        LazyDeviceDetector $deviceDetector,
        LoggerInterface $logger
    ) {
        $this->redis = $redis;
        $this->segmentAggregator = $segmentAggregator;
        $this->logger = $logger;
        $this->geoReader = $geoReader;
        $this->deviceDetector = $deviceDetector;
    }

    public function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    private function getRequest()
    {
        if (!$this->request) {
            $this->request = \App\Http\Request::createFromGlobals();
        }
        return $this->request;
    }

    public function setPositionMap(\App\Models\Position\Map $positions)
    {
        $this->positionMap = $positions->positions();
    }

    private function getPositionMap()
    {
        if (!$this->positionMap) {
            $this->positionMap = json_decode($this->redis->get(\App\Models\Position\Map::POSITIONS_MAP_REDIS_KEY), true) ?? [];
        }
        return $this->positionMap;
    }

    public function setDimensionMap(\App\Models\Dimension\Map $dimensions)
    {
        $this->dimensionMap = $dimensions->dimensions();
    }

    private function getDimensionMap()
    {
        if (!$this->dimensionMap) {
            $this->dimensionMap = json_decode($this->redis->get(\App\Models\Dimension\Map::DIMENSIONS_MAP_REDIS_KEY), true) ?? [];
        }
        return $this->dimensionMap;
    }

    public function setAlignmentsMap(\App\Models\Alignment\Map $alignments)
    {
        $this->alignmentsMap = $alignments->alignments();
    }

    private function getAlignmentsMap()
    {
        if (!$this->alignmentsMap) {
            $this->alignmentsMap = json_decode($this->redis->get(\App\Models\Alignment\Map::ALIGNMENTS_MAP_REDIS_KEY), true) ?? [];
        }
        return $this->alignmentsMap;
    }

    public function showtime(string $userData, string $callback, ShowtimeResponse $showtimeResponse)
    {
        try {
            $data = json_decode($userData);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('could not decode JSON in Showtime: ' . $userData);
            return $showtimeResponse->error($callback, 400, ['invalid data json provided']);
        }

        $url = $data->url ?? null;
        if (!$url) {
            return $showtimeResponse->error($callback, 400, ['url is required and missing']);
        }

        $userId = null;
        if (isset($data->userId) || !empty($data->userId)) {
            $userId = $data->userId;
        }

        $browserId = null;
        if (isset($data->browserId) || !empty($data->browserId)) {
            $browserId = $data->browserId;
        }
        if (!$browserId) {
            return $showtimeResponse->error($callback, 400, ['browserId is required and missing']);
        }

        $segmentAggregator = $this->segmentAggregator;
        if (isset($data->cache)) {
            $segmentAggregator->setProviderData($data->cache);
        }

        $positions = $this->getPositionMap();
        $dimensions = $this->getDimensionMap();
        $alignments = $this->getAlignmentsMap();

        $displayData = [];

        // Try to load one-time banners (having precedence over campaigns)
        $banner = null;
        if ($userId) {
            $banner = $this->loadOneTimeUserBanner($userId);
        }
        if (!$banner) {
            $banner = $this->loadOneTimeBrowserBanner($browserId);
        }
        if ($banner) {
            $displayData[] = $showtimeResponse->renderBanner($banner, $alignments, $dimensions, $positions);
            return $showtimeResponse->success($callback, $displayData, [], $segmentAggregator->getProviderData());
        }

        $campaignIds = json_decode($this->redis->get(Campaign::ACTIVE_CAMPAIGN_IDS)) ?? [];
        if (count($campaignIds) === 0) {
            return $showtimeResponse->success($callback, [], [], $segmentAggregator->getProviderData());
        }

        $activeCampaignUuids = [];
        foreach ($campaignIds as $campaignId) {
            /** @var Campaign $campaign */
            $campaign = unserialize($this->redis->get(Campaign::CAMPAIGN_TAG . ":{$campaignId}"));
            $campaignBannerVariant = $this->shouldDisplay($campaign, $data, $activeCampaignUuids);
            if ($campaignBannerVariant) {
                $displayData[] = $showtimeResponse->renderCampaign($campaignBannerVariant, $campaign, $alignments, $dimensions, $positions);
            }
        }

        return $showtimeResponse->success($callback, $displayData, $activeCampaignUuids, $segmentAggregator->getProviderData());
    }


    /**
     * Determines if campaign should be displayed for user/browser
     * Return either null if campaign should not be displayed or actual variant of CampaignBanner to be displayed
     *
     * @param Campaign    $campaign
     * @param             $userData
     * @param array       $activeCampaignUuids
     *
     * @return CampaignBanner|null
     */
    public function shouldDisplay(Campaign $campaign, $userData, array &$activeCampaignUuids): ?CampaignBanner
    {
        $userId = $userData->userId;
        $browserId = $userData->browserId;
        $running = false;

        foreach ($campaign->schedules as $schedule) {
            if ($schedule->isRunning()) {
                $running = true;
                break;
            }
        }
        if (!$running) {
            return null;
        }

        /** @var Collection $campaignBanners */
        $campaignBanners = $campaign->campaignBanners->keyBy('uuid');

        // banner
        if ($campaignBanners->count() == 0) {
            $this->logger->error("Active campaign [{$campaign->uuid}] has no banner set");
            return null;
        }

        $bannerUuid = null;
        $variantUuid = null;

        // find variant previously displayed to user
        $seenCampaigns = $userData->campaigns ?? false;
        if ($seenCampaigns && isset($seenCampaigns->{$campaign->uuid})) {
            $bannerUuid = $seenCampaigns->{$campaign->uuid}->bannerId ?? null;
            $variantUuid = $seenCampaigns->{$campaign->uuid}->variantId ?? null;
        }

        // fallback for older version of campaigns local storage data
        // where decision was based on bannerUuid and not variantUuid (which was not present at all)
        if ($bannerUuid && !$variantUuid) {
            foreach ($campaignBanners as $campaignBanner) {
                if (optional($campaignBanner->banner)->uuid === $bannerUuid) {
                    $variantUuid = $campaignBanner->uuid;
                    break;
                }
            }
        }

        /** @var CampaignBanner $seenVariant */
        // unset seen variant if it was deleted
        if (!($seenVariant = $campaignBanners->get($variantUuid))) {
            $variantUuid = null;
        }

        // unset seen variant if its proportion is 0%
        if ($seenVariant && $seenVariant->proportion === 0) {
            $variantUuid = null;
        }

        // variant still not set, choose random variant
        if ($variantUuid === null) {
            $variantsMapping = $campaign->getVariantsProportionMapping();

            $randVal = mt_rand(0, 100);
            $currPercent = 0;

            foreach ($variantsMapping as $uuid => $proportion) {
                $currPercent = $currPercent + $proportion;
                if ($currPercent >= $randVal) {
                    $variantUuid = $uuid;
                    break;
                }
            }
        }

        /** @var CampaignBanner $variant */
        $variant = $campaignBanners->get($variantUuid);
        if (!$variant) {
            $this->logger->error("Unable to get CampaignBanner [{$variantUuid}] for campaign [{$campaign->uuid}]");
            return null;
        }

        // check if campaign is set to be seen only once per session
        $campaignsSeenInSession = $userData->campaignsSession ?? [];
        if ($campaign->once_per_session && $campaignsSeenInSession) {
            $seen = isset($campaignsSeenInSession->{$campaign->uuid});
            if ($seen) {
                return null;
            }
        }

        // signed in state
        if (isset($campaign->signed_in) && $campaign->signed_in !== (bool) $userId) {
            return null;
        }

        // using adblock?
        if ($campaign->using_adblock !== null) {
            if (!isset($userData->usingAdblock)) {
                Log::error("Unable to load if user with ID [{$userId}] & browserId [{$browserId}] is using AdBlock.");
                return null;
            }
            if (($campaign->using_adblock && !$userData->usingAdblock) || ($campaign->using_adblock === false && $userData->usingAdblock)) {
                return null;
            }
        }

        // url filters
        if ($campaign->url_filter === Campaign::URL_FILTER_EXCEPT_AT) {
            foreach ($campaign->url_patterns as $urlPattern) {
                if (strpos($userData->url, $urlPattern) !== false) {
                    return null;
                }
            }
        }
        if ($campaign->url_filter === Campaign::URL_FILTER_ONLY_AT) {
            $matched = false;
            foreach ($campaign->url_patterns as $urlPattern) {
                if (strpos($userData->url, $urlPattern) !== false) {
                    $matched = true;
                }
            }
            if (!$matched) {
                return null;
            }
        }

        // referer filters
        if ($campaign->referer_filter === Campaign::URL_FILTER_EXCEPT_AT && $userData->referer) {
            foreach ($campaign->referer_patterns as $refererPattern) {
                if (strpos($userData->referer, $refererPattern) !== false) {
                    return null;
                }
            }
        }
        if ($campaign->referer_filter === Campaign::URL_FILTER_ONLY_AT) {
            if (!$userData->referer) {
                return null;
            }
            $matched = false;
            foreach ($campaign->referer_patterns as $refererPattern) {
                if (strpos($userData->referer, $refererPattern) !== false) {
                    $matched = true;
                }
            }
            if (!$matched) {
                return null;
            }
        }

        // device rules
        if (!isset($userData->userAgent)) {
            $this->logger->error("Unable to load user agent for userId [{$userId}]");
        } else {
            if (!in_array(Campaign::DEVICE_MOBILE, $campaign->devices) && $this->deviceDetector->get($userData->userAgent)->isMobile()) {
                return null;
            }

            if (!in_array(Campaign::DEVICE_DESKTOP, $campaign->devices) && $this->deviceDetector->get($userData->userAgent)->isDesktop()) {
                return null;
            }
        }

        // country rules
        if (!$campaign->countries->isEmpty()) {
            // load country ISO code based on IP
            try {
                $countryCode = $this->geoReader->countryCode($this->getRequest()->ip());
            } catch (\MaxMind\Db\Reader\InvalidDatabaseException | \GeoIp2\Exception\AddressNotFoundException $e) {
                $this->logger->error("Unable to load country for campaign [{$campaign->uuid}] with country rules: " . $e->getMessage());
                return null;
            }
            if ($countryCode === null) {
                $this->logger->error("Unable to load country for campaign [{$campaign->uuid}] with country rules");
                return null;
            }

            // check against white / black listed countries

            if (!$campaign->countriesBlacklist->isEmpty() && $campaign->countriesBlacklist->contains('iso_code', $countryCode)) {
                return null;
            }
            if (!$campaign->countriesWhitelist->isEmpty() && !$campaign->countriesWhitelist->contains('iso_code', $countryCode)) {
                return null;
            }
        }

        // segment
        $segmentRulesOk = $this->evaluateSegmentRules($campaign, $browserId, $userId);
        if (!$segmentRulesOk) {
            return null;
        }

        // Active campaign is campaign that targets selected user (previous rules were passed),
        // but whether it displays or not depends on pageview counting rules (every n-th page, up to N pageviews).
        // We need to track such campaigns on the client-side too.
        $activeCampaignUuids[] = $campaign->uuid;

        $seenCampaign = $seenCampaigns->{$campaign->uuid} ?? null;

        // pageview rules - check display banner every n-th request
        if ($seenCampaign !== null && $campaign->pageview_rules !== null) {
            $pageviewCount =  $seenCampaign->count ?? null;

            if ($pageviewCount === null) {
                // if campaign is recorder as seen but has no pageview count,
                // it means there is a probably old version or remplib cached on the client
                // do not show campaign (browser should reload the library)
                return null;
            }

            $displayBanner = $campaign->pageview_rules['display_banner'] ?? null;
            $displayBannerEvery = $campaign->pageview_rules['display_banner_every'] ?? 1;
            if ($displayBanner === 'every' && $pageviewCount % $displayBannerEvery !== 0) {
                return null;
            }
        }

        // seen count rules
        if ($seenCampaign !== null && $campaign->pageview_rules !== null) {
            $seenCount = $seenCampaign->seen ?? null;

            if ($seenCount === null) {
                // if campaign is recorder as seen but has no pageview count,
                // it means there is a probably old version or remplib cached on the client
                // do not show campaign (browser should reload the library)
                return null;
            }

            $displayTimes = $campaign->pageview_rules['display_times'] ?? null;
            if ($displayTimes && $seenCount >= $campaign->pageview_rules['display_n_times']) {
                return null;
            }
        }

        return $variant;
    }

    public function displayForUser(Banner $banner, string $userId, int $expiresInSeconds)
    {
        $timestamp = Carbon::now()->getTimestamp();
        $key = self::BANNER_ONETIME_USER_KEY . ":$userId:$timestamp";

        $this->redis->rpush($key, $banner->id);
        $this->redis->expire($key, $expiresInSeconds);
    }

    public function displayForBrowser(Banner $banner, string $browserId, int $expiresInSeconds)
    {
        $timestamp = Carbon::now()->getTimestamp();
        $key = self::BANNER_ONETIME_BROWSER_KEY . ":$browserId:$timestamp";

        $this->redis->rpush($key, $banner->id);
        $this->redis->expire($key, $expiresInSeconds);
    }


    private function loadOneTimeUserBanner($userId): ?Banner
    {
        $userBannerKeys = [];
        foreach ($this->redis->keys(self::BANNER_ONETIME_USER_KEY . ":$userId:*") as $userBannerKey) {
            $parts = explode(':', $userBannerKey, 3);
            $userBannerKeys[$parts[2]] = $userBannerKey;
        }

        return $this->loadOneTimeBanner($userBannerKeys);
    }

    private function loadOneTimeBrowserBanner($browserId): ?Banner
    {
        $browserBannerKeys = [];
        foreach ($this->redis->keys(self::BANNER_ONETIME_BROWSER_KEY . ":$browserId:*") as $browserBannerKey) {
            $parts = explode(':', $browserBannerKey, 3);
            $browserBannerKeys[$parts[2]] = $browserBannerKey;
        }

        return $this->loadOneTimeBanner($browserBannerKeys);
    }

    private function evaluateSegmentRules(Campaign $campaign, $browserId, $userId = null)
    {
        if ($campaign->segments->isEmpty()) {
            return true;
        }

        foreach ($campaign->segments as $campaignSegment) {
            $campaignSegment->setRelation('campaign', $campaign); // setting this manually to avoid DB query

            if ($userId) {
                $belongsToSegment = $this->segmentAggregator->checkUser($campaignSegment, (string)$userId);
            } else {
                $belongsToSegment = $this->segmentAggregator->checkBrowser($campaignSegment, (string)$browserId);
            }

            // user is member of segment, that's excluded from campaign; halt execution
            if ($belongsToSegment && !$campaignSegment->inclusive) {
                return false;
            }
            // user is NOT member of segment, that's required for campaign; halt execution
            if (!$belongsToSegment && $campaignSegment->inclusive) {
                return false;
            }
        }

        return true;
    }

    private function loadOneTimeBanner(array $bannerKeys): ?Banner
    {
        // Banner keys have format BANNER_TAG:USER_ID/BROWSER_ID:TIMESTAMP
        // Try to display the earliest banner first, therefore sort banner keys here (indexed by TIMESTAMP)
        ksort($bannerKeys);

        foreach ($bannerKeys as $bannerKey) {
            $bannerId = $this->redis->lpop($bannerKey);
            if (!empty($bannerId)) {
                $banner = Banner::loadCachedBanner($this->redis, $bannerId);
                if (!$banner) {
                    throw new \Exception("Banner with ID $bannerId is not present in cache");
                }
                return $banner;
            }
        }
        return null;
    }
}
