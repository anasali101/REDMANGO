<?php
namespace RedMango;

class LinkShortener {
    private $db;
    private $logger;
    private $baseUrl;
    
    public function __construct($db) {
        global $logger;
        $this->db = $db;
        $this->logger = $logger;
        $this->baseUrl = BASE_URL;
    }
    
    /**
     * Creates a shortened URL
     * 
     * @param string $originalUrl The original URL to shorten
     * @param string $customSuffix Optional custom suffix for the URL
     * @param int $userId Telegram user ID
     * @return array Information about the shortened URL
     */
    public function shortenUrl($originalUrl, $customSuffix = null, $userId = null) {
        // Validate URL
        if (!filter_var($originalUrl, FILTER_VALIDATE_URL)) {
            $this->logger->warning("Invalid URL attempted: $originalUrl");
            return [
                'success' => false,
                'message' => 'Invalid URL format'
            ];
        }
        
        // Calculate expiry time (48 hours from now)
        $expiryTime = date('Y-m-d H:i:s', strtotime('+' . LINK_EXPIRY_HOURS . ' hours'));
        
        // Generate a unique code if custom suffix not provided
        $urlCode = $customSuffix ?: $this->generateUniqueCode();
        
        // Check if custom suffix is already in use
        if ($customSuffix) {
            $existingLink = $this->db->fetch(
                "SELECT * FROM short_links WHERE url_code = ?",
                [$customSuffix]
            );
            
            if ($existingLink) {
                $this->logger->warning("Custom suffix already in use: $customSuffix");
                return [
                    'success' => false,
                    'message' => 'This custom URL is already in use. Please choose another one.'
                ];
            }
        }
        
        // Store the URL in the database
        $result = $this->db->query(
            "INSERT INTO short_links (url_code, original_url, created_at, expires_at, user_id) 
             VALUES (?, ?, NOW(), ?, ?)",
            [$urlCode, $originalUrl, $expiryTime, $userId]
        );
        
        if (!$result) {
            $this->logger->error("Failed to create short URL for: $originalUrl");
            return [
                'success' => false,
                'message' => 'Failed to create short URL'
            ];
        }
        
        $shortUrl = $this->baseUrl . $urlCode;
        $this->logger->info("Created short URL: $shortUrl for $originalUrl");
        
        return [
            'success' => true,
            'short_url' => $shortUrl,
            'original_url' => $originalUrl,
            'expires_at' => $expiryTime,
            'url_code' => $urlCode
        ];
    }
    
    /**
     * Resolves a short link to its original URL
     * 
     * @param string $urlCode The short URL code
     * @return string|false The original URL or false if not found/expired
     */
    public function resolveShortLink($urlCode) {
        // Get the link information
        $link = $this->db->fetch(
            "SELECT * FROM short_links WHERE url_code = ? AND expires_at > NOW()",
            [$urlCode]
        );
        
        if (!$link) {
            $this->logger->info("Link not found or expired: $urlCode");
            return false;
        }
        
        // Update access count
        $this->db->query(
            "UPDATE short_links SET access_count = access_count + 1, last_accessed = NOW() 
             WHERE id = ?",
            [$link['id']]
        );
        
        $this->logger->info("Resolved short URL: $urlCode to " . $link['original_url']);
        return $link['original_url'];
    }
    
    /**
     * Generates a unique code for shortened URLs
     * 
     * @param int $length Length of the code to generate
     * @return string A unique code
     */
    private function generateUniqueCode($length = 6) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        
        do {
            $urlCode = '';
            for ($i = 0; $i < $length; $i++) {
                $urlCode .= $characters[rand(0, $charactersLength - 1)];
            }
            
            // Check if code already exists
            $exists = $this->db->fetch(
                "SELECT id FROM short_links WHERE url_code = ?",
                [$urlCode]
            );
        } while ($exists);
        
        return $urlCode;
    }
    
    /**
     * Gets statistics for a shortened URL
     * 
     * @param string $urlCode The short URL code
     * @return array|false Statistics or false if not found
     */
    public function getLinkStats($urlCode) {
        return $this->db->fetch(
            "SELECT url_code, original_url, created_at, expires_at, 
                    access_count, last_accessed 
             FROM short_links 
             WHERE url_code = ?",
            [$urlCode]
        );
    }
    
    /**
     * Gets all active links for a user
     * 
     * @param int $userId The Telegram user ID
     * @return array An array of active links
     */
    public function getUserLinks($userId) {
        return $this->db->fetchAll(
            "SELECT url_code, original_url, created_at, expires_at, 
                    access_count, last_accessed 
             FROM short_links 
             WHERE user_id = ? AND expires_at > NOW() 
             ORDER BY created_at DESC",
            [$userId]
        );
    }
}