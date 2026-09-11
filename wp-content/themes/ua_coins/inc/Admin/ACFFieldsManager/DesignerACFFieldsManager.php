<?php

namespace Coins\Admin\ACFFieldsManager;

class DesignerACFFieldsManager
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
            'key' => 'group_designer_fields',
            'title' => 'Designer',
            'fields' => [
                [
                    'key' => 'field_designer_full_name',
                    'label' => 'Full name',
                    'name' => 'full_name',
                    'type' => 'text',
                    'required' => 0,
                ],
                [
                    'key' => 'field_designer_note',
                    'label' => 'Note',
                    'name' => 'note',
                    'type' => 'textarea',
                    'required' => 0,
                    'rows' => 4,
                    'new_lines' => 'br',
                ],
            ],
            'location' => [
                [
                    [
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'designer',
                    ],
                ],
            ],
            'position' => 'acf_after_title',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
        ]);
    }
}