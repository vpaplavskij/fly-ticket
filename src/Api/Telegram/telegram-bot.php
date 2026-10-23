<?php

namespace FlyTicket\Api\Telegram;

require_once __DIR__ . '/../../bootstrap.php';

use Config\BotRegistry;
use FlyTicket\Database\FlightRepository;
use FlyTicket\Database\Schema;
use FlyTicket\Database\UserRepository;
use FlyTicket\Support\Logger;

// ── Setup ──────────────────────────────────────────────────────────
Logger::registerHandlers();

/**
 * Run the long-polling loop for ONE bot.
 *
 * Each configured Telegram bot (see Config\BotRegistry) gets its own process,
 * so each poller can track its own getUpdates offset and verify its own token.
 * The DB is shared across bots; only the token differs.
 */
function runBotLoop(string $botName): void
{
    $bot = BotRegistry::get($botName);
    if ($bot === null) {
        $known = implode(', ', BotRegistry::names()) ?: '(none)';
        fwrite(STDERR, "Unknown bot '{$botName}'. Configured bots: {$known}\n");
        exit(1);
    }

    Logger::info('Bot process starting', ['bot' => $bot->name]);

    $telegram = new TelegramClient($bot->token);
    $pdo      = \Database::getConnection();
    Schema::ensureTables($pdo);
    $repo  = new FlightRepository($pdo);
    $users = new UserRepository($pdo);

    // This bot's primary monitored route (its TELEGRAM_<NAME>_ROUTE override
    // when configured, otherwise the global Config\Route default).
    [$origin, $destination] = $bot->originDestination();

    // Shared command handlers + inline-keyboard menu builders are loaded
    // automatically via Composer "files" autoload (see composer.json).

    // ── Entry point: long-polling bot loop (default) or one-shot render ─
    if (defined('RENDER_COMMANDS_ONLY')) {
        // One-shot render mode: print command output to stdout and exit.
        // Used for local testing without the long-polling loop. Lives in
        // telegram-bot-render.php (Composer "files" autoloaded) so render
        // mode never needs a bot token or enters the poll loop.
        renderCommandsForBot($bot);
        exit(0);
    }

    // Verify bot token works before entering the loop
    $me = $telegram->getMe();
    Logger::info('Bot connected', ['bot' => '@' . ($me['result']['username'] ?? '?'), 'name' => $bot->name]);
    printf("Telegram bot connected: @%s (%s) [%s]\n", $me['result']['username'] ?? '?', $me['result']['first_name'] ?? '?', $bot->name);
    printf("Button-driven menu active — tap the buttons, no commands needed.\n");
    printf("(Commands still work: /today /nextweek /help)\n\n");

    $offset = null;

    while (true) {
        try {
            $updates = $telegram->getUpdates(30, $offset);

            if (!isset($updates['result']) || !is_array($updates['result'])) {
                continue;
            }

            foreach ($updates['result'] as $update) {
                // Track offset to avoid re-processing
                $offset = max($offset ?? 0, $update['update_id'] + 1);

                $chatId = $update['message']['chat']['id']
                    ?? $update['callback_query']['message']['chat']['id']
                    ?? null;
                if ($chatId === null) {
                    continue;
                }

                // Load this chat's language preference before handling.
                $lang = $users->getLang($chatId) ?? 'en';
                Translator::set($lang);

                // ── Inline button press (callback_query) ────────────────────
                if (isset($update['callback_query'])) {
                    $cb    = $update['callback_query'];
                    $data  = $cb['data'] ?? '';
                    $msgId = $cb['message']['message_id'] ?? null;

                    Logger::info('Button pressed', ['bot' => $bot->name, 'data' => $data, 'chat_id' => $chatId]);
                    printf("[%s] Button: %s (chat %s)\n", $bot->name, $data, $chatId);

                    safeHandleCallback($telegram, $repo, $users, $origin, $destination, $chatId, $msgId, $data);
                    continue;
                }

                // ── Text command (still supported, falls back to menus) ─────
                if (!isset($update['message']['text'])) {
                    continue;
                }

                $chatId  = $update['message']['chat']['id'];
                $text    = trim($update['message']['text']);
                $parts   = preg_split('/\s+/', $text);
                $command = strtolower(str_replace('/', '', $parts[0]));

                Logger::info('Command received', ['bot' => $bot->name, 'command' => $command, 'chat_id' => $chatId]);
                printf("[%s] Received: %s from chat %s\n", $bot->name, $text, $chatId);

                try {
                    // /start, /menu, /main → show the main menu (buttons)
                    if ($command === 'start' || $command === 'menu' || $command === 'main') {
                        $telegram->sendMessage($chatId, menuBanner(), mainMenuKeyboard());
                        continue;
                    }

                    // /help (and its aliases /info, /information) → help text + back-to-menu button
                    if ($command === 'help' || $command === 'info' || $command === 'information') {
                        $telegram->sendMessage($chatId, handleHelp(), buildKeyboard([[Translator::t('btn_main_menu'), 'm']]));
                        continue;
                    }

                    // Regular commands: render the view. Range views carry an
                    // inline 🎫 link per day; long ranges are split into several
                    // messages (each under Telegram's 4096 cap). A footer menu is
                    // attached to the last message so the user can tap through to
                    // another view or back to the main menu — no need to re-type
                    // /start.
                    $arg = isset($parts[1]) ? strtolower($parts[1]) : '';

                    // Text commands render in the direction the chat last opened
                    // (default outbound), so a typed /today matches the leg the
                    // user was browsing by button.
                    setActiveDirection(getActiveDirection($chatId));
                    [$messages, $viewKb] = dispatchCommandFull($repo, $origin, $destination, $command, $arg);

                    // Footer menu (Today / Week / Month / 3m + opposite dir + Menu).
                    $footer = viewFooterKeyboard(activeDirection());

                    // Delete the previous view's message(s) so only one view is
                    // visible at a time, then send the new chunks.
                    deleteLastViewMessages($telegram, $chatId, getViewMessageIds($chatId));
                    $newIds = [];
                    foreach ($messages as $i => $msg) {
                        // Attach the footer (and any view keyboard) to the last message.
                        $kb = ($i === count($messages) - 1)
                            ? combineKeyboards($viewKb, $footer)
                            : null;
                        $result = $telegram->sendMessage($chatId, truncateReply($msg), $kb);
                        if (isset($result['result']['message_id'])) {
                            $newIds[] = $result['result']['message_id'];
                        }
                    }
                    setViewMessageIds($chatId, $newIds);

                } catch (\Exception $e) {
                    Logger::error('Error handling command', [
                        'bot'       => $bot->name,
                        'command'   => $command,
                        'chat_id'   => $chatId,
                        'exception' => $e->getMessage(),
                        'at'        => $e->getFile() . ':' . $e->getLine(),
                    ]);
                    printf("[%s] Error handling command: %s\n", $bot->name, $e->getMessage());
                    try {
                        $telegram->sendMessage($chatId, Translator::t('error_prefix') . htmlspecialchars($e->getMessage()));
                    } catch (\Exception $sendErr) {
                        Logger::error('Failed to send error message', ['exception' => $sendErr->getMessage()]);
                    }
                }
            }

        } catch (\Throwable $e) {
            // Network errors during long polling are normal — retry after a pause
            Logger::warn('Polling error (retrying in 5s)', ['bot' => $bot->name, 'exception' => $e->getMessage()]);
            printf("[%s] Polling error: %s (retrying in 5s)\n", $bot->name, $e->getMessage());
            sleep(5);
        }
    }
}

