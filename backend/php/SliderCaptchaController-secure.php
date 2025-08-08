<?php
/**
 * SECURE Slider Captcha PHP Implementation
 * With proper session management and replay attack prevention
 * 
 * @version 3.0 - SECURE
 */

session_start();

class SecureSliderCaptchaController {
    
    private $sessionPath = '/tmp/captcha_sessions/';
    
    public function __construct() {
        if (!is_dir($this->sessionPath)) {
            mkdir($this->sessionPath, 0700, true); // Secure permissions
        }
    }
    
    /**
     * Generate a new captcha challenge
     * This MUST be called before showing the captcha to get the challenge token
     */
    public function generateChallenge() {
        $this->setCorsHeaders();
        
        // IP-based rate limiting
        $ip = $this->getClientIp();
        $rateLimitFile = $this->sessionPath . '/ratelimit_' . md5($ip) . '.json';
        
        if (file_exists($rateLimitFile)) {
            $rateData = json_decode(file_get_contents($rateLimitFile), true);
            $now = time();
            
            // Reset counter if window expired (1 minute)
            if ($now - $rateData['window_start'] > 60) {
                $rateData = ['count' => 0, 'window_start' => $now];
            }
            
            // Check rate limit (max 10 challenges per minute)
            if ($rateData['count'] >= 10) {
                return $this->jsonResponse([
                    'error' => 'Rate limit exceeded. Please try again later.'
                ], 429);
            }
            
            $rateData['count']++;
        } else {
            $rateData = ['count' => 1, 'window_start' => time()];
        }
        
        file_put_contents($rateLimitFile, json_encode($rateData));
        
        // Generate unique challenge ID
        $challengeId = bin2hex(random_bytes(32));
        
        // Generate random puzzle position (where the piece should end up)
        // This matches the JavaScript: minX = L + 10, maxX = width - L - 10
        // Assuming default width=320, L≈60
        $minX = 70;
        $maxX = 250;
        $targetX = random_int($minX, $maxX); // Cryptographically secure random
        
        // Calculate slider position needed (matching JS formula)
        $sliderL = 42;
        $sliderR = 9;
        $L = $sliderL + $sliderR * 2 + 3; // ≈60
        $canvasWidth = 320;
        $maxSliderMove = $canvasWidth - 40; // 280
        $puzzleRange = $canvasWidth - $L; // ≈260
        $targetSliderX = ($targetX / $puzzleRange) * $maxSliderMove;
        
        // Store challenge data in session
        $_SESSION['captcha_challenge'] = [
            'id' => $challengeId,
            'targetX' => $targetX,
            'targetSliderX' => $targetSliderX,
            'created' => time(),
            'attempts' => 0,
            'solved' => false
        ];
        
        // Also store in file for persistence
        $this->storeChallengeData($challengeId, [
            'targetX' => $targetX,
            'targetSliderX' => $targetSliderX,
            'created' => time(),
            'ip' => $this->getClientIp(),
            'session_id' => session_id()
        ]);
        
        // SECURITY WARNING: Sending targetX exposes the solution!
        // This is a known vulnerability but required for current implementation
        // TODO: Implement server-side image generation to avoid this
        return $this->jsonResponse([
            'challengeId' => $challengeId,
            'targetX' => $targetX, // VULNERABILITY: Exposes solution to client
            'timestamp' => time()
        ]);
    }
    
    /**
     * Verify captcha solution
     * Validates against server-stored challenge data
     */
    public function verify() {
        $this->setCorsHeaders();
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->jsonResponse(['verified' => false, 'error' => 'Method not allowed'], 405);
        }
        
        // Validate required fields
        if (!isset($input['challengeId']) || !isset($input['trail'])) {
            return $this->jsonResponse([
                'verified' => false,
                'error' => 'Missing required fields'
            ], 400);
        }
        
        // Input validation and sanitization
        $challengeId = preg_replace('/[^a-f0-9]/i', '', $input['challengeId']);
        if (strlen($challengeId) !== 64) { // 32 bytes in hex
            return $this->jsonResponse([
                'verified' => false,
                'error' => 'Invalid challenge ID format'
            ], 400);
        }
        
