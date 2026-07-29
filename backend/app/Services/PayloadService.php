<?php

namespace App\Services;

use App\Models\Ad;
use App\Models\Conversation;
use App\Models\Page;
use App\Models\PageRating;
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
            'phone' => $profile?->phone,
            'city' => $profile?->city,
            'neighborhood' => $profile?->neighborhood,
            'languages' => array_values(array_filter($profile?->languages ?? [])),
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
            'palette_key' => $page->palette_key,
            'logo_url' => $page->logo_url,
            'banner_url' => $page->banner_url,
            'rating_summary' => $this->pageRatingSummary($page),
            'setup' => $setup,
            'owner' => $page->relationLoaded('user') ? $this->user($page->user) : null,
            'created_at' => $page->created_at?->toISOString(),
            'updated_at' => $page->updated_at?->toISOString(),
        ];

        if ($withAds) {
            $page->loadMissing(['ads.user.profile', 'ads.page']);
            $payload['ads'] = $page->ads->map(fn (Ad $ad) => $this->ad($ad))->values()->all();
        }

        return $payload;
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
            'image_url' => $ad->image_url,
            'status' => $ad->status,
            'city' => $ad->city ?: $ad->user?->profile?->city,
            'neighborhood' => $ad->neighborhood ?: $ad->user?->profile?->neighborhood,
            'user' => $this->user($ad->user),
            'page' => $this->page($ad->page),
            'created_at' => $ad->created_at?->toISOString(),
            'updated_at' => $ad->updated_at?->toISOString(),
        ];
    }

    public function conversation(Conversation $conversation, User $viewer, array $composerState, bool $withMessages = false): array
    {
        $conversation->loadMissing(['userOne.profile', 'userTwo.profile', 'messages.sender.profile']);
        $other = $conversation->otherParticipant($viewer);
        $latest = $conversation->messages->sortByDesc('created_at')->first();

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
        ];
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
