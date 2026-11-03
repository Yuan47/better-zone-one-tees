<?php

namespace App\Services;

class DannonProductImages
{
    private const STYLES = [
        'dannon-round-neck-v-neck' => 'round-neck',
        'dannon-round-neck-v-neck-kids' => 'round-neck-kids',
        'dannon-v-neck' => 'v-neck',
        'dannon-v-neck-kids' => 'v-neck-kids',
    ];

    // Only the original combined sample illustrations are replaced in presentation.
    // Owner uploads and the stored database paths remain untouched.
    private const LEGACY_IMAGES = [
        'democolor20261029_p11_white_afa82c13.png' => 'white',
        'democolor20261029_p11_red_78ea1699.png' => 'red',
        'democolor20261029_p11_mandarin_8c774f2f.png' => 'mandarin',
        'democolor20261029_p11_maroon_c58dac9e.png' => 'maroon',
        'democolor20261029_p11_batis_3a226be8.png' => 'batis',
        'democolor20261029_p11_teal_de3fa6ca.png' => 'teal',
        'democolor20261029_p11_light_blue_079711c2.png' => 'light-blue',
        'democolor20261029_p11_fatigue_e15cdbb5.png' => 'fatigue',
        'democolor20261029_p11_gray_da7decd8.png' => 'gray',
        'democolor20261029_p11_pool_green_02973c67.png' => 'pool-green',
        'democolor20261029_p11_gold_a6f4a812.png' => 'gold',
        'democolor20261029_p11_yellow_6ed532fa.png' => 'yellow',
        'democolor20261029_p11_peach_48b7eca4.png' => 'peach',
        'democolor20261029_p11_light_yellow_7bf6e27d.png' => 'light-yellow',
        'democolor20261029_p15_white_08c9a8a9.png' => 'white',
        'democolor20261029_p15_red_1c76d4a9.png' => 'red',
        'democolor20261029_p15_mandarin_27d569bb.png' => 'mandarin',
        'democolor20261029_p15_maroon_e296496a.png' => 'maroon',
        'democolor20261029_p15_batis_25aac6b3.png' => 'batis',
        'democolor20261029_p15_teal_a924a4ae.png' => 'teal',
        'democolor20261029_p15_light_blue_4c01c762.png' => 'light-blue',
        'democolor20261029_p15_fatigue_63f458d5.png' => 'fatigue',
        'democolor20261029_p15_gray_6cac20a9.png' => 'gray',
        'democolor20261029_p15_pool_green_2fca4b55.png' => 'pool-green',
        'democolor20261029_p15_gold_f7fcff57.png' => 'gold',
        'democolor20261029_p15_yellow_854c4a6d.png' => 'yellow',
        'democolor20261029_p15_peach_2b54c062.png' => 'peach',
        'democolor20261029_p15_light_yellow_2f86b4d7.png' => 'light-yellow',
    ];

    public static function isLegacyPreview(?string $imagePath): bool
    {
        return $imagePath !== null && str_starts_with($imagePath, 'uploads/variants/')
            && isset(self::LEGACY_IMAGES[substr($imagePath, strlen('uploads/variants/'))]);
    }

    public static function url(?string $productSlug, ?string $imagePath): ?string
    {
        if (! isset(self::STYLES[$productSlug]) || ! self::isLegacyPreview($imagePath)) {
            return null;
        }

        $color = self::LEGACY_IMAGES[substr($imagePath, strlen('uploads/variants/'))];

        return asset('catalog-previews/dannon/'.self::STYLES[$productSlug].'/'.$color.'.svg');
    }
}