// ── View-message tracking ─────────────────────────────────────────────
// The bot loop keeps one view visible per chat. When a new view is requested
// the previous view's message(s) are deleted first. These helpers read/write
// that tracker; it lives in a file-static array (one process = one bot).

/** @var array<int|string, int[]> */
$flyticket_last_view_ids = [];

/**
 * Per-chat last opened direction ('o'/'r'). Views remember which leg the user
 * was looking at so a plain range tap (or /buy) re-renders the SAME leg.
 */
$flyticket_active_directions = [];

function getActiveDirection(int|string $chatId): string
{
    global $flyticket_active_directions;
    return $flyticket_active_directions[$chatId] ?? 'o';
}

function setActiveDirectionForChat(int|string $chatId, string $direction): void
{
    global $flyticket_active_directions;
    $flyticket_active_directions[$chatId] = ($direction === 'r') ? 'r' : 'o';
}

function getViewMessageIds(int|string $chatId): array
{
    global $flyticket_last_view_ids;
    return $flyticket_last_view_ids[$chatId] ?? [];
}

function setViewMessageIds(int|string $chatId, array $ids): void
{
    global $flyticket_last_view_ids;
    if (empty($ids)) {
        unset($flyticket_last_view_ids[$chatId]);
    } else {
        $flyticket_last_view_ids[$chatId] = $ids;
    }
}

