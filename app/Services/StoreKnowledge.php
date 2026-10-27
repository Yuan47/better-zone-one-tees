<?php

namespace App\Services;

use App\Models\ChatbotFaq;
use App\Models\Product;
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

    public const PAYMENT_HELP = 'Online payments use PayMongo hosted checkout and require a verified PayMongo payment notification or provider reconciliation. Returning from checkout or a payment screenshot does not confirm payment. Partial payments are available only above ₱1,000 in discounted clothing, with a 7–30 day original deadline. Every payment reduces the same invoice balance without moving its deadline; packing waits for full payment. Walk-in cash is recorded as received by staff. Fully paid walk-in sales are Delivered automatically; online orders still require the customer receipt and review.';

    public const SIZE_HELP = 'Open a product to see its Size / Width (in.) / Length (in.) table. Sample reference charts are labeled; blank measurements need store confirmation. Reference-only sizes are not necessarily available to order.';

    public const TRACK_HELP = 'Sign in and open My orders to see your order status, or share your full order number (ZOT-…). Customers can only access their own orders.';

    public const CUSTOMER_PRIVACY_HELP = 'I can help with customer accounts, shopping, your own orders, payments, and returns. Internal store reports, staff or owner instructions, other people’s information, passwords, and secret keys are not available in customer chat. Never share a password, verification code, or recovery link here.';

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

    public function approvedFaqs(array $audiences = ['customer']): Collection
    {
        return ChatbotFaq::where('active', true)->whereIn('audience', array_intersect($audiences, ['customer', 'staff', 'owner']))
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    public function requiresInternalAccess(string $message): bool
    {
        // Audience filtering is the privacy boundary. This only keeps explicit
        // internal-data requests out of the customer AI flow as well.
        return (bool) preg_match('/\b(?:admin(?:istrator)?|staff|owner|internal)\b.{0,45}\b(?:dashboard|workspace|reports?|settings?|accounts?|context|faqs?|instructions?|guides?|data|information|passwords?|credentials?)\b'
            .'|\b(?:sales\s+reports?|sales\s+forecasts?|revenue\s+reports?|activity\s+logs?|customer\s+list|all\s+customer\s+emails|staff\s+accounts?)\b'
            .'|\b(?:reveal|show|print|dump|give|list)\b.{0,50}\b(?:api\s+keys?|secret\s+keys?|system\s+prompt|private\s+context|passwords?|other\s+customers?)\b/iu', $message);
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

    public function isExactFaq(string $message, ChatbotFaq $faq): bool
    {
        return $this->normalize($message) === $this->normalize($faq->question);
    }

    public function requiresCatalogAnswer(string $message, Collection $productMatches): bool
    {
        $choosing = preg_match('/\b(?:recommend(?:ations?)?|suggest(?:ions?)?|choose|choosing|pick|picking|looking\s+for|best|better|suitable|ideal|good)\b/iu', $message);
        $clothing = preg_match('/\b(?:shirts?|t[ -]?shirts?|tees?|polos?|clothes|clothing|garments?|uniforms?|outfits?|comfortable|comfy)\b/iu', $message);
        $describing = preg_match('/\btell\s+me\s+about\b|\b(?:describe|description|details?|information)\b/iu', $message);
        $attributes = preg_match('/\b(?:fabrics?|materials?|cotton|polyester|blends?|soft|softness|breathable|breathability|comfortable|comfort|comfy|feel|feels|texture|thickness|durable|durability)\b/iu', $message);

        return (bool) $attributes || (($choosing || $describing) && ($clothing || $productMatches->isNotEmpty()))
            || (bool) preg_match('/\bwhat\s+should\s+i\s+wear\b/iu', $message);
    }

    public function recommendationAnswer(Collection $catalog): string
    {
        if ($catalog->isEmpty()) {
            return 'There are no active products in the catalog right now. Store staff can help with availability.';
        }
        $options = $catalog->map(function ($product) {
            $variant = collect($product['variants'])->first(fn ($variant) => $variant['available_pieces'] > 0);

            return $variant ? $product['name'].' — example listed option: '.$variant['size'].' / '.$variant['color']
                .' ('.$variant['available_pieces'].' pieces currently available).' : null;
        })->filter()->values();
        if ($options->isEmpty()) {
            return 'These catalog choices have no currently available matching size/color: '.$catalog->pluck('name')->join(', ')
                .'. Choose another listed size and color or ask store staff.';
        }

        return "Catalog options to compare:\n".$options->map(fn ($option, $index) => ($index + 1).'. '.$option)->join("\n")
            ."\nOpen each product’s size chart and choose its listed size and color. For fabric and comfort advice, ask store staff. Availability is checked again at checkout.";
    }

    public function matchingProducts(string $message, Collection $products): Collection
    {
        $message = $this->normalize($message);
        $matches = [];
        foreach ($products as $product) {
            foreach ($this->productAliases($product) as $alias) {
                if (preg_match_all('/(?<![\pL\pN])'.preg_quote($alias, '/').'(?![\pL\pN])/u', $message, $found, PREG_OFFSET_CAPTURE)) {
                    foreach ($found[0] as [$text, $offset]) {
                        $matches[] = ['product' => $product, 'start' => $offset, 'end' => $offset + strlen($text)];
                    }
                }
            }
        }

        // A kids style must not also resolve to the adult name contained inside it.
        return collect($matches)->reject(function ($candidate) use ($matches) {
            foreach ($matches as $other) {
                if ($candidate['product']->id !== $other['product']->id && $other['start'] <= $candidate['start'] && $other['end'] >= $candidate['end']
                    && ($other['start'] < $candidate['start'] || $other['end'] > $candidate['end'])) {
                    return true;
                }
            }

            return false;
        })->pluck('product')->unique('id')->values();
    }

    public function variantAnswer(string $message, Collection $matches): string
    {
        if ($matches->count() !== 1) {
            return 'Please specify one product name, one size, and one color so I can check its exact price and stock. Use the name shown on the product page.';
        }
        $product = $matches->first();
        [$sizes, $colors] = $this->requestedOptions($message, $product);
        if (count($sizes) !== 1 || count($colors) !== 1 || preg_match('/\bor\b/i', $message)) {
            return $product->name.': please specify one size and one color from its product page. Prices and availability depend on that exact combination. '.self::QUANTITY_PRICING;
        }
        $variant = $product->variants->first(fn ($variant) => strcasecmp($variant->size, $sizes[0]) === 0 && strcasecmp($variant->color, $colors[0]) === 0);
        $label = $product->name.' · '.$sizes[0].' / '.$colors[0];
        if (! $variant) {
            return $label.' is not an offered variant. Choose a size and color listed on its product page. Size chart references do not establish availability.';
        }
        $available = $variant->available();
        $reply = $label.': '.($available ? $available.' pieces currently available.' : 'currently out of stock (0 pieces available).')
            .' Retail / SRP (1–5): '.$this->money($variant->retail_price)
            .'; Wholesale (6–50): '.$this->money($variant->wholesale_price)
            .'; Re-seller (51–100): '.$this->money($variant->reseller_price).' per piece. '
            .'101+ pieces receive 5% off the Re-seller clothing subtotal, rounded once on the entire subtotal. Delivery is separate. Stock is checked again at checkout.';
        if (preg_match_all('/\b([1-9][0-9]{0,4})\s*(?:pcs?\.?|pieces?|units?|shirts?|tees?)\b/i', $message, $quantities) && count(array_unique($quantities[1])) === 1) {
            $quantity = (int) $quantities[1][0];
            if ($quantity <= 10000) {
                $summary = app(Pricing::class)->cart([$variant->id => $quantity]);
                $reply .= ' Pricing reference for '.$quantity.' pieces of this variant: '.$this->money($summary['subtotal']).' clothing subtotal ('.$summary['label'].'). This does not reserve stock or confirm an order.';
            }
        }

        return $reply;
    }

    public function catalogContext(string $message, Collection $products, Collection $matches): Collection
    {
        $selected = $matches->isNotEmpty() ? $matches : $products->sortByDesc(fn ($product) => count(array_intersect($this->words($this->normalize($message)), $this->words($this->normalize($product->name)))));

        return $selected->take(3)->map(function ($product) use ($message) {
            [$sizes, $colors] = $this->requestedOptions($message, $product);
            $variants = $product->variants->filter(fn ($variant) => ($sizes === [] || in_array($variant->size, $sizes, true)) && ($colors === [] || in_array($variant->color, $colors, true)));

            return [
                'name' => $product->name, 'size_chart' => $product->size_chart, 'size_chart_note' => $product->size_chart_note,
                'currency' => 'PHP', 'price_unit' => 'integer centavos per piece',
                'variant_scope' => ['sizes' => $sizes, 'colors' => $colors],
                'matching_variant_count' => $variants->count(), 'variants_complete' => $variants->count() <= 40,
                'variants' => $variants->sortByDesc(fn ($variant) => $variant->available())->take(40)->map(fn ($variant) => [
                    'size' => $variant->size, 'color' => $variant->color, 'color_group' => $variant->color_group, 'available_pieces' => $variant->available(),
                    'retail_price' => $variant->retail_price, 'wholesale_price' => $variant->wholesale_price, 'reseller_price' => $variant->reseller_price,
                ])->values(),
            ];
        })->values();
    }

    private function productAliases(Product $product): array
    {
        $aliases = [$product->name, $product->slug];
        if (preg_match('/^(.+?)\s+-\s+(.+?)\s*\/\s*(.+)$/u', $product->name, $parts)) {
            $suffix = preg_match('/\s+KIDS$/i', $parts[3]) ? ' KIDS' : '';
            $aliases[] = $parts[1].' '.$parts[2].$suffix;
            $aliases[] = $parts[1].' '.$parts[3];
        }
        if ($product->isPolo()) {
            $aliases[] = str_ireplace('WITH COLLAR', 'POLO', $product->name);
            $aliases[] = str_ireplace('WITH COLLAR', 'POLO SHIRT', $product->name);
        }
        foreach ($aliases as $alias) {
            $aliases[] = preg_replace('/\bwith\b/i', '', $alias);
        }

        return array_values(array_unique(array_filter(array_map(fn ($alias) => $this->normalize($alias), $aliases))));
    }

    private function requestedOptions(string $message, Product $product): array
    {
        $knownSizes = $product->variants->pluck('size')->merge(collect($product->size_chart)->pluck('size'))
            ->merge(['XS', 'S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL'])->unique()->all();
        $sizes = [];
        foreach ($knownSizes as $size) {
            $size = (string) $size;
            // Single-letter sizes inside contractions and quantities such as "12 pieces" are not selections.
            if (preg_match('/(?<![\pL\pN\x{2019}\x{0027}])'.preg_quote($size, '/').'(?![\pL\pN\x{2019}\x{0027}])'.(ctype_digit($size) ? '(?!\s*(?:pcs?\.?|pieces?|units?|shirts?|tees?)\b)' : '').'/iu', $message)) {
                $sizes[] = $size;
            }
        }
        $colors = [];
        $remaining = ' '.$this->normalize($message).' ';
        foreach ($product->variants->pluck('color')->push('White')->unique()->sortByDesc(fn ($color) => strlen($color)) as $color) {
            $phrase = ' '.$this->normalize($color).' ';
            if (str_contains($remaining, $phrase)) {
                $colors[] = $color;
                $remaining = str_replace($phrase, ' ', $remaining);
            }
        }

        return [$sizes, $colors];
    }

    private function money(int $centavos): string
    {
        return '₱'.number_format($centavos / 100, 2);
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
                // A configured phrase is more specific than generic words shared by many FAQs.
                $score += 3 * count(explode(' ', $keyword));
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
