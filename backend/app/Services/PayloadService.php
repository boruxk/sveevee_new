<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\Conversation;
use App\Models\Page;
use App\Models\PageEvent;
use App\Models\PageProduct;
use App\Models\PageRating;
use App\Models\PageService;
use App\Models\User;
use App\Models\UserProfile;

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

    public function user(User $user, bool $includePrivate = false): array
    {
        $user->loadMissing(['profile', 'pages']);

        $payload = [
            'id' => $user->id,
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
        $contact = $this->pageContact($page, $setup);
        $addressDetails = $this->pageAddress($setup);
        $page->loadMissing(['products', 'services', 'events']);

        $payload = [
            'id' => $page->id,
            'user_id' => $page->user_id,
            'type' => $page->type,
            'name' => $page->name,
            'public_description' => $page->public_description,
            'contact_email' => $page->contact_email,
            'phone' => $page->phone,
            'address' => $page->address,
            'contact' => $contact,
            'address_details' => $addressDetails,
            'opening_hours' => $this->normalizedOpeningHours($setup['opening_hours'] ?? []),
            'features' => $this->pageFeatures($setup),
            'palette_key' => $page->palette_key,
            'logo_url' => $page->logo_url,
            'logo_name' => $page->logo_original_name,
            'banner_url' => $page->banner_url,
            'banner_name' => $page->banner_original_name,
            'rating_summary' => $this->pageRatingSummary($page),
            'products' => $page->products->map(fn (PageProduct $product) => $this->product($product))->values()->all(),
            'services' => $page->services->map(fn (PageService $service) => $this->service($service))->values()->all(),
            'events' => $page->events->map(fn (PageEvent $event) => $this->event($event))->values()->all(),
            'setup' => $setup,
            'owner' => $page->relationLoaded('user') ? $this->user($page->user) : null,
            'created_at' => $page->created_at?->toISOString(),
            'updated_at' => $page->updated_at?->toISOString(),
        ];

        if ($withAds) {
            $ads = $page->relationLoaded('ads')
                ? $page->ads->filter(fn (Ad $ad) => $ad->isVisible())
                : $page->ads()->with(['user.profile', 'page'])->active()->get();

            $payload['ads'] = $ads->map(fn (Ad $ad) => $this->ad($ad))->values()->all();
        }

        return $payload;
    }

    public function product(PageProduct $product): array
    {
        return [
            'id' => $product->id,
            'page_id' => $product->page_id,
            'name' => $product->name,
            'description' => $product->description,
            'image_url' => $product->image_url,
            'image_name' => $product->image_original_name,
            'price' => (float) $product->price,
            'price_label' => '₪'.number_format((float) $product->price, 2),
            'link' => $product->link,
            'created_at' => $product->created_at?->toISOString(),
            'updated_at' => $product->updated_at?->toISOString(),
        ];
    }

    public function event(PageEvent $event): array
    {
        return [
            'id' => $event->id,
            'page_id' => $event->page_id,
            'name' => $event->name,
            'description' => $event->description,
            'image_url' => $event->image_url,
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
            'image_url' => $service->image_url,
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
            'user_id' => $ad->user_id,
            'page_id' => $ad->page_id,
            'type' => $ad->type,
            'title' => $ad->title,
            'text' => $ad->text,
            'category' => $ad->category,
            'image_url' => $ad->image_url,
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

    public function unreadMessageCount(User $user): int
    {
        return $user->receivedUnreadMessages()->count();
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

    private function pageFeatures(array $setup): array
    {
        $features = is_array($setup['features'] ?? null) ? $setup['features'] : [];

        return [
            'store' => $this->booleanValue($features['store'] ?? null, false),
            'services' => $this->booleanValue($features['services'] ?? null, false),
            'events' => $this->booleanValue($features['events'] ?? null, false),
        ];
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
