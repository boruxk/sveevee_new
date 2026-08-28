<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\Conversation;
use App\Models\Page;
use App\Models\PageChatMessage;
use App\Models\PageConversation;
use App\Models\PageEvent;
use App\Models\PagePrice;
use App\Models\PageProduct;
use App\Models\PageRating;
use App\Models\PageService;
use App\Models\User;
use App\Models\UserProfile;
use App\Support\PublicImageVariants;

class PayloadService
{
    private const DEFAULT_OPENING_HOURS = [
        ['weekday' => 'sunday', 'is_open' => false, 'opens_at' => null, 'closes_at' => null],
        ['weekday' => 'monday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ['weekday' => 'tuesday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ['weekday' => 'wednesday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ['weekday' => 'thursday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '17:00'],
        ['weekday' => 'friday', 'is_open' => true, 'opens_at' => '09:00', 'closes_at' => '13:00'],
        ['weekday' => 'saturday', 'is_open' => false, 'opens_at' => null, 'closes_at' => null],
    ];

    public function __construct(private readonly SystemSettingsService $settings) {}

    public function user(User $user, bool $includePrivate = false): array
    {
        $user->loadMissing(['profile', 'pages']);

        $payload = [
            'id' => $user->id,
            'slug' => $user->public_slug,
            'public_path' => '/users/'.$user->public_slug,
            'name' => $user->name,
            'given_name' => $user->given_name,
            'family_name' => $user->family_name,
            'display_name' => $user->display_name,
            'role' => $user->role,
            'role_names' => $user->role_names,
            'profile' => $this->profile($user->profile, $user),
            'business_page' => $this->firstPageOfType($user, 'business'),
            'community_page' => $this->firstPageOfType($user, 'community'),
        ];

        if ($includePrivate) {
            $payload['email'] = $user->email;
            $payload['locale'] = $user->locale;
            $payload['has_password'] = filled($user->password);
            $payload['profile_complete'] = $this->profileComplete($user);
            $payload['missing_profile_fields'] = $this->missingProfileFields($user);
            $payload['banned_at'] = $user->banned_at?->toISOString();
            $payload['unread_messages_count'] = $this->unreadMessageCount($user);
        }

        return $payload;
    }

    public function profile(?UserProfile $profile, ?User $user = null): array
    {
        return [
            'email' => $user?->email,
            'photo_url' => $profile?->photo_url,
            ...$this->publicImageMeta('photo', $profile?->photo_path, $user?->display_name, '96px'),
            'photo_name' => $profile?->photo_original_name,
            'phone' => $profile?->phone,
            'city' => $profile?->city,
            'neighborhood' => $profile?->neighborhood,
            'user_type' => $profile?->user_type,
            'locale' => $user?->locale,
        ];
    }

    public function page(?Page $page, bool $withAds = false): ?array
    {
        if (! $page) {
            return null;
        }

        $setup = $page->setup ?? [];
        $isUnclaimed = (bool) $page->is_unclaimed;
        $features = $isUnclaimed ? [
            'store' => false,
            'services' => false,
            'events' => false,
            'price_list' => false,
        ] : $this->pageFeatures($setup);
        $logoPath = $isUnclaimed ? null : $page->logo_path;
        $bannerPath = $isUnclaimed ? null : $page->banner_path;
        $contact = $this->pageContact($page, $setup);
        $addressDetails = $this->pageAddress($setup);
        $socials = $this->pageSocials($setup);
        $page->loadMissing(['prices', 'products', 'services', 'events']);
        $page->products->each(fn (PageProduct $product) => $product->setRelation('page', $page));

        $payload = [
            'id' => $page->id,
            'slug' => $page->public_slug,
            'public_path' => '/pages/'.$page->public_slug,
            'user_id' => $isUnclaimed ? null : $page->user_id,
            'type' => $page->type,
            'is_unclaimed' => $isUnclaimed,
            'name' => $page->name,
            'public_description' => $page->public_description,
            'contact_email' => $page->contact_email,
            'phone' => $page->phone,
            'address' => $page->address,
            'category_key' => $page->category_key,
            'website' => filled($setup['website'] ?? null) ? (string) $setup['website'] : null,
            'contact' => $contact,
            'address_details' => $addressDetails,
            'socials' => $socials,
            'opening_hours' => $this->normalizedOpeningHours($setup['opening_hours'] ?? []),
            'features' => $features,
            'palette_key' => $page->palette_key,
            'logo_url' => $isUnclaimed ? null : $page->logo_url,
            ...$this->publicImageMeta('logo', $logoPath, $page->name.' logo', '96px'),
            'logo_name' => $isUnclaimed ? null : $page->logo_original_name,
            'banner_url' => $isUnclaimed ? null : $page->banner_url,
            ...$this->publicImageMeta('banner', $bannerPath, $page->name, '(max-width: 700px) calc(100vw - 28px), 1180px'),
            'banner_name' => $isUnclaimed ? null : $page->banner_original_name,
            'rating_summary' => $isUnclaimed ? ['average' => 0, 'count' => 0] : $this->pageRatingSummary($page),
            'prices' => $isUnclaimed ? [] : $page->prices->map(fn (PagePrice $price) => $this->price($price))->values()->all(),
            'products' => $isUnclaimed ? [] : $page->products->map(fn (PageProduct $product) => $this->product($product))->values()->all(),
            'services' => $isUnclaimed ? [] : $page->services->map(fn (PageService $service) => $this->service($service))->values()->all(),
            'events' => $isUnclaimed ? [] : $page->events->map(fn (PageEvent $event) => $this->event($event))->values()->all(),
            'setup' => [...$setup, 'features' => $features],
            'source_url' => $isUnclaimed ? $page->source_url : null,
            'source_checked_at' => $isUnclaimed ? $page->source_checked_at?->format('Y-m-d') : null,
            'claimed_at' => $page->claimed_at?->toISOString(),
            'owner' => ! $isUnclaimed && $page->relationLoaded('user') && $page->user
                ? $this->user($page->user)
                : null,
            'created_at' => $page->created_at?->toISOString(),
            'updated_at' => $page->updated_at?->toISOString(),
        ];

        if ($withAds) {
            $ads = $isUnclaimed
                ? collect()
                : ($page->relationLoaded('ads')
                ? $page->ads->filter(fn (Ad $ad) => $ad->isVisible())
                : $page->ads()->with(['user.profile', 'page'])->active()->get());

            $payload['ads'] = $ads->map(fn (Ad $ad) => $this->ad($ad))->values()->all();
        }

        return $payload;
    }

    public function product(PageProduct $product): array
    {
        $product->loadMissing('page');
        if ($product->page && ! array_key_exists('ratings_count', $product->page->getAttributes())) {
            $product->page->loadCount('ratings')->loadAvg('ratings', 'rating');
        }
        $activeOffer = $product->hasActiveOffer();
        $currentPrice = $product->currentPrice();

        return [
            'id' => $product->id,
            'slug' => $product->public_slug,
            'public_path' => '/product/'.$product->public_slug,
            'page_id' => $product->page_id,
            'name' => $product->name,
            'brand' => $product->brand,
            'model' => $product->model,
            'description' => $product->description,
            'category_key' => $product->category_key,
            'image_url' => $product->image_url,
            ...$this->publicImageMeta('image', $product->image_path, $product->name, '(max-width: 700px) calc(100vw - 36px), 340px'),
            'image_name' => $product->image_original_name,
            'price' => $currentPrice,
            'price_label' => $this->moneyLabel($currentPrice),
            'normal_price' => (float) $product->price,
            'normal_price_label' => $this->moneyLabel((float) $product->price),
            'offer_enabled' => (bool) $product->offer_enabled,
            'offer_active' => $activeOffer,
            'offer_price' => $product->offer_price !== null ? (float) $product->offer_price : null,
            'offer_price_label' => $product->offer_price !== null ? $this->moneyLabel((float) $product->offer_price) : null,
            'offer_starts_at' => $product->offer_starts_at?->toISOString(),
            'offer_ends_at' => $product->offer_ends_at?->toISOString(),
            'previous_price' => $product->previous_price !== null ? (float) $product->previous_price : null,
            'views_count' => (int) $product->views_count,
            'contacts_count' => (int) $product->contacts_count,
            'labels' => $this->productLabels($product, $activeOffer),
            'link' => $product->link,
            'created_at' => $product->created_at?->toISOString(),
            'updated_at' => $product->updated_at?->toISOString(),
        ];
    }

    public function price(PagePrice $price): array
    {
        return [
            'id' => $price->id,
            'page_id' => $price->page_id,
            'name' => $price->name,
            'price' => (float) $price->price,
            'price_label' => $this->moneyLabel((float) $price->price),
            'created_at' => $price->created_at?->toISOString(),
            'updated_at' => $price->updated_at?->toISOString(),
        ];
    }

    public function event(PageEvent $event): array
    {
        return [
            'id' => $event->id,
            'page_id' => $event->page_id,
            'name' => $event->name,
            'description' => $event->description,
            'category_key' => $event->category_key,
            'image_url' => $event->image_url,
            ...$this->publicImageMeta('image', $event->image_path, $event->name, '(max-width: 700px) calc(100vw - 36px), 340px'),
            'image_name' => $event->image_original_name,
            'date' => $event->event_date?->format('Y-m-d'),
            'time' => $this->eventTime($event->event_time),
            'end_time' => $this->eventTime($event->event_end_time),
            'address' => $event->address,
            'created_at' => $event->created_at?->toISOString(),
            'updated_at' => $event->updated_at?->toISOString(),
        ];
    }

    public function service(PageService $service): array
    {
        return [
            'id' => $service->id,
            'page_id' => $service->page_id,
            'name' => $service->name,
            'description' => $service->description,
            'category_key' => $service->category_key,
            'image_url' => $service->image_url,
            ...$this->publicImageMeta('image', $service->image_path, $service->name, '(max-width: 760px) calc(100vw - 36px), 360px'),
            'image_name' => $service->image_original_name,
            'link' => $service->link,
            'created_at' => $service->created_at?->toISOString(),
            'updated_at' => $service->updated_at?->toISOString(),
        ];
    }

    public function pageRatingSummary(Page $page): array
    {
        $count = $page->ratings_count ?? $page->ratings()->count();
        $average = $page->ratings_avg_rating ?? $page->ratings()->avg('rating');

        return [
            'average' => $count > 0 ? round((float) $average, 1) : 0,
            'count' => (int) $count,
        ];
    }

    public function pageRating(PageRating $rating): array
    {
        $rating->loadMissing('user.profile');

        return [
            'id' => $rating->id,
            'page_id' => $rating->page_id,
            'user_id' => $rating->user_id,
            'rating' => $rating->rating,
            'comment' => $rating->comment,
            'user' => $this->user($rating->user),
            'created_at' => $rating->created_at?->toISOString(),
            'updated_at' => $rating->updated_at?->toISOString(),
        ];
    }

    public function ad(Ad $ad): array
    {
        $ad->loadMissing(['user.profile', 'page']);

        return [
            'id' => $ad->id,
            'slug' => $ad->public_slug,
            'public_path' => '/ads/'.$ad->public_slug,
            'user_id' => $ad->user_id,
            'page_id' => $ad->page_id,
            'type' => $ad->type,
            'title' => $ad->title,
            'text' => $ad->text,
            'category' => $ad->category,
            'image_url' => $ad->image_url,
            ...$this->publicImageMeta('image', $ad->image_path, $ad->title, '(max-width: 700px) calc(100vw - 36px), 360px'),
            'image_name' => $ad->image_original_name,
            'status' => $ad->status,
            'city' => $this->adLocationValue($ad, 'city'),
            'neighborhood' => $this->adLocationValue($ad, 'neighborhood'),
            'user' => $this->user($ad->user),
            'page' => $this->page($ad->page),
            'expires_at' => $ad->expires_at?->toISOString(),
            'created_at' => $ad->created_at?->toISOString(),
            'updated_at' => $ad->updated_at?->toISOString(),
        ];
    }

    public function conversation(Conversation $conversation, User $viewer, array $composerState, bool $withMessages = false): array
    {
        $conversation->loadMissing(['userOne.profile', 'userTwo.profile', 'messages.sender.profile']);
        $other = $conversation->otherParticipant($viewer);
        $latest = $conversation->messages
            ->sortBy(fn ($message): string => sprintf('%020s%020d', $message->created_at?->format('Uu') ?? '0', $message->id))
            ->last();

        $payload = [
            'id' => $conversation->id,
            'other_user' => $other ? $this->user($other) : null,
            'is_support' => (bool) $conversation->is_support,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'latest_message' => $latest ? $this->message($latest) : null,
            'unread_count' => $conversation->messages
                ->where('sender_id', '!=', $viewer->id)
                ->whereNull('read_at')
                ->count(),
            'composer_state' => $composerState,
        ];

        if ($withMessages) {
            $payload['messages'] = $conversation->messages
                ->sortBy('created_at')
                ->map(fn ($message) => $this->message($message))
                ->values()
                ->all();
        }

        return $payload;
    }

    public function message($message): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_id' => $message->sender_id,
            'body' => $message->body,
            'read_at' => $message->read_at?->toISOString(),
            'created_at' => $message->created_at?->toISOString(),
            'sender' => $message->relationLoaded('sender') ? $this->user($message->sender) : null,
        ];
    }

    public function pageConversation(PageConversation $conversation, User $viewer, array $composerState, bool $withMessages = false): array
    {
        $conversation->loadMissing(['page', 'visitor.profile', 'messages.sender.profile']);
        $viewerIsOwner = $conversation->page->user_id === $viewer->id;
        $latest = $conversation->messages
            ->sortBy(fn ($message): string => sprintf('%020s%020d', $message->created_at?->format('Uu') ?? '0', $message->id))
            ->last();

        $payload = [
            'id' => $conversation->id,
            'page' => $this->pageChatIdentity($conversation->page),
            'other_user' => $viewerIsOwner
                ? $this->user($conversation->visitor)
                : $this->pageChatIdentity($conversation->page, asChatUser: true),
            'is_page_chat' => true,
            'last_message_at' => $conversation->last_message_at?->toISOString(),
            'latest_message' => $latest ? $this->pageChatMessage($latest, $conversation->page) : null,
            'unread_count' => $conversation->messages
                ->where('sender_id', '!=', $viewer->id)
                ->whereNull('read_at')
                ->count(),
            'composer_state' => $composerState,
        ];

        if ($withMessages) {
            $payload['messages'] = $conversation->messages
                ->sortBy('created_at')
                ->map(fn (PageChatMessage $message) => $this->pageChatMessage($message, $conversation->page))
                ->values()
                ->all();
        }

        return $payload;
    }

    public function pageChatMessage(PageChatMessage $message, Page $page): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->page_conversation_id,
            'sender_id' => $message->sender_id,
            'sender_as_page' => (bool) $message->sender_as_page,
            'body' => $message->body,
            'read_at' => $message->read_at?->toISOString(),
            'created_at' => $message->created_at?->toISOString(),
            'sender' => $message->sender_as_page
                ? $this->pageChatIdentity($page, asChatUser: true)
                : ($message->relationLoaded('sender') ? $this->user($message->sender) : null),
        ];
    }

    private function pageChatIdentity(Page $page, bool $asChatUser = false): array
    {
        $identity = [
            'id' => $asChatUser ? 'page-'.$page->id : $page->id,
            'page_id' => $page->id,
            'display_name' => $page->name,
            'name' => $page->name,
            'type' => $page->type,
            'public_path' => '/pages/'.$page->public_slug,
            'logo_url' => $page->logo_url,
            ...$this->publicImageMeta('logo', $page->logo_path, $page->name.' logo', '40px'),
        ];

        if ($asChatUser) {
            $identity['is_page'] = true;
            $identity['profile'] = [
                'photo_url' => $identity['logo_url'],
                'photo_alt' => $identity['logo_alt'],
                'photo_width' => $identity['logo_width'],
                'photo_height' => $identity['logo_height'],
                'photo_sizes' => $identity['logo_sizes'],
                'photo_webp_srcset' => $identity['logo_webp_srcset'],
                'photo_avif_srcset' => $identity['logo_avif_srcset'],
            ];
        }

        return $identity;
    }

    public function unreadMessageCount(User $user): int
    {
        $privateUnread = $user->receivedUnreadMessages()->count();
        $pageUnread = PageChatMessage::query()
            ->whereHas('conversation', fn ($query) => $query
                ->where('visitor_id', $user->id)
                ->whereHas('page.user', fn ($ownerQuery) => $ownerQuery->whereNull('banned_at')))
            ->where('sender_as_page', true)
            ->whereNull('read_at')
            ->count();

        return $privateUnread + $pageUnread;
    }

    private function firstPageOfType(User $user, string $type): ?array
    {
        $page = $user->pages->firstWhere('type', $type);

        return $page ? $this->page($page) : null;
    }

    private function profileComplete(User $user): bool
    {
        return $this->missingProfileFields($user) === [];
    }

    private function missingProfileFields(User $user): array
    {
        $profile = $user->profile;
        $fields = [
            'email' => $user->email,
            'given_name' => $user->given_name,
            'family_name' => $user->family_name,
            'city' => $profile?->city,
        ];

        return collect($fields)
            ->filter(fn ($value) => ! filled($value))
            ->keys()
            ->values()
            ->all();
    }

    private function pageContact(Page $page, array $setup): array
    {
        $contact = is_array($setup['contact'] ?? null) ? $setup['contact'] : [];

        return [
            'tel' => $contact['tel'] ?? $page->phone,
            'email' => $contact['email'] ?? $page->contact_email,
            'whatsapp' => $contact['whatsapp'] ?? null,
        ];
    }

    private function pageAddress(array $setup): array
    {
        $address = is_array($setup['address'] ?? null) ? $setup['address'] : [];

        return [
            'street' => $address['street'] ?? null,
            'number' => $address['number'] ?? null,
            'city' => $address['city'] ?? null,
            'neighborhood' => $address['neighborhood'] ?? null,
        ];
    }

    private function pageSocials(array $setup): array
    {
        $socials = is_array($setup['socials'] ?? null) ? $setup['socials'] : [];

        return [
            'facebook' => $socials['facebook'] ?? null,
            'instagram' => $socials['instagram'] ?? null,
            'tiktok' => $socials['tiktok'] ?? null,
            'telegram' => $socials['telegram'] ?? null,
        ];
    }

    private function pageFeatures(array $setup): array
    {
        $features = is_array($setup['features'] ?? null) ? $setup['features'] : [];

        return [
            'store' => $this->booleanValue($features['store'] ?? null, false),
            'services' => $this->booleanValue($features['services'] ?? null, false),
            'events' => $this->booleanValue($features['events'] ?? null, false),
            'price_list' => $this->booleanValue($features['price_list'] ?? null, false),
        ];
    }

    private function productLabels(PageProduct $product, bool $activeOffer): array
    {
        $labels = [];
        $ratingAverage = (float) ($product->page?->getAttribute('ratings_avg_rating') ?? 0);
        $ratingCount = (int) ($product->page?->getAttribute('ratings_count') ?? 0);

        $newDays = $this->settings->integer('labels.new_days', 3);
        $popularViews = $this->settings->integer('labels.popular_views', 100);
        $popularContacts = $this->settings->integer('labels.popular_contacts', 10);
        $ratingAverageMinimum = (float) $this->settings->get('labels.highly_rated_average', 4.7);
        $ratingCountMinimum = $this->settings->integer('labels.highly_rated_min_ratings', 3);

        if ($product->created_at?->greaterThanOrEqualTo(now()->subDays($newDays))) {
            $labels[] = 'new';
        }

        if ($product->previous_price !== null && (float) $product->previous_price > (float) $product->price) {
            $labels[] = 'price_dropped';
        }

        if ((int) $product->views_count >= $popularViews || (int) $product->contacts_count >= $popularContacts) {
            $labels[] = 'popular';
        }

        if ($ratingCount >= $ratingCountMinimum && $ratingAverage >= $ratingAverageMinimum) {
            $labels[] = 'highly_rated';
        }

        if ($activeOffer) {
            $labels[] = 'offer';
        }

        return $labels;
    }

    private function moneyLabel(float $price): string
    {
        return "\u{20AA}".number_format($price, 2);
    }

    private function booleanValue(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private function adLocationValue(Ad $ad, string $field): ?string
    {
        if (filled($ad->{$field})) {
            return $ad->{$field};
        }

        if ($ad->page_id && $ad->page) {
            $address = $this->pageAddress($ad->page->setup ?? []);

            return $address[$field] ?? null;
        }

        return $ad->user?->profile?->{$field};
    }

    public function publicImageMeta(string $prefix, ?string $path, ?string $alt, string $sizes): array
    {
        [$width, $height] = PublicImageVariants::dimensions($path);

        return [
            $prefix.'_alt' => $alt,
            $prefix.'_width' => $width,
            $prefix.'_height' => $height,
            $prefix.'_sizes' => $sizes,
            $prefix.'_webp_srcset' => PublicImageVariants::webpSrcset($path),
            $prefix.'_avif_srcset' => '',
        ];
    }

    private function eventTime(?string $time): ?string
    {
        if (! $time) {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})/', $time, $matches)) {
            return str_pad($matches[1], 2, '0', STR_PAD_LEFT).':'.$matches[2];
        }

        return $time;
    }

    private function normalizedOpeningHours(mixed $openingHours): array
    {
        $items = collect(is_array($openingHours) ? $openingHours : [])
            ->filter(fn ($item) => is_array($item) && filled($item['weekday'] ?? null))
            ->keyBy('weekday');

        return collect(self::DEFAULT_OPENING_HOURS)
            ->map(function (array $default) use ($items): array {
                $item = $items->get($default['weekday'], []);
                $isOpen = (bool) ($item['is_open'] ?? $default['is_open']);

                return [
                    'weekday' => $default['weekday'],
                    'is_open' => $isOpen,
                    'opens_at' => $isOpen ? ($item['opens_at'] ?? $default['opens_at']) : null,
                    'closes_at' => $isOpen ? ($item['closes_at'] ?? $default['closes_at']) : null,
                ];
            })
            ->values()
            ->all();
    }
}
