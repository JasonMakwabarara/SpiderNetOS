<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Builds minimal DAG definitions for quick-create / first-win automations.
 */
class FlowTemplateBuilder
{
    private const TEMPLATES = [
        'status' => [
            'name' => 'Daily Status Digest',
            'action' => 'digest',
            'schedule_cron' => 'daily_morning',
        ],
        'followup' => [
            'name' => 'Follow-up Reminder',
            'action' => 'reminder',
            'schedule_cron' => 'weekday_morning',
        ],
        'invoice' => [
            'name' => 'Invoice Reminder',
            'action' => 'invoice',
            'schedule_cron' => 'weekday_morning',
        ],
    ];

    /**
     * @return array{name: string, slug: string, description: string, dag: array, triggers: array, schedule_cron: string|null}
     */
    public function build(string $template, string $who, string $when): array
    {
        $key = array_key_exists($template, self::TEMPLATES) ? $template : 'status';
        $meta = self::TEMPLATES[$key];
        $name = $meta['name'];
        $slug = Str::slug($name.'-'.substr((string) Str::uuid(), 0, 8));
        $description = "For: {$who}. When: {$when}";

        $dag = [
            'nodes' => [
                [
                    'id' => 'trigger',
                    'type' => 'trigger',
                    'label' => 'Start',
                    'config' => ['when' => $when],
                ],
                [
                    'id' => 'action',
                    'type' => 'action',
                    'label' => $name,
                    'config' => [
                        'action' => $meta['action'],
                        'who' => $who,
                        'when' => $when,
                        'message' => "{$name} for {$who}",
                    ],
                ],
                [
                    'id' => 'notify',
                    'type' => 'action',
                    'label' => 'Notify',
                    'config' => [
                        'action' => 'notify',
                        'channel' => 'in_app',
                        'body' => "{$name} completed for {$who}",
                    ],
                ],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'action'],
                ['from' => 'action', 'to' => 'notify'],
            ],
        ];

        return [
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'dag' => $dag,
            'triggers' => [
                'type' => 'manual',
                'context' => [
                    'who' => $who,
                    'when' => $when,
                    'template' => $key,
                ],
            ],
            'schedule_cron' => $meta['schedule_cron'],
        ];
    }
}