function forgetViewMessage(int|string $chatId, ?int $msgId): void
{
    global $flyticket_last_view_ids;
    if ($msgId === null || !isset($flyticket_last_view_ids[$chatId])) {
        return;
    }
    $ids = array_values(array_filter(
        $flyticket_last_view_ids[$chatId],
        fn(int $id): bool => $id !== $msgId,
    ));
    setViewMessageIds($chatId, $ids);
}

function deleteLastViewMessages($telegram, int|string $chatId, array $ids): void
{
    foreach ($ids as $id) {
        try {
            $telegram->deleteMessage($chatId, (int) $id);
        } catch (\Throwable $e) {
            Logger::warn('Failed to delete previous view message', [
                'chat_id'    => $chatId,
                'message_id' => $id,
                'exception'  => $e->getMessage(),
            ]);
        }
    }
}

// ── Callback (button) routing ───────────────────────────────────────

/**
 * Handle an inline-button press. Edits the pressed message in place so the
 * conversation stays a single, navigable thread (menus → views → back).
 */
function handleCallback($telegram, FlightRepository $repo, UserRepository $users, string $origin, string $destination, int|string $chatId, ?int $msgId, string $data): void
{
    // "m" → main menu. The text differs from the /start banner so the edit
    // always changes something (avoids Telegram's "message not modified"). The
    // message being edited is the view's footer — drop it from the tracker.
    if ($data === 'm') {
        forgetViewMessage($chatId, $msgId);
        sendWithEdit($telegram, $chatId, $msgId, menuBanner(), mainMenuKeyboard());
        return;
    }

    // "help" → help text + back to menu (also drops the edited footer message).
    if ($data === 'help') {
        forgetViewMessage($chatId, $msgId);
        sendWithEdit($telegram, $chatId, $msgId, handleHelp(), buildKeyboard([[Translator::t('btn_main_menu'), 'm']]));
        return;
    }

    // "lang" → language selection menu
    if ($data === 'lang') {
        sendWithEdit($telegram, $chatId, $msgId, Translator::t('lang_title') . "\n\n" . Translator::t('lang_prompt'), languageMenuKeyboard());
        return;
    }

    // "setlang:<code>" → set the active language and return to main menu
    if (str_starts_with($data, 'setlang:')) {
        $code = substr($data, 8);
        $users->setLang($chatId, Translator::normalize($code));
        Translator::set(Translator::normalize($code));

        $name = Translator::displayName($code);
        sendWithEdit(
            $telegram,
            $chatId,
            $msgId,
            Translator::t('lang_set', ['name' => $name]) . "\n\n" . menuBanner(),
            mainMenuKeyboard(),
        );
        return;
    }

    // "d:<i>:<dir>" → open a leg's views (the date commands for that route pair
    // and direction). Shown when the user taps a direction button from the main
    // menu or the opposite-direction button in a view footer. Legacy format
    // "d:<dir>" (no index) means route 0 — messages sent before the multi-route
    // upgrade still carry it.
    if (str_starts_with($data, 'd:')) {
        // New format d:<i>:<dir> → 2 segments after the "d:" prefix ([i, dir]);
        // legacy d:<dir> → 1 segment. Distinguish by count, NOT >=3 (a 2-segment
        // string was misread as legacy, forcing every leg to route 0).
        $segs = explode(':', substr($data, 2));
        if (count($segs) === 2 && ctype_digit($segs[0])) {
            // New format: d:<i>:<dir>
            $routeIndex = (int) $segs[0];
            $direction  = $segs[1] === 'r' ? 'r' : 'o';
        } else {
            // Legacy format: d:<dir> → route 0.
            $routeIndex = 0;
            $direction  = (end($segs) === 'r') ? 'r' : 'o';
        }
        setActiveRouteIndex($routeIndex);
        setActiveDirectionForChat($chatId, $direction);
        setActiveDirection($direction);

        // Delete any open view so the direction menu is the only thing visible.
        deleteLastViewMessages($telegram, $chatId, getViewMessageIds($chatId));
        setViewMessageIds($chatId, []);

        // Thread the pressed leg's index into the keyboard so its view buttons
        // carry v:<view>:<i>:<dir> for THIS leg — not whatever stale route index
        // happens to sit in process state.
        sendWithEdit($telegram, $chatId, $msgId, directionBanner($direction), directionMenuKeyboard($direction, $routeIndex));
        return;
    }

    // "buy:<date>[:<time>]" → send the booking link for that date (and time,
    // when present). Tapping one of the 🎫 buttons in a view triggers this.
    if (str_starts_with($data, 'buy:')) {
        $payload = substr($data, 4);
        // "2026-11-05" or "2026-11-05:11:15" — split into date + optional time.
        $parts    = explode(':', $payload);
        $datePart = $parts[0];
        $time     = isset($parts[1]) ? $parts[1] : '';

        // Book in the direction the chat is currently viewing.
        setActiveDirection(getActiveDirection($chatId));
        [$reply, ] = handleBuy($repo, $datePart . ($time !== '' ? ' ' . $time : ''));

        // Always send as a NEW message (not an edit) so the user gets a
        // fresh reply with the booking link — editing the list in place can
        // fail silently if the message is too old or unchanged.
        $keyboard = buildKeyboard([[Translator::t('btn_main_menu'), 'm']]);
        try {
            $telegram->sendMessage($chatId, truncateReply($reply), $keyboard);
        } catch (\Exception $e) {
            Logger::error('Failed to send buy link', ['exception' => $e->getMessage()]);
        }
        return;
    }

    // "v:<view>:<i>:<dir>" → render a concrete view of that route pair's leg.
    // Delete the previous view's message(s) first, then send the new chunks —
    // one clean view at a time. The pressed button's message is part of the old
    // view, so it is deleted too (not edited). Legacy "v:<view>:<dir>" (no
    // index) means route 0; callbacks without a direction fall back to the
    // chat's last opened direction.
    if (str_starts_with($data, 'v:')) {
        $rest     = substr($data, 2);
        $segments = explode(':', $rest);
        $view     = $segments[0];

        // The route index is deliberately NOT trusted from the button. Stale
        // buttons (built by an older process, or a second running instance of
        // this bot) can carry a baked-in index that points at the WRONG leg —
        // e.g. opening RIX⇄LCA but tapping a "v:week:0:o" button that renders
        // RIX⇄MLA. The direction menu sets process state when its d:<i>:<dir>
        // button is pressed, and the view tap always follows it, so re-derive
        // the index from that state (activeRouteIndex()). A v: button is only
        // ever reached via a preceding d: press or a footer of an already-open
        // leg, both of which leave the correct index in process state.
        if (count($segments) >= 3 && ctype_digit($segments[1])) {
            // New format: v:<view>:<i>:<dir> — direction from the button.
            $dir = $segments[2] === 'r' ? 'r' : 'o';
        } elseif (isset($segments[1])) {
            // Legacy format: v:<view>:<dir> — direction from the button.
            $dir = $segments[1] === 'r' ? 'r' : 'o';
        } else {
            $dir = null;
        }

        if ($dir !== null) {
            setActiveDirectionForChat($chatId, $dir);
        } else {
            // No direction in the callback (v:<view>): keep what the chat has.
            $dir = getActiveDirection($chatId);
        }
        setActiveDirection($dir);

        // The leg we are rendering: the index left in process state by the
        // preceding d:<i>:<dir> press (or a footer of an already-open leg).
        // Each callback press is a fresh handleCallback() call, so $routeIndex
        // is NOT inherited from any earlier branch — read it from state.
        $routeIndex = activeRouteIndex();

        [$lo, $ld] = directionPair($dir);
        Logger::info('View rendering', [
            'route_index' => $routeIndex,
            'direction'   => $dir,
            'pair'        => $lo . '>' . $ld,
        ]);

        [$command, $arg]     = viewToCommand($view);
        [$messages, $viewKb] = dispatchCommandFull($repo, $origin, $destination, $command, $arg);

        // Footer (Today / Week / Month / 3m + opposite dir + Menu) so the user
        // can keep navigating by tap — range taps stay on this leg. Thread the
        // rendered leg's index through so the footer re-renders THIS leg too.
        $footer = viewFooterKeyboard(activeDirection(), $routeIndex);

        // Delete the old view (including the message whose button was pressed).
        $oldIds = getViewMessageIds($chatId);
        if ($msgId !== null) {
            $oldIds[] = $msgId;
        }
        deleteLastViewMessages($telegram, $chatId, $oldIds);

        // Send the new chunks as fresh messages; the last one carries the footer.
        $newIds = [];
        $count  = count($messages);
        foreach ($messages as $i => $msg) {
            $kb = ($i === $count - 1) ? combineKeyboards($viewKb, $footer) : null;
            try {
                $result = $telegram->sendMessage($chatId, truncateReply($msg), $kb);
                if (isset($result['result']['message_id'])) {
                    $newIds[] = $result['result']['message_id'];
                }
            } catch (\Exception $e) {
                Logger::error('Failed to send view chunk', ['exception' => $e->getMessage()]);
            }
        }
        setViewMessageIds($chatId, $newIds);
        return;
    }

    // Unknown callback → nudge back to the main menu.
    sendWithEdit($telegram, $chatId, $msgId, Translator::t('option_unavailable'), buildKeyboard([[Translator::t('btn_main_menu'), 'm']]));
}

