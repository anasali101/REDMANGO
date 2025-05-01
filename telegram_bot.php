<?php
namespace RedMango;

use TelegramBot\Api\BotApi;
use TelegramBot\Api\Types\Update;
use TelegramBot\Api\Types\ReplyKeyboardMarkup;
use TelegramBot\Api\Types\Inline\InlineKeyboardMarkup;
use TelegramBot\Api\Types\Inline\InlineKeyboardButton;

class TelegramBot {
    private $bot;
    private $db;
    private $linkShortener;
    private $logger;
    private $userStates = [];
    
    public function __construct($db) {
        global $logger;
        $this->db = $db;
        $this->logger = $logger;
        $this->bot = new BotApi(TELEGRAM_BOT_TOKEN);
        $this->linkShortener = new LinkShortener($db);
        
        // Load user states from storage
        $this->loadUserStates();
    }
    
    /**
     * Process incoming updates from Telegram
     */
    public function processUpdate($update) {
        if (isset($update['message'])) {
            $this->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
        }
    }
    
    /**
     * Handle incoming messages
     */
    private function handleMessage($message) {
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? '';
        $userId = $message['from']['id'];
        $username = $message['from']['username'] ?? null;
        
        // Track user in database if new
        $this->trackUser($userId, $username, $chatId);
        
        // Get current user state
        $userState = $this->getUserState($userId);
        
        // Handle commands
        if (strpos($text, '/') === 0) {
            $this->handleCommand($text, $chatId, $userId);
            return;
        }
        
        // Handle state-based interactions
        switch ($userState['state']) {
            case 'AWAITING_URL':
                $this->handleUrlInput($text, $chatId, $userId);
                break;
                
            case 'AWAITING_CUSTOM_SUFFIX':
                $this->handleCustomSuffixInput($text, $chatId, $userId, $userState['original_url']);
                break;
                
            default:
                // Default welcome message if no context
                $this->sendWelcomeMessage($chatId);
                break;
        }
    }
    
    /**
     * Handle button callback queries
     */
    private function handleCallbackQuery($callbackQuery) {
        $chatId = $callbackQuery['message']['chat']['id'];
        $userId = $callbackQuery['from']['id'];
        $messageId = $callbackQuery['message']['message_id'];
        $data = $callbackQuery['data'];
        
        // Acknowledge the callback query
        $this->bot->answerCallbackQuery($callbackQuery['id']);
        
        if ($data === 'skip_custom_suffix') {
            // Skip custom suffix and generate a random one
            $userState = $this->getUserState($userId);
            $this->createShortLink($chatId, $userState['original_url'], null, $userId);
            $this->updateUserState($userId, 'IDLE');
        } elseif ($data === 'create_another') {
            // Start over to create another link
            $this->promptForUrl($chatId);
        } elseif ($data === 'view_my_links') {
            // Show user's active links
            $this->showUserLinks($chatId, $userId);
        } elseif (strpos($data, 'stats_') === 0) {
            // Show statistics for a specific link
            $urlCode = substr($data, 6);
            $this->showLinkStats($chatId, $urlCode);
        }
    }
    
    /**
     * Handle bot commands
     */
    private function handleCommand($command, $chatId, $userId) {
        $command = strtolower(explode(' ', trim($command))[0]);
        
        switch ($command) {
            case '/start':
                $this->sendWelcomeMessage($chatId);
                break;
                
            case '/new':
                $this->promptForUrl($chatId);
                break;
                
            case '/mylinks':
                $this->showUserLinks($chatId, $userId);
                break;
                
            case '/help':
                $this->sendHelpMessage($chatId);
                break;
                
            case '/about':
                $this->sendAboutMessage($chatId);
                break;
                
            default:
                $this->bot->sendMessage($chatId, "Unknown command. Type /help to see available commands.");
                break;
        }
    }
    
    /**
     * Send welcome message with main menu
     */
    private function sendWelcomeMessage($chatId) {
        $keyboard = new InlineKeyboardMarkup([
            [
                new InlineKeyboardButton([
                    'text' => '🔗 Create New Short Link',
                    'callback_data' => 'create_another'
                ])
            ],
            [
                new InlineKeyboardButton([
                    'text' => '📋 My Links',
                    'callback_data' => 'view_my_links'
                ])
            ],
            [
                new InlineKeyboardButton([
                    'text' => '❓ Help',
                    'callback_data' => 'help'
                ])
            ]
        ]);
        
        $this->bot->sendMessage(
            $chatId,
            "🥭 *Welcome to RedMango Link Shortener!*\n\n" .
            "I can create short links that expire after 48 hours.\n" .
            "You can also create custom short links.\n\n" .
            "What would you like to do?",
            'Markdown',
            false,
            null,
            $keyboard
        );
    }
    
