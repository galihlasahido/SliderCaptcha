# Technical Documentation - Secure Slider Captcha

## Architecture Overview

### Security Model: Challenge-Response with Anti-Replay Protection

```
┌─────────────┐         ┌─────────────────┐         ┌──────────────┐
│   Browser   │────────▶│   Web Server    │────────▶│   Storage    │
│             │         │                 │         │  (Session/   │
│ JavaScript  │◀────────│ PHP/.NET/Java   │◀────────│   File)      │
└─────────────┘         └─────────────────┘         └──────────────┘
     ▲                           │
     │                           ▼
     │                   ┌─────────────────┐
     └───────────────────│   Challenge     │
                        │   Generator      │
                        └─────────────────┘
```

## Core Security Implementation

### 1. Challenge Generation (Server-Side)

```php
// PHP Implementation Example
public function generateChallenge() {
    // Generate cryptographically secure challenge ID
    $challengeId = bin2hex(random_bytes(32));
    
    // Random puzzle position (server determines position)
    $targetX = rand(70, 250);
    
    // Calculate expected slider position
    $targetSliderX = ($targetX / $puzzleRange) * $maxSliderMove;
    
    // Store challenge with metadata
    $_SESSION['captcha_challenge'] = [
        'id' => $challengeId,
        'targetX' => $targetX,
        'targetSliderX' => $targetSliderX,
        'created' => time(),
        'attempts' => 0,
        'solved' => false
    ];
    
    return ['challengeId' => $challengeId, 'targetX' => $targetX];
}
```

### 2. Client-Side Rendering (JavaScript)

```javascript
class SecureSliderCaptcha {
    async requestChallenge() {
        // Request challenge from server
        const response = await fetch(this.options.challengeUrl);
        const data = await response.json();
        
        // Store server-provided position
        this.challengeId = data.challengeId;
        this.targetX = data.targetX;
        
        // Render puzzle at server-determined position
        this.drawPuzzle();
    }
    
    drawPuzzle(img) {
        // Cut out puzzle piece at server position
        createPuzzlePath(this.canvasCtx, this.targetX, this.puzzleY);
        this.canvasCtx.globalCompositeOperation = 'destination-out';
        this.canvasCtx.fill();
    }
}
```

### 3. Verification Process

```php
public function verify($input) {
    $challengeId = $input['challengeId'];
    $trail = $input['trail'];
    
    // Load challenge from storage
    $challengeData = $this->loadChallengeData($challengeId);
    
    // Security checks
    if (!$challengeData) {
        return ['verified' => false, 'error' => 'Invalid challenge'];
    }
    
    if ($challengeData['solved']) {
        return ['verified' => false, 'error' => 'Challenge already used'];
    }
    
    if (time() - $challengeData['created'] > 300) {
        return ['verified' => false, 'error' => 'Challenge expired'];
    }
    
    // Validate solution
    $finalX = end($trail)['x'];
    if (abs($finalX - $challengeData['targetSliderX']) > TOLERANCE) {
        return ['verified' => false, 'error' => 'Incorrect position'];
    }
    
    // Mark as solved (prevents replay)
    $challengeData['solved'] = true;
    $this->deleteChallengeData($challengeId);
    
    return ['verified' => true, 'token' => $successToken];
}
```

## Security Features Deep Dive

### 1. One-Time Challenge Tokens

**Implementation:**
- 64-character hex string from `random_bytes(32)`
- Stored server-side with puzzle position
- Deleted immediately after successful verification

**Why it's secure:**
- Cannot be predicted or forged
- Tied to specific puzzle configuration
- Single-use prevents replay attacks

### 2. Server-Side Position Validation

**Storage Structure:**
```json
{
    "id": "a3f2d1b8c9e7...",
    "targetX": 147,
    "targetSliderX": 132.5,
    "created": 1754600000,
    "attempts": 2,
    "solved": false,
    "ipAddress": "192.168.1.1"
}
```

**Validation Logic:**
- Position tolerance: ±10 pixels
- Validates final slider position matches expected
- No client-side position information trusted