/**
 * Safe wrapper around handleCallback: any unexpected error is answered with a
 * nudge back to the main menu instead of propagating up and killing the
 * polling loop (which would make the bot appear "frozen").
 */
function safeHandleCallback($telegram, FlightRepository $repo, UserRepository $users, string $origin, string $destination, int|string $chatId, ?int $msgId, string $data): void
{
    try {
        handleCallback($telegram, $repo, $users, $origin, $destination, $chatId, $msgId, $data);
    } catch (\Throwable $e) {
        Logger::error('Button handler crashed', ['data' => $data, 'exception' => $e->getMessage()]);
        try {
            sendWithEdit($telegram, $chatId, $msgId, Translator::t('something_went_wrong'), buildKeyboard([[Translator::t('btn_main_menu'), 'm']]));
        } catch (\Throwable $fatal) {
            Logger::error('Failed to send fallback menu', ['exception' => $fatal->getMessage()]);
        }
    }
}

/**
 * Send a message with keyboard; edit in place when we have a message id.
 * Never throws: if the edit fails (message too old, "not modified", etc.) it
 * falls back to sending a new message so a button press can never kill the
 * polling loop.
 */
function sendWithEdit($telegram, int|string $chatId, ?int $msgId, string $text, ?string $keyboard): void
{
    if ($msgId !== null) {
        try {
            $telegram->editMessageText($chatId, $msgId, $text, $keyboard);
            return;
        } catch (\Exception $e) {
            // Message too old/unchanged to edit — fall back to a new message.
            Logger::warn('Edit failed, sending new message', ['exception' => $e->getMessage()]);
        }
    }

    try {
        $telegram->sendMessage($chatId, $text, $keyboard);
    } catch (\Exception $e) {
        // Last resort: log and drop. A failed send must not crash the loop.
        Logger::error('Failed to send menu message', ['exception' => $e->getMessage()]);
    }
}

// ── Standalone entry point ───────────────────────────────────────────
// When executed directly (php src/Api/Telegram/telegram-bot.php <bot>), run the
// named bot's loop. Render mode never reaches this line: render-commands.php
// defines RENDER_COMMANDS_ONLY before requiring this file, and
// runBotLoop() exits inside the render branch before returning.
if (basename(__FILE__) === 'telegram-bot.php') {
    $botName = $argv[1] ?? null;
    if ($botName === null) {
        fwrite(STDERR, "Usage: php src/Api/Telegram/telegram-bot.php <bot_name>\n");
        fwrite(STDERR, 'Configured bots: ' . (implode(', ', BotRegistry::names()) ?: '(none)') . "\n");
        exit(1);
    }
    runBotLoop($botName);
}
