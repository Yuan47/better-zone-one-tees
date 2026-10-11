<?php

namespace App\Services;

use App\Models\ChatbotFaq;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StoreKnowledge
{
    public const INFO_FIELDS = [
        'opening_hours' => 'Opening hours',
        'pickup_address' => 'Store / pickup address',
        'pickup_instructions' => 'Pickup instructions',
        'support_email' => 'Support email',
        'support_phone' => 'Support phone',
        'store_note' => 'Additional approved store information',
    ];

    public const QUANTITY_PRICING = 'Total pieces across the cart: 1–5 Retail/SRP, 6–50 Wholesale, 51–100 Re-seller, 101+ Bulk with 5% off the Re-seller clothing subtotal. Delivery is separate.';

    public const PAYMENT_HELP = 'PayMongo hosted checkout is used when payments are enabled. An order is confirmed as paid only after a verified PayMongo payment notification. Returning from checkout or sending a payment screenshot does not confirm payment.';

    public const SIZE_HELP = 'Open a product to see its Size / Width (in.) / Length (in.) table. Sample reference charts are labeled; blank measurements need store confirmation. Reference-only sizes are not necessarily available to order.';

    public const TRACK_HELP = 'Sign in and open My orders to see your order status, or share your full order number (ZOT-…). Customers can only access their own orders.';

    public function info(): array
    {
        $raw = DB::table('shop_settings')->where('key', 'store_info')->value('value');
        $saved = json_decode($raw ?? '{}', true);
        $saved = is_array($saved) ? $saved : [];
        if ($raw === null) {
            $saved['store_note'] = DB::table('shop_settings')->where('key', 'chatbot_faq')->value('value') ?? '';
        }

        $info = [];
        foreach (self::INFO_FIELDS as $field => $label) {
            $info[$field] = is_string($saved[$field] ?? null) ? $saved[$field] : '';
        }

        return $info;
    }

    public function saveInfo(array $data): array
    {
        $previous = $this->info();
        $next = [];
        foreach (self::INFO_FIELDS as $field => $label) {
            $next[$field] = trim((string) ($data[$field] ?? ''));
        }
        $changed = array_keys(array_filter($next, fn ($value, $field) => $value !== $previous[$field], ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            DB::table('shop_settings')->updateOrInsert(['key' => 'store_info'], ['value' => json_encode($next, JSON_THROW_ON_ERROR)]);
        }

        return $changed;
    }

    public function approvedFaqs(): Collection
    {
        return ChatbotFaq::where('active', true)->orderBy('sort_order')->orderBy('id')->get();
    }

    public function match(string $message, Collection $faqs): ?ChatbotFaq
    {
        $normalized = $this->normalize($message);
        if ($normalized === '') {
            return null;
        }
        $best = null;
        $bestScore = 0;
        foreach ($faqs as $faq) {
            $score = $this->score($normalized, $faq);
            if ($score > $bestScore) {
                $best = $faq;
                $bestScore = $score;
            }
        }

        return $best;
    }

    public function relevantFaqs(string $message, Collection $faqs, int $limit = 10): Collection
    {
        $normalized = $this->normalize($message);

        return $faqs->sortByDesc(fn ($faq) => $this->score($normalized, $faq))->take($limit)->values();
    }

    private function score(string $normalized, ChatbotFaq $faq): int
    {
        $question = $this->normalize($faq->question);
        if ($normalized !== '' && $normalized === $question) {
            return 10000;
        }
        $score = 0;
        foreach (explode(',', $faq->keywords ?? '') as $keyword) {
            $keyword = $this->normalize($keyword);
            if ($keyword !== '' && str_contains(' '.$normalized.' ', ' '.$keyword.' ')) {
                $score += 3;
            }
        }
        $questionWords = $this->words($question);
        $overlap = count(array_intersect($this->words($normalized), $questionWords));
        if ($overlap >= 2 || ($overlap === 1 && count($questionWords) === 1)) {
            $score += $overlap * 2;
        }

        return $score;
    }

    public function basicStoreInfo(string $message, array $info): ?string
    {
        $fields = match (true) {
            (bool) preg_match('/\bhours?\b|\bopen\b|\bclosed?\b|\bschedule\b/i', $message) => ['opening_hours'],
            (bool) preg_match('/\bpickup\b|pick up|\baddress\b|\blocation\b|where.*store/i', $message) => ['pickup_address', 'pickup_instructions'],
            (bool) preg_match('/\bcontact\b|\bsupport\b|\bemail\b|\bphone\b/i', $message) => ['support_email', 'support_phone'],
            default => [],
        };
        $answers = [];
        foreach ($fields as $field) {
            if (filled($info[$field] ?? '')) {
                $answers[] = self::INFO_FIELDS[$field].': '.$info[$field];
            }
        }

        return $answers === [] ? null : implode("\n", $answers);
    }

    private function normalize(string $text): string
    {
        return trim(preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($text)) ?? '');
    }

    private function words(string $text): array
    {
        $stopWords = ['a', 'an', 'the', 'do', 'does', 'i', 'we', 'you', 'my', 'your', 'how', 'can', 'which', 'what', 'where', 'when', 'are', 'is', 'at', 'of', 'and', 'or', 'to', 'with', 'our', 'in', 'for', 'it'];

        return array_values(array_unique(array_diff(explode(' ', $text), $stopWords, [''])));
    }
}