### 3. Movement Analysis (Bot Detection)

```javascript
// Trail point structure
{
    x: 125,      // Horizontal position
    y: -5,       // Vertical deviation
    t: 1250      // Timestamp (ms)
}
```

**Detection Algorithms:**

#### a. Velocity Analysis
```php
private function calculateVelocities($trail) {
    for ($i = 1; $i < count($trail); $i++) {
        $dx = $trail[$i]['x'] - $trail[$i-1]['x'];
        $dt = $trail[$i]['t'] - $trail[$i-1]['t'];
        $velocities[] = $dx / $dt;
    }
    
    $stdDev = $this->calculateStandardDeviation($velocities);
    // Bot detection: too uniform = likely bot
    return $stdDev > 0.01 && $stdDev < 100;
}
```

#### b. Y-Axis Movement
```php
// Human movement includes vertical deviation
$yRange = max($yValues) - min($yValues);
if ($yRange < 1) {
    return false; // No Y movement = bot-like
}
```

#### c. Duration Check
```php
if ($duration < 100) {
    return false; // Too fast = automated
}
```

### 4. Challenge Expiration & Cleanup

**Automatic Cleanup (Java Example):**
```java
@Scheduled(fixedDelay = 60000) // Every minute
public void scheduledCleanup() {
    Instant cutoff = Instant.now().minus(Duration.ofMinutes(5));
    
    challengeStore.entrySet().removeIf(
        entry -> entry.getValue().getCreated().isBefore(cutoff)
    );
}
```

### 5. Rate Limiting

**Per-Challenge Limits:**
- Maximum 5 attempts per challenge
- Challenge deleted after max attempts

**Per-IP Limits (optional):**
```php
const MAX_ATTEMPTS_PER_IP = 10;
const RATE_LIMIT_WINDOW = 300; // 5 minutes

if ($attempts > self::MAX_ATTEMPTS_PER_IP) {
    return ['error' => 'Rate limit exceeded'];
}
```

## Canvas Rendering Details

### Puzzle Piece Generation

```javascript
// Bezier curve paths for puzzle shape
const createPuzzlePath = (ctx, x, y) => {
    const l = 42; // Base width
    const r = 9;  // Tab radius
    
    ctx.beginPath();
    ctx.moveTo(x, y);
    
    // Top tab (convex)
    ctx.arc(x + l/2, y - r + 2, r, 0.72 * PI, 2.26 * PI);
    
    // Right tab (convex)
    ctx.arc(x + l + r - 2, y + l/2, r, 1.21 * PI, 2.78 * PI);
    
    // Left tab (concave)
    ctx.arc(x + r - 2, y + l/2, r + 0.4, 2.76 * PI, 1.24 * PI, true);
    
    ctx.closePath();
};
```

### Image Extraction & Clipping

```javascript
// Extract puzzle piece with proper alignment
tempCtx.drawImage(
    img,
    this.targetX,              // Source X
    this.puzzleY - r - 10,     // Source Y (includes tab)
    this.L,                    // Width
    this.L + blockExtraHeight, // Height (includes tab)
    0, 0,                      // Destination
    this.L,
    this.L + blockExtraHeight
);

// Apply clipping mask
createPuzzlePath(this.blockCtx, 0, r + 10);
this.blockCtx.clip();
this.blockCtx.drawImage(tempCanvas, 0, 0);
```

## Backend Implementations Comparison

| Feature | PHP | .NET 4.5 | .NET 4.8 | Java Spring |
|---------|-----|----------|----------|-------------|
| **Handler Type** | Direct Script | IHttpHandler | Web API 2 | REST Controller |
| **Async Support** | No | No | Yes | Yes |
| **Storage** | Session + File | Session + File | Memory + File | Memory + File |
| **Cleanup** | Manual | Manual | Manual | Scheduled |
| **JSON Library** | Native | JavaScriptSerializer | Newtonsoft | Jackson |
| **Random Gen** | random_bytes() | RNGCryptoServiceProvider | RNGCryptoServiceProvider | SecureRandom |
| **CORS** | Headers | Web.config | Attributes | @CrossOrigin |

