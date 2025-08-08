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
            mkdir($this->sessionPath, 0777, true);
        }
    }
    
    /**
     * Generate a new captcha challenge
     * This MUST be called before showing the captcha to get the challenge token
     */
    public function generateChallenge() {
        $this->setCorsHeaders();
        
        // Generate unique challenge ID
        $challengeId = bin2hex(random_bytes(32));
        
        // Generate random puzzle position (where the piece should end up)
        // This matches the JavaScript: minX = L + 10, maxX = width - L - 10
        // Assuming default width=320, L≈60
        $minX = 70;
        $maxX = 250;
        $targetX = rand($minX, $maxX);
        
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
        
        return $this->jsonResponse([
            'challengeId' => $challengeId,
            'targetX' => $targetX, // Send position to client for rendering
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
        
        $challengeId = $input['challengeId'];
        $trail = $input['trail'];
        
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
        
        // Get expected positions
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
        header("Access-Control-Allow-Origin: *");
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