<?php

/*
|--------------------------------------------------------------------------
| Workspace Plans
|--------------------------------------------------------------------------
|
| Limits per plan. A null limit means unlimited. The "internal" plan is
| used for your own team; the others are ready for the SaaS phase.
|
*/

return [

    'internal' => [
        'label' => 'Internal',
        'limits' => [
            'campaigns' => null,
            'sources' => null,
            'members' => null,
            'min_fetch_interval' => 15,
            'ai_daily_budget_usd' => null,
            'web_searches_per_day' => null,
        ],
    ],

    'free' => [
        'label' => 'Free',
        'limits' => [
            'campaigns' => 1,
            'sources' => 10,
            'members' => 2,
            'min_fetch_interval' => 120,
            'ai_daily_budget_usd' => 0.5,
            'web_searches_per_day' => 5,
        ],
    ],

    'pro' => [
        'label' => 'Pro',
        'limits' => [
            'campaigns' => 10,
            'sources' => 200,
            'members' => 10,
            'min_fetch_interval' => 30,
            'ai_daily_budget_usd' => 10,
            'web_searches_per_day' => 100,
        ],
    ],

];
