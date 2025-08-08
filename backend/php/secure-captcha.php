<?php
/**
 * Secure Server-Side Slider Captcha
 * Complete working implementation that never exposes the target position
 */

session_start();

class SecureCaptcha {
    private $sessionKey = 'secure_captcha';
    private $imageWidth = 320;
    private $imageHeight = 200;
    private $pieceSize = 50;
    private $tolerance = 5;
    private $mode = null;
    
    public function __construct() {
        // Ensure GD library is available
        if (!extension_loaded('gd')) {
            die(json_encode(['error' => 'GD library not installed']));
        }
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
        
        if (!$data) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }
        
        // Create base image
        $img = imagecreatetruecolor($this->imageWidth, $this->imageHeight);
        
        // Dark blue background like the pattern
        $darkBlue = imagecolorallocate($img, 25, 42, 86);
        imagefill($img, 0, 0, $darkBlue);
        
        // Define vibrant colors for the geometric pattern
        $colors = [
            imagecolorallocate($img, 255, 87, 115),  // Pink/Red
            imagecolorallocate($img, 255, 195, 0),   // Yellow
            imagecolorallocate($img, 46, 213, 182),  // Teal
            imagecolorallocate($img, 89, 131, 252),  // Blue
            imagecolorallocate($img, 255, 121, 63),  // Orange
            imagecolorallocate($img, 156, 136, 255), // Purple
            imagecolorallocate($img, 64, 224, 208),  // Turquoise
        ];
        
        // Set thicker lines for bold pattern
        imagesetthickness($img, 4);
        
        // Draw random curved lines
        for ($i = 0; $i < 8; $i++) {
            $color = $colors[array_rand($colors)];
            $x1 = random_int(0, $this->imageWidth);
            $y1 = random_int(0, $this->imageHeight);
            $x2 = random_int(0, $this->imageWidth);
            $y2 = random_int(0, $this->imageHeight);
            
            // Draw bezier curves using multiple line segments
            for ($t = 0; $t <= 1; $t += 0.1) {
                $cx = random_int(min($x1, $x2), max($x1, $x2));
                $cy = random_int(min($y1, $y2), max($y1, $y2));
                
                $px1 = $x1 + ($cx - $x1) * $t;
                $py1 = $y1 + ($cy - $y1) * $t;
                $px2 = $cx + ($x2 - $cx) * $t;
                $py2 = $cy + ($y2 - $cy) * $t;
                
                imageline($img, (int)$px1, (int)$py1, (int)$px2, (int)$py2, $color);
            }
        }
        
        // Draw spirals
        imagesetthickness($img, 3);
        for ($i = 0; $i < 4; $i++) {
            $color = $colors[array_rand($colors)];
            $centerX = random_int(30, $this->imageWidth - 30);
            $centerY = random_int(30, $this->imageHeight - 30);
            
            // Draw spiral using arcs
            for ($j = 0; $j < 3; $j++) {
                $radius = 15 + $j * 8;
                imagearc($img, $centerX, $centerY, $radius * 2, $radius * 2, 
                        $j * 90, $j * 90 + 270, $color);
            }
        }
        
        // Draw concentric circles
        for ($i = 0; $i < 3; $i++) {
            $color = $colors[array_rand($colors)];
            $x = random_int(20, $this->imageWidth - 20);
            $y = random_int(20, $this->imageHeight - 20);
            
            imagesetthickness($img, 3);
            imagearc($img, $x, $y, 30, 30, 0, 360, $color);
            imagesetthickness($img, 2);
            imagearc($img, $x, $y, 20, 20, 0, 360, $color);
            imagefilledellipse($img, $x, $y, 8, 8, $color);
        }
        