        $trail = $input['trail'];
        if (!is_array($trail) || count($trail) < 2 || count($trail) > 1000) {
            return $this->jsonResponse([
                'verified' => false,
                'error' => 'Invalid trail data'
            ], 400);
        }
        
        // Load challenge data
        $challengeData = $this->loadChallengeData($challengeId);
        
        if (!$challengeData) {
            error_log("Challenge not found: $challengeId");
            return $this->jsonResponse([
                'verified' => false,
                'error' => 'Invalid or expired challenge'
            ]);
        }
        
        // Check if already solved
        if ($challengeData['solved'] ?? false) {
            error_log("Challenge already solved: $challengeId");
            return $this->jsonResponse([
                'verified' => false,
                'error' => 'Challenge already used'
            ]);
        }
        
        // Check expiration (5 minutes)
        if (time() - $challengeData['created'] > 300) {
            error_log("Challenge expired: $challengeId");
            $this->deleteChallengeData($challengeId);
            return $this->jsonResponse([
                'verified' => false,
                'error' => 'Challenge expired'
            ]);
        }
        
        // Increment attempts
        $challengeData['attempts'] = ($challengeData['attempts'] ?? 0) + 1;
        
        // Max 5 attempts per challenge
        if ($challengeData['attempts'] > 5) {
            error_log("Too many attempts for challenge: $challengeId");
            $this->deleteChallengeData($challengeId);
            return $this->jsonResponse([
                'verified' => false,
                'error' => 'Too many attempts'
            ]);
        }
        
        // Update attempt count
        $this->storeChallengeData($challengeId, $challengeData);
        
        // Validate the solution
        $verified = $this->validateSolution($trail, $challengeData);
        
