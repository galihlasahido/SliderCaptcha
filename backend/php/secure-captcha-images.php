<?php
/**
 * Secure Server-Side Slider Captcha with Predefined Images
 * Uses real images instead of generated patterns
 */

session_start();

class SecureCaptchaImages {
    private $sessionKey = 'secure_captcha';
    private $imageWidth = 320;
    private $imageHeight = 200;
    private $pieceSize = 50;
    private $tolerance = 5;
    private $mode = null;
    private $imagesPath = __DIR__ . '/../../frontend/images/';
    
    public function __construct() {
        // Ensure GD library is available
        if (!extension_loaded('gd')) {
            die(json_encode(['error' => 'GD library not installed']));
        }
    }
    
    /**
     * Get a random image from the available images
     */
    private function getRandomImage() {
        $images = glob($this->imagesPath . '*.jpg');
        if (empty($images)) {
            throw new Exception('No images found');
        }
        return $images[array_rand($images)];
    }
    
    /**
     * Generate a new captcha challenge
     */
    public function generateChallenge() {
        // Check if this is for slider mode (horizontal only)
        $mode = $_GET['mode'] ?? 'freedrag';
        $this->mode = $mode;
        
        // Use different session key for each mode to prevent conflicts
        $sessionKey = $this->sessionKey . '_' . $mode;
        
        // Clear any existing challenge for this mode
        unset($_SESSION[$sessionKey]);
        
        // Generate unique challenge ID
        $challengeId = bin2hex(random_bytes(16));
        
        // Select a random image
        $imagePath = $this->getRandomImage();
        $imageIndex = basename($imagePath, '.jpg');
        
        // Generate random target position (kept secret on server)
        $targetX = random_int(60, $this->imageWidth - 60);
        
        // For slider mode, use fixed Y position (middle)
        // For freedrag mode, use random Y position
        if ($mode === 'slider') {
            $targetY = 75; // Fixed middle position for slider
        } else {
            $targetY = random_int(30, $this->imageHeight - 60);
        }
        
        // Store in session with mode information
        $_SESSION[$sessionKey] = [
            'id' => $challengeId,
            'targetX' => $targetX,
            'targetY' => $targetY,
            'mode' => $mode,
            'imagePath' => $imagePath,
            'imageIndex' => $imageIndex,
            'attempts' => 0,
            'created' => time()
        ];
        
        return [
            'success' => true,
            'challengeId' => $challengeId,
            'imageWidth' => $this->imageWidth,
            'imageHeight' => $this->imageHeight,
            'pieceSize' => $this->pieceSize
        ];
    }
    
