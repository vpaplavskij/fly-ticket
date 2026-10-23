<?php

return [
    // ── Menu labels ───────────────────────────────────────────────────
    'menu_title'            => '✈️ <b>Flight Price Monitor</b> — {route}',
    'bot_identity'          => '🤖 Bot: @{username}',
    'pick_direction'        => '<i>Pick a direction to start:</i>',
    // Country-based button labels: {origin}/{destination} carry COUNTRY NAMES
    // (e.g. "LATVIA", "CYPRUS (LCA)"), resolved from Config\Route::COUNTRIES.
    // Used in the main menu and direction footers so routes are named as
    // country pairs instead of city pairs.
    'btn_route_country'     => '✈️  {origin} → {destination}',
    'btn_help'              => 'ℹ️  Help',
    'btn_language'          => '🌐  Language',

    'buy_usage'             => "Usage: /buy <date>\nExample: /buy Nov 05\n(or /buy 2026-11-05)",
    'no_flights_on_date'    => "No flights found for {date} on {origin} → {destination}.",
    'no_booking_link'       => "No booking link available for {date}.",
    'buy_result_title'      => '🎫 <b>{date}</b> — {origin} → {destination}',
    'open_booking_link'     => 'Open booking link',
    'btn_today'             => "📅  Today's flights",
    'btn_next_week'         => '📆  Next week (7 days)',
    'btn_next_month'        => '🗓️  Next month',
    'btn_3_months'          => '📊  3 months',
    'btn_back'              => '⬅️  Back',
    'btn_main_menu'         => '🏠  Main menu',
    'btn_lang_lv'           => '🇱🇻  Latvian (LV)',
    'btn_lang_ru'           => '🇷🇺  Russian (RU)',
    'btn_lang_eng'          => '🇬🇧  English (ENG)',
    'choose_what_to_show'   => '<i>Choose what to show:</i>',

    // ── Handlers ──────────────────────────────────────────────────────
    'today_title'           => "✈️ <b>Today's flights</b>",
    'no_flights_today'      => 'No flights found for today.\nPlease check back later — prices are updated a few times a day.',
    'flights_count'         => '<i>{count} flight{s} today</i>',

    'upcoming_title'        => '📅 <b>Upcoming Prices</b>',
    'no_upcoming_data'      => 'No upcoming data yet.\n<i>Run the fetch script to populate future dates.</i>',

    // ── Lowest-price labels ───────────────────────────────────────────
    'lowest_badge'          => '🏷️ <i>lowest</i>',
    'cheapest_days'         => '💰 <b>Cheapest days</b>',
    // Alternative-origin section header (shown when VNO/KUN have data).
    'alternatives_title'    => '🔀 <b>Alternative departures</b>',

    // ── Help ──────────────────────────────────────────────────────────
    'help_title'            => '✈️ <b>Flight Price Monitor</b> — {route}',
    'bot_identity_line'     => '🤖 This bot: @{username}',
    'help_outbound_label'   => '<b>Outbound ({origin} → {destination})</b>',
    'help_today'            => '<b>/today</b> — all flights today',
    'help_nextweek'         => '<b>/nextweek</b> — next 7 days lowest prices',
    'help_nextmonth'        => '<b>/nextmonth</b> — next month lowest prices',
    'help_next3months'      => '<b>/next3months</b> — next 3 months lowest prices',
    'help_buy_label'        => '<b>Booking link</b>',
    'help_buy'              => '<b>/buy</b> &lt;date&gt; — booking link for the cheapest flight on that date (e.g. /buy Nov 05)',
    'help_information'      => '<b>/info</b> — bot info (alias of /help)',
    'help_help'             => '<b>/help</b> — show this help',

    // ── Language ──────────────────────────────────────────────────────
    'lang_title'            => '🌐 <b>Select language</b>',
    'lang_prompt'           => '<i>Choose your preferred language:</i>',
    'lang_set'              => '<i>✓ Language set to {name}</i>',

    // ── Errors / misc ─────────────────────────────────────────────────
    'unknown_command'       => 'Unknown command. Send /help to see available commands.',
    'error_prefix'          => '⚠️ Error: ',
    'option_unavailable'    => '⚠️ That option is no longer available.\n\n<i>Tap below to continue:</i>',
    'something_went_wrong'  => '⚠️ Something went wrong.\n\n<i>Tap below to continue:</i>',

    // ── Disclaimer ─────────────────────────────────────────────────────
    'price_note'            => '<i>Note: prices are indicative — the real price should be rechecked on the booking link.</i>',

    // ── Labels (for range headers) ────────────────────────────────────
    'label_next_week'       => 'next 7 days',
    'label_next_month'      => 'next month',
    'label_next_3_months'   => 'next 3 months',
];