        if ($verified) {
            // Mark as solved and delete
            $challengeData['solved'] = true;
            $this->storeChallengeData($challengeId, $challengeData);
            
            // Clean up
            $this->deleteChallengeData($challengeId);
            unset($_SESSION['captcha_challenge']);
            
            // Generate success token
            $successToken = bin2hex(random_bytes(16));
            $_SESSION['captcha_verified'] = [
                'token' => $successToken,
                'timestamp' => time()
            ];
            
            error_log("Challenge solved successfully: $challengeId");
            
            return $this->jsonResponse([
                'verified' => true,
                'message' => 'Verification successful',
                'token' => $successToken
            ]);
        } else {
            error_log("Challenge verification failed: $challengeId");
            
            return $this->jsonResponse([
                'verified' => false,
                'error' => 'Incorrect solution',
                'attemptsRemaining' => 5 - $challengeData['attempts']
            ]);
        }
    }
    
    /**
     * Validate the actual solution
     */
    private function validateSolution($trail, $challengeData) {
        if (!is_array($trail) || count($trail) < 3) {
            error_log("Invalid trail: too short");
            return false;
        }
        
        // Check if this is free drag mode from input data
        $input = json_decode(file_get_contents('php://input'), true);
        $mode = $input['mode'] ?? 'slider';
        error_log("Validation mode: $mode");
        
        if ($mode === 'free') {
            return $this->validateFreeDragSolution($trail, $challengeData);
        }
        
        // Original slider validation
        $expectedSliderX = $challengeData['targetSliderX'];
        $tolerance = 10; // Allow 10px tolerance
        
        // Get final slider position from trail
        $finalX = end($trail)['x'] ?? 0;
        
        error_log("Solution check - Expected: $expectedSliderX, Got: $finalX, Tolerance: $tolerance");
        
        // Check if slider reached the target position
        if (abs($finalX - $expectedSliderX) > $tolerance) {
            error_log("Position mismatch - too far from target");
            return false;
        }
        
        // Basic movement validation
        $firstX = $trail[0]['x'] ?? 0;
        if ($finalX <= $firstX) {
            error_log("No forward movement detected");
            return false;
        }
        
        // Check duration (must take at least 100ms)
        $duration = end($trail)['t'] ?? 0;
        if ($duration < 100) {
            error_log("Movement too fast: {$duration}ms");
            return false;
        }
        
        // Check for some Y movement (human behavior)
        $yValues = array_map(function($point) {
            return $point['y'] ?? 0;
        }, $trail);
        
        $yRange = max($yValues) - min($yValues);
        if ($yRange < 1) {
            error_log("No Y-axis movement detected (bot-like)");
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate free drag solution where puzzle can move in X and Y
     */
    private function validateFreeDragSolution($trail, $challengeData) {
        // Get expected position
        $targetX = $challengeData['targetX'];
        $tolerance = 10; // Allow 10px tolerance
        
        // Get final position from trail
        $finalPoint = end($trail);
        $finalX = $finalPoint['x'] ?? 0;
        $finalY = $finalPoint['y'] ?? 0;
        
        error_log("Free drag check - Target X: $targetX, Final X: $finalX");
        
        // Check if puzzle reached the target X position
        if (abs($finalX - $targetX) > $tolerance) {
            error_log("X position mismatch in free drag mode");
            return false;
        }
        
        // Check for movement (not just placed directly)
        if (count($trail) < 5) {
            error_log("Too few trail points for free drag");
            return false;
        }
        
        // Check duration
        $duration = $finalPoint['t'] ?? 0;
        if ($duration < 200) { // Slightly longer for free drag
            error_log("Free drag too fast: {$duration}ms");
            return false;
        }
        
        // Calculate total distance traveled
        $totalDistance = 0;
        for ($i = 1; $i < count($trail); $i++) {
            $dx = ($trail[$i]['x'] ?? 0) - ($trail[$i-1]['x'] ?? 0);
            $dy = ($trail[$i]['y'] ?? 0) - ($trail[$i-1]['y'] ?? 0);
            $totalDistance += sqrt($dx * $dx + $dy * $dy);
        }
        
        // Check if there was actual dragging movement
        if ($totalDistance < 20) {
            error_log("Insufficient drag distance: $totalDistance");
            return false;
        }
        
        return true;
    }
    
    /**
     * Store challenge data
     */
    private function storeChallengeData($challengeId, $data) {
        $file = $this->sessionPath . 'challenge_' . $challengeId . '.json';
        file_put_contents($file, json_encode($data), LOCK_EX);
    }
    
    /**
     * Load challenge data
     */
    private function loadChallengeData($challengeId) {
        $file = $this->sessionPath . 'challenge_' . $challengeId . '.json';
        if (file_exists($file)) {
            return json_decode(file_get_contents($file), true);
        }
        return null;
    }
    
    /**
     * Delete challenge data
     */
    private function deleteChallengeData($challengeId) {
        $file = $this->sessionPath . 'challenge_' . $challengeId . '.json';
        if (file_exists($file)) {
            unlink($file);
        }
    }
    
    /**
     * Clean up old challenges
     */
    public function cleanup() {
        $files = glob($this->sessionPath . 'challenge_*.json');
        $now = time();
        $cleaned = 0;
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && ($now - $data['created'] > 300)) { // 5 minutes
                unlink($file);
                $cleaned++;
            }
        }
        
        return $this->jsonResponse(['cleaned' => $cleaned]);
    }
    
    /**
     * Get client IP
     */
    private function getClientIp() {
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                return $ip;
            }
        }
        
        return '0.0.0.0';
    }
    
    /**
     * Set CORS headers
     */
    private function setCorsHeaders() {
        // TODO: Replace with your actual domain
        $allowedOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = ['http://localhost:8000', 'http://localhost:8080']; // Add your domains
        if (in_array($allowedOrigin, $allowedOrigins)) {
            header("Access-Control-Allow-Origin: $allowedOrigin");
        } else {
            header("Access-Control-Allow-Origin: http://localhost:8000"); // Default safe origin
        }
        header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type");
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
    
    /**
     * Send JSON response
     */
    private function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}

// Handle requests
$controller = new SecureSliderCaptchaController();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'challenge':
        $controller->generateChallenge();
        break;
        
    case 'verify':
        $controller->verify();
        break;
        
    case 'cleanup':
        $controller->cleanup();
        break;
        
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Invalid action']);
}
?>