    /**
     * Get the background image with puzzle hole
     */
    public function getBackgroundImage() {
        // Try to find the challenge in any mode's session
        $data = null;
        $challengeId = $_GET['id'] ?? '';
        
        // Check both possible session keys
        foreach (['slider', 'freedrag'] as $mode) {
            $sessionKey = $this->sessionKey . '_' . $mode;
            if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey]['id'] === $challengeId) {
                $data = $_SESSION[$sessionKey];
                break;
            }
        }
        
        if (!$data || !file_exists($data['imagePath'])) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }
        
        // Load the original image
        $original = imagecreatefromjpeg($data['imagePath']);
        if (!$original) {
            header('HTTP/1.1 500 Internal Server Error');
            exit;
        }
        
        // Get original dimensions
        $origWidth = imagesx($original);
        $origHeight = imagesy($original);
        
        // Create resized image
        $img = imagecreatetruecolor($this->imageWidth, $this->imageHeight);
        
        // Resize original image to fit our canvas
        imagecopyresampled($img, $original, 0, 0, 0, 0, 
                          $this->imageWidth, $this->imageHeight, 
                          $origWidth, $origHeight);
        
        // Free original image memory
        imagedestroy($original);
        
        // Draw puzzle hole at target position
        $x = $data['targetX'];
        $y = $data['targetY'];
        $size = $this->pieceSize;
        
        // Create semi-transparent overlay for hole area
        $overlay = imagecolorallocatealpha($img, 0, 0, 0, 30);
        imagefilledrectangle($img, $x-2, $y-2, $x+$size+2, $y+$size+2, $overlay);
        
        // Draw white border
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 40, 40, 40);
        
        // Draw border
        imagesetthickness($img, 2);
        imagerectangle($img, $x-1, $y-1, $x+$size+1, $y+$size+1, $white);
        
        // Fill with dark color to create hole effect
        imagefilledrectangle($img, $x, $y, $x+$size, $y+$size, $black);
        
        // Add puzzle tab on right side
        $tabX = $x + $size;
        $tabY = $y + $size/2;
        imagefilledellipse($img, $tabX, $tabY, 20, 20, $white);
        imagefilledellipse($img, $tabX, $tabY, 16, 16, $black);
        
        // Add slight shadow effect around hole
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 60);
        imagesetthickness($img, 1);
        imagerectangle($img, $x-2, $y-2, $x+$size+2, $y+$size+2, $shadow);
        
        // Output image
        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        imagepng($img);
        imagedestroy($img);
    }
    
    /**
     * Get the puzzle piece image
     */
    public function getPieceImage() {
        // Try to find the challenge in any mode's session
        $data = null;
        $challengeId = $_GET['id'] ?? '';
        
        // Check both possible session keys
        foreach (['slider', 'freedrag'] as $mode) {
            $sessionKey = $this->sessionKey . '_' . $mode;
            if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey]['id'] === $challengeId) {
                $data = $_SESSION[$sessionKey];
                break;
            }
        }
        
        if (!$data || !file_exists($data['imagePath'])) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }
        
        // Load the original image
        $original = imagecreatefromjpeg($data['imagePath']);
        if (!$original) {
            header('HTTP/1.1 500 Internal Server Error');
            exit;
        }
        
        // Get original dimensions
        $origWidth = imagesx($original);
        $origHeight = imagesy($original);
        
        // Create temporary full-size image
        $fullImg = imagecreatetruecolor($this->imageWidth, $this->imageHeight);
        
        // Resize original image to match our canvas size
        imagecopyresampled($fullImg, $original, 0, 0, 0, 0, 
                          $this->imageWidth, $this->imageHeight, 
                          $origWidth, $origHeight);
        
        // Free original image memory
        imagedestroy($original);
        
        // Create piece image with tab
        $pieceWidth = $this->pieceSize + 10;
        $pieceHeight = $this->pieceSize;
        $img = imagecreatetruecolor($pieceWidth, $pieceHeight);
        
        // Make background transparent
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);
        imagesavealpha($img, true);
        
        // Copy the piece from the full image at target position
        $x = $data['targetX'];
        $y = $data['targetY'];
        
        // Copy main piece area
        imagecopy($img, $fullImg, 0, 0, $x, $y, $this->pieceSize, $this->pieceSize);
        
        // Create the tab on the right side
        $tabX = $this->pieceSize;
        $tabY = $this->pieceSize / 2;
        $tabRadius = 8;
        
        // Copy tab area in a circle
        for ($i = -$tabRadius; $i <= $tabRadius; $i++) {
            for ($j = -$tabRadius; $j <= $tabRadius; $j++) {
                if ($i * $i + $j * $j <= $tabRadius * $tabRadius) {
                    $srcX = $x + $this->pieceSize + $i;
                    $srcY = $y + $tabY + $j;
                    
                    // Make sure we're within bounds
                    if ($srcX >= 0 && $srcX < $this->imageWidth && 
                        $srcY >= 0 && $srcY < $this->imageHeight) {
                        
                        $color = imagecolorat($fullImg, $srcX, $srcY);
                        imagesetpixel($img, $tabX + $i, $tabY + $j, $color);
                    }
                }
            }
        }
        
        // Free full image memory
        imagedestroy($fullImg);
        
        // Add border for better visibility
        $border = imagecolorallocate($img, 80, 80, 80);
        $borderLight = imagecolorallocate($img, 120, 120, 120);
        
        // Main piece border
        imagesetthickness($img, 1);
        imagerectangle($img, 0, 0, $this->pieceSize-1, $this->pieceSize-1, $border);
        
        // Tab outline
        imagearc($img, $tabX, $tabY, $tabRadius*2, $tabRadius*2, -90, 90, $border);
        
        // Add subtle inner border for depth
        imagerectangle($img, 1, 1, $this->pieceSize-2, $this->pieceSize-2, $borderLight);
        
        // Output image
        header('Content-Type: image/png');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        imagepng($img);
        imagedestroy($img);
    }
    
    /**
     * Verify the solution
     */
    public function verify($input) {
        // Try to find the challenge in any mode's session
        $data = null;
        $sessionKey = null;
        $challengeId = $input['challengeId'] ?? '';
        
        // Check both possible session keys
        foreach (['slider', 'freedrag'] as $mode) {
            $key = $this->sessionKey . '_' . $mode;
            if (isset($_SESSION[$key]) && $_SESSION[$key]['id'] === $challengeId) {
                $data = $_SESSION[$key];
                $sessionKey = $key;
                break;
            }
        }
        
        if (!$data) {
            return ['success' => false, 'error' => 'No active challenge'];
        }
        
        // Check expiration (5 minutes)
        if (time() - $data['created'] > 300) {
            unset($_SESSION[$sessionKey]);
            return ['success' => false, 'error' => 'Challenge expired'];
        }
        
        // Check attempts
        $data['attempts']++;
        if ($data['attempts'] > 5) {
            unset($_SESSION[$sessionKey]);
            return ['success' => false, 'error' => 'Too many attempts'];
        }
        $_SESSION[$sessionKey] = $data;
        
        // Verify challenge ID
        if ($input['challengeId'] !== $data['id']) {
            return ['success' => false, 'error' => 'Invalid challenge'];
        }
        
        // Verify position
        $userX = floatval($input['x'] ?? 0);
        $userY = floatval($input['y'] ?? 0);
        
        $correctX = abs($userX - $data['targetX']) <= $this->tolerance;
        $correctY = abs($userY - $data['targetY']) <= $this->tolerance;
        
        if ($correctX && $correctY) {
            unset($_SESSION[$sessionKey]);
            return ['success' => true, 'verified' => true];
        }
        
        return [
            'success' => false, 
            'verified' => false,
            'attempts_left' => 5 - $data['attempts']
        ];
    }
}

// Handle requests
$captcha = new SecureCaptchaImages();
$action = $_GET['action'] ?? '';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

switch ($action) {
    case 'challenge':
        header('Content-Type: application/json');
        echo json_encode($captcha->generateChallenge());
        break;
        
    case 'background':
        $captcha->getBackgroundImage();
        break;
        
    case 'piece':
        $captcha->getPieceImage();
        break;
        
    case 'verify':
        header('Content-Type: application/json');
        $input = json_decode(file_get_contents('php://input'), true);
        echo json_encode($captcha->verify($input));
        break;
        
    default:
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid action']);
}
?>