## Performance Considerations

### Memory Usage
- Each challenge: ~500 bytes
- 1000 concurrent users: ~500KB
- Automatic cleanup prevents memory leaks

### CPU Impact
- Challenge generation: < 1ms
- Verification: < 5ms
- Canvas rendering: < 50ms

### Network Overhead
- Challenge request: ~200 bytes
- Verification request: ~2KB (with trail)
- Response: ~150 bytes

## Testing Attack Vectors

### 1. Replay Attack
```bash
# Capture valid request
curl -X POST http://localhost:8000/backend/php/SliderCaptchaController-secure.php?action=verify \
  -H "Content-Type: application/json" \
  -d '{"challengeId":"abc123","trail":[...]}'

# Replay same request - should fail
# Response: {"verified":false,"error":"Challenge already used"}
```

### 2. Brute Force
```javascript
// Attempting random positions - fails due to:
// 1. No valid challengeId
// 2. Position validation
// 3. Rate limiting
for (let i = 0; i < 1000; i++) {
    fetch('/verify', {
        body: JSON.stringify({
            challengeId: 'fake-' + i,
            trail: generateFakeTrail()
        })
    });
}
```

### 3. Bot Simulation
```javascript
// Perfect linear movement - detected as bot
const fakeTrail = [];
for (let i = 0; i <= 100; i++) {
    fakeTrail.push({
        x: i * 2,
        y: 0,  // No Y variation = bot
        t: i * 10  // Perfect timing = bot
    });
}
```

## Deployment Best Practices

### 1. HTTPS Required
```nginx
server {
    listen 443 ssl http2;
    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;
    
    # Force HTTPS
    add_header Strict-Transport-Security "max-age=31536000";
}
```

### 2. Session Security
```php
// PHP session configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Strict');
```

### 3. CORS Configuration
```java
@CrossOrigin(
    origins = "https://yourdomain.com",
    allowCredentials = "true",
    methods = {RequestMethod.GET, RequestMethod.POST}
)
```

### 4. Rate Limiting (Nginx)
```nginx
limit_req_zone $binary_remote_addr zone=captcha:10m rate=10r/s;

location /backend/ {
    limit_req zone=captcha burst=5 nodelay;
    proxy_pass http://backend;
}
```

## Monitoring & Logging

### Key Metrics to Track
- Challenge generation rate
- Verification success/failure ratio
- Average solving time
- Bot detection triggers
- Challenge expiration rate

### Log Format Example
```
[2024-01-07 10:15:23] INFO: Challenge generated: a3f2d1b8 for IP: 192.168.1.1
[2024-01-07 10:15:45] INFO: Verification attempt: a3f2d1b8, attempt: 1
[2024-01-07 10:15:45] SUCCESS: Challenge verified: a3f2d1b8, duration: 22s
[2024-01-07 10:20:30] WARN: Challenge expired: b7e9c2d4
[2024-01-07 10:21:00] ERROR: Bot detected - uniform velocity from IP: 10.0.0.5
```

## Troubleshooting Guide

| Issue | Cause | Solution |
|-------|-------|----------|
| "Challenge not found" | Challenge expired or doesn't exist | Request new challenge |
| "Challenge already used" | Replay attack attempt | Working as intended |
| "Position mismatch" | Puzzle not properly aligned | Check canvas dimensions |
| "No Y movement detected" | Bot-like behavior | Add natural movement |
| "Too many attempts" | Rate limit reached | Wait before retry |
| Puzzle image blank | Image path incorrect | Verify image paths |
| Session not persisting | Cookie settings | Check session config |

## Future Enhancements

1. **WebAssembly Validation** - Client-side crypto validation
2. **Machine Learning** - Advanced bot detection patterns
3. **WebRTC Fingerprinting** - Device identification
4. **Accessibility Mode** - Audio-based alternative
5. **Multi-Challenge System** - Require solving multiple puzzles
6. **Adaptive Difficulty** - Adjust based on user behavior