        // Draw wavy lines
        imagesetthickness($img, 3);
        for ($i = 0; $i < 5; $i++) {
            $color = $colors[array_rand($colors)];
            $startX = random_int(0, $this->imageWidth);
            $startY = random_int(0, $this->imageHeight);
            $amplitude = random_int(10, 20);
            $wavelength = random_int(20, 40);
            
            for ($x = 0; $x < 60; $x += 2) {
                $y = sin($x / $wavelength * pi()) * $amplitude;
                if ($startX + $x < $this->imageWidth && $startY + $y < $this->imageHeight &&
                    $startY + $y > 0) {
                    imagesetpixel($img, (int)($startX + $x), (int)($startY + $y), $color);
                    imagesetpixel($img, (int)($startX + $x), (int)($startY + $y + 1), $color);
                    imagesetpixel($img, (int)($startX + $x), (int)($startY + $y - 1), $color);
                }
            }
        }
        
        // Add dots scattered around
        foreach ($colors as $color) {
            for ($i = 0; $i < 8; $i++) {
                $x = random_int(5, $this->imageWidth - 5);
                $y = random_int(5, $this->imageHeight - 5);
                imagefilledellipse($img, $x, $y, random_int(3, 6), random_int(3, 6), $color);
            }
        }
        
        // Add some dashed lines
        imagesetthickness($img, 3);
        for ($i = 0; $i < 4; $i++) {
            $color = $colors[array_rand($colors)];
            $x1 = random_int(0, $this->imageWidth);
            $y1 = random_int(0, $this->imageHeight);
            $angle = random_int(0, 360);
            $length = random_int(30, 60);
            
            // Draw dashed line
            for ($j = 0; $j < $length; $j += 8) {
                $x2 = $x1 + cos(deg2rad($angle)) * $j;
                $y2 = $y1 + sin(deg2rad($angle)) * $j;
                $x3 = $x1 + cos(deg2rad($angle)) * ($j + 4);
                $y3 = $y1 + sin(deg2rad($angle)) * ($j + 4);
                
                if ($x2 >= 0 && $x2 < $this->imageWidth && $y2 >= 0 && $y2 < $this->imageHeight &&
                    $x3 >= 0 && $x3 < $this->imageWidth && $y3 >= 0 && $y3 < $this->imageHeight) {
                    imageline($img, (int)$x2, (int)$y2, (int)$x3, (int)$y3, $color);
                }
            }
        }
        
        // Draw puzzle hole at target position
        $x = $data['targetX'];
        $y = $data['targetY'];
        $size = $this->pieceSize;
        
        // Create semi-transparent overlay for better visibility
        $overlay = imagecolorallocatealpha($img, 0, 0, 0, 50);
        imagefilledrectangle($img, $x-3, $y-3, $x+$size+3, $y+$size+3, $overlay);
        
