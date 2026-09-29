<?php

/*
|--------------------------------------------------------------------------
| Campaign Templates
|--------------------------------------------------------------------------
|
| Starting points for a campaign's AI configuration. Nothing here is
| hard-coded into the pipeline: the AI schema is built from whatever
| categories and extraction fields the campaign ends up with.
|
*/

return [

    'sports_tournaments' => [
        'label' => 'Sports tournaments',
        'ai_brief' => 'Updates about sports tournaments: newly announced tournaments, schedules and fixtures, results, registrations and trials, and venue or date changes.',
        'categories' => ['Announcement', 'Fixture / Schedule', 'Result', 'Registration / Trials', 'Venue / Date change', 'Other'],
        'extraction_fields' => [
            ['key' => 'tournament', 'label' => 'Tournament', 'type' => 'string', 'description' => 'Name of the tournament or competition'],
            ['key' => 'start_date', 'label' => 'Start date', 'type' => 'date', 'description' => 'Date the tournament starts'],
            ['key' => 'end_date', 'label' => 'End date', 'type' => 'date', 'description' => 'Date the tournament ends'],
            ['key' => 'city', 'label' => 'City', 'type' => 'string', 'description' => 'City or district where it takes place'],
            ['key' => 'venue', 'label' => 'Venue', 'type' => 'string', 'description' => 'Stadium or ground'],
            ['key' => 'age_group', 'label' => 'Age group / category', 'type' => 'string', 'description' => 'For example U-17, Senior, Women'],
            ['key' => 'organizer', 'label' => 'Organizer', 'type' => 'string', 'description' => 'Federation, association, club or company organizing it'],
            ['key' => 'registration_deadline', 'label' => 'Registration deadline', 'type' => 'date', 'description' => 'Last date to register, if mentioned'],
        ],
    ],

    'events' => [
        'label' => 'Events',
        'ai_brief' => 'Upcoming events: announcements, dates, venues, tickets and schedule changes.',
        'categories' => ['New event', 'Schedule change', 'Tickets', 'Recap', 'Other'],
        'extraction_fields' => [
            ['key' => 'event_name', 'label' => 'Event', 'type' => 'string', 'description' => 'Name of the event'],
            ['key' => 'date', 'label' => 'Date', 'type' => 'date', 'description' => 'Event date'],
            ['key' => 'city', 'label' => 'City', 'type' => 'string', 'description' => 'City'],
            ['key' => 'venue', 'label' => 'Venue', 'type' => 'string', 'description' => 'Venue'],
            ['key' => 'organizer', 'label' => 'Organizer', 'type' => 'string', 'description' => 'Organizer'],
            ['key' => 'ticket_info', 'label' => 'Tickets', 'type' => 'string', 'description' => 'Ticket price or booking details'],
        ],
    ],

    'product_launches' => [
        'label' => 'Product launches',
        'ai_brief' => 'New product launches, announcements, pricing and availability.',
        'categories' => ['Launch', 'Announcement', 'Pricing', 'Review', 'Other'],
        'extraction_fields' => [
            ['key' => 'product', 'label' => 'Product', 'type' => 'string', 'description' => 'Product name'],
            ['key' => 'company', 'label' => 'Company', 'type' => 'string', 'description' => 'Brand or company'],
            ['key' => 'launch_date', 'label' => 'Launch date', 'type' => 'date', 'description' => 'Launch or availability date'],
            ['key' => 'price', 'label' => 'Price', 'type' => 'string', 'description' => 'Price with currency'],
        ],
    ],

    'government_notices' => [
        'label' => 'Government notices',
        'ai_brief' => 'Official notices, circulars, schemes and deadlines from government bodies.',
        'categories' => ['Notice', 'Scheme', 'Deadline', 'Result', 'Other'],
        'extraction_fields' => [
            ['key' => 'department', 'label' => 'Department', 'type' => 'string', 'description' => 'Issuing department or body'],
            ['key' => 'notice_type', 'label' => 'Notice type', 'type' => 'string', 'description' => 'Circular, tender, scheme, result…'],
            ['key' => 'effective_date', 'label' => 'Effective date', 'type' => 'date', 'description' => 'Date it takes effect'],
            ['key' => 'deadline', 'label' => 'Deadline', 'type' => 'date', 'description' => 'Last date to apply or respond'],
        ],
    ],

    'custom' => [
        'label' => 'Custom (start empty)',
        'ai_brief' => '',
        'categories' => ['News', 'Other'],
        'extraction_fields' => [],
    ],

];