    /**
     * Prompt user to enter a URL
     */
    private function promptForUrl($chatId) {
        $this->bot->sendMessage(
            $chatId,
            "Please paste the URL you want to shorten:"
        );
        
        // Update user state
        $userId = $this->getUserIdFromChatId($chatId);
        $this->updateUserState($userId, 'AWAITING_URL');
    }
    
    /**
     * Handle URL input from user
     */
    private function handleUrlInput($text, $chatId, $userId) {
        // Validate URL
        if (!filter_var($text, FILTER_VALIDATE_URL)) {
            $this->bot->sendMessage(
                $chatId,
                "⚠️ That doesn't look like a valid URL. Please enter a valid URL including http:// or https://"
            );
            return;
        }
        
        // Store original URL and ask for custom suffix
        $this->updateUserState($userId, 'AWAITING_CUSTOM_SUFFIX', ['original_url' => $text]);
        
        $keyboard = new InlineKeyboardMarkup([
            [
                new InlineKeyboardButton([
                    'text' => 'Skip (use random)',
                    'callback_data' => 'skip_custom_suffix'
                ])
            ]
        ]);
        
        $this->bot->sendMessage(
            $chatId,
            "Would you like to customize your short link?\n\n" .
            "Enter your desired custom text (letters, numbers, and hyphens only) or click 'Skip' to use a random code.",
            null,
            false,
            null,
            $keyboard
        );
    }
    
    /**
     * Handle custom suffix input
     */
    private function handleCustomSuffixInput($customSuffix, $chatId, $userId, $originalUrl) {
        // Validate custom suffix format
        if (preg_match('/^[a-zA-Z0-9_-]+$/', $customSuffix)) {
            // Create the link with custom suffix
            $this->createShortLink($chatId, $originalUrl, $customSuffix, $userId);
            $this->updateUserState($userId, 'IDLE');
        } else {
            $this->bot->sendMessage(
                $chatId,
                "⚠️ Invalid custom text. Please use only letters, numbers, underscores, and hyphens."
            );
        }
    }
    
    /**
     * Create a short link and send result to user
     */
    private function createShortLink($chatId, $originalUrl, $customSuffix, $userId) {
        $result = $this->linkShortener->shortenUrl($originalUrl, $customSuffix, $userId);
        
        if ($result['success']) {
            $keyboard = new InlineKeyboardMarkup([
                [
                    new InlineKeyboardButton([
                        'text' => '🔗 Create Another Link',
                        'callback_data' => 'create_another'
                    ])
                ],
                [
                    new InlineKeyboardButton([
                        'text' => '📋 View My Links',
                        'callback_data' => 'view_my_links'
                    ])
                ]
            ]);
            
            $customText = $customSuffix ? "Custom short link created!" : "Short link created!";
            
            $this->bot->sendMessage(
                $chatId,
                "✅ *$customText*\n\n" .
                "🔗 Short URL: `" . $result['short_url'] . "`\n" .
                "🌐 Original URL: " . $this->truncateUrl($result['original_url'], 40) . "\n" .
                "⏱ Expires in: 48 hours (" . date('Y-m-d H:i', strtotime($result['expires_at'])) . ")\n\n" .
                "_This link will stop working after 48 hours._",
                'Markdown',
                false,
                null,
                $keyboard
            );
        } else {
            $this->bot->sendMessage(
                $chatId,
                "❌ Error: " . $result['message'] . "\n\n" .
                "Please try again with a different URL or custom text."
            );
            $this->promptForUrl($chatId);
        }
    }
    
    /**
     * Show user's active links
     */
    private function showUserLinks($chatId, $userId) {
        $links = $this->linkShortener->getUserLinks($userId);
        
        if (empty($links)) {
            $this->bot->sendMessage(
                $chatId,
                "You don't have any active links. All your links have expired or you haven't created any yet.\n\n" .
                "Use /new to create a new short link."
            );
            return;
        }
        
        $message = "🔗 *Your Active Links*\n\n";
        
        foreach ($links as $index => $link) {
            if ($index >= 10) {
                $message .= "\n_...and " . (count($links) - 10) . " more links_";
                break;
            }
            
            $expiresIn = floor((strtotime($link['expires_at']) - time()) / 3600);
            $message .= ($index + 1) . ". `" . BASE_URL . $link['url_code'] . "`\n" .
                        "   Original: " . $this->truncateUrl($