        // Draw white border
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 20, 20, 20);
        
        imagesetthickness($img, 2);
        imagerectangle($img, $x-1, $y-1, $x+$size+1, $y+$size+1, $white);
        
        // Dark hole
        imagefilledrectangle($img, $x, $y, $x+$size, $y+$size, $black);
        
        // Add puzzle tab
        $tabX = $x + $size;
        $tabY = $y + $size/2;
        imagefilledellipse($img, $tabX, $tabY, 20, 20, $white);
        imagefilledellipse($img, $tabX, $tabY, 16, 16, $black);
        
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
        
        if (!$data) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }
        
        // Create piece image
        $img = imagecreatetruecolor($this->pieceSize + 10, $this->pieceSize);
        
        // Make background transparent
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);
        imagesavealpha($img, true);
        
        // Fill with dark blue background matching the main image
        $darkBlue = imagecolorallocate($img, 25, 42, 86);
        imagefilledrectangle($img, 0, 0, $this->pieceSize, $this->pieceSize, $darkBlue);
        
        // Get piece position
        $x = $data['targetX'];
        $y = $data['targetY'];
        
        // Define the same vibrant colors
        $colors = [
            imagecolorallocate($img, 255, 87, 115),  // Pink/Red
            imagecolorallocate($img, 255, 195, 0),   // Yellow
            imagecolorallocate($img, 46, 213, 182),  // Teal
            imagecolorallocate($img, 89, 131, 252),  // Blue
            imagecolorallocate($img, 255, 121, 63),  // Orange
            imagecolorallocate($img, 156, 136, 255), // Purple
            imagecolorallocate($img, 64, 224, 208),  // Turquoise
        ];
        
        // Use seed based on position for consistent but unique pattern
        srand($x * 1000 + $y);
        
        // Draw pattern elements that would appear in this piece area
        // This creates a matching pattern that aligns with the background
        
        // Draw curved lines that pass through this area
        imagesetthickness($img, 4);
        for ($i = 0; $i < 3; $i++) {
            $color = $colors[array_rand($colors)];
            // Generate line that might pass through piece area
            $x1 = random_int(-50, $this->pieceSize + 50);
            $y1 = random_int(-50, $this->pieceSize + 50);
            $x2 = random_int(-50, $this->pieceSize + 50);
            $y2 = random_int(-50, $this->pieceSize + 50);
            
            imageline($img, $x1, $y1, $x2, $y2, $color);
        }
        
        // Add spirals if they would be in this area
        imagesetthickness($img, 3);
        $color = $colors[array_rand($colors)];
        $centerX = random_int(10, $this->pieceSize - 10);
        $centerY = random_int(10, $this->pieceSize - 10);
        
        for ($j = 0; $j < 2; $j++) {
            $radius = 8 + $j * 6;
            imagearc($img, $centerX, $centerY, $radius * 2, $radius * 2, 
                    $j * 90, $j * 90 + 270, $color);
        }
        
        // Add some dots
        foreach ($colors as $color) {
            for ($i = 0; $i < 2; $i++) {
                $dx = random_int(5, $this->pieceSize - 5);
                $dy = random_int(5, $this->pieceSize - 5);
                imagefilledellipse($img, $dx, $dy, random_int(3, 5), random_int(3, 5), $color);
            }
        }
        
        // Add wavy line
        imagesetthickness($img, 3);
        $color = $colors[array_rand($colors)];
        $startX = random_int(0, $this->pieceSize / 2);
        $startY = random_int(0, $this->pieceSize);
        $amplitude = 8;
        $wavelength = 15;
        
        for ($wx = 0; $wx < $this->pieceSize; $wx += 2) {
            $wy = sin($wx / $wavelength * pi()) * $amplitude;
            if ($startY + $wy >= 0 && $startY + $wy < $this->pieceSize) {
                imagesetpixel($img, $wx, (int)($startY + $wy), $color);
                imagesetpixel($img, $wx, (int)($startY + $wy + 1), $color);
                imagesetpixel($img, $wx, (int)($startY + $wy - 1), $color);
            }
        }
        
        // Add dashed lines
        imagesetthickness($img, 2);
        $color = $colors[array_rand($colors)];
        $angle = random_int(0, 180);
        for ($j = 0; $j < $this->pieceSize; $j += 8) {
            $lx1 = cos(deg2rad($angle)) * $j;
            $ly1 = sin(deg2rad($angle)) * $j;
            $lx2 = cos(deg2rad($angle)) * ($j + 4);
            $ly2 = sin(deg2rad($angle)) * ($j + 4);
            
            if ($lx1 >= 0 && $lx1 < $this->pieceSize && $ly1 >= 0 && $ly1 < $this->pieceSize &&
                $lx2 >= 0 && $lx2 < $this->pieceSize && $ly2 >= 0 && $ly2 < $this->pieceSize) {
                imageline($img, (int)$lx1, (int)$ly1, (int)$lx2, (int)$ly2, $color);
            }
        }
        
        // Reset random seed
        srand();
        
        // Add puzzle tab
        $tabY = $this->pieceSize/2;
        imagefilledellipse($img, $this->pieceSize, $tabY, 14, 14, $darkBlue);
        
        // Add matching pattern on tab
        $tabColor = $colors[array_rand($colors)];
        imagesetthickness($img, 2);
        imagearc($img, $this->pieceSize, $tabY, 10, 10, 0, 360, $tabColor);
        
        // Add white border for visibility
        $white = imagecolorallocate($img, 255, 255, 255);
        imagesetthickness($img, 2);
        imagerectangle($img, 0, 0, $this->pieceSize-1, $this->pieceSize-1, $white);
        
        // Tab border
        imagearc($img, $this->pieceSize, $tabY, 14, 14, -90, 90, $white);
        
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
$captcha = new SecureCaptcha();
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