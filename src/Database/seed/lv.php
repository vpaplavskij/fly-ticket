<?php

return [
    // ── Menu labels ───────────────────────────────────────────────────
    'menu_title'            => '✈️ <b>Lidojumu cenas</b> — {route}',
    'bot_identity'          => '🤖 Bots: @{username}',
    'pick_direction'        => '<i>Izvēlieties virzienu:</i>',
    // Pogas ar valstu nosaukumiem: {origin}/{destination} — VALSTU NOSAUKUMI
    // (e.g. "LATVIA", "CYPRUS (LCA)"), ko izšķir Config\Route::COUNTRIES.
    'btn_route_country'     => '✈️  {origin} → {destination}',
    'btn_help'              => 'ℹ️  Palīdzība',
    'btn_language'          => '🌐  Valoda',

    'buy_usage'             => "Lietošana: /buy <datums>\n Piemērs: /buy 05 Nov\n(vai /buy 2026-11-05)",
    'no_flights_on_date'    => "{date} datumā no {origin} uz {destination} lidojumi nav atrasti.",
    'no_booking_link'       => "{date} datumam rezervācijas saite nav pieejama.",
    'buy_result_title'      => '🎫 <b>{date}</b> — {origin} → {destination}',
    'open_booking_link'     => 'Atvērt rezervācijas saiti',
    'btn_today'             => "📅  Šodienas lidojumi",
    'btn_next_week'         => '📆  Nākamās 7 dienas',
    'btn_next_month'        => '🗓️  Nākamais mēnesis',
    'btn_3_months'          => '📊  3 mēneši',
    'btn_back'              => '⬅️  Atpakaļ',
    'btn_main_menu'         => '🏠  Galvenais izvēlnes',
    'btn_lang_lv'           => '🇱🇻  Latviešu (LV)',
    'btn_lang_ru'           => '🇷🇺  Krievu (RU)',
    'btn_lang_eng'          => '🇬🇧  Angļu (ENG)',
    'choose_what_to_show'   => '<i>Izvēlieties, ko rādīt:</i>',

    // ── Handlers ──────────────────────────────────────────────────────
    'today_title'           => "✈️ <b>Šodienas lidojumi</b>",
    'no_flights_today'      => 'Šodien nav atrasts neviens lidojums.\nLūdzu, pārliecinieties vēlāk — cenas tiek atjaunotas vairākas reizes dienā.',
    'flights_count'         => '<i>{count} lidojum{s} šodien</i>',

    'upcoming_title'        => '📅 <b>Cenas nākotnē</b>',
    'no_upcoming_data'      => 'Nākotnes dati vēl nav pieejami.\n<i>Izpildiet cenu atjaunošanu, lai ielādētu nākotnes datumus.</i>',

    // ── Zemāko cenu etiķetes ──────────────────────────────────────────
    'lowest_badge'          => '🏷️ <i>zemākā</i>',
    'cheapest_days'         => '💰 <b>Lētākās dienas</b>',
    // Alternatīvo izlidošanas ostu bloka nosaukums (VNO/KUN).
    'alternatives_title'    => '🔀 <b>Alternatīvi izlidojumi</b>',

    // ── Help ──────────────────────────────────────────────────────────
    'help_title'            => '✈️ <b>Lidojumu cenas</b> — {route}',
    'bot_identity_line'     => '🤖 Šis bots: @{username}',
    'help_outbound_label'   => '<b>Brauciens ({origin} → {destination})</b>',
    'help_today'            => '<b>/today</b> — visi šodienas lidojumi',
    'help_nextweek'         => '<b>/nextweek</b> — nākamās 7 dienu zemākās cenas',
    'help_nextmonth'        => '<b>/nextmonth</b> — nākamā mēneša zemākās cenas',
    'help_next3months'      => '<b>/next3months</b> — nākošo 3 mēnešu zemākās cenas',
    'help_buy_label'        => '<b>Pasūtīšanas saite</b>',
    'help_buy'              => '<b>/buy</b> &lt;datums&gt; — pasūtīšanas saite vislētākajam lidojumam šajā datumā (piem. /buy 05 Nov)',
    'help_information'      => '<b>/info</b> — informācija par botu (alias no /help)',
    'help_help'             => '<b>/help</b> — parādīt šo palīdzību',

    // ── Language ──────────────────────────────────────────────────────
    'lang_title'            => '🌐 <b>Izvēlieties valodu</b>',
    'lang_prompt'           => '<i>Izvēlieties vēlamo valodu:</i>',
    'lang_set'              => '<i>✓ Valoda iestatīta: {name}</i>',

    // ── Errors / misc ─────────────────────────────────────────────────
    'unknown_command'       => 'Nezināma komanda. Sūtiet /help, lai redzētu pieejamās komandas.',
    'error_prefix'          => '⚠️ Kļūda: ',
    'option_unavailable'    => '⚠️ Šī opcija vairs nav pieejama.\n\n<i>Nospiediet zemāk, lai turpinātu:</i>',
    'something_went_wrong'  => '⚠️ Kas negāja labi.\n\n<i>Nospiediet zemāk, lai turpinātu:</i>',

    // ── Disclaimer ─────────────────────────────────────────────────────
    'price_note'            => '<i>Uzmanībai: cenas ir informatīvas — reālo cenu jāpārbauda rezervācijas tiešsaistē.</i>',

    // ── Labels (for range headers) ────────────────────────────────────
    'label_next_week'       => 'nākamās 7 dienas',
    'label_next_month'      => 'nākamais mēnesis',
    'label_next_3_months'   => 'nākošie 3 mēneši',
];
