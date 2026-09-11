<?php

namespace Coins\Admin\ACFFieldsManager;

class CoinCollectionACFFieldsManager
{
    public function boot(): void
    {
        add_action('acf/init', [$this, 'register']);
    }

    public function register(): void
    {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        acf_add_local_field_group([
            'key'   => 'group_coin_collection_fields',
            'title' => 'Collection Item',
            'fields' => $this->getFields(),
            'location' => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => 'coin_collection',
                    ],
                ],
            ],
            'menu_order'            => 0,
            'position'              => 'acf_after_title',
            'style'                 => 'default',
            'label_placement'       => 'top',
            'instruction_placement' => 'label',
        ]);
    }

    private function getFields(): array
    {
        return [
            [
                'key'           => 'field_cc_user_id',
                'label'         => 'User',
                'name'          => 'user_id',
                'type'          => 'user',
                'instructions'  => 'Власник запису.',
                'required'      => 1,
                'role'          => '',
                'allow_null'    => 0,
                'multiple'      => 0,
                'return_format' => 'id',
                'wrapper'       => ['width' => '50'],
            ],
            [
                'key'           => 'field_cc_coin_id',
                'label'         => 'Coin',
                'name'          => 'coin_id',
                'type'          => 'post_object',
                'instructions'  => 'Монета в колекції.',
                'required'      => 1,
                'post_type'     => ['coins'],
                'allow_null'    => 0,
                'multiple'      => 0,
                'return_format' => 'id',
                'ui'            => 1,
                'wrapper'       => ['width' => '50'],
            ],
            [
                'key'          => 'field_cc_quantity',
                'label'        => 'Quantity',
                'name'         => 'quantity',
                'type'         => 'number',
                'instructions' => 'Кількість монет.',
                'required'     => 1,
                'default_value' => 1,
                'min'          => 1,
                'step'         => 1,
                'wrapper'      => ['width' => '50'],
            ],
            [
                'key'          => 'field_cc_price',
                'label'        => 'Purchase price (UAH)',
                'name'         => 'purchase_price',
                'type'         => 'number',
                'instructions' => 'Ціна, яку заплатив користувач.',
                'required'     => 0,
                'min'          => 0,
                'step'         => 0.01,
                'wrapper'      => ['width' => '50'],
            ],
        ];
    }
}